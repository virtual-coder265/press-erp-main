<?php
require_once __DIR__ . '/../../config/app.php';
checkAuth();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/project_pm_helper.php';
require_once __DIR__ . '/../../includes/project_visibility_helper.php';

$id = $_GET['id'] ?? 0;

$stmt = $pdo->prepare("SELECT * FROM projects WHERE id = ?");
$stmt->execute([$id]);
$project = $stmt->fetch();

if (!$project) {
    redirect('modules/projects/list?error=project_not_found');
}

$currentUserIdEdit = (int) ($_SESSION['user_id'] ?? 0);
if (!user_can_manage_project_pm($pdo, $currentUserIdEdit, $project)) {
    redirect('modules/projects/list?error=access_denied');
}

include '../../includes/header.php';
?>

<div class="workspace-stack">
    <div class="workspace-header">
        <div class="min-w-0">
            <a href="list" class="workspace-back-link mb-4">
                <i data-lucide="arrow-left" class="text-sm" aria-hidden="true"></i>
                <span>Back to Projects</span>
            </a>
            <h1 class="text-3xl font-bold text-gray-800 break-words">Edit Project</h1>
            <p class="text-gray-600 mt-1">Update project information, timing, and workflow controls with a layout that stays stable on smaller screens.</p>
        </div>
        <div class="workspace-header-actions">
            <a href="view?id=<?php echo $project['id']; ?>" class="surface-button text-gray-600 w-full sm:w-auto" aria-label="View project">
                <i data-lucide="eye" class="text-sm sm:mr-1" aria-hidden="true"></i>
                <span class="hidden sm:inline">View Project</span>
            </a>
        </div>
    </div>

    <div class="workspace-panel workspace-form-shell p-5 sm:p-8">
        <form method="POST" action="save">
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="id" value="<?php echo $project['id']; ?>">
            
            <div class="grid grid-cols-1 gap-6">
                <div>
                    <label class="block text-gray-700 font-bold mb-2">Project Name *</label>
                    <input type="text" name="name" required 
                           value="<?php echo htmlspecialchars($project['name']); ?>"
                           class="w-full px-4 py-3 border border-gray-300 rounded-lg"
                           placeholder="Enter project name">
                </div>
                
                <div>
                    <label class="block text-gray-700 font-bold mb-2">Description</label>
                    <textarea name="description" rows="5" 
                              class="w-full px-4 py-3 border border-gray-300 rounded-lg"
                              placeholder="Describe the project objectives and scope"><?php echo htmlspecialchars($project['description'] ?? ''); ?></textarea>
                </div>

                <?php
                $projectCardColorValue = (string) ($project['card_color'] ?? '');
                $projectCardColorFieldId = 'editProjectCardColor';
                include __DIR__ . '/_project_card_color_field.php';
                ?>

                <div class="workspace-panel p-4 sm:p-5 bg-slate-50">
                    <label class="block text-gray-700 font-bold mb-2">Workflow Requirements</label>
                    <p class="text-sm text-gray-500 mb-4">Choose every control that should apply to tasks under this project.</p>
                    <div class="grid grid-cols-1 gap-3">
                        <label class="flex items-start gap-3 p-4 border border-gray-200 rounded-xl bg-white">
                            <input type="checkbox" name="require_document_submission" value="1" class="mt-1 h-4 w-4 text-green-600 rounded border-gray-300 focus:ring-green-500"
                                   <?php echo !empty($project['require_document_submission']) ? 'checked' : ''; ?>>
                            <div class="min-w-0">
                                <div class="font-semibold text-gray-800">Require document submission</div>
                                <div class="text-sm text-gray-500">Tasks must attach a supporting file when status is advanced.</div>
                            </div>
                        </label>
                        <label class="flex items-start gap-3 p-4 border border-gray-200 rounded-xl bg-white">
                            <input type="checkbox" name="require_procedure_tracking" value="1" class="mt-1 h-4 w-4 text-green-600 rounded border-gray-300 focus:ring-green-500"
                                   <?php echo !empty($project['require_procedure_tracking']) ? 'checked' : ''; ?>>
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
                            <option value="Planning" <?php echo $project['status'] == 'Planning' ? 'selected' : ''; ?>>Planning</option>
                            <option value="In Progress" <?php echo $project['status'] == 'In Progress' ? 'selected' : ''; ?>>In Progress</option>
                            <option value="On Hold" <?php echo $project['status'] == 'On Hold' ? 'selected' : ''; ?>>On Hold</option>
                            <option value="Completed" <?php echo $project['status'] == 'Completed' ? 'selected' : ''; ?>>Completed</option>
                            <option value="Cancelled" <?php echo $project['status'] == 'Cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                        </select>
                    </div>
                    
                    <div>
                        <label class="block text-gray-700 font-bold mb-2">Priority *</label>
                        <select name="priority" required class="w-full px-4 py-3 border border-gray-300 rounded-lg">
                            <option value="Low" <?php echo $project['priority'] == 'Low' ? 'selected' : ''; ?>>Low</option>
                            <option value="Medium" <?php echo $project['priority'] == 'Medium' ? 'selected' : ''; ?>>Medium</option>
                            <option value="High" <?php echo $project['priority'] == 'High' ? 'selected' : ''; ?>>High</option>
                            <option value="Urgent" <?php echo $project['priority'] == 'Urgent' ? 'selected' : ''; ?>>Urgent</option>
                        </select>
                    </div>
                </div>
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label class="block text-gray-700 font-bold mb-2">Start Date</label>
                        <?php echo press_datetime_picker_field([
                            'name' => 'start_date',
                            'value' => $project['start_date'] ? date('Y-m-d', strtotime($project['start_date'])) : '',
                            'mode' => 'date',
                            'class' => 'w-full px-4 py-3 border border-gray-300 rounded-lg',
                        ]); ?>
                    </div>
                    
                    <div>
                        <label class="block text-gray-700 font-bold mb-2">End Date</label>
                        <?php echo press_datetime_picker_field([
                            'name' => 'end_date',
                            'value' => $project['end_date'] ? date('Y-m-d', strtotime($project['end_date'])) : '',
                            'mode' => 'date',
                            'class' => 'w-full px-4 py-3 border border-gray-300 rounded-lg',
                        ]); ?>
                    </div>
                </div>

                <?php
                $budOn = !empty($project['budget_tracking_enabled']);
                $budAmt = $project['budget_amount'] ?? '';
                $budCur = trim((string) ($project['budget_currency'] ?? 'USD')) ?: 'USD';
                ?>
                <div class="workspace-panel p-4 sm:p-5 bg-amber-50/50 border border-amber-100 rounded-xl">
                    <label class="flex items-start gap-3 mb-4">
                        <input type="checkbox" name="budget_tracking_enabled" value="1" id="editBudgetToggle" class="mt-1 h-4 w-4 text-amber-600 rounded border-gray-300" <?php echo $budOn ? 'checked' : ''; ?>>
                        <span>
                            <span class="block font-bold text-gray-800">Track budget for this project</span>
                            <span class="block text-sm text-gray-500">Roll up task expenses against the budget cap.</span>
                        </span>
                    </label>
                    <div id="editBudgetFields" class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-gray-700 font-bold mb-2">Budget amount</label>
                            <input type="number" step="0.01" min="0" name="budget_amount" class="w-full px-4 py-3 border border-gray-300 rounded-lg" placeholder="0.00" value="<?php echo htmlspecialchars((string) $budAmt); ?>">
                        </div>
                        <div>
                            <label class="block text-gray-700 font-bold mb-2">Currency</label>
                            <input type="text" name="budget_currency" maxlength="3" class="w-full px-4 py-3 border border-gray-300 rounded-lg uppercase" value="<?php echo htmlspecialchars(strtoupper(substr($budCur, 0, 3))); ?>">
                        </div>
                    </div>
                </div>
                <script>
                (function(){
                    var c = document.getElementById('editBudgetToggle');
                    var f = document.getElementById('editBudgetFields');
                    if (!c || !f) return;
                    function sync(){ var on = c.checked; f.classList.toggle('opacity-50', !on); f.classList.toggle('pointer-events-none', !on); }
                    c.addEventListener('change', sync);
                    sync();
                })();
                </script>
            </div>
            
            <div class="workspace-form-actions mt-8">
                <button type="submit" class="list-action-btn bg-green-600 text-white w-full sm:w-auto">
                    <i data-lucide="save" class="text-sm sm:mr-1" aria-hidden="true"></i>
                    <span>Update Project</span>
                </button>
                <a href="view?id=<?php echo $project['id']; ?>" class="surface-button text-gray-600 w-full sm:w-auto justify-center">
                    <i data-lucide="eye" class="text-sm sm:mr-1" aria-hidden="true"></i>
                    <span>View Project</span>
                </a>
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


