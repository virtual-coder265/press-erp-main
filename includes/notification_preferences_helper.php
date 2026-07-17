<?php

if (!function_exists('notification_pref_types')) {
    function notification_pref_types(): array
    {
        return ['message', 'task', 'security', 'reminder', 'work_order'];
    }
}

if (!function_exists('notification_prefs_user_is_configured')) {
    /**
     * True when the user has saved notification preferences for every type
     * with core channels (in-app, push, email) enabled.
     */
    function notification_prefs_user_is_configured(PDO $pdo, int $userId): bool
    {
        if ($userId <= 0) {
            return false;
        }

        try {
            $stmt = $pdo->prepare("
                SELECT COUNT(*) AS configured_count
                FROM notification_settings
                WHERE user_id = :user_id
                  AND in_app_enabled = 1
                  AND push_enabled = 1
                  AND email_enabled = 1
            ");
            $stmt->execute(['user_id' => $userId]);
            $count = (int) $stmt->fetchColumn();

            return $count >= count(notification_pref_types());
        } catch (Throwable $exception) {
            return false;
        }
    }
}

if (!function_exists('notification_prefs_user_has_push_subscription')) {
    function notification_prefs_user_has_push_subscription(PDO $pdo, int $userId): bool
    {
        if ($userId <= 0) {
            return false;
        }

        try {
            if (!function_exists('setting_truthy') || !setting_truthy('notification_push_enabled', true)) {
                return true;
            }

            $stmt = $pdo->prepare("
                SELECT COUNT(*)
                FROM web_push_subscriptions
                WHERE user_id = :user_id AND is_active = 1
            ");
            $stmt->execute(['user_id' => $userId]);

            return (int) $stmt->fetchColumn() > 0;
        } catch (Throwable $exception) {
            return false;
        }
    }
}

if (!function_exists('notification_prefs_user_needs_setup')) {
    function notification_prefs_user_needs_setup(PDO $pdo, int $userId): bool
    {
        if ($userId <= 0) {
            return false;
        }

        if (!notification_prefs_user_is_configured($pdo, $userId)) {
            return true;
        }

        return !notification_prefs_user_has_push_subscription($pdo, $userId);
    }
}

if (!function_exists('notification_prefs_apply_for_user')) {
    /**
     * Seed or update per-type notification channel preferences.
     *
     * @param array{phone?:string,whatsapp_phone?:string,force?:bool} $options
     */
    function notification_prefs_apply_for_user(PDO $pdo, int $userId, array $options = []): bool
    {
        if ($userId <= 0) {
            return false;
        }

        $phone = trim((string) ($options['phone'] ?? ''));
        $whatsappPhone = trim((string) ($options['whatsapp_phone'] ?? ''));
        $force = !empty($options['force']);

        if ($phone === '' && $whatsappPhone === '') {
            $stmt = $pdo->prepare('SELECT phone, whatsapp_phone FROM users WHERE id = ? LIMIT 1');
            $stmt->execute([$userId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $phone = trim((string) ($user['phone'] ?? ''));
            $whatsappPhone = trim((string) ($user['whatsapp_phone'] ?? ''));
        }

        $smsEnabled = $phone !== '' ? 1 : 0;
        $whatsappEnabled = $whatsappPhone !== '' ? 1 : 0;

        try {
            $existsStmt = $pdo->prepare('
                SELECT id
                FROM notification_settings
                WHERE user_id = :user_id AND notification_type = :type
                LIMIT 1
            ');
            $updateStmt = $pdo->prepare('
                UPDATE notification_settings
                SET email_enabled = 1,
                    in_app_enabled = 1,
                    push_enabled = 1,
                    sms_enabled = :sms_enabled,
                    whatsapp_enabled = :whatsapp_enabled
                WHERE user_id = :user_id AND notification_type = :type
            ');
            $insertStmt = $pdo->prepare('
                INSERT INTO notification_settings (
                    user_id,
                    notification_type,
                    email_enabled,
                    in_app_enabled,
                    push_enabled,
                    sms_enabled,
                    whatsapp_enabled
                )
                VALUES (:user_id, :type, 1, 1, 1, :sms_enabled, :whatsapp_enabled)
            ');

            foreach (notification_pref_types() as $type) {
                $params = [
                    'user_id' => $userId,
                    'type' => $type,
                    'sms_enabled' => $smsEnabled,
                    'whatsapp_enabled' => $whatsappEnabled,
                ];

                $existsStmt->execute([
                    'user_id' => $userId,
                    'type' => $type,
                ]);

                if ($existsStmt->fetchColumn()) {
                    if ($force) {
                        $updateStmt->execute($params);
                    }
                    continue;
                }

                $insertStmt->execute($params);
            }

            return true;
        } catch (Throwable $exception) {
            error_log('notification_prefs_apply_for_user failed: ' . $exception->getMessage());
            return false;
        }
    }
}

if (!function_exists('notification_prefs_runtime_defaults')) {
    function notification_prefs_runtime_defaults(?array $user = null): array
    {
        $phone = trim((string) ($user['phone'] ?? ''));
        $whatsappPhone = trim((string) ($user['whatsapp_phone'] ?? ''));

        return [
            'email_enabled' => true,
            'in_app_enabled' => true,
            'push_enabled' => true,
            'sms_enabled' => $phone !== '',
            'whatsapp_enabled' => $whatsappPhone !== '',
        ];
    }
}
