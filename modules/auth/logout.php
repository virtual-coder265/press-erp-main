<?php
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../libs/AuditLogger.php';

if (isset($_GET['finalize']) && $_GET['finalize'] === '1') {
    $auditLogger = new AuditLogger($pdo);
    $auditLogger->log(
        'auth',
        'logout',
        'User logged out of the system.',
        [
            'severity' => 'info',
            'user_id' => $_SESSION['user_id'] ?? null,
            'target_user_id' => $_SESSION['user_id'] ?? null,
            'status' => 'success',
            'context' => [
                'role' => $_SESSION['role'] ?? null,
                'user_name' => $_SESSION['user_name'] ?? null,
            ],
        ]
    );

    session_destroy();
    redirect('modules/auth/login');
}

$scopePath = parse_url(BASE_URL, PHP_URL_PATH);
if (!is_string($scopePath) || $scopePath === '') {
    $scopePath = '/';
}
$scopePath = rtrim($scopePath, '/') . '/';
$finalizeUrl = BASE_URL . 'modules/auth/logout?finalize=1';
$subscriptionUrl = BASE_URL . 'modules/user-account/push_subscription';
$csrfToken = csrf_token('push_subscription');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(APP_NAME); ?> Logout</title>
    <meta http-equiv="refresh" content="8;url=<?php echo htmlspecialchars($finalizeUrl); ?>">
    <?php
    $includeJquery = false;
    $preloadMaterialIcons = false;
    include __DIR__ . '/../../includes/head_assets.php';
    ?>
    <style>
        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #f8fafc 0%, #ecfeff 100%);
            font-family: var(--app-font-sans);
            color: #0f172a;
        }
        .logout-card {
            width: min(28rem, calc(100vw - 2rem));
            background: #ffffff;
            border-radius: 1.5rem;
            padding: 2rem;
            box-shadow: 0 18px 40px rgba(15, 23, 42, 0.12);
            text-align: center;
        }
        .logout-icon {
            width: 4rem;
            height: 4rem;
            margin: 0 auto 1rem;
            border-radius: 9999px;
            background: #ccfbf1;
            color: #0f766e;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.75rem;
            font-weight: 700;
        }
        .logout-copy {
            color: #475569;
            line-height: 1.5;
            margin-top: 0.75rem;
        }
    </style>
</head>
<body>
    <div class="logout-card">
        <div class="logout-icon">GP</div>
        <h1>Signing You Out</h1>
        <p class="logout-copy">Cleaning up this browser session, including background notification access, before returning to the login screen.</p>
    </div>

    <script>
        (function () {
            const finalizeUrl = <?php echo json_encode($finalizeUrl, JSON_UNESCAPED_SLASHES); ?>;
            const subscriptionUrl = <?php echo json_encode($subscriptionUrl, JSON_UNESCAPED_SLASHES); ?>;
            const scopePath = <?php echo json_encode($scopePath, JSON_UNESCAPED_SLASHES); ?>;
            const csrfToken = <?php echo json_encode($csrfToken); ?>;

            async function cleanupPushSubscription() {
                if (!('serviceWorker' in navigator)) {
                    return;
                }

                const registration = await navigator.serviceWorker.getRegistration(scopePath);
                if (!registration || !registration.pushManager) {
                    return;
                }

                const subscription = await registration.pushManager.getSubscription();
                if (!subscription) {
                    return;
                }

                const endpoint = subscription.endpoint;
                try {
                    await fetch(subscriptionUrl, {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-Token': csrfToken
                        },
                        body: JSON.stringify({
                            action: 'delete',
                            endpoint: endpoint
                        })
                    });
                } catch (error) {
                }

                try {
                    await subscription.unsubscribe();
                } catch (error) {
                }
            }

            cleanupPushSubscription()
                .catch(function () {
                })
                .finally(function () {
                    window.location.replace(finalizeUrl);
                });
        })();
    </script>
</body>
</html>
