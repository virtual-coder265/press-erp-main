<?php
require_once __DIR__ . '/../../config/app.php';
checkAuth();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/permissions_helper.php';
require_once __DIR__ . '/../../includes/reports_helper.php';

if (!reports_can_access('materials')) {
    http_response_code(403);
    die('Access Denied.');
}

$reportKey = 'materials';
$filters = reports_read_filters();
$kpis = reports_fetch_kpis($pdo, $reportKey, $filters);
$rows = reports_fetch_rows($pdo, $reportKey, $filters);
$categoryBreakdown = reports_fetch_materials_category_breakdown($pdo, $filters);
$columns = reports_get_columns($reportKey);
$categories = $pdo->query('SELECT id, name FROM material_categories ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);

$filterConfig = [
    'show_search' => true,
    'category_options' => $categories,
];

include '../../includes/header.php';
?>

<div class="list-toolbar mb-6">
    <div class="min-w-0">
        <p class="text-sm text-gray-500"><a href="index" class="text-indigo-600 hover:underline">Reports</a> / Materials Reports</p>
        <h1 class="text-3xl font-bold text-gray-800 break-words">Materials Reports</h1>
        <p class="text-sm text-gray-500 mt-1">Inventory catalogue, category breakdown, and current rate snapshots.</p>
    </div>
    <div class="list-toolbar-actions">
        <?php include __DIR__ . '/partials/_export_menu.php'; ?>
    </div>
</div>

<?php include __DIR__ . '/partials/_filters.php'; ?>
<?php $kpis = $kpis; include __DIR__ . '/partials/_kpi_grid.php'; ?>

<div class="grid grid-cols-1 xl:grid-cols-3 gap-6 mb-6">
    <div class="bg-white shadow rounded-xl p-6 xl:col-span-1">
        <h2 class="text-lg font-bold text-gray-800 mb-4">Category Breakdown</h2>
        <div class="space-y-3">
            <?php foreach ($categoryBreakdown as $item): ?>
                <div class="flex items-center justify-between gap-3 text-sm">
                    <span class="text-gray-700"><?php echo htmlspecialchars($item['category_name']); ?></span>
                    <span class="font-semibold text-gray-900"><?php echo (int) $item['material_count']; ?> <span class="text-gray-400 font-normal">avg MK <?php echo htmlspecialchars($item['avg_rate']); ?></span></span>
                </div>
            <?php endforeach; ?>
            <?php if (empty($categoryBreakdown)): ?>
                <p class="text-gray-500 text-sm">No materials match the current filters.</p>
            <?php endif; ?>
        </div>
    </div>
    <div class="xl:col-span-2">
        <?php include __DIR__ . '/partials/_report_table.php'; ?>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>
