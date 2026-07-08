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

$departmentSlug = trim((string) ($_GET['department'] ?? 'origination'));
$tab = trim((string) ($_GET['tab'] ?? 'incoming'));
if (!in_array($tab, ['incoming', 'active', 'ready', 'sent'], true)) {
    $tab = 'incoming';
}

$filters = [
    'search' => trim((string) ($_GET['search'] ?? '')),
    'priority' => trim((string) ($_GET['priority'] ?? '')),
    'date_from' => trim((string) ($_GET['date_from'] ?? '')),
    'date_to' => trim((string) ($_GET['date_to'] ?? '')),
    'sort' => trim((string) ($_GET['sort'] ?? 'due_date')),
    'direction' => trim((string) ($_GET['direction'] ?? 'ASC')),
];

$departments = work_order_safe_fetch($pdo, "SELECT * FROM production_departments WHERE is_active = 1 ORDER BY default_order ASC");
$currentDepartment = null;
foreach ($departments as $department) {
    if ($department['slug'] === $departmentSlug) {
        $currentDepartment = $department;
        break;
    }
}
if (!$currentDepartment && !empty($departments)) {
    $currentDepartment = $departments[0];
    $departmentSlug = (string) $currentDepartment['slug'];
}

$workflowMode = (string) ($currentDepartment['workflow_mode'] ?? work_order_department_workflow_mode($departmentSlug, $currentDepartment));
$queueItems = work_order_fetch_department_queue($pdo, $departmentSlug, $tab, $filters);

$tabCounts = [
    'incoming' => count(work_order_fetch_department_queue($pdo, $departmentSlug, 'incoming', $filters)),
    'active' => count(work_order_fetch_department_queue($pdo, $departmentSlug, 'active', $filters)),
    'ready' => count(work_order_fetch_department_queue($pdo, $departmentSlug, 'ready', $filters)),
    'sent' => count(work_order_fetch_department_queue($pdo, $departmentSlug, 'sent', $filters)),
];

$secondaryActionLabels = [
    'hold' => 'Put on hold',
    'return' => 'Return job',
];

$filterQuery = http_build_query(array_filter([
    'department' => $departmentSlug,
    'tab' => $tab,
    'search' => $filters['search'] !== '' ? $filters['search'] : null,
    'priority' => $filters['priority'] !== '' ? $filters['priority'] : null,
    'date_from' => $filters['date_from'] !== '' ? $filters['date_from'] : null,
    'date_to' => $filters['date_to'] !== '' ? $filters['date_to'] : null,
    'sort' => $filters['sort'] !== 'due_date' ? $filters['sort'] : null,
    'direction' => $filters['direction'] !== 'ASC' ? $filters['direction'] : null,
]));

include '../../includes/header.php';
?>

<style>
    .wo-step-track { display: flex; align-items: center; gap: 0.35rem; flex-wrap: wrap; }
    .wo-step-dot {
        width: 0.55rem; height: 0.55rem; border-radius: 9999px; background: #d1d5db; flex-shrink: 0;
    }
    .wo-step-dot.is-done { background: #16a34a; }
    .wo-step-dot.is-current { background: #4f46e5; box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.2); }
    .wo-step-label { font-size: 0.7rem; color: #6b7280; font-weight: 600; text-transform: uppercase; letter-spacing: 0.03em; }
    .wo-step-label.is-current { color: #4338ca; }
    .wo-step-label.is-done { color: #15803d; }
    .wo-primary-btn {
        display: flex; align-items: center; justify-content: center; gap: 0.5rem;
        width: 100%; min-height: 3rem; padding: 0.75rem 1rem;
        border-radius: 0.75rem; color: #fff; font-size: 0.95rem; font-weight: 700;
        transition: background-color 0.15s ease, transform 0.15s ease;
        box-shadow: 0 1px 2px rgba(15, 23, 42, 0.08);
    }
    .wo-primary-btn:hover { transform: translateY(-1px); }
    .wo-job-card { border: 1px solid #e5e7eb; border-radius: 1rem; padding: 1.25rem; background: #fff; }
    .wo-job-card.is-ready { border-color: #86efac; background: linear-gradient(180deg, #f0fdf4 0%, #fff 4rem); }
    .wo-receive-chip {
        display: inline-flex; align-items: center; gap: 0.35rem;
        font-size: 0.75rem; color: #374151; background: #f3f4f6;
        border-radius: 9999px; padding: 0.25rem 0.65rem;
    }
</style>

<div class="list-toolbar mb-6">
    <div class="min-w-0">
        <h1 class="text-3xl font-bold text-gray-800 break-words"><?php echo htmlspecialchars($currentDepartment['name'] ?? 'Department'); ?> Workspace</h1>
        <p class="text-sm text-gray-500 mt-1">
            <?php if ($workflowMode === 'routing'): ?>
                Record incoming jobs and designate where each work order should go next.
            <?php else: ?>
                Receive jobs, track production progress, and designate the next section when work is complete.
            <?php endif; ?>
        </p>
    </div>
    <div class="list-toolbar-actions">
        <a href="list" class="list-action-btn bg-indigo-600 text-white">
            <i data-lucide="clipboard-list" class="sm:mr-1 inline-block h-5 w-5" aria-hidden="true"></i>
            <span class="hidden sm:inline">All work orders</span>
        </a>
    </div>
</div>

<?php if (!empty($_SESSION['success'])): ?>
    <div class="bg-green-50 border border-green-200 text-green-800 rounded-lg p-4 mb-4"><?php echo htmlspecialchars((string) $_SESSION['success']); unset($_SESSION['success']); ?></div>
<?php endif; ?>
<?php if (!empty($_SESSION['error'])): ?>
    <div class="bg-red-50 border border-red-200 text-red-800 rounded-lg p-4 mb-4"><?php echo htmlspecialchars((string) $_SESSION['error']); unset($_SESSION['error']); ?></div>
<?php endif; ?>

<div class="bg-white shadow rounded-lg p-4 mb-4">
    <form method="GET" class="flex flex-col md:flex-row gap-3">
        <select name="department" class="flex-1 px-4 py-3 border border-gray-300 rounded-lg" onchange="this.form.submit()">
            <?php foreach ($departments as $department): ?>
                <option value="<?php echo htmlspecialchars($department['slug']); ?>" <?php echo $departmentSlug === $department['slug'] ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($department['name']); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <input type="hidden" name="tab" value="<?php echo htmlspecialchars($tab); ?>">
        <?php foreach ($filters as $key => $value): ?>
            <?php if ($value !== '' && !in_array($key, ['sort', 'direction'], true) || ($key === 'sort' && $value !== 'due_date') || ($key === 'direction' && $value !== 'ASC')): ?>
                <input type="hidden" name="<?php echo htmlspecialchars($key); ?>" value="<?php echo htmlspecialchars($value); ?>">
            <?php endif; ?>
        <?php endforeach; ?>
    </form>
</div>

<div class="bg-white shadow rounded-lg p-4 mb-6 wo-workspace-filters">
    <form method="GET" class="grid grid-cols-1 md:grid-cols-12 gap-3">
        <input type="hidden" name="department" value="<?php echo htmlspecialchars($departmentSlug); ?>">
        <input type="hidden" name="tab" value="<?php echo htmlspecialchars($tab); ?>">
        <div class="md:col-span-3">
            <input type="text" name="search" value="<?php echo htmlspecialchars($filters['search']); ?>"
                placeholder="Search WO#, customer, job..." class="w-full px-3 py-2.5 border border-gray-300 rounded-lg text-sm">
        </div>
        <div class="md:col-span-2">
            <select name="priority" class="w-full px-3 py-2.5 border border-gray-300 rounded-lg text-sm">
                <option value="">All priorities</option>
                <?php foreach (['Normal', 'Urgent', 'Critical'] as $priority): ?>
                    <option value="<?php echo $priority; ?>" <?php echo $filters['priority'] === $priority ? 'selected' : ''; ?>><?php echo $priority; ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="md:col-span-2">
            <input type="date" name="date_from" value="<?php echo htmlspecialchars($filters['date_from']); ?>"
                class="w-full px-3 py-2.5 border border-gray-300 rounded-lg text-sm" title="From date">
        </div>
        <div class="md:col-span-2">
            <input type="date" name="date_to" value="<?php echo htmlspecialchars($filters['date_to']); ?>"
                class="w-full px-3 py-2.5 border border-gray-300 rounded-lg text-sm" title="To date">
        </div>
        <div class="md:col-span-1">
            <select name="sort" class="w-full px-3 py-2.5 border border-gray-300 rounded-lg text-sm">
                <option value="due_date" <?php echo $filters['sort'] === 'due_date' ? 'selected' : ''; ?>>Due date</option>
                <option value="work_order_number" <?php echo $filters['sort'] === 'work_order_number' ? 'selected' : ''; ?>>WO number</option>
                <option value="customer_name" <?php echo $filters['sort'] === 'customer_name' ? 'selected' : ''; ?>>Customer</option>
                <option value="received_at" <?php echo $filters['sort'] === 'received_at' ? 'selected' : ''; ?>>Received</option>
                <option value="created_at" <?php echo $filters['sort'] === 'created_at' ? 'selected' : ''; ?>>Created</option>
            </select>
        </div>
        <div class="md:col-span-1">
            <select name="direction" class="w-full px-3 py-2.5 border border-gray-300 rounded-lg text-sm">
                <option value="ASC" <?php echo $filters['direction'] === 'ASC' ? 'selected' : ''; ?>>Asc</option>
                <option value="DESC" <?php echo $filters['direction'] === 'DESC' ? 'selected' : ''; ?>>Desc</option>
            </select>
        </div>
        <div class="md:col-span-1 flex gap-2">
            <button type="submit" class="flex-1 bg-indigo-600 text-white px-3 py-2.5 rounded-lg text-sm hover:bg-indigo-700">Apply</button>
        </div>
    </form>
</div>

<div class="flex flex-wrap gap-2 mb-6">
    <?php
    $tabs = [
        'incoming' => 'Incoming',
        'active' => 'In progress',
        'ready' => $workflowMode === 'routing' ? 'Ready to designate' : 'Ready to send',
        'sent' => 'Sent out',
    ];
    foreach ($tabs as $tabKey => $tabLabel):
        $isReadyTab = $tabKey === 'ready';
        $tabHref = 'workspace?' . http_build_query(array_merge(
            array_filter([
                'department' => $departmentSlug,
                'search' => $filters['search'] !== '' ? $filters['search'] : null,
                'priority' => $filters['priority'] !== '' ? $filters['priority'] : null,
                'date_from' => $filters['date_from'] !== '' ? $filters['date_from'] : null,
                'date_to' => $filters['date_to'] !== '' ? $filters['date_to'] : null,
                'sort' => $filters['sort'] !== 'due_date' ? $filters['sort'] : null,
                'direction' => $filters['direction'] !== 'ASC' ? $filters['direction'] : null,
            ]),
            ['tab' => $tabKey]
        ));
    ?>
        <a href="<?php echo htmlspecialchars($tabHref); ?>"
            class="px-4 py-2 rounded-lg text-sm font-semibold transition <?php echo $tab === $tabKey
                ? ($isReadyTab ? 'bg-emerald-600 text-white' : 'bg-indigo-600 text-white')
                : ($isReadyTab && $tabCounts['ready'] > 0 ? 'bg-emerald-50 text-emerald-800 border border-emerald-200 hover:bg-emerald-100' : 'bg-white text-gray-700 border border-gray-200 hover:bg-gray-50'); ?>">
            <?php echo htmlspecialchars($tabLabel); ?> (<?php echo (int) $tabCounts[$tabKey]; ?>)
        </a>
    <?php endforeach; ?>
</div>

<?php if ($tab === 'incoming' && $tabCounts['incoming'] > 0): ?>
    <div class="bg-blue-50 border border-blue-200 rounded-xl p-4 mb-6 text-sm text-blue-900">
        <strong>Incoming jobs</strong> — open each job and record receipt with quantity, receiver, and notes.
    </div>
<?php endif; ?>

<?php if ($tab === 'ready' && $tabCounts['ready'] > 0): ?>
    <div class="bg-emerald-50 border border-emerald-200 rounded-xl p-4 mb-6 text-sm text-emerald-900">
        <strong><?php echo $workflowMode === 'routing' ? 'Ready to designate' : 'Ready to send'; ?></strong>
        — choose the next section and confirm the handoff.
    </div>
<?php endif; ?>

<div class="space-y-4">
    <?php foreach ($queueItems as $item): ?>
        <?php
        $progressStatus = (string) $item['progress_status'];
        $primary = work_order_primary_workspace_action(
            $progressStatus,
            false,
            null,
            $workflowMode,
            (int) $item['progress_id'],
            (int) $item['work_order_id'],
            $departmentSlug
        );
        $secondary = array_diff(work_order_allowed_queue_actions($progressStatus, false, $workflowMode), [$primary['action'] ?? '']);
        $steps = work_order_workspace_steps($progressStatus, $workflowMode);
        $isReady = ($primary['type'] ?? '') === 'handoff';
        ?>
        <article class="wo-job-card shadow-sm <?php echo $isReady ? 'is-ready' : ''; ?>">
            <div class="flex flex-col lg:flex-row lg:items-start gap-5">
                <div class="flex-1 min-w-0">
                    <div class="flex flex-wrap items-center gap-2 mb-2">
                        <a href="view?id=<?php echo (int) $item['work_order_id']; ?>" class="text-lg font-bold text-indigo-600 hover:underline">
                            <?php echo htmlspecialchars($item['work_order_number']); ?>
                        </a>
                        <span class="px-2 py-1 text-xs rounded-full font-semibold <?php echo work_order_queue_badge_class($progressStatus); ?>">
                            <?php echo htmlspecialchars($progressStatus); ?>
                        </span>
                        <span class="text-xs text-gray-400">Step <?php echo (int) $item['sequence_no']; ?> · Due <?php echo htmlspecialchars($item['due_date'] ?: '—'); ?></span>
                    </div>

                    <p class="font-semibold text-gray-900"><?php echo htmlspecialchars($item['customer_name'] ?: '—'); ?></p>
                    <p class="text-sm text-gray-600 mt-1"><?php echo htmlspecialchars($item['job_description'] ?: 'No description'); ?></p>

                    <?php if (!empty($item['received_at'])): ?>
                        <div class="mt-3 flex flex-wrap gap-2">
                            <span class="wo-receive-chip">
                                Received <?php echo htmlspecialchars(date('M j, H:i', strtotime($item['received_at']))); ?>
                            </span>
                            <?php if (!empty($item['received_by_name'])): ?>
                                <span class="wo-receive-chip">By <?php echo htmlspecialchars($item['received_by_name']); ?></span>
                            <?php endif; ?>
                            <?php if (!empty($item['received_quantity'])): ?>
                                <span class="wo-receive-chip">Qty <?php echo (int) $item['received_quantity']; ?></span>
                            <?php endif; ?>
                        </div>
                        <?php if (!empty($item['receive_notes'])): ?>
                            <p class="text-xs text-gray-500 mt-2"><?php echo htmlspecialchars($item['receive_notes']); ?></p>
                        <?php endif; ?>
                    <?php endif; ?>

                    <?php if (!empty($item['designated_next_department_name']) && $tab === 'sent'): ?>
                        <p class="text-sm text-gray-500 mt-3">
                            Sent to <strong class="text-gray-800"><?php echo htmlspecialchars($item['designated_next_department_name']); ?></strong>
                        </p>
                    <?php endif; ?>

                    <div class="mt-4 wo-step-track" aria-label="Production progress">
                        <?php foreach ($steps as $index => $step): ?>
                            <?php if ($index > 0): ?><span class="text-gray-300">→</span><?php endif; ?>
                            <span class="inline-flex items-center gap-1.5">
                                <span class="wo-step-dot is-<?php echo htmlspecialchars($step['state']); ?>"></span>
                                <span class="wo-step-label is-<?php echo htmlspecialchars($step['state']); ?>"><?php echo htmlspecialchars($step['label']); ?></span>
                            </span>
                        <?php endforeach; ?>
                    </div>

                    <div class="flex flex-wrap gap-3 mt-4 text-sm">
                        <a href="department_edit?department=<?php echo urlencode($departmentSlug); ?>&id=<?php echo (int) $item['work_order_id']; ?>" class="text-indigo-600 hover:underline">
                            <?php echo $workflowMode === 'routing' ? 'Edit origination record' : 'Edit section fields'; ?>
                        </a>
                        <a href="view?id=<?php echo (int) $item['work_order_id']; ?>" class="text-indigo-600 hover:underline">View full work order</a>
                    </div>

                    <?php if ($primary && ($primary['type'] ?? '') === 'handoff'): ?>
                        <div class="mt-5 pt-4 border-t border-emerald-100">
                            <a href="handoff?progress_id=<?php echo (int) ($primary['progress_id'] ?? $item['progress_id']); ?>"
                                class="wo-primary-btn <?php echo htmlspecialchars($primary['button_class']); ?>">
                                <i data-lucide="send" class="h-5 w-5" aria-hidden="true"></i>
                                <?php echo htmlspecialchars($primary['label']); ?>
                            </a>
                            <p class="text-xs text-gray-500 mt-2"><?php echo htmlspecialchars($primary['description']); ?></p>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="w-full lg:w-72 flex-shrink-0">
                    <?php if ($primary && ($primary['type'] ?? '') !== 'handoff'): ?>
                        <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 mb-2">Do this now</p>
                        <?php if ($primary['type'] === 'receive_page'): ?>
                            <a href="receive?progress_id=<?php echo (int) ($primary['progress_id'] ?? $item['progress_id']); ?>"
                                class="wo-primary-btn <?php echo htmlspecialchars($primary['button_class']); ?>">
                                <?php echo htmlspecialchars($primary['label']); ?>
                            </a>
                        <?php else: ?>
                            <form method="POST" action="queue_action">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token('work_order_queue_action')); ?>">
                                <input type="hidden" name="progress_id" value="<?php echo (int) $item['progress_id']; ?>">
                                <input type="hidden" name="redirect_department" value="<?php echo htmlspecialchars($departmentSlug); ?>">
                                <input type="hidden" name="redirect_tab" value="<?php echo htmlspecialchars($tab); ?>">
                                <button type="submit" name="action" value="<?php echo htmlspecialchars($primary['action']); ?>"
                                    class="wo-primary-btn <?php echo htmlspecialchars($primary['button_class']); ?>">
                                    <?php echo htmlspecialchars($primary['label']); ?>
                                </button>
                            </form>
                        <?php endif; ?>
                        <p class="text-xs text-gray-500 mt-2"><?php echo htmlspecialchars($primary['description']); ?></p>

                        <?php if (!empty($secondary)): ?>
                            <form method="POST" action="queue_action" class="mt-3 pt-3 border-t border-gray-100 space-y-2">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token('work_order_queue_action')); ?>">
                                <input type="hidden" name="progress_id" value="<?php echo (int) $item['progress_id']; ?>">
                                <input type="hidden" name="redirect_department" value="<?php echo htmlspecialchars($departmentSlug); ?>">
                                <input type="hidden" name="redirect_tab" value="<?php echo htmlspecialchars($tab); ?>">
                                <div class="flex flex-wrap gap-2">
                                    <?php foreach ($secondary as $actionKey): ?>
                                        <button type="submit" name="action" value="<?php echo htmlspecialchars($actionKey); ?>"
                                            class="text-xs px-2 py-1 rounded border border-gray-200 text-gray-600 hover:bg-gray-50">
                                            <?php echo htmlspecialchars($secondaryActionLabels[$actionKey] ?? ucfirst($actionKey)); ?>
                                        </button>
                                    <?php endforeach; ?>
                                </div>
                                <?php if (in_array('hold', $secondary, true)): ?>
                                    <input type="text" name="hold_reason" placeholder="Hold reason (required for hold)" class="w-full px-2 py-1.5 text-xs border border-gray-300 rounded-lg">
                                <?php endif; ?>
                            </form>
                        <?php endif; ?>
                    <?php elseif ($primary && ($primary['type'] ?? '') === 'handoff' && !empty($secondary)): ?>
                        <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 mb-2">Other actions</p>
                        <form method="POST" action="queue_action" class="space-y-2">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token('work_order_queue_action')); ?>">
                            <input type="hidden" name="progress_id" value="<?php echo (int) $item['progress_id']; ?>">
                            <input type="hidden" name="redirect_department" value="<?php echo htmlspecialchars($departmentSlug); ?>">
                            <input type="hidden" name="redirect_tab" value="<?php echo htmlspecialchars($tab); ?>">
                            <div class="flex flex-wrap gap-2">
                                <?php foreach ($secondary as $actionKey): ?>
                                    <button type="submit" name="action" value="<?php echo htmlspecialchars($actionKey); ?>"
                                        class="text-xs px-2 py-1 rounded border border-gray-200 text-gray-600 hover:bg-gray-50">
                                        <?php echo htmlspecialchars($secondaryActionLabels[$actionKey] ?? ucfirst($actionKey)); ?>
                                    </button>
                                <?php endforeach; ?>
                            </div>
                            <?php if (in_array('hold', $secondary, true)): ?>
                                <input type="text" name="hold_reason" placeholder="Hold reason (required for hold)" class="w-full px-2 py-1.5 text-xs border border-gray-300 rounded-lg">
                            <?php endif; ?>
                        </form>
                    <?php elseif ($tab === 'sent' || $progressStatus === 'Dispatched'): ?>
                        <div class="rounded-lg bg-green-50 border border-green-200 p-4 text-center">
                            <i data-lucide="check-circle" class="h-8 w-8 text-green-600 mx-auto mb-2" aria-hidden="true"></i>
                            <p class="font-semibold text-green-800">Sent</p>
                            <?php if (!empty($item['designated_next_department_name'])): ?>
                                <p class="text-xs text-green-700 mt-1">Designated to <?php echo htmlspecialchars($item['designated_next_department_name']); ?></p>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </article>
    <?php endforeach; ?>

    <?php if (empty($queueItems)): ?>
        <div class="bg-white shadow rounded-xl p-12 text-center text-gray-500">
            <?php if ($tab === 'ready'): ?>
                No jobs waiting to send.
                <?php if ($workflowMode !== 'routing'): ?>
                    Mark jobs complete in <a href="workspace?department=<?php echo urlencode($departmentSlug); ?>&tab=active" class="text-indigo-600 hover:underline">In progress</a> first.
                <?php endif; ?>
            <?php elseif ($tab === 'incoming'): ?>
                No incoming jobs for <?php echo htmlspecialchars($currentDepartment['name']); ?> right now.
            <?php else: ?>
                No work orders in this list.
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<?php include '../../includes/footer.php'; ?>
