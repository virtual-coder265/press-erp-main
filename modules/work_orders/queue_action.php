<?php
require_once __DIR__ . '/../../config/app.php';
checkAuth();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/work_order_helper.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('modules/work_orders/queue');
}

if (!verify_csrf_token($_POST['csrf_token'] ?? '', 'work_order_queue_action')) {
    $_SESSION['error'] = 'Security check failed. Please try again.';
    redirect('modules/work_orders/queue');
}

if (!hasPermission('manage_production_queues') && !hasPermission('manage_work_orders')) {
    http_response_code(403);
    die('Access Denied.');
}

$progressId = (int) ($_POST['progress_id'] ?? 0);
$action = trim((string) ($_POST['action'] ?? ''));
$remarks = trim((string) ($_POST['remarks'] ?? ''));
$holdReason = trim((string) ($_POST['hold_reason'] ?? ''));
$redirectDepartment = trim((string) ($_POST['redirect_department'] ?? ''));
$redirectTab = trim((string) ($_POST['redirect_tab'] ?? 'incoming'));
$result = [];

try {
    $result = work_order_process_queue_action($pdo, $progressId, $action, (int) ($_SESSION['user_id'] ?? 0), $remarks, $holdReason);
    $_SESSION['success'] = $result['message'];
} catch (Throwable $exception) {
    $_SESSION['error'] = $exception->getMessage();
}

$target = 'modules/work_orders/workspace';
$params = [];
if ($redirectDepartment !== '') {
    $params[] = 'department=' . urlencode($redirectDepartment);
} elseif (!empty($result['department_slug'])) {
    $params[] = 'department=' . urlencode($result['department_slug']);
}

if (!empty($result['next_department_slug']) && ($action ?? '') === 'dispatch') {
    $params[] = 'department=' . urlencode($result['next_department_slug']);
    $params[] = 'tab=incoming';
} elseif (!empty($result['next_department_slug']) && ($action ?? '') === 'send_back') {
    $params[] = 'department=' . urlencode($result['next_department_slug']);
    $params[] = 'tab=active';
} elseif (!empty($result['redirect_tab'])) {
    $params[] = 'tab=' . urlencode($result['redirect_tab']);
} elseif (($action ?? '') === 'dispatch') {
    $params[] = 'tab=sent';
} elseif (($action ?? '') === 'complete') {
    $params[] = 'tab=ready';
} elseif (in_array($action ?? '', ['start', 'receive'], true)) {
    $params[] = 'tab=active';
} elseif ($redirectTab !== '') {
    $params[] = 'tab=' . urlencode($redirectTab);
}
if (!empty($params)) {
    $target .= '?' . implode('&', $params);
}
redirect($target);
