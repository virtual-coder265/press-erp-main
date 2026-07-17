<?php
require_once __DIR__ . '/../../config/app.php';
checkAuth();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/work_order_helper.php';

if (!hasPermission('view_work_orders') && !hasPermission('manage_work_orders') && !hasPermission('manage_production_queues')) {
    http_response_code(403);
    die('Access denied.');
}

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    redirect('modules/work_orders/list');
}

try {
    $document = work_order_prepare_print_document($pdo, $id, $_GET['section'] ?? 'full');
} catch (RuntimeException $exception) {
    die($exception->getMessage());
}

extract($document, EXTR_SKIP);
require_once __DIR__ . '/../../templates/work_order_print_template.php';
