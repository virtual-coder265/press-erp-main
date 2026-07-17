<?php
/**
 * Work Order PDF export — full traveler or a single section.
 *
 * Query params:
 *   id       Work order ID (required)
 *   section  full|job|forme|composing|letterpress|bookbinding|materials
 *   download 1 = attachment (default), 0 = inline preview
 */

require_once __DIR__ . '/../../config/app.php';
checkAuth();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/work_order_helper.php';
require_once __DIR__ . '/../../includes/pdf_helper.php';

if (!hasPermission('view_work_orders') && !hasPermission('manage_work_orders') && !hasPermission('manage_production_queues')) {
    http_response_code(403);
    die('Access denied.');
}

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    redirect('modules/work_orders/list');
}

$download = !isset($_GET['download']) || (string) $_GET['download'] !== '0';
$section = trim((string) ($_GET['section'] ?? 'full'));

try {
    $document = work_order_prepare_print_document($pdo, $id, $section);
} catch (RuntimeException $exception) {
    http_response_code(404);
    die($exception->getMessage());
}

generateWorkOrderPdf(
    $document['workOrder'],
    $document['productionForm'],
    $document['formeDressing'],
    $document['trimMargins'],
    $document['printSection'],
    $document['sectionTitle'],
    $download
);
exit;
