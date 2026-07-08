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
    $_SESSION['error'] = 'Invalid receive reference.';
    redirect('modules/work_orders/workspace');
}

$stmt = $pdo->prepare("
    SELECT pp.*, pr.work_order_id, pd.name AS department_name, pd.slug AS department_slug,
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
    redirect('modules/work_orders/workspace');
}

$departmentSlug = (string) ($context['department_slug'] ?? 'origination');
$canReceive = in_array((string) ($context['status'] ?? ''), ['Pending', 'Returned'], true);

$userStmt = $pdo->prepare("SELECT name FROM users WHERE id = ? LIMIT 1");
$userStmt->execute([(int) ($_SESSION['user_id'] ?? 0)]);
$receiverName = (string) ($userStmt->fetchColumn() ?: 'Current user');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '', 'work_order_receive')) {
        $_SESSION['error'] = 'Security check failed. Please try again.';
        redirect('modules/work_orders/receive?progress_id=' . $progressId);
    }

    if (!$canReceive) {
        $_SESSION['error'] = 'This job has already been received.';
        redirect('modules/work_orders/workspace?department=' . urlencode($departmentSlug) . '&tab=active');
    }

    try {
        $extra = [
            'received_quantity' => $_POST['received_quantity'] ?? '',
            'receive_notes' => $_POST['receive_notes'] ?? '',
        ];
        work_order_process_queue_action(
            $pdo,
            $progressId,
            'receive',
            (int) ($_SESSION['user_id'] ?? 0),
            '',
            '',
            $extra
        );

        $_SESSION['success'] = 'Work order ' . $context['work_order_number'] . ' received in ' . $context['department_name'] . '.';
        $workflowMode = (string) ($context['workflow_mode'] ?? 'production');
        $tab = $workflowMode === 'routing' ? 'ready' : 'active';
        redirect('modules/work_orders/workspace?department=' . urlencode($departmentSlug) . '&tab=' . $tab);
    } catch (Throwable $exception) {
        $_SESSION['error'] = $exception->getMessage();
        redirect('modules/work_orders/receive?progress_id=' . $progressId);
    }
}

include '../../includes/header.php';
?>

<div class="mb-6">
    <a href="workspace?department=<?php echo urlencode($departmentSlug); ?>&tab=incoming"
        class="text-indigo-600 hover:underline inline-flex items-center text-sm">
        <i data-lucide="arrow-left" class="mr-1 inline-block h-4 w-4" aria-hidden="true"></i>
        Back to <?php echo htmlspecialchars($context['department_name']); ?> workspace
    </a>
</div>

<?php if (!$canReceive): ?>
    <div class="max-w-2xl mx-auto bg-white shadow rounded-xl p-8 text-center">
        <h1 class="text-2xl font-bold text-gray-800 mb-2">Already received</h1>
        <p class="text-gray-600 mb-6">This work order is already marked as <?php echo htmlspecialchars($context['status']); ?>.</p>
        <a href="workspace?department=<?php echo urlencode($departmentSlug); ?>&tab=active"
            class="inline-flex items-center gap-2 bg-indigo-600 text-white px-5 py-3 rounded-lg hover:bg-indigo-700 transition">
            Return to workspace
        </a>
    </div>
<?php else: ?>
    <div class="max-w-2xl mx-auto">
        <div class="mb-6">
            <h1 class="text-3xl font-bold text-gray-800">Receive work order</h1>
            <p class="text-sm text-gray-500 mt-1">
                <?php echo htmlspecialchars($context['work_order_number']); ?> ·
                <?php echo htmlspecialchars($context['customer_name'] ?: 'Customer'); ?>
            </p>
        </div>

        <div class="bg-white shadow rounded-xl p-6 mb-6">
            <p class="text-sm text-gray-600"><?php echo htmlspecialchars($context['job_description'] ?: 'No description'); ?></p>
        </div>

        <form method="POST" action="receive" class="bg-white shadow rounded-xl p-6 space-y-5">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token('work_order_receive')); ?>">
            <input type="hidden" name="progress_id" value="<?php echo (int) $progressId; ?>">

            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-2">Quantity received</label>
                <input type="number" name="received_quantity" min="0" step="1"
                    value="<?php echo htmlspecialchars((string) ($context['quantity'] ?? '')); ?>"
                    class="w-full px-4 py-3 border border-gray-300 rounded-lg" required>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-2">Received by</label>
                    <input type="text" value="<?php echo htmlspecialchars($receiverName); ?>" readonly
                        class="w-full px-4 py-3 border border-gray-200 rounded-lg bg-gray-50 text-gray-700">
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-2">Time</label>
                    <input type="text" value="<?php echo htmlspecialchars(date('Y-m-d H:i')); ?>" readonly
                        class="w-full px-4 py-3 border border-gray-200 rounded-lg bg-gray-50 text-gray-700">
                </div>
            </div>

            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-2">Extra notes <span class="font-normal text-gray-400">(optional)</span></label>
                <textarea name="receive_notes" rows="3" placeholder="Condition, packaging, special instructions..."
                    class="w-full px-4 py-3 border border-gray-300 rounded-lg"></textarea>
            </div>

            <button type="submit"
                class="w-full bg-blue-600 hover:bg-blue-700 text-white font-bold py-3 px-4 rounded-lg transition">
                Confirm receipt
            </button>
        </form>
    </div>
<?php endif; ?>

<?php include '../../includes/footer.php'; ?>
