<?php
/**
 * Dashboard partial - Tasks modal body (Summary + My Tasks tabs).
 *
 * Component id: dashboard.modal.tasks
 * Required context:
 *   - $taskSummary, $dashboardTaskSummary, $totalTasksTracked
 *   - $recentTasks, $myOverdueTaskCount
 */
?>
<div class="todo-modal-body"
     data-ajax-component="dashboard.modal.tasks"
     data-ajax-refresh-on="modal-open:wsModalTasks,action:task.create"
     data-ajax-stale="20000">
<?php if (hasPermission('view_tasks')): ?>
    <div class="todo-tabs" data-ws-tab-group="wsTasksTabs" role="tablist">
        <button type="button" class="todo-tab is-active" data-ws-tab="summary" data-ws-tab-group-ref="wsTasksTabs">Summary</button>
        <button type="button" class="todo-tab" data-ws-tab="mine" data-ws-tab-group-ref="wsTasksTabs">My Tasks</button>
    </div>

    <div class="todo-tab-panel is-active" data-ws-tab-panel="summary" data-ws-tab-group-ref="wsTasksTabs">
        <div class="dashboard-panel-card p-6">
            <div class="relative flex items-start justify-between gap-3 mb-5">
                <div>
                    <h2 class="text-lg font-bold text-gray-800 flex items-center gap-2">
                        <i data-lucide="circle-check" class="text-emerald-600 w-[1.125rem] h-[1.125rem]" aria-hidden="true"></i>
                        Task Summary
                    </h2>
                    <p class="text-xs text-gray-500 mt-1">Color-coded states with live signals for active work.</p>
                </div>
            </div>
            <div class="relative space-y-3">
                <?php
                $taskCounters = ['Pending', 'Started', 'In Progress', 'In Review', 'Completed', 'Cancelled', 'Overdue'];
                $totalTasks = array_sum($taskSummary);
                foreach ($taskCounters as $status):
                    $meta = dashboardTaskStateMeta($status);
                    $count = $dashboardTaskSummary[$status] ?? 0;
                    $pct = $totalTasks > 0 && $status !== 'Overdue' ? round(($count / $totalTasks) * 100) : null;
                ?>
                <div class="dashboard-summary-tile flex items-center justify-between gap-3"
                     style="--task-accent: <?php echo $meta['accent']; ?>; --task-soft: <?php echo $meta['soft']; ?>; --task-bg: <?php echo $meta['bg']; ?>;">
                    <div class="flex items-center gap-3 min-w-0">
                        <span class="dashboard-status-dot <?php echo $meta['pulse'] ? '' : 'is-static'; ?>"></span>
                        <div class="min-w-0">
                            <div class="flex items-center gap-2 text-sm font-semibold text-gray-800">
                                <?php echo htmlspecialchars($meta['label']); ?>
                                <i data-lucide="<?php echo htmlspecialchars($meta['icon']); ?>" class="text-sm lucide-accent-inline w-4 h-4" style="color: <?php echo $meta['accent']; ?>;"></i>
                            </div>
                            <p class="text-xs text-gray-500">
                                <?php echo $status === 'Overdue' ? 'Past due and still active' : (($pct ?? 0) . '% of all tasks'); ?>
                            </p>
                        </div>
                    </div>
                    <span class="text-lg font-bold text-gray-800"><?php echo $count; ?></span>
                </div>
                <?php endforeach; ?>
            </div>
            <div class="relative mt-5 pt-4 border-t border-slate-100 flex items-center justify-between">
                <span class="text-xs text-gray-400"><?php echo $totalTasks; ?> total tracked tasks</span>
                <a href="<?php echo BASE_URL; ?>modules/tasks/list"
                   class="text-emerald-700 text-xs font-semibold hover:underline inline-flex items-center gap-1">
                    Assigned Tasks <i data-lucide="arrow-right" class="text-xs inline-block align-middle w-3 h-3" aria-hidden="true"></i>
                </a>
            </div>
        </div>
    </div>

    <div class="todo-tab-panel" data-ws-tab-panel="mine" data-ws-tab-group-ref="wsTasksTabs">
        <div class="dashboard-panel-card p-6">
            <div class="relative flex flex-col gap-3 mb-5 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <h2 class="text-lg font-bold text-gray-800 flex items-center gap-2">
                        <i data-lucide="user-round" class="text-emerald-600 w-[1.125rem] h-[1.125rem]" aria-hidden="true"></i>
                        My Tasks
                    </h2>
                    <p class="text-xs text-gray-500 mt-1">Assigned work sorted by due date, with overdue and completion cues.</p>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <span class="dashboard-meta-chip text-slate-600">
                        <span class="dashboard-status-dot is-static" style="--task-accent:#94a3b8; --task-bg:rgba(148, 163, 184, 0.10);"></span>
                        <?php echo count($recentTasks); ?> shown
                    </span>
                    <span class="dashboard-meta-chip text-red-600">
                        <span class="dashboard-status-dot" style="--task-accent:#ef4444; --task-bg:rgba(239, 68, 68, 0.10);"></span>
                        <?php echo $myOverdueTaskCount; ?> overdue
                    </span>
                    <a href="<?php echo BASE_URL; ?>modules/tasks/list?my_tasks=1"
                       class="text-emerald-700 text-xs font-semibold hover:underline inline-flex items-center gap-1">
                        View All <i data-lucide="arrow-right" class="text-xs inline-block align-middle w-3 h-3" aria-hidden="true"></i>
                    </a>
                </div>
            </div>
            <?php if (!empty($recentTasks)): ?>
            <div class="relative dashboard-task-list">
                <?php foreach ($recentTasks as $task):
                    $stateMeta = $task['state_meta'];
                    $priorityTone = [
                        'Low' => 'bg-slate-100 text-slate-600',
                        'Medium' => 'bg-amber-100 text-amber-700',
                        'High' => 'bg-orange-100 text-orange-700',
                        'Urgent' => 'bg-red-100 text-red-700',
                    ][$task['priority'] ?? ''] ?? 'bg-slate-100 text-slate-600';
                ?>
                <a href="<?php echo BASE_URL; ?>modules/tasks/view?id=<?php echo $task['id']; ?>"
                   class="dashboard-task-item <?php echo !empty($task['is_overdue']) ? 'is-overdue' : ''; ?> <?php echo $task['status'] === 'Completed' ? 'is-completed' : ''; ?>"
                   style="--task-accent: <?php echo $stateMeta['accent']; ?>; --task-soft: <?php echo $stateMeta['soft']; ?>; --task-bg: <?php echo $stateMeta['bg']; ?>;">
                    <div class="flex items-start justify-between gap-4">
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-2 mb-2">
                                <span class="dashboard-status-dot <?php echo $stateMeta['pulse'] ? '' : 'is-static'; ?>"></span>
                                <span class="text-xs font-bold uppercase tracking-wider" style="color: <?php echo $stateMeta['accent']; ?>;">
                                    <?php echo htmlspecialchars($stateMeta['label']); ?>
                                </span>
                                <span class="px-2.5 py-1 rounded-full text-xs font-semibold <?php echo $priorityTone; ?>">
                                    <?php echo htmlspecialchars($task['priority'] ?? 'Normal'); ?>
                                </span>
                            </div>
                            <p class="dashboard-task-title text-sm sm:text-base font-semibold text-gray-900 break-words">
                                <?php echo htmlspecialchars($task['title']); ?>
                            </p>
                            <?php if (!empty($task['description_excerpt'])): ?>
                            <p class="dashboard-task-desc text-xs text-gray-500 mt-1 break-words">
                                <?php echo htmlspecialchars($task['description_excerpt']); ?>
                            </p>
                            <?php endif; ?>
                        </div>
                        <span class="dashboard-task-pill <?php echo $stateMeta['badge']; ?>">
                            <i data-lucide="<?php echo htmlspecialchars($stateMeta['icon']); ?>" class="text-sm w-4 h-4" aria-hidden="true"></i>
                            <?php echo htmlspecialchars($stateMeta['label']); ?>
                        </span>
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 mt-4 text-xs">
                        <div class="rounded-xl bg-white border border-white px-3 py-2">
                            <p class="text-xs uppercase tracking-wide text-gray-400">Project</p>
                            <p class="dashboard-task-project font-semibold text-gray-700 mt-1 break-words">
                                <?php echo htmlspecialchars($task['project_name'] ?? 'No Project'); ?>
                            </p>
                        </div>
                        <div class="rounded-xl bg-white border border-white px-3 py-2">
                            <p class="text-xs uppercase tracking-wide text-gray-400">Schedule</p>
                            <p class="font-semibold mt-1 <?php echo !empty($task['is_overdue']) ? 'text-red-600' : 'text-gray-700'; ?>">
                                <?php echo htmlspecialchars($task['due_copy']); ?>
                            </p>
                            <?php if (!empty($task['due_date'])): ?>
                            <p class="text-xs text-gray-400 mt-1"><?php echo date('M j, Y', strtotime($task['due_date'])); ?></p>
                            <?php endif; ?>
                        </div>
                        <div class="rounded-xl bg-white border border-white px-3 py-2">
                            <p class="text-xs uppercase tracking-wide text-gray-400">Assigned</p>
                            <p class="font-semibold text-gray-700 mt-1 break-words">
                                <?php echo htmlspecialchars($task['assignee_summary'] ?? 'Unassigned'); ?>
                            </p>
                        </div>
                    </div>
                </a>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <div class="relative flex flex-col items-center justify-center py-12 text-center text-gray-400">
                <div class="w-16 h-16 rounded-full bg-slate-100 text-slate-400 flex items-center justify-center mb-3">
                    <i data-lucide="clipboard-check" class="text-3xl w-8 h-8" aria-hidden="true"></i>
                </div>
                <p class="text-sm font-semibold text-gray-600">No tasks assigned yet</p>
                <p class="text-xs text-gray-400 mt-1">New assignments will land here automatically once they are linked to your account.</p>
            </div>
            <?php endif; ?>
        </div>
    </div>
<?php else: ?>
    <div class="todo-empty"><i data-lucide="lock" aria-hidden="true"></i><p>You don't have permission to view tasks.</p></div>
<?php endif; ?>
</div>
