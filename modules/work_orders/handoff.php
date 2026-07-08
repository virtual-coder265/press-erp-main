<?php
require_once __DIR__ . '/../../config/app.php';
checkAuth();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/work_order_helper.php';

if (!hasPermission('manage_production_queues') && !hasPermission('manage_work_orders')) {
    http_response_code(403);
    die('Access Denied.');
}

work_order_bootstrap($pdo);

$progressId = (int) ($_GET['progress_id'] ?? $_POST['progress_id'] ?? 0);
if ($progressId <= 0) {
    $_SESSION['error'] = 'Invalid handoff reference.';
    redirect('modules/work_orders/workspace');
}

$context = work_order_fetch_handoff_context($pdo, $progressId);
if (!$context) {
    $_SESSION['error'] = 'Work order queue item not found.';
    redirect('modules/work_orders/workspace');
}

$departmentSlug = (string) ($context['department_slug'] ?? 'origination');
$workflowMode = (string) ($context['department_workflow_mode'] ?? 'production');
$canHandoff = work_order_can_handoff((string) ($context['status'] ?? ''), $workflowMode);
$destinations = $context['destinations'] ?? [];
$needsExtraFields = !empty($context['needs_extra_fields']);
$isRouting = $workflowMode === 'routing';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '', 'work_order_handoff')) {
        $_SESSION['error'] = 'Security check failed. Please try again.';
        redirect('modules/work_orders/handoff?progress_id=' . $progressId);
    }

    if (!$canHandoff) {
        $_SESSION['error'] = $isRouting
            ? 'Receive and record this job in Origination before designating the next section.'
            : 'Mark the job complete before sending it to the next section.';
        redirect('modules/work_orders/workspace?department=' . urlencode($departmentSlug) . '&tab=active');
    }

    try {
        $nextDepartmentId = (int) ($_POST['next_department_id'] ?? 0);
        $result = work_order_designate_and_send($pdo, $progressId, $nextDepartmentId, (int) ($_SESSION['user_id'] ?? 0), [
            'handoff_notes' => $_POST['handoff_notes'] ?? '',
            'handoff_sample' => $_POST['handoff_sample'] ?? '',
            'handoff_delivered_by' => $_POST['handoff_delivered_by'] ?? '',
            'handoff_remarks' => $_POST['handoff_remarks'] ?? '',
        ]);

        $_SESSION['success'] = $result['message'];
        if (!empty($result['next_department_slug'])) {
            redirect('modules/work_orders/workspace?department=' . urlencode($result['next_department_slug']) . '&tab=incoming');
        }
        redirect('modules/work_orders/workspace?department=' . urlencode($departmentSlug) . '&tab=sent');
    } catch (Throwable $exception) {
        $_SESSION['error'] = $exception->getMessage();
        redirect('modules/work_orders/handoff?progress_id=' . $progressId);
    }
}

$readyTab = $isRouting ? 'ready' : 'ready';
include '../../includes/header.php';
?>

<div class="mb-6">
    <a href="workspace?department=<?php echo urlencode($departmentSlug); ?>&tab=<?php echo $canHandoff ? $readyTab : 'active'; ?>"
        class="text-indigo-600 hover:underline inline-flex items-center text-sm">
        <i data-lucide="arrow-left" class="mr-1 inline-block h-4 w-4" aria-hidden="true"></i>
        Back to <?php echo htmlspecialchars($context['department_name']); ?> workspace
    </a>
</div>

<?php if (!$canHandoff): ?>
    <div class="max-w-2xl mx-auto bg-white shadow rounded-xl p-8 text-center">
        <div class="w-14 h-14 rounded-full bg-amber-100 text-amber-700 flex items-center justify-center mx-auto mb-4">
            <i data-lucide="alert-circle" class="h-7 w-7" aria-hidden="true"></i>
        </div>
        <h1 class="text-2xl font-bold text-gray-800 mb-2">Not ready to send yet</h1>
        <p class="text-gray-600 mb-6">
            Work order <strong><?php echo htmlspecialchars($context['work_order_number']); ?></strong> is currently
            <strong><?php echo htmlspecialchars($context['status']); ?></strong>.
            <?php if ($isRouting): ?>
                Receive the job and complete the origination record before designating where it should go.
            <?php else: ?>
                Mark the job <strong>complete</strong> in your workspace before designating the next section.
            <?php endif; ?>
        </p>
        <a href="workspace?department=<?php echo urlencode($departmentSlug); ?>&tab=active"
            class="inline-flex items-center gap-2 bg-indigo-600 text-white px-5 py-3 rounded-lg hover:bg-indigo-700 transition">
            Return to workspace
        </a>
    </div>
<?php else: ?>
    <div class="max-w-3xl mx-auto">
        <div class="text-center mb-8">
            <h1 class="text-3xl font-bold text-gray-800">
                <?php echo $isRouting ? 'Designate next section' : 'Designate &amp; send work order'; ?>
            </h1>
            <p class="text-gray-500 mt-2">
                <?php echo $isRouting
                    ? 'Choose where this job should go after Origination records it.'
                    : 'Select the next production section when work here is finished.'; ?>
            </p>
        </div>

        <div class="bg-white shadow rounded-xl p-6 mb-6">
            <h2 class="text-lg font-bold text-gray-800 mb-4">Work order summary</h2>
            <dl class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-sm">
                <div><dt class="text-gray-500">Work order</dt><dd class="font-semibold text-gray-900"><?php echo htmlspecialchars($context['work_order_number']); ?></dd></div>
                <div><dt class="text-gray-500">Customer</dt><dd class="font-semibold text-gray-900"><?php echo htmlspecialchars($context['customer_name'] ?: '—'); ?></dd></div>
                <div><dt class="text-gray-500">From section</dt><dd class="text-gray-900"><?php echo htmlspecialchars($context['department_name']); ?></dd></div>
                <div><dt class="text-gray-500">Due date</dt><dd class="text-gray-900"><?php echo htmlspecialchars($context['due_date'] ?: '—'); ?></dd></div>
                <?php if (!empty($context['quantity'])): ?>
                    <div><dt class="text-gray-500">Quantity</dt><dd class="text-gray-900"><?php echo (int) $context['quantity']; ?></dd></div>
                <?php endif; ?>
                <div class="sm:col-span-2"><dt class="text-gray-500">Job description</dt><dd class="text-gray-900 mt-1"><?php echo htmlspecialchars($context['job_description'] ?: '—'); ?></dd></div>
            </dl>
            <div class="mt-4 pt-4 border-t flex flex-wrap gap-3">
                <a href="department_edit?department=<?php echo urlencode($departmentSlug); ?>&id=<?php echo (int) $context['work_order_id']; ?>"
                    class="text-sm text-indigo-600 hover:underline">
                    <?php echo $isRouting ? 'Edit origination record' : 'Review / edit section fields'; ?>
                </a>
                <a href="view?id=<?php echo (int) $context['work_order_id']; ?>"
                    class="text-sm text-indigo-600 hover:underline">Open full work order</a>
            </div>
        </div>

        <form method="POST" action="handoff" class="bg-white shadow rounded-xl p-6 space-y-6">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token('work_order_handoff')); ?>">
            <input type="hidden" name="progress_id" value="<?php echo $progressId; ?>">

            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-3">Select next section <span class="text-red-500">*</span></label>
                <?php if (empty($destinations)): ?>
                    <p class="text-sm text-red-600">No destination sections are available.</p>
                <?php else: ?>
                    <div class="space-y-2">
                        <?php foreach ($destinations as $destination): ?>
                            <label class="flex items-start gap-3 p-4 border border-gray-200 rounded-lg hover:border-indigo-300 cursor-pointer">
                                <input type="radio" name="next_department_id" value="<?php echo (int) $destination['id']; ?>" required
                                    class="mt-1 text-indigo-600">
                                <span>
                                    <span class="font-semibold text-gray-900"><?php echo htmlspecialchars($destination['name']); ?></span>
                                    <?php if (!empty($destination['is_suggested'])): ?>
                                        <span class="ml-2 text-xs font-semibold uppercase tracking-wide text-emerald-700 bg-emerald-50 px-2 py-0.5 rounded-full">Suggested</span>
                                    <?php endif; ?>
                                    <span class="block text-xs text-gray-500 mt-1"><?php echo htmlspecialchars($destination['queue_label']); ?></span>
                                </span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <?php if ($needsExtraFields): ?>
                <div class="border-t pt-6 space-y-4">
                    <h3 class="text-sm font-semibold text-gray-800">Production handoff details</h3>
                    <div>
                        <label class="block text-sm text-gray-600 mb-1">Sample</label>
                        <input type="text" name="handoff_sample" class="w-full px-4 py-2 border border-gray-300 rounded-lg">
                    </div>
                    <div>
                        <label class="block text-sm text-gray-600 mb-1">Delivered by</label>
                        <input type="text" name="handoff_delivered_by" class="w-full px-4 py-2 border border-gray-300 rounded-lg">
                    </div>
                    <div>
                        <label class="block text-sm text-gray-600 mb-1">Remarks</label>
                        <textarea name="handoff_remarks" rows="2" class="w-full px-4 py-2 border border-gray-300 rounded-lg"></textarea>
                    </div>
                </div>
            <?php endif; ?>

            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-2">Handoff notes <span class="font-normal text-gray-400">(optional)</span></label>
                <textarea name="handoff_notes" rows="3" placeholder="e.g. Plates ready, quantity verified..."
                    class="w-full px-4 py-3 border border-gray-300 rounded-lg resize-y"></textarea>
            </div>

            <div class="flex flex-col sm:flex-row gap-3 sm:justify-end">
                <a href="workspace?department=<?php echo urlencode($departmentSlug); ?>&tab=<?php echo $readyTab; ?>"
                    class="px-5 py-3 rounded-lg border border-gray-300 text-gray-700 text-center hover:bg-gray-50 transition">
                    Cancel
                </a>
                <button type="submit" <?php echo empty($destinations) ? 'disabled' : ''; ?>
                    class="px-6 py-3 rounded-lg bg-emerald-600 text-white font-semibold hover:bg-emerald-700 transition inline-flex items-center justify-center gap-2 shadow-sm disabled:opacity-50">
                    <i data-lucide="send" class="h-5 w-5" aria-hidden="true"></i>
                    Confirm designation &amp; send
                </button>
            </div>
        </form>
    </div>
<?php endif; ?>

<?php include '../../includes/footer.php'; ?>
