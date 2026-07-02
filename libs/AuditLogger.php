<?php

require_once __DIR__ . '/../includes/settings_helper.php';

class AuditLogger
{
    private PDO $pdo;
    private array $tableAvailability = [];
    private ?NotificationManager $notificationManager = null;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public static function resolveClientIp(): string
    {
        $candidates = [
            $_SERVER['HTTP_CF_CONNECTING_IP'] ?? null,
            $_SERVER['HTTP_X_FORWARDED_FOR'] ?? null,
            $_SERVER['REMOTE_ADDR'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (!is_string($candidate) || trim($candidate) === '') {
                continue;
            }

            $parts = array_map('trim', explode(',', $candidate));
            foreach ($parts as $part) {
                if (filter_var($part, FILTER_VALIDATE_IP)) {
                    return $part;
                }
            }
        }

        return 'unknown';
    }

    public static function resolveUserAgent(): string
    {
        return mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? 'Unknown agent'), 0, 255);
    }

    public static function resolveRoute(): string
    {
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? $_SERVER['PHP_SELF'] ?? 'cli');
        return mb_substr($uri, 0, 255);
    }

    public static function resolveRequestMethod(): string
    {
        return strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'CLI'));
    }

    public function isSubsystemReady(): bool
    {
        return $this->tableExists('audit_logs')
            && $this->tableExists('security_login_attempts')
            && $this->tableExists('security_ip_blocks');
    }

    public function log(string $category, string $eventType, string $message, array $options = []): bool
    {
        if (!setting_truthy('enable_audit_logs', true) || !$this->tableExists('audit_logs')) {
            return false;
        }

        $stmt = $this->pdo->prepare("
            INSERT INTO audit_logs (
                category,
                event_type,
                severity,
                user_id,
                target_user_id,
                entity_type,
                entity_id,
                ip_address,
                user_agent,
                route,
                request_method,
                status,
                message,
                context_json,
                created_at
            ) VALUES (
                :category,
                :event_type,
                :severity,
                :user_id,
                :target_user_id,
                :entity_type,
                :entity_id,
                :ip_address,
                :user_agent,
                :route,
                :request_method,
                :status,
                :message,
                :context_json,
                NOW()
            )
        ");

        return $stmt->execute([
            'category' => $category,
            'event_type' => $eventType,
            'severity' => $options['severity'] ?? 'info',
            'user_id' => $options['user_id'] ?? ($_SESSION['user_id'] ?? null),
            'target_user_id' => $options['target_user_id'] ?? null,
            'entity_type' => $options['entity_type'] ?? null,
            'entity_id' => $options['entity_id'] ?? null,
            'ip_address' => $options['ip_address'] ?? self::resolveClientIp(),
            'user_agent' => $options['user_agent'] ?? self::resolveUserAgent(),
            'route' => $options['route'] ?? self::resolveRoute(),
            'request_method' => $options['request_method'] ?? self::resolveRequestMethod(),
            'status' => $options['status'] ?? null,
            'message' => $message,
            'context_json' => $this->encodeContext($options['context'] ?? []),
        ]);
    }

    public function cleanupExpiredIpBlocks(): void
    {
        if (!$this->tableExists('security_ip_blocks')) {
            return;
        }

        $stmt = $this->pdo->prepare("
            UPDATE security_ip_blocks
            SET is_active = 0,
                unblocked_at = COALESCE(unblocked_at, NOW()),
                updated_at = NOW()
            WHERE is_active = 1
              AND blocked_until IS NOT NULL
              AND blocked_until <= NOW()
        ");
        $stmt->execute();
    }

    public function getActiveIpBlock(?string $ipAddress = null): ?array
    {
        if (!$this->tableExists('security_ip_blocks')) {
            return null;
        }

        $this->cleanupExpiredIpBlocks();
        $ipAddress = $ipAddress ?: self::resolveClientIp();

        $stmt = $this->pdo->prepare("
            SELECT b.*,
                   blocker.name AS blocked_by_name,
                   unblocker.name AS unblocked_by_name
            FROM security_ip_blocks b
            LEFT JOIN users blocker ON blocker.id = b.blocked_by_user_id
            LEFT JOIN users unblocker ON unblocker.id = b.unblocked_by_user_id
            WHERE b.ip_address = :ip_address
              AND b.is_active = 1
              AND (b.blocked_until IS NULL OR b.blocked_until > NOW())
            ORDER BY b.created_at DESC
            LIMIT 1
        ");
        $stmt->execute(['ip_address' => $ipAddress]);

        $record = $stmt->fetch();
        return $record ?: null;
    }

    public function registerSuccessfulLogin(array $user, ?string $identifier = null, array $context = []): void
    {
        if (!$this->tableExists('security_login_attempts')) {
            return;
        }

        $this->insertLoginAttempt(
            $identifier ?: (string) ($user['email'] ?? ''),
            isset($user['id']) ? (int) $user['id'] : null,
            'success',
            null,
            $context
        );

        $this->log(
            'auth',
            'login_success',
            'User login successful.',
            [
                'severity' => 'info',
                'user_id' => $user['id'] ?? null,
                'target_user_id' => $user['id'] ?? null,
                'status' => 'success',
                'context' => array_merge($context, [
                    'identifier' => $identifier ?: ($user['email'] ?? null),
                    'role' => $user['role_name'] ?? null,
                ]),
            ]
        );
    }

    public function isRecognizedDeviceForUser(int $userId, ?string $userAgent = null): bool
    {
        if ($userId <= 0 || !$this->tableExists('security_login_attempts')) {
            return false;
        }

        $normalizedAgent = mb_substr((string) ($userAgent ?? self::resolveUserAgent()), 0, 255);
        if ($normalizedAgent === '') {
            return false;
        }

        $stmt = $this->pdo->prepare("
            SELECT 1
            FROM security_login_attempts
            WHERE user_id = :user_id
              AND outcome = 'success'
              AND user_agent = :user_agent
            LIMIT 1
        ");
        $stmt->execute([
            'user_id' => $userId,
            'user_agent' => $normalizedAgent,
        ]);

        return (bool) $stmt->fetchColumn();
    }

    public function registerBlockedLogin(string $identifier, array $blockRecord, ?int $matchedUserId = null, array $context = []): void
    {
        if (!$this->tableExists('security_login_attempts')) {
            return;
        }

        $failureReason = !empty($blockRecord['reason']) ? (string) $blockRecord['reason'] : 'ip_blocked';

        $this->insertLoginAttempt($identifier, $matchedUserId, 'blocked', $failureReason, array_merge($context, [
            'block_id' => $blockRecord['id'] ?? null,
            'blocked_until' => $blockRecord['blocked_until'] ?? null,
            'block_source' => $blockRecord['source'] ?? null,
        ]));

        $this->log(
            'security',
            'blocked_login_attempt',
            'Login attempt rejected because the source IP address is blocked.',
            [
                'severity' => 'critical',
                'user_id' => $matchedUserId,
                'target_user_id' => $matchedUserId,
                'status' => 'blocked',
                'context' => array_merge($context, [
                    'identifier' => $identifier,
                    'block_id' => $blockRecord['id'] ?? null,
                    'blocked_until' => $blockRecord['blocked_until'] ?? null,
                ]),
            ]
        );
    }

    public function registerFailedLogin(string $identifier, ?int $matchedUserId = null, string $reason = 'invalid_credentials', array $context = []): array
    {
        if (!$this->tableExists('security_login_attempts')) {
            return [
                'blocked' => false,
                'attempt_count' => 0,
                'threshold' => (int) get_setting('security_failed_login_threshold', 5),
            ];
        }

        $this->insertLoginAttempt($identifier, $matchedUserId, 'failed', $reason, $context);

        $this->log(
            'security',
            'login_failed',
            'Unauthorized login attempt detected.',
            [
                'severity' => 'warning',
                'user_id' => $matchedUserId,
                'target_user_id' => $matchedUserId,
                'status' => 'failed',
                'context' => array_merge($context, [
                    'identifier' => $identifier,
                    'reason' => $reason,
                ]),
            ]
        );

        if (!setting_truthy('security_login_monitoring_enabled', true)) {
            return [
                'blocked' => false,
                'attempt_count' => 0,
                'threshold' => (int) get_setting('security_failed_login_threshold', 5),
            ];
        }

        $threshold = max(1, (int) get_setting('security_failed_login_threshold', 5));
        $windowMinutes = max(1, (int) get_setting('security_failed_login_window_minutes', 15));
        $attemptCount = $this->countRecentFailedAttempts(self::resolveClientIp(), $windowMinutes);

        $result = [
            'blocked' => false,
            'attempt_count' => $attemptCount,
            'threshold' => $threshold,
        ];

        if ($attemptCount < $threshold) {
            return $result;
        }

        $durationMinutes = max(0, (int) get_setting('security_block_duration_minutes', 60));
        $block = $this->blockIp(
            self::resolveClientIp(),
            'Automatic IP block after repeated failed login attempts.',
            null,
            $durationMinutes,
            'automatic',
            array_merge($context, [
                'identifier' => $identifier,
                'attempt_count' => $attemptCount,
                'threshold' => $threshold,
                'window_minutes' => $windowMinutes,
                'matched_user_id' => $matchedUserId,
            ])
        );

        $result['blocked'] = $block !== null;
        $result['block'] = $block;

        if ($block !== null && setting_truthy('security_notify_admin_on_block', true)) {
            $until = !empty($block['blocked_until']) ? ' until ' . $block['blocked_until'] : ' until manually cleared';
            $this->notifyAdmins(
                'Security Alert: IP Auto-Blocked',
                'Source IP ' . self::resolveClientIp() . ' was automatically blocked after repeated failed login attempts' . $until . '.',
                array_merge($context, [
                    'identifier' => $identifier,
                    'attempt_count' => $attemptCount,
                    'threshold' => $threshold,
                ])
            );
        }

        return $result;
    }

    public function blockIp(
        string $ipAddress,
        string $reason,
        ?int $performedByUserId = null,
        ?int $durationMinutes = null,
        string $source = 'manual',
        array $context = []
    ): ?array {
        if (!filter_var($ipAddress, FILTER_VALIDATE_IP) || !$this->tableExists('security_ip_blocks')) {
            return null;
        }

        $existing = $this->getActiveIpBlock($ipAddress);
        if ($existing) {
            return $existing;
        }

        $blockedUntil = null;
        if ($durationMinutes !== null && $durationMinutes > 0) {
            $blockedUntil = date('Y-m-d H:i:s', time() + ($durationMinutes * 60));
        }

        $stmt = $this->pdo->prepare("
            INSERT INTO security_ip_blocks (
                ip_address,
                reason,
                source,
                blocked_by_user_id,
                blocked_until,
                is_active,
                notes,
                context_json,
                created_at,
                updated_at
            ) VALUES (
                :ip_address,
                :reason,
                :source,
                :blocked_by_user_id,
                :blocked_until,
                1,
                :notes,
                :context_json,
                NOW(),
                NOW()
            )
        ");
        $stmt->execute([
            'ip_address' => $ipAddress,
            'reason' => $reason,
            'source' => $source,
            'blocked_by_user_id' => $performedByUserId,
            'blocked_until' => $blockedUntil,
            'notes' => $context['notes'] ?? null,
            'context_json' => $this->encodeContext($context),
        ]);

        $record = $this->getIpBlockById((int) $this->pdo->lastInsertId());

        $this->log(
            'security',
            'ip_blocked',
            'IP address blocked from system access.',
            [
                'severity' => 'critical',
                'user_id' => $performedByUserId,
                'entity_type' => 'ip_address',
                'status' => $source,
                'ip_address' => $ipAddress,
                'context' => array_merge($context, [
                    'reason' => $reason,
                    'blocked_until' => $blockedUntil,
                    'source' => $source,
                ]),
            ]
        );

        return $record;
    }

    public function unblockIp(string $ipAddress, ?int $performedByUserId = null, string $notes = ''): bool
    {
        if (!filter_var($ipAddress, FILTER_VALIDATE_IP) || !$this->tableExists('security_ip_blocks')) {
            return false;
        }

        $block = $this->getActiveIpBlock($ipAddress);
        if (!$block) {
            return false;
        }

        $stmt = $this->pdo->prepare("
            UPDATE security_ip_blocks
            SET is_active = 0,
                unblocked_by_user_id = :unblocked_by_user_id,
                unblocked_at = NOW(),
                notes = CASE
                    WHEN notes IS NULL OR notes = '' THEN :notes1
                    WHEN :notes2 = '' THEN notes
                    ELSE CONCAT(notes, '\n', :notes3)
                END,
                updated_at = NOW()
            WHERE id = :id
        ");

        $success = $stmt->execute([
            'id' => $block['id'],
            'unblocked_by_user_id' => $performedByUserId,
            'notes1' => trim($notes),
            'notes2' => trim($notes),
            'notes3' => trim($notes),
        ]);

        if ($success) {
            $this->log(
                'security',
                'ip_unblocked',
                'IP address access restored by administrator.',
                [
                    'severity' => 'info',
                    'user_id' => $performedByUserId,
                    'entity_type' => 'ip_address',
                    'status' => 'resolved',
                    'ip_address' => $ipAddress,
                    'context' => [
                        'previous_block_id' => $block['id'],
                        'notes' => trim($notes),
                    ],
                ]
            );
        }

        return $success;
    }

    public function notifyAdmins(string $title, string $message, array $context = []): void
    {
        if (!$this->tableExists('notifications')) {
            return;
        }

        $stmt = $this->pdo->query("
            SELECT u.id
            FROM users u
            INNER JOIN roles r ON r.id = u.role_id
            WHERE r.name = 'System Admin'
        ");

        $adminIds = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
        if (empty($adminIds)) {
            return;
        }

        foreach ($adminIds as $adminId) {
            try {
                $this->getNotificationManager()->notify(
                    (int) $adminId,
                    'security',
                    $title,
                    $message,
                    'modules/admin/audit_center',
                    null,
                    false,
                    false,
                    $context
                );
            } catch (Exception $exception) {
                error_log('Unable to notify administrators about security event: ' . $exception->getMessage());
            }
        }
    }

    private function countRecentFailedAttempts(string $ipAddress, int $windowMinutes): int
    {
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*)
            FROM security_login_attempts
            WHERE ip_address = :ip_address
              AND outcome = 'failed'
              AND occurred_at >= DATE_SUB(NOW(), INTERVAL :window_minutes MINUTE)
        ");
        $stmt->bindValue(':ip_address', $ipAddress, PDO::PARAM_STR);
        $stmt->bindValue(':window_minutes', $windowMinutes, PDO::PARAM_INT);
        $stmt->execute();

        return (int) $stmt->fetchColumn();
    }

    private function insertLoginAttempt(
        string $identifier,
        ?int $matchedUserId,
        string $outcome,
        ?string $failureReason,
        array $context = []
    ): void {
        $stmt = $this->pdo->prepare("
            INSERT INTO security_login_attempts (
                user_id,
                attempted_identifier,
                ip_address,
                user_agent,
                outcome,
                failure_reason,
                metadata_json,
                occurred_at
            ) VALUES (
                :user_id,
                :attempted_identifier,
                :ip_address,
                :user_agent,
                :outcome,
                :failure_reason,
                :metadata_json,
                NOW()
            )
        ");

        $stmt->execute([
            'user_id' => $matchedUserId,
            'attempted_identifier' => mb_substr($identifier, 0, 190),
            'ip_address' => self::resolveClientIp(),
            'user_agent' => self::resolveUserAgent(),
            'outcome' => $outcome,
            'failure_reason' => $failureReason,
            'metadata_json' => $this->encodeContext($context),
        ]);
    }

    private function getIpBlockById(int $id): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT b.*,
                   blocker.name AS blocked_by_name,
                   unblocker.name AS unblocked_by_name
            FROM security_ip_blocks b
            LEFT JOIN users blocker ON blocker.id = b.blocked_by_user_id
            LEFT JOIN users unblocker ON unblocker.id = b.unblocked_by_user_id
            WHERE b.id = :id
            LIMIT 1
        ");
        $stmt->execute(['id' => $id]);
        $record = $stmt->fetch();

        return $record ?: null;
    }

    private function tableExists(string $tableName): bool
    {
        if (array_key_exists($tableName, $this->tableAvailability)) {
            return $this->tableAvailability[$tableName];
        }

        try {
            $stmt = $this->pdo->prepare("
                SELECT COUNT(*)
                FROM information_schema.tables
                WHERE table_schema = DATABASE()
                  AND table_name = :table_name
            ");
            $stmt->execute(['table_name' => $tableName]);
            $this->tableAvailability[$tableName] = ((int) $stmt->fetchColumn()) > 0;
        } catch (Exception $exception) {
            $this->tableAvailability[$tableName] = false;
        }

        return $this->tableAvailability[$tableName];
    }

    private function encodeContext(array $context): ?string
    {
        if (empty($context)) {
            return null;
        }

        $json = json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return $json === false ? null : $json;
    }

    private function getNotificationManager(): NotificationManager
    {
        if ($this->notificationManager instanceof NotificationManager) {
            return $this->notificationManager;
        }

        require_once __DIR__ . '/NotificationManager.php';
        $this->notificationManager = new NotificationManager($this->pdo);

        return $this->notificationManager;
    }
}
