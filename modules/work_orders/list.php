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

$search = trim((string) ($_GET['search'] ?? ''));
$statusFilter = trim((string) ($_GET['status'] ?? ''));
$departmentFilter = trim((string) ($_GET['department'] ?? ''));
$priorityFilter = trim((string) ($_GET['priority'] ?? ''));

$query = "
    SELECT wo.*, i.invoice_number, i.balance, e.estimation_number,
           pd.name AS current_department_name, pd.slug AS current_department_slug
    FROM work_orders wo
    INNER JOIN invoices i ON wo.invoice_id = i.id
    LEFT JOIN estimations e ON wo.estimation_id = e.id
    LEFT JOIN production_departments pd ON wo.current_department_id = pd.id
    WHERE 1=1
";
$params = [];

if ($search !== '') {
    $query .= " AND (
        wo.work_order_number LIKE :search
        OR wo.customer_name LIKE :search
        OR wo.job_description LIKE :search
        OR i.invoice_number LIKE :search
        OR e.estimation_number LIKE :search
    )";
    $params['search'] = '%' . $search . '%';
}

if ($statusFilter !== '') {
    $query .= " AND wo.status = :status";
    $params['status'] = $statusFilter;
}

if ($departmentFilter !== '') {
    $query .= " AND pd.slug = :department_slug";
    $params['department_slug'] = $departmentFilter;
}

if ($priorityFilter !== '') {
    $query .= " AND wo.priority = :priority";
    $params['priority'] = $priorityFilter;
}

$query .= " ORDER BY COALESCE(wo.due_date, '2999-12-31') ASC, wo.created_at DESC";
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$workOrders = $stmt->fetchAll();

$departmentStmt = $pdo->query("SELECT slug, name FROM production_departments WHERE is_active = 1 ORDER BY default_order ASC");
$departments = $departmentStmt->fetchAll();

include '../../includes/header.php';
?>

<div class="list-toolbar mb-6">
    <div class="min-w-0">
        <h1 class="text-3xl font-bold text-gray-800 break-words">Work Orders</h1>
        <p class="text-sm text-gray-500 mt-1">Track jobs from costing through origination, production sections, and dispatch.</p>
    </div>
    <div class="list-toolbar-actions">
        <a href="dashboard" class="list-action-btn bg-slate-700 text-white">
            <i data-lucide="layout-dashboard" class="sm:mr-1 inline-block h-5 w-5" aria-hidden="true"></i>
            <span class="hidden sm:inline">Dashboard</span>
        </a>
        <?php if (hasPermission('manage_production_queues') || hasPermission('manage_work_orders')): ?>
            <a href="workspace" class="list-action-btn bg-emerald-600 text-white">
                <i data-lucide="layout-grid" class="sm:mr-1 inline-block h-5 w-5" aria-hidden="true"></i>
                <span class="hidden sm:inline">Workspace</span>
            </a>
        <?php endif; ?>
    </div>
</div>

<?php if (!empty($_SESSION['success'])): ?>
    <div class="bg-green-50 border border-green-200 text-green-800 rounded-lg p-4 mb-4">
        <?php echo htmlspecialchars((string) $_SESSION['success']); unset($_SESSION['success']); ?>
    </div>
<?php endif; ?>
<?php if (!empty($_SESSION['error'])): ?>
    <div class="bg-red-50 border border-red-200 text-red-800 rounded-lg p-4 mb-4">
        <?php echo htmlspecialchars((string) $_SESSION['error']); unset($_SESSION['error']); ?>
    </div>
<?php endif; ?>

<div class="bg-white shadow rounded-lg p-6 mb-6">
    <form method="GET" class="grid grid-cols-1 md:grid-cols-12 gap-4">
        <div class="md:col-span-5">
            <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search work order, invoice, estimation, customer..." class="w-full px-4 py-3 border border-gray-300 rounded-lg">
        </div>
        <div class="md:col-span-2">
            <select name="status" class="w-full px-4 py-3 border border-gray-300 rounded-lg">
                <option value="">All statuses</option>
                <?php foreach (WorkOrderStatusManager::getAllStatuses() as $status): ?>
                    <option value="<?php echo htmlspecialchars($status); ?>" <?php echo $statusFilter === $status ? 'selected' : ''; ?>><?php echo htmlspecialchars($status); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="md:col-span-2">
            <select name="department" class="w-full px-4 py-3 border border-gray-300 rounded-lg">
                <option value="">All departments</option>
                <?php foreach ($departments as $department): ?>
                    <option value="<?php echo htmlspecialchars($department['slug']); ?>" <?php echo $departmentFilter === $department['slug'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($department['name']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="md:col-span-1">
            <select name="priority" class="w-full px-4 py-3 border border-gray-300 rounded-lg">
                <option value="">Priority</option>
                <?php foreach (['Normal', 'Urgent', 'Critical'] as $priority): ?>
                    <option value="<?php echo $priority; ?>" <?php echo $priorityFilter === $priority ? 'selected' : ''; ?>><?php echo $priority; ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="md:col-span-2 flex gap-2">
            <button type="submit" class="flex-1 bg-indigo-600 text-white px-4 py-3 rounded-lg hover:bg-indigo-700 transition">Filter</button>
            <a href="list" class="bg-gray-200 text-gray-700 px-4 py-3 rounded-lg hover:bg-gray-300 transition">Clear</a>
        </div>
    </form>
</div>

<div class="list-view-shell">
    <div class="hidden md:block overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Work Order</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Customer</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">References</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Current Department</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Priority</th>
                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Due Date</th>
                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Actions</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                <?php foreach ($workOrders as $wo): ?>
                    <?php
                    $deptSlug = (string) ($wo['current_department_slug'] ?? '');
                    $canQueue = $deptSlug !== '' && (hasPermission('manage_production_queues') || hasPermission('manage_work_orders'));
                    $canSendOrigination = hasPermission('manage_work_orders') && work_order_can_send_to_origination($wo);
                    ?>
                    <tr class="hover:bg-gray-50">
                        <td class="px-6 py-4">
                            <a href="view?id=<?php echo (int) $wo['id']; ?>" class="font-semibold text-indigo-600 hover:underline">
                                <?php echo htmlspecialchars($wo['work_order_number']); ?>
                            </a>
                            <div class="text-xs text-gray-500"><?php echo htmlspecialchars(date('M j, Y', strtotime($wo['created_at']))); ?></div>
                        </td>
                        <td class="px-6 py-4 text-sm text-gray-800"><?php echo htmlspecialchars($wo['customer_name'] ?: '—'); ?></td>
                        <td class="px-6 py-4 text-sm text-gray-600">
                            <div>Invoice: <?php echo htmlspecialchars($wo['invoice_number']); ?></div>
                            <div>Estimation: <?php echo htmlspecialchars($wo['estimation_number'] ?: '—'); ?></div>
                        </td>
                        <td class="px-6 py-4 text-sm text-gray-700">
                            <?php if ($canQueue): ?>
                                <a href="workspace?department=<?php echo urlencode($deptSlug); ?>" class="text-indigo-600 hover:underline">
                                    <?php echo htmlspecialchars($wo['current_department_name']); ?>
                                </a>
                            <?php else: ?>
                                <?php echo htmlspecialchars($wo['current_department_name'] ?: ($wo['status'] === 'Draft' ? 'Costing (awaiting send)' : 'Not routed yet')); ?>
                            <?php endif; ?>
                        </td>
                        <td class="px-6 py-4">
                            <span class="px-2 py-1 inline-flex text-xs rounded-full font-semibold <?php echo work_order_status_badge_class((string) $wo['status']); ?>">
                                <?php echo htmlspecialchars($wo['status']); ?>
                            </span>
                        </td>
                        <td class="px-6 py-4 text-sm text-gray-700"><?php echo htmlspecialchars($wo['priority']); ?></td>
                        <td class="px-6 py-4 text-right text-sm text-gray-700"><?php echo htmlspecialchars($wo['due_date'] ?: '—'); ?></td>
                        <td class="px-6 py-4">
                            <div class="flex justify-end gap-2">
                                <a href="view?id=<?php echo (int) $wo['id']; ?>" class="list-icon-action bg-indigo-600 text-white" title="Open work order" aria-label="Open work order">
                                    <i data-lucide="eye" class="h-4 w-4" aria-hidden="true"></i>
                                </a>
                                <?php if ($canQueue): ?>
                                    <a href="workspace?department=<?php echo urlencode($deptSlug); ?>" class="list-icon-action bg-emerald-600 text-white" title="Open department queue" aria-label="Open department queue">
                                        <i data-lucide="layout-grid" class="h-4 w-4" aria-hidden="true"></i>
                                    </a>
                                <?php endif; ?>
                                <?php if ($canSendOrigination): ?>
                                    <form method="POST" action="send_to_origination" class="inline">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token('work_order_send_origination')); ?>">
                                        <input type="hidden" name="work_order_id" value="<?php echo (int) $wo['id']; ?>">
                                        <button type="submit" class="list-icon-action bg-amber-500 text-white" title="Send to Origination" aria-label="Send to Origination">
                                            <i data-lucide="send" class="h-4 w-4" aria-hidden="true"></i>
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($workOrders)): ?>
                    <tr>
                        <td colspan="8" class="px-6 py-12 text-center text-gray-500">No work orders found.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <div class="list-mobile-stack md:hidden">
        <?php foreach ($workOrders as $wo): ?>
            <?php
            $deptSlug = (string) ($wo['current_department_slug'] ?? '');
            $canQueue = $deptSlug !== '' && (hasPermission('manage_production_queues') || hasPermission('manage_work_orders'));
            $canSendOrigination = hasPermission('manage_work_orders') && work_order_can_send_to_origination($wo);
            ?>
            <div class="list-mobile-card">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <p class="list-card-title">
                            <a href="view?id=<?php echo (int) $wo['id']; ?>" class="text-indigo-600 hover:underline">
                                <?php echo htmlspecialchars($wo['work_order_number']); ?>
                            </a>
                        </p>
                        <p class="text-sm text-gray-500 mt-1"><?php echo htmlspecialchars($wo['customer_name'] ?: '—'); ?></p>
                    </div>
                    <span class="px-2 py-1 inline-flex text-xs rounded-full font-semibold shrink-0 <?php echo work_order_status_badge_class((string) $wo['status']); ?>">
                        <?php echo htmlspecialchars($wo['status']); ?>
                    </span>
                </div>
                <div class="grid grid-cols-2 gap-3 mt-3 text-sm">
                    <div>
                        <p class="list-card-meta">Department</p>
                        <p class="list-card-value"><?php echo htmlspecialchars($wo['current_department_name'] ?: ($wo['status'] === 'Draft' ? 'Costing' : 'Not routed')); ?></p>
                    </div>
                    <div>
                        <p class="list-card-meta">Due</p>
                        <p class="list-card-value"><?php echo htmlspecialchars($wo['due_date'] ?: '—'); ?></p>
                    </div>
                    <div>
                        <p class="list-card-meta">Invoice</p>
                        <p class="list-card-value"><?php echo htmlspecialchars($wo['invoice_number']); ?></p>
                    </div>
                    <div>
                        <p class="list-card-meta">Priority</p>
                        <p class="list-card-value"><?php echo htmlspecialchars($wo['priority']); ?></p>
                    </div>
                </div>
                <div class="list-row-actions <?php echo $canSendOrigination ? '' : 'two-up'; ?> mt-4">
                    <a href="view?id=<?php echo (int) $wo['id']; ?>" class="list-icon-action bg-indigo-600 text-white" aria-label="Open work order">
                        <i data-lucide="eye" class="h-4 w-4" aria-hidden="true"></i>
                    </a>
                    <?php if ($canQueue): ?>
                        <a href="workspace?department=<?php echo urlencode($deptSlug); ?>" class="list-icon-action bg-emerald-600 text-white" aria-label="Open department queue">
                            <i data-lucide="layout-grid" class="h-4 w-4" aria-hidden="true"></i>
                        </a>
                    <?php endif; ?>
                    <?php if ($canSendOrigination): ?>
                        <form method="POST" action="send_to_origination">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token('work_order_send_origination')); ?>">
                            <input type="hidden" name="work_order_id" value="<?php echo (int) $wo['id']; ?>">
                            <button type="submit" class="list-icon-action bg-amber-500 text-white w-full" aria-label="Send to Origination">
                                <i data-lucide="send" class="h-4 w-4" aria-hidden="true"></i>
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
        <?php if (empty($workOrders)): ?>
            <div class="list-mobile-card text-center text-gray-500 py-12">
                <i data-lucide="clipboard-list" class="mx-auto mb-2 block h-12 w-12 text-gray-400" aria-hidden="true"></i>
                No work orders found.
            </div>
        <?php endif; ?>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>
