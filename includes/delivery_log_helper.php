<?php
/**
 * Delivery attempt logging for queued email, SMS, and WhatsApp jobs.
 */

if (!function_exists('delivery_logs_table_exists')) {
    function delivery_logs_table_exists(PDO $pdo): bool
    {
        static $cache = null;

        if ($cache !== null) {
            return $cache;
        }

        try {
            $stmt = $pdo->prepare("
                SELECT COUNT(*)
                FROM information_schema.tables
                WHERE table_schema = DATABASE()
                  AND table_name = ?
            ");
            $stmt->execute(['notification_dispatch_logs']);
            $cache = ((int) $stmt->fetchColumn()) > 0;
        } catch (Exception $exception) {
            $cache = false;
        }

        return $cache;
    }
}

if (!function_exists('delivery_logs_encode_payload')) {
    function delivery_logs_encode_payload($payload): ?string
    {
        if ($payload === null || $payload === '' || $payload === []) {
            return null;
        }

        if (is_string($payload)) {
            return mb_substr($payload, 0, 60000);
        }

        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return $json === false ? null : mb_substr($json, 0, 60000);
    }
}

if (!function_exists('delivery_logs_record')) {
    function delivery_logs_record(PDO $pdo, array $payload): bool
    {
        if (!delivery_logs_table_exists($pdo)) {
            return false;
        }

        try {
            $stmt = $pdo->prepare("
                INSERT INTO notification_dispatch_logs (
                    queue_name,
                    queue_item_id,
                    channel,
                    notification_type,
                    subject_line,
                    recipient,
                    provider,
                    status,
                    attempt_number,
                    trigger_source,
                    triggered_by_user_id,
                    message,
                    error_message,
                    response_json,
                    created_at
                ) VALUES (
                    :queue_name,
                    :queue_item_id,
                    :channel,
                    :notification_type,
                    :subject_line,
                    :recipient,
                    :provider,
                    :status,
                    :attempt_number,
                    :trigger_source,
                    :triggered_by_user_id,
                    :message,
                    :error_message,
                    :response_json,
                    NOW()
                )
            ");

            return $stmt->execute([
                'queue_name' => mb_substr((string) ($payload['queue_name'] ?? 'notification_queue'), 0, 50),
                'queue_item_id' => isset($payload['queue_item_id']) ? (int) $payload['queue_item_id'] : null,
                'channel' => mb_substr((string) ($payload['channel'] ?? 'email'), 0, 20),
                'notification_type' => isset($payload['notification_type']) ? mb_substr((string) $payload['notification_type'], 0, 50) : null,
                'subject_line' => isset($payload['subject_line']) ? mb_substr((string) $payload['subject_line'], 0, 255) : null,
                'recipient' => isset($payload['recipient']) ? mb_substr((string) $payload['recipient'], 0, 255) : null,
                'provider' => isset($payload['provider']) ? mb_substr((string) $payload['provider'], 0, 50) : null,
                'status' => mb_substr((string) ($payload['status'] ?? 'sent'), 0, 50),
                'attempt_number' => max(0, (int) ($payload['attempt_number'] ?? 0)),
                'trigger_source' => mb_substr((string) ($payload['trigger_source'] ?? 'queue_worker'), 0, 50),
                'triggered_by_user_id' => !empty($payload['triggered_by_user_id']) ? (int) $payload['triggered_by_user_id'] : null,
                'message' => mb_substr((string) ($payload['message'] ?? 'Delivery event recorded.'), 0, 255),
                'error_message' => isset($payload['error_message']) ? mb_substr((string) $payload['error_message'], 0, 5000) : null,
                'response_json' => delivery_logs_encode_payload($payload['response_json'] ?? null),
            ]);
        } catch (Exception $exception) {
            error_log('Unable to write notification dispatch log: ' . $exception->getMessage());
            return false;
        }
    }
}
