<?php
require_once __DIR__ . '/../../config/app.php';
checkAuth();
require_once __DIR__ . '/../../config/database.php';

if (!hasPermission('manage_projects') && empty($_SESSION['is_section_head'])) {
    http_response_code(403);
    echo '<div class="action-modal-empty">You do not have permission to create projects.</div>';
    exit;
}
?>
<form method="POST" action="<?php echo htmlspecialchars(BASE_URL . 'modules/projects/save'); ?>" class="action-modal-form" data-action-modal-form data-native-date-range>
    <input type="hidden" name="action" value="create">
    <input type="hidden" name="visibility_scope" value="department">
    <input type="hidden" name="project_department_id" value="<?php echo (int) ($_SESSION['department_id'] ?? 0); ?>">

    <div class="action-modal-grid">
        <div class="todo-field is-full">
            <label for="actionProjectName">Project name *</label>
            <input type="text" name="name" id="actionProjectName" class="todo-input" placeholder="Enter project name" required>
        </div>

        <div class="todo-field is-full">
            <label for="actionProjectDescription">Description</label>
            <textarea name="description" id="actionProjectDescription" rows="4" class="todo-textarea" placeholder="Describe the project objectives, scope, and expected delivery outcomes"></textarea>
        </div>

        <div class="todo-field">
            <label for="actionProjectStatus">Status</label>
            <select name="status" id="actionProjectStatus" class="todo-select">
                <option value="Planning">Planning</option>
                <option value="In Progress">In Progress</option>
                <option value="On Hold">On Hold</option>
                <option value="Completed">Completed</option>
                <option value="Cancelled">Cancelled</option>
            </select>
        </div>

        <div class="todo-field">
            <label for="actionProjectPriority">Priority</label>
            <select name="priority" id="actionProjectPriority" class="todo-select">
                <option value="Low">Low</option>
                <option value="Medium" selected>Medium</option>
                <option value="High">High</option>
                <option value="Urgent">Urgent</option>
            </select>
        </div>

        <div class="todo-field">
            <label for="actionProjectStart">Start date</label>
            <?php echo press_native_datetime_field([
                'name' => 'start_date',
                'id' => 'actionProjectStart',
                'value' => '',
                'mode' => 'date',
                'native_range' => 'start',
                'class' => 'todo-input',
            ]); ?>
        </div>

        <div class="todo-field">
            <label for="actionProjectEnd">End date</label>
            <?php echo press_native_datetime_field([
                'name' => 'end_date',
                'id' => 'actionProjectEnd',
                'value' => '',
                'mode' => 'date',
                'native_range' => 'end',
                'class' => 'todo-input',
            ]); ?>
        </div>

        <div class="todo-field is-full">
            <?php
            $projectCardColorValue = '';
            $projectCardColorFieldId = 'actionProjectCardColor';
            include __DIR__ . '/../projects/_project_card_color_field.php';
            ?>
        </div>

        <div class="todo-field is-full">
            <label class="font-bold text-gray-700">Workflow requirements</label>
            <div class="grid grid-cols-1 gap-3">
                <label class="action-modal-check" for="actionProjectDocuments">
                    <input type="checkbox" name="require_document_submission" id="actionProjectDocuments" value="1">
                    <span>
                        <strong>Require document submission</strong>
                        <span class="block action-modal-help">Tasks under this project should attach supporting files when progress changes.</span>
                    </span>
                </label>
                <label class="action-modal-check" for="actionProjectProcedure">
                    <input type="checkbox" name="require_procedure_tracking" id="actionProjectProcedure" value="1">
                    <span>
                        <strong>Require procedure tracking</strong>
                        <span class="block action-modal-help">Tasks under this project should record structured process steps.</span>
                    </span>
                </label>
            </div>
        </div>
    </div>

    <div class="action-modal-actions">
        <a href="<?php echo htmlspecialchars(BASE_URL . 'modules/projects/create'); ?>" class="todo-btn-ghost">Open full project form</a>
        <div class="flex items-center gap-2">
            <button type="button" class="todo-btn-ghost" data-ws-close>Cancel</button>
            <button type="submit" class="todo-btn-primary">
                <i class="material-icons text-sm">save</i>
                <span>Create project</span>
            </button>
        </div>
    </div>
</form>
