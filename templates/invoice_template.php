<?php
/**
 * Invoice PDF Template
 *
 * Visual design adapted from the "Juliana Silva / Graphic Solutions"
 * reference: top black band + large INVOICE wordmark, prominent
 * customer name with the invoice number aligned right, dark-headed
 * alternating items table, payment-data + totals split, terms section,
 * and a contact band footer with a decorative two-tone bottom strip.
 *
 * Optimised for Dompdf 3.x (uses table-based layouts, avoids flex).
 *
 * Defensive coding: every invoice / item field is coalesced so legacy
 * rows (where items_json was stored as `{"description":"...","price":"..."}`)
 * render without "Undefined array key" warnings. Older invoices that
 * recorded `subtotal == total_amount` and `tax_amount == 0` have their
 * implicit VAT recovered and surfaced in the totals box.
 *
 * Required input variables (provided by includes/pdf_helper.php):
 *   $invoice  array  Invoice DB row (incl. items_json, vat_percent, etc.)
 *   $business array  Business / branding data (currently unused — settings
 *                    are pulled fresh from settings_helper.php so the PDF
 *                    always reflects the latest configuration).
 */

// --------------------------------------------------------------------
//  1. Normalise the invoice payload
// --------------------------------------------------------------------

date_default_timezone_set('UTC');

$invoice = is_array($invoice ?? null) ? $invoice : [];

$invoice_number       = (string) ($invoice['invoice_number']  ?? '—');
$generated_date       = (string) ($invoice['generated_date']  ?? date('Y-m-d'));
$due_date             = (string) ($invoice['due_date']        ?? $generated_date);
$status               = (string) ($invoice['status']          ?? 'Unpaid');
$customer_name        = (string) ($invoice['customer_name']   ?? '');
$customer_email       = (string) ($invoice['customer_email']  ?? '');
$customer_phone       = (string) ($invoice['customer_phone']  ?? '');
$customer_address     = (string) ($invoice['customer_address'] ?? '');
$customer_subtitle    = (string) ($invoice['customer_subtitle'] ?? ''); // optional, e.g. department
$estimation_number    = (string) ($invoice['estimation_number'] ?? '');
$items_json           = (string) ($invoice['items_json']      ?? '[]');

$total_amount         = (float)  ($invoice['total_amount']    ?? 0);
$paid_amount          = (float)  ($invoice['paid_amount']     ?? 0);
$balance_amount       = isset($invoice['balance']) ? (float) $invoice['balance'] : max(0.0, $total_amount - $paid_amount);
$shipping_fee         = (float)  ($invoice['shipping_fee']    ?? 0);
$tax_amount_raw       = (float)  ($invoice['tax_amount']      ?? 0);
$discount_amount      = (float)  ($invoice['discount']        ?? 0);
$vat_percent          = isset($invoice['vat_percent']) && $invoice['vat_percent'] !== null
    ? (float) $invoice['vat_percent']
    : null;

// Normalise line items so legacy `{description, price}` payloads still
// render and so we always know quantity / unit_price / total_price.
$rawItems = json_decode($items_json, true);
if (!is_array($rawItems)) {
    $rawItems = [];
}
$items = [];
$itemsTotal = 0.0;
foreach ($rawItems as $row) {
    if (!is_array($row)) continue;
    $description = trim((string) ($row['description'] ?? ''));
    $quantity    = isset($row['quantity']) && $row['quantity'] !== '' ? (float) $row['quantity'] : 1.0;
    if ($quantity <= 0) $quantity = 1.0;

    if (isset($row['total_price']) && $row['total_price'] !== '') {
        $totalPrice = (float) $row['total_price'];
    } elseif (isset($row['unit_price']) && $row['unit_price'] !== '') {
        $totalPrice = (float) $row['unit_price'] * $quantity;
    } elseif (isset($row['price']) && $row['price'] !== '') {
        $totalPrice = (float) $row['price'];
    } else {
        $totalPrice = 0.0;
    }
    $unitPrice = $quantity > 0 ? $totalPrice / $quantity : $totalPrice;

    if ($description === '' && $totalPrice <= 0) {
        continue;
    }
    $items[] = [
        'description' => $description,
        'quantity'    => $quantity,
        'unit_price'  => $unitPrice,
        'total_price' => $totalPrice,
    ];
    $itemsTotal += $totalPrice;
}

// Subtotal: prefer the persisted column, fall back to the sum of items.
$persistedSubtotal = isset($invoice['subtotal']) ? (float) $invoice['subtotal'] : 0.0;
$subtotal = $persistedSubtotal > 0 ? $persistedSubtotal : $itemsTotal;

// Tax / VAT amount: prefer the persisted tax_amount; otherwise derive
// from subtotal × vat_percent.
$taxAmount = $tax_amount_raw;
if ($taxAmount <= 0 && $vat_percent !== null && $subtotal > 0) {
    $taxAmount = round($subtotal * ($vat_percent / 100), 2);
}

// Legacy reconciliation: older invoices were saved with
// `subtotal == total_amount` and `tax_amount = 0`, even though items
// were stored pre-VAT. Detect that and recover the implicit VAT.
if (
    $itemsTotal > 0
    && $taxAmount <= 0
    && $shipping_fee <= 0
    && $discount_amount <= 0
    && $total_amount > 0
    && abs($persistedSubtotal - $total_amount) < 0.01
    && $itemsTotal + 0.01 < $total_amount
) {
    $subtotal  = $itemsTotal;
    $taxAmount = round($total_amount - $itemsTotal, 2);
    if ($vat_percent === null && $subtotal > 0) {
        $vat_percent = round(($taxAmount / $subtotal) * 100, 2);
    }
}

if ($vat_percent === null && $subtotal > 0 && $taxAmount > 0) {
    $vat_percent = round(($taxAmount / $subtotal) * 100, 2);
}
$displayVatPercent = $vat_percent ?? 0.0;

// --------------------------------------------------------------------
//  2. Pull business / branding settings
// --------------------------------------------------------------------

require_once __DIR__ . '/../includes/settings_helper.php';
$settings = function_exists('get_business_pdf_settings') ? get_business_pdf_settings() : [];

$defaults = [
    'business_logo'      => '',
    'business_name'      => 'Your Company Name',
    'business_tagline'   => 'Print &amp; Production Services',
    'business_address'   => "123 Business Street\nCity, State, ZIP\nCountry",
    'business_phone'     => '',
    'business_email'     => '',
    'business_website'   => '',
    'business_tax_id'    => '',
    'invoice_terms'      => 'Payment is due within 30 days of invoice date. Please reference the invoice number on all payments. Late payments are subject to fees as outlined in our service agreement.',
    'invoice_footer'     => 'Thank you for your business!',
    'currency_symbol'    => 'MK',
];
foreach ($defaults as $k => $v) {
    if (empty($settings[$k])) {
        $settings[$k] = $v;
    }
}

$currency = (string) $settings['currency_symbol'];
$money = function ($n) use ($currency) {
    return $currency . ' ' . number_format((float) $n, 2);
};

// Resolve logo to an absolute filesystem path so Dompdf can embed it.
$logoPath = '';
if (!empty($settings['business_logo'])) {
    $candidate = $settings['business_logo'];
    if (preg_match('#^https?://#i', $candidate)) {
        $logoPath = $candidate;
    } else {
        $relative = ltrim($candidate, '/\\');
        $resolved = realpath(__DIR__ . '/../' . $relative);
        if ($resolved && is_file($resolved)) {
            $logoPath = $resolved;
        } elseif (!empty($_SERVER['DOCUMENT_ROOT'])) {
            $resolved = realpath($_SERVER['DOCUMENT_ROOT'] . '/' . $relative);
            if ($resolved && is_file($resolved)) {
                $logoPath = $resolved;
            }
        }
    }
}

// --------------------------------------------------------------------
//  3. Status colour + dates
// --------------------------------------------------------------------

$statusKey = strtolower(trim($status));
$statusPalette = [
    'paid'           => ['#1f7a3a', '#e6f4ec'],
    'partially paid' => ['#a06b00', '#fff5e0'],
    'overdue'        => ['#a02020', '#fde8e8'],
    'cancelled'      => ['#555555', '#ececec'],
    'unpaid'         => ['#a02020', '#fde8e8'],
];
[$statusFg, $statusBg] = $statusPalette[$statusKey] ?? ['#a02020', '#fde8e8'];

$generated_date_formatted = date('F j, Y', strtotime($generated_date));
$due_date_formatted       = date('F j, Y', strtotime($due_date));

$ink         = '#111111';   // primary near-black
$inkSoft     = '#444444';   // secondary text
$muted       = '#888888';   // muted labels
$rule        = '#1a1a1a';   // hairlines
$rowAlt      = '#f3f3f3';   // alternate row tint
$bandLight   = '#bdbdbd';   // light decorative band
$wordmark    = '#bcbcbc';   // big "INVOICE" wordmark colour

// First line of the address used as a short single-line value in the
// contact band footer.
$addressFirstLine = trim((string) (explode("\n", (string) $settings['business_address'])[0] ?? ''));

require_once __DIR__ . '/../includes/billing_layout_helper.php';
$layout = get_merged_billing_layout('invoice', isset($billing_layout_variant_override) ? $billing_layout_variant_override : null);
$jobNotes = trim((string) ($invoice['estimation_job_description'] ?? ''));
$showLogoBlock = ($layout['logo_position'] !== 'hidden') && ($logoPath !== '');
$hdrStyle = (string) ($layout['header_style'] ?? 'band');
if (!in_array($hdrStyle, ['band', 'classic', 'minimal'], true)) {
    $hdrStyle = 'band';
}
$logoPos = (string) ($layout['logo_position'] ?? 'left');
if (!in_array($logoPos, ['left', 'right', 'center', 'hidden'], true)) {
    $logoPos = 'left';
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Invoice <?php echo htmlspecialchars($invoice_number); ?></title>
    <style>
        @page { margin: 0 0 50px 0; }

        body {
            font-family: 'DejaVu Sans', Arial, sans-serif;
            color: <?php echo $ink; ?>;
            font-size: 11px;
            line-height: 1.45;
            margin: 0;
            padding: 0;
        }

        h1, h2, h3, h4 { margin: 0; padding: 0; }

        /* ------- Top dark band ------- */
        .top-band {
            background-color: <?php echo $rule; ?>;
            height: 18px;
            width: 100%;
        }

        .page {
            padding: 32px 56px 0 56px;
        }

        /* ------- Header ------- */
        table.layout {
            width: 100%;
            border-collapse: collapse;
        }
        table.layout td { vertical-align: top; padding: 0; }

        .brand-block { vertical-align: middle; }
        .brand-name {
            font-size: 18px;
            font-weight: bold;
            color: <?php echo $ink; ?>;
            letter-spacing: 1.5px;
            text-transform: uppercase;
        }
        .brand-tag {
            font-size: 10px;
            color: <?php echo $inkSoft; ?>;
            margin-top: 2px;
            letter-spacing: 1px;
        }

        .doc-wordmark {
            font-size: 56px;
            font-weight: bold;
            color: <?php echo $wordmark; ?>;
            text-align: right;
            font-style: italic;
            letter-spacing: 2px;
            line-height: 1;
        }

        .rule-thick {
            background-color: <?php echo $rule; ?>;
            height: 2px;
            margin: 14px 0 22px 0;
        }
        .rule-thin {
            background-color: <?php echo $rule; ?>;
            height: 1px;
            margin: 16px 0 22px 0;
        }

        /* ------- Customer + invoice number block ------- */
        .customer-name {
            font-size: 22px;
            font-weight: bold;
            color: <?php echo $ink; ?>;
            margin-bottom: 4px;
        }
        .customer-subtitle {
            font-size: 11px;
            font-weight: bold;
            color: <?php echo $inkSoft; ?>;
            margin-bottom: 6px;
        }
        .customer-line {
            font-size: 10.5px;
            color: <?php echo $inkSoft; ?>;
            line-height: 1.6;
        }

        .invoice-no-label {
            font-size: 10px;
            color: <?php echo $muted; ?>;
            text-align: right;
            letter-spacing: 1px;
        }
        .invoice-no-value {
            font-size: 18px;
            font-weight: bold;
            color: <?php echo $ink; ?>;
            text-align: right;
            margin-top: 2px;
            letter-spacing: 1px;
        }
        .invoice-meta {
            text-align: right;
            font-size: 10px;
            color: <?php echo $inkSoft; ?>;
            margin-top: 8px;
            line-height: 1.6;
        }
        .invoice-meta strong { color: <?php echo $ink; ?>; }
        .status-pill {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 12px;
            font-weight: bold;
            font-size: 9.5px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-top: 4px;
        }

        .ref-strip {
            margin-top: 4px;
            font-size: 10px;
            color: <?php echo $muted; ?>;
            text-align: right;
        }
        .ref-strip strong { color: <?php echo $inkSoft; ?>; }

        /* ------- Items table ------- */
        table.items {
            width: 100%;
            border-collapse: collapse;
            margin-top: 18px;
        }
        table.items thead th {
            background-color: <?php echo $rule; ?>;
            color: #ffffff;
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: 1px;
            text-align: left;
            padding: 10px 12px;
            font-weight: bold;
        }
        table.items thead th.right { text-align: right; }
        table.items tbody td {
            padding: 11px 12px;
            font-size: 11px;
            color: <?php echo $inkSoft; ?>;
            vertical-align: top;
            border: none;
        }
        table.items tbody tr:nth-child(odd) td { background-color: <?php echo $rowAlt; ?>; }
        table.items tbody td.right { text-align: right; white-space: nowrap; }
        table.items tbody td.center { text-align: center; }
        table.items tbody td .item-desc { color: <?php echo $ink; ?>; font-weight: 600; }

        /* ------- Payment data + totals ------- */
        .payment-totals { margin-top: 28px; }
        .payment-totals table.layout td { padding: 0 0 6px 0; }

        .pd-label {
            font-size: 10px;
            font-weight: bold;
            color: <?php echo $ink; ?>;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: 6px;
        }
        .pd-row {
            font-size: 10.5px;
            color: <?php echo $inkSoft; ?>;
            line-height: 1.7;
        }
        .pd-row strong { color: <?php echo $ink; ?>; text-transform: uppercase; letter-spacing: 0.5px; font-size: 10px; }

        table.totals-mini {
            width: 100%;
            border-collapse: collapse;
        }
        table.totals-mini td {
            padding: 4px 0;
            font-size: 11px;
        }
        table.totals-mini td.lbl {
            font-weight: bold;
            color: <?php echo $ink; ?>;
            text-transform: uppercase;
            letter-spacing: 1px;
            font-size: 10px;
        }
        table.totals-mini td.val {
            text-align: right;
            color: <?php echo $inkSoft; ?>;
            font-weight: 600;
        }
        table.totals-mini tr.total-row td {
            padding-top: 8px;
            font-size: 13px;
            color: <?php echo $ink; ?>;
        }
        table.totals-mini tr.total-row td.val { color: <?php echo $ink; ?>; font-weight: bold; }
        table.totals-mini tr.balance-row td {
            color: #8a1a1a;
            font-weight: bold;
        }
        table.totals-mini tr.balance-row td.val { color: #8a1a1a; }

        /* ------- Terms ------- */
        .terms-section { margin-top: 28px; }
        .terms-title {
            font-size: 11px;
            font-weight: bold;
            color: <?php echo $ink; ?>;
            text-transform: uppercase;
            letter-spacing: 1.5px;
            margin-bottom: 6px;
        }
        .terms-body {
            font-size: 10px;
            color: <?php echo $inkSoft; ?>;
            line-height: 1.7;
            text-align: justify;
        }

        /* ------- Contact band footer ------- */
        .contact-band {
            margin-top: 32px;
            margin-bottom: 0;
        }
        table.contact { width: 100%; border-collapse: collapse; }
        table.contact td {
            padding: 6px 8px;
            vertical-align: middle;
        }
        .contact-icon {
            width: 26px;
            height: 26px;
            border-radius: 13px;
            background-color: <?php echo $rule; ?>;
            color: #ffffff;
            display: inline-block;
            text-align: center;
            font-size: 13px;
            line-height: 26px;
            margin-right: 8px;
            vertical-align: middle;
        }
        .contact-icon-cell {
            width: 36px;
            text-align: center;
        }
        .contact-label {
            font-size: 11px;
            font-weight: bold;
            color: <?php echo $ink; ?>;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .contact-value {
            font-size: 10px;
            color: <?php echo $inkSoft; ?>;
            margin-top: 1px;
        }

        /* ------- Decorative bottom band ------- */
        .bottom-deco { margin-top: 22px; }
        table.bottom-deco { width: 100%; border-collapse: collapse; }
        table.bottom-deco td { height: 28px; padding: 0; }
        td.deco-light { background-color: <?php echo $bandLight; ?>; }
        td.deco-dark  { background-color: <?php echo $rule; ?>; }
        td.deco-slope {
            width: 28px;
            border-style: solid;
            border-width: 0 0 28px 28px;
            border-color: transparent transparent <?php echo $rule; ?> transparent;
            background: <?php echo $bandLight; ?>;
            padding: 0;
        }

        /* ------- "PAID" stamp overlay ------- */
        .paid-stamp {
            position: absolute;
            top: 240px;
            right: 80px;
            transform: rotate(-18deg);
            border: 4px solid #1f7a3a;
            color: #1f7a3a;
            padding: 6px 22px;
            font-size: 36px;
            font-weight: bold;
            letter-spacing: 4px;
            opacity: 0.18;
        }

        /* ------- Persistent page footer (visible on every page) ------- */
        .page-footer {
            position: fixed;
            bottom: 16px;
            left: 56px;
            right: 56px;
            text-align: center;
            font-size: 8.5px;
            color: <?php echo $muted; ?>;
        }

        .hdr-classic .doc-wordmark {
            font-size: 34px;
            font-style: normal;
            letter-spacing: 1px;
        }
        .hdr-minimal .doc-wordmark {
            font-size: 26px;
            font-style: normal;
            letter-spacing: 0.5px;
        }
        .hdr-minimal .page {
            padding-top: 22px;
        }
        .hdr-minimal .rule-thick {
            margin: 8px 0 14px 0;
        }
        .header-center-stack {
            text-align: center;
        }
        .header-center-stack .brand-name { letter-spacing: 1px; }
        .notes-section {
            margin-top: 14px;
            padding: 12px 14px;
            border: 1px solid #ddd;
            background: #fafafa;
            font-size: 10px;
            line-height: 1.5;
        }
        .notes-title {
            font-weight: bold;
            font-size: 10px;
            margin-bottom: 6px;
            color: <?php echo $ink; ?>;
        }
        .sig-wrap { margin-top: 22px; page-break-inside: avoid; }
        .sig-table { width: 100%; border-collapse: collapse; }
        .sig-table td { width: 33%; text-align: center; vertical-align: top; padding: 0 8px; }
        .sig-line {
            border-top: 1px solid <?php echo $rule; ?>;
            margin: 36px 0 6px 0;
        }
        .sig-label { font-size: 9.5px; color: <?php echo $inkSoft; ?>; }
    </style>
</head>
<body class="hdr-<?php echo htmlspecialchars($hdrStyle); ?>">

<?php if ($hdrStyle === 'band'): ?>
<div class="top-band">&nbsp;</div>
<?php endif; ?>

<?php if ($statusKey === 'paid'): ?>
    <div class="paid-stamp">PAID</div>
<?php endif; ?>

<div class="page">

    <!-- ============== Header ============== -->
    <?php
    $brandCell = function () use ($showLogoBlock, $logoPath, $settings, $ink, $inkSoft) {
        ?>
        <table class="layout"><tr>
            <?php if ($showLogoBlock): ?>
                <td style="width: 60px; padding-right: 10px; vertical-align: middle;">
                    <img src="<?php echo htmlspecialchars($logoPath); ?>"
                         alt="<?php echo htmlspecialchars($settings['business_name']); ?>"
                         style="max-width: 56px; max-height: 56px;">
                </td>
            <?php endif; ?>
            <td style="vertical-align: middle;">
                <div class="brand-name"><?php echo htmlspecialchars($settings['business_name']); ?></div>
                <?php if (!empty($settings['business_tagline'])): ?>
                    <div class="brand-tag"><?php echo htmlspecialchars($settings['business_tagline']); ?></div>
                <?php endif; ?>
            </td>
        </tr></table>
        <?php
    };
    $wordmarkHtml = '<div class="doc-wordmark">INVOICE</div>';
    ?>
    <?php if ($logoPos === 'center'): ?>
    <table class="layout">
        <tr>
            <td class="header-center-stack" style="width: 100%;">
                <?php if ($showLogoBlock): ?>
                    <div style="margin-bottom: 8px;">
                        <img src="<?php echo htmlspecialchars($logoPath); ?>"
                             alt="<?php echo htmlspecialchars($settings['business_name']); ?>"
                             style="max-width: 72px; max-height: 72px;">
                    </div>
                <?php endif; ?>
                <div class="brand-name"><?php echo htmlspecialchars($settings['business_name']); ?></div>
                <?php if (!empty($settings['business_tagline'])): ?>
                    <div class="brand-tag"><?php echo htmlspecialchars($settings['business_tagline']); ?></div>
                <?php endif; ?>
                <div style="margin-top: 10px;"><?php echo $wordmarkHtml; ?></div>
            </td>
        </tr>
    </table>
    <?php elseif ($logoPos === 'right'): ?>
    <table class="layout">
        <tr>
            <td style="width: 42%; vertical-align: middle; text-align: right;">
                <?php echo $wordmarkHtml; ?>
            </td>
            <td style="width: 58%; vertical-align: middle; text-align: right;">
                <table class="layout" align="right"><tr>
                    <td style="vertical-align: middle; text-align: right;">
                        <div class="brand-name"><?php echo htmlspecialchars($settings['business_name']); ?></div>
                        <?php if (!empty($settings['business_tagline'])): ?>
                            <div class="brand-tag"><?php echo htmlspecialchars($settings['business_tagline']); ?></div>
                        <?php endif; ?>
                    </td>
                    <?php if ($showLogoBlock): ?>
                        <td style="width: 60px; padding-left: 10px; vertical-align: middle;">
                            <img src="<?php echo htmlspecialchars($logoPath); ?>"
                                 alt="<?php echo htmlspecialchars($settings['business_name']); ?>"
                                 style="max-width: 56px; max-height: 56px;">
                        </td>
                    <?php endif; ?>
                </tr></table>
            </td>
        </tr>
    </table>
    <?php else: ?>
    <table class="layout">
        <tr>
            <td class="brand-block" style="width: 60%;">
                <?php $brandCell(); ?>
            </td>
            <td style="width: 40%; vertical-align: middle;">
                <?php echo $wordmarkHtml; ?>
            </td>
        </tr>
    </table>
    <?php endif; ?>

    <div class="rule-thick"></div>

    <!-- ============== Customer + Invoice Number ============== -->
    <table class="layout">
        <tr>
            <td style="width: 60%;">
                <div class="customer-name"><?php echo htmlspecialchars($customer_name !== '' ? $customer_name : '—'); ?></div>
                <?php if ($customer_subtitle !== ''): ?>
                    <div class="customer-subtitle"><?php echo htmlspecialchars($customer_subtitle); ?></div>
                <?php endif; ?>
                <?php if ($customer_address !== ''): ?>
                    <div class="customer-line"><?php echo nl2br(htmlspecialchars($customer_address)); ?></div>
                <?php endif; ?>
                <?php if ($customer_email !== ''): ?>
                    <div class="customer-line"><?php echo htmlspecialchars($customer_email); ?></div>
                <?php endif; ?>
                <?php if ($customer_phone !== ''): ?>
                    <div class="customer-line"><?php echo htmlspecialchars($customer_phone); ?></div>
                <?php endif; ?>
            </td>
            <td style="width: 40%;">
                <div class="invoice-no-label">INVOICE#</div>
                <div class="invoice-no-value"><?php echo htmlspecialchars($invoice_number); ?></div>
                <div class="invoice-meta">
                    <strong>Issued:</strong> <?php echo $generated_date_formatted; ?><br>
                    <strong>Due:</strong> <?php echo $due_date_formatted; ?><br>
                    <span class="status-pill" style="color: <?php echo $statusFg; ?>; background-color: <?php echo $statusBg; ?>;">
                        <?php echo htmlspecialchars($status); ?>
                    </span>
                </div>
                <?php if ($estimation_number !== ''): ?>
                    <div class="ref-strip">
                        <strong>Reference:</strong> <?php echo htmlspecialchars($estimation_number); ?>
                    </div>
                <?php endif; ?>
            </td>
        </tr>
    </table>

    <?php if (!empty($layout['show_notes']) && $jobNotes !== ''): ?>
        <div class="notes-section">
            <div class="notes-title">Job notes</div>
            <div><?php echo nl2br(htmlspecialchars($jobNotes)); ?></div>
        </div>
    <?php endif; ?>

    <!-- ============== Items ============== -->
    <table class="items">
        <thead>
            <tr>
                <th style="width: 52%;">Product</th>
                <th class="right" style="width: 18%;">Price</th>
                <th class="right" style="width: 10%;">Qty</th>
                <th class="right" style="width: 20%;">Total</th>
            </tr>
        </thead>
        <tbody>
            <?php if (!empty($items)): ?>
                <?php foreach ($items as $i => $item): ?>
                    <tr>
                        <td>
                            <div class="item-desc">
                                <?php echo nl2br(htmlspecialchars($item['description'])); ?>
                            </div>
                        </td>
                        <td class="right"><?php echo number_format($item['unit_price'], 2); ?></td>
                        <td class="right"><?php echo number_format($item['quantity'], $item['quantity'] == (int) $item['quantity'] ? 0 : 2); ?></td>
                        <td class="right"><?php echo number_format($item['total_price'], 2); ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr>
                    <td class="center" colspan="4" style="color:#888; font-style:italic;">
                        No line items recorded.
                    </td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>

    <!-- ============== Payment data + totals ============== -->
    <div class="payment-totals">
        <table class="layout">
            <tr>
                <?php if (!empty($layout['show_payment_details'])): ?>
                <td style="width: 55%; padding-right: 16px;">
                    <div class="pd-label">Payment Data:</div>
                    <?php if (!empty($settings['bank_name'])): ?>
                        <div class="pd-row"><strong>Bank:</strong> <?php echo htmlspecialchars($settings['bank_name']); ?></div>
                    <?php endif; ?>
                    <?php if (!empty($settings['account_number'])): ?>
                        <div class="pd-row"><strong>Account#:</strong> <?php echo htmlspecialchars($settings['account_number']); ?></div>
                    <?php endif; ?>
                    <?php if (!empty($settings['bank_branch'])): ?>
                        <div class="pd-row"><strong>Branch:</strong> <?php echo htmlspecialchars($settings['bank_branch']); ?></div>
                    <?php endif; ?>
                    <?php if (!empty($settings['swift_code'])): ?>
                        <div class="pd-row"><strong>SWIFT:</strong> <?php echo htmlspecialchars($settings['swift_code']); ?></div>
                    <?php endif; ?>
                    <?php if (!empty($settings['iban'])): ?>
                        <div class="pd-row"><strong>IBAN:</strong> <?php echo htmlspecialchars($settings['iban']); ?></div>
                    <?php endif; ?>
                    <div class="pd-row"><strong>Name:</strong> <?php echo htmlspecialchars($settings['business_name']); ?></div>
                    <div class="pd-row" style="margin-top: 6px; color: <?php echo $muted; ?>; font-size: 9.5px;">
                        Please reference invoice <strong><?php echo htmlspecialchars($invoice_number); ?></strong>
                        on all payments.
                    </div>
                </td>
                <td style="width: 45%; vertical-align: top;">
                <?php else: ?>
                <td style="width: 100%; vertical-align: top; text-align: right;" colspan="2">
                <?php endif; ?>
                    <table class="totals-mini" style="<?php echo empty($layout['show_payment_details']) ? 'margin-left: auto;' : ''; ?>">
                        <tr>
                            <td class="lbl">Subtotal</td>
                            <td class="val"><?php echo $money($subtotal); ?></td>
                        </tr>
                        <?php if (!empty($layout['show_tax_breakdown'])): ?>
                        <?php if ($discount_amount > 0): ?>
                            <tr>
                                <td class="lbl">Discount</td>
                                <td class="val">- <?php echo $money($discount_amount); ?></td>
                            </tr>
                        <?php endif; ?>
                        <?php if ($shipping_fee > 0): ?>
                            <tr>
                                <td class="lbl">Shipping</td>
                                <td class="val"><?php echo $money($shipping_fee); ?></td>
                            </tr>
                        <?php endif; ?>
                        <?php if ($taxAmount > 0 || $displayVatPercent > 0): ?>
                            <tr>
                                <td class="lbl">Tax (<?php echo number_format($displayVatPercent, 2); ?>%)</td>
                                <td class="val"><?php echo $money($taxAmount); ?></td>
                            </tr>
                        <?php endif; ?>
                        <?php endif; ?>
                        <tr class="total-row">
                            <td class="lbl">Total</td>
                            <td class="val"><?php echo $money($total_amount); ?></td>
                        </tr>
                        <?php if ($paid_amount > 0): ?>
                            <tr>
                                <td class="lbl">Paid</td>
                                <td class="val">- <?php echo $money($paid_amount); ?></td>
                            </tr>
                        <?php endif; ?>
                        <?php if ($balance_amount > 0.005 || $paid_amount > 0): ?>
                            <tr class="balance-row">
                                <td class="lbl">Balance Due</td>
                                <td class="val"><?php echo $money(max(0, $balance_amount)); ?></td>
                            </tr>
                        <?php endif; ?>
                    </table>
                </td>
            </tr>
        </table>
    </div>

    <div class="rule-thin"></div>

    <!-- ============== Terms and Conditions ============== -->
    <?php if (!empty($layout['show_terms']) && !empty($settings['invoice_terms'])): ?>
        <div class="terms-section">
            <div class="terms-title">Terms and Conditions</div>
            <div class="terms-body"><?php echo nl2br(htmlspecialchars($settings['invoice_terms'])); ?></div>
        </div>
    <?php endif; ?>

    <?php if (!empty($layout['show_signatures'])): ?>
    <div class="sig-wrap">
        <table class="sig-table">
            <tr>
                <td>
                    <div class="sig-line"></div>
                    <div class="sig-label"><?php echo htmlspecialchars((string) ($settings['signature1_title'] ?? 'Authorized Signature')); ?></div>
                </td>
                <td>
                    <div class="sig-line"></div>
                    <div class="sig-label"><?php echo htmlspecialchars((string) ($settings['signature2_title'] ?? 'Customer Signature')); ?></div>
                </td>
                <td>
                    <div class="sig-line"></div>
                    <div class="sig-label"><?php echo htmlspecialchars((string) ($settings['signature3_title'] ?? 'Date')); ?></div>
                </td>
            </tr>
        </table>
    </div>
    <?php endif; ?>

    <?php if (!empty($layout['show_footer'])): ?>
    <!-- ============== Contact band ============== -->
    <div class="contact-band">
        <table class="contact">
            <tr>
                <?php if (!empty($settings['business_phone'])): ?>
                    <td style="width: 33%;">
                        <table class="layout"><tr>
                            <td class="contact-icon-cell">
                                <span class="contact-icon">&#9742;</span>
                            </td>
                            <td>
                                <div class="contact-label">Phone:</div>
                                <div class="contact-value"><?php echo htmlspecialchars($settings['business_phone']); ?></div>
                            </td>
                        </tr></table>
                    </td>
                <?php endif; ?>
                <?php if (!empty($settings['business_email'])): ?>
                    <td style="width: 33%;">
                        <table class="layout"><tr>
                            <td class="contact-icon-cell">
                                <span class="contact-icon">&#9993;</span>
                            </td>
                            <td>
                                <div class="contact-label">Email:</div>
                                <div class="contact-value"><?php echo htmlspecialchars($settings['business_email']); ?></div>
                            </td>
                        </tr></table>
                    </td>
                <?php endif; ?>
                <?php if ($addressFirstLine !== ''): ?>
                    <td style="width: 34%;">
                        <table class="layout"><tr>
                            <td class="contact-icon-cell">
                                <span class="contact-icon">&#8962;</span>
                            </td>
                            <td>
                                <div class="contact-label">Address:</div>
                                <div class="contact-value"><?php echo htmlspecialchars($addressFirstLine); ?></div>
                            </td>
                        </tr></table>
                    </td>
                <?php endif; ?>
            </tr>
        </table>
    </div>
</div>

<!-- ============== Decorative bottom band ============== -->
<div class="bottom-deco">
    <table class="bottom-deco">
        <tr>
            <td class="deco-light" style="width: 55%;">&nbsp;</td>
            <td class="deco-slope">&nbsp;</td>
            <td class="deco-dark">&nbsp;</td>
        </tr>
    </table>
</div>

<!-- ============== Persistent page footer ============== -->
<div class="page-footer">
    <strong><?php echo htmlspecialchars($settings['business_name']); ?></strong>
    <?php if (!empty($settings['business_website'])): ?>
        &middot; <?php echo htmlspecialchars($settings['business_website']); ?>
    <?php endif; ?>
    <?php if (!empty($settings['invoice_footer'])): ?>
        &middot; <?php echo htmlspecialchars($settings['invoice_footer']); ?>
    <?php endif; ?>
</div>
<?php endif; ?>

</body>
</html>
