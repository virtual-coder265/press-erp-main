<?php
require_once ROOT_PATH . 'includes/branding_helper.php';

$appDisplayName = function_exists('get_setting')
    ? (string) get_setting('system_app_name', APP_NAME)
    : APP_NAME;
$errorCssVersion = file_exists(ROOT_PATH . 'assets/css/error-pages.css')
    ? (string) filemtime(ROOT_PATH . 'assets/css/error-pages.css')
    : (string) time();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($errorTitle); ?> · <?php echo htmlspecialchars($appDisplayName); ?></title>
    <link rel="icon" type="image/png" href="<?php echo htmlspecialchars(system_branding_resolved_url('favicon')); ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="<?php echo asset('vendor/css/tailwind-2.2.19.min.css'); ?>" rel="stylesheet">
    <link href="<?php echo asset('css/error-pages.css') . '?v=' . rawurlencode($errorCssVersion); ?>" rel="stylesheet">
    <?php if ($errorCode === 301 && !empty($errorRedirectUrl)): ?>
    <meta http-equiv="refresh" content="5;url=<?php echo htmlspecialchars(BASE_URL . ltrim($errorRedirectUrl, '/')); ?>">
    <?php endif; ?>
</head>
<body class="error-page-body">
    <main class="error-page-shell">
        <div class="error-page-card">
            <div class="error-page-brand">
                <img
                    src="<?php echo htmlspecialchars(system_branding_resolved_url('logo')); ?>"
                    alt="<?php echo htmlspecialchars($appDisplayName); ?> logo"
                    class="error-page-logo"
                >
                <span class="error-page-app-name"><?php echo htmlspecialchars($appDisplayName); ?></span>
            </div>

            <?php
            $partial = ROOT_PATH . 'includes/partials/errors/' . $template . '.php';
            if (is_file($partial)) {
                require $partial;
            } else {
                require ROOT_PATH . 'includes/partials/errors/generic.php';
            }
            ?>

            <div class="error-page-actions">
                <?php if ($errorReturnUrl !== $errorHomeUrl): ?>
                <a href="<?php echo htmlspecialchars(BASE_URL . ltrim($errorReturnUrl, '/')); ?>" class="error-page-btn error-page-btn-primary">
                    Return to previous page
                </a>
                <?php endif; ?>
                <a href="<?php echo htmlspecialchars(BASE_URL . ltrim($errorHomeUrl, '/')); ?>" class="error-page-btn error-page-btn-secondary">
                    Go to dashboard
                </a>
                <?php if (!empty($errorShowRetry)): ?>
                <button type="button" class="error-page-btn error-page-btn-ghost" onclick="window.location.reload()">
                    Try again
                </button>
                <?php endif; ?>
            </div>

            <p class="error-page-meta">Reference: HTTP <?php echo (int) $errorCode; ?></p>
        </div>
    </main>
</body>
</html>
