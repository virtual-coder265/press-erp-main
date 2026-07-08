<?php
require_once __DIR__ . '/../../config/app.php';
checkAuth();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/work_order_helper.php';

if (!hasPermission('view_work_orders') && !hasPermission('manage_work_orders')) {
    http_response_code(403);
    die('Access Denied.');
}

work_order_bootstrap($pdo);

$readyJobs = work_order_safe_fetch(
    $pdo,
    "SELECT wo.*, dr.id AS dispatch_id, dr.delivery_note_number, dr.collected_at
     FROM work_orders wo
     LEFT JOIN dispatch_register dr ON dr.work_order_id = wo.id
     WHERE wo.status IN ('Awaiting Dispatch', 'Dispatched')
     ORDER BY COALESCE(wo.dispatch_ready_at, wo.updated_at) ASC"
);

include '../../includes/header.php';
?>

<div class="list-toolbar mb-6">
    <div class="min-w-0">
        <h1 class="text-3xl font-bold text-gray-800 break-words">Dispatch Readiness</h1>
        <p class="text-sm text-gray-500 mt-1">Review completed production jobs and hand them over to the dispatch register and customer collection flow.</p>
    </div>
    <div class="list-toolbar-actions">
        <a href="<?php echo BASE_URL; ?>modules/dispatch/list" class="list-action-btn bg-green-600 text-white">
            <i data-lucide="truck" class="sm:mr-1 inline-block h-5 w-5" aria-hidden="true"></i>
            <span class="hidden sm:inline">Open Dispatch Register</span>
        </a>
    </div>
</div>

<div class="space-y-4">
    <?php foreach ($readyJobs as $job): ?>
        <div class="bg-white shadow rounded-xl p-5">
            <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
                <div>
                    <p class="font-semibold text-gray-900"><?php echo htmlspecialchars($job['work_order_number']); ?></p>
                    <p class="text-sm text-gray-500 mt-1"><?php echo htmlspecialchars($job['customer_name'] ?: '—'); ?></p>
                    <p class="text-sm text-gray-600 mt-2"><?php echo htmlspecialchars($job['job_description'] ?: 'No description'); ?></p>
                    <p class="text-xs text-gray-400 mt-2">Status: <?php echo htmlspecialchars($job['status']); ?></p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <a href="view?id=<?php echo (int) $job['id']; ?>" class="px-4 py-2 rounded-lg bg-indigo-600 text-white hover:bg-indigo-700 transition">View</a>
                    <?php if (!empty($job['dispatch_id'])): ?>
                        <a href="<?php echo BASE_URL; ?>modules/dispatch/view?id=<?php echo (int) $job['dispatch_id']; ?>" class="px-4 py-2 rounded-lg bg-green-600 text-white hover:bg-green-700 transition">Dispatch Record</a>
                    <?php else: ?>
                        <a href="<?php echo BASE_URL; ?>modules/dispatch/create?work_order_id=<?php echo (int) $job['id']; ?>" class="px-4 py-2 rounded-lg bg-green-600 text-white hover:bg-green-700 transition">Create Dispatch Entry</a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
    <?php if (empty($readyJobs)): ?>
        <div class="bg-white shadow rounded-xl p-8 text-center text-gray-500">No jobs are currently awaiting dispatch.</div>
    <?php endif; ?>
</div>

<?php include '../../includes/footer.php'; ?>
