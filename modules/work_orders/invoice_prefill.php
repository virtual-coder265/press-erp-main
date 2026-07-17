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

$invoiceId = (int) ($_GET['invoice_id'] ?? 0);
if ($invoiceId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid invoice.']);
    exit;
}

work_order_bootstrap($pdo);

$existingStmt = $pdo->prepare("SELECT id, work_order_number FROM work_orders WHERE invoice_id = ? LIMIT 1");
$existingStmt->execute([$invoiceId]);
$existing = $existingStmt->fetch(PDO::FETCH_ASSOC);
if ($existing) {
    echo json_encode([
        'success' => false,
        'message' => 'A work order already exists for this invoice: ' . $existing['work_order_number'],
        'existing_work_order_id' => (int) $existing['id'],
    ]);
    exit;
}

try {
    $prefill = work_order_prefill_from_invoice($pdo, $invoiceId);
    echo json_encode([
        'success' => true,
        'data' => work_order_prefill_to_json($prefill),
    ]);
} catch (Throwable $exception) {
    echo json_encode(['success' => false, 'message' => $exception->getMessage()]);
}
