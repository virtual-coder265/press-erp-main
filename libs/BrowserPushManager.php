<?php

require_once __DIR__ . '/../includes/settings_helper.php';
require_once __DIR__ . '/../includes/branding_helper.php';

class BrowserPushManager
{
    private const SUPPORT_CACHE_TTL = 21600;

    private $pdo;
    private $config;
    private $schemaReady;
    private $nodeAvailable;
    private static $bootstrappedConfig = false;
    private static $cachedNodeSupport = null;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->config = null;
        $this->schemaReady = null;
        $this->nodeAvailable = null;
    }

    public function isSupported(): bool
    {
        if ($this->nodeAvailable !== null) {
            return $this->nodeAvailable;
        }

        if (self::$cachedNodeSupport !== null) {
            $this->nodeAvailable = (bool) self::$cachedNodeSupport;
            return $this->nodeAvailable;
        }

        if (!file_exists(ROOT_PATH . 'cli-tools' . DIRECTORY_SEPARATOR . 'web-push.mjs')) {
            $this->nodeAvailable = false;
            self::$cachedNodeSupport = false;
            $this->writeSupportCache(false);
            return $this->nodeAvailable;
        }

        $cached = $this->readSupportCache();
        if ($cached !== null) {
            $this->nodeAvailable = $cached;
            self::$cachedNodeSupport = $cached;
            return $this->nodeAvailable;
        }

        try {
            $result = $this->runNodeScript('check');
            $this->nodeAvailable = !empty($result['success']);
        } catch (Throwable $exception) {
            $this->nodeAvailable = false;
        }

        self::$cachedNodeSupport = $this->nodeAvailable;
        $this->writeSupportCache((bool) $this->nodeAvailable);

        return $this->nodeAvailable;
    }

    public function isEnabled(): bool
    {
        if (!$this->isSupported()) {
            return false;
        }

        if (!$this->ensureSchema()) {
            return false;
        }

        if (!setting_truthy('notification_push_enabled', true)) {
            return false;
        }

        return $this->getConfig() !== null;
    }

    public function getPublicConfig(): array
    {
        $config = $this->getConfig();

        return [
            'enabled' => $this->isEnabled(),
            'supported' => $this->isSupported(),
            'publicKey' => $config['publicKey'] ?? '',
            'appName' => $this->getBrandName(),
            'baseUrl' => BASE_URL,
            'scope' => $this->getScopePath(),
            'serviceWorkerUrl' => BASE_URL . 'push-sw',
            'subscriptionUrl' => BASE_URL . 'modules/user-account/push_subscription',
            'notificationActionUrl' => BASE_URL . 'modules/user-account/notification_action',
            'csrfToken' => csrf_token('push_subscription'),
            'secureContext' => $this->isSecureContextExpected(),
        ];
    }

    public function getStatus(int $userId): array
    {
        return [
            'enabled' => $this->isEnabled(),
            'supported' => $this->isSupported(),
            'has_active_subscription' => $this->hasActiveSubscriptions($userId),
            'subscription_count' => $this->countActiveSubscriptions($userId),
            'app_name' => $this->getBrandName(),
        ];
    }

    public function saveSubscription(int $userId, array $subscription, array $meta = []): array
    {
        if ($userId <= 0) {
            throw new InvalidArgumentException('A valid user is required.');
        }

        if (!$this->isEnabled()) {
            return [
                'success' => false,
                'message' => 'Browser push is disabled.',
            ];
        }

        $normalized = $this->normalizeSubscriptionPayload($subscription);
        if ($normalized === null) {
            throw new InvalidArgumentException('Invalid push subscription payload.');
        }

        $stmt = $this->pdo->prepare("
            INSERT INTO web_push_subscriptions (
                user_id,
                endpoint,
                public_key,
                auth_token,
                content_encoding,
                subscription_json,
                user_agent,
                is_active,
                last_seen_at,
                created_at,
                updated_at
            )
            VALUES (
                :user_id,
                :endpoint,
                :public_key,
                :auth_token,
                :content_encoding,
                :subscription_json,
                :user_agent,
                1,
                NOW(),
                NOW(),
                NOW()
            )
            ON DUPLICATE KEY UPDATE
                user_id = VALUES(user_id),
                public_key = VALUES(public_key),
                auth_token = VALUES(auth_token),
                content_encoding = VALUES(content_encoding),
                subscription_json = VALUES(subscription_json),
                user_agent = VALUES(user_agent),
                is_active = 1,
                last_seen_at = NOW(),
                updated_at = NOW()
        ");

        $stmt->execute([
            'user_id' => $userId,
            'endpoint' => $normalized['endpoint'],
            'public_key' => $normalized['public_key'],
            'auth_token' => $normalized['auth_token'],
            'content_encoding' => $normalized['content_encoding'],
            'subscription_json' => json_encode($subscription, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'user_agent' => mb_substr((string) ($meta['user_agent'] ?? ''), 0, 255),
        ]);

        return [
            'success' => true,
            'endpoint' => $normalized['endpoint'],
        ];
    }

    public function deactivateSubscription(int $userId, string $endpoint): bool
    {
        if ($userId <= 0 || trim($endpoint) === '') {
            return false;
        }

        if (!$this->ensureSchema()) {
            return false;
        }

        $stmt = $this->pdo->prepare("
            UPDATE web_push_subscriptions
            SET is_active = 0,
                updated_at = NOW()
            WHERE user_id = :user_id
              AND endpoint = :endpoint
        ");

        $stmt->execute([
            'user_id' => $userId,
            'endpoint' => trim($endpoint),
        ]);

        return $stmt->rowCount() > 0;
    }

    public function sendNotificationForEvent(
        int $userId,
        string $type,
        string $title,
        string $description,
        ?string $link = null,
        $relatedId = null,
        array $context = []
    ): array {
        if ($userId <= 0 || !$this->isEnabled()) {
            return [
                'success' => false,
                'sent' => 0,
                'reason' => 'disabled',
            ];
        }

        $payload = $this->buildEventPayload($type, $title, $description, $link, $relatedId, $context);
        return $this->sendPayloadToUser($userId, $payload, $this->defaultWebPushOptions($type, $relatedId));
    }

    public function sendReminderAlarm(int $userId, array $alarm): array
    {
        if ($userId <= 0 || !$this->isEnabled()) {
            return [
                'success' => false,
                'sent' => 0,
                'reason' => 'disabled',
            ];
        }

        $title = trim((string) ($alarm['title'] ?? 'Reminder'));
        $bodyParts = [];

        if (!empty($alarm['note'])) {
            $bodyParts[] = trim((string) $alarm['note']);
        }
        if (!empty($alarm['due_label'])) {
            $bodyParts[] = trim((string) $alarm['due_label']);
        }
        if (!empty($alarm['project_name'])) {
            $bodyParts[] = 'Project: ' . trim((string) $alarm['project_name']);
        }

        $payload = [
            'kind' => 'reminder_alarm',
            'type' => 'reminder',
            'title' => $this->brandTitle('Reminder', $title),
            'body' => $this->limitText(implode(' - ', array_filter($bodyParts)), 220),
            'url' => $this->resolveAbsoluteUrl($alarm['href'] ?? 'modules/reminders/index'),
            'tag' => 'press-erp-reminder-alarm-' . (int) ($alarm['id'] ?? 0),
            'icon' => $this->resolvePurposeIconUrl('reminder'),
            'badge' => $this->resolveBadgeUrl(),
            'image' => $this->resolveBrandImageUrl(),
            'timestamp' => round(microtime(true) * 1000),
            'requireInteraction' => true,
            'sound' => 'reminder',
            'toastType' => 'warning',
            'toastMessage' => $title,
            'brandName' => $this->getBrandName(),
        ];

        return $this->sendPayloadToUser($userId, $payload, [
            'TTL' => 3600,
            'urgency' => 'high',
            'topic' => 'reminder-' . (int) ($alarm['id'] ?? 0),
        ]);
    }

    public function sendTestNotification(int $userId): array
    {
        if ($userId <= 0 || !$this->isEnabled()) {
            return [
                'success' => false,
                'sent' => 0,
                'reason' => 'disabled',
            ];
        }

        $payload = [
            'kind' => 'test_push',
            'type' => 'notice',
            'title' => $this->brandTitle('Push Test', 'Browser push is working'),
            'body' => 'This is a direct test from your notification settings page.',
            'url' => $this->resolveAbsoluteUrl('modules/user-account/notifications'),
            'tag' => 'press-erp-push-test-' . $userId,
            'icon' => $this->resolvePurposeIconUrl('notice'),
            'badge' => $this->resolveBadgeUrl(),
            'image' => $this->resolveBrandImageUrl(),
            'timestamp' => round(microtime(true) * 1000),
            'requireInteraction' => true,
            'forceSystemNotification' => true,
            'sound' => 'success',
            'toastType' => 'success',
            'toastMessage' => 'Test push sent.',
            'brandName' => $this->getBrandName(),
        ];

        return $this->sendPayloadToUser($userId, $payload, [
            'TTL' => 300,
            'urgency' => 'high',
            'topic' => 'push-test-' . $userId,
        ]);
    }

    public function getUsersWithActiveSubscriptions(int $limit = 100): array
    {
        if (!$this->ensureSchema()) {
            return [];
        }

        $limit = max(1, min(500, $limit));
        $stmt = $this->pdo->query("
            SELECT DISTINCT user_id
            FROM web_push_subscriptions
            WHERE is_active = 1
            ORDER BY user_id ASC
            LIMIT " . (int) $limit
        );

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
    }

    public function hasActiveSubscriptions(int $userId): bool
    {
        return $this->countActiveSubscriptions($userId) > 0;
    }

    public function countActiveSubscriptions(int $userId): int
    {
        if ($userId <= 0) {
            return 0;
        }

        if (!$this->ensureSchema()) {
            return 0;
        }

        $stmt = $this->pdo->prepare("
            SELECT COUNT(*)
            FROM web_push_subscriptions
            WHERE user_id = :user_id
              AND is_active = 1
        ");
        $stmt->execute(['user_id' => $userId]);

        return (int) $stmt->fetchColumn();
    }

    private function sendPayloadToUser(int $userId, array $payload, array $options = []): array
    {
        $subscriptions = $this->getActiveSubscriptions($userId);
        if (empty($subscriptions)) {
            return [
                'success' => false,
                'sent' => 0,
                'reason' => 'no_subscriptions',
            ];
        }

        $config = $this->getConfig();
        if ($config === null) {
            return [
                'success' => false,
                'sent' => 0,
                'reason' => 'missing_config',
            ];
        }

        $response = $this->runNodeScript('send', [
            'vapid' => $config,
            'payload' => $payload,
            'options' => $options,
            'subscriptions' => array_map(function (array $subscription): array {
                return [
                    'endpoint' => $subscription['endpoint'],
                    'keys' => [
                        'p256dh' => $subscription['public_key'],
                        'auth' => $subscription['auth_token'],
                    ],
                    'contentEncoding' => $subscription['content_encoding'] ?: 'aesgcm',
                ];
            }, $subscriptions),
        ]);

        $sent = 0;
        $failed = 0;
        $expired = 0;

        foreach (($response['reports'] ?? []) as $report) {
            if (!empty($report['success'])) {
                $sent++;
                continue;
            }

            $failed++;
            if (!empty($report['expired']) && !empty($report['endpoint'])) {
                $expired++;
                $this->deactivateSubscriptionByEndpoint((string) $report['endpoint']);
            }

            if (!empty($report['endpoint'])) {
                error_log('Web push send failed for ' . $report['endpoint'] . ': ' . ($report['reason'] ?? 'Unknown error'));
            }
        }

        return [
            'success' => !empty($response['success']) && $sent > 0,
            'sent' => $sent,
            'failed' => $failed,
            'expired' => $expired,
        ];
    }

    private function getActiveSubscriptions(int $userId): array
    {
        if (!$this->ensureSchema()) {
            return [];
        }

        $stmt = $this->pdo->prepare("
            SELECT endpoint, public_key, auth_token, content_encoding
            FROM web_push_subscriptions
            WHERE user_id = :user_id
              AND is_active = 1
            ORDER BY updated_at DESC
        ");
        $stmt->execute(['user_id' => $userId]);

        return $stmt->fetchAll() ?: [];
    }

    private function deactivateSubscriptionByEndpoint(string $endpoint): void
    {
        if (!$this->ensureSchema()) {
            return;
        }

        $stmt = $this->pdo->prepare("
            UPDATE web_push_subscriptions
            SET is_active = 0,
                updated_at = NOW()
            WHERE endpoint = :endpoint
        ");
        $stmt->execute(['endpoint' => trim($endpoint)]);
    }

    private function normalizeSubscriptionPayload(array $subscription): ?array
    {
        $endpoint = trim((string) ($subscription['endpoint'] ?? ''));
        $keys = $subscription['keys'] ?? [];
        $publicKey = trim((string) ($keys['p256dh'] ?? $subscription['publicKey'] ?? ''));
        $authToken = trim((string) ($keys['auth'] ?? $subscription['authToken'] ?? ''));
        $contentEncoding = trim((string) ($subscription['contentEncoding'] ?? 'aesgcm'));

        if ($endpoint === '' || $publicKey === '' || $authToken === '') {
            return null;
        }

        if (!in_array($contentEncoding, ['aesgcm', 'aes128gcm'], true)) {
            $contentEncoding = 'aesgcm';
        }

        return [
            'endpoint' => mb_substr($endpoint, 0, 2048),
            'public_key' => mb_substr($publicKey, 0, 255),
            'auth_token' => mb_substr($authToken, 0, 255),
            'content_encoding' => $contentEncoding,
        ];
    }

    private function getConfig(): ?array
    {
        if ($this->config !== null) {
            return $this->config;
        }

        if (!$this->isSupported()) {
            return null;
        }

        $publicKey = trim((string) get_setting('web_push_vapid_public_key', ''));
        $privateKey = trim((string) get_setting('web_push_vapid_private_key', ''));
        $subject = trim((string) get_setting('web_push_vapid_subject', ''));

        if (($publicKey === '' || $privateKey === '') && !self::$bootstrappedConfig) {
            self::$bootstrappedConfig = true;
            $this->bootstrapVapidConfiguration();
            clear_settings_cache();
            $publicKey = trim((string) get_setting('web_push_vapid_public_key', ''));
            $privateKey = trim((string) get_setting('web_push_vapid_private_key', ''));
            $subject = trim((string) get_setting('web_push_vapid_subject', ''));
        }

        if ($subject === '') {
            $subject = $this->defaultVapidSubject();
        }

        if ($publicKey === '' || $privateKey === '' || $subject === '') {
            return null;
        }

        $this->config = [
            'publicKey' => $publicKey,
            'privateKey' => $privateKey,
            'subject' => $subject,
        ];

        return $this->config;
    }

    private function ensureSchema(): bool
    {
        if ($this->schemaReady !== null) {
            return $this->schemaReady;
        }

        try {
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS web_push_subscriptions (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    user_id INT NOT NULL,
                    endpoint VARCHAR(2048) NOT NULL,
                    public_key VARCHAR(255) NOT NULL,
                    auth_token VARCHAR(255) NOT NULL,
                    content_encoding VARCHAR(32) NOT NULL DEFAULT 'aesgcm',
                    subscription_json LONGTEXT DEFAULT NULL,
                    user_agent VARCHAR(255) DEFAULT NULL,
                    is_active TINYINT(1) NOT NULL DEFAULT 1,
                    last_seen_at DATETIME DEFAULT NULL,
                    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    UNIQUE KEY uniq_web_push_endpoint (endpoint(768)),
                    KEY idx_web_push_user_active (user_id, is_active, updated_at),
                    CONSTRAINT fk_web_push_subscriptions_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");

            if (!$this->columnExists('notification_settings', 'push_enabled')) {
                $this->pdo->exec("ALTER TABLE notification_settings ADD COLUMN push_enabled TINYINT(1) NOT NULL DEFAULT 1");
            }

            $this->schemaReady = true;
        } catch (Throwable $exception) {
            error_log('Web push schema bootstrap failed: ' . $exception->getMessage());
            $this->schemaReady = false;
        }

        return $this->schemaReady;
    }

    private function columnExists(string $tableName, string $columnName): bool
    {
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*)
            FROM information_schema.columns
            WHERE table_schema = DATABASE()
              AND table_name = ?
              AND column_name = ?
        ");
        $stmt->execute([$tableName, $columnName]);

        return (int) $stmt->fetchColumn() > 0;
    }

    private function readSupportCache(): ?bool
    {
        $sessionCached = $this->readSessionSupportCache();
        if ($sessionCached !== null) {
            return $sessionCached;
        }

        $cachePath = $this->supportCachePath();
        if (!is_file($cachePath)) {
            return null;
        }

        $raw = @file_get_contents($cachePath);
        if (!is_string($raw) || trim($raw) === '') {
            return null;
        }

        $payload = json_decode($raw, true);
        if (!is_array($payload)) {
            return null;
        }

        $checkedAt = (int) ($payload['checked_at'] ?? 0);
        if ($checkedAt <= 0 || $checkedAt < (time() - self::SUPPORT_CACHE_TTL)) {
            return null;
        }

        $supported = !empty($payload['supported']);
        $this->writeSessionSupportCache($supported, $checkedAt);

        return $supported;
    }

    private function writeSupportCache(bool $supported): void
    {
        $checkedAt = time();
        $this->writeSessionSupportCache($supported, $checkedAt);

        $cachePath = $this->supportCachePath();
        $cacheDir = dirname($cachePath);
        if (!is_dir($cacheDir)) {
            @mkdir($cacheDir, 0777, true);
        }

        if (!is_dir($cacheDir)) {
            return;
        }

        $payload = json_encode([
            'supported' => $supported,
            'checked_at' => $checkedAt,
        ]);

        if ($payload === false) {
            return;
        }

        @file_put_contents($cachePath, $payload, LOCK_EX);
    }

    private function readSessionSupportCache(): ?bool
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return null;
        }

        $cache = $_SESSION['browser_push_support_cache'] ?? null;
        if (!is_array($cache)) {
            return null;
        }

        $checkedAt = (int) ($cache['checked_at'] ?? 0);
        if ($checkedAt <= 0 || $checkedAt < (time() - self::SUPPORT_CACHE_TTL)) {
            return null;
        }

        return !empty($cache['supported']);
    }

    private function writeSessionSupportCache(bool $supported, int $checkedAt): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }

        $_SESSION['browser_push_support_cache'] = [
            'supported' => $supported,
            'checked_at' => $checkedAt,
        ];
    }

    private function supportCachePath(): string
    {
        return ROOT_PATH
            . 'logs'
            . DIRECTORY_SEPARATOR
            . 'cache'
            . DIRECTORY_SEPARATOR
            . 'browser-push-support.json';
    }

    private function runNodeScript(string $command, array $payload = []): array
    {
        $scriptPath = ROOT_PATH . 'cli-tools' . DIRECTORY_SEPARATOR . 'web-push.mjs';
        if (!file_exists($scriptPath)) {
            throw new RuntimeException('Web push helper script is missing.');
        }

        $jsonPayload = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($jsonPayload === false) {
            throw new RuntimeException('Unable to encode web push payload.');
        }

        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $commandLine = 'node ' . escapeshellarg($scriptPath) . ' ' . escapeshellarg($command);
        $process = proc_open($commandLine, $descriptorSpec, $pipes, ROOT_PATH);
        if (!is_resource($process)) {
            throw new RuntimeException('Unable to start the node web push helper.');
        }

        fwrite($pipes[0], $jsonPayload);
        fclose($pipes[0]);

        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);
        $decoded = json_decode((string) $stdout, true);

        if ($exitCode !== 0) {
            throw new RuntimeException(trim((string) $stderr) !== '' ? trim((string) $stderr) : 'The node web push helper failed.');
        }

        if (!is_array($decoded)) {
            throw new RuntimeException('The node web push helper returned an invalid response.');
        }

        if (array_key_exists('success', $decoded) && !$decoded['success']) {
            throw new RuntimeException((string) ($decoded['message'] ?? 'The node web push helper returned an error.'));
        }

        return $decoded;
    }

    private function bootstrapVapidConfiguration(): void
    {
        try {
            $keys = $this->runNodeScript('generate-keys');
            save_application_settings([
                'web_push_vapid_public_key' => $keys['publicKey'],
                'web_push_vapid_private_key' => $keys['privateKey'],
                'web_push_vapid_subject' => $this->defaultVapidSubject(),
            ]);
        } catch (Throwable $exception) {
            error_log('Unable to bootstrap web push configuration: ' . $exception->getMessage());
        }
    }

    private function buildEventPayload(
        string $type,
        string $title,
        string $description,
        ?string $link,
        $relatedId,
        array $context
    ): array {
        $title = trim($title);
        $description = trim($description);
        $contextSummary = $this->buildContextSummary($type, $context);
        $body = $description;

        if ($contextSummary !== '' && stripos($description, $contextSummary) === false) {
            $body = trim($description . ' ' . $contextSummary);
        }

        return [
            'kind' => 'notification',
            'type' => $type,
            'title' => $this->brandTitle($this->typeLabel($type), $title),
            'body' => $this->limitText($body, 220),
            'url' => $this->resolveAbsoluteUrl($link ?: 'modules/dashboard/index'),
            'tag' => $this->buildEventTag($type, $relatedId),
            'icon' => $this->resolvePurposeIconUrl($type),
            'badge' => $this->resolveBadgeUrl(),
            'image' => $this->resolveBrandImageUrl(),
            'timestamp' => round(microtime(true) * 1000),
            'requireInteraction' => in_array($type, ['reminder', 'security'], true),
            'sound' => $this->resolveSoundType($type),
            'toastType' => $this->resolveToastType($type),
            'toastMessage' => $title,
            'brandName' => $this->getBrandName(),
        ];
    }

    private function defaultWebPushOptions(string $type, $relatedId): array
    {
        $urgency = in_array($type, ['reminder', 'security'], true) ? 'high' : 'normal';
        $ttl = in_array($type, ['reminder', 'security'], true) ? 43200 : 21600;
        $topicSeed = (string) ($relatedId !== null ? $relatedId : $type);
        $topic = preg_replace('/[^A-Za-z0-9\-_]/', '-', $type . '-' . $topicSeed);

        return [
            'TTL' => $ttl,
            'urgency' => $urgency,
            'topic' => mb_substr($topic ?: $type, 0, 32),
        ];
    }

    private function buildContextSummary(string $type, array $context): string
    {
        switch ($type) {
            case 'message':
                if (!empty($context['fromUser'])) {
                    return 'From ' . trim((string) $context['fromUser']) . '.';
                }
                break;

            case 'task':
                if (!empty($context['taskTitle'])) {
                    return 'Task: ' . trim((string) $context['taskTitle']) . '.';
                }
                break;

            case 'reminder':
                if (!empty($context['dueDate'])) {
                    $timestamp = strtotime((string) $context['dueDate']);
                    if ($timestamp !== false) {
                        return 'Due ' . date('M j, Y', $timestamp) . '.';
                    }
                }
                break;
        }

        return '';
    }

    private function brandTitle(string $typeLabel, string $title): string
    {
        $brand = $this->getBrandName();
        $typeLabel = trim($typeLabel);
        $title = trim($title);

        if ($brand === '') {
            return $title;
        }

        if ($typeLabel === '') {
            return $brand . ': ' . $title;
        }

        return $brand . ' - ' . $typeLabel . ': ' . $title;
    }

    private function typeLabel(string $type): string
    {
        $map = [
            'message' => 'Message',
            'task' => 'Task',
            'reminder' => 'Reminder',
            'security' => 'Security',
        ];

        return $map[$type] ?? 'Notice';
    }

    private function buildEventTag(string $type, $relatedId): string
    {
        if ($relatedId !== null && $relatedId !== '') {
            return 'press-erp-' . $type . '-' . preg_replace('/[^A-Za-z0-9\-_]/', '-', (string) $relatedId);
        }

        return 'press-erp-' . $type . '-' . substr(sha1($type . microtime(true)), 0, 12);
    }

    private function resolvePurposeIconUrl(string $type): string
    {
        $map = [
            'message' => 'message.svg',
            'task' => 'task.svg',
            'reminder' => 'reminder.svg',
            'security' => 'security.svg',
            'work_order' => 'work_order.svg',
        ];

        return BASE_URL . 'assets/images/notification-icons/' . ($map[$type] ?? 'notice.svg');
    }

    private function resolveBadgeUrl(): string
    {
        return system_branding_resolved_url('favicon');
    }

    private function resolveBrandImageUrl(): ?string
    {
        $logo = trim((string) get_setting('business_logo', ''));
        if ($logo === '') {
            return asset('images/logo-rect.png');
        }

        return $this->resolveAbsoluteUrl($logo, asset('images/logo-rect.png'));
    }

    private function resolveAbsoluteUrl(?string $path, ?string $fallback = null): string
    {
        $path = trim((string) $path);
        if ($path === '') {
            return $fallback !== null ? $this->resolveAbsoluteUrl($fallback) : BASE_URL . 'modules/dashboard/index';
        }

        if (preg_match('/^https?:\/\//i', $path)) {
            return $path;
        }

        return rtrim(BASE_URL, '/') . '/' . ltrim($path, '/');
    }

    private function getBrandName(): string
    {
        $name = trim((string) get_setting('system_app_name', ''));
        if ($name !== '') {
            return $name;
        }

        $name = trim((string) get_setting('business_name', ''));
        if ($name !== '') {
            return $name;
        }

        return APP_NAME;
    }

    private function defaultVapidSubject(): string
    {
        $mailAddress = trim((string) get_setting('mail_from_address', ''));
        if ($mailAddress !== '' && filter_var($mailAddress, FILTER_VALIDATE_EMAIL)) {
            return 'mailto:' . $mailAddress;
        }

        $businessEmail = trim((string) get_setting('business_email', ''));
        if ($businessEmail !== '' && filter_var($businessEmail, FILTER_VALIDATE_EMAIL)) {
            return 'mailto:' . $businessEmail;
        }

        if (stripos(BASE_URL, 'https://') === 0) {
            return rtrim(BASE_URL, '/');
        }

        return 'mailto:no-reply@localhost.local';
    }

    private function limitText(string $value, int $length): string
    {
        $value = trim(preg_replace('/\s+/', ' ', $value));
        if (mb_strlen($value) <= $length) {
            return $value;
        }

        return rtrim(mb_substr($value, 0, max(1, $length - 3))) . '...';
    }

    private function resolveSoundType(string $type): string
    {
        $map = [
            'message' => 'message',
            'task' => 'success',
            'reminder' => 'reminder',
            'security' => 'error',
        ];

        return $map[$type] ?? 'message';
    }

    private function resolveToastType(string $type): string
    {
        $map = [
            'message' => 'info',
            'task' => 'success',
            'reminder' => 'warning',
            'security' => 'error',
        ];

        return $map[$type] ?? 'info';
    }

    private function getScopePath(): string
    {
        $scope = parse_url(BASE_URL, PHP_URL_PATH);
        if (!is_string($scope) || $scope === '') {
            return '/';
        }

        return rtrim($scope, '/') . '/';
    }

    private function isSecureContextExpected(): bool
    {
        $host = parse_url(BASE_URL, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return false;
        }

        return stripos(BASE_URL, 'https://') === 0 || in_array($host, ['localhost', '127.0.0.1'], true);
    }
}
