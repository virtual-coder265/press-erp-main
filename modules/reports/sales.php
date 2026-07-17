<?php
require_once __DIR__ . '/../../config/app.php';
checkAuth();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/permissions_helper.php';
require_once __DIR__ . '/../../includes/reports_helper.php';
require_once __DIR__ . '/../../libs/InvoiceAuditMigrator.php';

if (!reports_can_access('sales')) {
    http_response_code(403);
    die('Access Denied.');
}

InvoiceAuditMigrator::ensure($pdo);

$reportKey = 'sales';
$filters = reports_read_filters();
$kpis = reports_fetch_kpis($pdo, $reportKey, $filters);
$rows = reports_fetch_rows($pdo, $reportKey, $filters);
$trend = reports_fetch_sales_monthly_trend($pdo, $filters, 6);
$columns = reports_get_columns($reportKey);

$filterConfig = [
    'show_search' => true,
    'show_sale_type' => true,
    'status_options' => ['Unpaid', 'Partially Paid', 'Paid', 'Overdue'],
];

include '../../includes/header.php';
?>

<div class="list-toolbar mb-6">
    <div class="min-w-0">
        <p class="text-sm text-gray-500"><a href="index" class="text-indigo-600 hover:underline">Reports</a> / Sales and Revenue</p>
        <h1 class="text-3xl font-bold text-gray-800 break-words">Sales and Revenue</h1>
        <p class="text-sm text-gray-500 mt-1">Revenue, collections, outstanding balances, and payment trends.</p>
    </div>
    <div class="list-toolbar-actions">
        <?php include __DIR__ . '/partials/_export_menu.php'; ?>
    </div>
</div>

<?php include __DIR__ . '/partials/_filters.php'; ?>
<?php $kpis = $kpis; include __DIR__ . '/partials/_kpi_grid.php'; ?>

<?php if (!empty($trend)): ?>
<div class="bg-white shadow rounded-xl p-6 mb-6">
    <h2 class="text-lg font-bold text-gray-800 mb-4">Revenue Trend (6 months)</h2>
    <div class="h-72">
        <canvas id="salesRevenueTrendChart" aria-label="Monthly revenue and collections trend" role="img"></canvas>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
(function () {
    var canvas = document.getElementById('salesRevenueTrendChart');
    if (!canvas || typeof Chart === 'undefined') return;
    var trend = <?php echo json_encode($trend, JSON_UNESCAPED_UNICODE); ?>;
    new Chart(canvas, {
        type: 'bar',
        data: {
            labels: trend.map(function (r) { return r.label; }),
            datasets: [
                {
                    label: 'Revenue (MK)',
                    data: trend.map(function (r) { return r.revenue; }),
                    backgroundColor: 'rgba(79, 70, 229, 0.7)',
                    borderRadius: 6
                },
                {
                    label: 'Collected (MK)',
                    data: trend.map(function (r) { return r.collected; }),
                    backgroundColor: 'rgba(16, 185, 129, 0.7)',
                    borderRadius: 6
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { position: 'bottom' } },
            scales: { y: { beginAtZero: true } }
        }
    });
})();
</script>
<?php endif; ?>

<?php include __DIR__ . '/partials/_report_table.php'; ?>

<?php include '../../includes/footer.php'; ?>
