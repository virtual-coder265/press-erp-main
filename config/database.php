<?php
if (!function_exists('env')) {
    require_once __DIR__ . '/env.php';
    loadEnv(dirname(__DIR__) . '/.env');
}

if (!defined('BASE_URL')) {
    define('BASE_URL', rtrim((string) env('BASE_URL', 'http://localhost/press-erp-main/'), '/') . '/');
}

if (!defined('APP_NAME')) {
    define('APP_NAME', (string) env('APP_NAME', 'Gov Press ERP'));
}

define('DB_HOST', env('DB_HOST', 'localhost'));
define('DB_PORT', (int) env('DB_PORT', '3306'));
define('DB_NAME', env('DB_NAME', 'press_erp'));
define('DB_USER', env('DB_USER', 'root'));
define('DB_PASS', env('DB_PASS', ''));

try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);

    require_once __DIR__ . '/../includes/settings_helper.php';
    require_once __DIR__ . '/../includes/permissions_helper.php';
    require_once __DIR__ . '/../libs/AuditLogger.php';

    if (php_sapi_name() !== 'cli' && empty($GLOBALS['_permissions_catalog_synced'])) {
        permissions_ensure_catalog($pdo);
        $GLOBALS['_permissions_catalog_synced'] = true;
    }

    if (php_sapi_name() !== 'cli') {
        $auditLogger = new AuditLogger($pdo);
        $activeBlock = $auditLogger->getActiveIpBlock();

        if ($activeBlock) {
            $currentScript = (string) ($_SERVER['PHP_SELF'] ?? '');
            if (strpos($currentScript, 'modules/auth/login') !== false || basename($currentScript) === 'login.php') {
                $auditLogger->registerBlockedLogin(
                    trim((string) ($_POST['email'] ?? 'anonymous_request')),
                    $activeBlock,
                    isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null,
                    ['source' => 'config/database']
                );
            } else {
                $auditLogger->log(
                    'security',
                    'blocked_request',
                    'Request rejected because the source IP address is blocked.',
                    [
                        'severity' => 'critical',
                        'user_id' => $_SESSION['user_id'] ?? null,
                        'status' => 'blocked',
                        'context' => [
                            'block_id' => $activeBlock['id'] ?? null,
                            'blocked_until' => $activeBlock['blocked_until'] ?? null,
                        ],
                    ]
                );
            }

            http_response_code(403);
            $blockedUntil = !empty($activeBlock['blocked_until']) ? $activeBlock['blocked_until'] : 'manually cleared';
            die("<div style='padding: 3rem; font-family: sans-serif; max-width: 42rem; margin: 0 auto; text-align: center;'>
                    <h1 style='font-size: 2rem; margin-bottom: 1rem;'>Access blocked</h1>
                    <p style='font-size: 1rem; color: #555; margin-bottom: .75rem;'>This IP address is currently blocked from accessing " . htmlspecialchars(APP_NAME) . ".</p>
                    <p style='font-size: .95rem; color: #777;'>Reason: " . htmlspecialchars((string) ($activeBlock['reason'] ?? 'Security control')) . "<br>Blocked until: " . htmlspecialchars((string) $blockedUntil) . "</p>
                 </div>");
        }
    }

    // Profile Enforcement Hook
    if (
        isset($_SESSION['user_id']) &&
        empty($_SESSION['profile_skip_warning']) &&
        setting_truthy('profile_enforcement_enabled', true)
    ) {
        $current_script = $_SERVER['PHP_SELF'] ?? '';
        
        // Exclude specific paths like auth and the profile page itself (to prevent infinite loops)
        if (strpos($current_script, 'modules/user-account/profile') === false && 
            strpos($current_script, 'modules/auth/logout') === false && 
            strpos($current_script, 'modules/auth/login') === false) {
            
            // Check cache
            if (!isset($_SESSION['profile_enforced_valid']) || !$_SESSION['profile_enforced_valid']) {
                $columnCheckStmt = $pdo->query("
                    SELECT COLUMN_NAME
                    FROM information_schema.COLUMNS
                    WHERE TABLE_SCHEMA = DATABASE()
                      AND TABLE_NAME = 'users'
                      AND COLUMN_NAME IN ('phone', 'whatsapp_phone')
                ");
                $availableColumns = $columnCheckStmt->fetchAll(PDO::FETCH_COLUMN) ?: [];

                if (in_array('phone', $availableColumns, true) && in_array('whatsapp_phone', $availableColumns, true)) {
                    $stmt = $pdo->prepare("SELECT phone, whatsapp_phone FROM users WHERE id = ?");
                    $stmt->execute([$_SESSION['user_id']]);
                    $u = $stmt->fetch();

                    $valid_sms = !empty($u['phone']) && preg_match('/^\+265(99|88|98|89)\d{5,}$/', $u['phone']);
                    $valid_wa = !empty($u['whatsapp_phone']) && preg_match('/^\+265(99|88|98|89)\d{5,}$/', $u['whatsapp_phone']);

                    if (!$valid_sms && !$valid_wa) {
                        $_SESSION['profile_enforced_valid'] = false;
                        $redirect_url = defined('BASE_URL') ? BASE_URL . "modules/user-account/profile?action=force_update" : "/press-erp-main/modules/user-account/profile?action=force_update";
                        header("Location: " . $redirect_url);
                        exit;
                    }

                    $_SESSION['profile_enforced_valid'] = true;
                } else {
                    $_SESSION['profile_enforced_valid'] = true;
                }
            }
        }
    }

} catch (PDOException $e) {
    if (env('APP_ENV', 'development') === 'production') {
        error_log("DB Connection Error: " . $e->getMessage());
        die("System is currently unavailable. Please try again later.");
    } else {
        die("ERROR: Could not connect. " . $e->getMessage());
    }
}
?>
