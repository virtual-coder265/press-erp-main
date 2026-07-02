<?php
require_once __DIR__ . '/env.php';
loadEnv(dirname(__DIR__) . '/.env');

// Base URL configuration
define('BASE_URL', env('BASE_URL', 'http://localhost/press-erp-main/'));
define('ROOT_PATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);
define('APP_NAME', env('APP_NAME', 'Gov Press ERP'));
define('APP_TIMEZONE', env('APP_TIMEZONE', 'Africa/Blantyre'));
define('MAINTENANCE_MODE', filter_var(env('MAINTENANCE_MODE', 'false'), FILTER_VALIDATE_BOOLEAN));

date_default_timezone_set(APP_TIMEZONE);

// Environment Error Handling
$app_env = env('APP_ENV', 'development');
if ($app_env === 'production') {
    error_reporting(0);
    ini_set('display_errors', '0');
} else {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
}

// Include Composer Autoloader
if (file_exists(ROOT_PATH . 'vendor/autoload.php')) {
    require_once ROOT_PATH . 'vendor/autoload.php';
}

// Start Session if not already started
if (session_status() === PHP_SESSION_NONE) {
    // Session Hardening
    ini_set('session.use_only_cookies', 1);
    ini_set('session.cookie_httponly', 1);
    // Secure flag handles HTTPS checking based on setup
    if(isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')) {
        ini_set('session.cookie_secure', 1);
        ini_set('session.cookie_samesite', 'Lax');
    }
    session_start();
}

require_once ROOT_PATH . 'includes/installer_helper.php';
require_once ROOT_PATH . 'includes/datetime_picker_helper.php';
installer_bootstrap_guard();

if (
    MAINTENANCE_MODE &&
    (!isset($_SESSION['role']) || $_SESSION['role'] !== 'System Admin') &&
    strpos($_SERVER['PHP_SELF'] ?? '', 'modules/auth/login') === false &&
    strpos($_SERVER['PHP_SELF'] ?? '', 'modules/installer') === false
) {
    http_response_code(503);
    die("<div style='padding: 3rem; font-family: sans-serif; max-width: 42rem; margin: 0 auto; text-align: center;'>
            <h1 style='font-size: 2rem; margin-bottom: 1rem;'>" . htmlspecialchars(APP_NAME) . " is under maintenance</h1>
            <p style='font-size: 1rem; color: #555;'>We're applying configuration or system updates right now. Please try again shortly.</p>
         </div>");
}

// Helper function for asset paths
function asset($path) {
    $base = (function_exists('installer_is_installed') && !installer_is_installed())
        ? installer_detect_base_url()
        : BASE_URL;

    return $base . 'assets/' . ltrim($path, '/');
}

// Helper for redirect
function redirect($path) {
    $base = (function_exists('installer_is_installed') && !installer_is_installed())
        ? installer_detect_base_url()
        : BASE_URL;

    header("Location: " . $base . ltrim($path, '/'));
    exit;
}

function csrf_token(string $key = 'default'): string
{
    if (empty($_SESSION['_csrf_tokens'][$key])) {
        $_SESSION['_csrf_tokens'][$key] = bin2hex(random_bytes(32));
    }

    return $_SESSION['_csrf_tokens'][$key];
}

function verify_csrf_token(?string $token, string $key = 'default'): bool
{
    if (!isset($_SESSION['_csrf_tokens'][$key]) || !is_string($token)) {
        return false;
    }

    return hash_equals($_SESSION['_csrf_tokens'][$key], $token);
}

// Helper to check authentication
function checkAuth() {
    if (!isset($_SESSION['user_id'])) {
        redirect('modules/auth/login');
    }
}

// Helper to check if current user has a specific permission
function hasPermission($slug) {
    // System Admin has all permissions by default
    if (isset($_SESSION['role']) && $_SESSION['role'] === 'System Admin') {
        return true;
    }
    if (!isset($_SESSION['permissions'])) {
        return false;
    }

    $permissions = array_values(array_unique(array_map('strval', (array) $_SESSION['permissions'])));
    if (in_array($slug, $permissions, true)) {
        return true;
    }

    // Treat higher-order management permissions as satisfying related view/create checks.
    $aliases = [];
    if (strpos($slug, 'view_') === 0) {
        $aliases[] = 'manage_' . substr($slug, 5);
    }
    if (strpos($slug, 'create_') === 0) {
        $aliases[] = 'manage_' . substr($slug, 7);
    }

    foreach ($aliases as $alias) {
        if (in_array($alias, $permissions, true)) {
            return true;
        }
    }

    return false;
}

// Helper to enforce permission on a page
function checkPermission($slug) {
    if (!hasPermission($slug)) {
        header('HTTP/1.1 403 Forbidden');
        die("<div style='padding: 2rem; font-family: sans-serif; text-align: center;'>
                <h1 style='font-size: 3rem; margin-bottom: 0.5rem;'>403</h1>
                <p style='font-size: 1.25rem; color: #666;'>Access Denied. You do not have permission to view this page.</p>
                <a href='" . BASE_URL . "index' style='display: inline-block; margin-top: 1rem; padding: 0.5rem 1rem; background: #0f766e; color: white; text-decoration: none; border-radius: 4px;'>Return Home</a>
             </div>");
    }
}
?>
