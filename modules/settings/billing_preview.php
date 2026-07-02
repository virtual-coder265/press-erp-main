<?php
/**
 * Billing layout PDF previews (sample data). Extensionless URL:
 * modules/settings/billing_preview?doc=invoice|quote|receipt&variant=executive (optional).
 */

require_once __DIR__ . '/../../config/app.php';
checkAuth();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/settings_helper.php';
require_once __DIR__ . '/../../includes/billing_layout_helper.php';
require_once __DIR__ . '/../../vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

if (($_SESSION['role'] ?? '') !== 'System Admin' && !hasPermission('manage_settings')) {
    die('Access Denied.');
}

$doc = strtolower(trim((string) ($_GET['doc'] ?? 'invoice')));
$variant = isset($_GET['variant']) ? trim((string) $_GET['variant']) : '';
if ($variant !== '' && !in_array($variant, BILLING_LAYOUT_VARIANT_IDS, true)) {
    $variant = '';
}
$variantParam = $variant !== '' ? $variant : null;

if ($doc === 'invoice') {
    require_once __DIR__ . '/../../includes/pdf_helper.php';

    $invoice = [
        'invoice_number' => 'PREVIEW-001',
        'generated_date' => date('Y-m-d'),
        'due_date' => date('Y-m-d', strtotime('+30 days')),
        'status' => 'Partially Paid',
        'customer_name' => 'Sample Customer Ltd.',
        'customer_email' => 'billing@example.com',
        'customer_phone' => '+265 000 000 000',
        'customer_address' => "123 Sample Street\nLilongwe\nMalawi",
        'customer_subtitle' => 'Accounts Payable',
        'estimation_number' => 'EST-PREVIEW-001',
        'estimation_job_description' => "Sample job notes for preview.\nIncludes layout toggles for notes section.",
        'items_json' => json_encode([
            [
                'description' => "Sample line: booklet printing\nA4, full colour, saddle stitch",
                'quantity' => 500,
                'unit_price' => 2.5,
                'total_price' => 1250.0,
            ],
            [
                'description' => 'Binding & finishing',
                'quantity' => 1,
                'unit_price' => 350.0,
                'total_price' => 350.0,
            ],
        ]),
        'subtotal' => 1600.0,
        'tax_amount' => 240.0,
        'vat_percent' => 15.0,
        'discount' => 0.0,
        'shipping_fee' => 0.0,
        'total_amount' => 1840.0,
        'paid_amount' => 400.0,
        'balance' => 1440.0,
    ];

    generateInvoicePdf($invoice, [], false, $variantParam);
    exit;
}

if ($doc === 'quote') {
    $billing_layout_variant_override = $variantParam;

    $est = [
        'estimation_number' => 'EST-PREVIEW-001',
        'customer_name' => 'Sample Customer Ltd.',
        'customer_email' => 'quotes@example.com',
        'customer_phone' => '+265 000 000 000',
        'job_description' => "Sample estimation job description for preview.\nLine two with details.",
        'created_at' => date('Y-m-d H:i:s'),
        'status' => 'Draft',
        'total_amount' => 1840.0,
    ];

    $items = [
        [
            'description' => 'Sample product / service line',
            'item_type' => 'Service',
            'total_price' => 1250.0,
        ],
        [
            'description' => 'Additional work',
            'item_type' => 'Other',
            'total_price' => 590.0,
        ],
    ];

    ob_start();
    require __DIR__ . '/../../templates/estimation_print_template.php';
    $html = ob_get_clean();
    $html = preg_replace('/<div\s+class="no-print"[^>]*>.*?<\/div>/is', '', (string) $html);

    $options = new Options();
    $options->set('isHtml5ParserEnabled', true);
    $options->set('isRemoteEnabled', true);
    $options->set('defaultFont', 'DejaVu Sans');
    $options->set('defaultMediaType', 'print');

    $dompdf = new Dompdf($options);
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();
    $dompdf->stream('preview_estimation.pdf', ['Attachment' => false]);
    exit;
}

if ($doc === 'receipt') {
    require_once __DIR__ . '/../../includes/pdf_helper.php';

    $invoice = [
        'invoice_number' => 'PREVIEW-001',
        'customer_name' => 'Sample Customer Ltd.',
        'estimation_number' => 'EST-PREVIEW-001',
        'estimation_job_description' => 'Sample job notes on receipt preview.',
        'total_amount' => 1840.0,
        'paid_amount' => 900.0,
        'balance' => 940.0,
    ];

    $payments = [
        [
            'id' => 1,
            'payment_date' => date('Y-m-d', strtotime('-14 days')),
            'payment_method' => 'Bank Transfer',
            'transaction_id' => 'TXN-PREVIEW-1',
            'gr_number' => '7855123',
            'amount' => 500.0,
        ],
        [
            'id' => 2,
            'payment_date' => date('Y-m-d'),
            'payment_method' => 'Cash',
            'transaction_id' => '',
            'gr_number' => '7855124',
            'amount' => 400.0,
        ],
    ];

    generateSalesReceiptPdf($invoice, $payments, [], false, $variantParam);
    exit;
}

http_response_code(404);
header('Content-Type: text/plain; charset=UTF-8');
echo 'Unknown preview document.';
