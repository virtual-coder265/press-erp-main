<?php
/**
 * Dashboard partial - Reminders modal body.
 *
 * Component id: dashboard.modal.reminders
 * Required context:
 *   - $dashboardReminderStats, $dashboardReminderAttentionCount
 *   - $dashboardReminderItems
 */
?>
<div class="todo-modal-body"
     data-ajax-component="dashboard.modal.reminders"
     data-ajax-refresh-on="modal-open:wsModalReminders,action:reminder.create"
     data-ajax-stale="20000">
    <div class="dashboard-panel-card p-6">
        <div class="relative flex flex-col gap-3 mb-5">
            <div>
                <p class="text-xs text-gray-500">Personal reminders, notes, and scheduled alerts that stay separate from project task workflow.</p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <span class="dashboard-meta-chip text-slate-600">
                    <span class="dashboard-status-dot is-static" style="--task-accent:#0f766e; --task-bg:rgba(15, 118, 110, 0.10);"></span>
                    <?php echo (int) $dashboardReminderStats['active']; ?> open
                </span>
                <span class="dashboard-meta-chip text-rose-600">
                    <span class="dashboard-status-dot" style="--task-accent:#f43f5e; --task-bg:rgba(244, 63, 94, 0.10);"></span>
                    <?php echo $dashboardReminderAttentionCount; ?> due now
                </span>
            </div>
        </div>

        <?php if (!empty($dashboardReminderItems)): ?>
        <div class="dashboard-reminder-list">
            <?php foreach ($dashboardReminderItems as $item): ?>
            <a href="<?php echo $item['href']; ?>" class="dashboard-reminder-item" data-action-modal="reminder.create" data-action-option-id="<?php echo (int) $item['id']; ?>">
                <div class="dashboard-reminder-head">
                    <span class="dashboard-reminder-icon is-<?php echo htmlspecialchars($item['tone']); ?>">
                        <i data-lucide="<?php echo htmlspecialchars($item['icon']); ?>" class="text-sm w-4 h-4" aria-hidden="true"></i>
                    </span>
                    <div class="min-w-0">
                        <p class="dashboard-reminder-title"><?php echo htmlspecialchars($item['title']); ?></p>
                        <p class="dashboard-reminder-subtitle"><?php echo htmlspecialchars($item['subtitle']); ?></p>
                    </div>
                </div>
                <div class="dashboard-reminder-meta">
                    <span class="dashboard-reminder-pill">
                        <i data-lucide="clock" class="text-xs w-3 h-3" aria-hidden="true"></i>
                        <span><?php echo htmlspecialchars($item['value']); ?></span>
                    </span>
                    <span class="dashboard-reminder-pill">
                        <i data-lucide="flag" class="text-xs w-3 h-3" aria-hidden="true"></i>
                        <span><?php echo htmlspecialchars($item['meta']); ?></span>
                    </span>
                </div>
            </a>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
        <div class="relative flex flex-col items-center justify-center py-12 text-center text-gray-400">
            <div class="w-16 h-16 rounded-full bg-slate-100 text-slate-400 flex items-center justify-center mb-3">
                <i data-lucide="calendar-off" class="text-3xl w-8 h-8" aria-hidden="true"></i>
            </div>
            <p class="text-sm font-semibold text-gray-600">Your reminder board is clear</p>
            <p class="text-xs text-gray-400 mt-1">Create a personal reminder card or wait for the next task assignment to appear here.</p>
        </div>
        <?php endif; ?>
    </div>
</div>
