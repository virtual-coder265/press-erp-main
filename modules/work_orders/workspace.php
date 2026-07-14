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
    'send_back' => 'Send back to sender',
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

$primaryActionIcons = [
    'handoff' => 'send',
    'dispatch_record' => 'truck',
    'receive_page' => 'inbox',
    'queue' => 'circle-play',
];

include '../../includes/header.php';
?>

<div class="list-toolbar mb-6">
    <div class="min-w-0">
        <h1 class="text-3xl font-bold text-gray-800 break-words"><?php echo htmlspecialchars($currentDepartment['name'] ?? 'Department'); ?> Workspace</h1>
        <p class="text-sm text-gray-500 mt-1">
            <?php if ($workflowMode === 'dispatch'): ?>
                Receive completed jobs, record dispatch send-off entries, or send work back to the sender when issues are found.
            <?php elseif ($workflowMode === 'routing'): ?>
                Record incoming jobs and designate where each work order should go next.
            <?php else: ?>
                Receive jobs, track production progress, and designate the next section when work is complete.
            <?php endif; ?>
        </p>
    </div>
    <div class="list-toolbar-actions">
        <a href="dashboard" class="list-action-btn bg-slate-700 text-white">
            <i data-lucide="layout-dashboard" class="sm:mr-1 inline-block h-5 w-5" aria-hidden="true"></i>
            <span class="hidden sm:inline">Dashboard</span>
        </a>
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

<div class="wo-tab-bar">
    <?php
    $tabs = [
        'incoming' => 'Incoming',
        'active' => $workflowMode === 'dispatch' ? 'On hold' : 'In progress',
        'ready' => $workflowMode === 'dispatch' ? 'Ready for dispatch' : ($workflowMode === 'routing' ? 'Ready to designate' : 'Ready to send'),
        'sent' => $workflowMode === 'dispatch' ? 'Sent back' : 'Sent out',
    ];
    foreach ($tabs as $tabKey => $tabLabel):
        $isReadyTab = $tabKey === 'ready';
        $isActive = $tab === $tabKey;
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
        $tabClass = 'wo-tab';
        if ($isReadyTab) {
            $tabClass .= ' is-ready';
        }
        if ($isActive) {
            $tabClass .= ' is-active';
        }
    ?>
        <a href="<?php echo htmlspecialchars($tabHref); ?>" class="<?php echo $tabClass; ?>">
            <?php echo htmlspecialchars($tabLabel); ?>
            <span class="wo-tab-count"><?php echo (int) $tabCounts[$tabKey]; ?></span>
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
        <?php if ($workflowMode === 'dispatch'): ?>
            <strong>Ready for pickup or delivery</strong>
            — record the dispatch send-off in the Dispatch Register, or send the job back to the sender if there are issues.
        <?php else: ?>
            <strong><?php echo $workflowMode === 'routing' ? 'Ready to designate' : 'Ready to send'; ?></strong>
            — choose the next section and confirm the handoff.
        <?php endif; ?>
    </div>
<?php endif; ?>

<div class="space-y-4">
    <?php foreach ($queueItems as $item): ?>
        <?php
        $progressStatus = (string) $item['progress_status'];
        $dispatchRegister = null;
        if ($workflowMode === 'dispatch') {
            $dispatchRegister = [
                'id' => !empty($item['dispatch_register_id']) ? (int) $item['dispatch_register_id'] : null,
                'date_out' => $item['dispatch_date_out'] ?? null,
                'delivery_note_number' => $item['dispatch_delivery_note_number'] ?? null,
            ];
        }
        $primary = work_order_primary_workspace_action(
            $progressStatus,
            false,
            null,
            $workflowMode,
            (int) $item['progress_id'],
            (int) $item['work_order_id'],
            $departmentSlug,
            $dispatchRegister
        );
        $secondary = array_diff(work_order_allowed_queue_actions($progressStatus, false, $workflowMode), [$primary['action'] ?? '']);
        $steps = work_order_workspace_steps($progressStatus, $workflowMode);
        $isReady = ($primary['type'] ?? '') === 'handoff' || ($workflowMode === 'dispatch' && ($primary['type'] ?? '') === 'dispatch_record');
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

                    <div class="wo-card-links">
                        <a href="department_edit?department=<?php echo urlencode($departmentSlug); ?>&id=<?php echo (int) $item['work_order_id']; ?>" class="wo-card-link">
                            <i data-lucide="pencil-line" class="h-4 w-4" aria-hidden="true"></i>
                            <?php echo $workflowMode === 'routing' ? 'Edit origination record' : 'Edit section fields'; ?>
                        </a>
                        <a href="view?id=<?php echo (int) $item['work_order_id']; ?>" class="wo-card-link">
                            <i data-lucide="file-text" class="h-4 w-4" aria-hidden="true"></i>
                            View full work order
                        </a>
                    </div>
                </div>

                <div class="wo-card-actions">
                    <?php if ($primary): ?>
                        <?php
                        $primaryType = (string) ($primary['type'] ?? '');
                        $primaryIcon = $primaryActionIcons[$primaryType] ?? 'circle-play';
                        if ($primaryType === 'queue') {
                            $queueIconMap = [
                                'start' => 'play',
                                'complete' => 'check-circle',
                                'resume' => 'play-circle',
                            ];
                            $primaryIcon = $queueIconMap[(string) ($primary['action'] ?? '')] ?? 'circle-play';
                        }
                        ?>
                        <p class="wo-card-actions-label"><?php echo $primaryType === 'dispatch_record' ? 'Dispatch actions' : 'Do this now'; ?></p>

                        <?php if ($primaryType === 'handoff'): ?>
                            <a href="handoff?progress_id=<?php echo (int) ($primary['progress_id'] ?? $item['progress_id']); ?>"
                                class="wo-primary-btn <?php echo htmlspecialchars($primary['button_class']); ?>">
                                <i data-lucide="<?php echo htmlspecialchars($primaryIcon); ?>" class="h-5 w-5" aria-hidden="true"></i>
                                <?php echo htmlspecialchars($primary['label']); ?>
                            </a>
                        <?php elseif ($primaryType === 'dispatch_record'): ?>
                            <?php if (!empty($primary['dispatch_id'])): ?>
                                <a href="<?php echo BASE_URL; ?>modules/dispatch/view?id=<?php echo (int) $primary['dispatch_id']; ?>"
                                    class="wo-primary-btn <?php echo htmlspecialchars($primary['button_class']); ?>">
                                    <i data-lucide="<?php echo htmlspecialchars($primaryIcon); ?>" class="h-5 w-5" aria-hidden="true"></i>
                                    <?php echo htmlspecialchars($primary['label']); ?>
                                </a>
                            <?php else: ?>
                                <a href="<?php echo BASE_URL; ?>modules/dispatch/create?work_order_id=<?php echo (int) ($primary['work_order_id'] ?? $item['work_order_id']); ?>"
                                    class="wo-primary-btn <?php echo htmlspecialchars($primary['button_class']); ?>">
                                    <i data-lucide="<?php echo htmlspecialchars($primaryIcon); ?>" class="h-5 w-5" aria-hidden="true"></i>
                                    <?php echo htmlspecialchars($primary['label']); ?>
                                </a>
                            <?php endif; ?>
                        <?php elseif ($primaryType === 'receive_page'): ?>
                            <a href="receive?progress_id=<?php echo (int) ($primary['progress_id'] ?? $item['progress_id']); ?>"
                                class="wo-primary-btn <?php echo htmlspecialchars($primary['button_class']); ?>">
                                <i data-lucide="<?php echo htmlspecialchars($primaryIcon); ?>" class="h-5 w-5" aria-hidden="true"></i>
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
                                    <i data-lucide="<?php echo htmlspecialchars($primaryIcon); ?>" class="h-5 w-5" aria-hidden="true"></i>
                                    <?php echo htmlspecialchars($primary['label']); ?>
                                </button>
                            </form>
                        <?php endif; ?>

                        <?php if (!empty($primary['description'])): ?>
                            <p class="wo-primary-desc"><?php echo htmlspecialchars($primary['description']); ?></p>
                        <?php endif; ?>

                        <?php if (!empty($secondary)): ?>
                            <form method="POST" action="queue_action" class="wo-secondary-actions">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token('work_order_queue_action')); ?>">
                                <input type="hidden" name="progress_id" value="<?php echo (int) $item['progress_id']; ?>">
                                <input type="hidden" name="redirect_department" value="<?php echo htmlspecialchars($departmentSlug); ?>">
                                <input type="hidden" name="redirect_tab" value="<?php echo htmlspecialchars($tab); ?>">
                                <?php foreach ($secondary as $actionKey): ?>
                                    <?php if ($actionKey === 'send_back'): ?>
                                        <a href="send_back?progress_id=<?php echo (int) $item['progress_id']; ?>"
                                            class="wo-secondary-btn is-danger">
                                            <i data-lucide="undo-2" class="h-3.5 w-3.5" aria-hidden="true"></i>
                                            <?php echo htmlspecialchars($secondaryActionLabels[$actionKey] ?? ucfirst($actionKey)); ?>
                                        </a>
                                    <?php else: ?>
                                        <button type="submit" name="action" value="<?php echo htmlspecialchars($actionKey); ?>"
                                            class="wo-secondary-btn">
                                            <?php echo htmlspecialchars($secondaryActionLabels[$actionKey] ?? ucfirst($actionKey)); ?>
                                        </button>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                                <?php if (in_array('hold', $secondary, true)): ?>
                                    <input type="text" name="hold_reason" placeholder="Hold reason (required)" class="wo-hold-field">
                                <?php endif; ?>
                            </form>
                        <?php endif; ?>
                    <?php elseif ($tab === 'sent' || $progressStatus === 'Dispatched'): ?>
                        <div class="wo-sent-badge">
                            <i data-lucide="check-circle" class="h-8 w-8 text-green-600 mx-auto mb-2" aria-hidden="true"></i>
                            <p class="font-semibold text-green-800"><?php echo $workflowMode === 'dispatch' ? 'Sent back' : 'Sent'; ?></p>
                            <?php if (!empty($item['designated_next_department_name'])): ?>
                                <p class="text-xs text-green-700 mt-1">
                                    <?php echo $workflowMode === 'dispatch' ? 'Returned to' : 'Designated to'; ?>
                                    <?php echo htmlspecialchars($item['designated_next_department_name']); ?>
                                </p>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <p class="wo-card-actions-label">Status</p>
                        <p class="text-sm text-gray-600">No queue action is available for this job right now.</p>
                        <?php if (!empty($secondary)): ?>
                            <form method="POST" action="queue_action" class="wo-secondary-actions">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token('work_order_queue_action')); ?>">
                                <input type="hidden" name="progress_id" value="<?php echo (int) $item['progress_id']; ?>">
                                <input type="hidden" name="redirect_department" value="<?php echo htmlspecialchars($departmentSlug); ?>">
                                <input type="hidden" name="redirect_tab" value="<?php echo htmlspecialchars($tab); ?>">
                                <?php foreach ($secondary as $actionKey): ?>
                                    <?php if ($actionKey === 'send_back'): ?>
                                        <a href="send_back?progress_id=<?php echo (int) $item['progress_id']; ?>"
                                            class="wo-secondary-btn is-danger">
                                            <i data-lucide="undo-2" class="h-3.5 w-3.5" aria-hidden="true"></i>
                                            <?php echo htmlspecialchars($secondaryActionLabels[$actionKey] ?? ucfirst($actionKey)); ?>
                                        </a>
                                    <?php else: ?>
                                        <button type="submit" name="action" value="<?php echo htmlspecialchars($actionKey); ?>"
                                            class="wo-secondary-btn">
                                            <?php echo htmlspecialchars($secondaryActionLabels[$actionKey] ?? ucfirst($actionKey)); ?>
                                        </button>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </form>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </article>
    <?php endforeach; ?>

    <?php if (empty($queueItems)): ?>
        <div class="bg-white shadow rounded-xl p-12 text-center text-gray-500">
            <?php if ($tab === 'ready'): ?>
                <?php if ($workflowMode === 'dispatch'): ?>
                    No jobs are ready for dispatch right now.
                    <?php if ($tabCounts['incoming'] > 0): ?>
                        Receive incoming jobs in the <a href="workspace?department=<?php echo urlencode($departmentSlug); ?>&tab=incoming" class="text-indigo-600 hover:underline">Incoming</a> tab first.
                    <?php endif; ?>
                <?php else: ?>
                    No jobs waiting to send.
                    <?php if ($workflowMode !== 'routing'): ?>
                        Mark jobs complete in <a href="workspace?department=<?php echo urlencode($departmentSlug); ?>&tab=active" class="text-indigo-600 hover:underline">In progress</a> first.
                    <?php endif; ?>
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
