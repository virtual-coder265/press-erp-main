<?php
/**
 * Estimation Print Template
 * 
 * This template is used for printing estimations.
 * 
 * @param array $est The estimation data array
 * @param array $items The estimation items array
 * @param array $papers Paper rows
 * @param array $inkRows Ink colour rows
 * @param array $binding Binding material rows
 * @param array $prepress Pre-press labour rows
 * @param array $press Press labour rows
 * @param array $finishing Finishing labour rows
 * @param array $subtotals Section subtotals keyed by section
 * @param array $business Business information array
 * @return string HTML output
 */

// Set default timezone
date_default_timezone_set('UTC');

// Get business settings from database
require_once __DIR__ . '/../includes/settings_helper.php';
$settings = get_business_pdf_settings();

// Set default values if not set
$settings['business_logo'] = $settings['business_logo'] ?? '';
$settings['business_name'] = $settings['business_name'] ?? 'Government Press';
$settings['business_address'] = $settings['business_address'] ?? "Government Press Road\nZomba, Malawi";
$settings['business_phone'] = $settings['business_phone'] ?? '';
$settings['business_email'] = $settings['business_email'] ?? '';
$settings['business_website'] = $settings['business_website'] ?? '';
$settings['business_tax_id'] = $settings['business_tax_id'] ?? '';
$settings['estimation_disclaimer'] = $settings['estimation_disclaimer'] ?? 'This is an estimation, not a final invoice. Prices are subject to change.';
$settings['signature1_title'] = $settings['signature1_title'] ?? 'Prepared By';
$settings['signature2_title'] = $settings['signature2_title'] ?? 'Approved By';
$settings['signature3_title'] = $settings['signature3_title'] ?? 'Date';

require_once __DIR__ . '/../includes/billing_layout_helper.php';
require_once __DIR__ . '/../includes/pdf_helper.php';
require_once __DIR__ . '/../includes/estimation_view_data_helper.php';
$layout = get_merged_billing_layout('quote', isset($billing_layout_variant_override) ? $billing_layout_variant_override : null);

$logoSrc = '';
if (!empty($settings['business_logo'])) {
    $resolved = resolve_pdf_embed_image_src((string) $settings['business_logo']);
    if ($resolved !== null) {
        $logoSrc = $resolved;
    }
}
$showLogoBlock = ($layout['logo_position'] !== 'hidden') && ($logoSrc !== '');
$hdrStyle = (string) ($layout['header_style'] ?? 'band');
if (!in_array($hdrStyle, ['band', 'classic', 'minimal'], true)) {
    $hdrStyle = 'band';
}
$logoPos = (string) ($layout['logo_position'] ?? 'left');
if (!in_array($logoPos, ['left', 'right', 'center', 'hidden'], true)) {
    $logoPos = 'left';
}

$papers = $papers ?? [];
$standard_materials = $standard_materials ?? [];
$other_items = $other_items ?? [];
$inkRows = $inkRows ?? [];
$binding = $binding ?? [];
$prepress = $prepress ?? [];
$press = $press ?? [];
$finishing = $finishing ?? [];
$consumables = $consumables ?? [];
$subtotals = $subtotals ?? estimation_compute_section_subtotals($items ?? [], $papers, $inkRows, $binding, $prepress, $press, $finishing);

$formatMoney = static function ($value): string {
    return number_format((float) $value, 2);
};

$formatQty = static function ($value, int $decimals = 2): string {
    if ($value === null || $value === '') {
        return '—';
    }

    return number_format((float) $value, $decimals);
};

$renderSectionHeading = static function (string $title, float $subtotal) use ($formatMoney): void {
    echo '<div class="section-heading">';
    echo '<span class="section-title">' . htmlspecialchars($title) . '</span>';
    echo '<span class="section-subtotal">Subtotal: MK ' . $formatMoney($subtotal) . '</span>';
    echo '</div>';
};

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Estimation
        <?php echo htmlspecialchars($est['estimation_number']); ?>
    </title>
    <style>
        /* Base Styles */
        body {
            font-family: 'DejaVu Sans', Arial, sans-serif;
            line-height: 1.6;
            color: #333;
            font-size: 12px;
            margin: 0;
            padding: 20px;
            background: #fff;
        }

        /* Header */
        .header {
            display: flex;
            justify-content: space-between;
            margin-bottom: 30px;
            border-bottom: 2px solid #eee;
            padding-bottom: 20px;
        }

        .logo {
            max-width: 150px;
            max-height: 80px;
        }

        .document-title {
            font-size: 24px;
            font-weight: bold;
            color: #2c3e50;
            margin: 0 0 5px 0;
            text-transform: uppercase;
        }

        .document-number {
            font-size: 14px;
            color: #7f8c8d;
            margin: 0 0 15px 0;
        }

        /* Client and Company Info */
        .info-section {
            display: flex;
            justify-content: space-between;
            margin-bottom: 30px;
        }

        .info-box {
            width: 48%;
            padding: 15px;
            border: 1px solid #eee;
            border-radius: 5px;
            background-color: #f9f9f9;
        }

        .info-title {
            font-weight: bold;
            margin-bottom: 10px;
            color: #2c3e50;
            border-bottom: 1px solid #eee;
            padding-bottom: 5px;
        }

        /* Items Table */
        .items-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }

        .items-table th {
            background-color: #2c3e50;
            color: white;
            text-align: left;
            padding: 10px;
        }

        .items-table td {
            padding: 10px;
            border-bottom: 1px solid #eee;
        }

        .items-table tr:nth-child(even) {
            background-color: #f9f9f9;
        }

        .text-right {
            text-align: right;
        }

        .text-center {
            text-align: center;
        }

        .section-heading {
            display: flex;
            justify-content: space-between;
            align-items: baseline;
            margin: 24px 0 8px;
            padding-bottom: 6px;
            border-bottom: 1px solid #ddd;
        }

        .section-title {
            font-size: 13px;
            font-weight: bold;
            color: #2c3e50;
            text-transform: uppercase;
            letter-spacing: 0.03em;
        }

        .section-subtotal {
            font-size: 11px;
            color: #666;
        }

        .detail-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 16px;
            font-size: 10px;
        }

        .detail-table th {
            background-color: #ecf0f1;
            color: #2c3e50;
            text-align: left;
            padding: 7px 8px;
            border-bottom: 1px solid #ddd;
        }

        .detail-table td {
            padding: 7px 8px;
            border-bottom: 1px solid #eee;
            vertical-align: top;
        }

        .detail-table tr:nth-child(even) td {
            background-color: #fafafa;
        }

        .summary-grid {
            width: 100%;
            border-collapse: collapse;
            margin: 12px 0 18px;
            font-size: 11px;
        }

        .summary-grid td {
            padding: 6px 8px;
            border-bottom: 1px solid #eee;
        }

        .summary-grid td:last-child {
            text-align: right;
            font-weight: 600;
        }

        .buildup-box {
            margin-top: 16px;
            padding: 12px 14px;
            border: 1px solid #eee;
            background: #f9f9f9;
            font-size: 11px;
        }

        .buildup-title {
            font-weight: bold;
            margin-bottom: 8px;
            color: #2c3e50;
            text-transform: uppercase;
            letter-spacing: 0.03em;
            font-size: 10px;
        }

        .grand-total-box {
            margin-top: 14px;
            padding: 12px 14px;
            background: #1f7a4d;
            color: #fff;
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 13px;
            font-weight: bold;
        }

        .grand-total-box span:last-child {
            font-size: 18px;
        }

        /* Totals */
        .totals {
            width: 100%;
            max-width: 300px;
            margin-left: auto;
            margin-bottom: 30px;
        }

        .totals-row {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            border-bottom: 1px solid #eee;
        }

        .totals-row.total {
            font-weight: bold;
            font-size: 1.1em;
            border-top: 2px solid #2c3e50;
            margin-top: 5px;
            padding-top: 10px;
        }

        /* Signatures */
        .signatures {
            display: flex;
            justify-content: space-between;
            margin-top: 50px;
            page-break-inside: avoid;
        }

        .signature-box {
            width: 30%;
            text-align: center;
        }

        .signature-line {
            border-top: 1px solid #333;
            margin: 40px 0 5px;
            width: 100%;
        }

        .signature-label {
            font-size: 12px;
            color: #666;
        }

        /* Footer */
        .footer {
            margin-top: 50px;
            padding-top: 20px;
            border-top: 1px solid #eee;
            font-size: 11px;
            color: #7f8c8d;
            text-align: center;
        }

        /* Job Description */
        .job-description {
            margin-bottom: 20px;
            padding: 15px;
            background-color: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 0.375rem;
        }

        /* Print Styles */
        @media print {
            body {
                padding: 0;
                font-size: 11px;
            }

            .no-print {
                display: none !important;
            }

            .items-table,
            .job-description,
            .signatures {
                page-break-inside: avoid;
            }

            .info-box {
                background-color: #fff;
                /* Save ink */
                border: 1px solid #ccc;
            }
        }
    </style>
</head>

<body class="est-hdr-<?php echo htmlspecialchars($hdrStyle); ?>">

    <?php if ($hdrStyle === 'band'): ?>
    <div style="background-color:#1a1a1a;height:10px;width:100%;margin:0 0 16px 0;">&nbsp;</div>
    <?php endif; ?>

    <!-- Header -->
    <?php if ($logoPos === 'center'): ?>
    <table style="width:100%;border-collapse:collapse;margin-bottom:20px;border-bottom:2px solid #eee;padding-bottom:16px;">
        <tr>
            <td style="text-align:center;vertical-align:top;">
                <?php if ($showLogoBlock): ?>
                    <img src="<?php echo htmlspecialchars($logoSrc); ?>" alt="" class="logo" style="max-height:70px;">
                    <div style="height:8px;"></div>
                <?php endif; ?>
                <div class="document-title" style="font-size:<?php echo $hdrStyle === 'minimal' ? '18px' : '22px'; ?>;"><?php echo htmlspecialchars($settings['business_name']); ?></div>
                <div style="margin-top:8px;font-size:11px;color:#555;"><?php echo nl2br(htmlspecialchars($settings['business_address'])); ?></div>
                <div class="document-title" style="margin-top:14px;">ESTIMATION</div>
                <div class="document-number"># <?php echo htmlspecialchars($est['estimation_number']); ?></div>
                <div style="font-size:11px;color:#555;">
                    <strong>Date:</strong> <?php echo date('F j, Y', strtotime($est['created_at'])); ?>
                    &nbsp;|&nbsp; <strong>Status:</strong> <?php echo htmlspecialchars($est['status']); ?>
                </div>
            </td>
        </tr>
    </table>
    <?php elseif ($logoPos === 'right'): ?>
    <table style="width:100%;border-collapse:collapse;margin-bottom:20px;border-bottom:2px solid #eee;padding-bottom:16px;">
        <tr>
            <td style="width:48%;vertical-align:top;text-align:left;">
                <div class="document-title">ESTIMATION</div>
                <div class="document-number"># <?php echo htmlspecialchars($est['estimation_number']); ?></div>
                <div style="font-size:11px;margin-top:8px;">
                    <strong>Date:</strong> <?php echo date('F j, Y', strtotime($est['created_at'])); ?><br>
                    <strong>Status:</strong> <?php echo htmlspecialchars($est['status']); ?>
                </div>
            </td>
            <td style="width:52%;vertical-align:top;text-align:right;">
                <?php if ($showLogoBlock): ?>
                    <img src="<?php echo htmlspecialchars($logoSrc); ?>" alt="" class="logo" style="margin-left:auto;display:block;">
                <?php endif; ?>
                <div class="document-title" style="font-size:18px;margin-top:6px;"><?php echo htmlspecialchars($settings['business_name']); ?></div>
                <div style="margin-top:8px;font-size:10px;color:#555;text-align:right;"><?php echo nl2br(htmlspecialchars($settings['business_address'])); ?></div>
            </td>
        </tr>
    </table>
    <?php else: ?>
    <table style="width:100%;border-collapse:collapse;margin-bottom:20px;border-bottom:2px solid #eee;padding-bottom:16px;">
        <tr>
            <td style="width:55%;vertical-align:top;text-align:left;">
                <?php if ($showLogoBlock): ?>
                    <img src="<?php echo htmlspecialchars($logoSrc); ?>" alt="" class="logo">
                <?php else: ?>
                    <div class="document-title" style="font-size:18px;"><?php echo htmlspecialchars($settings['business_name']); ?></div>
                <?php endif; ?>
                <div style="margin-top:10px;font-size:11px;color:#555;">
                    <?php echo nl2br(htmlspecialchars($settings['business_address'])); ?><br>
                    <?php if (!empty($settings['business_phone'])): ?>Phone: <?php echo htmlspecialchars($settings['business_phone']); ?><br><?php endif; ?>
                    <?php if (!empty($settings['business_email'])): ?>Email: <?php echo htmlspecialchars($settings['business_email']); ?><?php endif; ?>
                </div>
            </td>
            <td style="width:45%;vertical-align:top;text-align:right;">
                <div class="document-title">ESTIMATION</div>
                <div class="document-number"># <?php echo htmlspecialchars($est['estimation_number']); ?></div>
                <div style="font-size:11px;margin-top:8px;">
                    <strong>Date:</strong> <?php echo date('F j, Y', strtotime($est['created_at'])); ?><br>
                    <strong>Status:</strong> <?php echo htmlspecialchars($est['status']); ?>
                </div>
            </td>
        </tr>
    </table>
    <?php endif; ?>

    <!-- Client Info -->
    <?php if (!empty($layout['show_notes'])): ?>
    <div class="info-section">
        <div class="info-box">
            <div class="info-title">Estimation For</div>
            <div><strong>
                    <?php echo htmlspecialchars($est['customer_name']); ?>
                </strong></div>
            <?php if (!empty($est['customer_email'])): ?>
                <div>Email:
                    <?php echo htmlspecialchars($est['customer_email']); ?>
                </div>
            <?php endif; ?>
            <?php if (!empty($est['customer_phone'])): ?>
                <div>Phone:
                    <?php echo htmlspecialchars($est['customer_phone']); ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="info-box">
            <div class="info-title">Job Description</div>
            <?php if (!empty($est['job_description'])): ?>
                <div style="font-size: 0.95em; margin-top: 5px;">
                    <?php echo nl2br(htmlspecialchars((string) $est['job_description'])); ?>
                </div>
            <?php else: ?>
                <div style="font-size: 0.95em; color: #666; font-style: italic;">No job description recorded.</div>
            <?php endif; ?>
        </div>
    </div>
    <?php else: ?>
    <div class="info-section">
        <div class="info-box" style="width:48%;">
            <div class="info-title">Estimation For</div>
            <div><strong>
                    <?php echo htmlspecialchars($est['customer_name']); ?>
                </strong></div>
            <?php if (!empty($est['customer_email'])): ?>
                <div>Email:
                    <?php echo htmlspecialchars($est['customer_email']); ?>
                </div>
            <?php endif; ?>
            <?php if (!empty($est['customer_phone'])): ?>
                <div>Phone:
                    <?php echo htmlspecialchars($est['customer_phone']); ?>
                </div>
            <?php endif; ?>
        </div>
        <div class="info-box" style="width:48%;">
            <div class="info-title">Job Description</div>
            <?php if (!empty($est['job_description'])): ?>
                <div style="font-size: 0.95em; margin-top: 5px;">
                    <?php echo nl2br(htmlspecialchars((string) $est['job_description'])); ?>
                </div>
            <?php else: ?>
                <div style="font-size: 0.95em; color: #666; font-style: italic;">No job description recorded.</div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Standard materials -->
    <?php if (!empty($standard_materials)): ?>
        <?php $renderSectionHeading('Standard materials', (float) ($subtotals['standard_materials'] ?? 0)); ?>
        <table class="detail-table">
            <thead>
                <tr>
                    <th>Material</th>
                    <th class="text-right">Qty</th>
                    <th class="text-right">Rate / unit</th>
                    <th class="text-right">Total</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($standard_materials as $row): ?>
                    <tr>
                        <td><?php echo htmlspecialchars((string) ($row['description'] ?? '—')); ?></td>
                        <td class="text-right"><?php echo $formatQty($row['quantity'] ?? null); ?></td>
                        <td class="text-right"><?php echo $formatQty($row['unit_price'] ?? null); ?></td>
                        <td class="text-right"><?php echo $formatMoney($row['total_price'] ?? 0); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <!-- Line items -->
    <?php if (!empty($other_items)): ?>
        <?php $renderSectionHeading('Line items', (float) ($subtotals['other_items'] ?? 0)); ?>
        <table class="detail-table">
            <thead>
                <tr>
                    <th>Description</th>
                    <th>Type</th>
                    <th class="text-right">Qty</th>
                    <th class="text-right">Unit price</th>
                    <th class="text-right">Total</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($other_items as $row): ?>
                    <tr>
                        <td><?php echo nl2br(htmlspecialchars((string) ($row['description'] ?? '—'))); ?></td>
                        <td><?php echo htmlspecialchars((string) ($row['item_type'] ?? '—')); ?></td>
                        <td class="text-right"><?php echo $formatQty($row['quantity'] ?? null); ?></td>
                        <td class="text-right"><?php echo $formatQty($row['unit_price'] ?? null); ?></td>
                        <td class="text-right"><?php echo $formatMoney($row['total_price'] ?? 0); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <?php if (!empty($papers)): ?>
        <?php $renderSectionHeading('Paper', (float) ($subtotals['papers'] ?? 0)); ?>
        <table class="detail-table">
            <thead>
                <tr>
                    <th>Catalog Material</th>
                    <th>Type</th>
                    <th>Size</th>
                    <th class="text-right">Grammage</th>
                    <th>Colour</th>
                    <th class="text-right">Sheets</th>
                    <th class="text-right">Rate</th>
                    <th class="text-right">Total</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($papers as $row): ?>
                    <tr>
                        <td><?php echo htmlspecialchars((string) ($row['material_name'] ?? ($row['material_id'] ? 'Linked #' . (int) $row['material_id'] : '—'))); ?></td>
                        <td><?php echo htmlspecialchars((string) ($row['paper_type'] ?? '—')); ?></td>
                        <td><?php echo htmlspecialchars((string) ($row['paper_size'] ?? '—')); ?></td>
                        <td class="text-right"><?php echo $formatQty($row['paper_grammage'] ?? 0); ?></td>
                        <td><?php echo htmlspecialchars((string) ($row['paper_color'] ?? '—')); ?></td>
                        <td class="text-right"><?php echo $formatQty($row['paper_sheets'] ?? 0); ?></td>
                        <td class="text-right"><?php echo $formatQty($row['paper_rate'] ?? 0); ?></td>
                        <td class="text-right"><?php echo $formatMoney($row['paper_total'] ?? 0); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <?php if (!empty($inkRows)): ?>
        <?php $renderSectionHeading('Ink colours', (float) ($subtotals['ink'] ?? 0)); ?>
        <table class="detail-table">
            <thead>
                <tr>
                    <th>Colour</th>
                    <th class="text-right">Kgs</th>
                    <th class="text-right">Rate / kg</th>
                    <th class="text-right">Total</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($inkRows as $row): ?>
                    <tr>
                        <td><?php echo htmlspecialchars((string) ($row['colour_name'] ?? '—')); ?></td>
                        <td class="text-right"><?php echo $formatQty($row['kgs'] ?? 0, 4); ?></td>
                        <td class="text-right"><?php echo $formatQty($row['rate'] ?? 0); ?></td>
                        <td class="text-right"><?php echo $formatMoney($row['total'] ?? 0); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <?php if (!empty($binding)): ?>
        <?php $renderSectionHeading('Binding materials', (float) ($subtotals['binding'] ?? 0)); ?>
        <table class="detail-table">
            <thead>
                <tr>
                    <th>Material</th>
                    <th>Unit</th>
                    <th class="text-right">Quantity</th>
                    <th class="text-right">Rate</th>
                    <th class="text-right">Total</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($binding as $row): ?>
                    <tr>
                        <td><?php echo htmlspecialchars((string) ($row['material_name'] ?? '—')); ?></td>
                        <td><?php echo htmlspecialchars((string) ($row['unit'] ?? '—')); ?></td>
                        <td class="text-right"><?php echo $formatQty($row['quantity'] ?? 0); ?></td>
                        <td class="text-right"><?php echo $formatQty($row['rate'] ?? 0); ?></td>
                        <td class="text-right"><?php echo $formatMoney($row['total'] ?? 0); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <?php if (!empty($prepress)): ?>
        <?php $renderSectionHeading('Pre-press labour', (float) ($subtotals['prepress'] ?? 0)); ?>
        <table class="detail-table">
            <thead>
                <tr>
                    <th>Labour</th>
                    <th>Unit</th>
                    <th class="text-right">Hours</th>
                    <th class="text-right">Rate / hr</th>
                    <th class="text-right">Total</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($prepress as $row): ?>
                    <tr>
                        <td><?php echo htmlspecialchars((string) ($row['labour_name'] ?? '—')); ?></td>
                        <td><?php echo htmlspecialchars((string) ($row['unit'] ?? 'hrs')); ?></td>
                        <td class="text-right"><?php echo $formatQty($row['hrs'] ?? 0); ?></td>
                        <td class="text-right"><?php echo $formatQty($row['rate'] ?? 0); ?></td>
                        <td class="text-right"><?php echo $formatMoney($row['total'] ?? 0); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <?php if (!empty($press)): ?>
        <?php $renderSectionHeading('Press labour', (float) ($subtotals['press'] ?? 0)); ?>
        <table class="detail-table">
            <thead>
                <tr>
                    <th>Machine</th>
                    <th class="text-right">Colours</th>
                    <th class="text-right">Make-ready hrs</th>
                    <th class="text-right">Make-ready rate</th>
                    <th class="text-right">Make-ready total</th>
                    <th class="text-right">Run hrs</th>
                    <th class="text-right">Run rate</th>
                    <th class="text-right">Run total</th>
                    <th class="text-right">Impressions</th>
                    <th class="text-right">IPH</th>
                    <th class="text-right">Machine total</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($press as $row): ?>
                    <tr>
                        <td><?php echo htmlspecialchars((string) ($row['machine_name'] ?? '—')); ?></td>
                        <td class="text-right"><?php echo (int) ($row['colours'] ?? 0); ?></td>
                        <td class="text-right"><?php echo $formatQty($row['make_ready_hrs'] ?? 0); ?></td>
                        <td class="text-right"><?php echo $formatQty($row['make_ready_rate'] ?? 0); ?></td>
                        <td class="text-right"><?php echo $formatMoney($row['make_ready_total'] ?? 0); ?></td>
                        <td class="text-right"><?php echo $formatQty($row['running_hrs'] ?? 0); ?></td>
                        <td class="text-right"><?php echo $formatQty($row['running_rate'] ?? 0); ?></td>
                        <td class="text-right"><?php echo $formatMoney($row['running_total'] ?? 0); ?></td>
                        <td class="text-right"><?php echo (int) ($row['impressions'] ?? 0); ?></td>
                        <td class="text-right"><?php echo (int) ($row['iph'] ?? 0); ?></td>
                        <td class="text-right"><?php echo $formatMoney($row['machine_total'] ?? 0); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <?php if (!empty($finishing)): ?>
        <?php $renderSectionHeading('Finishing labour', (float) ($subtotals['finishing'] ?? 0)); ?>
        <table class="detail-table">
            <thead>
                <tr>
                    <th>Labour</th>
                    <th>Measure</th>
                    <th class="text-right">Quantity / Hours</th>
                    <th class="text-right">Rate</th>
                    <th class="text-right">Total</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($finishing as $row): ?>
                    <tr>
                        <td><?php echo htmlspecialchars((string) ($row['labour_name'] ?? '—')); ?></td>
                        <td><?php echo htmlspecialchars((string) ($row['measure_type'] ?? '—')); ?></td>
                        <td class="text-right"><?php echo $formatQty($row['quantity'] ?? 0); ?></td>
                        <td class="text-right"><?php echo $formatQty($row['rate'] ?? 0); ?></td>
                        <td class="text-right"><?php echo $formatMoney($row['total'] ?? 0); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <?php if (!empty($consumables)): ?>
        <?php $renderSectionHeading('Printing consumables', (float) ($subtotals['consumables'] ?? 0)); ?>
        <table class="detail-table">
            <thead>
                <tr>
                    <th>Material</th>
                    <th>Unit</th>
                    <th class="text-right">Qty</th>
                    <th class="text-right">Rate</th>
                    <th class="text-right">Total</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($consumables as $row):
                    $details = estimation_decode_item_details($row['details_json'] ?? null);
                    $unit = trim((string) ($details['unit'] ?? ''));
                    ?>
                    <tr>
                        <td><?php echo htmlspecialchars((string) ($row['description'] ?? '—')); ?></td>
                        <td><?php echo htmlspecialchars($unit !== '' ? $unit : '—'); ?></td>
                        <td class="text-right"><?php echo $formatQty($row['quantity'] ?? 0); ?></td>
                        <td class="text-right"><?php echo $formatQty($row['unit_price'] ?? 0); ?></td>
                        <td class="text-right"><?php echo $formatMoney($row['total_price'] ?? 0); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <?php if (empty($standard_materials) && empty($other_items) && empty($papers) && empty($inkRows) && empty($binding) && empty($prepress) && empty($press) && empty($finishing) && empty($consumables)): ?>
        <p class="text-center" style="color:#666;font-style:italic;">No line items or cost breakdown recorded.</p>
    <?php endif; ?>



    <!-- Totals -->
    <div class="section-heading" style="margin-top:28px;">
        <span class="section-title">Totals</span>
    </div>

    <table class="summary-grid">
        <tbody>
            <?php
            $totalsRows = [
                'Standard materials' => (float) ($subtotals['standard_materials'] ?? 0),
                'Line items' => (float) ($subtotals['other_items'] ?? 0),
                'Paper' => (float) ($subtotals['papers'] ?? 0),
                'Ink' => (float) ($subtotals['ink'] ?? 0),
                'Binding materials' => (float) ($subtotals['binding'] ?? 0),
                'Pre-press labour' => (float) ($subtotals['prepress'] ?? 0),
                'Press labour' => (float) ($subtotals['press'] ?? 0),
                'Finishing labour' => (float) ($subtotals['finishing'] ?? 0),
                'Printing consumables' => (float) ($subtotals['consumables'] ?? ($est['cost_consumables_amount'] ?? 0)),
            ];
            foreach ($totalsRows as $label => $value):
                if ($value <= 0) {
                    continue;
                }
                ?>
                <tr>
                    <td><?php echo htmlspecialchars($label); ?></td>
                    <td>MK <?php echo $formatMoney($value); ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <?php if (isset($est['subtotal_amount']) || isset($est['vat_percent'])): ?>
        <div class="buildup-box">
            <div class="buildup-title">Cost build-up</div>
            <table class="summary-grid" style="margin:0;">
                <tbody>
                    <tr>
                        <td>Cost subtotal</td>
                        <td>MK <?php echo $formatMoney($est['subtotal_amount'] ?? 0); ?></td>
                    </tr>
                    <?php if ((float) ($est['cost_supervision_amount'] ?? 0) > 0): ?>
                        <tr>
                            <td>Overtime / supervision</td>
                            <td>MK <?php echo $formatMoney($est['cost_supervision_amount']); ?></td>
                        </tr>
                    <?php endif; ?>
                    <?php if ((float) ($est['profit_margin_percent'] ?? 0) > 0): ?>
                        <tr>
                            <td>Profit margin</td>
                            <td><?php echo $formatMoney($est['profit_margin_percent']); ?>%</td>
                        </tr>
                        <tr>
                            <td>Profit amount</td>
                            <td>MK <?php echo $formatMoney($est['profit_amount'] ?? 0); ?></td>
                        </tr>
                    <?php endif; ?>
                    <tr>
                        <td><strong>Pre-VAT total</strong></td>
                        <td><strong>MK <?php echo $formatMoney($est['pre_vat_total'] ?? 0); ?></strong></td>
                    </tr>
                    <tr>
                        <td>VAT rate</td>
                        <td><?php echo $formatMoney($est['vat_percent'] ?? 0); ?>%</td>
                    </tr>
                    <tr>
                        <td>VAT amount</td>
                        <td>MK <?php echo $formatMoney($est['vat_amount'] ?? 0); ?></td>
                    </tr>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <div class="grand-total-box">
        <span>Grand Total</span>
        <span>MK <?php echo $formatMoney($est['total_amount'] ?? 0); ?></span>
    </div>

    <!-- Disclaimer/Terms -->
    <?php if (!empty($layout['show_terms'])): ?>
    <div style="margin-top: 30px; padding: 15px; background-color: #f9f9f9; border-radius: 5px;">
        <div style="font-weight: bold; margin-bottom: 5px;">Note</div>
        <div style="font-size: 11px; color: #666;">
            <?php echo nl2br(htmlspecialchars($settings['estimation_disclaimer'])); ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Signatures -->
    <?php if (!empty($layout['show_signatures'])): ?>
    <div class="signatures">
        <div class="signature-box">
            <div class="signature-line"></div>
            <div class="signature-label">
                <?php echo htmlspecialchars($settings['signature1_title']); ?>
            </div>
        </div>

        <div class="signature-box">
            <div class="signature-line"></div>
            <div class="signature-label">
                <?php echo htmlspecialchars($settings['signature2_title']); ?>
            </div>
        </div>

        <div class="signature-box">
            <div class="signature-line"></div>
            <div class="signature-label">
                <?php echo htmlspecialchars($settings['signature3_title']); ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Footer -->
    <?php if (!empty($layout['show_footer'])): ?>
    <div class="footer">
        <p>
            <?php echo htmlspecialchars($settings['business_name']); ?> |
            <?php
            $addressLines = explode("\n", $settings['business_address']);
            echo htmlspecialchars(trim($addressLines[0]));
            ?>
        </p>
    </div>
    <?php endif; ?>

    <!-- Print Button (hidden when printing) -->
    <div class="no-print" style="margin-top: 30px; text-align: center; margin-bottom: 30px;">
        <button onclick="window.print()"
            style="padding: 10px 24px; background-color: #2c3e50; color: white; border: none; border-radius: 4px; cursor: pointer; font-size: 16px; font-weight: bold; box-shadow: 0 2px 4px rgba(0,0,0,0.2);">
            <i class="material-icons" style="vertical-align: middle; margin-right: 5px;">print</i> Print Estimation
        </button>
        <button onclick="window.close()"
            style="padding: 10px 24px; background-color: #95a5a6; color: white; border: none; border-radius: 4px; cursor: pointer; font-size: 16px; margin-left: 10px; box-shadow: 0 2px 4px rgba(0,0,0,0.2);">
            Close
        </button>
    </div>
</body>

</html>