<?php
/**
 * Email/SMTP Configuration
 *
 * These constants are derived from the unified settings helper so that
 * runtime consumers can safely read either .env-backed credentials or
 * database-backed operational settings.
 */

require_once __DIR__ . '/../includes/settings_helper.php';

define('MAIL_DRIVER', (string) get_setting('mail_driver', 'smtp'));
define('MAIL_HOST', (string) get_setting('mail_host', ''));
define('MAIL_PORT', (int) get_setting('mail_port', 465));
define('MAIL_USERNAME', (string) get_setting('mail_username', ''));
define('MAIL_PASSWORD', (string) get_setting('mail_password', ''));
define('MAIL_ENCRYPTION', (string) get_setting('mail_encryption', 'ssl'));
define('MAIL_FROM_ADDRESS', (string) get_setting('mail_from_address', ''));
define('MAIL_FROM_NAME', (string) get_setting('mail_from_name', 'Gov Press ERP'));

// Email queue settings
define('MAIL_QUEUE_ENABLED', setting_truthy('mail_queue_enabled', true));
define('MAIL_QUEUE_TABLE', 'email_queue');
define('MAIL_LOG_ENABLED', setting_truthy('mail_log_enabled', true));

/**
 * Get mail settings from database or use defaults
 */
function getMailSettings() {
    return get_mail_config_settings();
}

function isMailQueueEnabled() {
    return setting_truthy('mail_queue_enabled', true);
}

function isMailLogEnabled() {
    return setting_truthy('mail_log_enabled', true);
}
?>
