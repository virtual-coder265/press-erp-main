<?php
require_once __DIR__ . '/../../config/app.php';

$requestedCode = isset($_GET['code']) ? (int) $_GET['code'] : 404;
if ($requestedCode < 400 || $requestedCode > 599) {
    $requestedCode = 404;
}

$apacheStatus = isset($_SERVER['REDIRECT_STATUS']) ? (int) $_SERVER['REDIRECT_STATUS'] : 0;
if ($apacheStatus >= 400 && $apacheStatus <= 599) {
    $requestedCode = $apacheStatus;
}

$message = trim((string) ($_GET['message'] ?? ''));
$returnUrl = trim((string) ($_GET['return_url'] ?? ''));

try {
    require_once __DIR__ . '/../../config/database.php';
} catch (Throwable $e) {
    // Database unavailable — still render the error screen without audit logging.
}

$options = [];
if ($message !== '') {
    $options['message'] = $message;
}
if ($returnUrl !== '') {
    $options['return_url'] = $returnUrl;
}

if ($requestedCode === 404 && $message === '') {
    $options['message'] = 'The URL you requested does not match any page in ' . APP_NAME . '.';
}

app_render_error($requestedCode, $options);
