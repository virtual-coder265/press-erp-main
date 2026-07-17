<?php
require_once __DIR__ . '/../../config/app.php';
checkAuth();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/permissions_helper.php';
require_once __DIR__ . '/../../includes/reports_helper.php';
require_once __DIR__ . '/../../includes/work_order_helper.php';

if (!reports_can_access('work_orders')) {
    http_response_code(403);
    die('Access Denied.');
}

work_order_bootstrap($pdo);

$reportKey = 'work_orders';
$filters = reports_read_filters();
$kpis = reports_fetch_kpis($pdo, $reportKey, $filters);
$rows = reports_fetch_rows($pdo, $reportKey, $filters);
$statusBreakdown = reports_fetch_work_order_status_breakdown($pdo, $filters);
$departmentKpis = reports_fetch_work_order_department_kpis($pdo, $filters);
$columns = reports_get_columns($reportKey);
$departments = work_order_safe_fetch($pdo, 'SELECT slug, name FROM production_departments WHERE is_active = 1 ORDER BY default_order ASC');

$filterConfig = [
    'show_search' => true,
    'show_priority' => true,
    'department_options' => $departments,
    'status_options' => ['Draft', 'Waiting Payment', 'Ready for Production', 'In Production', 'Awaiting Dispatch', 'Dispatched', 'Completed', 'Cancelled'],
];

include '../../includes/header.php';
?>

<div class="list-toolbar mb-6">
    <div class="min-w-0">
        <p class="text-sm text-gray-500"><a href="index" class="text-indigo-600 hover:underline">Reports</a> / Work Order Reports</p>
        <h1 class="text-3xl font-bold text-gray-800 break-words">Work Order Reports</h1>
        <p class="text-sm text-gray-500 mt-1">Production status, payment readiness, department throughput, and turnaround analysis.</p>
    </div>
    <div class="list-toolbar-actions">
        <?php include __DIR__ . '/partials/_export_menu.php'; ?>
    </div>
</div>

<?php include __DIR__ . '/partials/_filters.php'; ?>
<?php $kpis = $kpis; include __DIR__ . '/partials/_kpi_grid.php'; ?>

<div class="grid grid-cols-1 xl:grid-cols-2 gap-6 mb-6">
    <div class="bg-white shadow rounded-xl p-6">
        <h2 class="text-lg font-bold text-gray-800 mb-4">Status Distribution</h2>
        <div class="space-y-3">
            <?php foreach ($statusBreakdown as $row): ?>
                <div class="flex items-center justify-between gap-3">
                    <span class="px-2 py-1 text-xs rounded-full font-semibold <?php echo work_order_status_badge_class((string) $row['status']); ?>"><?php echo htmlspecialchars($row['status']); ?></span>
                    <span class="text-lg font-semibold text-gray-900"><?php echo (int) $row['total']; ?></span>
                </div>
            <?php endforeach; ?>
            <?php if (empty($statusBreakdown)): ?>
                <p class="text-gray-500 text-sm">No work orders match the current filters.</p>
            <?php endif; ?>
        </div>
    </div>

    <div class="bg-white shadow rounded-xl p-6">
        <h2 class="text-lg font-bold text-gray-800 mb-4">Department Throughput</h2>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Department</th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Total</th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Active</th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Finished</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    <?php foreach ($departmentKpis as $row): ?>
                        <tr>
                            <td class="px-4 py-3 text-sm text-gray-800"><?php echo htmlspecialchars($row['name']); ?></td>
                            <td class="px-4 py-3 text-sm text-right text-gray-700"><?php echo (int) $row['total_steps']; ?></td>
                            <td class="px-4 py-3 text-sm text-right text-indigo-700 font-semibold"><?php echo (int) $row['active_steps']; ?></td>
                            <td class="px-4 py-3 text-sm text-right text-emerald-700 font-semibold"><?php echo (int) $row['finished_steps']; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($departmentKpis)): ?>
                        <tr><td colspan="4" class="px-4 py-8 text-center text-gray-500">No department data for this period.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php include __DIR__ . '/partials/_report_table.php'; ?>

<?php include '../../includes/footer.php'; ?>
