<?php
require_once __DIR__ . '/../../config/app.php';
checkAuth();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/work_order_helper.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('modules/work_orders/list');
}

if (!verify_csrf_token($_POST['csrf_token'] ?? '', 'work_order_send_origination')) {
    $_SESSION['error'] = 'Security check failed. Please try again.';
    redirect('modules/work_orders/list');
}

if (!hasPermission('manage_work_orders')) {
    http_response_code(403);
    die('Access Denied.');
}

work_order_bootstrap($pdo);

$workOrderId = (int) ($_POST['work_order_id'] ?? 0);
$redirectTo = trim((string) ($_POST['redirect_to'] ?? 'view'));

try {
    $result = work_order_send_to_origination($pdo, $workOrderId, (int) ($_SESSION['user_id'] ?? 0));
    $_SESSION['success'] = $result['message'];
    if ($redirectTo === 'workspace') {
        redirect('modules/work_orders/workspace?department=origination&tab=incoming');
    }
    redirect('modules/work_orders/view?id=' . (int) $result['work_order_id']);
} catch (Throwable $exception) {
    $_SESSION['error'] = $exception->getMessage();
    if ($workOrderId > 0) {
        redirect('modules/work_orders/view?id=' . $workOrderId);
    }
    redirect('modules/work_orders/list');
}
