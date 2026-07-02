<?php
/**
 * Email Helper Functions
 * 
 * Common email utility functions used across the system
 */

require_once __DIR__ . '/../config/mail.php';
require_once __DIR__ . '/../libs/MailManager.php';
require_once __DIR__ . '/delivery_log_helper.php';

/**
 * Send a notification email to a user
 * 
 * @param PDO $pdo Database connection
 * @param int $userId User ID to send to
 * @param string $title Notification title
 * @param string $message Notification message
 * @return array Result array with success/error
 */
function sendUserNotification($pdo, $userId, $title, $message) {
    $stmt = $pdo->prepare("SELECT name, email FROM users WHERE id = :id");
    $stmt->execute(['id' => $userId]);
    $user = $stmt->fetch();

    if (!$user || !$user['email']) {
        return [
            'success' => false,
            'error' => 'User not found or has no email'
        ];
    }

    $mailSettings = getMailSettings();
    $mailManager = new MailManager($pdo, $mailSettings);

    return $mailManager->sendNotificationEmail(
        $user['email'],
        $user['name'],
        $title,
        $message
    );
}

/**
 * Send dispatch status notification email
 * 
 * @param PDO $pdo Database connection
 * @param int $userId User ID to send to
 * @param string $dispatchNumber Dispatch reference number
 * @param string $status New dispatch status
 * @return array Result array with success/error
 */
function sendDispatchNotification($pdo, $userId, $dispatchNumber, $status) {
    $stmt = $pdo->prepare("SELECT name, email FROM users WHERE id = :id");
    $stmt->execute(['id' => $userId]);
    $user = $stmt->fetch();

    if (!$user || !$user['email']) {
        return [
            'success' => false,
            'error' => 'User not found or has no email'
        ];
    }

    $mailSettings = getMailSettings();
    $mailManager = new MailManager($pdo, $mailSettings);

    return $mailManager->sendDispatchNotificationEmail(
        $user['email'],
        $user['name'],
        $dispatchNumber,
        $status
    );
}

/**
 * Send password reset email
 * 
 * @param PDO $pdo Database connection
 * @param string $email Email address
 * @param string $userName User name
 * @param string $resetLink Password reset link
 * @return array Result array with success/error
 */
function sendPasswordReset($pdo, $email, $userName, $resetLink) {
    $mailSettings = getMailSettings();
    $mailManager = new MailManager($pdo, $mailSettings);

    return $mailManager->sendPasswordResetEmail(
        $email,
        $userName,
        $resetLink
    );
}

/**
 * Queue an email for later sending (for future batch processing)
 * 
 * @param PDO $pdo Database connection
 * @param string $recipient Email address
 * @param string $subject Email subject
 * @param string $body Email body
 * @param int $priority Queue priority (1-10, default 5)
 * @return bool Success/failure
 */
function queueEmail($pdo, $recipient, $subject, $body, $priority = 5) {
    try {
        $stmt = $pdo->prepare("
            INSERT INTO email_queue (recipient, subject, body, priority, status)
            VALUES (:recipient, :subject, :body, :priority, 'pending')
        ");

        return $stmt->execute([
            'recipient' => $recipient,
            'subject' => $subject,
            'body' => $body,
            'priority' => min(10, max(1, $priority))
        ]);
    } catch (Exception $e) {
        error_log('Failed to queue email: ' . $e->getMessage());
        return false;
    }
}

/**
 * Get email log for a specific recipient
 * 
 * @param PDO $pdo Database connection
 * @param string $recipient Email address
 * @param int $limit Number of records to retrieve
 * @return array Array of email log records
 */
function getEmailLog($pdo, $recipient, $limit = 50) {
    $stmt = $pdo->prepare("
        SELECT id, recipient, subject, success, error_message, sent_at
        FROM email_log
        WHERE recipient = :recipient
        ORDER BY sent_at DESC
        LIMIT :limit
    ");

    $stmt->bindValue(':recipient', $recipient);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();

    return $stmt->fetchAll();
}

/**
 * Get failed emails for retry
 * 
 * @param PDO $pdo Database connection
 * @param int $limit Number of records to retrieve
 * @return array Array of failed email queue records
 */
function getFailedEmails($pdo, $limit = 50) {
    $stmt = $pdo->prepare("
        SELECT id, recipient, subject, attempts, last_attempt
        FROM email_queue
        WHERE status = 'failed'
        ORDER BY last_attempt DESC
        LIMIT :limit
    ");

    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();

    return $stmt->fetchAll();
}

/**
 * Requeue failed email queue records for another delivery attempt.
 *
 * @param PDO $pdo Database connection
 * @param int $limit Number of records to requeue
 * @param int|null $triggeredByUserId User who initiated the retry
 * @param string $triggerSource Queue trigger source
 * @return int Number of rows requeued
 */
function requeueFailedEmails($pdo, $limit = 50, $triggeredByUserId = null, $triggerSource = 'manual_requeue') {
    $selectStmt = $pdo->prepare("
        SELECT id, recipient, subject, attempts
        FROM email_queue
        WHERE status = 'failed'
        ORDER BY last_attempt DESC, created_at DESC
        LIMIT :limit
    ");
    $selectStmt->bindValue(':limit', max(1, (int) $limit), PDO::PARAM_INT);
    $selectStmt->execute();
    $emails = $selectStmt->fetchAll();

    if (empty($emails)) {
        return 0;
    }

    $updateStmt = $pdo->prepare("
        UPDATE email_queue
        SET status = 'pending',
            last_error = NULL
        WHERE id = :id AND status = 'failed'
    ");

    $requeued = 0;

    foreach ($emails as $email) {
        $updateStmt->execute(['id' => $email['id']]);
        if ($updateStmt->rowCount() !== 1) {
            continue;
        }

        $requeued++;

        delivery_logs_record($pdo, [
            'queue_name' => 'email_queue',
            'queue_item_id' => $email['id'],
            'channel' => 'email',
            'subject_line' => $email['subject'],
            'recipient' => $email['recipient'],
            'provider' => 'mail',
            'status' => 'requeued',
            'attempt_number' => (int) $email['attempts'],
            'trigger_source' => (string) $triggerSource,
            'triggered_by_user_id' => $triggeredByUserId ? (int) $triggeredByUserId : null,
            'message' => 'Email queue item requeued for delivery.',
        ]);
    }

    return $requeued;
}

/**
 * Process email queue (sends pending emails)
 * Called by cron job or manual trigger
 * 
 * @param PDO $pdo Database connection
 * @param int $batchSize Number of emails to process per call
 * @return array Result statistics
 */
function processEmailQueue($pdo, $batchSize = 50, $options = []) {
    $stats = [
        'processed' => 0,
        'sent' => 0,
        'failed' => 0
    ];

    static $emailQueueHasLastError = null;

    try {
        $force = !empty($options['force']);
        $triggerSource = (string) ($options['trigger_source'] ?? 'queue_worker');
        $triggeredByUserId = !empty($options['triggered_by_user_id']) ? (int) $options['triggered_by_user_id'] : null;

        if (!$force && function_exists('isMailQueueEnabled') && !isMailQueueEnabled()) {
            return $stats;
        }

        if ($batchSize === 50 && function_exists('get_setting')) {
            $batchSize = max(1, (int) get_setting('mail_queue_batch_size', 50));
        }

        // Get pending emails
        $stmt = $pdo->prepare("
            SELECT id, recipient, subject, body, attempts
            FROM email_queue
            WHERE status = 'pending'
            ORDER BY priority DESC, created_at ASC
            LIMIT :limit
        ");

        $stmt->bindValue(':limit', $batchSize, PDO::PARAM_INT);
        $stmt->execute();
        $emails = $stmt->fetchAll();

        $mailSettings = getMailSettings();
        $mailManager = new MailManager($pdo, $mailSettings);

        if ($emailQueueHasLastError === null) {
            try {
                $columnStmt = $pdo->prepare("
                    SELECT COUNT(*)
                    FROM information_schema.COLUMNS
                    WHERE TABLE_SCHEMA = DATABASE()
                      AND TABLE_NAME = 'email_queue'
                      AND COLUMN_NAME = 'last_error'
                ");
                $columnStmt->execute();
                $emailQueueHasLastError = ((int) $columnStmt->fetchColumn()) > 0;
            } catch (Exception $e) {
                $emailQueueHasLastError = false;
            }
        }

        $claimStmt = $pdo->prepare("
            UPDATE email_queue
            SET status = 'processing'
            WHERE id = :id AND status = 'pending'
        ");

        foreach ($emails as $email) {
            $claimStmt->execute(['id' => $email['id']]);
            if ($claimStmt->rowCount() !== 1) {
                continue;
            }

            $stats['processed']++;
            $attemptNumber = ((int) ($email['attempts'] ?? 0)) + 1;

            $result = $mailManager->send(
                $email['recipient'],
                $email['subject'],
                $email['body']
            );

            if ($result['success']) {
                // Mark as sent
                $updateSql = $emailQueueHasLastError
                    ? "UPDATE email_queue SET status = 'sent', last_attempt = NOW(), last_error = NULL WHERE id = :id"
                    : "UPDATE email_queue SET status = 'sent', last_attempt = NOW() WHERE id = :id";
                $updateStmt = $pdo->prepare($updateSql);
                $updateStmt->execute(['id' => $email['id']]);

                delivery_logs_record($pdo, [
                    'queue_name' => 'email_queue',
                    'queue_item_id' => $email['id'],
                    'channel' => 'email',
                    'subject_line' => $email['subject'],
                    'recipient' => $result['recipient'] ?? $email['recipient'],
                    'provider' => $result['provider'] ?? 'mail',
                    'status' => 'sent',
                    'attempt_number' => $attemptNumber,
                    'trigger_source' => $triggerSource,
                    'triggered_by_user_id' => $triggeredByUserId,
                    'message' => $result['message'] ?? 'Queued email delivered successfully.',
                    'response_json' => $result,
                ]);
                $stats['sent']++;
            } else {
                // Update failure info
                $errorMessage = mb_substr((string) ($result['error'] ?? $result['message'] ?? 'Unknown email delivery failure'), 0, 1000);
                $updateSql = $emailQueueHasLastError
                    ? "UPDATE email_queue SET status = 'failed', attempts = attempts + 1, last_attempt = NOW(), last_error = :last_error WHERE id = :id"
                    : "UPDATE email_queue SET status = 'failed', attempts = attempts + 1, last_attempt = NOW() WHERE id = :id";
                $updateStmt = $pdo->prepare($updateSql);
                $params = ['id' => $email['id']];
                if ($emailQueueHasLastError) {
                    $params['last_error'] = $errorMessage;
                }
                $updateStmt->execute($params);

                delivery_logs_record($pdo, [
                    'queue_name' => 'email_queue',
                    'queue_item_id' => $email['id'],
                    'channel' => 'email',
                    'subject_line' => $email['subject'],
                    'recipient' => $result['recipient'] ?? $email['recipient'],
                    'provider' => $result['provider'] ?? 'mail',
                    'status' => 'failed',
                    'attempt_number' => $attemptNumber,
                    'trigger_source' => $triggerSource,
                    'triggered_by_user_id' => $triggeredByUserId,
                    'message' => $result['message'] ?? 'Queued email delivery failed.',
                    'error_message' => $errorMessage,
                    'response_json' => $result,
                ]);
                $stats['failed']++;
            }
        }

        if (($stats['processed'] > 0 || $stats['failed'] > 0) && file_exists(__DIR__ . '/../libs/AuditLogger.php')) {
            require_once __DIR__ . '/../libs/AuditLogger.php';
            $auditLogger = new AuditLogger($pdo);
            $auditLogger->log(
                'queue',
                'email_queue_batch_processed',
                'Email queue batch processed.',
                [
                    'severity' => $stats['failed'] > 0 ? 'warning' : 'info',
                    'status' => $stats['failed'] > 0 ? 'partial_failure' : 'success',
                    'context' => array_merge($stats, [
                        'trigger_source' => $triggerSource,
                        'triggered_by_user_id' => $triggeredByUserId,
                    ]),
                ]
            );
        }
    } catch (Exception $e) {
        error_log('Email queue processing error: ' . $e->getMessage());
    }

    return $stats;
}
?>
