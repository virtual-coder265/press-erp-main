<?php
/**
 * Sales receipt PDF download (extensionless URL: modules/invoices/receipt?id=X[&payment_id=Y]).
 */

require_once __DIR__ . '/../../config/app.php';
checkAuth();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/pdf_helper.php';
require_once __DIR__ . '/../../includes/settings_helper.php';

if (function_exists('checkPermission')) {
    checkPermission('manage_invoices');
}

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$paymentId = isset($_GET['payment_id']) ? (int) $_GET['payment_id'] : 0;

if ($id <= 0) {
    redirect('modules/invoices/list');
}

$stmt = $pdo->prepare("
    SELECT i.*, e.estimation_number,
           e.job_description AS estimation_job_description,
           COALESCE(e.customer_name, i.customer_name) AS customer_name
    FROM invoices i
    LEFT JOIN estimations e ON i.estimation_id = e.id
    WHERE i.id = ?
");
$stmt->execute([$id]);
$invoice = $stmt->fetch();

if (!$invoice) {
    http_response_code(404);
    die('Invoice not found.');
}

$payStmt = $pdo->prepare("
    SELECT p.*
    FROM invoice_payments p
    WHERE p.invoice_id = ?
    ORDER BY p.payment_date ASC, p.id ASC
");
$payStmt->execute([$id]);
$allPayments = $payStmt->fetchAll();

if ($paymentId > 0) {
    $payments = array_values(array_filter($allPayments, static function ($row) use ($paymentId) {
        return (int) ($row['id'] ?? 0) === $paymentId;
    }));
} else {
    $payments = $allPayments;
}

if (empty($payments)) {
    http_response_code(404);
    die('No payments available for a receipt.');
}

$business = get_business_pdf_settings();

try {
    generateSalesReceiptPdf($invoice, $payments, $business, true);
} catch (Throwable $e) {
    http_response_code(500);
    die('Could not generate receipt.');
}
exit;
