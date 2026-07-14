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
    $_SESSION['error'] = 'Invalid send-back reference.';
    redirect('modules/work_orders/workspace?department=dispatch-office');
}

$stmt = $pdo->prepare("
    SELECT pp.*, pr.work_order_id, pd.id AS department_id, pd.name AS department_name, pd.slug AS department_slug,
           pd.workflow_mode, wo.work_order_number, wo.customer_name, wo.job_description,
           wo.quantity, wo.due_date
    FROM production_progress pp
    INNER JOIN production_routes pr ON pp.route_id = pr.id
    INNER JOIN production_departments pd ON pp.department_id = pd.id
    INNER JOIN work_orders wo ON pp.work_order_id = wo.id
    WHERE pp.id = ?
    LIMIT 1
");
$stmt->execute([$progressId]);
$context = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$context) {
    $_SESSION['error'] = 'Work order queue item not found.';
    redirect('modules/work_orders/workspace?department=dispatch-office');
}

$departmentSlug = (string) ($context['department_slug'] ?? 'dispatch-office');
$workflowMode = (string) ($context['workflow_mode'] ?? 'production');
$canSendBack = $workflowMode === 'dispatch'
    && in_array((string) ($context['status'] ?? ''), ['Received', 'On Hold'], true);

$senderDepartment = work_order_find_send_back_department(
    $pdo,
    (int) $context['work_order_id'],
    (int) $context['department_id']
);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '', 'work_order_send_back')) {
        $_SESSION['error'] = 'Security check failed. Please try again.';
        redirect('modules/work_orders/send_back?progress_id=' . $progressId);
    }

    if (!$canSendBack) {
        $_SESSION['error'] = 'This job cannot be sent back from dispatch right now.';
        redirect('modules/work_orders/workspace?department=' . urlencode($departmentSlug) . '&tab=ready');
    }

    try {
        $remarks = trim((string) ($_POST['remarks'] ?? ''));
        if ($remarks === '') {
            throw new RuntimeException('Please explain why this job is being sent back.');
        }

        $result = work_order_send_back_to_sender(
            $pdo,
            $progressId,
            (int) ($_SESSION['user_id'] ?? 0),
            $remarks
        );

        $_SESSION['success'] = $result['message'];
        $targetDepartment = $result['next_department_slug'] !== ''
            ? $result['next_department_slug']
            : $departmentSlug;
        redirect('modules/work_orders/workspace?department=' . urlencode($targetDepartment) . '&tab=active');
    } catch (Throwable $exception) {
        $_SESSION['error'] = $exception->getMessage();
        redirect('modules/work_orders/send_back?progress_id=' . $progressId);
    }
}

include '../../includes/header.php';
?>

<div class="mb-6">
    <a href="workspace?department=<?php echo urlencode($departmentSlug); ?>&tab=ready" class="wo-page-back">
        <i data-lucide="arrow-left" class="h-4 w-4" aria-hidden="true"></i>
        Back to Dispatch Queue
    </a>
    <h1 class="text-3xl font-bold text-gray-800">Send Back to Sender</h1>
    <p class="text-gray-600 mt-1">Return this job to the department that sent it to dispatch when there are issues or further work is required.</p>
</div>

<?php if (!empty($_SESSION['error'])): ?>
    <div class="bg-red-50 border border-red-200 text-red-800 rounded-lg p-4 mb-4"><?php echo htmlspecialchars((string) $_SESSION['error']); unset($_SESSION['error']); ?></div>
<?php endif; ?>

<div class="bg-white shadow rounded-lg p-8 max-w-3xl">
    <div class="mb-6 rounded-xl border border-gray-200 p-5 bg-gray-50">
        <p class="text-sm text-gray-500">Work order</p>
        <p class="text-xl font-bold text-gray-900"><?php echo htmlspecialchars($context['work_order_number']); ?></p>
        <p class="text-sm text-gray-600 mt-2"><?php echo htmlspecialchars($context['customer_name'] ?: '—'); ?></p>
        <p class="text-sm text-gray-600"><?php echo htmlspecialchars($context['job_description'] ?: 'No description'); ?></p>
        <?php if ($senderDepartment): ?>
            <p class="text-sm text-gray-700 mt-4">
                Will return to <strong><?php echo htmlspecialchars($senderDepartment['name']); ?></strong>
            </p>
        <?php else: ?>
            <p class="text-sm text-red-700 mt-4">Unable to determine the sender department for this job.</p>
        <?php endif; ?>
    </div>

    <?php if ($canSendBack && $senderDepartment): ?>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token('work_order_send_back')); ?>">
            <input type="hidden" name="progress_id" value="<?php echo (int) $progressId; ?>">

            <div class="mb-6">
                <label class="block text-gray-700 font-bold mb-2" for="remarks">Reason for send-back *</label>
                <textarea id="remarks" name="remarks" rows="4" required
                    class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-red-500"
                    placeholder="Describe the issue or instructions for the sending department."></textarea>
            </div>

            <div class="wo-form-actions">
                <button type="submit" class="wo-btn wo-btn-danger">
                    <i data-lucide="undo-2" class="h-4 w-4" aria-hidden="true"></i>
                    Send back to <?php echo htmlspecialchars($senderDepartment['name']); ?>
                </button>
                <a href="workspace?department=<?php echo urlencode($departmentSlug); ?>&tab=ready" class="wo-btn wo-btn-secondary">
                    Cancel
                </a>
            </div>
        </form>
    <?php else: ?>
        <a href="workspace?department=<?php echo urlencode($departmentSlug); ?>&tab=ready"
            class="wo-btn bg-indigo-600 text-white hover:bg-indigo-700">
            Return to Dispatch Queue
        </a>
    <?php endif; ?>
</div>

<?php include '../../includes/footer.php'; ?>
