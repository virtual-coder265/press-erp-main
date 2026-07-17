<?php
require_once __DIR__ . '/../../config/app.php';
checkAuth();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/permissions_helper.php';
require_once __DIR__ . '/../../includes/reports_helper.php';

if (!reports_can_access('dispatch')) {
    http_response_code(403);
    die('Access Denied.');
}

$reportKey = 'dispatch';
$filters = reports_read_filters();
$kpis = reports_fetch_kpis($pdo, $reportKey, $filters);
$rows = reports_fetch_rows($pdo, $reportKey, $filters);
$columns = reports_get_columns($reportKey);

$filterConfig = [
    'show_search' => true,
    'show_work_order' => true,
];

include '../../includes/header.php';
?>

<div class="list-toolbar mb-6">
    <div class="min-w-0">
        <p class="text-sm text-gray-500"><a href="index" class="text-indigo-600 hover:underline">Reports</a> / Dispatch Reports</p>
        <h1 class="text-3xl font-bold text-gray-800 break-words">Dispatch Reports</h1>
        <p class="text-sm text-gray-500 mt-1">Dispatch register volumes, delivery notes, and collection activity.</p>
    </div>
    <div class="list-toolbar-actions">
        <?php include __DIR__ . '/partials/_export_menu.php'; ?>
    </div>
</div>

<?php include __DIR__ . '/partials/_filters.php'; ?>
<?php $kpis = $kpis; include __DIR__ . '/partials/_kpi_grid.php'; ?>
<?php include __DIR__ . '/partials/_report_table.php'; ?>

<?php include '../../includes/footer.php'; ?>
