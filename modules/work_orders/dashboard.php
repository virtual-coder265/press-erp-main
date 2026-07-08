<?php
require_once __DIR__ . '/../../config/app.php';
checkAuth();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/work_order_helper.php';
require_once __DIR__ . '/../../libs/WorkOrderStatusManager.php';

if (!hasPermission('view_work_orders') && !hasPermission('view_work_order_reports') && !hasPermission('manage_work_orders')) {
    http_response_code(403);
    die('Access Denied.');
}

work_order_bootstrap($pdo);

$statusStats = work_order_safe_fetch($pdo, "SELECT status, COUNT(*) AS total FROM work_orders GROUP BY status ORDER BY total DESC");
$departmentStats = work_order_safe_fetch(
    $pdo,
    "SELECT pd.name, COUNT(*) AS total
     FROM production_progress pp
     INNER JOIN production_departments pd ON pp.department_id = pd.id
     WHERE pp.status IN ('Received','In Progress','On Hold')
     GROUP BY pd.id, pd.name
     ORDER BY total DESC, pd.default_order ASC"
);
$overdueJobs = work_order_safe_fetch(
    $pdo,
    "SELECT wo.id, wo.work_order_number, wo.customer_name, wo.due_date, wo.status
     FROM work_orders wo
     WHERE wo.due_date IS NOT NULL
       AND wo.due_date < CURDATE()
       AND wo.status NOT IN ('Completed','Cancelled')
     ORDER BY wo.due_date ASC"
);
$urgentJobs = work_order_safe_fetch(
    $pdo,
    "SELECT id, work_order_number, customer_name, status, due_date
     FROM work_orders
     WHERE priority IN ('Urgent','Critical')
       AND status NOT IN ('Completed','Cancelled')
     ORDER BY priority DESC, COALESCE(due_date, '2999-12-31') ASC"
);

$summary = [
    'total' => (int) ($pdo->query("SELECT COUNT(*) FROM work_orders")->fetchColumn() ?: 0),
    'in_production' => (int) ($pdo->query("SELECT COUNT(*) FROM work_orders WHERE status = 'In Production'")->fetchColumn() ?: 0),
    'awaiting_dispatch' => (int) ($pdo->query("SELECT COUNT(*) FROM work_orders WHERE status = 'Awaiting Dispatch'")->fetchColumn() ?: 0),
    'completed' => (int) ($pdo->query("SELECT COUNT(*) FROM work_orders WHERE status = 'Completed'")->fetchColumn() ?: 0),
];

include '../../includes/header.php';
?>

<div class="list-toolbar mb-6">
    <div class="min-w-0">
        <h1 class="text-3xl font-bold text-gray-800 break-words">Production Dashboard</h1>
        <p class="text-sm text-gray-500 mt-1">Monitor job flow from accepted invoice through routing, completion, and dispatch.</p>
    </div>
    <div class="list-toolbar-actions">
        <a href="list" class="list-action-btn bg-indigo-600 text-white">
            <i data-lucide="clipboard-list" class="sm:mr-1 inline-block h-5 w-5" aria-hidden="true"></i>
            <span class="hidden sm:inline">All Work Orders</span>
        </a>
    </div>
</div>

<div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-6 mb-6">
    <div class="bg-white rounded-xl shadow p-6">
        <p class="text-sm text-gray-500">Total Work Orders</p>
        <p class="text-3xl font-bold text-gray-900 mt-2"><?php echo $summary['total']; ?></p>
    </div>
    <div class="bg-white rounded-xl shadow p-6">
        <p class="text-sm text-gray-500">In Production</p>
        <p class="text-3xl font-bold text-indigo-700 mt-2"><?php echo $summary['in_production']; ?></p>
    </div>
    <div class="bg-white rounded-xl shadow p-6">
        <p class="text-sm text-gray-500">Awaiting Dispatch</p>
        <p class="text-3xl font-bold text-purple-700 mt-2"><?php echo $summary['awaiting_dispatch']; ?></p>
    </div>
    <div class="bg-white rounded-xl shadow p-6">
        <p class="text-sm text-gray-500">Completed</p>
        <p class="text-3xl font-bold text-emerald-700 mt-2"><?php echo $summary['completed']; ?></p>
    </div>
</div>

<div class="grid grid-cols-1 xl:grid-cols-3 gap-6 mb-6">
    <div class="bg-white rounded-xl shadow p-6">
        <h2 class="text-lg font-bold text-gray-800 mb-4">Jobs By Status</h2>
        <div class="space-y-3">
            <?php foreach ($statusStats as $row): ?>
                <div class="flex items-center justify-between gap-3">
                    <span class="px-2 py-1 text-xs rounded-full font-semibold <?php echo work_order_status_badge_class((string) $row['status']); ?>">
                        <?php echo htmlspecialchars($row['status']); ?>
                    </span>
                    <span class="text-lg font-semibold text-gray-900"><?php echo (int) $row['total']; ?></span>
                </div>
            <?php endforeach; ?>
            <?php if (empty($statusStats)): ?>
                <p class="text-sm text-gray-500 italic">No work-order data yet.</p>
            <?php endif; ?>
        </div>
    </div>

    <div class="bg-white rounded-xl shadow p-6">
        <h2 class="text-lg font-bold text-gray-800 mb-4">Department Workload</h2>
        <div class="space-y-3">
            <?php foreach ($departmentStats as $row): ?>
                <div class="flex items-center justify-between gap-3">
                    <span class="text-sm text-gray-700"><?php echo htmlspecialchars($row['name']); ?></span>
                    <span class="text-lg font-semibold text-gray-900"><?php echo (int) $row['total']; ?></span>
                </div>
            <?php endforeach; ?>
            <?php if (empty($departmentStats)): ?>
                <p class="text-sm text-gray-500 italic">No active queue workload right now.</p>
            <?php endif; ?>
        </div>
    </div>

    <div class="bg-white rounded-xl shadow p-6">
        <h2 class="text-lg font-bold text-gray-800 mb-4">Urgent Jobs</h2>
        <div class="space-y-3">
            <?php foreach (array_slice($urgentJobs, 0, 6) as $job): ?>
                <a href="view?id=<?php echo (int) $job['id']; ?>" class="block border border-gray-200 rounded-lg p-3 hover:border-indigo-300 transition">
                    <div class="flex items-center justify-between gap-3">
                        <span class="font-semibold text-gray-900"><?php echo htmlspecialchars($job['work_order_number']); ?></span>
                        <span class="text-xs text-red-600 font-bold"><?php echo htmlspecialchars((string) $job['status']); ?></span>
                    </div>
                    <p class="text-sm text-gray-500 mt-1"><?php echo htmlspecialchars($job['customer_name'] ?: '—'); ?></p>
                </a>
            <?php endforeach; ?>
            <?php if (empty($urgentJobs)): ?>
                <p class="text-sm text-gray-500 italic">No urgent jobs currently open.</p>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="bg-white rounded-xl shadow p-6">
    <h2 class="text-lg font-bold text-gray-800 mb-4">Overdue Jobs</h2>
    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Work Order</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Customer</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Due Date</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                <?php foreach ($overdueJobs as $job): ?>
                    <tr>
                        <td class="px-4 py-3 text-sm font-semibold text-gray-900"><a class="text-indigo-600 hover:underline" href="view?id=<?php echo (int) $job['id']; ?>"><?php echo htmlspecialchars($job['work_order_number']); ?></a></td>
                        <td class="px-4 py-3 text-sm text-gray-700"><?php echo htmlspecialchars($job['customer_name'] ?: '—'); ?></td>
                        <td class="px-4 py-3 text-sm"><span class="px-2 py-1 text-xs rounded-full font-semibold <?php echo work_order_status_badge_class((string) $job['status']); ?>"><?php echo htmlspecialchars($job['status']); ?></span></td>
                        <td class="px-4 py-3 text-sm text-right text-red-600 font-semibold"><?php echo htmlspecialchars($job['due_date']); ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($overdueJobs)): ?>
                    <tr><td colspan="4" class="px-4 py-8 text-center text-gray-500">No overdue jobs.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>
