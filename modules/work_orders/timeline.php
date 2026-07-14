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

$id = (int) ($_GET['id'] ?? 0);
$workOrder = $id > 0 ? work_order_fetch_one($pdo, $id) : null;

if ($id > 0 && !$workOrder) {
    http_response_code(404);
    die('Work order not found.');
}

if ($id > 0) {
    $movements = work_order_fetch_movements($pdo, $id);
} else {
    $movements = work_order_safe_fetch(
        $pdo,
        "SELECT pm.*, wo.work_order_number, fd.name AS from_department_name, td.name AS to_department_name,
                su.name AS sender_name, ru.name AS receiver_name
         FROM production_movements pm
         INNER JOIN work_orders wo ON pm.work_order_id = wo.id
         LEFT JOIN production_departments fd ON pm.from_department_id = fd.id
         LEFT JOIN production_departments td ON pm.to_department_id = td.id
         LEFT JOIN users su ON pm.sender_user_id = su.id
         LEFT JOIN users ru ON pm.receiver_user_id = ru.id
         ORDER BY pm.created_at DESC, pm.id DESC
         LIMIT 100"
    );
}

include '../../includes/header.php';
?>

<?php if ($workOrder): ?>
    <div class="mb-2">
        <a href="view?id=<?php echo (int) $workOrder['id']; ?>" class="wo-page-back">
            <i data-lucide="arrow-left" class="h-4 w-4" aria-hidden="true"></i>
            Back to work order
        </a>
    </div>
<?php endif; ?>

<div class="list-toolbar mb-6">
    <div class="min-w-0">
        <h1 class="text-3xl font-bold text-gray-800 break-words"><?php echo $workOrder ? htmlspecialchars($workOrder['work_order_number'] . ' Timeline') : 'Production Timeline'; ?></h1>
        <p class="text-sm text-gray-500 mt-1">Audit trail of every work-order handoff and production movement.</p>
    </div>
    <?php if (!$workOrder): ?>
    <div class="list-toolbar-actions">
        <a href="list" class="list-action-btn bg-indigo-600 text-white">
            <i data-lucide="clipboard-list" class="sm:mr-1 inline-block h-5 w-5" aria-hidden="true"></i>
            <span class="hidden sm:inline">Work orders</span>
        </a>
    </div>
    <?php endif; ?>
</div>

<div class="bg-white shadow rounded-xl p-6">
    <div class="space-y-5">
        <?php foreach ($movements as $movement): ?>
            <div class="relative pl-6 border-l-2 border-indigo-200">
                <div class="absolute -left-[9px] top-1 w-4 h-4 rounded-full bg-indigo-600"></div>
                <div class="flex flex-col md:flex-row md:items-start md:justify-between gap-2">
                    <div>
                        <p class="font-semibold text-gray-900">
                            <?php echo htmlspecialchars($movement['work_order_number'] ?? $workOrder['work_order_number']); ?>
                            <span class="text-sm text-gray-500">· <?php echo htmlspecialchars(ucwords(str_replace('_', ' ', (string) $movement['movement_type']))); ?></span>
                        </p>
                        <p class="text-sm text-gray-600 mt-1">
                            <?php echo htmlspecialchars($movement['from_department_name'] ?: 'Origin'); ?>
                            <?php if (!empty($movement['to_department_name'])): ?>
                                to <?php echo htmlspecialchars($movement['to_department_name']); ?>
                            <?php endif; ?>
                        </p>
                        <?php if (!empty($movement['remarks'])): ?>
                            <p class="text-sm text-gray-500 mt-2"><?php echo htmlspecialchars($movement['remarks']); ?></p>
                        <?php endif; ?>
                        <p class="text-xs text-gray-400 mt-2">
                            <?php if (($movement['movement_type'] ?? '') === 'receive'): ?>
                                Received by <?php echo htmlspecialchars($movement['receiver_name'] ?: '—'); ?>
                            <?php else: ?>
                                Sender: <?php echo htmlspecialchars($movement['sender_name'] ?: 'System'); ?>
                                <?php if (!empty($movement['receiver_name'])): ?>
                                    | Receiver: <?php echo htmlspecialchars($movement['receiver_name']); ?>
                                <?php endif; ?>
                            <?php endif; ?>
                        </p>
                    </div>
                    <div class="text-xs text-gray-400"><?php echo htmlspecialchars($movement['created_at']); ?></div>
                </div>
            </div>
        <?php endforeach; ?>
        <?php if (empty($movements)): ?>
            <p class="text-sm text-gray-500 italic">No production movement history yet.</p>
        <?php endif; ?>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>
