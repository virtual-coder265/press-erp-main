<?php
require_once __DIR__ . '/../../config/app.php';
checkAuth();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/task_management_helper.php';
require_once __DIR__ . '/../../includes/project_visibility_helper.php';

$project_id = $_GET['project_id'] ?? '';

$userIdProj = (int) ($_SESSION['user_id'] ?? 0);
$tvis = project_visibility_sql_where_for_projects('p', $userIdProj, $pdo);
$tq = "SELECT id, name, created_by, require_document_submission, require_procedure_tracking FROM projects p WHERE 1=1 {$tvis['clause']} ORDER BY p.name";
$tstmt = $pdo->prepare($tq);
foreach ($tvis['binds'] as $bk => $bv) {
    $tstmt->bindValue(':' . $bk, $bv, PDO::PARAM_INT);
}
$tstmt->execute();
$projects = $tstmt->fetchAll(PDO::FETCH_ASSOC);

// Get all users for assignment
$users = $pdo->query("SELECT id, name, email FROM users ORDER BY name")->fetchAll();

$pressErpSkipGlobalDateTimePicker = true;
$use_native_datetime_fields = true;

$project_requirement_map = [];
foreach ($projects as $project) {
    $project_requirement_map[$project['id']] = [
        'pm_id' => (int) $project['created_by'],
        'require_document_submission' => (int) !empty($project['require_document_submission']),
        'require_procedure_tracking' => (int) !empty($project['require_procedure_tracking'])
    ];
}

$selected_project_defaults = $project_requirement_map[$project_id] ?? [
    'require_document_submission' => 0,
    'require_procedure_tracking' => 0,
];
$user_workloads = fetch_task_assignee_workload($pdo);
$user_picker_options = [];
foreach ($users as $user) {
    $user_picker_options[] = [
        'id' => (int) $user['id'],
        'name' => $user['name'],
        'email' => $user['email'],
        'open_tasks' => (int) ($user_workloads[$user['id']]['open_tasks'] ?? 0),
    ];
}

$task_form_mode = 'create';
$original_status_value = 'Not Started';
$current_task_data = [
    'project_id' => $project_id,
    'name' => '',
    'description' => '',
    'status' => 'Not Started',
    'priority' => 'Medium',
    'due_date' => '',
    'require_document_submission' => $selected_project_defaults['require_document_submission'],
    'require_procedure_tracking' => $selected_project_defaults['require_procedure_tracking'],
];
$selected_assignees = [];
$current_primary_assignee_id = null;
$procedure_steps = !empty($current_task_data['require_procedure_tracking']) ? [['instruction' => '', 'note' => '']] : [];
$general_attachments = [];
$can_complete_directly = false;

include '../../includes/header.php';
?>

<div class="workspace-stack">
    <div class="workspace-header">
        <div class="min-w-0">
            <a href="list" class="workspace-back-link mb-4">
                <i data-lucide="arrow-left" class="text-sm" aria-hidden="true"></i>
                <span>Back to Tasks</span>
            </a>
            <h1 class="text-3xl font-bold text-gray-800 break-words">New Task</h1>
            <p class="text-gray-600 mt-1">Create a new task and connect it to the right project, owner, and workflow requirements.</p>
        </div>
        <div class="workspace-header-actions">
            <a href="list<?php echo $project_id ? '?project_id=' . urlencode($project_id) : ''; ?>" class="surface-button text-gray-600 w-full sm:w-auto" aria-label="Cancel task creation">
                <i data-lucide="x" class="text-sm sm:mr-1" aria-hidden="true"></i>
                <span class="hidden sm:inline">Cancel</span>
            </a>
        </div>
    </div>

    <div class="workspace-panel workspace-form-shell p-5 sm:p-8">
        <form method="POST" action="save" enctype="multipart/form-data" id="taskCreateForm">
            <input type="hidden" name="action" value="create">
            <?php include __DIR__ . '/_task_form_fields.php'; ?>
            
            <div class="workspace-form-actions mt-8">
                <button type="submit" class="list-action-btn bg-green-600 text-white w-full sm:w-auto">
                    <i data-lucide="save" class="text-sm sm:mr-1" aria-hidden="true"></i>
                    <span>Create Task</span>
                </button>
                <a href="list<?php echo $project_id ? '?project_id=' . urlencode($project_id) : ''; ?>" class="surface-button text-gray-600 w-full sm:w-auto justify-center">
                    <i data-lucide="arrow-left" class="text-sm sm:mr-1" aria-hidden="true"></i>
                    <span>Cancel</span>
                </a>
            </div>
        </form>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>
<?php include __DIR__ . '/_task_form_script.php'; ?>


