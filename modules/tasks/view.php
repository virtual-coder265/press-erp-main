<?php
require_once __DIR__ . '/../../config/app.php';
checkAuth();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/task_management_helper.php';
require_once __DIR__ . '/../../includes/project_pm_helper.php';
require_once __DIR__ . '/../../includes/reminder_helper.php';
require_once __DIR__ . '/../../includes/upload_helper.php';
require_once __DIR__ . '/../../includes/team_invitation_helper.php';

$taskId = (int) ($_GET['id'] ?? 0);
$userId = (int) ($_SESSION['user_id'] ?? 0);

$taskAccess = fetch_task_access_context($pdo, $taskId, $userId);
if (!$taskAccess) {
    redirect('modules/tasks/list?error=task_not_found');
}

if (empty($taskAccess['can_manage'])) {
    redirect('modules/tasks/list?error=access_denied');
}

$stmt = $pdo->prepare("
    SELECT
        t.*,
        p.name AS project_name,
        p.created_by AS pm_id,
        p.budget_tracking_enabled,
        p.budget_amount,
        p.budget_currency,
        u1.name AS assigned_to_name,
        COALESCE(u2.name, 'Deleted User') AS created_by_name,
        COALESCE(u3.name, 'Deleted User') AS pm_name
    FROM tasks t
    JOIN projects p ON p.id = t.project_id
    LEFT JOIN users u1 ON u1.id = t.assigned_to
    LEFT JOIN users u2 ON u2.id = t.created_by
    LEFT JOIN users u3 ON u3.id = p.created_by
    WHERE t.id = ?
");
$stmt->execute([$taskId]);
$task = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$task) {
    redirect('modules/tasks/list?error=task_not_found');
}

$taskAssignees = fetch_task_assignees($pdo, $taskId);
$taskAssigneeSummary = format_task_assignee_summary($taskAssignees, $task['assigned_to_name'] ?? null);
$procedureSteps = fetch_task_procedure_steps($pdo, $taskId);
$generalAttachments = fetch_task_attachments($pdo, $taskId, 'general');
$statusEvidenceAttachments = fetch_task_attachments($pdo, $taskId, 'status_evidence');
$taskProgressLogs = fetch_task_progress_logs($pdo, $taskId, 100);
$latestProgressLog = $taskProgressLogs[0] ?? null;

$commentStmt = $pdo->prepare("
    SELECT tc.*, COALESCE(u.name, 'Deleted User') AS user_name, u.photo AS user_photo
    FROM task_comments tc
    LEFT JOIN users u ON u.id = tc.user_id
    WHERE tc.task_id = ?
    ORDER BY tc.created_at DESC
");
$commentStmt->execute([$taskId]);
$taskComments = $commentStmt->fetchAll(PDO::FETCH_ASSOC);

$tc_attach_map = [];
if ($taskComments) {
    try {
        $ids = array_values(array_unique(array_filter(array_map('intval', array_column($taskComments, 'id')))));
        if ($ids !== []) {
            $tcIdList = implode(',', $ids);
            $tcAttRows = $pdo->query(
                "SELECT * FROM task_comment_attachments WHERE comment_id IN ($tcIdList) ORDER BY created_at ASC"
            )->fetchAll(PDO::FETCH_ASSOC);
            foreach ($tcAttRows as $tcaRow) {
                $tc_attach_map[(int) $tcaRow['comment_id']][] = $tcaRow;
            }
        }
    } catch (Throwable $e) {
        $tc_attach_map = [];
    }
}

$taskExpenses = [];
if (project_budget_enabled($task)) {
    $expStmt = $pdo->prepare("
        SELECT te.*, COALESCE(u.name, 'User') AS creator_name
        FROM task_expenses te
        LEFT JOIN users u ON u.id = te.created_by
        WHERE te.task_id = ?
        ORDER BY te.created_at DESC, te.id DESC
    ");
    $expStmt->execute([$taskId]);
    $taskExpenses = $expStmt->fetchAll(PDO::FETCH_ASSOC);
}

$taskWorkspaceParticipants = fetch_delivery_participants_for_task($pdo, $task);
$taskWorkspaceShowGroupChat = count($taskWorkspaceParticipants) > 1;
$taskWorkspaceGroupChatTitle = trim((string) $task['name']) . ' | ' . trim((string) ($task['project_name'] ?? 'Project'));

$teamInvitationsReady = team_invitation_tables_ready($pdo);
$canInviteTaskTeam = $teamInvitationsReady && user_can_send_task_team_invitation($pdo, $userId, $task);
$ti_invite_user_rows = $pdo->query('SELECT id, name, email FROM users ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
$ti_invite_users_json = json_encode(array_values(array_map(static function (array $u): array {
    return [
        'id' => (int) $u['id'],
        'name' => (string) ($u['name'] ?? ''),
        'email' => (string) ($u['email'] ?? ''),
    ];
}, $ti_invite_user_rows)), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

$taskChatMessages = [];
foreach (array_reverse($taskComments) as $tc) {
    $taskChatMessages[] = [
        'user_id' => (int) ($tc['user_id'] ?? 0),
        'user_name' => (string) ($tc['user_name'] ?? ''),
        'user_photo' => $tc['user_photo'] ?? null,
        'body' => (string) ($tc['comment'] ?? ''),
        'created_at' => $tc['created_at'] ?? '',
        'attachments' => $tc_attach_map[(int) ($tc['id'] ?? 0)] ?? [],
    ];
}

$reviewStmt = $pdo->prepare("
    SELECT tr.*, COALESCE(u.name, 'Deleted User') AS reviewer_name
    FROM task_reviews tr
    LEFT JOIN users u ON u.id = tr.reviewer_id
    WHERE tr.task_id = ?
    ORDER BY tr.created_at DESC
");
$reviewStmt->execute([$taskId]);
$reviews = $reviewStmt->fetchAll(PDO::FETCH_ASSOC);
$latestReview = $reviews[0] ?? fetch_task_latest_review($pdo, $taskId);
$workflowState = get_task_workflow_state($task, $latestReview);
$taskLastDecisionAt = fetch_task_last_decision_at($task, $latestReview);
$taskHasChangesSinceDecision = task_has_changes_since_last_decision($pdo, $task, $latestReview);
$taskSaveLockedUntilChanged = !empty($taskAccess['is_pm'])
    && $task['status'] === 'Completed'
    && $taskLastDecisionAt !== null
    && !$taskHasChangesSinceDecision;
$taskLastDecisionStamp = !empty($taskLastDecisionAt) ? date('M d, Y g:i A', strtotime($taskLastDecisionAt)) : null;

$docStmt = $pdo->prepare("
    SELECT td.*, COALESCE(u.name, 'Deleted User') AS user_name
    FROM task_documentation td
    LEFT JOIN users u ON u.id = td.user_id
    WHERE td.task_id = ?
    ORDER BY td.created_at DESC
");
$docStmt->execute([$taskId]);
$documentations = $docStmt->fetchAll(PDO::FETCH_ASSOC);

$taskProgressAttachmentCount = 0;
foreach ($taskProgressLogs as $progressLog) {
    $taskProgressAttachmentCount += (int) ($progressLog['attachment_count'] ?? 0);
}

$taskWorkHistoryItems = [];
foreach ($taskProgressLogs as $progressLog) {
    $taskWorkHistoryItems[] = [
        'kind' => 'progress',
        'ts' => strtotime((string) ($progressLog['created_at'] ?? 'now')),
        'row' => $progressLog,
    ];
}
foreach ($documentations as $documentation) {
    $taskWorkHistoryItems[] = [
        'kind' => 'legacy_doc',
        'ts' => strtotime((string) ($documentation['created_at'] ?? 'now')),
        'row' => $documentation,
    ];
}
usort($taskWorkHistoryItems, static fn (array $a, array $b): int => ($b['ts'] <=> $a['ts']));

$statusColors = [
    'Not Started' => 'bg-gray-100 text-gray-800',
    'In Progress' => 'bg-emerald-50 text-emerald-700',
    'In Review' => 'bg-yellow-100 text-yellow-800',
    'Completed' => 'bg-green-100 text-green-800',
    'Cancelled' => 'bg-red-100 text-red-800'
];

$priorityBadgeColors = [
    'Low' => 'bg-slate-100 text-slate-700',
    'Medium' => 'bg-emerald-50 text-emerald-700',
    'High' => 'bg-amber-100 text-amber-700',
    'Urgent' => 'bg-rose-100 text-rose-700'
];
$taskPriorityBadgeColor = $priorityBadgeColors[$task['priority'] ?? 'Low'] ?? 'bg-slate-100 text-slate-700';

$taskIsOverdue = !empty($task['due_date']) && !in_array($task['status'], ['Completed', 'Cancelled'], true) && strtotime($task['due_date']) < strtotime(date('Y-m-d'));
$taskDueLabel = !empty($task['due_date']) ? date('M d, Y', strtotime($task['due_date'])) : 'No deadline';
$taskDueNote = 'Set a due date';
if (!empty($task['due_date'])) {
    $dueDelta = (int) floor((strtotime(date('Y-m-d', strtotime($task['due_date']))) - strtotime(date('Y-m-d'))) / 86400);
    if ($taskIsOverdue) {
        $taskDueNote = abs($dueDelta) . ' day' . (abs($dueDelta) === 1 ? '' : 's') . ' overdue';
    } elseif ($dueDelta === 0) {
        $taskDueNote = 'Due today';
    } else {
        $taskDueNote = $dueDelta . ' day' . ($dueDelta === 1 ? '' : 's') . ' left';
    }
}

$taskFileCount = count($generalAttachments) + count($statusEvidenceAttachments) + $taskProgressAttachmentCount;
$taskReviewCount = count($reviews);
$taskCommentCount = count($taskComments);
$taskDocumentationCount = count($documentations);
$taskProgressLogCount = count($taskProgressLogs);
$taskWorkHistoryCount = count($taskWorkHistoryItems);
$taskReminderModuleReady = reminder_module_ready($pdo, true);
$currentUserTaskReminder = null;
$taskReminderBoardLink = BASE_URL . 'modules/reminders/index';

if ($taskReminderModuleReady && !empty($taskAccess['is_assignee'])) {
    sync_task_assignment_reminders_for_task($pdo, $taskId, $userId);

    $reminderStmt = $pdo->prepare("
        SELECT *
        FROM reminders
        WHERE user_id = ? AND task_id = ? AND source = 'task_assignment'
        LIMIT 1
    ");
    $reminderStmt->execute([$userId, $taskId]);
    $currentUserTaskReminder = $reminderStmt->fetch(PDO::FETCH_ASSOC) ?: null;

    if ($currentUserTaskReminder) {
        $currentUserTaskReminder['due_meta'] = reminder_due_meta(
            $currentUserTaskReminder['due_on'] ?? null,
            $currentUserTaskReminder['remind_at'] ?? null,
            $currentUserTaskReminder['status'] ?? 'active'
        );
    }
}

$taskProcedureCount = 0;
foreach ($procedureSteps as $step) {
    if (!empty(trim((string) ($step['instruction'] ?? ''))) || !empty(trim((string) ($step['note'] ?? '')))) {
        $taskProcedureCount++;
    }
}

$task_view_rel_time = static function (?string $dt): string {
    if ($dt === null || $dt === '') {
        return '';
    }
    $ts = strtotime($dt);
    if ($ts === false) {
        return '';
    }
    $diff = max(0, time() - $ts);
    if ($diff < 60) {
        return 'just now';
    }
    if ($diff < 3600) {
        return (string) (int) floor($diff / 60) . 'm ago';
    }
    if ($diff < 86400) {
        return (string) (int) floor($diff / 3600) . 'h ago';
    }
    if ($diff < 604800) {
        return (string) (int) floor($diff / 86400) . 'd ago';
    }

    return date('M j', $ts);
};

$reviewerIds = [];
foreach ($reviews as $rv) {
    if (!empty($rv['reviewer_id'])) {
        $reviewerIds[(int) $rv['reviewer_id']] = true;
    }
}
$taskReviewerStat = count($reviewerIds);
$taskTeamStat = max(count($taskAssignees), !empty($task['assigned_to']) ? 1 : 0);

$taskHeroStats = [
    ['label' => 'Comments', 'value' => number_format($taskCommentCount), 'tone' => '#0f766e'],
    ['label' => 'Files', 'value' => number_format($taskFileCount), 'tone' => '#33c481'],
    ['label' => 'Work Logs', 'value' => number_format($taskProgressLogCount), 'tone' => '#2563eb'],
    ['label' => 'Reviewers', 'value' => number_format($taskReviewerStat), 'tone' => '#f59e0b'],
    ['label' => 'Team', 'value' => number_format($taskTeamStat), 'tone' => '#0d9488'],
];

$taskActivityItems = [];
foreach ($taskProgressLogs as $log) {
    $taskActivityItems[] = [
        'ts' => strtotime((string) ($log['created_at'] ?? 'now')),
        'icon' => 'notebook-pen',
        'text' => 'Progress entry',
        'user' => (string) ($log['user_name'] ?? ''),
        'rel' => $task_view_rel_time($log['created_at'] ?? null),
    ];
}
foreach ($taskComments as $c) {
    $taskActivityItems[] = [
        'ts' => strtotime((string) ($c['created_at'] ?? 'now')),
        'icon' => 'messages-square',
        'text' => 'Discussion message',
        'user' => (string) ($c['user_name'] ?? ''),
        'rel' => $task_view_rel_time($c['created_at'] ?? null),
    ];
}
foreach ($documentations as $d) {
    $taskActivityItems[] = [
        'ts' => strtotime((string) ($d['created_at'] ?? 'now')),
        'icon' => 'file-up',
        'text' => 'Documentation update',
        'user' => (string) ($d['user_name'] ?? ''),
        'rel' => $task_view_rel_time($d['created_at'] ?? null),
    ];
}
foreach ($reviews as $r) {
    $act = ucwords(str_replace('_', ' ', (string) ($r['action'] ?? '')));
    $taskActivityItems[] = [
        'ts' => strtotime((string) ($r['created_at'] ?? 'now')),
        'icon' => 'gavel',
        'text' => 'Review · ' . $act,
        'user' => (string) ($r['reviewer_name'] ?? ''),
        'rel' => $task_view_rel_time($r['created_at'] ?? null),
    ];
}
usort($taskActivityItems, static fn (array $a, array $b): int => ($b['ts'] <=> $a['ts']));
$taskActivityItems = array_slice($taskActivityItems, 0, 8);

$taskInsights = [];
if ($taskIsOverdue) {
    $taskInsights[] = 'This task is past its deadline.';
}
if (!empty($workflowState['manager_action_required']) && !empty($taskAccess['is_pm'])) {
    $taskInsights[] = 'Your approval is required before work can move forward.';
} elseif (($workflowState['key'] ?? '') === 'awaiting_approval' && empty($taskAccess['is_pm'])) {
    $taskInsights[] = 'Waiting for project manager review.';
}
if (!empty($task['require_document_submission']) && ($taskProgressAttachmentCount + count($statusEvidenceAttachments)) === 0 && !in_array($task['status'], ['Not Started', 'Completed', 'Cancelled'], true)) {
    $taskInsights[] = 'Status evidence has not been uploaded yet.';
}
if (($workflowState['key'] ?? '') === 'changes_requested') {
    $taskInsights[] = 'Address the latest review feedback, then resubmit.';
}

$sysStatus = (string) ($task['status'] ?? 'Not Started');
$hasAssignees = $taskTeamStat > 0;
$stepCreatedAt = !empty($task['created_at']) ? date('M j, Y', strtotime((string) $task['created_at'])) : null;

$isCancelled = $sysStatus === 'Cancelled';
$stpDone = [true, $hasAssignees, false, false, false, false];
$stpActive = [false, false, false, false, false, false];

if (!$isCancelled) {
    $stpDone[2] = in_array($sysStatus, ['In Progress', 'In Review', 'Completed'], true);
    $stpDone[3] = in_array($sysStatus, ['In Review', 'Completed'], true);
    $stpDone[4] = $sysStatus === 'Completed' && (!empty($task['approved_by']) || ($latestReview['action'] ?? '') === 'approved' || !empty($task['approved_at']));
    $stpDone[5] = $sysStatus === 'Completed';

    if ($sysStatus === 'In Progress') {
        $stpActive[2] = true;
    } elseif ($sysStatus === 'In Review') {
        $stpActive[3] = true;
    } elseif ($sysStatus === 'Not Started') {
        if (!$hasAssignees) {
            $stpActive[1] = true;
        } else {
            $stpActive[2] = true;
        }
    }
}

$taskStepper = [
    ['label' => 'Created', 'date' => $stepCreatedAt, 'done' => $stpDone[0], 'active' => $stpActive[0]],
    ['label' => 'Assigned', 'date' => $hasAssignees ? $stepCreatedAt : null, 'done' => $stpDone[1], 'active' => $stpActive[1]],
    ['label' => 'In Progress', 'date' => null, 'done' => $stpDone[2], 'active' => $stpActive[2]],
    ['label' => 'In Review', 'date' => null, 'done' => $stpDone[3], 'active' => $stpActive[3]],
    ['label' => 'Approved', 'date' => null, 'done' => $stpDone[4], 'active' => $stpActive[4]],
    ['label' => 'Completed', 'date' => !empty($task['completed_at']) ? date('M j, Y', strtotime((string) $task['completed_at'])) : null, 'done' => $stpDone[5], 'active' => $stpActive[5]],
];

$assigneePrimaryName = '';
foreach ($taskAssignees as $ta) {
    if (!empty($ta['is_primary'])) {
        $assigneePrimaryName = (string) ($ta['name'] ?? '');
        break;
    }
}
if ($assigneePrimaryName === '' && !empty($task['assigned_to_name'])) {
    $assigneePrimaryName = (string) $task['assigned_to_name'];
}
$pmName = (string) ($task['pm_name'] ?? 'PM');

$approvalSignedOff = !empty($task['approved_by']) || ($latestReview['action'] ?? '') === 'approved' || !empty($task['approved_at']);
$approvalChain = [
    ['label' => 'Team', 'name' => $assigneePrimaryName !== '' ? $assigneePrimaryName : ($hasAssignees ? 'Assigned' : 'Unassigned'), 'state' => $hasAssignees ? 'done' : ($sysStatus === 'Not Started' ? 'current' : 'pending')],
    ['label' => 'PM review', 'name' => $pmName, 'state' => $sysStatus === 'In Review' ? 'current' : ($sysStatus === 'Completed' || $approvalSignedOff ? 'done' : 'pending')],
    ['label' => 'Approved', 'name' => 'Sign-off', 'state' => $approvalSignedOff || $sysStatus === 'Completed' ? 'done' : ($sysStatus === 'In Review' ? 'pending' : 'pending')],
    ['label' => 'Completed', 'name' => 'Closed', 'state' => $sysStatus === 'Completed' ? 'done' : 'pending'],
];
if ($sysStatus === 'Cancelled') {
    foreach ($approvalChain as $i => $_row) {
        $approvalChain[$i]['state'] = 'pending';
    }
}

$taskDescTeaser = '';
if (!empty($task['description'])) {
    $oneLineDesc = preg_replace('/\s+/u', ' ', trim((string) $task['description']));
    $taskDescTeaser = function_exists('mb_strimwidth')
        ? mb_strimwidth($oneLineDesc, 0, 200, '…', 'UTF-8')
        : (substr($oneLineDesc, 0, 200) . (strlen($oneLineDesc) > 200 ? '…' : ''));
}

$showSubmitReviewCta = !empty($taskAccess['can_manage'])
    && empty($taskAccess['is_pm'])
    && in_array($task['status'], ['Not Started', 'In Progress'], true)
    && !$taskSaveLockedUntilChanged;

$taskProgressLocked = (empty($taskAccess['is_pm']) && $task['status'] === 'Completed') || $taskSaveLockedUntilChanged;
$taskProgressLockMessage = '';
if (empty($taskAccess['is_pm']) && $task['status'] === 'Completed') {
    $taskProgressLockMessage = 'Signed off by PM. New progress entries are locked.';
} elseif ($taskSaveLockedUntilChanged) {
    $taskProgressLockMessage = 'No new team changes were detected after the last PM decision.';
}

$success = $_GET['success'] ?? '';
$error = $_GET['error'] ?? '';
$notice = $_GET['notice'] ?? '';

include '../../includes/header.php';
?>

<link href="<?php echo asset('css/premium-modules.css'); ?>" rel="stylesheet">
<link href="<?php echo asset('css/workspace-group-chat.css'); ?>" rel="stylesheet">
<style>
    #task-action-panel {
        scroll-margin-top: 1rem;
    }

    .task-view-v2 {
        gap: 1.75rem;
    }

    @media (min-width: 768px) {
        .task-view-v2 {
            gap: 2.25rem;
        }
    }

    .task-topbar {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        justify-content: space-between;
        gap: 0.75rem 1rem;
        padding: 0.65rem 1rem;
        background: #fff;
        border: 1px solid var(--premium-border);
        border-radius: 0.75rem;
        box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04);
    }

    .task-topbar-actions {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        gap: 0.5rem;
    }

    .task-topbar-icon {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 2.25rem;
        height: 2.25rem;
        border-radius: 0.5rem;
        border: 1px solid rgb(226 232 240);
        color: #64748b;
        background: #fff;
        text-decoration: none;
    }

    .task-topbar-icon:hover {
        background: rgb(248 250 252);
        color: #0f766e;
    }

    .task-card-lite {
        background: #fff;
        border: 1px solid var(--premium-border);
        border-radius: 0.75rem;
        padding: 1.1rem 1.25rem;
        box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04);
    }

    .task-band {
        display: flex;
        flex-direction: column;
        gap: 0.5rem;
    }

    .task-status-pills {
        display: flex;
        flex-wrap: wrap;
        gap: 0.45rem;
        margin-top: 0.85rem;
    }

    .task-status-pills .pill {
        display: inline-flex;
        align-items: center;
        gap: 0.35rem;
        padding: 0.3rem 0.65rem;
        border-radius: 999px;
        font-size: 0.75rem;
        font-weight: 600;
        color: #475569;
        background: rgb(241 245 249);
        white-space: nowrap;
    }

    .task-meta-min {
        margin-top: 0.85rem;
        font-size: 0.9375rem;
        line-height: 1.55;
        color: #64748b;
        max-width: 42rem;
    }

    .task-context-mini {
        font-size: 0.6875rem;
        font-weight: 700;
        letter-spacing: 0.06em;
        text-transform: uppercase;
        color: #94a3b8;
        margin: 0 0 0.35rem;
    }

    .task-context-mini-title {
        font-size: 0.9375rem;
        font-weight: 700;
        color: #0f172a;
        text-decoration: none;
        margin: 0 0 0.75rem;
        display: inline-block;
    }

    .task-context-mini-row {
        font-size: 0.8125rem;
        color: #64748b;
        margin: 0.35rem 0 0;
        display: flex;
        gap: 0.4rem;
        align-items: flex-start;
        line-height: 1.4;
    }

    .task-due-strong {
        color: #dc2626;
        font-weight: 600;
    }

    .task-stepper {
        overflow-x: auto;
        padding-bottom: 0.25rem;
    }

    .task-stepper-inner {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 0.35rem;
        min-width: min(920px, 100%);
        margin: 0 auto;
    }

    .task-step {
        flex: 1;
        text-align: center;
        position: relative;
        min-width: 4rem;
    }

    .task-step-dot {
        width: 1.85rem;
        height: 1.85rem;
        border-radius: 999px;
        margin: 0 auto;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 0.6875rem;
        font-weight: 800;
        border: 2px solid rgb(226 232 240);
        color: #94a3b8;
        background: #fff;
    }

    .task-step-done .task-step-dot {
        background: #ecfdf5;
        border-color: #34d399;
        color: #047857;
    }

    .task-step-active .task-step-dot {
        border-color: #2563eb;
        background: #eff6ff;
        color: #1d4ed8;
        box-shadow: 0 0 0 4px rgba(37, 99, 235, 0.12);
    }

    .task-step-label {
        margin-top: 0.35rem;
        font-size: 0.6875rem;
        font-weight: 600;
        color: #64748b;
        line-height: 1.2;
        padding: 0 0.125rem;
    }

    .task-step-date {
        margin-top: 0.25rem;
        font-size: 0.625rem;
        color: #94a3b8;
    }

    .task-mid-three {
        display: grid;
        gap: 1.25rem;
        align-items: start;
    }

    @media (min-width: 1100px) {
        .task-mid-three {
            grid-template-columns: minmax(0, 1.25fr) minmax(0, 1fr);
        }
    }

    .task-meta-strip {
        display: flex;
        flex-wrap: wrap;
        gap: 0.4rem 1.5rem;
        margin-top: 0.95rem;
        font-size: 0.8125rem;
        color: #475569;
    }

    .task-meta-strip-item {
        display: inline-flex;
        align-items: center;
        gap: 0.4rem;
        line-height: 1.4;
    }

    .task-meta-strip-item .task-meta-strip-label {
        font-size: 0.6875rem;
        font-weight: 700;
        letter-spacing: 0.05em;
        text-transform: uppercase;
        color: #94a3b8;
    }

    .task-meta-strip-item .task-meta-strip-value {
        font-weight: 600;
        color: #0f172a;
    }

    .task-meta-strip-item--alert .task-meta-strip-value {
        color: #dc2626;
    }

    .task-meta-strip-item svg.lucide {
        width: 0.95rem;
        height: 0.95rem;
        color: #94a3b8;
        flex-shrink: 0;
    }

    .task-section-divider {
        border: 0;
        border-top: 1px dashed rgb(226 232 240);
        margin: 1.5rem 0 1rem;
    }

    .task-form-field {
        display: flex;
        flex-direction: column;
        gap: 0.45rem;
    }

    .task-form-field-label {
        font-size: 0.7rem;
        font-weight: 700;
        letter-spacing: 0.07em;
        text-transform: uppercase;
        color: #64748b;
    }

    .task-form-field .task-form-hint {
        font-size: 0.75rem;
        color: #94a3b8;
        margin: 0;
    }

    .task-form-input {
        width: 100%;
        padding: 0.7rem 0.85rem;
        border: 1px solid rgb(226 232 240);
        border-radius: 0.65rem;
        font-size: 0.875rem;
        background: #fff;
        color: #0f172a;
        outline: none;
        transition: border-color 0.15s ease, box-shadow 0.15s ease;
    }

    .task-form-input:focus {
        border-color: #14b8a6;
        box-shadow: 0 0 0 3px rgba(20, 184, 166, 0.12);
    }

    .task-form-input--evidence {
        font-size: 0.75rem;
        padding: 0.45rem;
    }

    .task-progress-form-grid {
        display: grid;
        gap: 1rem;
    }

    @media (min-width: 720px) {
        .task-progress-form-grid--two {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
    }

    .task-progress-procedures {
        border: 1px solid rgb(226 232 240);
        border-radius: 0.85rem;
        padding: 1rem 1.1rem;
        background: rgba(236, 253, 245, 0.45);
    }

    .task-progress-procedures-head {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        justify-content: space-between;
        gap: 0.75rem;
        margin-bottom: 0.85rem;
    }

    .task-progress-procedure-item {
        background: #fff;
        border: 1px solid rgb(220 252 231);
        border-radius: 0.75rem;
        padding: 0.85rem 0.95rem;
        box-shadow: 0 1px 1px rgba(15, 23, 42, 0.03);
    }

    .task-progress-procedure-item + .task-progress-procedure-item {
        margin-top: 0.65rem;
    }

    .task-context-card {
        background: #fff;
        border: 1px solid var(--premium-border);
        border-radius: 0.85rem;
        padding: 1.1rem 1.25rem;
        box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04);
    }

    .task-context-card + .task-context-card {
        margin-top: 1rem;
    }

    .task-context-card h3 {
        font-size: 0.95rem;
        font-weight: 700;
        color: #0f172a;
        margin: 0 0 0.65rem;
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }

    .task-context-card h3 svg.lucide {
        color: #0f766e;
        width: 1.05rem;
        height: 1.05rem;
    }

    .task-stats-inline {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(7rem, 1fr));
        gap: 0.65rem;
    }

    .task-stat-chip {
        border: 1px solid rgb(241 245 249);
        border-radius: 0.65rem;
        padding: 0.55rem 0.65rem;
        background: rgb(249 250 251);
        text-align: center;
    }

    .task-stat-chip strong {
        display: block;
        font-size: 1.1rem;
        line-height: 1.2;
        font-weight: 800;
        color: #0f172a;
    }

    .task-stat-chip span {
        display: block;
        font-size: 0.625rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.08em;
        color: #94a3b8;
        margin-top: 0.1rem;
    }

    .task-decision-card {
        border: 1px solid rgb(254 215 170);
        background: linear-gradient(180deg, #fffbeb, #ffffff);
    }

    .task-decision-card h3 {
        color: #b45309;
    }

    .task-decision-card h3 svg.lucide {
        color: #d97706;
    }

    .task-review-history {
        border-top: 1px solid rgb(226 232 240);
        padding-top: 1.25rem;
        margin-top: 0.5rem;
    }

    /* ---------- Horizontal tabs ---------- */
    .task-tabs {
        display: flex;
        flex-wrap: nowrap;
        gap: 0.25rem;
        padding: 0.35rem;
        background: #fff;
        border: 1px solid var(--premium-border);
        border-radius: 0.85rem;
        box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04);
        overflow-x: auto;
        scrollbar-width: thin;
        position: sticky;
        top: 0.25rem;
        z-index: 25;
        -webkit-overflow-scrolling: touch;
    }

    .task-tabs::-webkit-scrollbar {
        height: 4px;
    }

    .task-tabs::-webkit-scrollbar-thumb {
        background: rgb(203 213 225);
        border-radius: 999px;
    }

    .task-tab {
        display: inline-flex;
        align-items: center;
        gap: 0.45rem;
        padding: 0.55rem 0.95rem;
        border-radius: 0.65rem;
        border: 1px solid transparent;
        background: transparent;
        color: #64748b;
        font-size: 0.8125rem;
        font-weight: 600;
        white-space: nowrap;
        cursor: pointer;
        transition: background-color 0.15s ease, color 0.15s ease, border-color 0.15s ease;
        flex-shrink: 0;
    }

    .task-tab:hover {
        background: rgb(248 250 252);
        color: #0f766e;
    }

    .task-tab:focus-visible {
        outline: 2px solid #14b8a6;
        outline-offset: 1px;
    }

    .task-tab svg.lucide {
        width: 1rem;
        height: 1rem;
        flex-shrink: 0;
    }

    .task-tab--active,
    .task-tab[aria-selected="true"] {
        background: linear-gradient(180deg, #ecfdf5, #ffffff);
        color: #047857;
        border-color: #a7f3d0;
        box-shadow: 0 1px 0 rgba(5, 150, 105, 0.08), inset 0 -2px 0 #10b981;
    }

    .task-tab-count {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-width: 1.25rem;
        height: 1.25rem;
        padding: 0 0.4rem;
        border-radius: 999px;
        background: rgb(241 245 249);
        color: #475569;
        font-size: 0.625rem;
        font-weight: 800;
        letter-spacing: 0.02em;
    }

    .task-tab[aria-selected="true"] .task-tab-count {
        background: #d1fae5;
        color: #047857;
    }

    .task-tab-dirty-dot {
        display: inline-block;
        width: 0.45rem;
        height: 0.45rem;
        border-radius: 999px;
        background: #f59e0b;
        margin-left: 0.1rem;
    }

    .task-tab-panels {
        margin-top: 1rem;
        width: 100%;
        max-width: 100%;
        min-width: 0;
        box-sizing: border-box;
    }

    .task-tab-panel {
        width: 100%;
        max-width: 100%;
        min-width: 0;
        margin: 0;
        padding: 0;
        box-sizing: border-box;
    }

    .task-tab-panel[hidden] {
        display: none !important;
    }

    /* Every task tab uses this one layout shell. */
    .task-tab-content-wrapper {
        display: flex;
        flex-direction: column;
        gap: 1.25rem;
        width: 100%;
        max-width: 100%;
        min-width: 0;
        margin: 0;
        padding: 0;
        box-sizing: border-box;
    }

    /* Only direct children — avoid forcing widths on nested flex rows/grids inside cards. */
    .task-tab-content-wrapper > * {
        width: 100%;
        max-width: 100%;
        box-sizing: border-box;
        min-width: 0;
    }

    .task-tab-content-wrapper .premium-card,
    .task-tab-content-wrapper .task-card-lite {
        width: 100%;
        max-width: 100%;
        min-width: 0;
        box-sizing: border-box;
    }

    @media (min-width: 768px) {
        .task-tab-content-wrapper {
            gap: 1.5rem;
        }
    }

    @media (max-width: 640px) {
        .task-tab {
            padding: 0.5rem 0.7rem;
            font-size: 0.75rem;
        }

        .task-tab svg.lucide {
            width: 0.9rem;
            height: 0.9rem;
        }
    }

    /* Unsaved navigation banner */
    .task-unsaved-banner {
        display: none;
        align-items: center;
        justify-content: space-between;
        gap: 0.65rem;
        padding: 0.55rem 0.9rem;
        background: #fef3c7;
        border: 1px solid #fcd34d;
        color: #92400e;
        border-radius: 0.65rem;
        font-size: 0.8125rem;
        margin-bottom: 0.75rem;
    }

    .task-unsaved-banner.is-shown {
        display: flex;
    }

    .task-unsaved-banner i {
        flex-shrink: 0;
    }

    .task-heading-sm {
        font-size: 0.6875rem;
        font-weight: 700;
        letter-spacing: 0.08em;
        text-transform: uppercase;
        color: #94a3b8;
        margin: 0 0 0.65rem;
    }

    .task-insights {
        border-left: 3px solid rgb(251 113 133);
        background: linear-gradient(90deg, rgb(255 241 242), rgb(255 255 255));
    }

    .task-insights ul {
        margin: 0;
        padding-left: 1.1rem;
        font-size: 0.8125rem;
        color: #881337;
        line-height: 1.55;
    }

    .task-activity-list {
        list-style: none;
        margin: 0;
        padding: 0;
        display: flex;
        flex-direction: column;
        gap: 0.85rem;
    }

    .task-activity-li {
        display: flex;
        gap: 0.65rem;
        align-items: flex-start;
        font-size: 0.8125rem;
    }

    .task-activity-ic {
        width: 2rem;
        height: 2rem;
        border-radius: 0.5rem;
        background: rgb(241 245 249);
        display: flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
        color: #0f766e;
    }

    .task-activity-li time {
        display: block;
        font-size: 0.625rem;
        color: #94a3b8;
        margin-top: 0.15rem;
    }

    .task-next-pane {
        background: rgb(254 252 232);
        border: 1px solid rgb(254 240 138);
        border-radius: 0.65rem;
        padding: 0.85rem 1rem;
        margin-top: 1rem;
    }

    .task-approval-strip {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        gap: 0.35rem;
        justify-content: center;
    }

    @media (min-width: 960px) {
        .task-approval-strip {
            justify-content: space-between;
            flex-wrap: nowrap;
            gap: 0.65rem;
        }
    }

    .task-ap-node {
        flex: 1 1 auto;
        min-width: 4.75rem;
        max-width: 9rem;
        text-align: center;
        padding: 0.5rem;
    }

    .task-ap-ring {
        width: 2.25rem;
        height: 2.25rem;
        border-radius: 999px;
        margin: 0 auto;
        border: 2px solid rgb(226 232 240);
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 0.6875rem;
        font-weight: 700;
        color: #475569;
        background: rgb(248 250 252);
    }

    .task-ap-node--current .task-ap-ring {
        border-color: #2563eb;
        background: #eff6ff;
        color: #1d40af;
    }

    .task-ap-node--done .task-ap-ring {
        border-color: #34d399;
        background: #ecfdf5;
        color: #047857;
    }

    .task-ap-arrow {
        color: rgb(203 213 225);
        flex-shrink: 0;
        align-self: center;
        padding: 0 0.15rem;
    }

    .task-alert-tight {
        padding: 0.65rem 1rem;
        border-radius: 0.65rem;
        font-size: 0.8125rem;
        border: 1px solid transparent;
    }

    .task-stats-tight.premium-stats-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .task-stats-tight .premium-stat-item {
        padding: 0.85rem;
        border-radius: 0.65rem;
    }

    .task-stats-tight .premium-stat-value {
        font-size: 1.35rem;
    }

    .task-stats-tight .premium-stat-label {
        font-size: 0.6rem;
    }

    .task-view-main-grid {
        display: grid;
        gap: 1.25rem;
        grid-template-columns: 1fr;
    }

    @media (min-width: 1024px) {
        .task-view-main-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
    }

    .task-view-lower-grid {
        display: grid;
        gap: 1.25rem;
        grid-template-columns: 1fr;
        margin-top: 0;
    }

    @media (min-width: 1024px) {
        .task-view-lower-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
            align-items: start;
        }
    }

    .task-review-foot {
        text-align: center;
        padding: 1.75rem;
        border: 1px dashed rgb(226 232 240);
        border-radius: 0.65rem;
        background: rgb(249 250 251);
        margin-top: 0.25rem;
    }

    #taskDocumentationList .doc-item {
        margin-bottom: 0;
        align-items: flex-start;
    }

    #taskDocumentationList .doc-item .doc-icon {
        margin-top: 0.125rem;
    }

    .task-view-shell .doc-icon svg.lucide,
    .task-view-shell .premium-card-title svg.lucide,
    .task-view-shell .premium-btn svg.lucide {
        width: 1.125rem;
        height: 1.125rem;
        flex-shrink: 0;
    }

    .task-view-shell h2#task-doc-hub-heading svg.lucide {
        width: 1.35rem;
        height: 1.35rem;
    }

    .task-view-shell .task-step-dot svg.lucide,
    .task-view-shell .task-activity-ic svg.lucide,
    .task-topbar svg.lucide {
        width: 1rem;
        height: 1rem;
    }
</style>

<div class="task-view-shell task-view-v2">

        <nav class="task-topbar" aria-label="Task actions">
            <a href="list" class="text-sm font-semibold text-slate-600 hover:text-[#0f766e] inline-flex items-center gap-1">
                <i data-lucide="arrow-left" aria-hidden="true"></i>
                <span>Back to tasks</span>
            </a>
            <div class="task-topbar-actions">
                <?php if ($taskReminderModuleReady): ?>
                <a href="<?php echo htmlspecialchars($taskReminderBoardLink, ENT_QUOTES, 'UTF-8'); ?>" class="task-topbar-icon" title="Reminders" aria-label="Reminders">
                    <i data-lucide="bell" aria-hidden="true"></i>
                </a>
                <?php endif; ?>
                <?php if (!empty($taskAccess['can_edit'])): ?>
                <a href="edit?id=<?php echo $taskId; ?>" class="task-topbar-icon" title="More options" aria-label="Edit task">
                    <i data-lucide="more-horizontal" aria-hidden="true"></i>
                </a>
                <?php endif; ?>
                <?php if (!(empty($taskAccess['is_pm']) && $task['status'] === 'Completed')): ?>
                <button type="submit" form="taskManageForm" class="premium-btn premium-btn-primary text-sm" style="padding:0.4rem 0.95rem;">
                    <i data-lucide="save" class="text-sm" aria-hidden="true"></i>
                    <span>Save workspace</span>
                </button>
                <?php endif; ?>
            </div>
        </nav>

        <section class="task-band" aria-labelledby="task-page-title">
            <div class="min-w-0">
                <div class="flex flex-wrap items-center gap-2 mb-2">
                    <span class="px-2.5 py-0.5 rounded-full text-[11px] font-bold uppercase tracking-wide <?php echo htmlspecialchars($workflowState['badge_class'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($workflowState['label']); ?></span>
                    <span class="px-2.5 py-0.5 rounded-full text-[11px] font-bold uppercase tracking-wide <?php echo $taskPriorityBadgeColor; ?>">
                        <?php echo htmlspecialchars($task['priority']); ?> priority
                    </span>
                    <?php if ($taskIsOverdue): ?>
                    <span class="px-2.5 py-0.5 rounded-full text-[11px] font-bold uppercase tracking-wide bg-rose-50 text-rose-700 border border-rose-100">Overdue</span>
                    <?php endif; ?>
                </div>
                <h1 id="task-page-title" class="text-xl sm:text-2xl font-bold text-slate-900 tracking-tight m-0"><?php echo htmlspecialchars($task['name']); ?></h1>

                <?php if ($taskDescTeaser !== ''): ?>
                <p class="task-meta-min"><?php echo htmlspecialchars($taskDescTeaser); ?></p>
                <?php endif; ?>

                <div class="task-meta-strip" aria-label="Task quick facts">
                    <span class="task-meta-strip-item">
                        <i data-lucide="briefcase" aria-hidden="true"></i>
                        <span class="task-meta-strip-label">Project</span>
                        <a href="../projects/view?id=<?php echo (int) $task['project_id']; ?>" class="task-meta-strip-value hover:underline" style="color:#0f766e;"><?php echo htmlspecialchars((string) $task['project_name']); ?></a>
                    </span>
                    <span class="task-meta-strip-item">
                        <i data-lucide="user-circle" aria-hidden="true"></i>
                        <span class="task-meta-strip-label">PM</span>
                        <span class="task-meta-strip-value"><?php echo htmlspecialchars((string) ($task['pm_name'] ?? 'Unknown')); ?></span>
                    </span>
                    <span class="task-meta-strip-item">
                        <i data-lucide="users" aria-hidden="true"></i>
                        <span class="task-meta-strip-label">Team</span>
                        <span class="task-meta-strip-value"><?php echo htmlspecialchars($taskAssigneeSummary); ?></span>
                    </span>
                    <span class="task-meta-strip-item <?php echo $taskIsOverdue ? 'task-meta-strip-item--alert' : ''; ?>">
                        <i data-lucide="calendar" aria-hidden="true"></i>
                        <span class="task-meta-strip-label">Due</span>
                        <span class="task-meta-strip-value"><?php echo htmlspecialchars($taskDueLabel); ?><?php if ($taskDueNote !== ''): ?> <span class="font-normal text-slate-400">· <?php echo htmlspecialchars($taskDueNote); ?></span><?php endif; ?></span>
                    </span>
                </div>

                <div class="mt-5 flex flex-wrap gap-2">
                    <a href="#progress" data-task-tab-link="progress" class="premium-btn premium-btn-primary text-sm" style="padding:0.4rem 0.95rem;">
                        <i data-lucide="notebook-pen" class="text-sm" aria-hidden="true"></i><span>Record progress</span>
                    </a>
                    <?php if (!empty($taskAccess['is_pm']) && $task['status'] === 'In Review'): ?>
                    <a href="review?id=<?php echo $taskId; ?>" class="premium-btn premium-btn-secondary text-sm" style="padding:0.4rem 0.85rem;">
                        <i data-lucide="gavel" class="text-sm" aria-hidden="true"></i><span>Open review workspace</span>
                    </a>
                    <?php endif; ?>
                    <?php if (!empty($taskWorkHistoryItems) || !empty($task['require_document_submission'])): ?>
                    <a href="#history" data-task-tab-link="history" class="premium-btn premium-btn-secondary text-sm" style="padding:0.4rem 0.85rem;">
                        <i data-lucide="folder" class="text-sm" aria-hidden="true"></i><span>Work history <?php echo number_format($taskWorkHistoryCount); ?></span>
                    </a>
                    <?php endif; ?>
                </div>
            </div>
        </section>

        <section class="task-card-lite task-stepper" aria-label="Task progress">
            <div class="task-stepper-inner">
                <?php foreach ($taskStepper as $si => $st): ?>
                <?php
                $cls = [];
                if (!empty($st['done'])) {
                    $cls[] = 'task-step-done';
                }
                if (!empty($st['active'])) {
                    $cls[] = 'task-step-active';
                }
                $cls = trim(implode(' ', $cls));
                ?>
                <div class="task-step <?php echo htmlspecialchars($cls, ENT_QUOTES, 'UTF-8'); ?>">
                    <div class="task-step-dot" aria-hidden="true">
                        <?php if (!empty($st['done']) && empty($st['active'])): ?>
                        <i data-lucide="check" aria-hidden="true"></i>
                        <?php else: ?>
                        <?php echo (int) $si + 1; ?>
                        <?php endif; ?>
                    </div>
                    <div class="task-step-label"><?php echo htmlspecialchars((string) $st['label']); ?></div>
                    <?php if (!empty($st['date'])): ?>
                    <div class="task-step-date"><?php echo htmlspecialchars((string) $st['date']); ?></div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
        </section>


    <?php if ($success === 'team_invite_sent'): ?>
    <div class="task-alert-tight bg-emerald-50 border-emerald-200 text-emerald-800">Team invitation sent.</div>
    <?php elseif ($success === 'invitation_accepted'): ?>
    <div class="task-alert-tight bg-emerald-50 border-emerald-200 text-emerald-800">You joined this task as an assignee.</div>
    <?php elseif ($success === 'work_updated'): ?>
    <div class="task-alert-tight bg-emerald-50 border-emerald-200 text-emerald-800">Progress entry saved.</div>
    <?php elseif ($success === 'approved'): ?>
    <div class="task-alert-tight bg-emerald-50 border-emerald-200 text-emerald-800">Task approved.</div>
    <?php elseif ($success === 'requested_changes'): ?>
    <div class="task-alert-tight bg-amber-50 border-amber-200 text-amber-900">Changes requested; the team was notified.</div>
    <?php elseif ($success === 'rejected'): ?>
    <div class="task-alert-tight bg-rose-50 border-rose-200 text-rose-800">Task rejected.</div>
    <?php elseif ($success === 'comment_posted'): ?>
    <div class="task-alert-tight bg-emerald-50 border-emerald-200 text-emerald-800">Message posted.</div>
    <?php endif; ?>

    <?php if ($notice === 'submitted_for_review'): ?>
    <div class="task-alert-tight bg-amber-50 border-amber-200 text-amber-900">Submitted for PM review (only a PM can mark completed directly).</div>
    <?php endif; ?>

    <?php if ($error): ?>
    <div class="task-alert-tight bg-rose-50 border-rose-200 text-rose-800"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>
    <?php if (count($taskInsights) > 0): ?>
    <section class="task-insights rounded-lg px-4 py-3" aria-label="Attention needed">
        <p class="task-heading-sm text-rose-800 m-0 mb-2">Attention needed</p>
        <ul>
            <?php foreach ($taskInsights as $bl): ?>
            <li><?php echo htmlspecialchars($bl); ?></li>
            <?php endforeach; ?>
        </ul>
        <a href="#progress" data-task-tab-link="progress" class="inline-block mt-3 text-xs font-semibold text-[#0f766e] hover:underline">View recommendations →</a>
    </section>
    <?php endif; ?>

    <div class="workspace-chat-layout<?php echo $taskWorkspaceShowGroupChat ? ' workspace-chat-layout--with-pane' : ''; ?>">
    <div class="workspace-chat-layout-main">
        <div id="taskUnsavedBanner" class="task-unsaved-banner" role="status" aria-live="polite">
            <span class="inline-flex items-center gap-2">
                <i data-lucide="alert-triangle" aria-hidden="true"></i>
                <span><strong>Unsaved changes</strong> <span data-task-unsaved-tab-hint></span>. Save before leaving or you’ll lose your work.</span>
            </span>
            <button type="button" id="taskUnsavedJumpBtn" class="text-xs font-bold text-amber-800 underline">Go to it</button>
        </div>

        <nav class="task-tabs" role="tablist" aria-label="Task workspace sections" id="taskTabs">
            <button type="button" role="tab" id="tab-btn-overview" data-tab="overview" aria-controls="tab-panel-overview" aria-selected="true" tabindex="0" class="task-tab task-tab--active">
                <i data-lucide="layout-dashboard" aria-hidden="true"></i>
                <span>Overview</span>
            </button>
            <button type="button" role="tab" id="tab-btn-progress" data-tab="progress" aria-controls="tab-panel-progress" aria-selected="false" tabindex="-1" class="task-tab">
                <i data-lucide="notebook-pen" aria-hidden="true"></i>
                <span>Progress</span>
                <span class="task-tab-dirty-dot" data-tab-dirty-marker="progress" hidden></span>
            </button>
            <button type="button" role="tab" id="tab-btn-brief" data-tab="brief" aria-controls="tab-panel-brief" aria-selected="false" tabindex="-1" class="task-tab">
                <i data-lucide="file-text" aria-hidden="true"></i>
                <span>Brief</span>
            </button>
            <?php if (!$taskWorkspaceShowGroupChat): ?>
            <button type="button" role="tab" id="tab-btn-discussion" data-tab="discussion" aria-controls="tab-panel-discussion" aria-selected="false" tabindex="-1" class="task-tab">
                <i data-lucide="messages-square" aria-hidden="true"></i>
                <span>Discussion</span>
                <span class="task-tab-count"><?php echo number_format($taskCommentCount); ?></span>
            </button>
            <?php endif; ?>
            <button type="button" role="tab" id="tab-btn-history" data-tab="history" aria-controls="tab-panel-history" aria-selected="false" tabindex="-1" class="task-tab">
                <i data-lucide="folder-clock" aria-hidden="true"></i>
                <span>Work history</span>
                <span class="task-tab-count"><?php echo number_format($taskWorkHistoryCount); ?></span>
            </button>
            <?php if (project_budget_enabled($task)): ?>
            <button type="button" role="tab" id="tab-btn-expenses" data-tab="expenses" aria-controls="tab-panel-expenses" aria-selected="false" tabindex="-1" class="task-tab">
                <i data-lucide="receipt" aria-hidden="true"></i>
                <span>Expenses</span>
                <?php if (!empty($taskExpenses)): ?>
                <span class="task-tab-count"><?php echo number_format(count($taskExpenses)); ?></span>
                <?php endif; ?>
            </button>
            <?php endif; ?>
            <?php if (!empty($reviews)): ?>
            <button type="button" role="tab" id="tab-btn-reviews" data-tab="reviews" aria-controls="tab-panel-reviews" aria-selected="false" tabindex="-1" class="task-tab">
                <i data-lucide="gavel" aria-hidden="true"></i>
                <span>Reviews</span>
                <span class="task-tab-count"><?php echo number_format(count($reviews)); ?></span>
            </button>
            <?php endif; ?>
        </nav>

        <div class="task-tab-panels">
        <section class="task-tab-panel" role="tabpanel" id="tab-panel-overview" aria-labelledby="tab-btn-overview" tabindex="0">
        <div class="task-tab-content-wrapper">
        <div class="task-mid-three">
            <div class="task-card-lite">
                <p class="task-heading-sm">Workflow status</p>
                <p class="text-sm text-slate-600 m-0 leading-relaxed"><?php echo htmlspecialchars($workflowState['description']); ?></p>

                <?php if (!empty($latestReview['note']) && in_array(($latestReview['action'] ?? ''), ['requested_changes', 'rejected'], true)): ?>
                <div class="mt-4 p-3 rounded-lg bg-amber-50 border border-amber-100 text-sm text-amber-900 whitespace-pre-wrap"><?php echo htmlspecialchars((string) $latestReview['note']); ?></div>
                <?php endif; ?>

                <?php if (!empty($taskAccess['is_pm']) && $task['status'] === 'In Review'): ?>
                <div class="task-next-pane mt-4">
                    <p class="task-heading-sm text-amber-900 m-0 mb-2">Decision required</p>
                    <form method="POST" action="review?id=<?php echo $taskId; ?>" class="space-y-3">
                        <input type="hidden" name="redirect_to" value="modules/tasks/view?id=<?php echo $taskId; ?>">
                        <label class="block text-[10px] font-bold uppercase text-amber-800/90 tracking-wider" for="pmReviewNote">Reviewer note</label>
                        <textarea id="pmReviewNote" name="note" rows="2" class="w-full p-2.5 border border-amber-200 rounded-lg text-sm bg-white outline-none" placeholder="Share context with the team (optional)"></textarea>
                        <div class="flex flex-wrap gap-2">
                            <button type="submit" name="review_action" value="approved" class="flex-1 min-w-[5rem] premium-btn premium-btn-primary text-xs justify-center" style="padding:0.4rem;">Approve</button>
                            <button type="submit" name="review_action" value="requested_changes" class="flex-1 min-w-[5rem] premium-btn premium-btn-secondary text-xs justify-center text-amber-700" style="padding:0.4rem;">Request changes</button>
                            <button type="submit" name="review_action" value="rejected" class="flex-1 min-w-[5rem] premium-btn premium-btn-secondary text-xs justify-center text-red-600" style="padding:0.4rem;" onclick="return confirm('Reject this task?');">Reject</button>
                        </div>
                    </form>
                </div>
                <?php elseif ($showSubmitReviewCta): ?>
                <p class="text-xs text-slate-500 mt-3 m-0">Ready to hand off? Use the progress form below to record work, then submit for PM review.</p>
                <?php endif; ?>

                <hr class="task-section-divider">
                <p class="task-heading-sm">Recent activity</p>
                <?php if (empty($taskActivityItems)): ?>
                <p class="text-sm text-slate-400 m-0">No activity recorded yet.</p>
                <?php else: ?>
                <ul class="task-activity-list">
                    <?php foreach (array_slice($taskActivityItems, 0, 5) as $act): ?>
                    <li class="task-activity-li">
                        <div class="task-activity-ic" aria-hidden="true"><i data-lucide="<?php echo htmlspecialchars((string) $act['icon'], ENT_QUOTES, 'UTF-8'); ?>"></i></div>
                        <div>
                            <strong class="font-semibold text-slate-700"><?php echo htmlspecialchars((string) $act['text']); ?></strong>
                            <?php if ((string) $act['user'] !== ''): ?> · <?php echo htmlspecialchars((string) $act['user']); ?><?php endif; ?>
                            <time><?php echo htmlspecialchars((string) $act['rel']); ?></time>
                        </div>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>
            </div>

            <div class="task-card-lite">
                <p class="task-heading-sm">Quick stats</p>
                <div class="task-stats-inline mb-4">
                    <?php foreach ($taskHeroStats as $stat): ?>
                    <div class="task-stat-chip">
                        <strong style="color: <?php echo htmlspecialchars($stat['tone'], ENT_QUOTES, 'UTF-8'); ?>;"><?php echo htmlspecialchars((string) $stat['value'], ENT_QUOTES, 'UTF-8'); ?></strong>
                        <span><?php echo htmlspecialchars((string) $stat['label'], ENT_QUOTES, 'UTF-8'); ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>

                <?php if ($canInviteTaskTeam || (!$teamInvitationsReady && !empty($taskAccess['can_edit']))): ?>
                <hr class="task-section-divider">
                <div class="collab-invite-panel !mt-0 !pt-0 !border-t-0">
                    <div class="collab-invite-panel__head">
                        <i data-lucide="user-plus" aria-hidden="true"></i>
                        <p class="collab-invite-panel__title">Invite collaborators</p>
                    </div>
                    <p class="collab-invite-panel__intro mb-4">Add someone to this task by invitation—they&rsquo;ll need to accept before they are assigned.</p>
                    <?php if ($canInviteTaskTeam): ?>
                    <form method="post" action="team_invite_save" id="taskTeamInviteForm" class="space-y-2">
                        <input type="hidden" name="task_id" value="<?php echo (int) $taskId; ?>">
                        <input type="hidden" name="invitee_user_id" id="taskTeamInviteUserId" value="">
                        <div class="relative">
                            <label for="taskTeamInviteSearch" class="collab-invite-field-label">Team member</label>
                            <input type="text" id="taskTeamInviteSearch" autocomplete="off" class="collab-invite-field" placeholder="Search by name or email">
                            <div id="taskTeamInviteResults" class="hidden absolute z-30 left-0 right-0 mt-1 max-h-48 overflow-y-auto rounded-xl border border-slate-200 bg-white shadow-lg"></div>
                        </div>
                        <div>
                            <label for="taskTeamInviteNote" class="sr-only">Optional note</label>
                            <textarea id="taskTeamInviteNote" name="note" rows="2" class="collab-invite-field" placeholder="Optional note for the invitee"></textarea>
                        </div>
                        <button type="submit" class="premium-btn premium-btn-primary w-full justify-center text-sm" style="padding:0.45rem 0.75rem" id="taskTeamInviteSubmit" disabled>
                            <i data-lucide="send" class="w-4 h-4" aria-hidden="true"></i>
                            Send invitation
                        </button>
                    </form>
                    <?php else: ?>
                    <p class="text-[11px] text-amber-800 m-0">Invitations require <code>sql/team_invitations_migration.sql</code>.</p>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
        </div>
        </section>
        <!-- /Overview panel -->

        <section class="task-tab-panel" role="tabpanel" id="tab-panel-progress" aria-labelledby="tab-btn-progress" tabindex="0" hidden>
        <div class="task-tab-content-wrapper">
        <section class="premium-card" id="task-action-panel">
            <div class="flex flex-wrap items-start justify-between gap-3 mb-4">
                <div>
                    <div class="premium-card-title m-0">
                        <i data-lucide="notebook-pen" class="text-emerald-600" aria-hidden="true"></i>
                        <span>Record progress</span>
                    </div>
                    <p class="text-sm text-slate-500 mt-2 mb-0">Capture what has been done, what comes next, the current outcome, and the evidence for this task.</p>
                </div>
                <?php if (!empty($latestProgressLog)): ?>
                <span class="px-3 py-1 rounded-full bg-emerald-50 text-emerald-700 text-[10px] font-bold uppercase tracking-wider">
                    Last entry <?php echo htmlspecialchars($task_view_rel_time($latestProgressLog['created_at'] ?? null)); ?>
                </span>
                <?php endif; ?>
            </div>

            <?php if ($taskProgressLockMessage !== ''): ?>
            <div class="mb-4 rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-600">
                <?php echo htmlspecialchars($taskProgressLockMessage, ENT_QUOTES, 'UTF-8'); ?>
            </div>
            <?php endif; ?>

            <form method="POST" action="manage" enctype="multipart/form-data" id="taskManageForm" class="space-y-6">
                <input type="hidden" name="task_id" value="<?php echo $taskId; ?>">
                <fieldset class="space-y-6" <?php echo $taskProgressLocked ? 'disabled' : ''; ?>>
                    <div class="task-form-field">
                        <label for="progressWorkDone" class="task-form-field-label">Work done</label>
                        <textarea id="progressWorkDone" name="progress_work_done" rows="3" class="task-form-input" placeholder="Describe what has been done on this task so far"></textarea>
                    </div>

                    <div class="task-progress-form-grid task-progress-form-grid--two">
                        <div class="task-form-field">
                            <label for="progressNextWork" class="task-form-field-label">Next scheduled work</label>
                            <textarea id="progressNextWork" name="progress_next_work" rows="3" class="task-form-input" placeholder="What is scheduled to happen next?"></textarea>
                        </div>
                        <div class="task-form-field">
                            <label for="progressOutcome" class="task-form-field-label">Current outcome</label>
                            <textarea id="progressOutcome" name="progress_outcome" rows="3" class="task-form-input" placeholder="Result or current state after this work?"></textarea>
                        </div>
                    </div>

                    <div class="task-progress-procedures">
                        <div class="task-progress-procedures-head">
                            <div>
                                <h4 class="font-bold text-emerald-900 m-0">Procedures performed</h4>
                                <p class="text-[10px] text-emerald-700 uppercase font-bold tracking-tight m-0"><?php echo !empty($task['require_procedure_tracking']) ? 'Required for this task' : 'Optional but recommended'; ?></p>
                            </div>
                            <button type="button" id="addProgressProcedureStep" class="inline-flex items-center gap-1.5 rounded-lg bg-emerald-700 px-3 py-1.5 text-xs font-bold text-white hover:bg-emerald-800 transition">
                                <i data-lucide="list-plus" class="text-sm" aria-hidden="true"></i>
                                <span>Add step</span>
                            </button>
                        </div>

                        <div id="progressProcedureList">
                            <div class="progress-procedure-item task-progress-procedure-item" data-step-index="0">
                                <div class="flex flex-wrap items-center justify-between gap-2 mb-2">
                                    <span class="progress-procedure-badge px-2 py-0.5 bg-emerald-50 text-emerald-700 text-[10px] font-bold rounded uppercase">Procedure 1</span>
                                    <div class="flex items-center gap-1">
                                        <button type="button" class="progress-procedure-move-up p-1 text-slate-400 hover:text-slate-600" aria-label="Move up"><i data-lucide="chevron-up" class="text-sm" aria-hidden="true"></i></button>
                                        <button type="button" class="progress-procedure-move-down p-1 text-slate-400 hover:text-slate-600" aria-label="Move down"><i data-lucide="chevron-down" class="text-sm" aria-hidden="true"></i></button>
                                        <button type="button" class="progress-procedure-remove p-1 text-rose-400 hover:text-rose-600" aria-label="Remove procedure"><i data-lucide="trash-2" class="text-sm" aria-hidden="true"></i></button>
                                    </div>
                                </div>
                                <div class="space-y-2">
                                    <input type="text" name="progress_step_procedure[]" class="task-form-input" placeholder="Describe the procedure performed">
                                    <textarea name="progress_step_output[]" rows="2" class="task-form-input" placeholder="Output / result from this procedure"></textarea>
                                    <input type="file" name="progress_step_attachments[0][]" multiple class="task-form-input task-form-input--evidence" aria-label="Evidence for this procedure">
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="task-progress-form-grid task-progress-form-grid--two">
                        <div class="task-form-field">
                            <label for="progressEntryFiles" class="task-form-field-label" style="color:#047857;">Evidence / proof of work</label>
                            <p class="task-form-hint">Documents, screenshots, or files proving this recorded work. Required when changing status on tasks that need evidence.</p>
                            <input id="progressEntryFiles" type="file" name="progress_entry_attachments[]" multiple class="task-form-input task-form-input--evidence">
                        </div>
                        <div class="task-form-field">
                            <label for="progressReferenceFiles" class="task-form-field-label">Reference files for task</label>
                            <p class="task-form-hint">Optional task-level references that stay attached to this task beyond one progress entry.</p>
                            <input id="progressReferenceFiles" type="file" name="task_reference_attachments[]" multiple class="task-form-input task-form-input--evidence">
                        </div>
                    </div>

                    <div class="task-form-field">
                        <label for="taskStatus" class="task-form-field-label">Task status after this update</label>
                        <select name="status" id="taskStatus" class="task-form-input font-semibold">
                            <option value="Not Started" <?php echo $task['status'] === 'Not Started' ? 'selected' : ''; ?>>Not Started</option>
                            <option value="In Progress" <?php echo $task['status'] === 'In Progress' ? 'selected' : ''; ?>>In Progress</option>
                            <?php if (!empty($taskAccess['is_pm']) || $task['status'] === 'In Review'): ?>
                            <option value="In Review" <?php echo $task['status'] === 'In Review' ? 'selected' : ''; ?>>In Review</option>
                            <?php endif; ?>
                            <?php if (!empty($taskAccess['is_pm']) || $task['status'] === 'Completed'): ?>
                            <option value="Completed" <?php echo $task['status'] === 'Completed' ? 'selected' : ''; ?>>Completed</option>
                            <?php endif; ?>
                            <option value="Cancelled" <?php echo $task['status'] === 'Cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                        </select>
                    </div>
                </fieldset>

                <div class="pt-2 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between sm:gap-4">
                    <?php if ($taskProgressLocked): ?>
                    <p class="text-xs text-slate-400 italic m-0"><?php echo htmlspecialchars($taskProgressLockMessage !== '' ? $taskProgressLockMessage : 'Progress updates are currently locked.', ENT_QUOTES, 'UTF-8'); ?></p>
                    <?php else: ?>
                    <button type="submit" id="taskSaveProgressButton" class="w-full sm:flex-1 premium-btn premium-btn-primary" style="justify-content: center;">
                        <i data-lucide="save" aria-hidden="true"></i>
                        <span>Save Progress</span>
                    </button>
                    <?php if (empty($taskAccess['is_pm'])): ?>
                    <button type="submit" name="workflow_action" value="submit_for_review" class="w-full sm:flex-1 premium-btn premium-btn-secondary text-emerald-700" style="justify-content: center; border-color: var(--premium-accent);">
                        <i data-lucide="send" aria-hidden="true"></i>
                        <span>Submit for Review</span>
                    </button>
                    <?php endif; ?>
                    <?php endif; ?>
                </div>
            </form>
        </section>
        </div>
        </section>
        <!-- /Progress panel -->

        <section class="task-tab-panel" role="tabpanel" id="tab-panel-brief" aria-labelledby="tab-btn-brief" tabindex="0" hidden>
        <div class="task-tab-content-wrapper">
        <section class="premium-card">
            <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
                <div class="premium-card-title m-0">
                    <i data-lucide="file-text" class="text-emerald-600" aria-hidden="true"></i>
                    <span>Task brief</span>
                </div>
                <?php if (!empty($taskAccess['can_edit'])): ?>
                <a href="edit?id=<?php echo $taskId; ?>" class="premium-btn premium-btn-secondary text-xs" style="padding:0.35rem 0.65rem;">
                    Edit details
                </a>
                <?php endif; ?>
            </div>

            <?php if (!empty($task['description'])): ?>
            <div class="premium-subtitle" style="line-height: 1.7; color: #475569; margin-bottom: 1.25rem;">
                <?php echo nl2br(htmlspecialchars($task['description'])); ?>
            </div>
            <?php else: ?>
            <p class="text-sm text-slate-400 italic mb-5">No description provided for this task.</p>
            <?php endif; ?>

            <div class="flex flex-wrap gap-2 mb-6">
                <span class="px-2.5 py-1 rounded-full text-[10px] font-bold uppercase tracking-wider <?php echo !empty($task['require_document_submission']) ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-500'; ?>">
                    <?php echo !empty($task['require_document_submission']) ? 'Evidence required' : 'Evidence optional'; ?>
                </span>
                <span class="px-2.5 py-1 rounded-full text-[10px] font-bold uppercase tracking-wider <?php echo !empty($task['require_procedure_tracking']) ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-500'; ?>">
                    <?php echo !empty($task['require_procedure_tracking']) ? 'Procedure log required' : 'Procedure log optional'; ?>
                </span>
            </div>

            <div class="rounded-xl border border-slate-100 bg-slate-50/60 p-4">
                <div class="flex flex-wrap items-start justify-between gap-3 mb-3">
                    <div>
                        <h3 class="text-sm font-bold text-slate-800 m-0">Planned checklist</h3>
                        <p class="text-xs text-slate-500 m-0 mt-1">The task plan. Daily progress updates below do not overwrite it.</p>
                    </div>
                    <?php if (!empty($taskAccess['can_edit'])): ?>
                    <a href="edit?id=<?php echo $taskId; ?>" class="text-xs font-bold text-emerald-700 hover:underline">Edit checklist</a>
                    <?php endif; ?>
                </div>

                <?php if (empty($procedureSteps)): ?>
                <p class="text-sm text-slate-500 m-0">No planned checklist has been defined for this task yet.</p>
                <?php else: ?>
                <ol class="space-y-2 m-0 pl-0 list-none">
                    <?php foreach ($procedureSteps as $step): ?>
                    <li class="rounded-lg border border-slate-100 bg-white px-4 py-3">
                        <div class="text-[10px] font-bold uppercase tracking-wider text-emerald-700 mb-1">Step <?php echo (int) ($step['step_order'] ?? 0); ?></div>
                        <div class="text-sm font-semibold text-slate-800"><?php echo htmlspecialchars((string) ($step['instruction'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></div>
                        <?php if (!empty($step['note'])): ?>
                        <div class="text-xs text-slate-500 mt-2"><?php echo nl2br(htmlspecialchars((string) $step['note'], ENT_QUOTES, 'UTF-8')); ?></div>
                        <?php endif; ?>
                    </li>
                    <?php endforeach; ?>
                </ol>
                <?php endif; ?>
            </div>
        </section>
        </div>
        </section>
        <!-- /Brief panel -->

        <?php if (!$taskWorkspaceShowGroupChat): ?>
        <section class="task-tab-panel" role="tabpanel" id="tab-panel-discussion" aria-labelledby="tab-btn-discussion" tabindex="0" hidden>
        <div class="task-tab-content-wrapper">
        <section class="premium-card">
            <div class="premium-card-title mb-6 w-full flex-wrap justify-between gap-3">
                <span class="inline-flex items-center gap-3 min-w-0">
                    <i data-lucide="messages-square" class="flex-shrink-0 text-emerald-600" aria-hidden="true"></i>
                    <span>Discussion</span>
                </span>
                <span class="shrink-0 px-3 py-1 rounded-full bg-slate-100 text-slate-500 text-[10px] font-bold uppercase whitespace-nowrap"><?php echo number_format($taskCommentCount); ?> messages</span>
            </div>

            <div class="discussion-feed" id="task-feed" style="max-height: 300px; overflow-y: auto; padding-right: 0.5rem;">
                <?php if (empty($taskComments)): ?>
                <div class="p-6 text-center rounded-xl border border-dashed border-slate-200 bg-slate-50/40">
                    <i data-lucide="message-square-dashed" class="mx-auto block text-slate-300 mb-2" style="width:1.5rem;height:1.5rem;" aria-hidden="true"></i>
                    <p class="text-sm text-slate-400 m-0">No messages yet. Start the conversation.</p>
                </div>
                <?php else: ?>
                <?php foreach ($taskComments as $comment): ?>
                <?php
                $_tcAtts = $tc_attach_map[(int) ($comment['id'] ?? 0)] ?? [];
                ?>
                <div class="comment-bubble">
                    <div class="comment-header">
                        <span class="comment-author"><?php echo htmlspecialchars((string) ($comment['user_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></span>
                        <span class="comment-date"><?php echo date('M d, H:i', strtotime($comment['created_at'])); ?></span>
                    </div>
                    <p class="text-sm text-slate-700 leading-relaxed"><?php echo nl2br(htmlspecialchars((string) ($comment['comment'] ?? ''), ENT_QUOTES, 'UTF-8')); ?></p>
                    <?php if ($_tcAtts !== []): ?>
                    <div class="mt-2 flex flex-wrap gap-2">
                        <?php foreach ($_tcAtts as $tca): ?>
                        <?php
                        $tu = rtrim(BASE_URL, '/') . '/' . ltrim((string) ($tca['file_path'] ?? ''), '/');
                        ?>
                        <?php if (attachment_is_voice($tca)): ?>
                        <div class="flex flex-col gap-1 rounded-xl border border-slate-200 bg-white p-2 min-w-[200px] max-w-full">
                            <audio controls preload="metadata" class="w-full h-9" src="<?php echo htmlspecialchars($tu, ENT_QUOTES, 'UTF-8'); ?>"></audio>
                            <span class="text-[10px] font-bold text-slate-400 truncate"><?php echo htmlspecialchars((string) ($tca['file_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></span>
                        </div>
                        <?php else: ?>
                        <a href="<?php echo htmlspecialchars($tu, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener noreferrer" class="inline-flex items-center gap-1 px-3 py-1 rounded-lg bg-slate-100 text-xs font-semibold text-slate-600 hover:bg-emerald-50 hover:text-emerald-700">
                            <i data-lucide="paperclip" class="h-3.5 w-3.5" aria-hidden="true"></i>
                            <span class="truncate max-w-[140px]"><?php echo htmlspecialchars((string) ($tca['file_name'] ?? 'file'), ENT_QUOTES, 'UTF-8'); ?></span>
                        </a>
                        <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <form method="POST" action="comment" enctype="multipart/form-data" class="mt-5 p-3 bg-white rounded-xl border border-slate-200" id="taskDiscussionForm" novalidate>
                <input type="hidden" name="task_id" value="<?php echo $taskId; ?>">
                <input type="hidden" name="voice_note_sent" id="taskDiscussionVoiceFlag" value="0">
                <input type="hidden" name="redirect_to" value="modules/tasks/view?id=<?php echo $taskId; ?>">
                <label class="sr-only" for="taskDiscussionTa">Message</label>
                <textarea id="taskDiscussionTa" name="comment" rows="2" class="task-form-input" placeholder="Write a message..."></textarea>
                <input type="file" id="taskDiscussionFiles" name="comment_files[]" multiple accept=".jpg,.jpeg,.png,.gif,.webp,.pdf,.doc,.docx,.webm,.ogg,.opus,.mp3,.wav,.m4a,.aac,audio/*" class="hidden">
                <p class="text-xs text-slate-500 mt-2 min-h-[1rem]" id="taskDiscussionVoiceHint" aria-live="polite"></p>
                <div class="mt-3 flex flex-wrap items-center justify-between gap-2">
                    <div class="flex items-center gap-2">
                        <button type="button" class="inline-flex items-center justify-center rounded-xl border border-slate-200 bg-white p-2 text-slate-600 hover:bg-slate-100" title="Attach file" aria-label="Attach file" onclick="document.getElementById('taskDiscussionFiles').click();">
                            <i data-lucide="paperclip" class="h-4 w-4" aria-hidden="true"></i>
                        </button>
                        <button type="button" id="taskDiscussionMic" class="inline-flex items-center justify-center rounded-xl border border-slate-200 bg-white p-2 text-slate-600 hover:bg-slate-100 press-voice-btn" title="Voice note" aria-label="Record voice note">
                            <i data-lucide="mic" class="h-4 w-4" aria-hidden="true"></i>
                        </button>
                    </div>
                    <button type="submit" class="premium-btn premium-btn-primary">
                        <i data-lucide="send" class="text-sm" aria-hidden="true"></i>
                        <span>Post Message</span>
                    </button>
                </div>
            </form>
            <script>
            (function () {
                document.addEventListener('DOMContentLoaded', function () {
                    var form = document.getElementById('taskDiscussionForm');
                    var ta = document.getElementById('taskDiscussionTa');
                    var files = document.getElementById('taskDiscussionFiles');
                    var flag = document.getElementById('taskDiscussionVoiceFlag');
                    var hint = document.getElementById('taskDiscussionVoiceHint');
                    if (!form || !ta || !files) {
                        return;
                    }
                    files.addEventListener('change', function () {
                        if (flag) {
                            flag.value = '0';
                        }
                    });
                    form.addEventListener('submit', function (e) {
                        var t = String(ta.value || '').trim();
                        var hasF = files.files && files.files.length;
                        if (!t && !hasF) {
                            e.preventDefault();
                            var m = 'Add a message, attach a file, or record a voice note.';
                            if (typeof window.showToast === 'function') {
                                window.showToast(m, 'error');
                            } else {
                                alert(m);
                            }
                            return false;
                        }
                        return true;
                    });
                    if (window.PressVoiceNote) {
                        window.PressVoiceNote.bindToggle({
                            button: '#taskDiscussionMic',
                            fileInput: '#taskDiscussionFiles',
                            hiddenVoiceInput: '#taskDiscussionVoiceFlag',
                            statusEl: hint,
                            maxSeconds: 180,
                        });
                    }
                    if (typeof window.refreshAppShellIcons === 'function') {
                        window.refreshAppShellIcons();
                    }
                });
            })();
            </script>
        </section>
        </div>
        </section>
        <!-- /Discussion panel -->
        <?php endif; ?>

        <?php if (project_budget_enabled($task)): ?>
        <section class="task-tab-panel" role="tabpanel" id="tab-panel-expenses" aria-labelledby="tab-btn-expenses" tabindex="0" hidden>
        <div class="task-tab-content-wrapper">
        <section class="premium-card" id="task-expenses">
            <div class="premium-card-title mb-4 w-full flex-wrap justify-between gap-2">
                <span class="inline-flex items-center gap-2">
                    <i data-lucide="receipt" class="text-emerald-600" aria-hidden="true"></i>
                    <span>Expenses</span>
                </span>
                <span class="text-xs font-bold text-slate-400 uppercase">Project budget: <?php echo htmlspecialchars((string) ($task['budget_currency'] ?? 'USD')); ?></span>
            </div>
            <?php if (!empty($taskAccess['can_manage'])): ?>
            <form method="post" action="expense_save" enctype="multipart/form-data" class="mb-5 p-4 rounded-xl border border-slate-200 bg-white grid grid-cols-1 md:grid-cols-2 gap-3">
                <input type="hidden" name="task_id" value="<?php echo $taskId; ?>">
                <input type="hidden" name="redirect_to" value="modules/tasks/view?id=<?php echo $taskId; ?>">
                <div class="task-form-field">
                    <label class="task-form-field-label" for="exp-amount">Amount</label>
                    <input id="exp-amount" type="number" step="0.01" min="0" name="amount" required class="task-form-input">
                </div>
                <div class="task-form-field">
                    <label class="task-form-field-label" for="exp-currency">Currency</label>
                    <input id="exp-currency" type="text" name="currency" maxlength="3" class="task-form-input uppercase" value="<?php echo htmlspecialchars(strtoupper(substr((string) ($task['budget_currency'] ?? 'USD'), 0, 3))); ?>">
                </div>
                <div class="task-form-field md:col-span-2">
                    <label class="task-form-field-label" for="exp-desc">Description</label>
                    <textarea id="exp-desc" name="description" rows="2" class="task-form-input" placeholder="What was purchased?"></textarea>
                </div>
                <div class="task-form-field md:col-span-2">
                    <label class="task-form-field-label" for="exp-receipt">Receipt <span class="font-normal normal-case text-slate-400">(optional)</span></label>
                    <input id="exp-receipt" type="file" name="receipt" accept=".jpg,.jpeg,.png,.gif,.webp,.pdf,.doc,.docx" class="task-form-input task-form-input--evidence">
                </div>
                <div class="md:col-span-2">
                    <button type="submit" class="premium-btn premium-btn-primary text-sm" style="padding:0.45rem 1rem">Add expense</button>
                </div>
            </form>
            <?php endif; ?>
            <?php if (empty($taskExpenses)): ?>
            <p class="text-sm text-slate-500 m-0">No expenses recorded for this task yet.</p>
            <?php else: ?>
            <ul class="divide-y divide-slate-100 border border-slate-100 rounded-xl overflow-hidden">
                <?php foreach ($taskExpenses as $ex): ?>
                <li class="p-3 flex flex-wrap items-start justify-between gap-2 bg-white">
                    <div class="min-w-0">
                        <p class="font-bold text-slate-800 m-0"><?php echo htmlspecialchars(number_format((float) ($ex['amount'] ?? 0), 2)); ?> <?php echo htmlspecialchars((string) ($ex['currency'] ?? '')); ?></p>
                        <p class="text-xs text-slate-500 m-0"><?php echo htmlspecialchars(date('M j, Y g:i A', strtotime((string) ($ex['created_at'] ?? 'now')))); ?> · <?php echo htmlspecialchars((string) ($ex['creator_name'] ?? '')); ?></p>
                        <?php if (!empty($ex['description'])): ?>
                        <p class="text-sm text-slate-600 m-0 mt-1"><?php echo nl2br(htmlspecialchars((string) $ex['description'])); ?></p>
                        <?php endif; ?>
                        <?php if (!empty($ex['receipt_file_path'])): ?>
                        <a href="<?php echo htmlspecialchars(rtrim(BASE_URL, '/') . '/' . ltrim((string) $ex['receipt_file_path'], '/')); ?>" class="text-xs font-bold text-emerald-700 hover:underline mt-1 inline-block" target="_blank" rel="noopener noreferrer">View receipt</a>
                        <?php endif; ?>
                    </div>
                    <?php
                    $uid = (int) ($_SESSION['user_id'] ?? 0);
                    $canDelEx = ((int) ($ex['created_by'] ?? 0) === $uid) || !empty($taskAccess['is_pm']) || hasPermission('manage_projects');
                    ?>
                    <?php if ($canDelEx): ?>
                    <form method="post" action="expense_delete" class="shrink-0" onsubmit="return confirm('Remove this expense?');">
                        <input type="hidden" name="expense_id" value="<?php echo (int) ($ex['id'] ?? 0); ?>">
                        <input type="hidden" name="redirect_to" value="modules/tasks/view?id=<?php echo $taskId; ?>">
                        <button type="submit" class="text-xs font-bold text-rose-600 hover:underline">Delete</button>
                    </form>
                    <?php endif; ?>
                </li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>
        </section>
        </div>
        </section>
        <!-- /Expenses panel -->
        <?php endif; ?>

        <section class="task-tab-panel" role="tabpanel" id="tab-panel-history" aria-labelledby="tab-btn-history" tabindex="0" hidden>
        <div class="task-tab-content-wrapper">
        <section class="premium-card doc-hub-container" id="task-work-history-hub" aria-labelledby="task-work-history-heading">
            <?php
            $documentationAttachmentCount = 0;
            foreach ($documentations as $_drow) {
                if (!empty(trim((string) ($_drow['document_path'] ?? '')))) {
                    $documentationAttachmentCount++;
                }
            }
            ?>
            <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between mb-6">
                <div class="min-w-0">
                    <h2 id="task-work-history-heading" class="flex items-center gap-3 text-xl font-extrabold text-slate-900 tracking-tight mb-2">
                        <i data-lucide="folder-heart" class="text-emerald-600 flex-shrink-0" aria-hidden="true"></i>
                        <span>Work history &amp; evidence</span>
                    </h2>
                    <p class="text-sm text-slate-500 max-w-xl">Chronological progress logs, recorded procedures, outcomes, and older documentation entries live together here.</p>
                </div>
                <div class="flex flex-wrap items-center gap-2 shrink-0">
                    <span class="px-3 py-1.5 rounded-full bg-white border border-emerald-200 text-emerald-700 text-xs font-bold uppercase tracking-wide whitespace-nowrap">
                        <?php echo number_format($taskWorkHistoryCount); ?> histor<?php echo $taskWorkHistoryCount === 1 ? 'y item' : 'y items'; ?>
                        · <?php echo number_format($taskProgressAttachmentCount + $documentationAttachmentCount); ?> evidence file<?php echo ($taskProgressAttachmentCount + $documentationAttachmentCount) === 1 ? '' : 's'; ?>
                    </span>
                </div>
            </div>

            <?php if (empty($taskWorkHistoryItems)): ?>
            <div class="p-8 text-center bg-white/50 rounded-2xl border border-dashed border-emerald-200">
                <i data-lucide="package" class="text-4xl text-emerald-200 mb-2" aria-hidden="true"></i>
                <p class="text-slate-500 font-medium">No work history has been recorded yet.</p>
            </div>
            <?php else: ?>
            <div class="doc-search-wrapper">
                <label for="taskWorkHistorySearch" class="sr-only">Filter work history</label>
                <i data-lucide="search" class="pointer-events-none absolute left-4 top-1/2 z-[1] h-5 w-5 -translate-y-1/2 text-slate-400" aria-hidden="true"></i>
                <input
                    type="search"
                    id="taskWorkHistorySearch"
                    class="doc-search-input"
                    placeholder="Search by teammate, procedures, notes, filenames, or status..."
                    autocomplete="off"
                    aria-controls="taskWorkHistoryList"
                >
            </div>

            <div class="flex flex-col gap-4" id="taskWorkHistoryList">
                <?php foreach ($taskWorkHistoryItems as $historyItem): ?>
                <?php if (($historyItem['kind'] ?? '') === 'progress'): ?>
                <?php
                $log = $historyItem['row'];
                $logUser = trim((string) ($log['user_name'] ?? '')) !== '' ? (string) $log['user_name'] : 'Unknown';
                $logWorkDone = trim((string) ($log['work_done'] ?? ''));
                $logNextWork = trim((string) ($log['next_work'] ?? ''));
                $logOutcome = trim((string) ($log['outcome_text'] ?? ''));
                $logStatusLine = !empty($log['has_status_change'])
                    ? trim((string) ($log['old_status'] ?? '')) . ' → ' . trim((string) ($log['new_status'] ?? ''))
                    : ('Logged under ' . trim((string) ($log['new_status'] ?? ($task['status'] ?? 'Current status'))));
                $logSearchParts = [$logUser, $logWorkDone, $logNextWork, $logOutcome, $logStatusLine];
                foreach (($log['steps'] ?? []) as $logStep) {
                    $logSearchParts[] = (string) ($logStep['procedure_text'] ?? '');
                    $logSearchParts[] = (string) ($logStep['output_text'] ?? '');
                    foreach (($logStep['attachments'] ?? []) as $stepAttachment) {
                        $logSearchParts[] = (string) ($stepAttachment['original_name'] ?? '');
                    }
                }
                foreach (($log['entry_attachments'] ?? []) as $entryAttachment) {
                    $logSearchParts[] = (string) ($entryAttachment['original_name'] ?? '');
                }
                $logSearch = strtolower(implode(' ', array_filter($logSearchParts)));
                ?>
                <article class="task-work-history-item rounded-2xl border border-emerald-100 bg-white/95 p-5 shadow-sm" data-doc-search="<?php echo htmlspecialchars($logSearch, ENT_QUOTES, 'UTF-8'); ?>">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <div class="flex flex-wrap items-center gap-2 mb-2">
                                <span class="px-2.5 py-1 rounded-full bg-emerald-50 text-emerald-700 text-[10px] font-bold uppercase tracking-wider">Progress entry</span>
                                <span class="px-2.5 py-1 rounded-full bg-slate-100 text-slate-600 text-[10px] font-bold uppercase tracking-wider"><?php echo htmlspecialchars($logStatusLine, ENT_QUOTES, 'UTF-8'); ?></span>
                            </div>
                            <h3 class="text-sm font-bold text-slate-800 m-0"><?php echo htmlspecialchars($logUser, ENT_QUOTES, 'UTF-8'); ?></h3>
                            <p class="text-xs text-slate-500 m-0 mt-1"><?php echo date('M d, Y · H:i', strtotime((string) ($log['created_at'] ?? 'now'))); ?></p>
                        </div>
                        <span class="text-xs font-bold text-slate-400 uppercase"><?php echo number_format((int) ($log['attachment_count'] ?? 0)); ?> file<?php echo (int) ($log['attachment_count'] ?? 0) === 1 ? '' : 's'; ?></span>
                    </div>

                    <?php if ($logWorkDone !== ''): ?>
                    <div class="mt-4">
                        <div class="text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-1">Work done</div>
                        <p class="text-sm text-slate-700 leading-relaxed m-0"><?php echo nl2br(htmlspecialchars($logWorkDone, ENT_QUOTES, 'UTF-8')); ?></p>
                    </div>
                    <?php endif; ?>

                    <?php if (!empty($log['steps'])): ?>
                    <div class="mt-4 space-y-3">
                        <div class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Procedures performed</div>
                        <?php foreach (($log['steps'] ?? []) as $logStep): ?>
                        <div class="rounded-xl border border-emerald-100 bg-emerald-50/50 p-4">
                            <div class="text-xs font-bold text-emerald-700 uppercase tracking-wider mb-1">Procedure <?php echo (int) ($logStep['step_order'] ?? 0); ?></div>
                            <div class="text-sm font-semibold text-slate-800"><?php echo htmlspecialchars((string) ($logStep['procedure_text'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></div>
                            <?php if (!empty($logStep['output_text'])): ?>
                            <div class="text-xs text-slate-600 mt-2"><?php echo nl2br(htmlspecialchars((string) $logStep['output_text'], ENT_QUOTES, 'UTF-8')); ?></div>
                            <?php endif; ?>
                            <?php if (!empty($logStep['attachments'])): ?>
                            <div class="mt-3 flex flex-wrap gap-2">
                                <?php foreach ($logStep['attachments'] as $stepAttachment): ?>
                                <?php $stepAttachmentHref = rtrim(BASE_URL, '/') . '/' . ltrim((string) ($stepAttachment['file_path'] ?? ''), '/'); ?>
                                <a href="<?php echo htmlspecialchars($stepAttachmentHref, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener noreferrer" class="inline-flex items-center gap-2 rounded-full border border-emerald-200 bg-white px-3 py-1.5 text-xs font-semibold text-emerald-700 hover:bg-emerald-100">
                                    <i data-lucide="paperclip" class="h-3.5 w-3.5" aria-hidden="true"></i>
                                    <span><?php echo htmlspecialchars((string) ($stepAttachment['original_name'] ?? 'Evidence'), ENT_QUOTES, 'UTF-8'); ?></span>
                                </a>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>

                    <?php if ($logNextWork !== '' || $logOutcome !== ''): ?>
                    <div class="mt-4 grid grid-cols-1 md:grid-cols-2 gap-4">
                        <?php if ($logNextWork !== ''): ?>
                        <div class="rounded-xl border border-slate-200 bg-slate-50 p-4">
                            <div class="text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-1">Next scheduled work</div>
                            <p class="text-sm text-slate-700 leading-relaxed m-0"><?php echo nl2br(htmlspecialchars($logNextWork, ENT_QUOTES, 'UTF-8')); ?></p>
                        </div>
                        <?php endif; ?>
                        <?php if ($logOutcome !== ''): ?>
                        <div class="rounded-xl border border-slate-200 bg-slate-50 p-4">
                            <div class="text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-1">Current outcome</div>
                            <p class="text-sm text-slate-700 leading-relaxed m-0"><?php echo nl2br(htmlspecialchars($logOutcome, ENT_QUOTES, 'UTF-8')); ?></p>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>

                    <?php if (!empty($log['entry_attachments'])): ?>
                    <div class="mt-4">
                        <div class="text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-2">Entry evidence</div>
                        <div class="flex flex-wrap gap-2">
                            <?php foreach (($log['entry_attachments'] ?? []) as $entryAttachment): ?>
                            <?php $entryAttachmentHref = rtrim(BASE_URL, '/') . '/' . ltrim((string) ($entryAttachment['file_path'] ?? ''), '/'); ?>
                            <a href="<?php echo htmlspecialchars($entryAttachmentHref, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener noreferrer" class="inline-flex items-center gap-2 rounded-full border border-slate-200 bg-slate-50 px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-emerald-50 hover:text-emerald-700">
                                <i data-lucide="paperclip" class="h-3.5 w-3.5" aria-hidden="true"></i>
                                <span><?php echo htmlspecialchars((string) ($entryAttachment['original_name'] ?? 'Evidence'), ENT_QUOTES, 'UTF-8'); ?></span>
                            </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </article>
                <?php else: ?>
                <?php
                $doc = $historyItem['row'];
                $docPath = trim((string) ($doc['document_path'] ?? ''));
                $docHref = $docPath !== '' ? rtrim(BASE_URL, '/') . '/' . ltrim($docPath, '/') : '';
                $docUser = trim((string) ($doc['user_name'] ?? '')) !== '' ? (string) $doc['user_name'] : 'Unknown';
                $docTextRaw = trim((string) ($doc['documentation_text'] ?? ''));
                $oldSt = trim((string) ($doc['old_status'] ?? ''));
                $newSt = trim((string) ($doc['new_status'] ?? ''));
                if ($oldSt !== '' && $newSt !== '') {
                    $statusLine = $oldSt . ' → ' . $newSt;
                } elseif ($newSt !== '') {
                    $statusLine = $newSt;
                } elseif ($oldSt !== '') {
                    $statusLine = $oldSt;
                } else {
                    $statusLine = 'Documentation';
                }
                $docSearch = strtolower(implode(' ', array_filter([$docUser, $docTextRaw, $statusLine, $docPath])));
                ?>
                <article class="task-work-history-item rounded-2xl border border-slate-200 bg-slate-50/70 p-5 shadow-sm" data-doc-search="<?php echo htmlspecialchars($docSearch, ENT_QUOTES, 'UTF-8'); ?>">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <div class="flex flex-wrap items-center gap-2 mb-2">
                                <span class="px-2.5 py-1 rounded-full bg-slate-200 text-slate-700 text-[10px] font-bold uppercase tracking-wider">Legacy documentation</span>
                                <span class="px-2.5 py-1 rounded-full bg-white text-emerald-700 text-[10px] font-bold uppercase tracking-wider"><?php echo htmlspecialchars($statusLine, ENT_QUOTES, 'UTF-8'); ?></span>
                            </div>
                            <h3 class="text-sm font-bold text-slate-800 m-0"><?php echo htmlspecialchars($docUser, ENT_QUOTES, 'UTF-8'); ?></h3>
                            <p class="text-xs text-slate-500 m-0 mt-1"><?php echo date('M d, Y · H:i', strtotime((string) ($doc['created_at'] ?? 'now'))); ?></p>
                        </div>
                        <?php if ($docHref !== ''): ?>
                        <a href="<?php echo htmlspecialchars($docHref, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener noreferrer" class="inline-flex items-center gap-2 rounded-full border border-slate-200 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-emerald-50 hover:text-emerald-700">
                            <i data-lucide="external-link" class="h-3.5 w-3.5" aria-hidden="true"></i>
                            <span>Open file</span>
                        </a>
                        <?php endif; ?>
                    </div>

                    <?php if ($docTextRaw !== ''): ?>
                    <div class="mt-4 text-sm text-slate-700 leading-relaxed"><?php echo nl2br(htmlspecialchars($docTextRaw, ENT_QUOTES, 'UTF-8')); ?></div>
                    <?php else: ?>
                    <p class="mt-4 text-sm text-slate-500 m-0">This older documentation entry only contains an attached file.</p>
                    <?php endif; ?>
                </article>
                <?php endif; ?>
                <?php endforeach; ?>
            </div>
            <p id="taskWorkHistoryEmptyState" class="hidden text-center py-6 text-slate-400 italic">Nothing matches your search.</p>
            <?php endif; ?>

            <div class="mt-8 border-t border-slate-200 pt-6">
                <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
                    <div>
                        <h3 class="text-sm font-bold text-slate-800 m-0">Task reference files</h3>
                        <p class="text-xs text-slate-500 m-0 mt-1">Persistent files attached to the task outside individual work-log entries.</p>
                    </div>
                    <span class="px-3 py-1 rounded-full bg-slate-100 text-slate-500 text-[10px] font-bold uppercase"><?php echo number_format(count($generalAttachments)); ?> file<?php echo count($generalAttachments) === 1 ? '' : 's'; ?></span>
                </div>

                <?php if (empty($generalAttachments)): ?>
                <p class="text-sm text-slate-500 m-0">No task reference files yet.</p>
                <?php else: ?>
                <div class="space-y-3">
                    <?php foreach ($generalAttachments as $attachment): ?>
                    <?php $referenceHref = rtrim(BASE_URL, '/') . '/' . ltrim((string) ($attachment['file_path'] ?? ''), '/'); ?>
                    <div class="flex flex-col gap-2 rounded-xl border border-slate-200 bg-white p-4 sm:flex-row sm:items-center sm:justify-between">
                        <div class="min-w-0">
                            <div class="text-sm font-semibold text-slate-800 truncate"><?php echo htmlspecialchars((string) ($attachment['original_name'] ?? 'Attachment'), ENT_QUOTES, 'UTF-8'); ?></div>
                            <div class="text-xs text-slate-500 mt-1">
                                <?php echo htmlspecialchars((string) ($attachment['uploader_name'] ?? 'Unknown'), ENT_QUOTES, 'UTF-8'); ?>
                                · <?php echo date('M d, Y · H:i', strtotime((string) ($attachment['created_at'] ?? 'now'))); ?>
                            </div>
                        </div>
                        <a href="<?php echo htmlspecialchars($referenceHref, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener noreferrer" class="inline-flex items-center gap-2 rounded-full border border-slate-200 bg-slate-50 px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-emerald-50 hover:text-emerald-700">
                            <i data-lucide="paperclip" class="h-3.5 w-3.5" aria-hidden="true"></i>
                            <span>Open file</span>
                        </a>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </section>
        </div>
        </section>
        <!-- /History panel -->

        <?php if (!empty($reviews)): ?>
        <section class="task-tab-panel" role="tabpanel" id="tab-panel-reviews" aria-labelledby="tab-btn-reviews" tabindex="0" hidden>
        <div class="task-tab-content-wrapper">
        <section class="premium-card" aria-label="Review history">
            <div class="premium-card-title m-0 mb-4">
                <i data-lucide="gavel" class="text-emerald-600" aria-hidden="true"></i>
                <span>Review history</span>
            </div>
            <p class="text-sm text-slate-500 m-0 mb-5">Every approval, change request, and rejection issued on this task.</p>
            <ol class="space-y-3 m-0 pl-0 list-none">
                <?php foreach ($reviews as $review): ?>
                <?php
                $reviewAction = (string) ($review['action'] ?? '');
                $reviewActionLabel = ucwords(str_replace('_', ' ', $reviewAction));
                $reviewToneClass = 'text-slate-500';
                $reviewToneIcon = 'circle';
                if ($reviewAction === 'approved') {
                    $reviewToneClass = 'text-emerald-600';
                    $reviewToneIcon = 'circle-check';
                } elseif ($reviewAction === 'rejected') {
                    $reviewToneClass = 'text-rose-600';
                    $reviewToneIcon = 'circle-x';
                } elseif ($reviewAction === 'requested_changes') {
                    $reviewToneClass = 'text-amber-600';
                    $reviewToneIcon = 'circle-alert';
                }
                ?>
                <li class="p-4 rounded-xl border border-slate-200 bg-white">
                    <div class="flex flex-wrap items-center justify-between gap-2 mb-1">
                        <span class="font-bold text-slate-800 text-sm"><?php echo htmlspecialchars((string) ($review['reviewer_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></span>
                        <span class="text-xs font-semibold text-slate-400"><?php echo date('M d, Y · H:i', strtotime((string) ($review['created_at'] ?? 'now'))); ?></span>
                    </div>
                    <div class="text-xs font-bold uppercase tracking-wide inline-flex items-center gap-1.5 <?php echo $reviewToneClass; ?>">
                        <i data-lucide="<?php echo $reviewToneIcon; ?>" class="h-3.5 w-3.5" aria-hidden="true"></i>
                        <?php echo htmlspecialchars($reviewActionLabel, ENT_QUOTES, 'UTF-8'); ?>
                    </div>
                    <?php if (!empty($review['note'])): ?>
                    <p class="text-sm text-slate-700 m-0 mt-3 leading-relaxed italic">“<?php echo htmlspecialchars((string) $review['note'], ENT_QUOTES, 'UTF-8'); ?>”</p>
                    <?php endif; ?>
                </li>
                <?php endforeach; ?>
            </ol>
        </section>
        </div>
        </section>
        <!-- /Reviews panel -->
        <?php endif; ?>
        </div>
        <!-- /task-tab-panels -->
    </div>
    <?php
    if (!empty($taskWorkspaceShowGroupChat)) {
        $workspaceGroupChatShow = true;
        $workspaceGroupChatTitle = $taskWorkspaceGroupChatTitle;
        $workspaceGroupChatFeedId = 'workspace-task-group-chat-feed';
        $workspaceGroupChatFormAction = 'comment';
        $workspaceGroupChatFormMethod = 'POST';
        $workspaceGroupChatFormEnctype = 'multipart/form-data';
        $workspaceGroupChatHiddenFields = [
            'task_id' => $taskId,
            'redirect_to' => 'modules/tasks/view?id=' . $taskId,
        ];
        $workspaceGroupChatParticipants = $taskWorkspaceParticipants;
        $workspaceGroupChatMessages = $taskChatMessages;
        $workspaceGroupChatCurrentUserId = $userId;
        include __DIR__ . '/../../includes/partials/workspace_group_chat_sidebar.php';
    }
    ?>
    </div>
    </div>

<script>
/**
 * Task view: horizontal tabs + unsaved-work navigation guard.
 *
 * Tabs are client-side (no reload). Switching tabs never loses work.
 * Real navigation (refresh, close, external link click, "Back to tasks")
 * is guarded so a user with unsaved changes is asked to confirm.
 */
(function () {
    var tabList = document.getElementById('taskTabs');
    if (!tabList) {
        return;
    }

    var tabButtons = Array.prototype.slice.call(tabList.querySelectorAll('[role="tab"]'));
    var panelHost = document.querySelector('.task-tab-panels');
    var panels = panelHost ? Array.prototype.slice.call(panelHost.querySelectorAll('.task-tab-panel')) : [];
    var unsavedBanner = document.getElementById('taskUnsavedBanner');

    // ---------- Tab switching ----------
    function tabIdFromHash(hash) {
        if (!hash) return '';
        var raw = String(hash).replace(/^#/, '').toLowerCase();
        return raw;
    }

    function findButton(tabId) {
        for (var i = 0; i < tabButtons.length; i++) {
            if (tabButtons[i].getAttribute('data-tab') === tabId) {
                return tabButtons[i];
            }
        }
        return null;
    }

    function setActiveTab(tabId, options) {
        var opts = options || {};
        var target = findButton(tabId);
        if (!target) {
            target = tabButtons[0];
            if (!target) return;
            tabId = target.getAttribute('data-tab');
        }

        tabButtons.forEach(function (btn) {
            var isActive = (btn === target);
            btn.setAttribute('aria-selected', isActive ? 'true' : 'false');
            btn.setAttribute('tabindex', isActive ? '0' : '-1');
            btn.classList.toggle('task-tab--active', isActive);
        });

        panels.forEach(function (panel) {
            var pid = panel.getAttribute('id') || '';
            var match = pid === ('tab-panel-' + tabId);
            if (match) {
                panel.removeAttribute('hidden');
            } else {
                panel.setAttribute('hidden', '');
            }
        });

        if (opts.updateHash !== false) {
            try {
                if (history && history.replaceState) {
                    history.replaceState(null, '', '#' + tabId);
                } else {
                    location.hash = tabId;
                }
            } catch (err) {
                /* hash update best-effort */
            }
        }

        if (opts.focus) {
            try { target.focus({ preventScroll: true }); } catch (e) { target.focus(); }
        }

        if (typeof window.refreshAppShellIcons === 'function') {
            window.refreshAppShellIcons();
        }
    }

    tabButtons.forEach(function (btn) {
        btn.addEventListener('click', function () {
            setActiveTab(btn.getAttribute('data-tab'));
        });
    });

    // Keyboard nav: ArrowLeft/Right + Home/End within the tablist
    tabList.addEventListener('keydown', function (event) {
        var key = event.key;
        if (['ArrowLeft', 'ArrowRight', 'Home', 'End'].indexOf(key) === -1) {
            return;
        }
        var current = document.activeElement;
        var idx = tabButtons.indexOf(current);
        if (idx === -1) {
            return;
        }
        event.preventDefault();
        var next = idx;
        if (key === 'ArrowLeft') next = (idx - 1 + tabButtons.length) % tabButtons.length;
        else if (key === 'ArrowRight') next = (idx + 1) % tabButtons.length;
        else if (key === 'Home') next = 0;
        else if (key === 'End') next = tabButtons.length - 1;
        var btn = tabButtons[next];
        if (btn) {
            setActiveTab(btn.getAttribute('data-tab'), { focus: true });
        }
    });

    // Hero / insight CTAs that should switch tabs
    document.querySelectorAll('[data-task-tab-link]').forEach(function (link) {
        link.addEventListener('click', function (event) {
            event.preventDefault();
            setActiveTab(link.getAttribute('data-task-tab-link'));
            var hashTarget = (link.getAttribute('href') || '').replace(/^#/, '');
            if (hashTarget) {
                try { history.replaceState(null, '', '#' + hashTarget); } catch (e) { /* noop */ }
            }
            tabList.scrollIntoView({ behavior: 'smooth', block: 'start' });
        });
    });

    // Hash-based deep links + back/forward
    window.addEventListener('hashchange', function () {
        var tabId = tabIdFromHash(location.hash);
        if (tabId) setActiveTab(tabId, { updateHash: false });
    });

    // Initial tab from URL hash, or default to overview
    var initialTab = tabIdFromHash(location.hash) || 'overview';
    setActiveTab(initialTab, { updateHash: !!location.hash });

    // ---------- Unsaved-work navigation guard ----------
    var taskShellEl = document.querySelector('.task-view-shell');
    if (!taskShellEl) {
        return;
    }

    var dirtyForms = {};
    var formTabMap = {};
    var dirtyMessage = 'You have unsaved changes on this task. Leave anyway and lose them?';
    var tabLabels = {
        overview: 'Overview',
        progress: 'the progress form',
        brief: 'Brief',
        discussion: 'Discussion',
        history: 'Work history',
        expenses: 'Expenses',
        reviews: 'Reviews'
    };

    function panelForElement(el) {
        var panel = el && el.closest ? el.closest('.task-tab-panel') : null;
        if (!panel) return '';
        var id = panel.getAttribute('id') || '';
        return id.replace(/^tab-panel-/, '');
    }

    function firstDirtyTab() {
        var ids = Object.keys(dirtyForms).filter(function (k) { return dirtyForms[k]; });
        for (var i = 0; i < ids.length; i++) {
            var tab = formTabMap[ids[i]];
            if (tab) return tab;
        }
        return '';
    }

    function recomputeBanner() {
        if (!unsavedBanner) return;
        var anyDirty = Object.keys(dirtyForms).some(function (key) { return dirtyForms[key]; });
        unsavedBanner.classList.toggle('is-shown', anyDirty);
        if (anyDirty) {
            var hint = unsavedBanner.querySelector('[data-task-unsaved-tab-hint]');
            var tab = firstDirtyTab();
            if (hint) {
                hint.textContent = tab && tabLabels[tab] ? ' in ' + tabLabels[tab] : '';
            }
            var jumpBtn = document.getElementById('taskUnsavedJumpBtn');
            if (jumpBtn) {
                jumpBtn.style.display = tab ? '' : 'none';
                jumpBtn.dataset.targetTab = tab || '';
            }
        }
    }

    function markFormDirty(formId, isDirty) {
        if (!formId) return;
        dirtyForms[formId] = !!isDirty;

        if (formId === 'taskManageForm') {
            var marker = document.querySelector('[data-tab-dirty-marker="progress"]');
            if (marker) {
                if (isDirty) marker.removeAttribute('hidden');
                else marker.setAttribute('hidden', '');
            }
        }

        recomputeBanner();
    }

    function isAnyDirty() {
        return Object.keys(dirtyForms).some(function (key) { return dirtyForms[key]; });
    }

    var unsavedJumpBtn = document.getElementById('taskUnsavedJumpBtn');
    if (unsavedJumpBtn) {
        unsavedJumpBtn.addEventListener('click', function () {
            var tab = unsavedJumpBtn.dataset.targetTab || '';
            if (tab) setActiveTab(tab);
        });
    }

    // Track edits on every form inside the task workspace
    var watchedForms = Array.prototype.slice.call(taskShellEl.querySelectorAll('form'));
    watchedForms.forEach(function (form) {
        var formId = form.id || ('taskForm_' + Math.random().toString(36).slice(2, 8));
        if (!form.id) {
            form.id = formId;
        }

        formTabMap[formId] = panelForElement(form);

        var markDirty = function () { markFormDirty(formId, true); };
        form.addEventListener('input', markDirty);
        form.addEventListener('change', markDirty);

        // Submitting any form means the user is about to save → drop the dirty flag for it.
        // Also propagate the current tab to `redirect_to` so the user lands back on the same tab.
        form.addEventListener('submit', function (event) {
            try {
                var activeBtn = tabList.querySelector('[role="tab"][aria-selected="true"]');
                var activeTab = activeBtn ? activeBtn.getAttribute('data-tab') : '';
                if (activeTab) {
                    var redirectInput = form.querySelector('input[name="redirect_to"]');
                    if (redirectInput && redirectInput.value && redirectInput.value.indexOf('#') === -1) {
                        redirectInput.value = redirectInput.value + '#' + activeTab;
                    }
                }
            } catch (e) {
                /* best-effort */
            }

            // Clear dirty up front so the beforeunload guard does not fire during a real submit.
            markFormDirty(formId, false);

            // If another listener (e.g. validation) prevents the submit, re-mark dirty in next tick.
            setTimeout(function () {
                if (event.defaultPrevented) {
                    markFormDirty(formId, true);
                }
            }, 0);
        });
    });

    // beforeunload prompt (covers F5, tab close, external URL change)
    window.addEventListener('beforeunload', function (event) {
        if (!isAnyDirty()) return;
        event.preventDefault();
        event.returnValue = dirtyMessage;
        return dirtyMessage;
    });

    // Internal anchor clicks: warn before leaving page (tabs themselves use buttons, not anchors)
    document.body.addEventListener('click', function (event) {
        if (!isAnyDirty()) return;
        var anchor = event.target.closest && event.target.closest('a[href]');
        if (!anchor) return;
        // Skip tab-switch anchors (handled above) and intra-page hash anchors
        if (anchor.hasAttribute('data-task-tab-link')) return;
        var href = anchor.getAttribute('href') || '';
        if (href === '' || href.charAt(0) === '#') return;
        // Skip anchors that open in a new tab / window – user keeps current page
        if (anchor.target && anchor.target !== '' && anchor.target !== '_self') return;
        if (anchor.hasAttribute('download')) return;
        if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
        if (event.button !== 0) return;

        if (!window.confirm(dirtyMessage)) {
            event.preventDefault();
        }
    }, true);
})();
</script>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const addProgressProcedureButton = document.getElementById('addProgressProcedureStep');
    const progressProcedureList = document.getElementById('progressProcedureList');
    const taskStatus = document.getElementById('taskStatus');
    const taskManageForm = document.getElementById('taskManageForm');
    const originalStatus = <?php echo json_encode($task['status']); ?>;
    const requiresDocument = <?php echo !empty($task['require_document_submission']) ? 'true' : 'false'; ?>;
    const requiresProcedure = <?php echo !empty($task['require_procedure_tracking']) ? 'true' : 'false'; ?>;

    function escapeHtml(value) {
        return String(value || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function syncProgressProcedureRows() {
        if (!progressProcedureList) {
            return;
        }

        Array.prototype.forEach.call(progressProcedureList.querySelectorAll('.progress-procedure-item'), function(stepItem, index) {
            stepItem.setAttribute('data-step-index', String(index));
            const badge = stepItem.querySelector('.progress-procedure-badge');
            if (badge) {
                badge.textContent = 'Procedure ' + (index + 1);
            }

            const fileInput = stepItem.querySelector('input[type="file"]');
            if (fileInput) {
                fileInput.name = 'progress_step_attachments[' + index + '][]';
            }
        });
    }

    function buildProgressProcedureStep() {
        const wrapper = document.createElement('div');
        wrapper.className = 'progress-procedure-item bg-white border border-emerald-100 rounded-xl p-4';
        wrapper.innerHTML =
            '<div class="flex flex-wrap items-center justify-between gap-3 mb-3">' +
                '<span class="progress-procedure-badge inline-flex items-center px-3 py-1 rounded-full bg-emerald-50 text-emerald-700 text-sm font-semibold">Procedure</span>' +
                '<div class="flex items-center gap-2">' +
                    '<button type="button" class="progress-procedure-move-up inline-flex items-center justify-center h-9 w-9 rounded-lg border border-gray-200 text-gray-600 hover:bg-gray-50"><i data-lucide="arrow-up" class="text-sm"></i></button>' +
                    '<button type="button" class="progress-procedure-move-down inline-flex items-center justify-center h-9 w-9 rounded-lg border border-gray-200 text-gray-600 hover:bg-gray-50"><i data-lucide="arrow-down" class="text-sm"></i></button>' +
                    '<button type="button" class="progress-procedure-remove inline-flex items-center justify-center h-9 w-9 rounded-lg border border-red-200 text-red-600 hover:bg-red-50"><i data-lucide="trash-2" class="text-sm"></i></button>' +
                '</div>' +
            '</div>' +
            '<div class="space-y-3">' +
                '<input type="text" name="progress_step_procedure[]" class="w-full px-4 py-3 border border-gray-300 rounded-lg" placeholder="Describe the procedure performed">' +
                '<textarea name="progress_step_output[]" rows="2" class="w-full px-4 py-3 border border-gray-300 rounded-lg" placeholder="Output / result from this procedure"></textarea>' +
                '<div class="rounded-xl border border-dashed border-emerald-200 bg-emerald-50/40 px-3 py-3">' +
                    '<label class="block text-[10px] font-bold uppercase tracking-wider text-emerald-700 mb-2">Evidence for this procedure</label>' +
                    '<input type="file" multiple class="w-full text-xs text-emerald-800 file:mr-4 file:py-2 file:px-4 file:rounded-full file:border-0 file:text-xs file:font-bold file:bg-white file:text-emerald-700 hover:file:bg-emerald-50">' +
                '</div>' +
            '</div>';
        return wrapper;
    }

    if (addProgressProcedureButton && progressProcedureList) {
        addProgressProcedureButton.addEventListener('click', function() {
            progressProcedureList.appendChild(buildProgressProcedureStep());
            syncProgressProcedureRows();
            if (typeof window.refreshAppShellIcons === 'function') {
                window.refreshAppShellIcons();
            }
        });

        progressProcedureList.addEventListener('click', function(event) {
            const item = event.target.closest('.progress-procedure-item');
            if (!item) {
                return;
            }

            if (event.target.closest('.progress-procedure-remove')) {
                item.remove();
                if (!progressProcedureList.children.length) {
                    progressProcedureList.appendChild(buildProgressProcedureStep());
                }
                syncProgressProcedureRows();
                return;
            }

            if (event.target.closest('.progress-procedure-move-up') && item.previousElementSibling) {
                progressProcedureList.insertBefore(item, item.previousElementSibling);
                syncProgressProcedureRows();
                return;
            }

            if (event.target.closest('.progress-procedure-move-down') && item.nextElementSibling) {
                progressProcedureList.insertBefore(item.nextElementSibling, item);
                syncProgressProcedureRows();
            }
        });

        syncProgressProcedureRows();
    }

    if (taskManageForm) {
        taskManageForm.addEventListener('submit', function(event) {
            const submitter = event.submitter;
            const isSubmitForReview = submitter && submitter.name === 'workflow_action' && submitter.value === 'submit_for_review';
            const workDone = String((document.getElementById('progressWorkDone') || {}).value || '').trim();
            const nextWork = String((document.getElementById('progressNextWork') || {}).value || '').trim();
            const outcome = String((document.getElementById('progressOutcome') || {}).value || '').trim();
            const entryFiles = taskManageForm.querySelector('input[name="progress_entry_attachments[]"]');
            const hasEntryFiles = !!(entryFiles && entryFiles.files && entryFiles.files.length);

            let hasProcedureContent = false;
            let hasProcedureFiles = false;
            Array.prototype.forEach.call(taskManageForm.querySelectorAll('.progress-procedure-item'), function(item) {
                const procedureInput = item.querySelector('input[name="progress_step_procedure[]"]');
                const outputInput = item.querySelector('textarea[name="progress_step_output[]"]');
                const fileInput = item.querySelector('input[type="file"]');
                if (String((procedureInput && procedureInput.value) || '').trim() !== '' || String((outputInput && outputInput.value) || '').trim() !== '') {
                    hasProcedureContent = true;
                }
                if (fileInput && fileInput.files && fileInput.files.length) {
                    hasProcedureFiles = true;
                }
            });

            const hasPayload = workDone !== '' || nextWork !== '' || outcome !== '' || hasProcedureContent || hasEntryFiles || hasProcedureFiles || (taskStatus && taskStatus.value !== originalStatus);
            if (!hasPayload) {
                event.preventDefault();
                const emptyMessage = 'Record what was done, the next step, an outcome, a procedure row, or attach evidence before saving.';
                if (typeof window.showToast === 'function') {
                    window.showToast(emptyMessage, 'error');
                } else {
                    alert(emptyMessage);
                }
                return;
            }

            if (requiresProcedure && !hasProcedureContent) {
                event.preventDefault();
                const procedureMessage = 'At least one executed procedure is required for this task.';
                if (typeof window.showToast === 'function') {
                    window.showToast(procedureMessage, 'error');
                } else {
                    alert(procedureMessage);
                }
                return;
            }

            if (requiresDocument && taskStatus && (taskStatus.value !== originalStatus || isSubmitForReview) && !hasEntryFiles && !hasProcedureFiles) {
                event.preventDefault();
                const evidenceMessage = 'Attach at least one evidence file before changing status or submitting for review.';
                if (typeof window.showToast === 'function') {
                    window.showToast(evidenceMessage, 'error');
                } else {
                    alert(evidenceMessage);
                }
            }
        });
    }

    const taskWorkHistorySearch = document.getElementById('taskWorkHistorySearch');
    const taskWorkHistoryItems = document.querySelectorAll('.task-work-history-item');
    const taskWorkHistoryEmptyState = document.getElementById('taskWorkHistoryEmptyState');

    if (taskWorkHistorySearch && taskWorkHistoryItems.length) {
        const filterWorkHistoryList = function() {
            const query = taskWorkHistorySearch.value.trim().toLowerCase();
            let visibleCount = 0;

            Array.prototype.forEach.call(taskWorkHistoryItems, function(item) {
                const searchText = item.getAttribute('data-doc-search') || '';
                const isMatch = query === '' || searchText.indexOf(query) !== -1;
                item.classList.toggle('hidden', !isMatch);
                if (isMatch) {
                    visibleCount++;
                }
            });

            if (taskWorkHistoryEmptyState) {
                taskWorkHistoryEmptyState.classList.toggle('hidden', visibleCount > 0);
            }
        };

        taskWorkHistorySearch.addEventListener('input', filterWorkHistoryList);
    }

    if (typeof window.refreshAppShellIcons === 'function') {
        window.refreshAppShellIcons();
    }
});
</script>
<?php if (!empty($canInviteTaskTeam)): ?>
<script>
(function(){
    var users = <?php echo $ti_invite_users_json; ?>;
    var curUserId = <?php echo (int) $userId; ?>;
    var search = document.getElementById('taskTeamInviteSearch');
    var results = document.getElementById('taskTeamInviteResults');
    var hidden = document.getElementById('taskTeamInviteUserId');
    var submit = document.getElementById('taskTeamInviteSubmit');
    if (!search || !results || !hidden || !submit || !Array.isArray(users)) {
        return;
    }
    function matches(u, q) {
        if (!q) {
            return false;
        }
        var n = (u.name || '').toLowerCase();
        var e = (u.email || '').toLowerCase();
        return n.indexOf(q) !== -1 || e.indexOf(q) !== -1;
    }
    function render(q) {
        var qq = q.trim().toLowerCase();
        var list = users.filter(function (u) {
            if (Number(u.id) === curUserId) {
                return false;
            }
            return matches(u, qq);
        }).slice(0, 12);
        if (qq === '' || list.length === 0) {
            results.innerHTML = '';
            results.classList.add('hidden');
            return;
        }
        results.innerHTML = list.map(function (u) {
            return '<button type="button" class="task-ti-pick w-full text-left px-3 py-2.5 text-sm hover:bg-emerald-50 border-b border-slate-100 last:border-0" data-user-id="' + Number(u.id) + '" data-label="' +
                String(u.name || '').replace(/"/g, '&quot;') + '">' +
                '<span class="font-bold text-slate-800">' + String(u.name || '') + '</span>' +
                (u.email ? '<span class="block text-xs text-slate-500">' + String(u.email) + '</span>' : '') +
                '</button>';
        }).join('');
        results.classList.remove('hidden');
    }
    function pick(id, label) {
        hidden.value = String(id);
        search.value = label;
        results.innerHTML = '';
        results.classList.add('hidden');
        submit.disabled = false;
    }
    search.addEventListener('input', function () {
        hidden.value = '';
        submit.disabled = true;
        render(search.value);
    });
    search.addEventListener('focus', function () {
        render(search.value);
    });
    results.addEventListener('click', function (e) {
        var b = e.target.closest('.task-ti-pick');
        if (!b) {
            return;
        }
        pick(b.getAttribute('data-user-id'), b.getAttribute('data-label') || '');
    });
    document.addEventListener('click', function (e) {
        if (!search.contains(e.target) && !results.contains(e.target)) {
            results.classList.add('hidden');
        }
    });
})();
</script>
<?php endif; ?>

<?php include '../../includes/footer.php'; ?>
