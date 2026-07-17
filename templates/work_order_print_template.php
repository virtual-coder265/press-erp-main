<?php
/**
 * Work Order Print Template
 *
 * @param array $workOrder
 * @param array $productionForm
 * @param array $formeDressing
 * @param array $trimMargins
 * @param string $printSection
 * @param string $sectionTitle
 */

date_default_timezone_set('UTC');

require_once __DIR__ . '/../includes/settings_helper.php';
$settings = get_business_pdf_settings();
$settings['business_logo'] = $settings['business_logo'] ?? '';
$settings['business_name'] = $settings['business_name'] ?? 'Government Press';
$settings['business_address'] = $settings['business_address'] ?? "Government Press Road\nZomba, Malawi";
$settings['business_phone'] = $settings['business_phone'] ?? '';
$settings['business_email'] = $settings['business_email'] ?? '';

require_once __DIR__ . '/../includes/pdf_helper.php';
$logoSrc = '';
if (!empty($settings['business_logo'])) {
    $resolved = resolve_pdf_embed_image_src((string) $settings['business_logo']);
    if ($resolved !== null) {
        $logoSrc = $resolved;
    }
}

if (!function_exists('wo_print_value')) {
    function wo_print_value($value): string
    {
        $value = trim((string) $value);
        return $value !== '' ? htmlspecialchars($value) : '—';
    }
}

if (!function_exists('wo_print_show')) {
    function wo_print_show(string $section, string $current): bool
    {
        if ($current === 'full') {
            return true;
        }
        if ($current === 'job') {
            return in_array($section, ['job', 'costing'], true);
        }
        return $section === $current;
    }
}

$bindingLabel = $workOrder['binding_type_name'] ?? $workOrder['binding_catalog_name'] ?? '';
$composing = $productionForm['composing'] ?? [];
$letterpress = $productionForm['letterpress'] ?? [];
$bookbinding = $productionForm['bookbinding'] ?? [];
$paperMaterials = $productionForm['paper_materials'] ?? [];

$travelerTotalCost = (float) ($workOrder['invoice_total'] ?? $workOrder['total_cost_snapshot'] ?? 0);
$travelerAmountPaid = (float) ($workOrder['paid_amount'] ?? $workOrder['amount_paid_snapshot'] ?? 0);
$travelerBalance = (float) ($workOrder['balance'] ?? $workOrder['balance_snapshot'] ?? 0);

$sectionBlocks = [
    'composing' => [
        'title' => 'Composing / Photosetters',
        'fields' => [
            'Compositor' => $composing['compositor_name'] ?? '',
            'Date received' => $composing['date_received'] ?? '',
            'Type' => $composing['type'] ?? '',
            'Type area wide (ems)' => $composing['type_area_wide_ems'] ?? '',
            'Type area deep (ems)' => $composing['type_area_deep_ems'] ?? '',
            'Proof to / date' => $composing['proof_to_date'] ?? '',
            'Special instructions' => $composing['special_instructions'] ?? '',
        ],
    ],
    'letterpress' => [
        'title' => 'Letterpress / Offset',
        'fields' => [
            'Machine minder' => $letterpress['machine_minder_name'] ?? '',
            'Date received' => $letterpress['date_received'] ?? '',
            'Machine type' => $letterpress['machine_type'] ?? '',
            'Ink colour' => $letterpress['ink_colour'] ?? '',
            'Overs allowed' => $letterpress['overs_allowed'] ?? '',
            'Plate type' => $letterpress['plate_type'] ?? '',
            'Camera %' => $letterpress['camera_percent'] ?? '',
            'Process' => $letterpress['process'] ?? '',
            'Size' => $letterpress['size'] ?? '',
            'Special instructions' => $letterpress['special_instructions'] ?? '',
        ],
    ],
    'bookbinding' => [
        'title' => 'Bookbinding / Finishing',
        'fields' => [
            'Machine minder' => $bookbinding['machine_minder_name'] ?? '',
            'Date received' => $bookbinding['date_received'] ?? '',
            'Ruling' => $bookbinding['ruling'] ?? '',
            'Perforating' => $bookbinding['perforating'] ?? '',
            'Trim fore-edge' => $bookbinding['trim_fore_edge'] ?? '',
            'Trim back' => $bookbinding['trim_back'] ?? '',
            'Trim head' => $bookbinding['trim_head'] ?? '',
            'Trim tail' => $bookbinding['trim_tail'] ?? '',
            'Special instructions' => $bookbinding['special_instructions'] ?? '',
        ],
    ],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Work Order <?php echo wo_print_value($workOrder['work_order_number']); ?> — <?php echo htmlspecialchars($sectionTitle); ?></title>
    <style>
        body {
            font-family: 'DejaVu Sans', Arial, sans-serif;
            line-height: 1.5;
            color: #1f2937;
            font-size: 12px;
            margin: 0;
            padding: 24px;
            background: #fff;
        }
        .brand-band {
            background: #1a1a1a;
            height: 8px;
            margin: 0 0 18px 0;
        }
        .header-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
            border-bottom: 2px solid #e5e7eb;
            padding-bottom: 14px;
        }
        .document-title {
            font-size: 20px;
            font-weight: 700;
            letter-spacing: 0.04em;
            text-transform: uppercase;
            color: #111827;
        }
        .document-number {
            font-size: 16px;
            font-weight: 600;
            color: #374151;
            margin-top: 4px;
        }
        .meta-line {
            font-size: 11px;
            color: #6b7280;
            margin-top: 8px;
        }
        .logo {
            max-height: 64px;
        }
        .section-card {
            border: 1px solid #d1d5db;
            border-radius: 6px;
            margin-bottom: 16px;
            page-break-inside: avoid;
        }
        .section-head {
            background: #f3f4f6;
            border-bottom: 1px solid #d1d5db;
            padding: 10px 14px;
            font-size: 13px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.03em;
            color: #111827;
        }
        .section-body {
            padding: 14px;
        }
        .field-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 10px 18px;
        }
        .field-label {
            display: block;
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            color: #6b7280;
            margin-bottom: 2px;
        }
        .field-value {
            font-size: 12px;
            font-weight: 600;
            color: #111827;
        }
        .field-span-2 {
            grid-column: span 2;
        }
        .balance-box {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            padding: 12px;
        }
        .balance-row {
            display: flex;
            justify-content: space-between;
            padding: 4px 0;
        }
        .balance-row.total {
            border-top: 1px solid #cbd5e1;
            margin-top: 6px;
            padding-top: 8px;
            font-weight: 700;
        }
        .materials-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 11px;
        }
        .materials-table th,
        .materials-table td {
            border: 1px solid #d1d5db;
            padding: 6px 8px;
            text-align: left;
        }
        .materials-table th {
            background: #f9fafb;
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: 0.03em;
        }
        .job-description {
            white-space: pre-wrap;
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 4px;
            padding: 10px;
            margin-top: 6px;
        }
        .footer {
            margin-top: 28px;
            padding-top: 12px;
            border-top: 1px solid #e5e7eb;
            font-size: 10px;
            color: #6b7280;
            text-align: center;
        }
        .signature-row {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 24px;
            margin-top: 28px;
        }
        .signature-box {
            border-top: 1px solid #9ca3af;
            padding-top: 6px;
            font-size: 10px;
            color: #6b7280;
            text-align: center;
        }
        <?php if (!empty($pdfRender)): ?>
        .field-grid {
            width: 100%;
        }
        .field-grid > div {
            display: inline-block;
            width: 48%;
            vertical-align: top;
            margin-bottom: 8px;
        }
        .field-span-2 {
            display: block;
            width: 100% !important;
        }
        .balance-row {
            clear: both;
        }
        .signature-row {
            display: table;
            width: 100%;
        }
        .signature-box {
            display: table-cell;
            width: 33%;
        }
        <?php endif; ?>
        @media print {
            body { padding: 0; font-size: 11px; }
            .no-print { display: none !important; }
            .section-card { page-break-inside: avoid; }
            .balance-box { background: #fff; }
        }
    </style>
</head>
<body>
    <div class="brand-band"></div>

    <table class="header-table">
        <tr>
            <td style="width:58%; vertical-align:top;">
                <div class="document-title">Work Order Traveler</div>
                <div class="document-number"># <?php echo wo_print_value($workOrder['work_order_number']); ?></div>
                <div class="meta-line">
                    <strong>Section:</strong> <?php echo htmlspecialchars($sectionTitle); ?>
                    &nbsp;|&nbsp; <strong>Status:</strong> <?php echo wo_print_value($workOrder['status']); ?>
                    &nbsp;|&nbsp; <strong>Priority:</strong> <?php echo wo_print_value($workOrder['priority']); ?>
                </div>
                <div class="meta-line">
                    <strong>Invoice:</strong> <?php echo wo_print_value($workOrder['invoice_number']); ?>
                    <?php if (!empty($workOrder['estimation_number'])): ?>
                        &nbsp;|&nbsp; <strong>Estimation:</strong> <?php echo wo_print_value($workOrder['estimation_number']); ?>
                    <?php endif; ?>
                    &nbsp;|&nbsp; <strong>Printed:</strong> <?php echo date('d M Y H:i'); ?>
                </div>
            </td>
            <td style="width:42%; vertical-align:top; text-align:right;">
                <?php if ($logoSrc !== ''): ?>
                    <img src="<?php echo htmlspecialchars($logoSrc); ?>" alt="" class="logo">
                <?php endif; ?>
                <div class="document-title" style="font-size:16px; margin-top:8px;"><?php echo htmlspecialchars($settings['business_name']); ?></div>
                <div class="meta-line" style="text-align:right;"><?php echo nl2br(htmlspecialchars($settings['business_address'])); ?></div>
            </td>
        </tr>
    </table>

    <?php if (wo_print_show('job', $printSection)): ?>
    <div class="section-card">
        <div class="section-head">Job Header</div>
        <div class="section-body">
            <div class="field-grid">
                <div><span class="field-label">Customer</span><span class="field-value"><?php echo wo_print_value($workOrder['customer_name']); ?></span></div>
                <div><span class="field-label">Ministry / Department</span><span class="field-value"><?php echo wo_print_value($workOrder['ministry_department']); ?></span></div>
                <div><span class="field-label">Order Ref / LPO</span><span class="field-value"><?php echo wo_print_value($workOrder['order_ref_lpo']); ?></span></div>
                <div><span class="field-label">Binding</span><span class="field-value"><?php echo wo_print_value($bindingLabel); ?></span></div>
                <div><span class="field-label">Quantity</span><span class="field-value"><?php echo wo_print_value($workOrder['quantity']); ?></span></div>
                <div><span class="field-label">Pages</span><span class="field-value"><?php echo wo_print_value($workOrder['pages_count']); ?></span></div>
                <div><span class="field-label">Size deep</span><span class="field-value"><?php echo wo_print_value($workOrder['size_deep']); ?></span></div>
                <div><span class="field-label">Size wide</span><span class="field-value"><?php echo wo_print_value($workOrder['size_wide']); ?></span></div>
                <div><span class="field-label">Numbering</span><span class="field-value"><?php echo wo_print_value($workOrder['numbering_start']); ?></span></div>
                <div><span class="field-label">Previous WO</span><span class="field-value"><?php echo wo_print_value($workOrder['previous_work_order_number']); ?></span></div>
                <div><span class="field-label">Charge vote</span><span class="field-value"><?php echo wo_print_value($workOrder['charge_vote']); ?></span></div>
                <div><span class="field-label">Costed by</span><span class="field-value"><?php echo wo_print_value($workOrder['costed_by_name']); ?></span></div>
            </div>
            <?php if (!empty($workOrder['job_description'])): ?>
                <div class="field-span-2" style="margin-top:12px;">
                    <span class="field-label">Description of work</span>
                    <div class="job-description"><?php echo htmlspecialchars($workOrder['job_description']); ?></div>
                </div>
            <?php endif; ?>
            <?php if (!empty($workOrder['special_instructions'])): ?>
                <div class="field-span-2" style="margin-top:12px;">
                    <span class="field-label">Special instructions</span>
                    <div class="job-description"><?php echo htmlspecialchars($workOrder['special_instructions']); ?></div>
                </div>
            <?php endif; ?>
            <?php if (!empty($workOrder['delivery_instructions'])): ?>
                <div class="field-span-2" style="margin-top:12px;">
                    <span class="field-label">Delivery instructions</span>
                    <div class="job-description"><?php echo htmlspecialchars($workOrder['delivery_instructions']); ?></div>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php if (wo_print_show('costing', $printSection)): ?>
    <div class="section-card">
        <div class="section-head">Costing &amp; Balance</div>
        <div class="section-body">
            <div class="balance-box">
                <div class="balance-row"><span>Total cost of job</span><span>MK <?php echo number_format($travelerTotalCost, 2); ?></span></div>
                <div class="balance-row"><span>Amount paid</span><span>MK <?php echo number_format($travelerAmountPaid, 2); ?></span></div>
                <div class="balance-row total"><span>Balance</span><span>MK <?php echo number_format($travelerBalance, 2); ?></span></div>
            </div>
            <div class="field-grid" style="margin-top:12px;">
                <div><span class="field-label">Issued by</span><span class="field-value"><?php echo wo_print_value($workOrder['issued_by_name']); ?></span></div>
                <div><span class="field-label">Payment status</span><span class="field-value"><?php echo wo_print_value($workOrder['payment_status']); ?></span></div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if (wo_print_show('forme', $printSection)): ?>
    <div class="section-card">
        <div class="section-head">Forme Dressing &amp; Trim Margins</div>
        <div class="section-body">
            <div class="field-grid">
                <?php foreach (['backs' => 'Forme — Backs', 'heads' => 'Forme — Heads', 'gutters' => 'Forme — Gutters', 'tails' => 'Forme — Tails'] as $key => $label): ?>
                    <div><span class="field-label"><?php echo $label; ?></span><span class="field-value"><?php echo wo_print_value($formeDressing[$key] ?? ''); ?></span></div>
                <?php endforeach; ?>
                <?php foreach (['backs' => 'Trim — Backs', 'heads' => 'Trim — Heads', 'fore_edge' => 'Trim — Fore-edge', 'tails' => 'Trim — Tails'] as $key => $label): ?>
                    <div><span class="field-label"><?php echo $label; ?></span><span class="field-value"><?php echo wo_print_value($trimMargins[$key] ?? ''); ?></span></div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php foreach ($sectionBlocks as $slug => $block): ?>
        <?php if (!wo_print_show($slug, $printSection)) continue; ?>
        <div class="section-card">
            <div class="section-head"><?php echo htmlspecialchars($block['title']); ?></div>
            <div class="section-body">
                <div class="field-grid">
                    <?php foreach ($block['fields'] as $label => $value): ?>
                        <?php if (trim((string) $value) === '' && $printSection !== 'full') continue; ?>
                        <div class="<?php echo $label === 'Special instructions' ? 'field-span-2' : ''; ?>">
                            <span class="field-label"><?php echo htmlspecialchars($label); ?></span>
                            <span class="field-value" style="<?php echo $label === 'Special instructions' ? 'white-space:pre-wrap;font-weight:500;' : ''; ?>">
                                <?php echo wo_print_value($value); ?>
                            </span>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div class="signature-row">
                    <div class="signature-box">Received by / Date</div>
                    <div class="signature-box">Completed by / Date</div>
                    <div class="signature-box">Checked by / Date</div>
                </div>
            </div>
        </div>
    <?php endforeach; ?>

    <?php if (wo_print_show('materials', $printSection)): ?>
    <div class="section-card">
        <div class="section-head">Paper &amp; Materials</div>
        <div class="section-body">
            <?php if (empty($paperMaterials)): ?>
                <p style="color:#9ca3af; font-style:italic;">No materials logged yet.</p>
            <?php else: ?>
                <table class="materials-table">
                    <thead>
                        <tr>
                            <th>Ledger</th>
                            <th>Qty</th>
                            <th>Cut to</th>
                            <th>R.I.V.</th>
                            <th>Date</th>
                            <th>Notes</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($paperMaterials as $row): ?>
                            <tr>
                                <td><?php echo wo_print_value($row['ledger_no'] ?? ''); ?></td>
                                <td><?php echo wo_print_value($row['quantity'] ?? ''); ?></td>
                                <td><?php echo wo_print_value($row['cut_to'] ?? ''); ?></td>
                                <td><?php echo wo_print_value($row['riv_no'] ?? ''); ?></td>
                                <td><?php echo wo_print_value($row['date'] ?? ''); ?></td>
                                <td><?php echo wo_print_value($row['notes'] ?? ''); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <div class="footer">
        <?php echo htmlspecialchars($settings['business_name']); ?>
        <?php if (!empty($settings['business_phone'])): ?> | <?php echo htmlspecialchars($settings['business_phone']); ?><?php endif; ?>
        <?php if (!empty($settings['business_email'])): ?> | <?php echo htmlspecialchars($settings['business_email']); ?><?php endif; ?>
    </div>

    <?php if (empty($pdfRender)): ?>
    <div class="no-print" style="margin-top:24px; text-align:center;">
        <a href="<?php echo htmlspecialchars(work_order_pdf_href((int) ($workOrder['id'] ?? 0), $printSection, true)); ?>"
            style="display:inline-block;padding:10px 24px;background:#b91c1c;color:#fff;text-decoration:none;border-radius:4px;font-size:15px;font-weight:600;">
            Download PDF
        </a>
        <button onclick="window.print()" style="padding:10px 24px; background:#1e3a5f; color:#fff; border:none; border-radius:4px; cursor:pointer; font-size:15px; font-weight:600; margin-left:8px;">
            Print <?php echo htmlspecialchars($sectionTitle); ?>
        </button>
        <button onclick="window.close()" style="padding:10px 24px; background:#9ca3af; color:#fff; border:none; border-radius:4px; cursor:pointer; font-size:15px; margin-left:8px;">
            Close
        </button>
    </div>
    <?php endif; ?>
</body>
</html>
