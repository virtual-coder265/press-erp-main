<?php
require_once __DIR__ . '/../../config/app.php';
checkAuth();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/work_order_helper.php';

header('Content-Type: application/json');

if (!hasPermission('manage_work_orders') && !hasPermission('manage_invoices')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit;
}

if (!verify_csrf_token($_POST['csrf_token'] ?? '', 'work_order_binding')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Security check failed.']);
    exit;
}

try {
    $result = work_order_add_binding_type($pdo, (string) ($_POST['name'] ?? ''));
    echo json_encode(['success' => true, 'id' => $result['id'], 'name' => $result['name']]);
} catch (Throwable $exception) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $exception->getMessage()]);
}
