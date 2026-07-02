<?php
$reminderStatus = $reminder['status'] ?? 'active';
$dueTone = $reminder['due_meta']['tone'] ?? 'neutral';
$cardClasses = ['reminder-card'];

if (!empty($reminder['is_task_linked'])) {
    $cardClasses[] = 'is-task';
}
if ($dueTone === 'danger') {
    $cardClasses[] = 'is-overdue';
}
if ($reminderStatus === 'completed') {
    $cardClasses[] = 'is-completed';
}
if ($reminderStatus === 'dismissed') {
    $cardClasses[] = 'is-dismissed';
}

$taskHref = !empty($reminder['task_id'])
    ? BASE_URL . 'modules/tasks/view?id=' . (int) $reminder['task_id']
    : null;
$editHref = BASE_URL . 'modules/reminders/index?edit=' . (int) $reminder['id'];
if (!empty($redirectQuery)) {
    $editHref .= '&' . $redirectQuery;
}
$alarmOffsetOptions = $alarmOffsetOptions ?? reminder_alarm_offset_options();
$alarmMeta = $reminder['alarm_meta'] ?? reminder_alarm_meta($reminder);
$alarmCanBeScheduled = $reminderStatus === 'active'
    && reminder_effective_due_datetime($reminder['due_on'] ?? null, $reminder['remind_at'] ?? null) !== null;
$reminderActionAt = personal_reminder_last_action_at($reminder);
$reminderChangedSinceAction = personal_reminder_has_changes_since_action($reminder);
$reminderActionStamp = !empty($reminderActionAt) ? date('M j, Y g:i A', strtotime($reminderActionAt)) : null;
?>

<article class="<?php echo htmlspecialchars(implode(' ', $cardClasses)); ?>">
    <div class="reminder-card-head">
        <div class="flex flex-wrap items-center gap-2">
            <span class="reminder-chip <?php echo !empty($reminder['is_task_linked']) ? 'reminder-chip-source-task' : 'reminder-chip-source-self'; ?>">
                <i class="material-icons text-sm"><?php echo !empty($reminder['is_task_linked']) ? 'assignment' : 'sticky_note_2'; ?></i>
                <span><?php echo htmlspecialchars($reminder['source_label']); ?></span>
            </span>
            <span class="reminder-chip <?php echo htmlspecialchars($reminder['priority_badge_class']); ?>">
                <i class="material-icons text-sm">flag</i>
                <span><?php echo htmlspecialchars($reminder['priority'] ?? 'Medium'); ?></span>
            </span>
        </div>

        <?php if (!empty($reminder['pinned']) && empty($reminder['is_task_linked'])): ?>
        <span class="reminder-chip bg-slate-900 text-white">
            <i class="material-icons text-sm">push_pin</i>
            <span>Pinned</span>
        </span>
        <?php endif; ?>
    </div>

    <div class="space-y-3">
        <h3 class="reminder-card-title"><?php echo htmlspecialchars($reminder['title'] ?? 'Reminder'); ?></h3>
        <p class="reminder-card-note">
            <?php echo htmlspecialchars(trim((string) ($reminder['note'] ?? '')) !== '' ? $reminder['note'] : 'No additional note added yet.'); ?>
        </p>
    </div>

    <div class="reminder-card-meta">
        <span class="reminder-card-meta-item <?php echo htmlspecialchars($reminder['due_meta']['badge_class'] ?? 'bg-slate-100 text-slate-600'); ?>">
            <i class="material-icons text-sm">schedule</i>
            <span><?php echo htmlspecialchars($reminder['due_meta']['label'] ?? 'No target date'); ?></span>
        </span>
        <span class="reminder-card-meta-item <?php echo htmlspecialchars($alarmMeta['badge_class'] ?? 'bg-slate-100 text-slate-600'); ?>">
            <i class="material-icons text-sm">notifications_active</i>
            <span><?php echo htmlspecialchars($alarmMeta['label'] ?? 'Alarm off'); ?></span>
        </span>
        <?php if (!empty($reminder['project_name'])): ?>
        <span class="reminder-card-meta-item">
            <i class="material-icons text-sm">folder</i>
            <span><?php echo htmlspecialchars($reminder['project_name']); ?></span>
        </span>
        <?php endif; ?>
        <?php if (!empty($reminder['task_status']) && !empty($reminder['is_task_linked'])): ?>
        <span class="reminder-card-meta-item">
            <i class="material-icons text-sm">sync</i>
            <span><?php echo htmlspecialchars($reminder['task_status']); ?></span>
        </span>
        <?php endif; ?>
    </div>

    <div class="reminder-card-alarm">
        <div class="min-w-0">
            <div class="reminder-card-alarm-title">Alarm handler</div>
            <p class="reminder-card-alarm-note"><?php echo htmlspecialchars($alarmMeta['detail'] ?? 'No alarm scheduled.'); ?></p>
        </div>

        <?php if ($alarmCanBeScheduled): ?>
        <form method="POST" action="alarm" class="reminder-card-alarm-form">
            <input type="hidden" name="_csrf" value="<?php echo htmlspecialchars(csrf_token('reminder_alarm')); ?>">
            <input type="hidden" name="id" value="<?php echo (int) $reminder['id']; ?>">
            <input type="hidden" name="redirect_to" value="<?php echo htmlspecialchars($redirectTarget); ?>">
            <label class="reminder-card-alarm-toggle">
                <input type="hidden" name="alarm_enabled" value="0">
                <input type="checkbox" name="alarm_enabled" value="1" class="h-4 w-4 rounded border-slate-300 text-teal-600" <?php echo !empty($reminder['alarm_enabled']) ? 'checked' : ''; ?>>
                <span>Active</span>
            </label>
            <select name="alarm_offset_minutes" class="reminder-card-alarm-select">
                <?php foreach ($alarmOffsetOptions as $minutes => $label): ?>
                <option value="<?php echo (int) $minutes; ?>" <?php echo (int) ($reminder['alarm_offset_minutes'] ?? 30) === (int) $minutes ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($label); ?>
                </option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="reminder-card-action">
                <i class="material-icons text-sm">alarm</i>
                <span>Update alarm</span>
            </button>
        </form>
        <?php else: ?>
        <span class="reminder-card-status"><?php echo htmlspecialchars($alarmMeta['detail'] ?? 'Add a due schedule first to activate alarm playback for this card.'); ?></span>
        <?php endif; ?>
    </div>

    <div class="reminder-card-actions">
        <?php if (!empty($reminder['is_task_linked'])): ?>
            <?php if ($taskHref): ?>
            <a href="<?php echo htmlspecialchars($taskHref); ?>" class="reminder-card-action is-primary">
                <i class="material-icons text-sm">open_in_new</i>
                <span>Open task card</span>
            </a>
            <?php endif; ?>
            <span class="reminder-card-status">Task details stay synced automatically. Only your personal alarm preference is editable here.</span>
        <?php else: ?>
            <?php if ($reminderStatus === 'active'): ?>
            <a href="<?php echo htmlspecialchars($editHref); ?>" class="reminder-card-action" data-action-modal="reminder.create" data-action-option-id="<?php echo (int) $reminder['id']; ?>">
                <i class="material-icons text-sm">edit</i>
                <span>Edit</span>
            </a>

            <form method="POST" action="action" class="inline-flex">
                <input type="hidden" name="_csrf" value="<?php echo htmlspecialchars(csrf_token('reminder_action')); ?>">
                <input type="hidden" name="id" value="<?php echo (int) $reminder['id']; ?>">
                <input type="hidden" name="action" value="complete">
                <input type="hidden" name="redirect_to" value="<?php echo htmlspecialchars($redirectTarget); ?>">
                <button type="submit" class="reminder-card-action is-primary">
                    <i class="material-icons text-sm">done</i>
                    <span>Complete</span>
                </button>
            </form>

            <form method="POST" action="action" class="inline-flex">
                <input type="hidden" name="_csrf" value="<?php echo htmlspecialchars(csrf_token('reminder_action')); ?>">
                <input type="hidden" name="id" value="<?php echo (int) $reminder['id']; ?>">
                <input type="hidden" name="action" value="dismiss">
                <input type="hidden" name="redirect_to" value="<?php echo htmlspecialchars($redirectTarget); ?>">
                <button type="submit" class="reminder-card-action is-danger">
                    <i class="material-icons text-sm">archive</i>
                    <span>Archive</span>
                </button>
            </form>

            <form method="POST" action="action" class="inline-flex">
                <input type="hidden" name="_csrf" value="<?php echo htmlspecialchars(csrf_token('reminder_action')); ?>">
                <input type="hidden" name="id" value="<?php echo (int) $reminder['id']; ?>">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="redirect_to" value="<?php echo htmlspecialchars($redirectTarget); ?>">
                <button type="submit" class="reminder-card-action is-danger" onclick="return confirm('Delete this reminder note permanently?');">
                    <i class="material-icons text-sm">delete</i>
                    <span>Delete</span>
                </button>
            </form>
            <?php else: ?>
            <a href="<?php echo htmlspecialchars($editHref); ?>" class="reminder-card-action">
                <i class="material-icons text-sm">edit</i>
                <span>Edit</span>
            </a>

            <form method="POST" action="action" class="inline-flex">
                <input type="hidden" name="_csrf" value="<?php echo htmlspecialchars(csrf_token('reminder_action')); ?>">
                <input type="hidden" name="id" value="<?php echo (int) $reminder['id']; ?>">
                <input type="hidden" name="action" value="reopen">
                <input type="hidden" name="redirect_to" value="<?php echo htmlspecialchars($redirectTarget); ?>">
                <button type="submit" class="reminder-card-action">
                    <i class="material-icons text-sm">replay</i>
                    <span>Move to active</span>
                </button>
            </form>

            <?php if ($reminderChangedSinceAction && $reminderStatus === 'completed'): ?>
            <form method="POST" action="action" class="inline-flex">
                <input type="hidden" name="_csrf" value="<?php echo htmlspecialchars(csrf_token('reminder_action')); ?>">
                <input type="hidden" name="id" value="<?php echo (int) $reminder['id']; ?>">
                <input type="hidden" name="action" value="complete">
                <input type="hidden" name="redirect_to" value="<?php echo htmlspecialchars($redirectTarget); ?>">
                <button type="submit" class="reminder-card-action is-primary">
                    <i class="material-icons text-sm">done_all</i>
                    <span>Complete Again</span>
                </button>
            </form>
            <?php endif; ?>

            <?php if ($reminderChangedSinceAction): ?>
            <form method="POST" action="action" class="inline-flex">
                <input type="hidden" name="_csrf" value="<?php echo htmlspecialchars(csrf_token('reminder_action')); ?>">
                <input type="hidden" name="id" value="<?php echo (int) $reminder['id']; ?>">
                <input type="hidden" name="action" value="dismiss">
                <input type="hidden" name="redirect_to" value="<?php echo htmlspecialchars($redirectTarget); ?>">
                <button type="submit" class="reminder-card-action is-danger">
                    <i class="material-icons text-sm">archive</i>
                    <span><?php echo $reminderStatus === 'dismissed' ? 'Archive Again' : 'Archive'; ?></span>
                </button>
            </form>
            <?php endif; ?>

            <form method="POST" action="action" class="inline-flex">
                <input type="hidden" name="_csrf" value="<?php echo htmlspecialchars(csrf_token('reminder_action')); ?>">
                <input type="hidden" name="id" value="<?php echo (int) $reminder['id']; ?>">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="redirect_to" value="<?php echo htmlspecialchars($redirectTarget); ?>">
                <button type="submit" class="reminder-card-action is-danger" onclick="return confirm('Delete this reminder note permanently?');">
                    <i class="material-icons text-sm">delete</i>
                    <span>Delete</span>
                </button>
            </form>
            <?php endif; ?>

            <span class="reminder-card-status">
                <?php if ($reminderStatus === 'completed'): ?>
                    <?php echo $reminderChangedSinceAction
                        ? 'This card changed after completion. Review the edits, then confirm completion again.'
                        : 'Completed cards stay locked until something changes' . ($reminderActionStamp ? ' after ' . htmlspecialchars($reminderActionStamp) : '') . '.'; ?>
                <?php elseif ($reminderStatus === 'dismissed'): ?>
                    <?php echo $reminderChangedSinceAction
                        ? 'This archived card was updated. Re-archive it when you are done reviewing the changes.'
                        : 'Archived cards stay quiet until something changes' . ($reminderActionStamp ? ' after ' . htmlspecialchars($reminderActionStamp) : '') . '.'; ?>
                <?php else: ?>
                    Personal cards can be edited without touching project data.
                <?php endif; ?>
            </span>
        <?php endif; ?>
    </div>
</article>
