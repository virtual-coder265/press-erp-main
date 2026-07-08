<?php
require_once __DIR__ . '/../../config/app.php';
checkAuth();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/work_order_helper.php';

if (!hasPermission('view_work_order_reports') && !hasPermission('manage_work_orders')) {
    http_response_code(403);
    die('Access Denied.');
}

work_order_bootstrap($pdo);

$statusRows = work_order_safe_fetch($pdo, "SELECT status, COUNT(*) AS total FROM work_orders GROUP BY status ORDER BY total DESC");
$paymentRows = work_order_safe_fetch($pdo, "SELECT payment_status, COUNT(*) AS total FROM work_orders GROUP BY payment_status ORDER BY total DESC");
$turnaroundRows = work_order_safe_fetch(
    $pdo,
    "SELECT wo.work_order_number, wo.customer_name, wo.created_at, wo.completed_at,
            TIMESTAMPDIFF(HOUR, wo.created_at, wo.completed_at) AS turnaround_hours
     FROM work_orders wo
     WHERE wo.completed_at IS NOT NULL
     ORDER BY wo.completed_at DESC
     LIMIT 25"
);
$departmentKpis = work_order_safe_fetch(
    $pdo,
    "SELECT pd.name,
            COUNT(*) AS total_steps,
            SUM(CASE WHEN pp.status IN ('Received','In Progress','On Hold') THEN 1 ELSE 0 END) AS active_steps,
            SUM(CASE WHEN pp.status IN ('Completed','Dispatched') THEN 1 ELSE 0 END) AS finished_steps
     FROM production_progress pp
     INNER JOIN production_departments pd ON pp.department_id = pd.id
     GROUP BY pd.id, pd.name
     ORDER BY pd.default_order ASC"
);

include '../../includes/header.php';
?>

<div class="list-toolbar mb-6">
    <div class="min-w-0">
        <h1 class="text-3xl font-bold text-gray-800 break-words">Work Order Reports</h1>
        <p class="text-sm text-gray-500 mt-1">Operational KPIs for job status, payment readiness, turnaround time, and department output.</p>
    </div>
</div>

<div class="grid grid-cols-1 xl:grid-cols-2 gap-6 mb-6">
    <div class="bg-white shadow rounded-xl p-6">
        <h2 class="text-lg font-bold text-gray-800 mb-4">Status Distribution</h2>
        <div class="space-y-3">
            <?php foreach ($statusRows as $row): ?>
                <div class="flex items-center justify-between gap-3">
                    <span class="px-2 py-1 text-xs rounded-full font-semibold <?php echo work_order_status_badge_class((string) $row['status']); ?>"><?php echo htmlspecialchars($row['status']); ?></span>
                    <span class="text-lg font-semibold text-gray-900"><?php echo (int) $row['total']; ?></span>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="bg-white shadow rounded-xl p-6">
        <h2 class="text-lg font-bold text-gray-800 mb-4">Payment Readiness</h2>
        <div class="space-y-3">
            <?php foreach ($paymentRows as $row): ?>
                <div class="flex items-center justify-between gap-3">
                    <span class="text-sm text-gray-700"><?php echo htmlspecialchars($row['payment_status']); ?></span>
                    <span class="text-lg font-semibold text-gray-900"><?php echo (int) $row['total']; ?></span>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<div class="bg-white shadow rounded-xl p-6 mb-6">
    <h2 class="text-lg font-bold text-gray-800 mb-4">Department KPI Snapshot</h2>
    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Department</th>
                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Total Steps</th>
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
                    <tr><td colspan="4" class="px-4 py-8 text-center text-gray-500">No production KPI data yet.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="bg-white shadow rounded-xl p-6">
    <h2 class="text-lg font-bold text-gray-800 mb-4">Recent Turnaround Times</h2>
    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Work Order</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Customer</th>
                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Hours</th>
                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Completed</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                <?php foreach ($turnaroundRows as $row): ?>
                    <tr>
                        <td class="px-4 py-3 text-sm font-semibold text-gray-900"><?php echo htmlspecialchars($row['work_order_number']); ?></td>
                        <td class="px-4 py-3 text-sm text-gray-700"><?php echo htmlspecialchars($row['customer_name'] ?: '—'); ?></td>
                        <td class="px-4 py-3 text-sm text-right text-gray-800"><?php echo htmlspecialchars((string) ($row['turnaround_hours'] ?? '—')); ?></td>
                        <td class="px-4 py-3 text-sm text-right text-gray-500"><?php echo htmlspecialchars($row['completed_at']); ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($turnaroundRows)): ?>
                    <tr><td colspan="4" class="px-4 py-8 text-center text-gray-500">No completed work orders yet.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>
