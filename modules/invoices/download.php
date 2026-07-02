<?php
/**
 * Invoice Download Endpoint
 *
 * Streams the invoice as a real PDF attachment so opening the link always
 * triggers a browser download (Content-Disposition: attachment).
 *
 * Use the extensionless URL `modules/invoices/download?id=X` so the
 * .htaccess rewrite handles routing properly.
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
if ($id <= 0) {
    redirect('modules/invoices/list');
}

$stmt = $pdo->prepare("
    SELECT i.*, e.estimation_number,
           e.job_description AS estimation_job_description,
           COALESCE(e.customer_name, i.customer_name)   AS customer_name,
           COALESCE(e.customer_email, i.customer_email) AS customer_email,
           COALESCE(e.customer_phone, i.customer_phone) AS customer_phone
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

if (!isset($invoice['customer_address']) || $invoice['customer_address'] === null) {
    $invoice['customer_address'] = '';
}

// The legacy template extracts $items_json from $invoice. Make sure it is
// always present so older payloads do not crash dompdf.
if (!isset($invoice['items_json']) || $invoice['items_json'] === null) {
    $invoice['items_json'] = json_encode([]);
}

$business = get_business_pdf_settings();

// generateInvoicePdf streams with `Attachment => true` when the third arg
// is true, which is exactly the contract for a download endpoint.
generateInvoicePdf($invoice, $business, true);
exit;
