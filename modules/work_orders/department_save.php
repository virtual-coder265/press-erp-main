<?php
require_once __DIR__ . '/../../config/app.php';
checkAuth();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/work_order_helper.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('modules/work_orders/workspace');
}

if (!verify_csrf_token($_POST['csrf_token'] ?? '', 'work_order_department')) {
    $_SESSION['error'] = 'Security check failed. Please try again.';
    redirect('modules/work_orders/workspace');
}

if (!hasPermission('manage_production_queues') && !hasPermission('manage_work_orders')) {
    http_response_code(403);
    die('Access Denied.');
}

$workOrderId = (int) ($_POST['work_order_id'] ?? 0);
$departmentSlug = trim((string) ($_POST['department'] ?? ''));
$userId = (int) ($_SESSION['user_id'] ?? 0);

try {
    if ($workOrderId <= 0 || $departmentSlug === '') {
        throw new RuntimeException('Invalid work order or department reference.');
    }
    work_order_update_department_section($pdo, $workOrderId, $departmentSlug, $_POST, $userId);
    $_SESSION['success'] = 'Department section saved successfully.';
    redirect('modules/work_orders/department_edit?department=' . urlencode($departmentSlug) . '&id=' . $workOrderId);
} catch (Throwable $exception) {
    $_SESSION['error'] = $exception->getMessage();
    redirect('modules/work_orders/department_edit?department=' . urlencode($departmentSlug) . '&id=' . $workOrderId);
}
