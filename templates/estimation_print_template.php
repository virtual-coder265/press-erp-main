<?php
/**
 * Estimation Print Template
 * 
 * This template is used for printing estimations.
 * 
 * @param array $est The estimation data array
 * @param array $items The estimation items array
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
            <div class="info-title">Job Summary</div>
            <div><strong>Job Description:</strong></div>
            <div style="font-size: 0.9em; margin-top: 5px;">
                <?php
                $jd = (string) ($est['job_description'] ?? '');
                echo nl2br(htmlspecialchars(substr($jd, 0, 150) . (strlen($jd) > 150 ? '...' : '')));
                ?>
            </div>
        </div>
    </div>

    <?php if (strlen((string) ($est['job_description'] ?? '')) > 150): ?>
        <div class="job-description">
            <div class="info-title">Full Job Description</div>
            <div>
                <?php echo nl2br(htmlspecialchars((string) ($est['job_description'] ?? ''))); ?>
            </div>
        </div>
    <?php endif; ?>
    <?php else: ?>
    <div class="info-section">
        <div class="info-box" style="width:100%;">
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
    </div>
    <?php endif; ?>

    <!-- Items Table -->
    <table class="items-table">
        <thead>
            <tr>
                <th style="width: 5%;">#</th>
                <th style="width: 55%;">Description</th>
                <th style="width: 20%;">Type</th>
                <th style="width: 20%; text-align: right;">Amount</th>
            </tr>
        </thead>
        <tbody>
            <?php if (!empty($items)): ?>
                <?php foreach ($items as $index => $item): ?>
                    <tr>
                        <td>
                            <?php echo $index + 1; ?>
                        </td>
                        <td>
                            <?php echo nl2br(htmlspecialchars($item['description'] ?? '')); ?>
                        </td>
                        <td>
                            <?php echo htmlspecialchars($item['item_type'] ?? ''); ?>
                        </td>
                        <td class="text-right">
                            <?php echo number_format($item['total_price'], 2); ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr>
                    <td colspan="4" class="text-center">No items found</td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>

    <?php if (!empty($layout['show_payment_details'])): ?>
    <div style="margin: 20px 0; padding: 12px 14px; border: 1px solid #eee; background: #fafafa; font-size: 11px;">
        <div style="font-weight:bold;margin-bottom:6px;color:#2c3e50;">Payment details</div>
        <?php if (!empty($settings['bank_name'])): ?><div><strong>Bank:</strong> <?php echo htmlspecialchars($settings['bank_name']); ?></div><?php endif; ?>
        <?php if (!empty($settings['account_number'])): ?><div><strong>Account:</strong> <?php echo htmlspecialchars($settings['account_number']); ?></div><?php endif; ?>
        <?php if (!empty($settings['bank_branch'])): ?><div><strong>Branch:</strong> <?php echo htmlspecialchars($settings['bank_branch']); ?></div><?php endif; ?>
        <?php if (!empty($settings['swift_code'])): ?><div><strong>SWIFT:</strong> <?php echo htmlspecialchars($settings['swift_code']); ?></div><?php endif; ?>
        <?php if (!empty($settings['iban'])): ?><div><strong>IBAN:</strong> <?php echo htmlspecialchars($settings['iban']); ?></div><?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- Totals -->
    <div class="totals">
        <div class="totals-row total">
            <span>Total Estimated Amount:</span>
            <span>
                <?php echo number_format($est['total_amount'], 2); ?>
            </span>
        </div>
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