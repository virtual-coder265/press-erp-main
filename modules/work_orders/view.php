<?php
require_once __DIR__ . '/../../config/app.php';
checkAuth();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/work_order_helper.php';
require_once __DIR__ . '/../../libs/WorkOrderStatusManager.php';

if (!hasPermission('view_work_orders') && !hasPermission('manage_work_orders')) {
    http_response_code(403);
    die('Access Denied.');
}

work_order_bootstrap($pdo);

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    redirect('modules/work_orders/list');
}

$workOrder = work_order_fetch_one($pdo, $id);
if (!$workOrder) {
    http_response_code(404);
    die('Work order not found.');
}

$route = work_order_fetch_route($pdo, $id);
$spec = work_order_fetch_specifications($pdo, $id);
$movements = work_order_fetch_movements($pdo, $id);
$dispatchEntries = work_order_safe_fetch(
    $pdo,
    "SELECT dr.*, u1.name AS authorised_dispatcher_name, u2.name AS closed_by_name
     FROM dispatch_register dr
     LEFT JOIN users u1 ON dr.authorised_dispatcher_id = u1.id
     LEFT JOIN users u2 ON dr.closed_by = u2.id
     WHERE dr.work_order_id = ?
     ORDER BY dr.created_at DESC",
    [$id]
);

$summary = [];
if (!empty($spec['specification_summary'])) {
    $summary = json_decode((string) $spec['specification_summary'], true) ?: [];
}
$productionForm = [];
if (!empty($spec['production_form_json'])) {
    $productionForm = json_decode((string) $spec['production_form_json'], true) ?: [];
}
$formeDressing = work_order_decode_json_field($workOrder['forme_dressing_json'] ?? null);
$trimMargins = work_order_decode_json_field($workOrder['trim_margins_json'] ?? null);
$jobCounts = $summary['counts'] ?? [];
$currentRouteSlug = '';
$currentProgress = null;
foreach ($route as $step) {
    if (($step['department_id'] ?? null) == ($workOrder['current_department_id'] ?? null)) {
        $currentRouteSlug = (string) ($step['slug'] ?? '');
        if (!empty($step['progress_id']) && ($step['route_status'] ?? '') === 'Active') {
            $currentProgress = $step;
        }
        break;
    }
}
if ($currentRouteSlug === '' && !empty($route[0]['slug'])) {
    $currentRouteSlug = (string) $route[0]['slug'];
}

$canDesignateSend = false;
$designateProgressId = 0;
$designateDepartmentName = '';
if ($currentProgress && (hasPermission('manage_production_queues') || hasPermission('manage_work_orders'))) {
    $progressWorkflow = work_order_department_workflow_mode((string) ($currentProgress['slug'] ?? ''), $currentProgress);
    $canDesignateSend = work_order_can_handoff((string) ($currentProgress['status'] ?? ''), $progressWorkflow);
    $designateProgressId = (int) ($currentProgress['progress_id'] ?? 0);
    $designateDepartmentName = (string) ($currentProgress['department_name'] ?? '');
}

include '../../includes/header.php';
?>

<div class="mb-6">
    <a href="list" class="wo-page-back">
        <i data-lucide="arrow-left" class="h-4 w-4" aria-hidden="true"></i>
        Back to work orders
    </a>
</div>

<div class="bg-white shadow rounded-xl p-6 mb-6">
    <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
        <div class="min-w-0">
            <h1 class="text-3xl font-bold text-gray-800 break-words"><?php echo htmlspecialchars($workOrder['work_order_number']); ?></h1>
            <div class="flex flex-wrap items-center gap-3 mt-3">
                <span class="px-2 py-1 text-xs font-semibold rounded-full <?php echo work_order_status_badge_class((string) $workOrder['status']); ?>">
                    <?php echo htmlspecialchars($workOrder['status']); ?>
                </span>
                <span class="text-sm text-gray-500">Priority <strong class="text-gray-800"><?php echo htmlspecialchars($workOrder['priority']); ?></strong></span>
                <span class="text-sm text-gray-500">Invoice <strong class="text-gray-800"><?php echo htmlspecialchars($workOrder['invoice_number']); ?></strong></span>
                <span class="text-sm text-gray-500">Estimation <strong class="text-gray-800"><?php echo htmlspecialchars($workOrder['estimation_number'] ?: '—'); ?></strong></span>
            </div>
            <p class="mt-3 text-sm text-gray-600">
                Current location:
                <strong><?php echo htmlspecialchars($workOrder['current_department_name'] ?: ($workOrder['status'] === 'Draft' ? 'Costing (awaiting send to Origination)' : 'Not in a department queue')); ?></strong>
                <?php if (!empty($workOrder['due_date'])): ?>
                    <span class="mx-2 text-gray-300">|</span>
                    Due <strong><?php echo htmlspecialchars($workOrder['due_date']); ?></strong>
                <?php endif; ?>
            </p>
            <?php if (!empty($workOrder['sent_to_origination_at'])): ?>
                <p class="mt-2 text-sm text-gray-500">Sent to Origination: <?php echo htmlspecialchars($workOrder['sent_to_origination_at']); ?></p>
            <?php endif; ?>
        </div>
        <div class="wo-action-bar">
            <?php if (hasPermission('manage_work_orders')): ?>
                <a href="edit?id=<?php echo (int) $workOrder['id']; ?>" class="wo-action-btn bg-amber-600 text-white hover:bg-amber-700">
                    <i data-lucide="pencil" class="h-4 w-4" aria-hidden="true"></i> Edit traveler
                </a>
            <?php endif; ?>
            <a href="<?php echo BASE_URL; ?>modules/invoices/view?id=<?php echo (int) $workOrder['invoice_id']; ?>" class="wo-action-btn bg-blue-600 text-white hover:bg-blue-700">
                <i data-lucide="receipt" class="h-4 w-4" aria-hidden="true"></i> Invoice
            </a>
            <?php if ($currentRouteSlug !== ''): ?>
            <a href="workspace?department=<?php echo urlencode($currentRouteSlug); ?>" class="wo-action-btn bg-indigo-600 text-white hover:bg-indigo-700">
                <i data-lucide="layout-grid" class="h-4 w-4" aria-hidden="true"></i> Queue
            </a>
            <?php endif; ?>
            <a href="timeline?id=<?php echo (int) $workOrder['id']; ?>" class="wo-action-btn bg-slate-700 text-white hover:bg-slate-800">
                <i data-lucide="history" class="h-4 w-4" aria-hidden="true"></i> Timeline
            </a>
        </div>
    </div>
</div>

<?php if (work_order_can_send_to_origination($workOrder) && hasPermission('manage_work_orders')): ?>
    <div class="wo-cta-banner">
        <p class="text-sm text-gray-700">This work order is ready to leave costing. Send it to Origination to begin production routing.</p>
        <form method="POST" action="send_to_origination">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token('work_order_send_origination')); ?>">
            <input type="hidden" name="work_order_id" value="<?php echo (int) $workOrder['id']; ?>">
            <button type="submit" class="wo-action-btn bg-emerald-600 text-white hover:bg-emerald-700 w-full sm:w-auto">
                <i data-lucide="send" class="h-5 w-5" aria-hidden="true"></i> Send to Origination
            </button>
        </form>
    </div>
<?php elseif ($canDesignateSend && $designateProgressId > 0): ?>
    <div class="wo-cta-banner">
        <p class="text-sm text-gray-700">
            Ready to leave <strong><?php echo htmlspecialchars($designateDepartmentName); ?></strong>.
            Choose the next section and confirm the handoff.
        </p>
        <a href="handoff?progress_id=<?php echo $designateProgressId; ?>"
            class="wo-action-btn bg-emerald-600 text-white hover:bg-emerald-700 w-full sm:w-auto">
            <i data-lucide="send" class="h-5 w-5" aria-hidden="true"></i> Designate &amp; send
        </a>
    </div>
<?php endif; ?>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">
    <div class="bg-white shadow rounded-lg p-6">
        <h3 class="text-lg font-bold text-gray-700 mb-4 border-b pb-2">Customer Snapshot</h3>
        <dl class="space-y-2 text-sm">
            <div><dt class="text-gray-500">Name</dt><dd class="font-semibold text-gray-800"><?php echo htmlspecialchars($workOrder['customer_name'] ?: '—'); ?></dd></div>
            <div><dt class="text-gray-500">Email</dt><dd class="text-gray-800"><?php echo htmlspecialchars($workOrder['customer_email'] ?: '—'); ?></dd></div>
            <div><dt class="text-gray-500">Phone</dt><dd class="text-gray-800"><?php echo htmlspecialchars($workOrder['customer_phone'] ?: '—'); ?></dd></div>
            <div><dt class="text-gray-500">Payment state</dt><dd class="text-gray-800"><?php echo htmlspecialchars($workOrder['payment_status']); ?></dd></div>
            <div><dt class="text-gray-500">Outstanding balance</dt><dd class="text-gray-800 font-semibold">MK <?php echo number_format((float) ($workOrder['balance'] ?? $workOrder['balance_snapshot'] ?? 0), 2); ?></dd></div>
            <div><dt class="text-gray-500">Costed by</dt><dd class="text-gray-800"><?php echo htmlspecialchars($workOrder['costed_by_name'] ?: '—'); ?></dd></div>
            <div><dt class="text-gray-500">Issued by</dt><dd class="text-gray-800"><?php echo htmlspecialchars($workOrder['issued_by_name'] ?: '—'); ?></dd></div>
            <?php if (!empty($workOrder['binding_type_name']) || !empty($workOrder['binding_catalog_name'])): ?>
                <div><dt class="text-gray-500">Binding</dt><dd class="text-gray-800"><?php echo htmlspecialchars($workOrder['binding_type_name'] ?: $workOrder['binding_catalog_name']); ?></dd></div>
            <?php endif; ?>
        </dl>
    </div>

    <div class="bg-white shadow rounded-lg p-6 lg:col-span-2">
        <h3 class="text-lg font-bold text-gray-700 mb-4 border-b pb-2">Job Specification Summary</h3>
        <p class="text-sm text-gray-800 whitespace-pre-wrap"><?php echo htmlspecialchars($workOrder['job_description'] ?: 'No production description recorded.'); ?></p>
        <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mt-4 text-sm">
            <?php foreach (['items' => 'Items', 'papers' => 'Paper', 'ink' => 'Ink', 'binding' => 'Binding', 'prepress' => 'Pre-press', 'press' => 'Press', 'finishing' => 'Finishing'] as $key => $label): ?>
                <div class="bg-gray-50 rounded-lg p-3">
                    <div class="text-xs uppercase tracking-wide text-gray-500"><?php echo htmlspecialchars($label); ?></div>
                    <div class="text-lg font-semibold text-gray-900"><?php echo (int) ($jobCounts[$key] ?? 0); ?></div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<?php require __DIR__ . '/../../includes/work_order_traveler_view.php'; ?>

<div class="grid grid-cols-1 xl:grid-cols-2 gap-6 mb-6">
    <div class="bg-white shadow rounded-lg p-6">
        <h3 class="text-lg font-bold text-gray-700 mb-4">Recent Movements</h3>
        <div class="space-y-4">
            <?php foreach (array_slice($movements, 0, 8) as $movement): ?>
                <div class="border-l-4 border-indigo-200 pl-4">
                    <p class="font-semibold text-gray-900"><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', (string) $movement['movement_type']))); ?></p>
                    <p class="text-sm text-gray-600">
                        <?php if (!empty($movement['from_department_name'])): ?>
                            <?php echo htmlspecialchars($movement['from_department_name']); ?>
                        <?php else: ?>
                            Origin
                        <?php endif; ?>
                        <?php if (!empty($movement['to_department_name'])): ?>
                            to <?php echo htmlspecialchars($movement['to_department_name']); ?>
                        <?php endif; ?>
                    </p>
                    <?php if (!empty($movement['remarks'])): ?>
                        <p class="text-sm text-gray-500 mt-1"><?php echo htmlspecialchars($movement['remarks']); ?></p>
                    <?php endif; ?>
                    <p class="text-xs text-gray-400 mt-1">
                        <?php if (($movement['movement_type'] ?? '') === 'receive'): ?>
                            Received by <?php echo htmlspecialchars($movement['receiver_name'] ?: '—'); ?>
                        <?php else: ?>
                            <?php echo htmlspecialchars($movement['sender_name'] ?: 'System'); ?>
                            <?php if (!empty($movement['to_department_name'])): ?>
                                → <?php echo htmlspecialchars($movement['to_department_name']); ?>
                            <?php endif; ?>
                        <?php endif; ?>
                        · <?php echo htmlspecialchars($movement['created_at']); ?>
                    </p>
                </div>
            <?php endforeach; ?>
            <?php if (empty($movements)): ?>
                <p class="text-sm text-gray-500 italic">No movement activity logged yet.</p>
            <?php endif; ?>
        </div>
    </div>

    <div class="bg-white shadow rounded-lg p-6">
        <h3 class="text-lg font-bold text-gray-700 mb-4">Dispatch Records</h3>
        <div class="space-y-4">
            <?php foreach ($dispatchEntries as $entry): ?>
                <div class="border border-gray-200 rounded-lg p-4">
                    <div class="flex items-center justify-between gap-3">
                        <p class="font-semibold text-gray-900"><?php echo htmlspecialchars($entry['delivery_note_number'] ?: 'Dispatch record'); ?></p>
                        <a href="<?php echo BASE_URL; ?>modules/dispatch/view?id=<?php echo (int) $entry['id']; ?>" class="wo-card-link">
                            <i data-lucide="external-link" class="h-4 w-4" aria-hidden="true"></i> Open dispatch record
                        </a>
                    </div>
                    <p class="text-sm text-gray-600 mt-1"><?php echo htmlspecialchars($entry['remarks'] ?: 'No remarks'); ?></p>
                    <p class="text-xs text-gray-400 mt-2">Collected by: <?php echo htmlspecialchars($entry['collected_by_name'] ?: 'Pending'); ?></p>
                </div>
            <?php endforeach; ?>
            <?php if (empty($dispatchEntries)): ?>
                <p class="text-sm text-gray-500 italic">No dispatch records linked yet.</p>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>
