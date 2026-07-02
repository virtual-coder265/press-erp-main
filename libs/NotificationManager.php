<?php
/**
 * NotificationManager - Centralized Notification System
 * 
 * Handles in-app notifications and email alerts.
 */

require_once __DIR__ . '/MailManager.php';
require_once __DIR__ . '/BrowserPushManager.php';
require_once __DIR__ . '/../includes/delivery_log_helper.php';

class NotificationManager {
    private $pdo;
    private $mailManager;
    private $twilioIncomingNumbers;
    private $userSettingsCache;
    private $systemNotificationSettings;
    private $browserPushManager;

    public function __construct($pdo) {
        $this->pdo = $pdo;
        $this->twilioIncomingNumbers = null;
        $this->userSettingsCache = [];
        $this->systemNotificationSettings = function_exists('get_notification_system_settings')
            ? get_notification_system_settings()
            : [
                'notifications_enabled' => true,
                'in_app_enabled' => true,
                'push_enabled' => true,
                'email_enabled' => true,
                'sms_enabled' => false,
                'whatsapp_enabled' => false,
                'queue_enabled' => true,
                'batch_size' => 25,
                'retry_limit' => 3,
            ];
        
        // Initialize MailManager with default settings
        require_once __DIR__ . '/../config/mail.php';
        $this->mailManager = new MailManager($pdo, getMailSettings());
        $this->browserPushManager = new BrowserPushManager($pdo);
    }

    /**
     * Send a notification to a user
     * 
     * @param int $userId Target user ID
     * @param string $type notification type (message, task, security, reminder)
     * @param string $title Title of the notification
     * @param string $description Detailed description
     * @param string $link Optional relative link for in-app action
     * @param int $relatedId Optional ID of related entity (message_id, etc.)
     * @return bool Success status
     */
    public function notify($userId, $type, $title, $description, $link = null, $relatedId = null, $sendSms = false, $sendWhatsapp = false, $context = [], array $options = []) {
        try {
            if (!$this->systemNotificationSettings['notifications_enabled']) {
                return true;
            }

            // 1. Get user preferences
            $settings = $this->getUserSettings($userId, $type);
            $channelOverrides = is_array($options['channels'] ?? null) ? $options['channels'] : [];

            $allowInApp = $this->systemNotificationSettings['in_app_enabled'] && $settings['in_app_enabled'];
            if (array_key_exists('in_app', $channelOverrides)) {
                $allowInApp = $allowInApp && (bool) $channelOverrides['in_app'];
            }

            // 2. In-app notification
            if ($allowInApp) {
                $this->createInAppNotification($userId, $type, $title, $description, $link, $relatedId);
            }

            $allowPush = !empty($this->systemNotificationSettings['push_enabled'])
                && !empty($settings['push_enabled']);
            if (array_key_exists('push', $channelOverrides)) {
                $allowPush = $allowPush && (bool) $channelOverrides['push'];
            }

            // 3. Browser push notification
            if ($allowPush && $this->browserPushManager instanceof BrowserPushManager) {
                $result = $this->browserPushManager->sendNotificationForEvent(
                    (int) $userId,
                    (string) $type,
                    (string) $title,
                    (string) $description,
                    $link,
                    $relatedId,
                    is_array($context) ? $context : []
                );

                if (!empty($result['failed'])) {
                    error_log('Browser push notification partially failed for user ' . $userId . '.');
                }
            }

            $allowEmail = $this->systemNotificationSettings['email_enabled'] && $settings['email_enabled'];
            if (array_key_exists('email', $channelOverrides)) {
                $allowEmail = $allowEmail && (bool) $channelOverrides['email'];
            }

            // 4. Email notification
            if ($allowEmail) {
                if (!$this->dispatchExternalNotification($userId, 'email', $type, $title, $description, $link, $relatedId, $context)) {
                    error_log('Failed to queue email notification for user ' . $userId . '.');
                }
            }

            // 5. SMS / WhatsApp
            $shouldSendSms = $this->systemNotificationSettings['sms_enabled'] && ($sendSms || $settings['sms_enabled']);
            $shouldSendWhatsapp = $this->systemNotificationSettings['whatsapp_enabled'] && ($sendWhatsapp || $settings['whatsapp_enabled']);
            if (array_key_exists('sms', $channelOverrides)) {
                $shouldSendSms = $shouldSendSms && (bool) $channelOverrides['sms'];
            }
            if (array_key_exists('whatsapp', $channelOverrides)) {
                $shouldSendWhatsapp = $shouldSendWhatsapp && (bool) $channelOverrides['whatsapp'];
            }

            if ($shouldSendSms) {
                if (!$this->dispatchExternalNotification($userId, 'sms', $type, $title, $description, $link, $relatedId, $context)) {
                    error_log('Failed to queue SMS notification for user ' . $userId . '.');
                }
            }
            if ($shouldSendWhatsapp) {
                if (!$this->dispatchExternalNotification($userId, 'whatsapp', $type, $title, $description, $link, $relatedId, $context)) {
                    error_log('Failed to queue WhatsApp notification for user ' . $userId . '.');
                }
            }

            return true;
        } catch (Exception $e) {
            error_log("Notification Error: " . $e->getMessage());
            return false;
        }
    }

    private function dispatchExternalNotification($userId, $channel, $type, $title, $description, $link, $relatedId, array $context = []) {
        if ($this->systemNotificationSettings['queue_enabled']) {
            return $this->queueExternalNotification($userId, $channel, $type, $title, $description, $link, $relatedId, $context);
        }

        switch ($channel) {
            case 'email':
                $result = $this->sendEmailNotification($userId, $title, $description, $type, $link, $context);
                return !empty($result['success']);

            case 'sms':
                $fullLink = $link ? BASE_URL . ltrim($link, '/') : null;
                $result = $this->sendSmsNotification($userId, $this->formatSmsBody($title, $description, $fullLink, false), false);
                return !empty($result['success']);

            case 'whatsapp':
                $fullLink = $link ? BASE_URL . ltrim($link, '/') : null;
                $result = $this->sendSmsNotification($userId, $this->formatSmsBody($title, $description, $fullLink, true), true);
                return !empty($result['success']);
        }

        return false;
    }

    /**
     * Queue an external notification channel for async processing.
     */
    private function queueExternalNotification($userId, $channel, $type, $title, $description, $link, $relatedId, array $context = []) {
        $stmt = $this->pdo->prepare("
            INSERT INTO notification_queue (
                user_id, channel, notification_type, title, description, link, related_id, context_json, status, attempts, available_at, created_at
            )
            VALUES (
                :user_id, :channel, :notification_type, :title, :description, :link, :related_id, :context_json, 'pending', 0, NOW(), NOW()
            )
        ");

        return $stmt->execute([
            'user_id' => $userId,
            'channel' => $channel,
            'notification_type' => $type,
            'title' => $title,
            'description' => $description,
            'link' => $link,
            'related_id' => $relatedId,
            'context_json' => !empty($context) ? json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
        ]);
    }

    /**
     * Format SMS/WhatsApp message body with action link
     */
    private function formatSmsBody($title, $description, $fullLink, $isWhatsapp) {
        if ($isWhatsapp) {
            $body = "*Press ERP - {$title}*\n{$description}";
            if ($fullLink) {
                $body .= "\nView it now: {$fullLink}";
            }
        } else {
            $body = "Press ERP - {$title}: {$description}";
            if ($fullLink) {
                $body .= " View: {$fullLink}";
            }
        }
        return $body;
    }

    /**
     * Create in-app notification record
     */
    private function createInAppNotification($userId, $type, $title, $description, $link, $relatedId) {
        $stmt = $this->pdo->prepare("
            INSERT INTO notifications (user_id, type, title, description, link, related_id, is_read, created_at)
            VALUES (:user_id, :type, :title, :description, :link, :related_id, FALSE, NOW())
        ");
        
        return $stmt->execute([
            'user_id' => $userId,
            'type' => $type,
            'title' => $title,
            'description' => $description,
            'link' => $link,
            'related_id' => $relatedId
        ]);
    }

    /**
     * Send email via MailManager
     */
    private function sendEmailNotification($userId, $title, $description, $type = null, $link = null, $context = []) {
        // Fetch user email and name
        $stmt = $this->pdo->prepare("SELECT name, email FROM users WHERE id = :id");
        $stmt->execute(['id' => $userId]);
        $user = $stmt->fetch();

        if ($user && !empty($user['email'])) {
            $result = $this->mailManager->sendNotificationEmail(
                $user['email'],
                $user['name'],
                $title,
                $description,
                $link,
                $type,
                $context
            );

            if (empty($result['recipient'])) {
                $result['recipient'] = $user['email'];
            }

            return $result;
        }
        return [
            'success' => false,
            'error' => 'User not found or has no email address',
            'recipient' => $user['email'] ?? null,
        ];
    }

    /**
     * Get Twilio Settings from database
     */
    private function getTwilioSettings() {
        if (function_exists('get_sms_config_settings')) {
            return get_sms_config_settings();
        }

        return [
            'sid' => '',
            'token' => '',
            'from_number' => '',
            'whatsapp_number' => ''
        ];
    }

    /**
     * Send SMS/WhatsApp via Twilio API
     */
    private function sendSmsNotification($userId, $description, $isWhatsapp = false) {
        $settings = $this->getTwilioSettings();
        if (empty($settings['sid']) || empty($settings['token'])) {
            return [
                'success' => false,
                'error' => 'Twilio SID or token is missing',
                'provider' => 'twilio',
            ];
        }

        // Fetch user phone
        $stmt = $this->pdo->prepare("SELECT phone, whatsapp_phone FROM users WHERE id = :id");
        $stmt->execute(['id' => $userId]);
        $user = $stmt->fetch();

        if (!$user) {
            return [
                'success' => false,
                'error' => "User $userId not found",
                'provider' => 'twilio',
            ];
        }
        
        if ($isWhatsapp) {
            $to_phone = $user['whatsapp_phone'] ?? '';
            if (empty($to_phone)) {
                return [
                    'success' => false,
                    'error' => "User $userId has no WhatsApp number registered to receive notifications",
                    'provider' => 'twilio',
                ];
            }
            $from_phone = $this->normalizeWhatsappAddress($settings['whatsapp_number']);
            $to_phone = $this->normalizeWhatsappAddress($to_phone);
        } else {
            $to_phone = $this->normalizePhoneNumber($user['phone']);
            if (empty($to_phone)) {
                return [
                    'success' => false,
                    'error' => "User $userId has no SMS phone number",
                    'provider' => 'twilio',
                ];
            }
            $from_phone = $this->resolveSmsFromNumber($settings);
        }

        if (empty($from_phone)) {
            return [
                'success' => false,
                'error' => $isWhatsapp
                    ? 'Twilio WhatsApp sender is missing. Configure the sandbox or WhatsApp-enabled sender number.'
                    : 'Twilio SMS sender is missing or not owned by the configured account.',
                'provider' => 'twilio',
                'recipient' => $to_phone,
            ];
        }

        $url = "https://api.twilio.com/2010-04-01/Accounts/" . $settings['sid'] . "/Messages.json";
        
        $data = [
            'From' => $from_phone,
            'To' => $to_phone,
            'Body' => substr($description, 0, 1600)
        ];

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_USERPWD, $settings['sid'] . ':' . $settings['token']);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);
        curl_close($ch);

        $decoded = json_decode((string) $response, true);
        
        if ($http_code >= 300 || $http_code == 0) {
            $apiMessage = $decoded['message'] ?? $decoded['error_message'] ?? null;
            $errorMessage = trim("Twilio HTTP Error ($http_code). cURL Error: $curl_error" . ($apiMessage ? '. API Message: ' . $apiMessage : ''));

            return [
                'success' => false,
                'error' => $errorMessage,
                'response' => $decoded,
                'provider' => 'twilio',
                'recipient' => $to_phone,
            ];
        }

        $messageStatus = strtolower((string) ($decoded['status'] ?? ''));
        $messageErrorCode = $decoded['error_code'] ?? null;
        $messageError = $decoded['error_message'] ?? $decoded['message'] ?? null;

        if (in_array($messageStatus, ['failed', 'undelivered'], true) || !empty($messageErrorCode)) {
            $providerError = 'Twilio message status "' . ($decoded['status'] ?? 'unknown') . '"';
            if (!empty($messageErrorCode)) {
                $providerError .= ' (error code ' . $messageErrorCode . ')';
            }
            if (!empty($messageError)) {
                $providerError .= ': ' . $messageError;
            }

            return [
                'success' => false,
                'error' => $providerError,
                'response' => $decoded,
                'provider' => 'twilio',
                'recipient' => $to_phone,
            ];
        }
        
        return [
            'success' => true,
            'sid' => $decoded['sid'] ?? null,
            'status' => $decoded['status'] ?? null,
            'provider' => 'twilio',
            'recipient' => $to_phone,
            'response' => $decoded,
        ];
    }

    private function normalizePhoneNumber($phone) {
        $phone = trim((string) $phone);

        if ($phone === '') {
            return '';
        }

        if (strpos($phone, 'whatsapp:') === 0) {
            $phone = substr($phone, 9);
        }

        $phone = preg_replace('/\s+/', '', $phone);

        if ($phone !== '' && $phone[0] !== '+') {
            $phone = '+' . ltrim($phone, '+');
        }

        return $phone;
    }

    private function normalizeWhatsappAddress($phone) {
        $phone = trim((string) $phone);

        if ($phone === '') {
            return '';
        }

        if (strpos($phone, 'whatsapp:') === 0) {
            $phone = substr($phone, 9);
        }

        $phone = $this->normalizePhoneNumber($phone);

        return $phone === '' ? '' : 'whatsapp:' . $phone;
    }

    private function resolveSmsFromNumber(array $settings) {
        $configuredNumber = $this->normalizePhoneNumber($settings['from_number'] ?? '');

        if ($configuredNumber === '') {
            $numbers = $this->getTwilioIncomingNumbers($settings);
            return $numbers[0] ?? '';
        }

        if (strpos($configuredNumber, 'MG') === 0) {
            return $configuredNumber;
        }

        $numbers = $this->getTwilioIncomingNumbers($settings);

        if (empty($numbers)) {
            return $configuredNumber;
        }

        if (in_array($configuredNumber, $numbers, true)) {
            return $configuredNumber;
        }

        error_log('Twilio SMS sender ' . $configuredNumber . ' is not owned by the account. Falling back to ' . $numbers[0] . '.');
        return $numbers[0];
    }

    private function getTwilioIncomingNumbers(array $settings) {
        if ($this->twilioIncomingNumbers !== null) {
            return $this->twilioIncomingNumbers;
        }

        $this->twilioIncomingNumbers = [];

        if (empty($settings['sid']) || empty($settings['token'])) {
            return $this->twilioIncomingNumbers;
        }

        $url = 'https://api.twilio.com/2010-04-01/Accounts/' . $settings['sid'] . '/IncomingPhoneNumbers.json?PageSize=20';
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_USERPWD, $settings['sid'] . ':' . $settings['token']);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($httpCode >= 300 || $httpCode === 0) {
            error_log('Unable to fetch Twilio incoming numbers. HTTP ' . $httpCode . '. cURL Error: ' . $curlError);
            return $this->twilioIncomingNumbers;
        }

        $decoded = json_decode((string) $response, true);

        foreach (($decoded['incoming_phone_numbers'] ?? []) as $number) {
            $capabilities = $number['capabilities'] ?? [];
            if (!empty($capabilities['sms']) && !empty($number['phone_number'])) {
                $this->twilioIncomingNumbers[] = $this->normalizePhoneNumber($number['phone_number']);
            }
        }

        return $this->twilioIncomingNumbers;
    }

    /**
     * Get user notification preferences
     */
    private function getUserSettings($userId, $type) {
        $cacheKey = $userId . ':' . $type;
        if (isset($this->userSettingsCache[$cacheKey])) {
            return $this->userSettingsCache[$cacheKey];
        }

        try {
            $stmt = $this->pdo->prepare("
                SELECT email_enabled, in_app_enabled, push_enabled, sms_enabled, whatsapp_enabled 
                FROM notification_settings 
                WHERE user_id = :user_id AND notification_type = :type
            ");
            $stmt->execute(['user_id' => $userId, 'type' => $type]);
            $settings = $stmt->fetch();
        } catch (Exception $exception) {
            $stmt = $this->pdo->prepare("
                SELECT email_enabled, in_app_enabled, sms_enabled, whatsapp_enabled 
                FROM notification_settings 
                WHERE user_id = :user_id AND notification_type = :type
            ");
            $stmt->execute(['user_id' => $userId, 'type' => $type]);
            $settings = $stmt->fetch();
        }

        // Default to enabled if no settings found
        if (!$settings) {
            $this->userSettingsCache[$cacheKey] = [
                'email_enabled' => false,
                'in_app_enabled' => true,
                'push_enabled' => true,
                'sms_enabled' => false,
                'whatsapp_enabled' => false
            ];
            return $this->userSettingsCache[$cacheKey];
        }

        $this->userSettingsCache[$cacheKey] = [
            'email_enabled' => (bool)$settings['email_enabled'],
            'in_app_enabled' => (bool)$settings['in_app_enabled'],
            'push_enabled' => !array_key_exists('push_enabled', $settings) ? true : (bool) $settings['push_enabled'],
            'sms_enabled' => (bool)$settings['sms_enabled'],
            'whatsapp_enabled' => (bool)$settings['whatsapp_enabled']
        ];
        return $this->userSettingsCache[$cacheKey];
    }

    /**
     * Process queued external notifications.
     */
    public function processQueue($limit = 25, array $options = []) {
        $stats = [
            'processed' => 0,
            'sent' => 0,
            'failed' => 0,
            'retried' => 0,
        ];

        $force = !empty($options['force']);
        $triggerSource = (string) ($options['trigger_source'] ?? 'queue_worker');
        $triggeredByUserId = !empty($options['triggered_by_user_id']) ? (int) $options['triggered_by_user_id'] : null;

        if (
            !$force
            && (!$this->systemNotificationSettings['notifications_enabled'] || !$this->systemNotificationSettings['queue_enabled'])
        ) {
            return $stats;
        }

        if ($limit === 25) {
            $limit = max(1, (int) ($this->systemNotificationSettings['batch_size'] ?? 25));
        }

        $selectStmt = $this->pdo->prepare("
            SELECT *
            FROM notification_queue
            WHERE status = 'pending' AND available_at <= NOW()
            ORDER BY created_at ASC
            LIMIT :limit
        ");
        $selectStmt->bindValue(':limit', (int) $limit, PDO::PARAM_INT);
        $selectStmt->execute();
        $jobs = $selectStmt->fetchAll();

        if (empty($jobs)) {
            return $stats;
        }

        $claimStmt = $this->pdo->prepare("
            UPDATE notification_queue
            SET status = 'processing'
            WHERE id = :id AND status = 'pending'
        ");

        foreach ($jobs as $job) {
            $claimStmt->execute(['id' => $job['id']]);
            if ($claimStmt->rowCount() !== 1) {
                continue;
            }

            $stats['processed']++;
            $attemptNumber = ((int) $job['attempts']) + 1;
            $result = $this->deliverQueuedNotification($job);

            if (!empty($result['success'])) {
                $this->markQueuedNotificationSent((int) $job['id']);
                $this->logQueuedDeliveryAttempt($job, $result, 'sent', $attemptNumber, $triggerSource, $triggeredByUserId);
                $stats['sent']++;
                continue;
            }

            $attempts = $attemptNumber;
            $retryDelayMinutes = min(30, max(1, $attempts * 2));
            $errorMessage = $result['error'] ?? $result['message'] ?? 'Unknown delivery error';

            $retryLimit = max(1, (int) ($this->systemNotificationSettings['retry_limit'] ?? 3));

            if ($attempts >= $retryLimit) {
                $this->markQueuedNotificationFailed((int) $job['id'], $attempts, $errorMessage, true, $retryDelayMinutes);
                $this->logQueuedDeliveryAttempt($job, $result, 'failed', $attemptNumber, $triggerSource, $triggeredByUserId);
                $stats['failed']++;
            } else {
                $this->markQueuedNotificationFailed((int) $job['id'], $attempts, $errorMessage, false, $retryDelayMinutes);
                $this->logQueuedDeliveryAttempt($job, $result, 'retried', $attemptNumber, $triggerSource, $triggeredByUserId);
                $stats['retried']++;
            }
        }

        return $stats;
    }

    public function requeueFailedJobs($limit = 25, $channel = null, $triggeredByUserId = null, $triggerSource = 'manual_requeue') {
        $allowedChannels = ['email', 'sms', 'whatsapp'];
        $channel = in_array($channel, $allowedChannels, true) ? $channel : null;

        $sql = "
            SELECT id, user_id, channel, notification_type, title, attempts
            FROM notification_queue
            WHERE status = 'failed'
        ";
        if ($channel !== null) {
            $sql .= " AND channel = :channel";
        }
        $sql .= "
            ORDER BY processed_at DESC, created_at DESC
            LIMIT :limit
        ";

        $selectStmt = $this->pdo->prepare($sql);
        if ($channel !== null) {
            $selectStmt->bindValue(':channel', $channel, PDO::PARAM_STR);
        }
        $selectStmt->bindValue(':limit', max(1, (int) $limit), PDO::PARAM_INT);
        $selectStmt->execute();
        $jobs = $selectStmt->fetchAll();

        if (empty($jobs)) {
            return 0;
        }

        $updateStmt = $this->pdo->prepare("
            UPDATE notification_queue
            SET status = 'pending',
                processed_at = NULL,
                available_at = NOW(),
                last_error = NULL
            WHERE id = :id AND status = 'failed'
        ");

        $requeued = 0;

        foreach ($jobs as $job) {
            $updateStmt->execute(['id' => $job['id']]);
            if ($updateStmt->rowCount() !== 1) {
                continue;
            }

            $requeued++;
            delivery_logs_record($this->pdo, [
                'queue_name' => 'notification_queue',
                'queue_item_id' => $job['id'],
                'channel' => $job['channel'],
                'notification_type' => $job['notification_type'],
                'subject_line' => $job['title'],
                'recipient' => $this->resolveQueuedJobRecipient($job),
                'provider' => $job['channel'] === 'email' ? 'mail' : 'twilio',
                'status' => 'requeued',
                'attempt_number' => (int) $job['attempts'],
                'trigger_source' => (string) $triggerSource,
                'triggered_by_user_id' => $triggeredByUserId ? (int) $triggeredByUserId : null,
                'message' => 'Notification queue item requeued for delivery.',
            ]);
        }

        return $requeued;
    }

    private function deliverQueuedNotification(array $job) {
        $context = [];
        if (!empty($job['context_json'])) {
            $decoded = json_decode((string) $job['context_json'], true);
            if (is_array($decoded)) {
                $context = $decoded;
            }
        }

        switch ($job['channel']) {
            case 'email':
                return $this->sendEmailNotification(
                    (int) $job['user_id'],
                    $job['title'],
                    $job['description'],
                    $job['notification_type'],
                    $job['link'],
                    $context
                );

            case 'sms':
                $fullLink = !empty($job['link']) ? BASE_URL . ltrim((string) $job['link'], '/') : null;
                return $this->sendSmsNotification(
                    (int) $job['user_id'],
                    $this->formatSmsBody($job['title'], $job['description'], $fullLink, false),
                    false
                );

            case 'whatsapp':
                $fullLink = !empty($job['link']) ? BASE_URL . ltrim((string) $job['link'], '/') : null;
                return $this->sendSmsNotification(
                    (int) $job['user_id'],
                    $this->formatSmsBody($job['title'], $job['description'], $fullLink, true),
                    true
                );

            default:
                return [
                    'success' => false,
                    'error' => 'Unsupported notification channel: ' . $job['channel'],
                ];
        }
    }

    private function markQueuedNotificationSent($jobId) {
        $stmt = $this->pdo->prepare("
            UPDATE notification_queue
            SET status = 'sent', processed_at = NOW(), last_error = NULL
            WHERE id = :id
        ");
        $stmt->execute(['id' => $jobId]);
    }

    private function markQueuedNotificationFailed($jobId, $attempts, $errorMessage, $terminalFailure, $retryDelayMinutes) {
        if ($terminalFailure) {
            $stmt = $this->pdo->prepare("
                UPDATE notification_queue
                SET status = 'failed',
                    attempts = :attempts,
                    processed_at = NOW(),
                    last_error = :last_error
                WHERE id = :id
            ");
            $stmt->execute([
                'id' => $jobId,
                'attempts' => $attempts,
                'last_error' => mb_substr((string) $errorMessage, 0, 1000),
            ]);
            return;
        }

        $stmt = $this->pdo->prepare("
            UPDATE notification_queue
            SET status = 'pending',
                attempts = :attempts,
                last_error = :last_error,
                available_at = DATE_ADD(NOW(), INTERVAL :retry_delay MINUTE)
            WHERE id = :id
        ");
        $stmt->bindValue(':id', $jobId, PDO::PARAM_INT);
        $stmt->bindValue(':attempts', $attempts, PDO::PARAM_INT);
        $stmt->bindValue(':last_error', mb_substr((string) $errorMessage, 0, 1000), PDO::PARAM_STR);
        $stmt->bindValue(':retry_delay', $retryDelayMinutes, PDO::PARAM_INT);
        $stmt->execute();
    }

    private function logQueuedDeliveryAttempt(array $job, array $result, $status, $attemptNumber, $triggerSource, $triggeredByUserId = null) {
        delivery_logs_record($this->pdo, [
            'queue_name' => 'notification_queue',
            'queue_item_id' => $job['id'] ?? null,
            'channel' => $job['channel'] ?? 'email',
            'notification_type' => $job['notification_type'] ?? null,
            'subject_line' => $job['title'] ?? null,
            'recipient' => $result['recipient'] ?? $this->resolveQueuedJobRecipient($job),
            'provider' => $result['provider'] ?? (($job['channel'] ?? 'email') === 'email' ? 'mail' : 'twilio'),
            'status' => $status,
            'attempt_number' => max(1, (int) $attemptNumber),
            'trigger_source' => (string) $triggerSource,
            'triggered_by_user_id' => $triggeredByUserId ? (int) $triggeredByUserId : null,
            'message' => $result['message'] ?? ($status === 'sent'
                ? 'Queued notification delivered successfully.'
                : 'Queued notification delivery attempt failed.'),
            'error_message' => $result['error'] ?? null,
            'response_json' => $result['response'] ?? $result,
        ]);
    }

    private function resolveQueuedJobRecipient(array $job) {
        $channel = (string) ($job['channel'] ?? '');
        $userId = isset($job['user_id']) ? (int) $job['user_id'] : 0;

        if ($userId <= 0 || !in_array($channel, ['email', 'sms', 'whatsapp'], true)) {
            return null;
        }

        $stmt = $this->pdo->prepare("SELECT email, phone, whatsapp_phone FROM users WHERE id = :id LIMIT 1");
        $stmt->execute(['id' => $userId]);
        $user = $stmt->fetch();

        if (!$user) {
            return null;
        }

        if ($channel === 'email') {
            return $user['email'] ?? null;
        }

        if ($channel === 'sms') {
            return $this->normalizePhoneNumber($user['phone'] ?? '');
        }

        return $this->normalizeWhatsappAddress($user['whatsapp_phone'] ?? '');
    }

    /**
     * Mark notification as read
     */
    public function markAsRead($notificationId, $userId) {
        $stmt = $this->pdo->prepare("
            UPDATE notifications SET is_read = TRUE 
            WHERE id = :id AND user_id = :user_id
        ");
        return $stmt->execute(['id' => $notificationId, 'user_id' => $userId]);
    }

    /**
     * Get unread notifications for a user
     */
    public function getUnread($userId, $limit = 5) {
        $stmt = $this->pdo->prepare("
            SELECT * FROM notifications 
            WHERE user_id = :user_id AND is_read = FALSE 
            ORDER BY created_at DESC 
            LIMIT :limit
        ");
        $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Get all notifications for a user (paginated)
     */
    public function getAll($userId, $limit = 20, $offset = 0) {
        $stmt = $this->pdo->prepare("
            SELECT * FROM notifications 
            WHERE user_id = :user_id 
            ORDER BY created_at DESC 
            LIMIT :limit OFFSET :offset
        ");
        $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Get unread count
     */
    public function getUnreadCount($userId) {
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*) FROM notifications 
            WHERE user_id = :user_id AND is_read = FALSE
        ");
        $stmt->execute(['user_id' => $userId]);
        return $stmt->fetchColumn();
    }
}
