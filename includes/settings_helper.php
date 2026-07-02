<?php
/**
 * Shared settings helper for database + .env backed configuration.
 */

if (!function_exists('settings_project_root')) {
    function settings_project_root() {
        return defined('ROOT_PATH') ? ROOT_PATH : dirname(__DIR__) . DIRECTORY_SEPARATOR;
    }
}

if (!function_exists('settings_env_file_path')) {
    function settings_env_file_path() {
        return settings_project_root() . '.env';
    }
}

if (!function_exists('settings_bool_env')) {
    function settings_bool_env($key, $default = false) {
        $value = env($key, $default ? 'true' : 'false');
        $normalized = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        return $normalized === null ? (bool) $default : $normalized;
    }
}

if (!function_exists('get_settings_registry')) {
    function get_settings_registry() {
        static $registry = null;

        if ($registry !== null) {
            return $registry;
        }

        $registry = [
            'business_name' => ['group' => 'business', 'type' => 'string', 'default' => env('APP_NAME', 'Gov Press ERP')],
            'business_address' => ['group' => 'business', 'type' => 'string', 'default' => ''],
            'business_tax_id' => ['group' => 'business', 'type' => 'string', 'default' => ''],
            'business_registration_number' => ['group' => 'business', 'type' => 'string', 'default' => ''],
            'business_logo' => ['group' => 'business', 'type' => 'string', 'default' => ''],
            'business_phone' => ['group' => 'contact', 'type' => 'string', 'default' => ''],
            'business_email' => ['group' => 'contact', 'type' => 'string', 'default' => ''],
            'business_website' => ['group' => 'contact', 'type' => 'string', 'default' => ''],
            'invoice_terms' => ['group' => 'invoice', 'type' => 'string', 'default' => ''],
            'invoice_footer' => ['group' => 'invoice', 'type' => 'string', 'default' => 'Thank you for your business!'],
            'invoice_prefix' => ['group' => 'invoice', 'type' => 'string', 'default' => 'INV'],
            'invoice_due_days' => ['group' => 'invoice', 'type' => 'int', 'default' => 30],
            'signature1_title' => ['group' => 'signature', 'type' => 'string', 'default' => 'Authorized Signature'],
            'signature2_title' => ['group' => 'signature', 'type' => 'string', 'default' => 'Customer Signature'],
            'signature3_title' => ['group' => 'signature', 'type' => 'string', 'default' => 'Date'],
            'bank_name' => ['group' => 'bank', 'type' => 'string', 'default' => ''],
            'account_number' => ['group' => 'bank', 'type' => 'string', 'default' => ''],
            'bank_branch' => ['group' => 'bank', 'type' => 'string', 'default' => ''],
            'swift_code' => ['group' => 'bank', 'type' => 'string', 'default' => ''],
            'iban' => ['group' => 'bank', 'type' => 'string', 'default' => ''],
            'billing_layout_json' => ['group' => 'billing', 'type' => 'string', 'default' => ''],
            'system_app_name' => ['group' => 'system', 'type' => 'string', 'default' => env('APP_NAME', 'Gov Press ERP'), 'env_key' => 'APP_NAME', 'persist_env' => true, 'persist_db' => true],
            'system_tagline' => ['group' => 'system', 'type' => 'string', 'default' => 'Government Press Operations Suite'],
            'system_logo' => ['group' => 'system', 'type' => 'string', 'default' => ''],
            'system_favicon' => ['group' => 'system', 'type' => 'string', 'default' => ''],
            'system_operation_mode' => ['group' => 'system', 'type' => 'string', 'default' => env('ERP_OPERATION_MODE', 'standard'), 'env_key' => 'ERP_OPERATION_MODE', 'persist_env' => true, 'persist_db' => true],
            'system_timezone' => ['group' => 'system', 'type' => 'string', 'default' => env('APP_TIMEZONE', 'Africa/Blantyre'), 'env_key' => 'APP_TIMEZONE', 'persist_env' => true, 'persist_db' => true],
            'default_currency' => ['group' => 'system', 'type' => 'string', 'default' => 'MWK'],
            'default_country_code' => ['group' => 'system', 'type' => 'string', 'default' => '+265'],
            'date_format' => ['group' => 'system', 'type' => 'string', 'default' => 'Y-m-d'],
            'time_format' => ['group' => 'system', 'type' => 'string', 'default' => 'H:i'],
            'records_per_page' => ['group' => 'system', 'type' => 'int', 'default' => 25],
            'maintenance_mode' => ['group' => 'system', 'type' => 'bool', 'default' => settings_bool_env('MAINTENANCE_MODE', false), 'env_key' => 'MAINTENANCE_MODE', 'persist_env' => true, 'persist_db' => true],
            'profile_enforcement_enabled' => ['group' => 'system', 'type' => 'bool', 'default' => true],
            'allow_self_registration' => ['group' => 'system', 'type' => 'bool', 'default' => false],
            'enforce_strong_passwords' => ['group' => 'system', 'type' => 'bool', 'default' => true],
            'enable_audit_logs' => ['group' => 'system', 'type' => 'bool', 'default' => true],
            'enable_multi_branch' => ['group' => 'system', 'type' => 'bool', 'default' => true],
            'enable_approval_workflows' => ['group' => 'system', 'type' => 'bool', 'default' => true],
            'enable_project_billing' => ['group' => 'system', 'type' => 'bool', 'default' => true],
            'enable_priority_dispatch' => ['group' => 'system', 'type' => 'bool', 'default' => true],
            'enable_advanced_estimation_rules' => ['group' => 'system', 'type' => 'bool', 'default' => false],
            'security_login_monitoring_enabled' => ['group' => 'security', 'type' => 'bool', 'default' => true],
            'security_failed_login_threshold' => ['group' => 'security', 'type' => 'int', 'default' => 5],
            'security_failed_login_window_minutes' => ['group' => 'security', 'type' => 'int', 'default' => 15],
            'security_block_duration_minutes' => ['group' => 'security', 'type' => 'int', 'default' => 60],
            'security_notify_admin_on_block' => ['group' => 'security', 'type' => 'bool', 'default' => true],
            'mail_driver' => ['group' => 'mail', 'type' => 'string', 'default' => env('MAIL_DRIVER', 'smtp'), 'env_key' => 'MAIL_DRIVER', 'persist_env' => true, 'persist_db' => true],
            'mail_host' => ['group' => 'mail', 'type' => 'string', 'default' => env('MAIL_HOST', ''), 'env_key' => 'MAIL_HOST', 'persist_env' => true, 'persist_db' => true],
            'mail_port' => ['group' => 'mail', 'type' => 'int', 'default' => (int) env('MAIL_PORT', 465), 'env_key' => 'MAIL_PORT', 'persist_env' => true, 'persist_db' => true],
            'mail_username' => ['group' => 'mail', 'type' => 'string', 'default' => env('MAIL_USERNAME', ''), 'env_key' => 'MAIL_USERNAME', 'persist_env' => true, 'persist_db' => true],
            'mail_password' => ['group' => 'mail', 'type' => 'string', 'default' => env('MAIL_PASSWORD', ''), 'env_key' => 'MAIL_PASSWORD', 'persist_env' => true, 'persist_db' => false, 'sensitive' => true],
            'mail_encryption' => ['group' => 'mail', 'type' => 'string', 'default' => env('MAIL_ENCRYPTION', 'ssl'), 'env_key' => 'MAIL_ENCRYPTION', 'persist_env' => true, 'persist_db' => true],
            'mail_from_address' => ['group' => 'mail', 'type' => 'string', 'default' => env('MAIL_FROM_ADDRESS', ''), 'env_key' => 'MAIL_FROM_ADDRESS', 'persist_env' => true, 'persist_db' => true],
            'mail_from_name' => ['group' => 'mail', 'type' => 'string', 'default' => env('MAIL_FROM_NAME', 'Gov Press ERP'), 'env_key' => 'MAIL_FROM_NAME', 'persist_env' => true, 'persist_db' => true],
            'mail_queue_enabled' => ['group' => 'mail', 'type' => 'bool', 'default' => settings_bool_env('MAIL_QUEUE_ENABLED', true), 'env_key' => 'MAIL_QUEUE_ENABLED', 'persist_env' => true, 'persist_db' => true],
            'mail_log_enabled' => ['group' => 'mail', 'type' => 'bool', 'default' => settings_bool_env('MAIL_LOG_ENABLED', true), 'env_key' => 'MAIL_LOG_ENABLED', 'persist_env' => true, 'persist_db' => true],
            'mail_queue_batch_size' => ['group' => 'mail', 'type' => 'int', 'default' => 50],
            'notifications_enabled' => ['group' => 'notification', 'type' => 'bool', 'default' => true],
            'notification_in_app_enabled' => ['group' => 'notification', 'type' => 'bool', 'default' => true],
            'notification_push_enabled' => ['group' => 'notification', 'type' => 'bool', 'default' => true],
            'notification_email_enabled' => ['group' => 'notification', 'type' => 'bool', 'default' => true],
            'notification_sms_enabled' => ['group' => 'notification', 'type' => 'bool', 'default' => false],
            'notification_whatsapp_enabled' => ['group' => 'notification', 'type' => 'bool', 'default' => false],
            'notification_queue_enabled' => ['group' => 'notification', 'type' => 'bool', 'default' => true],
            'notification_digest_enabled' => ['group' => 'notification', 'type' => 'bool', 'default' => false],
            'notification_batch_size' => ['group' => 'notification', 'type' => 'int', 'default' => 25],
            'notification_retry_limit' => ['group' => 'notification', 'type' => 'int', 'default' => 3],
            'notification_admin_email' => ['group' => 'notification', 'type' => 'string', 'default' => ''],
            'web_push_vapid_public_key' => ['group' => 'notification', 'type' => 'string', 'default' => '', 'env_key' => 'WEB_PUSH_VAPID_PUBLIC_KEY', 'persist_env' => true, 'persist_db' => true],
            'web_push_vapid_private_key' => ['group' => 'notification', 'type' => 'string', 'default' => '', 'env_key' => 'WEB_PUSH_VAPID_PRIVATE_KEY', 'persist_env' => true, 'persist_db' => false, 'sensitive' => true],
            'web_push_vapid_subject' => ['group' => 'notification', 'type' => 'string', 'default' => '', 'env_key' => 'WEB_PUSH_VAPID_SUBJECT', 'persist_env' => true, 'persist_db' => true],
            'sms_provider' => ['group' => 'sms', 'type' => 'string', 'default' => 'twilio'],
            'twilio_sid' => ['group' => 'sms', 'type' => 'string', 'default' => env('TWILIO_SID', ''), 'env_key' => 'TWILIO_SID', 'persist_env' => true, 'persist_db' => true],
            'twilio_token' => ['group' => 'sms', 'type' => 'string', 'default' => env('TWILIO_TOKEN', ''), 'env_key' => 'TWILIO_TOKEN', 'persist_env' => true, 'persist_db' => false, 'sensitive' => true],
            'twilio_from_number' => ['group' => 'sms', 'type' => 'string', 'default' => env('TWILIO_FROM_NUMBER', ''), 'env_key' => 'TWILIO_FROM_NUMBER', 'persist_env' => true, 'persist_db' => true],
            'twilio_whatsapp_number' => ['group' => 'sms', 'type' => 'string', 'default' => env('TWILIO_WHATSAPP_NUMBER', ''), 'env_key' => 'TWILIO_WHATSAPP_NUMBER', 'persist_env' => true, 'persist_db' => true],
            'sms_default_country_code' => ['group' => 'sms', 'type' => 'string', 'default' => '+265'],
            'sms_sender_name' => ['group' => 'sms', 'type' => 'string', 'default' => 'Press ERP'],
            'public_api_base_url' => ['group' => 'api', 'type' => 'string', 'default' => ''],
            'api_client_id' => ['group' => 'api', 'type' => 'string', 'default' => env('API_CLIENT_ID', ''), 'env_key' => 'API_CLIENT_ID', 'persist_env' => true, 'persist_db' => true],
            'api_client_secret' => ['group' => 'api', 'type' => 'string', 'default' => env('API_CLIENT_SECRET', ''), 'env_key' => 'API_CLIENT_SECRET', 'persist_env' => true, 'persist_db' => false, 'sensitive' => true],
            'webhook_endpoint' => ['group' => 'api', 'type' => 'string', 'default' => env('WEBHOOK_ENDPOINT', ''), 'env_key' => 'WEBHOOK_ENDPOINT', 'persist_env' => true, 'persist_db' => true],
            'webhook_signing_secret' => ['group' => 'api', 'type' => 'string', 'default' => env('WEBHOOK_SIGNING_SECRET', ''), 'env_key' => 'WEBHOOK_SIGNING_SECRET', 'persist_env' => true, 'persist_db' => false, 'sensitive' => true],
            'ai_enabled' => ['group' => 'ai', 'type' => 'bool', 'default' => settings_bool_env('AI_ENABLED', false), 'env_key' => 'AI_ENABLED', 'persist_env' => true, 'persist_db' => true],
            'ai_provider' => ['group' => 'ai', 'type' => 'string', 'default' => env('AI_PROVIDER', 'openai'), 'env_key' => 'AI_PROVIDER', 'persist_env' => true, 'persist_db' => true],
            'ai_model' => ['group' => 'ai', 'type' => 'string', 'default' => env('AI_MODEL', 'gpt-4o-mini'), 'env_key' => 'AI_MODEL', 'persist_env' => true, 'persist_db' => true],
            'ai_ollama_base_url' => ['group' => 'ai', 'type' => 'string', 'default' => env('OLLAMA_BASE_URL', 'http://127.0.0.1:11434'), 'env_key' => 'OLLAMA_BASE_URL', 'persist_env' => true, 'persist_db' => true],
            'ai_api_key' => ['group' => 'ai', 'type' => 'string', 'default' => env('OPENAI_API_KEY', ''), 'env_key' => 'OPENAI_API_KEY', 'persist_env' => true, 'persist_db' => false, 'sensitive' => true],
            'ai_monthly_budget' => ['group' => 'ai', 'type' => 'float', 'default' => (float) env('AI_MONTHLY_BUDGET', 0), 'env_key' => 'AI_MONTHLY_BUDGET', 'persist_env' => true, 'persist_db' => true],
            'ai_max_tokens' => ['group' => 'ai', 'type' => 'int', 'default' => (int) env('AI_MAX_TOKENS', 700), 'env_key' => 'AI_MAX_TOKENS', 'persist_env' => true, 'persist_db' => true],
            'ai_timeout_seconds' => ['group' => 'ai', 'type' => 'int', 'default' => (int) env('AI_TIMEOUT_SECONDS', 30), 'env_key' => 'AI_TIMEOUT_SECONDS', 'persist_env' => true, 'persist_db' => true],
            'ai_enable_tasks' => ['group' => 'ai', 'type' => 'bool', 'default' => settings_bool_env('AI_ENABLE_TASKS', true), 'env_key' => 'AI_ENABLE_TASKS', 'persist_env' => true, 'persist_db' => true],
            'ai_enable_projects' => ['group' => 'ai', 'type' => 'bool', 'default' => settings_bool_env('AI_ENABLE_PROJECTS', true), 'env_key' => 'AI_ENABLE_PROJECTS', 'persist_env' => true, 'persist_db' => true],
            'ai_enable_reminders' => ['group' => 'ai', 'type' => 'bool', 'default' => settings_bool_env('AI_ENABLE_REMINDERS', true), 'env_key' => 'AI_ENABLE_REMINDERS', 'persist_env' => true, 'persist_db' => true],
            'ai_enable_analysis' => ['group' => 'ai', 'type' => 'bool', 'default' => settings_bool_env('AI_ENABLE_ANALYSIS', true), 'env_key' => 'AI_ENABLE_ANALYSIS', 'persist_env' => true, 'persist_db' => true],
            'ai_enable_invoices' => ['group' => 'ai', 'type' => 'bool', 'default' => settings_bool_env('AI_ENABLE_INVOICES', true), 'env_key' => 'AI_ENABLE_INVOICES', 'persist_env' => true, 'persist_db' => true],
            'ai_enable_estimations' => ['group' => 'ai', 'type' => 'bool', 'default' => settings_bool_env('AI_ENABLE_ESTIMATIONS', true), 'env_key' => 'AI_ENABLE_ESTIMATIONS', 'persist_env' => true, 'persist_db' => true],
            'ai_enable_sales' => ['group' => 'ai', 'type' => 'bool', 'default' => settings_bool_env('AI_ENABLE_SALES', true), 'env_key' => 'AI_ENABLE_SALES', 'persist_env' => true, 'persist_db' => true],
            'hero_weather_enabled' => ['group' => 'hero_weather', 'type' => 'bool', 'default' => true],
            'hero_weather_backgrounds' => ['group' => 'hero_weather', 'type' => 'string', 'default' => ''],
            'login_slides' => ['group' => 'login', 'type' => 'string', 'default' => ''],
        ];

        return $registry;
    }
}

if (!function_exists('get_setting_definition')) {
    function get_setting_definition($key) {
        $registry = get_settings_registry();
        return $registry[$key] ?? null;
    }
}

if (!function_exists('clear_settings_cache')) {
    function clear_settings_cache() {
        unset($GLOBALS['__press_erp_settings_cache']);
    }
}

if (!function_exists('get_all_db_settings')) {
    function get_all_db_settings() {
        if (isset($GLOBALS['__press_erp_settings_cache']) && is_array($GLOBALS['__press_erp_settings_cache'])) {
            return $GLOBALS['__press_erp_settings_cache'];
        }

        if (!isset($GLOBALS['pdo']) || !($GLOBALS['pdo'] instanceof PDO)) {
            $GLOBALS['__press_erp_settings_cache'] = [];
            return $GLOBALS['__press_erp_settings_cache'];
        }

        try {
            $stmt = $GLOBALS['pdo']->query("SELECT setting_key, setting_value FROM business_settings");
            $GLOBALS['__press_erp_settings_cache'] = $stmt->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
        } catch (Exception $e) {
            $GLOBALS['__press_erp_settings_cache'] = [];
        }

        return $GLOBALS['__press_erp_settings_cache'];
    }
}

if (!function_exists('settings_env_has_key')) {
    function settings_env_has_key($key) {
        return array_key_exists($key, $_ENV) || array_key_exists($key, $_SERVER) || getenv($key) !== false;
    }
}

if (!function_exists('settings_env_file_has_key')) {
    function settings_env_file_has_key($key) {
        $envValues = parse_env_file_to_array();

        return array_key_exists($key, $envValues) && trim((string) $envValues[$key]) !== '';
    }
}

if (!function_exists('cast_setting_value')) {
    function cast_setting_value($value, $type) {
        switch ($type) {
            case 'bool':
                if (is_bool($value)) {
                    return $value;
                }
                $normalized = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                return $normalized === null ? false : $normalized;
            case 'int':
                return (int) $value;
            case 'float':
                return (float) $value;
            default:
                return is_string($value) ? trim($value) : $value;
        }
    }
}

if (!function_exists('normalize_setting_input')) {
    function normalize_setting_input($key, $value) {
        $definition = get_setting_definition($key);
        $type = $definition['type'] ?? 'string';
        return cast_setting_value($value, $type);
    }
}

if (!function_exists('setting_to_db_value')) {
    function setting_to_db_value($key, $value) {
        $definition = get_setting_definition($key);
        $type = $definition['type'] ?? 'string';

        if ($type === 'bool') {
            return $value ? '1' : '0';
        }

        return (string) $value;
    }
}

if (!function_exists('setting_to_env_value')) {
    function setting_to_env_value($key, $value) {
        $definition = get_setting_definition($key);
        $type = $definition['type'] ?? 'string';

        if ($type === 'bool') {
            return $value ? 'true' : 'false';
        }

        return (string) $value;
    }
}

if (!function_exists('parse_env_file_to_array')) {
    function parse_env_file_to_array($path = null) {
        $path = $path ?: settings_env_file_path();

        if (!file_exists($path)) {
            return [];
        }

        $values = [];
        $lines = file($path, FILE_IGNORE_NEW_LINES);

        foreach ($lines as $line) {
            if (!preg_match('/^\s*([A-Z0-9_]+)\s*=\s*(.*)\s*$/i', $line, $matches)) {
                continue;
            }

            $name = trim($matches[1]);
            $value = trim($matches[2]);

            if ((substr($value, 0, 1) === '"' && substr($value, -1) === '"') || (substr($value, 0, 1) === "'" && substr($value, -1) === "'")) {
                $value = substr($value, 1, -1);
            }

            $values[$name] = stripcslashes($value);
        }

        return $values;
    }
}

if (!function_exists('format_env_assignment_value')) {
    function format_env_assignment_value($value) {
        if ($value === null) {
            return '""';
        }

        $value = (string) $value;

        if ($value === '') {
            return '""';
        }

        if (preg_match('/^[A-Za-z0-9_:\\/.+\\-]+$/', $value)) {
            return $value;
        }

        return '"' . addcslashes($value, "\\\"\n\r\t") . '"';
    }
}

if (!function_exists('write_env_values')) {
    function write_env_values(array $updates, $path = null) {
        $path = $path ?: settings_env_file_path();
        $existingLines = file_exists($path) ? file($path, FILE_IGNORE_NEW_LINES) : [];
        $handledKeys = [];

        foreach ($existingLines as $index => $line) {
            if (!preg_match('/^\s*([A-Z0-9_]+)\s*=/i', $line, $matches)) {
                continue;
            }

            $envKey = trim($matches[1]);
            if (!array_key_exists($envKey, $updates)) {
                continue;
            }

            $existingLines[$index] = $envKey . '=' . format_env_assignment_value($updates[$envKey]);
            $handledKeys[$envKey] = true;
        }

        foreach ($updates as $envKey => $value) {
            if (!isset($handledKeys[$envKey])) {
                $existingLines[] = $envKey . '=' . format_env_assignment_value($value);
            }

            putenv($envKey . '=' . $value);
            $_ENV[$envKey] = (string) $value;
            $_SERVER[$envKey] = (string) $value;
        }

        $content = implode(PHP_EOL, $existingLines);
        if ($content !== '') {
            $content .= PHP_EOL;
        }

        return file_put_contents($path, $content, LOCK_EX) !== false;
    }
}

if (!function_exists('sync_sensitive_setting_to_env')) {
    function sync_sensitive_setting_to_env($key, $value) {
        static $syncedKeys = [];

        if (isset($syncedKeys[$key])) {
            return $syncedKeys[$key];
        }

        $definition = get_setting_definition($key);
        if (
            !$definition
            || empty($definition['env_key'])
            || empty($definition['persist_env'])
        ) {
            $syncedKeys[$key] = false;
            return false;
        }

        $normalizedValue = trim((string) $value);
        if ($normalizedValue === '') {
            $syncedKeys[$key] = false;
            return false;
        }

        $envKey = (string) $definition['env_key'];
        if (settings_env_file_has_key($envKey)) {
            $syncedKeys[$key] = true;
            return true;
        }

        $envValue = setting_to_env_value($key, normalize_setting_input($key, $normalizedValue));
        $syncedKeys[$key] = write_env_values([$envKey => $envValue]);

        if (!$syncedKeys[$key]) {
            error_log('Unable to backfill env-backed setting "' . $key . '" into the .env file.');
        }

        return $syncedKeys[$key];
    }
}

if (!function_exists('get_setting')) {
    function get_setting($key, $default = null) {
        $definition = get_setting_definition($key);
        $hasExplicitDefault = func_num_args() >= 2;
        $fallback = $hasExplicitDefault ? $default : ($definition['default'] ?? null);

        if ($definition && !empty($definition['env_key']) && settings_env_has_key($definition['env_key'])) {
            return cast_setting_value(env($definition['env_key']), $definition['type'] ?? 'string');
        }

        $dbSettings = get_all_db_settings();
        if (array_key_exists($key, $dbSettings)) {
            if (
                $definition
                && !empty($definition['env_key'])
                && !empty($definition['persist_env'])
                && !settings_env_has_key($definition['env_key'])
                && trim((string) $dbSettings[$key]) !== ''
            ) {
                sync_sensitive_setting_to_env($key, $dbSettings[$key]);
            }

            return cast_setting_value($dbSettings[$key], $definition['type'] ?? 'string');
        }

        return $fallback;
    }
}

if (!function_exists('get_setting_storage_status')) {
    function get_setting_storage_status($key) {
        $definition = get_setting_definition($key);
        $envKey = $definition['env_key'] ?? null;
        $dbSettings = get_all_db_settings();
        $dbValue = $dbSettings[$key] ?? null;
        $dbPresent = $dbValue !== null && trim((string) $dbValue) !== '';
        $envPresent = false;

        if ($envKey) {
            $envValue = env($envKey, null);
            $envPresent = ($envValue !== null && $envValue !== false && trim((string) $envValue) !== '')
                || settings_env_file_has_key($envKey);
        }

        $source = 'default';
        if ($envPresent) {
            $source = 'env';
        } elseif ($dbPresent) {
            $source = 'database';
        }

        return [
            'source' => $source,
            'env_present' => $envPresent,
            'db_present' => $dbPresent,
            'legacy_db_fallback' => !$envPresent && $dbPresent,
        ];
    }
}

if (!function_exists('setting_has_stored_value')) {
    function setting_has_stored_value($key) {
        $definition = get_setting_definition($key);

        if ($definition && !empty($definition['env_key']) && settings_env_has_key($definition['env_key'])) {
            return trim((string) env($definition['env_key'], '')) !== '';
        }

        $dbSettings = get_all_db_settings();
        return isset($dbSettings[$key]) && trim((string) $dbSettings[$key]) !== '';
    }
}

if (!function_exists('setting_truthy')) {
    function setting_truthy($key, $default = false) {
        return (bool) cast_setting_value(get_setting($key, $default), 'bool');
    }
}

if (!function_exists('get_settings_by_group')) {
    function get_settings_by_group($group) {
        $registry = get_settings_registry();
        $settings = [];

        foreach ($registry as $key => $definition) {
            if (($definition['group'] ?? 'general') !== $group) {
                continue;
            }
            $settings[$key] = get_setting($key);
        }

        if (!empty($settings)) {
            return $settings;
        }

        if (!isset($GLOBALS['pdo']) || !($GLOBALS['pdo'] instanceof PDO)) {
            return [];
        }

        $stmt = $GLOBALS['pdo']->prepare("SELECT setting_key, setting_value FROM business_settings WHERE setting_group = ?");
        $stmt->execute([$group]);
        return $stmt->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
    }
}

if (!function_exists('update_setting')) {
    function update_setting($key, $value, $group = 'general') {
        if (!isset($GLOBALS['pdo']) || !($GLOBALS['pdo'] instanceof PDO)) {
            return false;
        }

        $definition = get_setting_definition($key);
        $normalizedValue = normalize_setting_input($key, $value);
        $targetGroup = $definition['group'] ?? $group;

        $sql = "INSERT INTO business_settings (setting_key, setting_value, setting_group)
                VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    setting_value = VALUES(setting_value),
                    setting_group = VALUES(setting_group),
                    updated_at = CURRENT_TIMESTAMP";

        $stmt = $GLOBALS['pdo']->prepare($sql);
        $success = $stmt->execute([$key, setting_to_db_value($key, $normalizedValue), $targetGroup]);

        if ($success) {
            clear_settings_cache();
        }

        return $success;
    }
}

if (!function_exists('save_application_settings')) {
    function save_application_settings(array $values) {
        if (!isset($GLOBALS['pdo']) || !($GLOBALS['pdo'] instanceof PDO)) {
            throw new RuntimeException('Database connection is not available for saving settings.');
        }

        $envUpdates = [];
        $dbWrites = [];

        foreach ($values as $key => $value) {
            $definition = get_setting_definition($key) ?? ['group' => 'general', 'type' => 'string'];
            $normalizedValue = normalize_setting_input($key, $value);

            if (!empty($definition['sensitive']) && trim((string) $normalizedValue) === '') {
                continue;
            }

            if (($definition['persist_db'] ?? true) === true) {
                $dbWrites[$key] = [
                    'value' => setting_to_db_value($key, $normalizedValue),
                    'group' => $definition['group'] ?? 'general',
                ];
            }

            if (!empty($definition['env_key']) && !empty($definition['persist_env'])) {
                $envUpdates[$definition['env_key']] = setting_to_env_value($key, $normalizedValue);
            }
        }

        $envPath = settings_env_file_path();
        $originalEnvContents = file_exists($envPath) ? file_get_contents($envPath) : null;
        $envTouched = false;

        $GLOBALS['pdo']->beginTransaction();

        try {
            if (!empty($envUpdates)) {
                if (!write_env_values($envUpdates, $envPath)) {
                    throw new RuntimeException('Unable to write configuration values to the .env file.');
                }
                $envTouched = true;
            }

            if (!empty($dbWrites)) {
                $stmt = $GLOBALS['pdo']->prepare("
                    INSERT INTO business_settings (setting_key, setting_value, setting_group)
                    VALUES (:setting_key, :setting_value, :setting_group)
                    ON DUPLICATE KEY UPDATE
                        setting_value = VALUES(setting_value),
                        setting_group = VALUES(setting_group),
                        updated_at = CURRENT_TIMESTAMP
                ");

                foreach ($dbWrites as $key => $payload) {
                    $stmt->execute([
                        'setting_key' => $key,
                        'setting_value' => $payload['value'],
                        'setting_group' => $payload['group'],
                    ]);
                }
            }

            $GLOBALS['pdo']->commit();
            clear_settings_cache();
        } catch (Exception $e) {
            if ($GLOBALS['pdo']->inTransaction()) {
                $GLOBALS['pdo']->rollBack();
            }

            if ($envTouched) {
                if ($originalEnvContents === null) {
                    if (file_exists($envPath)) {
                        unlink($envPath);
                    }
                } else {
                    file_put_contents($envPath, $originalEnvContents, LOCK_EX);
                }

                if (function_exists('loadEnv') && file_exists($envPath)) {
                    loadEnv($envPath);
                }
            }

            throw $e;
        }
    }
}

if (!function_exists('get_business_pdf_settings')) {
    function get_business_pdf_settings() {
        $settings = [
            'business' => get_settings_by_group('business'),
            'contact' => get_settings_by_group('contact'),
            'invoice' => get_settings_by_group('invoice'),
            'signature' => get_settings_by_group('signature'),
            'bank' => get_settings_by_group('bank')
        ];

        $result = [];
        foreach ($settings as $groupItems) {
            foreach ($groupItems as $key => $value) {
                $result[$key] = $value;
            }
        }

        return $result;
    }
}

if (!function_exists('get_mail_config_settings')) {
    function get_mail_config_settings() {
        return [
            'host' => get_setting('mail_host', ''),
            'port' => get_setting('mail_port', 465),
            'username' => get_setting('mail_username', ''),
            'password' => get_setting('mail_password', ''),
            'encryption' => get_setting('mail_encryption', 'ssl'),
            'from_address' => get_setting('mail_from_address', ''),
            'from_name' => get_setting('mail_from_name', 'Gov Press ERP'),
            'driver' => get_setting('mail_driver', 'smtp'),
            'queue_enabled' => setting_truthy('mail_queue_enabled', true),
            'log_enabled' => setting_truthy('mail_log_enabled', true),
            'queue_batch_size' => get_setting('mail_queue_batch_size', 50),
        ];
    }
}

if (!function_exists('get_sms_config_settings')) {
    function get_sms_config_settings() {
        return [
            'provider' => get_setting('sms_provider', 'twilio'),
            'sid' => get_setting('twilio_sid', ''),
            'token' => get_setting('twilio_token', ''),
            'from_number' => get_setting('twilio_from_number', ''),
            'whatsapp_number' => get_setting('twilio_whatsapp_number', ''),
            'default_country_code' => get_setting('sms_default_country_code', '+265'),
            'sender_name' => get_setting('sms_sender_name', 'Press ERP'),
        ];
    }
}

if (!function_exists('get_notification_system_settings')) {
    function get_notification_system_settings() {
        return [
            'notifications_enabled' => setting_truthy('notifications_enabled', true),
            'in_app_enabled' => setting_truthy('notification_in_app_enabled', true),
            'push_enabled' => setting_truthy('notification_push_enabled', true),
            'email_enabled' => setting_truthy('notification_email_enabled', true),
            'sms_enabled' => setting_truthy('notification_sms_enabled', false),
            'whatsapp_enabled' => setting_truthy('notification_whatsapp_enabled', false),
            'queue_enabled' => setting_truthy('notification_queue_enabled', true),
            'digest_enabled' => setting_truthy('notification_digest_enabled', false),
            'batch_size' => get_setting('notification_batch_size', 25),
            'retry_limit' => get_setting('notification_retry_limit', 3),
            'admin_email' => get_setting('notification_admin_email', ''),
        ];
    }
}

if (!function_exists('get_security_monitoring_settings')) {
    function get_security_monitoring_settings() {
        return [
            'login_monitoring_enabled' => setting_truthy('security_login_monitoring_enabled', true),
            'failed_login_threshold' => max(1, (int) get_setting('security_failed_login_threshold', 5)),
            'failed_login_window_minutes' => max(1, (int) get_setting('security_failed_login_window_minutes', 15)),
            'block_duration_minutes' => max(0, (int) get_setting('security_block_duration_minutes', 60)),
            'notify_admin_on_block' => setting_truthy('security_notify_admin_on_block', true),
        ];
    }
}

if (!function_exists('is_pro_operation_mode')) {
    function is_pro_operation_mode() {
        return strtolower((string) get_setting('system_operation_mode', 'standard')) === 'pro';
    }
}

if (!function_exists('get_ai_settings')) {
    function get_ai_settings() {
        return [
            'enabled' => setting_truthy('ai_enabled', false),
            'provider' => strtolower((string) get_setting('ai_provider', 'openai')),
            'model' => (string) get_setting('ai_model', 'gpt-4o-mini'),
            'ollama_base_url' => (string) get_setting('ai_ollama_base_url', 'http://127.0.0.1:11434'),
            'api_key' => (string) get_setting('ai_api_key', ''),
            'monthly_budget' => max(0.0, (float) get_setting('ai_monthly_budget', 0)),
            'max_tokens' => max(64, (int) get_setting('ai_max_tokens', 700)),
            'timeout_seconds' => max(5, min(120, (int) get_setting('ai_timeout_seconds', 30))),
            'features' => [
                'tasks' => setting_truthy('ai_enable_tasks', true),
                'projects' => setting_truthy('ai_enable_projects', true),
                'reminders' => setting_truthy('ai_enable_reminders', true),
                'analysis' => setting_truthy('ai_enable_analysis', true),
                'invoices' => setting_truthy('ai_enable_invoices', true),
                'estimations' => setting_truthy('ai_enable_estimations', true),
                'sales' => setting_truthy('ai_enable_sales', true),
            ],
        ];
    }
}
?>
