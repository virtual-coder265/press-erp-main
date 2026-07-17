<?php
require_once __DIR__ . '/../../config/app.php';
checkAuth();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/permissions_helper.php';
require_once __DIR__ . '/../../includes/reports_helper.php';
require_once __DIR__ . '/../../libs/InvoiceAuditMigrator.php';

if (!reports_can_access('invoices')) {
    http_response_code(403);
    die('Access Denied.');
}

InvoiceAuditMigrator::ensure($pdo);

$reportKey = 'invoices';
$filters = reports_read_filters();
$kpis = reports_fetch_kpis($pdo, $reportKey, $filters);
$rows = reports_fetch_rows($pdo, $reportKey, $filters);
$statusBreakdown = reports_fetch_invoice_status_breakdown($pdo, $filters);
$columns = reports_get_columns($reportKey);

$filterConfig = [
    'show_search' => true,
    'status_options' => ['Unpaid', 'Partially Paid', 'Paid', 'Overdue', 'Cancelled'],
];

include '../../includes/header.php';
?>

<div class="list-toolbar mb-6">
    <div class="min-w-0">
        <p class="text-sm text-gray-500"><a href="index" class="text-indigo-600 hover:underline">Reports</a> / Invoice Reports</p>
        <h1 class="text-3xl font-bold text-gray-800 break-words">Invoice Reports</h1>
        <p class="text-sm text-gray-500 mt-1">Invoice volumes, balances, and payment status for the selected period.</p>
    </div>
    <div class="list-toolbar-actions">
        <?php include __DIR__ . '/partials/_export_menu.php'; ?>
    </div>
</div>

<?php include __DIR__ . '/partials/_filters.php'; ?>
<?php $kpis = $kpis; include __DIR__ . '/partials/_kpi_grid.php'; ?>

<div class="grid grid-cols-1 xl:grid-cols-3 gap-6 mb-6">
    <div class="bg-white shadow rounded-xl p-6 xl:col-span-1">
        <h2 class="text-lg font-bold text-gray-800 mb-4">Status Breakdown</h2>
        <div class="space-y-3">
            <?php foreach ($statusBreakdown as $item): ?>
                <div class="flex items-center justify-between gap-3 text-sm">
                    <span class="text-gray-700"><?php echo htmlspecialchars($item['status']); ?></span>
                    <span class="font-semibold text-gray-900"><?php echo (int) $item['total']; ?> <span class="text-gray-400 font-normal">(MK <?php echo reports_format_money($item['amount']); ?>)</span></span>
                </div>
            <?php endforeach; ?>
            <?php if (empty($statusBreakdown)): ?>
                <p class="text-gray-500 text-sm">No invoice data for this period.</p>
            <?php endif; ?>
        </div>
    </div>
    <div class="xl:col-span-2">
        <?php include __DIR__ . '/partials/_report_table.php'; ?>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>
