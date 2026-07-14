<?php
/**
 * Sales receipt PDF (Dompdf). Variables: $invoice, $payments (non-empty array), $settings.
 */

date_default_timezone_set('UTC');

$invoice = is_array($invoice ?? null) ? $invoice : [];
$payments = is_array($payments ?? null) ? $payments : [];

require_once __DIR__ . '/../includes/settings_helper.php';
require_once __DIR__ . '/../includes/billing_layout_helper.php';
require_once __DIR__ . '/../includes/pdf_helper.php';

$settings = function_exists('get_business_pdf_settings') ? get_business_pdf_settings() : [];
$layout = get_merged_billing_layout('receipt', isset($billing_layout_variant_override) ? $billing_layout_variant_override : null);

$defaults = [
    'business_logo' => '',
    'business_name' => 'Your Company',
    'business_address' => '',
    'business_phone' => '',
    'business_email' => '',
    'business_website' => '',
    'invoice_footer' => '',
    'currency_symbol' => 'MK',
];
foreach ($defaults as $k => $v) {
    if (empty($settings[$k])) {
        $settings[$k] = $v;
    }
}

$logoSrc = '';
if (!empty($settings['business_logo']) && $layout['logo_position'] !== 'hidden') {
    $resolved = resolve_pdf_embed_image_src((string) $settings['business_logo']);
    if ($resolved !== null) {
        $logoSrc = $resolved;
    }
}

$currency = (string) $settings['currency_symbol'];
$money = function ($n) use ($currency) {
    return $currency . ' ' . number_format((float) $n, 2);
};

$invNo = (string) ($invoice['invoice_number'] ?? '—');
$customer = (string) ($invoice['customer_name'] ?? '');
$estRef = (string) ($invoice['estimation_number'] ?? '');
$total = (float) ($invoice['total_amount'] ?? 0);
$paid = (float) ($invoice['paid_amount'] ?? 0);
$balance = isset($invoice['balance']) ? (float) $invoice['balance'] : max(0, $total - $paid);
$receiptTitle = count($payments) === 1 ? 'PAYMENT RECEIPT' : 'PAYMENT RECEIPT SUMMARY';

$hdr = (string) ($layout['header_style'] ?? 'band');
$logoPos = (string) ($layout['logo_position'] ?? 'left');
$showLogo = $logoSrc !== '' && $logoPos !== 'hidden';

$sumThisReceipt = 0.0;
foreach ($payments as $p) {
    $sumThisReceipt += (float) ($p['amount'] ?? 0);
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Receipt <?php echo htmlspecialchars($invNo); ?></title>
    <style>
        body { font-family: 'DejaVu Sans', Arial, sans-serif; font-size: 11px; color: #111; margin: 0; padding: 24px 40px; }
        table.meta { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        .doc-title { font-size: 22px; font-weight: bold; letter-spacing: 1px; color: #1a1a1a; }
        .muted { color: #666; font-size: 10px; }
        table.lines { width: 100%; border-collapse: collapse; margin-top: 16px; }
        table.lines th { background: #1a1a1a; color: #fff; text-align: left; padding: 8px; font-size: 10px; }
        table.lines td { padding: 8px; border-bottom: 1px solid #e5e5e5; }
        .right { text-align: right; }
        .tot { margin-top: 18px; width: 100%; max-width: 320px; margin-left: auto; }
        .tot td { padding: 4px 0; }
        .bank { margin-top: 20px; padding: 12px; border: 1px solid #ddd; background: #fafafa; font-size: 10px; }
        .sig { width: 100%; margin-top: 28px; border-collapse: collapse; }
        .sig td { width: 33%; text-align: center; vertical-align: top; padding: 0 6px; }
        .sig .ln { border-top: 1px solid #333; margin: 32px 0 6px 0; }
        .foot { margin-top: 28px; text-align: center; font-size: 9px; color: #888; border-top: 1px solid #eee; padding-top: 12px; }
        .notes { margin-top: 14px; padding: 10px; border: 1px solid #e2e8f0; background: #fff; font-size: 10px; }
    </style>
</head>
<body>

<?php if ($hdr === 'band'): ?>
<div style="background:#1a1a1a;height:10px;margin:-24px -40px 16px -40px;">&nbsp;</div>
<?php endif; ?>

<table class="meta">
    <tr>
        <?php if ($logoPos === 'right'): ?>
            <td style="width:55%;vertical-align:top;">
                <div class="doc-title"><?php echo htmlspecialchars($receiptTitle); ?></div>
                <div class="muted" style="margin-top:6px;">Invoice <?php echo htmlspecialchars($invNo); ?></div>
                <?php if ($estRef !== ''): ?><div class="muted">Reference: <?php echo htmlspecialchars($estRef); ?></div><?php endif; ?>
            </td>
            <td style="width:45%;vertical-align:top;text-align:right;">
                <?php if ($showLogo): ?>
                    <img src="<?php echo htmlspecialchars($logoSrc); ?>" style="max-height:56px;" alt="">
                    <div style="height:8px;"></div>
                <?php endif; ?>
                <strong><?php echo htmlspecialchars($settings['business_name']); ?></strong>
                <?php if (!empty($settings['business_address'])): ?>
                    <div class="muted" style="margin-top:4px;"><?php echo nl2br(htmlspecialchars($settings['business_address'])); ?></div>
                <?php endif; ?>
            </td>
        <?php elseif ($logoPos === 'center'): ?>
            <td style="text-align:center;vertical-align:top;">
                <?php if ($showLogo): ?>
                    <img src="<?php echo htmlspecialchars($logoSrc); ?>" style="max-height:64px;margin-bottom:8px;" alt="">
                <?php endif; ?>
                <div><strong><?php echo htmlspecialchars($settings['business_name']); ?></strong></div>
                <div class="doc-title" style="margin-top:12px;"><?php echo htmlspecialchars($receiptTitle); ?></div>
                <div class="muted">Invoice <?php echo htmlspecialchars($invNo); ?></div>
            </td>
        <?php else: ?>
            <td style="width:58%;vertical-align:top;">
                <?php if ($showLogo): ?>
                    <table style="border-collapse:collapse;"><tr>
                        <td style="padding-right:10px;vertical-align:middle;"><img src="<?php echo htmlspecialchars($logoSrc); ?>" style="max-height:52px;" alt=""></td>
                        <td style="vertical-align:middle;">
                            <strong style="font-size:14px;"><?php echo htmlspecialchars($settings['business_name']); ?></strong>
                            <?php if (!empty($settings['business_address'])): ?>
                                <div class="muted" style="margin-top:4px;"><?php echo nl2br(htmlspecialchars($settings['business_address'])); ?></div>
                            <?php endif; ?>
                        </td>
                    </tr></table>
                <?php else: ?>
                    <strong style="font-size:14px;"><?php echo htmlspecialchars($settings['business_name']); ?></strong>
                <?php endif; ?>
            </td>
            <td style="width:42%;vertical-align:top;text-align:right;">
                <div class="doc-title"><?php echo htmlspecialchars($receiptTitle); ?></div>
                <div class="muted" style="margin-top:6px;">Invoice <?php echo htmlspecialchars($invNo); ?></div>
                <?php if ($estRef !== ''): ?><div class="muted">Reference: <?php echo htmlspecialchars($estRef); ?></div><?php endif; ?>
            </td>
        <?php endif; ?>
    </tr>
</table>

<div class="muted" style="margin-bottom:8px;"><strong>Received from:</strong> <?php echo htmlspecialchars($customer !== '' ? $customer : '—'); ?></div>

<?php
$jobNotes = trim((string) ($invoice['estimation_job_description'] ?? ''));
if (!empty($layout['show_notes']) && $jobNotes !== ''): ?>
    <div class="notes"><strong>Job notes</strong><div style="margin-top:4px;"><?php echo nl2br(htmlspecialchars($jobNotes)); ?></div></div>
<?php endif; ?>

<table class="lines">
    <thead>
        <tr>
            <th>GR Number</th>
            <th>Date</th>
            <th>Method</th>
            <th>Reference</th>
            <th class="right">Amount</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($payments as $p): ?>
            <tr>
                <td class="font-mono"><strong><?php echo htmlspecialchars((string) ($p['gr_number'] ?? '') ?: '—'); ?></strong></td>
                <td><?php echo htmlspecialchars((string) ($p['payment_date'] ?? '')); ?></td>
                <td><?php echo htmlspecialchars((string) ($p['payment_method'] ?? '')); ?></td>
                <td><?php echo htmlspecialchars((string) ($p['transaction_id'] ?? '') ?: '—'); ?></td>
                <td class="right"><?php echo $money((float) ($p['amount'] ?? 0)); ?></td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>

<table class="tot">
    <?php if (!empty($layout['show_tax_breakdown'])): ?>
    <tr><td class="muted">Invoice total</td><td class="right"><?php echo $money($total); ?></td></tr>
    <tr><td class="muted">Total paid (invoice)</td><td class="right"><?php echo $money($paid); ?></td></tr>
    <tr><td class="muted">Outstanding</td><td class="right"><?php echo $money(max(0, $balance)); ?></td></tr>
    <?php endif; ?>
    <tr><td><strong>Amount this document</strong></td><td class="right"><strong><?php echo $money($sumThisReceipt); ?></strong></td></tr>
</table>

<?php if (!empty($layout['show_payment_details'])): ?>
<div class="bank">
    <strong>Payment information</strong>
    <?php if (!empty($settings['bank_name'])): ?><div><strong>Bank:</strong> <?php echo htmlspecialchars($settings['bank_name']); ?></div><?php endif; ?>
    <?php if (!empty($settings['account_number'])): ?><div><strong>Account:</strong> <?php echo htmlspecialchars($settings['account_number']); ?></div><?php endif; ?>
    <?php if (!empty($settings['iban'])): ?><div><strong>IBAN:</strong> <?php echo htmlspecialchars($settings['iban']); ?></div><?php endif; ?>
</div>
<?php endif; ?>

<?php if (!empty($layout['show_terms']) && !empty($settings['invoice_terms'])): ?>
    <div style="margin-top:16px;font-size:9.5px;color:#555;"><?php echo nl2br(htmlspecialchars($settings['invoice_terms'])); ?></div>
<?php endif; ?>

<?php if (!empty($layout['show_signatures'])): ?>
<table class="sig">
    <tr>
        <td><div class="ln"></div><div class="muted"><?php echo htmlspecialchars((string) ($settings['signature1_title'] ?? '')); ?></div></td>
        <td><div class="ln"></div><div class="muted"><?php echo htmlspecialchars((string) ($settings['signature2_title'] ?? '')); ?></div></td>
        <td><div class="ln"></div><div class="muted"><?php echo htmlspecialchars((string) ($settings['signature3_title'] ?? '')); ?></div></td>
    </tr>
</table>
<?php endif; ?>

<?php if (!empty($layout['show_footer'])): ?>
<div class="foot">
    <?php echo htmlspecialchars($settings['business_name']); ?>
    <?php if (!empty($settings['business_website'])): ?> · <?php echo htmlspecialchars($settings['business_website']); ?><?php endif; ?>
    <?php if (!empty($settings['invoice_footer'])): ?> · <?php echo htmlspecialchars($settings['invoice_footer']); ?><?php endif; ?>
</div>
<?php endif; ?>

</body>
</html>
