<?php
require_once __DIR__ . '/../../config/app.php';
checkAuth();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/reminder_helper.php';

if (!hasPermission('view_tasks') && !hasPermission('manage_tasks')) {
    http_response_code(403);
    echo '<div class="action-modal-empty">You do not have permission to create tasks.</div>';
    exit;
}

$projects = $pdo->query("SELECT id, name FROM projects ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$users = $pdo->query("SELECT id, name, email FROM users ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$alarmOffsetOptions = function_exists('reminder_alarm_offset_options') ? reminder_alarm_offset_options() : [30 => '30 minutes before'];
$selectedProjectId = (int) ($_GET['project_id'] ?? 0);
?>
<form method="POST" action="<?php echo htmlspecialchars(BASE_URL . 'modules/tasks/save'); ?>" class="action-modal-form" data-action-modal-form>
    <input type="hidden" name="action" value="create">
    <input type="hidden" name="require_document_submission" value="0">
    <input type="hidden" name="require_procedure_tracking" value="0">

    <div class="action-modal-grid">
        <div class="todo-field is-full">
            <label for="actionTaskName">Task name *</label>
            <input type="text" name="name" id="actionTaskName" class="todo-input" placeholder="Enter task name" required>
        </div>

        <div class="todo-field is-full">
            <label for="actionTaskProject">Project *</label>
            <select name="project_id" id="actionTaskProject" class="todo-select" required>
                <option value="">Select project</option>
                <?php foreach ($projects as $project): ?>
                <option value="<?php echo (int) $project['id']; ?>" <?php echo $selectedProjectId === (int) $project['id'] ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($project['name']); ?>
                </option>
                <?php endforeach; ?>
            </select>
            <p class="action-modal-help">Advanced attachments and procedure evidence stay available from the full task form.</p>
        </div>

        <div class="todo-field is-full">
            <label for="actionTaskDescription">Description</label>
            <textarea name="description" id="actionTaskDescription" rows="4" class="todo-textarea" placeholder="Describe the task details and expected output"></textarea>
        </div>

        <div class="todo-field">
            <label for="actionTaskStatus">Status</label>
            <select name="status" id="actionTaskStatus" class="todo-select">
                <option value="Not Started" selected>Not Started</option>
                <option value="In Progress">In Progress</option>
                <option value="In Review">In Review</option>
                <option value="Cancelled">Cancelled</option>
            </select>
        </div>

        <div class="todo-field">
            <label for="actionTaskPriority">Priority</label>
            <select name="priority" id="actionTaskPriority" class="todo-select">
                <option value="Low">Low</option>
                <option value="Medium" selected>Medium</option>
                <option value="High">High</option>
                <option value="Urgent">Urgent</option>
            </select>
        </div>

        <div class="todo-field">
            <label for="actionTaskAssignee">Primary assignee</label>
            <select name="assigned_to" id="actionTaskAssignee" class="todo-select">
                <option value="">Unassigned</option>
                <?php foreach ($users as $user): ?>
                <option value="<?php echo (int) $user['id']; ?>">
                    <?php echo htmlspecialchars($user['name'] . (!empty($user['email']) ? ' - ' . $user['email'] : '')); ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="todo-field">
            <label for="actionTaskDue">Due date</label>
            <?php echo press_native_datetime_field([
                'name' => 'due_date',
                'id' => 'actionTaskDue',
                'value' => '',
                'mode' => 'date',
                'disable_past' => true,
                'class' => 'todo-input',
            ]); ?>
        </div>

        <div class="todo-field is-full">
            <label class="action-modal-check" for="actionTaskAlarm">
                <input type="hidden" name="my_alarm_enabled" value="0">
                <input type="checkbox" name="my_alarm_enabled" id="actionTaskAlarm" value="1" checked>
                <span>
                    <strong>Enable my reminder alarm</strong>
                    <span class="block action-modal-help">Create a personal reminder for this task assignment.</span>
                </span>
            </label>
        </div>

        <div class="todo-field is-full">
            <label for="actionTaskAlarmOffset">Remind me before due</label>
            <select name="my_alarm_offset_minutes" id="actionTaskAlarmOffset" class="todo-select">
                <?php foreach ($alarmOffsetOptions as $minutes => $label): ?>
                <option value="<?php echo (int) $minutes; ?>" <?php echo (int) $minutes === 30 ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($label); ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>

    <div class="action-modal-actions">
        <a href="<?php echo htmlspecialchars(BASE_URL . 'modules/tasks/create'); ?>" class="todo-btn-ghost">Open full task form</a>
        <div class="flex items-center gap-2">
            <button type="button" class="todo-btn-ghost" data-ws-close>Cancel</button>
            <button type="submit" class="todo-btn-primary">
                <i class="material-icons text-sm">save</i>
                <span>Create task</span>
            </button>
        </div>
    </div>
</form>
