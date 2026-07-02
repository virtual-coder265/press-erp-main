<?php
require_once __DIR__ . '/../../config/app.php';
checkAuth();
require_once __DIR__ . '/../../config/database.php';

if (!hasPermission('manage_projects') && empty($_SESSION['is_section_head'])) {
    redirect('modules/projects/list?error=access_denied');
}

require_once __DIR__ . '/../../includes/project_visibility_helper.php';

$project = [
    'visibility_scope' => 'department',
    'department_id' => (int) ($_SESSION['department_id'] ?? 0) ?: null,
];

$pressErpSkipGlobalDateTimePicker = true;
$pressErpProjectCreateBudget = true;

include '../../includes/header.php';
?>

<div class="workspace-stack">
    <div class="workspace-header">
        <div class="min-w-0">
            <a href="list" class="workspace-back-link mb-4">
                <i data-lucide="arrow-left" class="text-sm" aria-hidden="true"></i>
                <span>Back to Projects</span>
            </a>
            <h1 class="text-3xl font-bold text-gray-800 break-words">New Project</h1>
            <p class="text-gray-600 mt-1">Create a new project to organize tasks, timeline expectations, and workflow requirements in one place.</p>
        </div>
        <div class="workspace-header-actions">
            <a href="list" class="surface-button text-gray-600 w-full sm:w-auto" aria-label="Cancel project creation">
                <i data-lucide="x" class="text-sm sm:mr-1" aria-hidden="true"></i>
                <span class="hidden sm:inline">Cancel</span>
            </a>
        </div>
    </div>

    <div class="workspace-panel workspace-form-shell p-5 sm:p-8">
        <form method="POST" action="save" id="projectCreateForm" data-native-date-range>
            <input type="hidden" name="action" value="create">
            
            <div class="grid grid-cols-1 gap-6">
                <div>
                    <label class="block text-gray-700 font-bold mb-2">Project Name *</label>
                    <input type="text" name="name" required 
                           class="w-full px-4 py-3 border border-gray-300 rounded-lg"
                           placeholder="Enter project name">
                </div>
                
                <div>
                    <label class="block text-gray-700 font-bold mb-2">Description</label>
                    <textarea name="description" rows="5" 
                              class="w-full px-4 py-3 border border-gray-300 rounded-lg"
                              placeholder="Describe the project objectives, scope, and expected delivery outcomes"></textarea>
                </div>
                
                <?php
                $projectCardColorValue = '';
                $projectCardColorFieldId = 'createProjectCardColor';
                include __DIR__ . '/_project_card_color_field.php';
                ?>

                <div class="workspace-panel p-4 sm:p-5 bg-slate-50">
                    <label class="block text-gray-700 font-bold mb-2">Workflow Requirements</label>
                    <p class="text-sm text-gray-500 mb-4">Choose every control that should apply to tasks under this project.</p>
                    <div class="grid grid-cols-1 gap-3">
                        <label class="flex items-start gap-3 p-4 border border-gray-200 rounded-xl bg-white">
                            <input type="checkbox" name="require_document_submission" value="1" class="mt-1 h-4 w-4 text-green-600 rounded border-gray-300 focus:ring-green-500">
                            <div class="min-w-0">
                                <div class="font-semibold text-gray-800">Require document submission</div>
                                <div class="text-sm text-gray-500">Tasks must attach a supporting file when status is advanced.</div>
                            </div>
                        </label>
                        <label class="flex items-start gap-3 p-4 border border-gray-200 rounded-xl bg-white">
                            <input type="checkbox" name="require_procedure_tracking" value="1" class="mt-1 h-4 w-4 text-green-600 rounded border-gray-300 focus:ring-green-500">
                            <div class="min-w-0">
                                <div class="font-semibold text-gray-800">Require procedure tracking</div>
                                <div class="text-sm text-gray-500">Tasks must record procedural notes when status is advanced.</div>
                            </div>
                        </label>
                    </div>
                </div>

                <?php include __DIR__ . '/_project_visibility_fields.php'; ?>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label class="block text-gray-700 font-bold mb-2">Status *</label>
                        <select name="status" required class="w-full px-4 py-3 border border-gray-300 rounded-lg">
                            <option value="Planning">Planning</option>
                            <option value="In Progress">In Progress</option>
                            <option value="On Hold">On Hold</option>
                            <option value="Completed">Completed</option>
                            <option value="Cancelled">Cancelled</option>
                        </select>
                    </div>
                    
                    <div>
                        <label class="block text-gray-700 font-bold mb-2">Priority *</label>
                        <select name="priority" required class="w-full px-4 py-3 border border-gray-300 rounded-lg">
                            <option value="Low">Low</option>
                            <option value="Medium" selected>Medium</option>
                            <option value="High">High</option>
                            <option value="Urgent">Urgent</option>
                        </select>
                    </div>
                </div>
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label for="projectCreateStartDate" class="block text-gray-700 font-bold mb-2">Start Date</label>
                        <?php echo press_native_datetime_field([
                            'name' => 'start_date',
                            'id' => 'projectCreateStartDate',
                            'mode' => 'date',
                            'native_range' => 'start',
                            'class' => 'w-full px-4 py-3 border border-gray-300 rounded-lg',
                        ]); ?>
                    </div>
                    <div>
                        <label for="projectCreateEndDate" class="block text-gray-700 font-bold mb-2">End Date</label>
                        <?php echo press_native_datetime_field([
                            'name' => 'end_date',
                            'id' => 'projectCreateEndDate',
                            'mode' => 'date',
                            'native_range' => 'end',
                            'class' => 'w-full px-4 py-3 border border-gray-300 rounded-lg',
                        ]); ?>
                    </div>
                </div>

                <div class="workspace-panel p-4 sm:p-5 bg-amber-50/50 border border-amber-100 rounded-xl">
                    <label class="flex items-start gap-3 mb-4">
                        <input type="checkbox" name="budget_tracking_enabled" value="1" id="createBudgetToggle" class="mt-1 h-4 w-4 text-amber-600 rounded border-gray-300">
                        <span>
                            <span class="block font-bold text-gray-800">Track budget for this project</span>
                            <span class="block text-sm text-gray-500">Enable per-task expenses and a budget roll-up on the project view.</span>
                        </span>
                    </label>
                    <div id="createBudgetFields" class="grid grid-cols-1 md:grid-cols-2 gap-4 opacity-50 pointer-events-none">
                        <div>
                            <label class="block text-gray-700 font-bold mb-2">Budget amount</label>
                            <input type="number" step="0.01" min="0" name="budget_amount" class="w-full px-4 py-3 border border-gray-300 rounded-lg" placeholder="0.00">
                        </div>
                        <div>
                            <label class="block text-gray-700 font-bold mb-2">Currency</label>
                            <input type="text" name="budget_currency" maxlength="3" class="w-full px-4 py-3 border border-gray-300 rounded-lg uppercase" placeholder="USD" value="USD">
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="workspace-form-actions mt-8">
                <button type="submit" class="list-action-btn bg-green-600 text-white w-full sm:w-auto">
                    <i data-lucide="save" class="text-sm sm:mr-1" aria-hidden="true"></i>
                    <span>Create Project</span>
                </button>
                <a href="list" class="surface-button text-gray-600 w-full sm:w-auto justify-center">
                    <i data-lucide="arrow-left" class="text-sm sm:mr-1" aria-hidden="true"></i>
                    <span>Cancel</span>
                </a>
            </div>
        </form>
    </div>
</div>

<script>if (typeof window.refreshAppShellIcons === 'function') { window.refreshAppShellIcons(); }</script>
<?php include '../../includes/footer.php'; ?>
