<?php
require_once __DIR__ . '/../../config/app.php';
checkAuth();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/reminder_helper.php';

if (!reminder_module_ready($pdo, true)) {
    http_response_code(503);
    echo '<div class="action-modal-empty">Reminder tools are not ready yet.</div>';
    exit;
}

$alarmOffsetOptions = reminder_alarm_offset_options();
$reminderId = (int) ($_GET['id'] ?? 0);
$reminder = $reminderId > 0 ? fetch_user_reminder($pdo, (int) $_SESSION['user_id'], $reminderId) : null;
if ($reminderId > 0 && !$reminder) {
    http_response_code(404);
    echo '<div class="action-modal-empty">Reminder not found.</div>';
    exit;
}

$isTaskLinked = !empty($reminder['is_task_linked']);
$prefillTitle = $reminder ? (string) ($reminder['title'] ?? '') : trim((string) ($_GET['title'] ?? ''));
$prefillDateTime = $reminder ? (string) ($reminder['remind_at'] ?? '') : trim((string) ($_GET['remind_at'] ?? ''));
$prefillPriority = $reminder ? (string) ($reminder['priority'] ?? 'Medium') : 'Medium';
$prefillNote = $reminder ? (string) ($reminder['note'] ?? '') : trim((string) ($_GET['note'] ?? ''));
$alarmEnabled = $reminder ? (int) !empty($reminder['alarm_enabled']) : (int) ($prefillDateTime !== '');
$alarmOffset = (int) ($reminder['alarm_offset_minutes'] ?? 30);
$taskUrl = !empty($reminder['task_id']) ? BASE_URL . 'modules/tasks/view?id=' . (int) $reminder['task_id'] : null;
$modalTitle = $reminderId > 0 ? ($isTaskLinked ? 'Update task alarm' : 'Update reminder') : 'Create reminder';
?>
<form method="POST" action="<?php echo htmlspecialchars(BASE_URL . 'modules/reminders/save'); ?>" class="action-modal-form" data-action-modal-form>
    <input type="hidden" name="_csrf" value="<?php echo htmlspecialchars(csrf_token('reminder_save')); ?>">
    <input type="hidden" name="redirect_to" value="modules/reminders/index">
    <input type="hidden" name="id" value="<?php echo (int) $reminderId; ?>">

    <div class="action-modal-grid">
        <?php if ($isTaskLinked): ?>
        <div class="todo-field is-full">
            <div class="action-modal-check">
                <i class="material-icons text-indigo-600">assignment</i>
                <span>
                    <strong>Task-linked reminder</strong>
                    <span class="block action-modal-help">Task details stay synced automatically. You can update only your personal alarm preference here.</span>
                </span>
            </div>
        </div>
        <?php endif; ?>

        <div class="todo-field is-full">
            <label for="actionReminderTitle">Reminder title *</label>
            <input type="text" name="title" id="actionReminderTitle" class="todo-input" value="<?php echo htmlspecialchars($prefillTitle); ?>" placeholder="What should you remember?" required <?php echo $isTaskLinked ? 'readonly' : ''; ?>>
        </div>

        <div class="todo-field">
            <label for="actionReminderAt">Due date and time</label>
            <?php echo press_datetime_picker_field([
                'name' => 'remind_at',
                'id' => 'actionReminderAt',
                'value' => $prefillDateTime,
                'mode' => 'datetime',
                'readonly' => $isTaskLinked,
                'disable_past' => $reminderId === 0 && !$isTaskLinked,
                'class' => 'todo-input',
            ]); ?>
        </div>

        <div class="todo-field">
            <label for="actionReminderPriority">Priority</label>
            <select name="priority" id="actionReminderPriority" class="todo-select" <?php echo $isTaskLinked ? 'disabled' : ''; ?>>
                <?php foreach (['Low', 'Medium', 'High', 'Urgent'] as $priority): ?>
                <option value="<?php echo htmlspecialchars($priority); ?>" <?php echo $prefillPriority === $priority ? 'selected' : ''; ?>><?php echo htmlspecialchars($priority); ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="todo-field is-full">
            <label class="action-modal-check" for="actionReminderAlarm">
                <input type="hidden" name="alarm_enabled" value="0">
                <input type="checkbox" name="alarm_enabled" id="actionReminderAlarm" value="1" <?php echo $alarmEnabled ? 'checked' : ''; ?>>
                <span>
                    <strong>Enable alarm</strong>
                    <span class="block action-modal-help">Play the reminder notification when this item is due.</span>
                </span>
            </label>
        </div>

        <div class="todo-field is-full">
            <label for="actionReminderOffset">Remind me before due</label>
            <select name="alarm_offset_minutes" id="actionReminderOffset" class="todo-select">
                <?php foreach ($alarmOffsetOptions as $minutes => $label): ?>
                <option value="<?php echo (int) $minutes; ?>" <?php echo (int) $minutes === $alarmOffset ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($label); ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="todo-field is-full">
            <label for="actionReminderNote">Note</label>
            <textarea name="note" id="actionReminderNote" class="todo-textarea" placeholder="Optional context, location, or follow-up details" <?php echo $isTaskLinked ? 'readonly' : ''; ?>><?php echo htmlspecialchars($prefillNote); ?></textarea>
        </div>
    </div>

    <div class="action-modal-actions">
        <div class="flex items-center gap-2">
            <a href="<?php echo htmlspecialchars(BASE_URL . 'modules/reminders/index?hub=calendar'); ?>" class="todo-btn-ghost">Open full calendar</a>
            <?php if ($taskUrl): ?>
            <a href="<?php echo htmlspecialchars($taskUrl); ?>" class="todo-btn-ghost">Open task</a>
            <?php endif; ?>
        </div>
        <div class="flex items-center gap-2">
            <button type="button" class="todo-btn-ghost" data-ws-close>Cancel</button>
            <button type="submit" class="todo-btn-primary">
                <i class="material-icons text-sm">save</i>
                <span><?php echo htmlspecialchars($modalTitle); ?></span>
            </button>
        </div>
    </div>
</form>
