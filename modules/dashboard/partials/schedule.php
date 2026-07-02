<?php
/**
 * Dashboard partial - "Today's / Selected day's Schedule" reminder list.
 *
 * Component id: dashboard.schedule
 * Required context:
 *   - $dashboardSelectedItems, $dashboardSelectedDay
 *   - $dashboardTodayYmd, $dashboardSelectedReminderAt
 */
?>
<div class="dashboard-schedule-card"
     data-ajax-component="dashboard.schedule"
     data-ajax-poll="60000"
     data-ajax-refresh-on="focus,action:reminder.create,action:task.create"
     data-ajax-stale="20000"
     data-ajax-params='<?php echo htmlspecialchars(json_encode([
        'cal_day' => $dashboardSelectedDay,
     ]), ENT_QUOTES, 'UTF-8'); ?>'>
    <div class="dashboard-schedule-head">
        <div>
            <span><?php echo $dashboardSelectedDay === $dashboardTodayYmd ? "Today's Schedule" : 'Selected Schedule'; ?></span>
            <strong><?php echo htmlspecialchars(date('D, M j', strtotime($dashboardSelectedDay))); ?></strong>
        </div>
        <a href="<?php echo BASE_URL; ?>modules/reminders/index?hub=calendar" class="dashboard-schedule-view">View All</a>
    </div>

    <div class="dashboard-schedule-list">
        <?php if (empty($dashboardSelectedItems)): ?>
            <div class="dashboard-schedule-empty">
                <i data-lucide="calendar-check-2" class="dashboard-lucide-lg" aria-hidden="true"></i>
                <p>No reminders scheduled for this day.</p>
            </div>
        <?php else: ?>
            <?php foreach ($dashboardSelectedItems as $item): ?>
                <?php
                $isTaskLinked = !empty($item['is_task_linked']);
                $itemHref = $isTaskLinked && !empty($item['task_id'])
                    ? BASE_URL . 'modules/tasks/view?id=' . (int) $item['task_id']
                    : BASE_URL . 'modules/reminders/index?detail=' . (int) ($item['id'] ?? 0);
                $timeLabel = $item['remind_at_display'] ?? ($item['due_meta']['compact_label'] ?? 'Scheduled');
                ?>
                <a href="<?php echo htmlspecialchars($itemHref); ?>" class="dashboard-schedule-item">
                    <span class="dashboard-schedule-icon <?php echo $isTaskLinked ? 'is-task' : 'is-self'; ?>">
                        <i data-lucide="<?php echo $isTaskLinked ? 'clipboard-list' : 'calendar-clock'; ?>" aria-hidden="true"></i>
                    </span>
                    <span class="dashboard-schedule-copy">
                        <small><?php echo htmlspecialchars($timeLabel); ?></small>
                        <strong><?php echo htmlspecialchars($item['title'] ?? 'Reminder'); ?></strong>
                        <?php if (!empty($item['project_name'])): ?>
                            <em><?php echo htmlspecialchars($item['project_name']); ?></em>
                        <?php endif; ?>
                    </span>
                    <span class="dashboard-schedule-open" aria-label="Open schedule item">
                        <i data-lucide="chevron-right" aria-hidden="true"></i>
                    </span>
                </a>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <a href="<?php echo BASE_URL; ?>modules/reminders/index?hub=calendar&cal_day=<?php echo urlencode($dashboardSelectedDay); ?>"
       class="dashboard-schedule-add"
       data-action-modal="reminder.create"
       data-action-option-remind-at="<?php echo htmlspecialchars($dashboardSelectedReminderAt); ?>">
        <i data-lucide="plus" aria-hidden="true"></i>
        Add New
    </a>
</div>
