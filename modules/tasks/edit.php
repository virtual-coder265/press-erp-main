<?php
require_once __DIR__ . '/../../config/app.php';
checkAuth();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/task_management_helper.php';
require_once __DIR__ . '/../../includes/upload_helper.php';
require_once __DIR__ . '/../../includes/project_visibility_helper.php';

$id = $_GET['id'] ?? 0;

$stmt = $pdo->prepare("SELECT * FROM tasks WHERE id = ?");
$stmt->execute([$id]);
$task = $stmt->fetch();

if (!$task) {
    redirect('modules/tasks/list?error=task_not_found');
}

$task_access = fetch_task_access_context($pdo, (int) $id, (int) $_SESSION['user_id']);
if (!$task_access || empty($task_access['can_edit'])) {
    redirect('modules/tasks/view?id=' . (int) $id . '&error=edit_access_denied');
}
$latest_review = fetch_task_latest_review($pdo, (int) $id);

// Get past documentation
$stmt = $pdo->prepare("SELECT td.*, COALESCE(u.name, 'Deleted User') as user_name FROM task_documentation td LEFT JOIN users u ON td.user_id = u.id WHERE task_id = ? ORDER BY created_at DESC");
$stmt->execute([$id]);
$documentations = $stmt->fetchAll();

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
$users = $pdo->query("SELECT id, name, email, photo FROM users ORDER BY name")->fetchAll();

// Project row for PM / approval UI
$pmStmt = $pdo->prepare('SELECT * FROM projects WHERE id = ? LIMIT 1');
$pmStmt->execute([$task['project_id']]);
$project_for_task = $pmStmt->fetch(PDO::FETCH_ASSOC) ?: [];
$pm_id = (int) ($project_for_task['created_by'] ?? 0);
$is_pm = $project_for_task && project_user_can_manage_project($pdo, (int) $_SESSION['user_id'], $project_for_task);
$task_last_decision_at = fetch_task_last_decision_at($task, $latest_review);
$task_has_changes_since_decision = task_has_changes_since_last_decision($pdo, $task, $latest_review);
$task_update_locked_until_changed = $is_pm
    && $task['status'] === 'Completed'
    && $task_last_decision_at !== null
    && !$task_has_changes_since_decision;
$task_edit_locked_for_non_pm = $task['status'] === 'Completed' && !empty($task['approved_by']) && !$is_pm;
$task_update_lock_stamp = !empty($task_last_decision_at) ? date('M d, Y g:i A', strtotime($task_last_decision_at)) : null;
$task_update_button_disabled = $task_update_locked_until_changed || $task_edit_locked_for_non_pm;

// Get task comments (with reply context)
$commentStmt = $pdo->prepare("
    SELECT tc.*,
           COALESCE(u.name, 'Deleted User') AS user_name,
           u.photo AS user_photo,
           rtc.comment AS reply_to_comment,
           ru.name     AS reply_to_user_name
    FROM task_comments tc
    LEFT JOIN users u           ON tc.user_id = u.id
    LEFT JOIN task_comments rtc ON tc.reply_to_id = rtc.id
    LEFT JOIN users ru          ON rtc.user_id = ru.id
    WHERE tc.task_id = ?
    ORDER BY tc.created_at ASC
");
$commentStmt->execute([$id]);
$task_comments = $commentStmt->fetchAll();

// Comment attachments
$tc_attach_map = [];
if ($task_comments) {
    $tc_ids = implode(',', array_map('intval', array_column($task_comments, 'id')));
    try {
        $tcaRows = $pdo->query(
            "SELECT * FROM task_comment_attachments WHERE comment_id IN ($tc_ids) ORDER BY created_at ASC"
        )->fetchAll();
        foreach ($tcaRows as $tca) {
            $tc_attach_map[(int)$tca['comment_id']][] = $tca;
        }
    } catch (Exception $e) { /* table may not exist yet */ }
}

$tc_users_json = json_encode(array_values(array_map(fn($u) => [
    'id'    => (int)$u['id'],
    'name'  => $u['name'],
    'photo' => (!empty($u['photo']) && $u['photo'] !== 'default.png') ? BASE_URL . ltrim($u['photo'], '/') : null,
], $users)));


include '../../includes/header.php';

$success_message = $_GET['success'] ?? '';
$error_message = $_GET['error'] ?? '';

$project_requirement_map = [];
foreach ($projects as $project) {
    $project_requirement_map[$project['id']] = [
        'pm_id' => (int) $project['created_by'],
        'require_document_submission' => (int) !empty($project['require_document_submission']),
        'require_procedure_tracking' => (int) !empty($project['require_procedure_tracking'])
    ];
}
$selected_assignees = fetch_task_assignees($pdo, (int) $task['id']);
if (empty($selected_assignees) && !empty($task['assigned_to'])) {
    foreach ($users as $user) {
        if ((int) $user['id'] === (int) $task['assigned_to']) {
            $selected_assignees[] = [
                'user_id' => (int) $user['id'],
                'name' => $user['name'],
                'email' => $user['email'],
                'is_primary' => 1,
            ];
            break;
        }
    }
}
$current_primary_assignee_id = null;
foreach ($selected_assignees as $assignee) {
    if (!empty($assignee['is_primary'])) {
        $current_primary_assignee_id = (int) $assignee['user_id'];
        break;
    }
}
if ($current_primary_assignee_id === null && !empty($task['assigned_to'])) {
    $current_primary_assignee_id = (int) $task['assigned_to'];
}
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
$selected_assignees = array_map(static function (array $assignee) use ($user_workloads): array {
    return [
        'id' => (int) $assignee['user_id'],
        'name' => $assignee['name'],
        'email' => $assignee['email'],
        'is_primary' => (int) !empty($assignee['is_primary']),
        'open_tasks' => (int) ($user_workloads[$assignee['user_id']]['open_tasks'] ?? 0),
    ];
}, $selected_assignees);
$procedure_steps = fetch_task_procedure_steps($pdo, (int) $task['id']);
$general_attachments = fetch_task_attachments($pdo, (int) $task['id'], 'general');
$task_form_mode = 'edit';
$original_status_value = $task['status'];
$current_task_data = [
    'id' => (int) $task['id'],
    'project_id' => (int) $task['project_id'],
    'name' => $task['name'],
    'description' => $task['description'] ?? '',
    'status' => $task['status'],
    'priority' => $task['priority'],
    'due_date' => $task['due_date'] ? date('Y-m-d', strtotime($task['due_date'])) : '',
    'require_document_submission' => (int) ($task['require_document_submission'] ?? $project_requirement_map[$task['project_id']]['require_document_submission'] ?? 0),
    'require_procedure_tracking' => (int) ($task['require_procedure_tracking'] ?? $project_requirement_map[$task['project_id']]['require_procedure_tracking'] ?? 0),
];
$can_complete_directly = $is_pm;
?>

<div class="workspace-stack">
    <div class="workspace-header">
        <div class="min-w-0">
            <a href="list" class="workspace-back-link mb-4">
                <i data-lucide="arrow-left" class="text-sm" aria-hidden="true"></i>
                <span>Back to Tasks</span>
            </a>
            <h1 class="text-3xl font-bold text-gray-800 break-words">Edit Task</h1>
            <p class="text-gray-600 mt-1">Update task details, ownership, and status evidence in a layout that remains readable at smaller widths.</p>
        </div>
        <div class="workspace-header-actions">
            <a href="../projects/view?id=<?php echo $task['project_id']; ?>" class="surface-button text-gray-600 w-full sm:w-auto" aria-label="View related project">
                <i data-lucide="folder-open" class="text-sm sm:mr-1" aria-hidden="true"></i>
                <span class="hidden sm:inline">View Project</span>
            </a>
        </div>
    </div>

    <div class="workspace-panel workspace-form-shell p-5 sm:p-8">
        <?php if ($success_message === 'comment_posted'): ?>
        <div class="workspace-panel bg-green-50 border-l-4 border-green-400 p-4 mb-6">
            <p class="text-sm text-green-700">Comment posted successfully.</p>
        </div>
        <?php endif; ?>
        <?php if ($error_message && $error_message !== 'comment_posted'): ?>
        <div class="workspace-panel bg-red-50 border-l-4 border-red-400 p-4 mb-6">
            <p class="text-sm text-red-700"><?php echo htmlspecialchars($error_message); ?></p>
        </div>
        <?php endif; ?>
        <?php if ($task['status'] === 'In Review'): ?>
        <div class="workspace-panel bg-yellow-50 border-l-4 border-yellow-400 p-4 mb-6">
            <div class="flex">
                <div class="flex-shrink-0"><i data-lucide="triangle-alert" class="text-yellow-400" aria-hidden="true"></i></div>
                <div class="ml-3">
                    <p class="text-sm text-yellow-700">This task is currently <strong>In Review</strong>. Please provide feedback or mark as completed if requirements are met.</p>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($task['status'] === 'Completed' && $task['approved_by']): ?>
        <div class="workspace-panel bg-green-50 border-l-4 border-green-400 p-4 mb-6">
            <div class="flex">
                <div class="flex-shrink-0"><i data-lucide="badge-check" class="text-green-400" aria-hidden="true"></i></div>
                <div class="ml-3">
                    <p class="text-sm text-green-700">This task has been <strong>officially approved</strong> by the Project Manager.</p>
                    <?php if ($task_edit_locked_for_non_pm): ?>
                    <p class="text-xs text-green-600 mt-1">Only the project manager can reopen or refresh the completion state from here.</p>
                    <?php elseif ($task_update_locked_until_changed): ?>
                    <p class="text-xs text-green-600 mt-1">No new task changes were detected since approval<?php echo $task_update_lock_stamp ? ' on ' . htmlspecialchars($task_update_lock_stamp) : ''; ?>.</p>
                    <?php elseif ($is_pm && $task_has_changes_since_decision): ?>
                    <p class="text-xs text-green-600 mt-1">This approved task has newer edits, so the update action is available again.</p>
                    <?php endif; ?>
                    <?php if ($task['score']): ?>
                    <p class="text-xs text-green-600">PM Score: <span class="font-bold"><?php echo $task['score']; ?>/5</span></p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <form method="POST" action="save" enctype="multipart/form-data" id="taskEditForm">
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="id" value="<?php echo $task['id']; ?>">
            <?php include __DIR__ . '/_task_form_fields.php'; ?>
            
            <div class="workspace-form-actions mt-8">
                <button
                    type="submit"
                    class="list-action-btn bg-green-600 text-white w-full sm:w-auto <?php echo $task_update_button_disabled ? 'opacity-60 cursor-not-allowed' : ''; ?>"
                    data-lock-on-pristine="<?php echo $task_update_locked_until_changed ? '1' : '0'; ?>"
                    data-default-label="Update Task"
                    data-locked-label="Update After Changes"
                    <?php echo $task_update_button_disabled ? 'disabled aria-disabled="true"' : ''; ?>
                >
                    <i data-lucide="save" class="text-sm sm:mr-1" aria-hidden="true"></i>
                    <span>
                        <?php
                        if ($task_edit_locked_for_non_pm) {
                            echo 'PM Approval Locked';
                        } elseif ($task_update_locked_until_changed) {
                            echo 'Update After Changes';
                        } else {
                            echo 'Update Task';
                        }
                        ?>
                    </span>
                </button>
                <a href="../projects/view?id=<?php echo $task['project_id']; ?>" class="surface-button text-gray-600 w-full sm:w-auto justify-center">
                    <i data-lucide="folder-open" class="text-sm sm:mr-1" aria-hidden="true"></i>
                    <span>View Project</span>
                </a>
                <a href="list" class="surface-button text-gray-600 w-full sm:w-auto justify-center">
                    <i data-lucide="arrow-left" class="text-sm sm:mr-1" aria-hidden="true"></i>
                    <span>Cancel</span>
                </a>
            </div>
        </form>
    </div>

    <!-- Task Discussion -->
    <div class="workspace-panel p-5 sm:p-6" id="comments">
        <h2 class="text-2xl font-bold text-gray-800 mb-6 flex items-center gap-2">
            <i data-lucide="messages-square" class="text-blue-600" aria-hidden="true"></i> Task Discussion
        </h2>

        <div class="grid grid-cols-1 lg:grid-cols-5 gap-6">

            <!-- ── Comment Feed ─────────────────────────────── -->
            <div class="lg:col-span-3 order-2 lg:order-1">
                <div class="space-y-3 max-h-[500px] overflow-y-auto pr-1" id="tc-feed" style="scroll-behavior:smooth;">
                    <?php if (empty($task_comments)): ?>
                    <div class="text-center py-12 text-gray-400">
                        <i data-lucide="message-circle" class="w-10 h-10" aria-hidden="true"></i>
                        <p class="mt-2 text-sm">No discussion yet. Add an internal note!</p>
                    </div>
                    <?php else: ?>
                    <?php
                    $tc_initials = function(string $n): string {
                        $p = explode(' ', trim($n));
                        return count($p) >= 2 ? strtoupper(substr($p[0],0,1).substr($p[1],0,1)) : strtoupper(substr($n,0,2));
                    };
                    foreach ($task_comments as $tc):
                        $tc_json = json_encode(['id' => (int)$tc['id'], 'body' => $tc['comment'], 'sender' => $tc['user_name']]);
                    ?>
                    <div class="tc-comment group relative bg-white rounded-xl border border-gray-100 shadow-sm p-4"
                         id="tc-<?php echo $tc['id']; ?>"
                         data-comment='<?php echo htmlspecialchars($tc_json, ENT_QUOTES); ?>'>

                        <?php if ($tc['reply_to_id'] && $tc['reply_to_comment']): ?>
                        <div class="mb-3 bg-blue-50 rounded-r-lg px-3 py-2" style="border-left:3px solid #3b82f6;">
                            <p class="text-xs font-bold text-blue-600 mb-0.5"><?php echo htmlspecialchars($tc['reply_to_user_name'] ?? 'User'); ?></p>
                            <p class="text-xs text-gray-600"><?php echo htmlspecialchars(mb_substr($tc['reply_to_comment'], 0, 120)); ?><?php echo mb_strlen($tc['reply_to_comment']) > 120 ? '…' : ''; ?></p>
                        </div>
                        <?php endif; ?>

                        <div class="flex items-start gap-3">
                            <?php if (!empty($tc['user_photo']) && $tc['user_photo'] !== 'default.png'): ?>
                                <img src="<?php echo htmlspecialchars(BASE_URL . ltrim($tc['user_photo'], '/')); ?>" alt="<?php echo htmlspecialchars($tc['user_name']); ?>" class="w-8 h-8 rounded-full object-cover flex-shrink-0">
                            <?php else: ?>
                                <div class="w-8 h-8 rounded-full bg-gradient-to-br from-blue-500 to-indigo-500 text-white flex items-center justify-center text-xs font-bold flex-shrink-0">
                                    <?php echo $tc_initials($tc['user_name']); ?>
                                </div>
                            <?php endif; ?>
                            <div class="flex-1 min-w-0">
                                <div class="flex items-center justify-between gap-2 mb-1">
                                    <span class="text-sm font-bold text-blue-600"><?php echo htmlspecialchars($tc['user_name']); ?></span>
                                    <span class="text-xs text-gray-400"><?php echo date('M d, Y H:i', strtotime($tc['created_at'])); ?></span>
                                </div>
                                <p class="text-sm text-gray-800 leading-relaxed"><?php
                                    $tc_body = htmlspecialchars($tc['comment']);
                                    $tc_body = preg_replace('/@([A-Za-z0-9 ]+?)(?=\s|$|[^A-Za-z0-9 ])/u',
                                        '<span class="inline-flex items-center bg-blue-100 text-blue-700 rounded-full px-2 text-xs font-semibold">@$1</span>', $tc_body);
                                    echo nl2br($tc_body);
                                ?></p>

                                <?php if (!empty($tc_attach_map[$tc['id']])): ?>
                                <div class="mt-2 flex flex-wrap gap-2">
                                <?php foreach ($tc_attach_map[$tc['id']] as $tca):
                                    $is_img = strpos($tca['file_type'], 'image/') === 0;
                                    $tca_url = BASE_URL . $tca['file_path'];
                                    $is_vc = attachment_is_voice($tca);
                                ?>
                                <?php if ($is_img): ?>
                                <a href="<?php echo htmlspecialchars($tca_url); ?>" target="_blank">
                                    <img src="<?php echo htmlspecialchars($tca_url); ?>" alt="<?php echo htmlspecialchars($tca['file_name']); ?>"
                                         class="w-20 h-20 object-cover rounded-lg border border-gray-200">
                                </a>
                                <?php elseif ($is_vc): ?>
                                <div class="flex flex-col gap-1 p-2 rounded-lg border border-gray-200 bg-white max-w-[200px]">
                                    <audio class="w-full h-9" controls preload="metadata" src="<?php echo htmlspecialchars($tca_url); ?>"></audio>
                                    <span class="text-[10px] font-bold text-gray-400 truncate"><?php echo htmlspecialchars($tca['file_name']); ?></span>
                                </div>
                                <?php else: ?>
                                <a href="<?php echo htmlspecialchars($tca_url); ?>" target="_blank"
                                   class="flex items-center gap-1 text-xs bg-gray-100 hover:bg-blue-50 text-gray-600 hover:text-blue-600 px-3 py-1.5 rounded-lg transition">
                                    <i data-lucide="paperclip" class="text-sm" aria-hidden="true"></i>
                                    <span class="truncate max-w-[120px]"><?php echo htmlspecialchars($tca['file_name']); ?></span>
                                </a>
                                <?php endif; ?>
                                <?php endforeach; ?>
                                </div>
                                <?php endif; ?>
                            </div>

                            <button type="button"
                                    class="opacity-0 group-hover:opacity-100 transition-opacity flex-shrink-0 text-gray-400 hover:text-blue-600 p-1 rounded"
                                    title="Reply"
                                    onclick="tcSetReply(JSON.parse(this.closest('[data-comment]').dataset.comment))">
                                <i data-lucide="reply" class="h-4 w-4" aria-hidden="true"></i>
                            </button>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- ── Comment Form ─────────────────────────────── -->
            <div class="lg:col-span-2 order-1 lg:order-2">

                <div id="tcReplyBar" class="hidden mb-3 bg-blue-50 rounded-xl p-3 flex items-start gap-2 border border-blue-200">
                    <div style="width:3px;background:#3b82f6;border-radius:3px;flex-shrink:0;align-self:stretch;"></div>
                    <div class="flex-1 min-w-0">
                        <p id="tcReplyBarSender" class="text-xs font-bold text-blue-600"></p>
                        <p id="tcReplyBarPreview" class="text-xs text-gray-600 truncate"></p>
                    </div>
                    <button type="button" onclick="tcCancelReply()" class="text-gray-400 hover:text-gray-600 flex-shrink-0">
                        <i data-lucide="x" class="h-3.5 w-3.5" aria-hidden="true"></i>
                    </button>
                </div>

                <form method="POST" action="comment" enctype="multipart/form-data"
                      class="bg-gray-50 p-4 rounded-xl border border-gray-200" id="tcForm" novalidate>
                    <input type="hidden" name="task_id"      value="<?php echo $id; ?>">
                    <input type="hidden" name="voice_note_sent" id="tcVoiceFlag" value="0">
                    <input type="hidden" name="redirect_to"  value="modules/tasks/edit?id=<?php echo $id; ?>">
                    <input type="hidden" name="reply_to_id"  id="tcReplyToId"    value="">
                    <input type="hidden" name="tagged_users" id="tcTaggedUsers"  value="">
                    <input type="file"   id="tcFileInput" name="comment_files[]" multiple
                           accept=".jpg,.jpeg,.png,.gif,.webp,.pdf,.doc,.docx,.webm,.ogg,.opus,.mp3,.wav,.m4a,.aac,audio/*" style="display:none">

                    <div class="mb-3 relative">
                        <label class="block text-xs font-bold text-gray-600 mb-1.5 uppercase tracking-wide">Internal Note</label>
                        <div class="tc-mention-list absolute bottom-full left-0 right-0 bg-white border border-gray-200 rounded-xl shadow-lg max-h-36 overflow-y-auto z-10 hidden"></div>
                        <textarea name="comment" id="tcTextarea" rows="4"
                                  class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 text-sm resize-none"
                                  placeholder="Update the PM or ask a question… use @ to mention"></textarea>
                    </div>

                    <div id="tcAttachStrip" class="flex flex-wrap gap-2 mb-2" style="display:none!important;"></div>
                    <p class="text-xs text-gray-500 mb-2 min-h-[1rem]" id="tcVoiceHint" aria-live="polite"></p>

                    <div class="flex items-center gap-2">
                        <button type="button" onclick="document.getElementById('tcFileInput').click()"
                                class="p-2 text-gray-500 hover:text-blue-600 hover:bg-blue-50 rounded-lg transition" title="Attach file">
                            <i data-lucide="paperclip" class="h-[1.1rem] w-[1.1rem]" aria-hidden="true"></i>
                        </button>
                        <button type="button" id="tcVoiceMic" class="p-2 text-gray-500 hover:text-blue-600 hover:bg-blue-50 rounded-lg transition press-voice-btn" title="Voice note" aria-label="Record voice note">
                            <i data-lucide="mic" class="h-[1.1rem] w-[1.1rem]" aria-hidden="true"></i>
                        </button>
                        <button type="submit"
                                class="flex-1 bg-blue-600 text-white py-2 rounded-lg font-bold hover:bg-blue-700 transition text-sm flex items-center justify-center gap-2">
                            <i data-lucide="send" class="text-sm" aria-hidden="true"></i> Post Comment
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

<script>
(function(){
    var feed = document.getElementById('tc-feed');
    if (feed) feed.scrollTop = feed.scrollHeight;

    window.tcSetReply = function(data){
        document.getElementById('tcReplyToId').value          = data.id;
        document.getElementById('tcReplyBarSender').textContent   = data.sender;
        document.getElementById('tcReplyBarPreview').textContent  = data.body.substring(0,100)+(data.body.length>100?'…':'');
        document.getElementById('tcReplyBar').classList.remove('hidden');
        document.getElementById('tcTextarea').focus();
    };
    window.tcCancelReply = function(){
        document.getElementById('tcReplyToId').value = '';
        document.getElementById('tcReplyBar').classList.add('hidden');
    };

    var tcFileInput = document.getElementById('tcFileInput');
    var tcStrip     = document.getElementById('tcAttachStrip');
    var tcFiles     = [];
    tcFileInput.addEventListener('change', function(){ document.getElementById('tcVoiceFlag').value='0'; tcFiles = Array.from(this.files); renderTcStrip(); });
    function renderTcStrip(){
        if(!tcFiles.length){ tcStrip.style.cssText='display:none!important'; document.getElementById('tcVoiceFlag').value='0'; return; }
        tcStrip.style.cssText='display:flex!important;flex-wrap:wrap;gap:.4rem;';
        tcStrip.innerHTML='';
        tcFiles.forEach(function(f,i){
            var c=document.createElement('div');
            c.className='flex items-center gap-1 bg-blue-50 text-blue-700 text-xs rounded-lg px-2 py-1';
            c.innerHTML='<i data-lucide="paperclip" class="h-3.5 w-3.5"></i><span class="truncate max-w-[100px]">'+escHtml2(f.name)+'</span><span class="cursor-pointer ml-1 text-blue-400 hover:text-red-500" data-i="'+i+'"><i data-lucide="x" class="h-3 w-3"></i></span>';
            tcStrip.appendChild(c);
        });
        if (typeof window.refreshAppShellIcons === 'function') {
            window.refreshAppShellIcons();
        }
        tcStrip.querySelectorAll('[data-i]').forEach(function(btn){
            btn.addEventListener('click',function(){ tcFiles.splice(parseInt(this.dataset.i,10),1); rebuildTc(); renderTcStrip(); });
        });
    }
    function rebuildTc(){ var dt=new DataTransfer(); tcFiles.forEach(function(f){dt.items.add(f);}); tcFileInput.files=dt.files; }

    var TC_USERS  = <?php echo $tc_users_json; ?>;
    var tcTA      = document.getElementById('tcTextarea');
    var tcMention = document.querySelector('.tc-mention-list');
    var tcTagged  = document.getElementById('tcTaggedUsers');
    var tcTagIds  = [];

    tcTA.addEventListener('input', function(){
        var val=this.value, cur=this.selectionStart;
        var before=val.substring(0,cur);
        var m=before.match(/@([A-Za-z0-9 ]*)$/);
        if(!m){ tcMention.classList.add('hidden'); return; }
        var q=m[1].toLowerCase();
        var filtered=TC_USERS.filter(function(u){ return u.name.toLowerCase().includes(q)&&!tcTagIds.includes(u.id); }).slice(0,6);
        if(!filtered.length){ tcMention.classList.add('hidden'); return; }
        tcMention.innerHTML=filtered.map(function(u){
            var avatarHtml = u.photo
                ? '<img src="'+escHtml2(u.photo)+'" alt="" style="width:22px;height:22px;border-radius:50%;object-fit:cover;flex-shrink:0;">'
                : '<div style="width:22px;height:22px;border-radius:50%;background:linear-gradient(135deg,#3b82f6,#6366f1);color:#fff;display:flex;align-items:center;justify-content:center;font-size:.6rem;font-weight:700;flex-shrink:0;">'+escHtml2(u.name.substring(0,2).toUpperCase())+'</div>';
            return '<div class="flex items-center gap-2 px-3 py-1.5 cursor-pointer hover:bg-blue-50 text-sm" data-id="'+u.id+'" data-name="'+escHtml2(u.name)+'">'+avatarHtml+escHtml2(u.name)+'</div>';
        }).join('');
        tcMention.classList.remove('hidden');
        tcMention.querySelectorAll('[data-id]').forEach(function(item){
            item.addEventListener('mousedown', function(e){
                e.preventDefault();
                var id=parseInt(this.dataset.id,10), name=this.dataset.name;
                var nb=before.replace(/@([A-Za-z0-9 ]*)$/,'@'+name+' ');
                tcTA.value=nb+val.substring(cur);
                tcTA.setSelectionRange(nb.length,nb.length);
                if(!tcTagIds.includes(id)) tcTagIds.push(id);
                tcTagged.value=JSON.stringify(tcTagIds);
                tcMention.classList.add('hidden');
                tcTA.focus();
            });
        });
    });
    tcTA.addEventListener('blur', function(){ setTimeout(function(){ tcMention.classList.add('hidden'); }, 150); });

    document.addEventListener('DOMContentLoaded', function(){
    var tcFormNode = document.getElementById('tcForm');
    if (tcFormNode) {
        tcFormNode.addEventListener('submit', function(e){
            var t = String(tcTA.value||'').trim();
            if (!t && !tcFiles.length) {
                e.preventDefault();
                var m = 'Add a message, attach a file, or record a voice note.';
                if (typeof window.showToast === 'function') window.showToast(m, 'error');
                else alert(m);
                return false;
            }
            return true;
        });
    }
    if (window.PressVoiceNote) {
        window.PressVoiceNote.bindToggle({
            button: '#tcVoiceMic',
            fileInput: '#tcFileInput',
            hiddenVoiceInput: '#tcVoiceFlag',
            statusEl: '#tcVoiceHint',
            maxSeconds: 180,
            onFile: function(file){
                tcFiles.push(file);
                rebuildTc();
                renderTcStrip();
            }
        });
    }
    });

    function escHtml2(s){ return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
})();
</script>

    <?php if (!empty($documentations)): ?>
    <div class="workspace-panel workspace-form-shell p-5 sm:p-8">
        <h2 class="text-2xl font-bold text-gray-800 mb-6">Documentation History</h2>
        <div class="workspace-history space-y-6">
        <?php foreach ($documentations as $doc): ?>
        <div class="workspace-history-item pl-2 sm:pl-4">
            <div class="workspace-history-dot"></div>
            <div class="workspace-history-head text-sm text-gray-500 mb-2">
                <div class="min-w-0">
                    <span class="font-bold text-gray-800"><?php echo htmlspecialchars($doc['user_name']); ?></span> changed status 
                    from <span class="text-gray-600 italic"><?php echo htmlspecialchars($doc['old_status']); ?></span> 
                    to <span class="font-semibold text-gray-800"><?php echo htmlspecialchars($doc['new_status']); ?></span>
                </div>
                <div class="text-xs sm:text-sm"><?php echo date('M d, Y H:i', strtotime($doc['created_at'])); ?></div>
            </div>
            <div class="workspace-panel bg-gray-50 p-4 mt-2">
                <?php if ($doc['documentation_text']): ?>
                    <p class="text-gray-700 whitespace-pre-wrap"><?php echo htmlspecialchars($doc['documentation_text']); ?></p>
                <?php endif; ?>
                <?php if ($doc['document_path']): ?>
                    <a href="<?php echo BASE_URL . $doc['document_path']; ?>" target="_blank" class="inline-flex items-center mt-2 text-blue-600 hover:underline">
                        <i data-lucide="paperclip" class="mr-1" aria-hidden="true"></i> View Attached Document
                    </a>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php include '../../includes/footer.php'; ?>
<?php include __DIR__ . '/_task_form_script.php'; ?>

