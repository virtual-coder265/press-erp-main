<?php
require_once __DIR__ . '/../../config/app.php';
checkAuth();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/task_management_helper.php';
require_once __DIR__ . '/../../includes/project_pm_helper.php';
require_once __DIR__ . '/../../includes/upload_helper.php';
require_once __DIR__ . '/../../includes/team_invitation_helper.php';
require_once __DIR__ . '/../../includes/project_visibility_helper.php';

/**
 * Open an existing 1:1 conversation or the compose screen prefilled for this peer.
 */
function project_view_peer_messaging_href(PDO $pdo, int $viewerId, int $peerId, string $subjectLine): string
{
    if ($viewerId < 1 || $peerId < 1 || $viewerId === $peerId) {
        return '';
    }
    try {
        $stmt = $pdo->prepare(
            'SELECT id FROM conversations WHERE (participant1_id = ? AND participant2_id = ?) OR (participant1_id = ? AND participant2_id = ?) LIMIT 1'
        );
        $stmt->execute([$viewerId, $peerId, $peerId, $viewerId]);
        $cid = $stmt->fetchColumn();
        if ($cid) {
            return BASE_URL . 'modules/messaging/view?id=' . (int) $cid;
        }
    } catch (Throwable $e) {
        // Fall through to compose URL.
    }

    $q = http_build_query(
        [
            'reply_to' => $peerId,
            'subject' => $subjectLine,
            'body' => $subjectLine . "\n\n",
        ],
        '',
        '&',
        PHP_QUERY_RFC3986
    );

    return BASE_URL . 'modules/messaging/send?' . $q;
}

$id = $_GET['id'] ?? 0;

$stmt = $pdo->prepare("SELECT p.*, COALESCE(u.name, 'Deleted User') AS created_by_name, u.photo AS created_by_photo, u.email AS created_by_email,
                      u.phone AS created_by_phone, u.whatsapp_phone AS created_by_whatsapp
                      FROM projects p 
                      LEFT JOIN users u ON p.created_by = u.id 
                      WHERE p.id = ?");
$stmt->execute([$id]);
$project = $stmt->fetch();

if (!$project) {
    redirect('modules/projects/list?error=project_not_found');
}

$currentUserId = (int) ($_SESSION['user_id'] ?? 0);
if (!project_user_can_view_project($pdo, $currentUserId, $project)) {
    redirect('modules/projects/list?error=access_denied');
}

$canManageProjectPm = user_can_manage_project_pm($pdo, $currentUserId, $project);
$teamInvitationsReady = team_invitation_tables_ready($pdo);
$canInviteProjectTeam = $teamInvitationsReady && user_can_send_project_team_invitation($pdo, $currentUserId, $project);

$isProjectManagerViewer = ($currentUserId > 0 && (int) ($project['created_by'] ?? 0) === $currentUserId);
$projectCommSubject = trim((string) ($project['name'] ?? '')) !== '' ? trim((string) ($project['name'] ?? '')) : 'Project';

$timelineItems = [];
$projectRisks = [];
$activityLogRows = [];
$projectUploadedFiles = [];
$budgetSpentTotal = 0.0;
try {
    $tiStmt = $pdo->prepare(
        'SELECT pti.*, t.name AS linked_task_name
         FROM project_timeline_items pti
         LEFT JOIN tasks t ON t.id = pti.linked_task_id
         WHERE pti.project_id = ?
         ORDER BY pti.sort_order ASC, pti.planned_date ASC, pti.id ASC'
    );
    $tiStmt->execute([(int) $id]);
    $timelineItems = $tiStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $timelineItems = [];
}
try {
    $riskStmt = $pdo->prepare(
        'SELECT r.*, t.name AS linked_task_name
         FROM project_risks r
         LEFT JOIN tasks t ON t.id = r.task_id
         WHERE r.project_id = ?
         ORDER BY FIELD(r.status,\'Open\',\'Mitigating\',\'Resolved\',\'Accepted\',\'Closed\'), r.updated_at DESC'
    );
    $riskStmt->execute([(int) $id]);
    $projectRisks = $riskStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $projectRisks = [];
}
try {
    $spentStmt = $pdo->prepare(
        'SELECT COALESCE(SUM(te.amount), 0) FROM task_expenses te
         INNER JOIN tasks t ON t.id = te.task_id
         WHERE t.project_id = ?'
    );
    $spentStmt->execute([(int) $id]);
    $budgetSpentTotal = (float) $spentStmt->fetchColumn();
} catch (Throwable $e) {
    $budgetSpentTotal = 0.0;
}
try {
    $pfStmt = $pdo->prepare(
        'SELECT pf.*, COALESCE(u.name, \'User\') AS uploader_name
         FROM project_files pf
         LEFT JOIN users u ON u.id = pf.uploaded_by
         WHERE pf.project_id = ?
         ORDER BY pf.created_at DESC'
    );
    $pfStmt->execute([(int) $id]);
    $projectUploadedFiles = $pfStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $projectUploadedFiles = [];
}

$activityActionFilter = trim((string) ($_GET['pal_action'] ?? ''));
try {
    $actSql = 'SELECT pal.*, COALESCE(u.name, \'User\') AS user_name, u.photo AS user_photo
        FROM project_activity_log pal
        LEFT JOIN users u ON u.id = pal.user_id
        WHERE pal.project_id = ?';
    $actParams = [(int) $id];
    if ($activityActionFilter !== '') {
        $actSql .= ' AND pal.action = ?';
        $actParams[] = $activityActionFilter;
    }
    $actSql .= ' ORDER BY pal.created_at DESC LIMIT 120';
    $actStmt = $pdo->prepare($actSql);
    $actStmt->execute($actParams);
    $activityLogRows = $actStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $activityLogRows = [];
}

$projectApprovalSuccess = $_GET['success'] ?? '';
$projectApprovalError = $_GET['error'] ?? '';
$projectHasChangesSinceApproval = project_has_changes_since_last_approval($pdo, $project);
$projectApprovalLocked = in_array($project['approved_status'] ?? 'Pending', ['Approved', 'Rejected'], true)
    && !$projectHasChangesSinceApproval;
$projectApprovalStamp = !empty($project['approved_at']) ? date('M d, Y g:i A', strtotime($project['approved_at'])) : null;
$projectApprovalActionLabel = ($project['approved_status'] ?? 'Pending') === 'Rejected' ? 'rejected' : 'approved';

$stmt = $pdo->prepare("SELECT t.*, u1.name as assigned_to_name, u2.name as created_by_name 
                      FROM tasks t 
                      LEFT JOIN users u1 ON t.assigned_to = u1.id 
                      LEFT JOIN users u2 ON t.created_by = u2.id 
                      WHERE t.project_id = ? 
                      ORDER BY t.due_date ASC, t.priority DESC");
$stmt->execute([$id]);
$tasks = $stmt->fetchAll();

$taskDocumentationCounts = [];
if (!empty($tasks)) {
    $taskIds = array_map('intval', array_column($tasks, 'id'));
    if (!empty($taskIds)) {
        $taskIdPlaceholders = implode(',', array_fill(0, count($taskIds), '?'));
        $taskDocCountStmt = $pdo->prepare("
            SELECT task_id, COUNT(*) AS documentation_count
            FROM task_documentation
            WHERE task_id IN ($taskIdPlaceholders)
            GROUP BY task_id
        ");
        $taskDocCountStmt->execute($taskIds);
        foreach ($taskDocCountStmt->fetchAll(PDO::FETCH_ASSOC) as $taskDocRow) {
            $taskDocumentationCounts[(int) $taskDocRow['task_id']] = (int) $taskDocRow['documentation_count'];
        }
    }
}

// ── Fetch Project Comments (with reply context) ──────────────────────────────
$commentStmt = $pdo->prepare("
    SELECT pc.*,
           COALESCE(u.name, 'Deleted User') AS user_name,
           u.photo AS user_photo,
           rpc.comment  AS reply_to_comment,
           ru.name      AS reply_to_user_name
    FROM project_comments pc
    LEFT JOIN users u              ON pc.user_id = u.id
    LEFT JOIN project_comments rpc ON pc.reply_to_id = rpc.id
    LEFT JOIN users ru             ON rpc.user_id = ru.id
    WHERE pc.project_id = ?
    ORDER BY pc.created_at ASC
");
$commentStmt->execute([$id]);
$project_comments = $commentStmt->fetchAll();

// Attachments for comments
$pc_attach_map = [];
if ($project_comments) {
    $pc_ids = implode(',', array_map('intval', array_column($project_comments, 'id')));
    try {
        $pcaRows = $pdo->query(
            "SELECT * FROM project_comment_attachments WHERE comment_id IN ($pc_ids) ORDER BY created_at ASC"
        )->fetchAll();
        foreach ($pcaRows as $pca) {
            $pc_attach_map[(int)$pca['comment_id']][] = $pca;
        }
    } catch (Exception $e) { /* table may not exist yet */ }
}

$projectWorkspaceParticipants = fetch_delivery_participants_for_project($pdo, (int) $id);
$projectWorkspaceShowGroupChat = count($projectWorkspaceParticipants) > 1;
$projectWorkspaceGroupChatTitle = trim((string) ($project['name'] ?? '')) . ' | Project';

$pvcDigitsOnly = static function (?string $raw): string {
    return (string) preg_replace('/\D+/', '', (string) $raw);
};

$pmPeerId = (int) ($project['created_by'] ?? 0);
$pmMessagingHref = !$isProjectManagerViewer && $pmPeerId > 0
    ? project_view_peer_messaging_href($pdo, $currentUserId, $pmPeerId, $projectCommSubject)
    : '';
$pmPhone = trim((string) ($project['created_by_phone'] ?? ''));
$pmWaPhone = trim((string) ($project['created_by_whatsapp'] ?? ''));
$pmEmail = trim((string) ($project['created_by_email'] ?? ''));
$pmWaDigits = $pvcDigitsOnly($pmWaPhone !== '' ? $pmWaPhone : $pmPhone);
$pmWaHref = !$isProjectManagerViewer && $pmWaDigits !== ''
    ? ('https://wa.me/' . $pmWaDigits . '?text=' . rawurlencode($projectCommSubject . "\n\n"))
    : '';
$pmTelHref = !$isProjectManagerViewer && $pmPhone !== ''
    ? ('tel:' . preg_replace('/\s+/', '', $pmPhone))
    : '';
$pmMailtoHref = !$isProjectManagerViewer && $pmEmail !== ''
    ? ('mailto:' . rawurlencode($pmEmail)
        . '?subject=' . rawurlencode($projectCommSubject)
        . '&body=' . rawurlencode($projectCommSubject . "\n\n"))
    : '';

$stakeholderContactRows = [];
if (!$isProjectManagerViewer && $projectWorkspaceParticipants !== []) {
    $pmUid = (int) ($project['created_by'] ?? 0);
    $peerIds = [];
    foreach ($projectWorkspaceParticipants as $sp) {
        $sid = (int) ($sp['id'] ?? 0);
        if ($sid < 1 || $sid === $currentUserId || $sid === $pmUid) {
            continue;
        }
        $peerIds[] = $sid;
    }
    $peerIds = array_values(array_unique($peerIds));
    if ($peerIds !== []) {
        $placeholders = implode(',', array_fill(0, count($peerIds), '?'));
        try {
            $shStmt = $pdo->prepare(
                "SELECT id, name, email, phone, whatsapp_phone FROM users WHERE id IN ($placeholders) ORDER BY name ASC"
            );
            $shStmt->execute($peerIds);
            while ($uRow = $shStmt->fetch(PDO::FETCH_ASSOC)) {
                $sid = (int) $uRow['id'];
                $phone = trim((string) ($uRow['phone'] ?? ''));
                $waPhone = trim((string) ($uRow['whatsapp_phone'] ?? ''));
                $email = trim((string) ($uRow['email'] ?? ''));
                $waDigits = $pvcDigitsOnly($waPhone !== '' ? $waPhone : $phone);
                $stakeholderContactRows[] = [
                    'name' => (string) ($uRow['name'] ?? ''),
                    'msg_href' => project_view_peer_messaging_href($pdo, $currentUserId, $sid, $projectCommSubject),
                    'wa_href' => $waDigits !== ''
                        ? ('https://wa.me/' . $waDigits . '?text=' . rawurlencode($projectCommSubject . "\n\n"))
                        : '',
                    'tel_href' => $phone !== '' ? ('tel:' . preg_replace('/\s+/', '', $phone)) : '',
                    'mailto_href' => $email !== ''
                        ? ('mailto:' . rawurlencode($email)
                            . '?subject=' . rawurlencode($projectCommSubject)
                            . '&body=' . rawurlencode($projectCommSubject . "\n\n"))
                        : '',
                ];
            }
        } catch (Throwable $e) {
            $stakeholderContactRows = [];
        }
    }
}

$projectChatMessages = [];
foreach ($project_comments as $pc) {
    $projectChatMessages[] = [
        'user_id' => (int) ($pc['user_id'] ?? 0),
        'user_name' => (string) ($pc['user_name'] ?? ''),
        'user_photo' => $pc['user_photo'] ?? null,
        'body' => (string) ($pc['comment'] ?? ''),
        'created_at' => $pc['created_at'] ?? '',
        'attachments' => $pc_attach_map[(int) ($pc['id'] ?? 0)] ?? [],
    ];
}

// All users for @mention
$pc_all_users = $pdo->query("SELECT id, name, photo FROM users ORDER BY name")->fetchAll();
$pc_users_json = json_encode(array_values(array_map(fn($u) => [
    'id'    => (int)$u['id'],
    'name'  => $u['name'],
    'photo' => (!empty($u['photo']) && $u['photo'] !== 'default.png') ? BASE_URL . ltrim($u['photo'], '/') : null,
], $pc_all_users)), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

$ti_invite_user_rows = $pdo->query('SELECT id, name, email FROM users ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
$ti_invite_users_json = json_encode(array_values(array_map(static function (array $u): array {
    return [
        'id' => (int) $u['id'],
        'name' => (string) ($u['name'] ?? ''),
        'email' => (string) ($u['email'] ?? ''),
    ];
}, $ti_invite_user_rows)), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

// ── Fetch all task documentation and attachments for this project ─────────────
$docStmt = $pdo->prepare("
    SELECT
        t.name AS task_name,
        COALESCE(u.name, 'Deleted User') AS uploader_name,
        td.created_at,
        td.document_path AS file_path,
        COALESCE(NULLIF(td.documentation_text, ''), CONCAT(COALESCE(td.old_status, 'Initial'), ' -> ', COALESCE(td.new_status, ''))) AS context_label,
        'Status Evidence' AS file_source
    FROM task_documentation td
    JOIN tasks t ON td.task_id = t.id
    LEFT JOIN users u ON td.user_id = u.id
    WHERE t.project_id = ? AND td.document_path IS NOT NULL
    UNION ALL
    SELECT
        t.name AS task_name,
        COALESCE(u.name, 'Deleted User') AS uploader_name,
        ta.created_at,
        ta.file_path,
        ta.original_name AS context_label,
        'Task Attachment' AS file_source
    FROM task_attachments ta
    JOIN tasks t ON ta.task_id = t.id
    LEFT JOIN users u ON ta.uploaded_by = u.id
    WHERE t.project_id = ?
    UNION ALL
    SELECT
        '—' AS task_name,
        COALESCE(u.name, 'Deleted User') AS uploader_name,
        pf.created_at,
        pf.file_path,
        pf.original_name AS context_label,
        'Project Library' AS file_source
    FROM project_files pf
    LEFT JOIN users u ON u.id = pf.uploaded_by
    WHERE pf.project_id = ?
    ORDER BY created_at DESC
");
$docStmt->execute([(int) $id, (int) $id, (int) $id]);
$all_project_docs = $docStmt->fetchAll();

// ── Handle Project Approval POST ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['project_approval'])) {
    $new_approval_status = $_POST['approval_status'] ?? 'Pending';
    $user_id = $_SESSION['user_id'];
    $allowedApprovalStatuses = ['Approved', 'Rejected', 'Pending'];
    
    if (!in_array($new_approval_status, $allowedApprovalStatuses, true)) {
        redirect("modules/projects/view?id=$id&error=" . urlencode('Invalid project approval action.'));
    }

    if ($user_id == $project['created_by']) {
        if ($projectApprovalLocked) {
            redirect("modules/projects/view?id=$id&error=" . urlencode('No project changes were detected since the last sign-off.'));
        }

        try {
            $pdo->beginTransaction();

            if ($new_approval_status === 'Pending') {
                $updateStmt = $pdo->prepare("UPDATE projects SET approved_status = 'Pending', approved_by = NULL, approved_at = NULL WHERE id = ?");
                $updateStmt->execute([$id]);
            } else {
                $updateStmt = $pdo->prepare("UPDATE projects SET approved_status = ?, approved_by = ?, approved_at = NOW() WHERE id = ?");
                $updateStmt->execute([$new_approval_status, $user_id, $id]);
            }

            $pdo->commit();
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            error_log('Project sign-off update failed for project #' . $id . ': ' . $e->getMessage());
            $errorMessage = normalize_workflow_exception_message(
                $e->getMessage(),
                'Unable to update the project sign-off right now.'
            );
            redirect("modules/projects/view?id=$id&error=" . urlencode($errorMessage));
        }

        redirect("modules/projects/view?id=$id&success=project_status_updated");
    }
}

$task_stats = [
    'total' => count($tasks),
    'completed' => 0,
    'in_progress' => 0,
    'not_started' => 0,
    'overdue' => 0
];

foreach ($tasks as $task) {
    if ($task['status'] == 'Completed') {
        $task_stats['completed']++;
    } elseif ($task['status'] == 'In Progress' || $task['status'] == 'In Review') {
        $task_stats['in_progress']++;
    } else {
        $task_stats['not_started']++;
    }

    if ($task['due_date'] && $task['status'] != 'Completed' && strtotime($task['due_date']) < time()) {
        $task_stats['overdue']++;
    }
}

$assignee_map = fetch_task_assignees_for_tasks($pdo, array_column($tasks, 'id'));
$latest_review_map = fetch_task_latest_reviews_for_tasks($pdo, array_column($tasks, 'id'));
$pending_review_tasks = [];
foreach ($tasks as &$task) {
    $task['assignee_summary'] = format_task_assignee_summary($assignee_map[$task['id']] ?? [], $task['assigned_to_name'] ?? null);
    $task['can_edit'] = ((int) $_SESSION['user_id'] === (int) $task['created_by']) || ((int) $_SESSION['user_id'] === (int) $project['created_by']);
    $task['workflow_state'] = get_task_workflow_state($task, $latest_review_map[$task['id']] ?? null);
    $task['documentation_count'] = $taskDocumentationCounts[(int) $task['id']] ?? 0;
    if ($task['status'] === 'In Review') {
        $pending_review_tasks[] = $task;
    }
}
unset($task);

$projectKanbanBuckets = [
    'Backlog' => [],
    'In progress' => [],
    'Review' => [],
    'Done' => [],
];
foreach ($tasks as $tRow) {
    $st = $tRow['status'] ?? 'Not Started';
    if (in_array($st, ['Completed', 'Cancelled'], true)) {
        $projectKanbanBuckets['Done'][] = $tRow;
    } elseif ($st === 'In Review') {
        $projectKanbanBuckets['Review'][] = $tRow;
    } elseif ($st === 'In Progress') {
        $projectKanbanBuckets['In progress'][] = $tRow;
    } else {
        $projectKanbanBuckets['Backlog'][] = $tRow;
    }
}

$progress = $task_stats['total'] > 0 ? round(($task_stats['completed'] / $task_stats['total']) * 100) : 0;

include '../../includes/header.php';

$status_colors = [
    'Planning' => 'bg-emerald-50 text-emerald-700',
    'In Progress' => 'bg-green-100 text-green-800',
    'On Hold' => 'bg-yellow-100 text-yellow-800',
    'Completed' => 'bg-gray-100 text-gray-800',
    'Cancelled' => 'bg-red-100 text-red-800'
];
$priority_colors = [
    'Low' => 'text-gray-600',
    'Medium' => 'text-emerald-600',
    'High' => 'text-orange-600',
    'Urgent' => 'text-red-600'
];
$task_status_colors = [
    'Not Started' => 'bg-gray-100 text-gray-800',
    'In Progress' => 'bg-emerald-50 text-emerald-700',
    'In Review' => 'bg-yellow-100 text-yellow-800',
    'Completed' => 'bg-green-100 text-green-800',
    'Cancelled' => 'bg-red-100 text-red-800'
];
$task_priority_colors = [
    'Low' => 'text-gray-600',
    'Medium' => 'text-emerald-600',
    'High' => 'text-orange-600',
    'Urgent' => 'text-red-600'
];
$status_color = $status_colors[$project['status']] ?? 'bg-gray-100 text-gray-800';
$priority_color = $priority_colors[$project['priority']] ?? 'text-gray-600';
$requirement_badges = [];

if (!empty($project['require_document_submission'])) {
    $requirement_badges[] = [
        'label' => 'Document Submission',
        'class' => 'bg-green-100 text-green-800'
    ];
}

if (!empty($project['require_procedure_tracking'])) {
    $requirement_badges[] = [
        'label' => 'Procedure Tracking',
        'class' => 'bg-emerald-50 text-emerald-700'
    ];
}

$approval_colors = [
    'Approved' => 'bg-emerald-100 text-emerald-700',
    'Rejected' => 'bg-rose-100 text-rose-700',
    'Pending' => 'bg-slate-100 text-slate-700'
];
$approval_color = $approval_colors[$project['approved_status'] ?? 'Pending'] ?? 'bg-slate-100 text-slate-700';

$project_timeline_label = 'Dates not scheduled';
$project_timeline_note = 'Add start and end dates';
if (!empty($project['start_date']) && !empty($project['end_date'])) {
    $project_timeline_label = date('M d', strtotime($project['start_date'])) . ' - ' . date('M d, Y', strtotime($project['end_date']));
    $project_timeline_note = 'Planned delivery window';
} elseif (!empty($project['start_date'])) {
    $project_timeline_label = 'Starts ' . date('M d, Y', strtotime($project['start_date']));
    $project_timeline_note = 'End date not scheduled';
} elseif (!empty($project['end_date'])) {
    $project_timeline_label = 'Ends ' . date('M d, Y', strtotime($project['end_date']));
    $project_timeline_note = 'Start date not scheduled';
}

$project_deadline_label = !empty($project['end_date']) ? date('M d, Y', strtotime($project['end_date'])) : 'No deadline';
$project_deadline_note = 'Set an end date';
if (!empty($project['end_date'])) {
    $day_delta = (int) floor((strtotime(date('Y-m-d', strtotime($project['end_date']))) - strtotime(date('Y-m-d'))) / 86400);
    if (in_array($project['status'], ['Completed', 'Cancelled'], true)) {
        $project_deadline_note = ucfirst($project['status']);
    } elseif ($day_delta < 0) {
        $project_deadline_note = abs($day_delta) . ' day' . (abs($day_delta) === 1 ? '' : 's') . ' overdue';
    } elseif ($day_delta === 0) {
        $project_deadline_note = 'Due today';
    } else {
        $project_deadline_note = $day_delta . ' day' . ($day_delta === 1 ? '' : 's') . ' left';
    }
}

$recent_project_docs = array_slice($all_project_docs, 0, 5);

$project_timeline_hero = $project_timeline_label;
if (!empty($project['start_date']) && !empty($project['end_date'])) {
    $project_timeline_hero = date('M d', strtotime($project['start_date'])) . ' – ' . date('M d, Y', strtotime($project['end_date']));
}

$descTrim = trim((string) ($project['description'] ?? ''));

$project_tagline = 'Manage project scope, tasks, files, and approvals from one centralized workspace.';
if ($descTrim !== '') {
    $firstLine = preg_split('/\r\n|\r|\n/', $descTrim, 2)[0];
    $project_tagline = mb_strimwidth(trim($firstLine), 0, 220, '…', 'UTF-8');
}

$overview_labels = ['Scope', 'Objectives', 'Key deliverables', 'Success criteria'];
$overview_chunks = [];
if ($descTrim !== '') {
    $parts = preg_split("/\n\s*\n/", $descTrim);
    $parts = array_values(array_filter(array_map('trim', $parts), static fn ($p) => $p !== ''));
    foreach ($parts as $i => $chunk) {
        $overview_chunks[] = [
            'label' => $overview_labels[$i] ?? ('Detail ' . ($i + 1)),
            'text' => $chunk,
        ];
        if (count($overview_chunks) >= 4) {
            break;
        }
    }
}

$priority_badge_styles = [
    'Low' => 'background:#e2e8f0;color:#475569;',
    'Medium' => 'background:#ffedd5;color:#c2410c;',
    'High' => 'background:#ffedd5;color:#ea580c;',
    'Urgent' => 'background:#fee2e2;color:#b91c1c;',
];
$priority_badge_style = $priority_badge_styles[$project['priority']] ?? $priority_badge_styles['Medium'];

$created_by_photo_url = (!empty($project['created_by_photo']) && $project['created_by_photo'] !== 'default.png')
    ? BASE_URL . ltrim($project['created_by_photo'], '/')
    : null;

$activity_feed_ts = static function (int $ts): string {
    return date('M d, Y', $ts) . ' • ' . date('g:i A', $ts);
};

$task_latest_ts = null;
foreach ($tasks as $t) {
    if (!empty($t['created_at'])) {
        $ts = strtotime($t['created_at']);
        if ($task_latest_ts === null || $ts > $task_latest_ts) {
            $task_latest_ts = $ts;
        }
    }
}
$doc_latest_ts = null;
foreach ($all_project_docs as $d) {
    if (!empty($d['created_at'])) {
        $ts = strtotime($d['created_at']);
        if ($doc_latest_ts === null || $ts > $doc_latest_ts) {
            $doc_latest_ts = $ts;
        }
    }
}

$project_activity_items = [
    [
        'photo' => $created_by_photo_url,
        'name' => (string) $project['created_by_name'],
        'action' => 'created the project',
        'ts' => strtotime($project['created_at']),
    ],
    [
        'photo' => $created_by_photo_url,
        'name' => (string) $project['created_by_name'],
        'action' => 'added ' . (int) $task_stats['total'] . ' task' . ($task_stats['total'] === 1 ? '' : 's'),
        'ts' => $task_latest_ts ?? (strtotime($project['created_at']) + 60),
    ],
    [
        'photo' => $created_by_photo_url,
        'name' => (string) $project['created_by_name'],
        'action' => 'uploaded ' . count($all_project_docs) . ' file' . (count($all_project_docs) === 1 ? '' : 's'),
        'ts' => $doc_latest_ts ?? (strtotime($project['created_at']) + 120),
    ],
];
usort($project_activity_items, static fn ($a, $b) => $a['ts'] <=> $b['ts']);

$project_lead_initials = static function (string $n): string {
    $p = preg_split('/\s+/', trim($n));

    return count($p) >= 2
        ? strtoupper(substr($p[0], 0, 1) . substr($p[1], 0, 1))
        : strtoupper(substr($n, 0, 2));
};

$project_ref_code = 'PRJ-' . str_pad((string) (int) $project['id'], 3, '0', STR_PAD_LEFT);

$blocked_task_count = count(array_filter($tasks, static fn ($t) => ($t['status'] ?? '') === 'In Review'));

$pct_of = static function (int $n, int $d): int {
    return $d > 0 ? (int) round(100 * $n / $d) : 0;
};
$task_total = max(1, $task_stats['total']);
$project_rail_metrics = [
    ['label' => 'Tasks', 'value' => (string) $task_stats['total'], 'sub' => null, 'tone' => '#0f172a'],
    ['label' => 'Completed', 'value' => (string) $task_stats['completed'], 'sub' => $pct_of($task_stats['completed'], $task_total) . '%', 'tone' => '#16a34a'],
    ['label' => 'In progress', 'value' => (string) $task_stats['in_progress'], 'sub' => $pct_of($task_stats['in_progress'], $task_total) . '%', 'tone' => '#0d9488'],
    ['label' => 'Not started', 'value' => (string) $task_stats['not_started'], 'sub' => $pct_of($task_stats['not_started'], $task_total) . '%', 'tone' => '#64748b'],
    ['label' => 'Overdue', 'value' => (string) $task_stats['overdue'], 'sub' => null, 'tone' => '#dc2626'],
    ['label' => 'In review', 'value' => (string) $blocked_task_count, 'sub' => null, 'tone' => '#9333ea'],
];

$project_view_rel_time = static function (int $ts): string {
    $delta = time() - $ts;
    if ($delta < 60) {
        return 'Just now';
    }
    if ($delta < 3600) {
        return (int) floor($delta / 60) . 'm ago';
    }
    if ($delta < 86400) {
        return (int) floor($delta / 3600) . 'h ago';
    }
    if ($delta < 86400 * 7) {
        return (int) floor($delta / 86400) . 'd ago';
    }

    return date('M j, Y', $ts);
};

$recent_completed_stmt = $pdo->prepare("
    SELECT t.name, t.completed_at, COALESCE(u.name, 'Team member') AS actor_name, u.photo AS actor_photo
    FROM tasks t
    LEFT JOIN users u ON u.id = t.assigned_to
    WHERE t.project_id = ? AND t.status = 'Completed' AND t.completed_at IS NOT NULL
    ORDER BY t.completed_at DESC
    LIMIT 5
");
$recent_completed_stmt->execute([(int) $id]);
$recent_completed_rows = $recent_completed_stmt->fetchAll(PDO::FETCH_ASSOC);

$recent_feed_items = [];
foreach ($recent_completed_rows as $rc) {
    $recent_feed_items[] = [
        'kind' => 'task_done',
        'text' => 'Task "' . (string) $rc['name'] . '" completed',
        'name' => (string) $rc['actor_name'],
        'photo' => (!empty($rc['actor_photo']) && $rc['actor_photo'] !== 'default.png') ? BASE_URL . ltrim((string) $rc['actor_photo'], '/') : null,
        'ts' => strtotime((string) $rc['completed_at']),
    ];
}
usort($recent_feed_items, static fn ($a, $b) => $b['ts'] <=> $a['ts']);
$recent_feed_items = array_slice($recent_feed_items, 0, 4);
if (count($recent_feed_items) < 3) {
    foreach (array_reverse($project_comments) as $pc) {
        if (count($recent_feed_items) >= 4) {
            break;
        }
        $recent_feed_items[] = [
            'kind' => 'comment',
            'text' => mb_strimwidth(trim((string) ($pc['comment'] ?? '')), 0, 72, '…', 'UTF-8'),
            'name' => (string) ($pc['user_name'] ?? ''),
            'photo' => (!empty($pc['user_photo']) && $pc['user_photo'] !== 'default.png') ? BASE_URL . ltrim((string) $pc['user_photo'], '/') : null,
            'ts' => strtotime((string) ($pc['created_at'] ?? 'now')),
        ];
    }
    usort($recent_feed_items, static fn ($a, $b) => $b['ts'] <=> $a['ts']);
    $recent_feed_items = array_slice($recent_feed_items, 0, 4);
}

$chart_labels = [];
$chart_planned = [];
$chart_actual = [];
$ts_chart_start = !empty($project['start_date'])
    ? strtotime($project['start_date'] . ' 12:00:00')
    : strtotime((string) ($project['created_at'] ?? 'now'));
$ts_chart_end = !empty($project['end_date'])
    ? strtotime($project['end_date'] . ' 12:00:00')
    : ($ts_chart_start + (int) (180 * 86400));
if ($ts_chart_end <= $ts_chart_start) {
    $ts_chart_end = $ts_chart_start + (int) (90 * 86400);
}
$month_slots = 6;
$timelineChartCaption = '';
if (!empty($timelineItems)) {
    $timelineChartCaption = 'Planned vs actual cumulative deliverables from the timeline below.';
    $nTi = max(1, count($timelineItems));
    for ($i = 0; $i < $month_slots; $i++) {
        $t = (int) round($ts_chart_start + ($i / max(1, $month_slots - 1)) * ($ts_chart_end - $ts_chart_start));
        $chart_labels[] = date('M', $t);
        $cutoffDate = date('Y-m-d', $t);
        $plannedN = 0;
        $actualN = 0;
        foreach ($timelineItems as $it) {
            $pd = (string) ($it['planned_date'] ?? '');
            if ($pd !== '' && strcmp($pd, $cutoffDate) <= 0) {
                $plannedN++;
            }
            $ad = $it['actual_date'] ?? null;
            $cm = $it['completed_at'] ?? null;
            $hitActual = false;
            if ($ad && (string) $ad !== '' && strcmp((string) $ad, $cutoffDate) <= 0) {
                $hitActual = true;
            } elseif ($cm && strtotime((string) $cm) <= $t) {
                $hitActual = true;
            }
            if ($hitActual) {
                $actualN++;
            }
        }
        $chart_planned[] = min(100, round(100 * $plannedN / $nTi, 1));
        $chart_actual[] = min(100, round(100 * $actualN / $nTi, 1));
    }
} else {
    $timelineChartCaption = 'Actual task completion vs an even plan across the project window. Add timeline items for a planned curve.';
    for ($i = 0; $i < $month_slots; $i++) {
        $t = (int) round($ts_chart_start + ($i / max(1, $month_slots - 1)) * ($ts_chart_end - $ts_chart_start));
        $chart_labels[] = date('M', $t);
        $chart_planned[] = min(100, round(($i / max(1, $month_slots - 1)) * 100, 1));
        $done_by = 0;
        if ($task_stats['total'] > 0) {
            foreach ($tasks as $tk) {
                if (($tk['status'] ?? '') === 'Completed' && !empty($tk['completed_at']) && strtotime((string) $tk['completed_at']) <= $t) {
                    $done_by++;
                }
            }
            $chart_actual[] = min(100, round(100 * $done_by / $task_stats['total'], 1));
        } else {
            $chart_actual[] = $i === $month_slots - 1 ? (float) $progress : 0.0;
        }
    }
}

$chart_w = 400;
$chart_h = 200;
$chart_pad = 40;
$chart_plot_fn = static function (array $vals, int $w, int $h, int $pad, int $n): string {
    $pts = [];
    for ($i = 0; $i < $n; $i++) {
        $x = $pad + ($i / max(1, $n - 1)) * ($w - 2 * $pad);
        $v = (float) ($vals[$i] ?? 0);
        $y = $h - $pad - ($v / 100) * ($h - 2 * $pad);
        $pts[] = round($x, 1) . ',' . round($y, 1);
    }

    return implode(' ', $pts);
};
$n_chart = count($chart_labels);
$chart_poly_planned = $chart_plot_fn($chart_planned, $chart_w, $chart_h, $chart_pad, $n_chart);
$chart_poly_actual = $chart_plot_fn($chart_actual, $chart_w, $chart_h, $chart_pad, $n_chart);

$schedule_elapsed_pct = 0;
if (!empty($project['start_date']) && !empty($project['end_date'])) {
    $ms = strtotime($project['start_date'] . ' 00:00:00');
    $me = strtotime($project['end_date'] . ' 23:59:59');
    $span = max(1, $me - $ms);
    $schedule_elapsed_pct = (int) min(100, max(0, round(100 * (time() - $ms) / $span)));
}

$forecast_finish_label = '—';
if (!empty($project['end_date'])) {
    $forecast_finish_label = date('M d, Y', strtotime($project['end_date']));
    if ($progress < 100 && $schedule_elapsed_pct > $progress + 5) {
        $forecast_finish_label = date('M d, Y', strtotime($project['end_date'] . ' +' . (int) max(7, ($schedule_elapsed_pct - $progress) * 2) . ' days'));
    }
}

$budgetEnabled = project_budget_enabled($project);
$budgetCapVal = isset($project['budget_amount']) && $project['budget_amount'] !== null && $project['budget_amount'] !== ''
    ? (float) $project['budget_amount']
    : null;
$budgetCurDisp = strtoupper(substr(trim((string) ($project['budget_currency'] ?? 'USD')), 0, 3));
$budgetSpendPct = ($budgetEnabled && $budgetCapVal !== null && $budgetCapVal > 0)
    ? (int) min(100, round(100 * $budgetSpentTotal / $budgetCapVal))
    : 0;

$user_photo_url = static function (?string $photo): ?string {
    if ($photo === null || $photo === '' || $photo === 'default.png') {
        return null;
    }

    return BASE_URL . ltrim($photo, '/');
};
?>

<link href="<?php echo asset('css/premium-modules.css'); ?>" rel="stylesheet">
<link href="<?php echo asset('css/workspace-group-chat.css'); ?>" rel="stylesheet">
<link href="<?php echo asset('css/tasks-view.css'); ?>" rel="stylesheet">


<div class="project-view-shell">
        <?php if ($projectApprovalSuccess === 'project_status_updated'): ?>
        <div class="rounded-xl border border-green-200 bg-green-50 p-4 text-sm text-green-700">
            Project sign-off was updated successfully.
        </div>
        <?php endif; ?>

        <?php if ($projectApprovalError !== ''): ?>
        <div class="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-700">
            <?php echo htmlspecialchars($projectApprovalError); ?>
        </div>
        <?php endif; ?>

        <?php
        $pmSuccessBanner = (string) ($_GET['success'] ?? '');
        $pmSuccessMessages = [
            'timeline_saved' => 'Timeline was saved.',
            'risk_saved' => 'Risk register was updated.',
            'file_uploaded' => 'File was uploaded to the project library.',
            'file_deleted' => 'Project file was removed.',
            'project_created' => 'Project created successfully.',
            'project_updated' => 'Project updated successfully.',
            'comment_posted' => 'Your comment was posted.',
            'team_invite_sent' => 'Team invitation was sent.',
            'invitation_accepted' => 'You joined this project team.',
        ];
        if (isset($pmSuccessMessages[$pmSuccessBanner]) && $pmSuccessBanner !== 'project_status_updated'): ?>
        <div class="rounded-xl border border-green-200 bg-green-50 p-4 text-sm text-green-700">
            <?php echo htmlspecialchars($pmSuccessMessages[$pmSuccessBanner]); ?>
        </div>
        <?php endif; ?>

        <?php
        $pmErr = (string) ($_GET['error'] ?? '');
        if ($pmErr !== '' && $projectApprovalError === ''): ?>
        <div class="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-700">
            <?php echo htmlspecialchars(str_replace('_', ' ', $pmErr)); ?>
        </div>
        <?php endif; ?>

        <div id="pv-overview" class="pjs-shell pv-shell pv-active-overview">
            <div class="project-workspace-content">
                <header class="pjs-header">
                    <nav class="project-view-dash-breadcrumb !mb-0" aria-label="Breadcrumb">
                        <a href="list">Projects</a>
                        <span class="project-view-dash-breadcrumb-sep" aria-hidden="true">&gt;</span>
                        <a href="#pv-overview">Project Workspace</a>
                        <span class="project-view-dash-breadcrumb-sep" aria-hidden="true">&gt;</span>
                        <span class="text-slate-600 font-semibold">Project Overview</span>
                    </nav>
                    <a href="list" class="pjs-back">
                        <i data-lucide="arrow-left" class="text-lg" aria-hidden="true"></i>
                        <span>Back to Projects</span>
                    </a>

                    <div class="pjs-kicker-row">
                        <span class="pjs-id-chip"><?php echo htmlspecialchars($project_ref_code); ?></span>
                    </div>
                    <div class="pjs-title-row">
                        <h1 class="pjs-title break-words"><?php echo htmlspecialchars($project['name']); ?></h1>
                        <button type="button" class="pjs-star" id="pjs-favorite-btn" aria-label="Favorite project" aria-pressed="false">
                            <i id="pjs-fav-icon" data-lucide="star-off" class="text-xl" aria-hidden="true"></i>
                        </button>
                    </div>
                    <p class="pjs-desc"><?php if ($descTrim !== ''): ?><?php echo nl2br(htmlspecialchars($descTrim)); ?><?php else: ?>No description yet — add one when you <a href="edit?id=<?php echo (int) $project['id']; ?>" class="text-emerald-700 font-bold hover:underline">edit the project</a>.<?php endif; ?></p>

                    <div class="pjs-chip-row">
                        <span class="pjs-chip <?php echo $status_color; ?> border-0"><?php echo htmlspecialchars($project['status']); ?></span>
                        <span class="pjs-chip"><i data-lucide="calendar" aria-hidden="true"></i><?php echo htmlspecialchars($project_timeline_hero); ?></span>
                        <span class="pjs-chip"><span class="pjs-pri-dot" style="background:<?php echo $project['priority'] === 'Urgent' ? '#dc2626' : ($project['priority'] === 'Low' ? '#64748b' : '#ea580c'); ?>"></span><?php echo htmlspecialchars($project['priority']); ?> Priority</span>
                        <?php if (!empty($projectWorkspaceParticipants)): ?>
                        <span class="pjs-chip pjs-chip-team">
                            <span class="pjs-team-ava">
                                <?php
                                foreach (array_slice($projectWorkspaceParticipants, 0, 4) as $tp):
                                    $tpu = $user_photo_url($tp['photo'] ?? null);
                                    ?>
                                    <?php if ($tpu): ?>
                                        <img src="<?php echo htmlspecialchars($tpu); ?>" alt="" width="24" height="24">
                                    <?php else: ?>
                                        <span><?php echo htmlspecialchars($project_lead_initials((string) ($tp['name'] ?? '?'))); ?></span>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </span>
                            <?php $tx = count($projectWorkspaceParticipants) - 4; ?>
                            <?php if ($tx > 0): ?>
                                <span class="text-slate-500 font-bold">+<?php echo (int) $tx; ?></span>
                            <?php endif; ?>
                            <span class="text-slate-600 font-bold ml-1">Team</span>
                        </span>
                        <?php endif; ?>
                    </div>

                    <nav class="pjs-tabs" aria-label="Project sections">
                        <a class="pjs-tab pjs-tab--active" href="#pv-overview"><i data-lucide="layout-dashboard" aria-hidden="true"></i> Overview</a>
                        <a class="pjs-tab" href="#pv-milestones"><i data-lucide="calendar-range" aria-hidden="true"></i> Timeline</a>
                        <a class="pjs-tab" href="#pv-tasks"><i data-lucide="list-todo" aria-hidden="true"></i> Tasks</a>
                        <a class="pjs-tab" href="#project-documentation-hub"><i data-lucide="folder-open" aria-hidden="true"></i> Files</a>
                        <a class="pjs-tab" href="#pv-budget"><i data-lucide="wallet" aria-hidden="true"></i> Budget</a>
                        <a class="pjs-tab" href="#pv-activity"><i data-lucide="messages-square" aria-hidden="true"></i> Activity</a>
                        <a class="pjs-tab" href="edit?id=<?php echo (int) $project['id']; ?>"><i data-lucide="settings" aria-hidden="true"></i> Settings</a>
                    </nav>
                </header>

                <div class="pjs-workspace-columns">
                    <div class="pjs-main-column">
                        <div class="pv-tab-panel pv-tab-panel--overview is-active" data-pv-panel="overview">
                        <div class="pv-tab-content-wrapper">
                        <div class="pjs-top-row">

                        <section class="premium-card pjs-chart-card">
                            <div class="premium-card-title !mb-2">
                                <i data-lucide="line-chart" class="text-emerald-600" aria-hidden="true"></i>
                                <span>Progress overview</span>
                            </div>
                            <p class="text-sm text-slate-500 m-0 mb-2"><?php echo htmlspecialchars($timelineChartCaption); ?></p>
                            <div class="pjs-chart-legend">
                                <span class="pjs-legend-i"><span class="pjs-legend-line pjs-legend-line--actual"></span> Actual</span>
                                <span class="pjs-legend-i"><span class="pjs-legend-line pjs-legend-line--planned" style="height:2px;background:repeating-linear-gradient(90deg,#94a3b8 0,#94a3b8 4px,transparent 4px,transparent 7px);width:1.5rem;border:none;"></span> Planned</span>
                            </div>
                            <svg class="w-full max-w-full" style="max-height:220px;" viewBox="0 0 <?php echo (int) $chart_w; ?> <?php echo (int) $chart_h; ?>" preserveAspectRatio="xMidYMid meet" aria-hidden="true">
                                <line x1="<?php echo (int) $chart_pad; ?>" y1="<?php echo (int) $chart_h - $chart_pad; ?>" x2="<?php echo (int) $chart_w - $chart_pad; ?>" y2="<?php echo (int) $chart_h - $chart_pad; ?>" stroke="#e2e8f0" stroke-width="1"/>
                                <?php for ($gi = 1; $gi <= 3; $gi++): $gy = $chart_pad + ($gi / 4) * ($chart_h - 2 * $chart_pad); ?>
                                <line x1="<?php echo (int) $chart_pad; ?>" y1="<?php echo (int) $gy; ?>" x2="<?php echo (int) $chart_w - $chart_pad; ?>" y2="<?php echo (int) $gy; ?>" stroke="#f1f5f9" stroke-width="1"/>
                                <?php endfor; ?>
                                <polyline fill="none" stroke="#94a3b8" stroke-width="2" stroke-dasharray="7 5" points="<?php echo htmlspecialchars($chart_poly_planned); ?>"/>
                                <polyline fill="none" stroke="#0d9488" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" points="<?php echo htmlspecialchars($chart_poly_actual); ?>"/>
                            </svg>
                            <div class="pjs-chart-labels">
                                <?php foreach ($chart_labels as $cl): ?>
                                <span><?php echo htmlspecialchars($cl); ?></span>
                                <?php endforeach; ?>
                            </div>
                        </section>

                        <section class="premium-card">
                            <div class="premium-card-title !mb-3">
                                <i data-lucide="notebook-text" class="text-emerald-600" aria-hidden="true"></i>
                                <span>Key dates</span>
                            </div>
                            <div class="pjs-kv">
                                <div class="pjs-kv-row">
                                    <span class="pjs-kv-k">Project start</span>
                                    <span class="pjs-kv-v"><?php echo !empty($project['start_date']) ? htmlspecialchars(date('M d, Y', strtotime($project['start_date']))) : '—'; ?></span>
                                </div>
                                <div class="pjs-kv-row">
                                    <span class="pjs-kv-k">Target finish</span>
                                    <span class="pjs-kv-v"><?php echo !empty($project['end_date']) ? htmlspecialchars(date('M d, Y', strtotime($project['end_date']))) : '—'; ?></span>
                                </div>
                                <div class="pjs-kv-row">
                                    <span class="pjs-kv-k">Forecast finish</span>
                                    <span class="pjs-kv-v"><?php echo htmlspecialchars($forecast_finish_label); ?></span>
                                </div>
                                <div class="pjs-kv-row">
                                    <span class="pjs-kv-k">Last updated</span>
                                    <span class="pjs-kv-v"><?php echo htmlspecialchars(date('M d, Y', strtotime($project['updated_at'] ?? $project['created_at']))); ?></span>
                                </div>
                            </div>
                        </section>
                        </div>

                        <section class="premium-card">
                            <div class="premium-card-title !mb-2">
                                <i data-lucide="layers" class="text-emerald-600" aria-hidden="true"></i>
                                <span>Risks &amp; team</span>
                            </div>
                            <p class="text-sm text-slate-500 m-0 mb-3">Review the risk register and delivery participants from overview.</p>
                            <div class="flex flex-wrap gap-2">
                                <button type="button" class="premium-btn premium-btn-secondary text-sm" style="padding:0.5rem 1rem" id="pv-open-risks-modal" aria-haspopup="dialog" aria-controls="pv-modal-risks">
                                    <i data-lucide="triangle-alert" class="text-base" aria-hidden="true"></i>
                                    Open Risks
                                </button>
                                <button type="button" class="premium-btn premium-btn-secondary text-sm" style="padding:0.5rem 1rem" id="pv-open-team-modal" aria-haspopup="dialog" aria-controls="pv-modal-team">
                                    <i data-lucide="users" class="text-base" aria-hidden="true"></i>
                                    Open Team
                                </button>
                            </div>
                        </section>

                        <section class="premium-card">

                            <div class="premium-card-title w-full flex-wrap justify-between gap-2 !mb-2">
                                <span class="inline-flex items-center gap-2"><i data-lucide="history" class="text-emerald-600" aria-hidden="true"></i> Recent activity</span>
                                <a href="#pv-activity" class="text-sm font-bold text-emerald-700 hover:underline">View all</a>
                            </div>
                            <div class="pjs-feed">
                                <?php if (empty($recent_feed_items)): ?>
                                <p class="text-sm text-slate-500 m-0 py-4 text-center">No recent task completions or comments yet.</p>
                                <?php else: ?>
                                    <?php foreach ($recent_feed_items as $f): ?>
                                    <div class="pjs-feed-item">
                                        <?php if (!empty($f['photo'])): ?>
                                        <img src="<?php echo htmlspecialchars($f['photo']); ?>" alt="" class="project-activity-avatar" width="36" height="36">
                                        <?php else: ?>
                                        <span class="project-activity-fallback"><?php echo htmlspecialchars($project_lead_initials($f['name'])); ?></span>
                                        <?php endif; ?>
                                        <div class="min-w-0 flex-1">
                                            <p class="project-activity-line m-0"><strong><?php echo htmlspecialchars($f['name']); ?></strong> · <?php echo htmlspecialchars($f['text']); ?></p>
                                            <p class="project-activity-time m-0"><?php echo htmlspecialchars($project_view_rel_time((int) $f['ts'])); ?></p>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </section>

                    <?php if (!empty($overview_chunks)): ?>
                    <section class="premium-card">

                        <div class="premium-card-title"><i data-lucide="file-text" class="text-emerald-600" aria-hidden="true"></i><span>Scope &amp; details</span></div>
                        <ul class="project-overview-list !mt-0">
                            <?php $overview_icons = ['tags', 'flag', 'package', 'badge-check'];
                            foreach ($overview_chunks as $idx => $chunk): ?>
                            <li class="project-overview-item">
                                <span class="project-overview-icon"><i data-lucide="<?php echo htmlspecialchars($overview_icons[$idx] ?? 'scroll-text'); ?>" aria-hidden="true"></i></span>
                                <div class="min-w-0">
                                    <p class="project-overview-label"><?php echo htmlspecialchars($chunk['label']); ?></p>
                                    <p class="project-overview-text"><?php echo nl2br(htmlspecialchars($chunk['text'])); ?></p>
                                </div>
                            </li>
                            <?php endforeach; ?>
                        </ul>
                    </section>
                    <?php endif; ?>

                        <div class="pjs-signoff-strip">
                            <span class="text-[11px] font-bold uppercase tracking-wider text-slate-400">Sign-off</span>
                            <span class="project-view-chip !py-0.5 <?php echo $approval_color; ?>"><?php echo htmlspecialchars($project['approved_status'] ?? 'Pending'); ?></span>
                            <div class="flex flex-wrap gap-2 ml-auto">
                                <a href="../tasks/create?project_id=<?php echo (int) $project['id']; ?>" class="premium-btn premium-btn-primary" style="padding:0.5rem 1rem;font-size:0.8rem;"><i data-lucide="plus" class="text-sm" aria-hidden="true"></i> New Task</a>
                                <a href="edit?id=<?php echo (int) $project['id']; ?>" class="premium-btn premium-btn-secondary" style="padding:0.5rem 1rem;font-size:0.8rem;"><i data-lucide="square-pen" class="text-sm" aria-hidden="true"></i> Edit</a>
                                <details class="project-view-actions-more">
                                    <summary class="premium-btn premium-btn-secondary cursor-pointer" style="padding:0.5rem 0.85rem;"><i data-lucide="ellipsis" aria-hidden="true"></i></summary>
                                    <div class="project-view-actions-more-panel" role="menu">
                                        <?php if (!empty($all_project_docs) || !empty($tasks)): ?>
                                        <a href="export_documentation?id=<?php echo (int) $project['id']; ?>" role="menuitem"><i data-lucide="file-text" class="text-base" aria-hidden="true"></i> Export PDF</a>
                                        <?php endif; ?>
                                        <a href="analytics" role="menuitem"><i data-lucide="line-chart" class="text-base" aria-hidden="true"></i> Portfolio analytics</a>
                                        <a href="list" role="menuitem"><i data-lucide="folder-open" class="text-base" aria-hidden="true"></i> All projects</a>
                                    </div>
                                </details>
                            </div>
                        </div>

        <?php if ($_SESSION['user_id'] == $project['created_by']): ?>
        <section class="premium-card !bg-gradient-to-br from-emerald-50 to-white border-emerald-100 pv-pm-workspace-card">
            <div class="premium-card-title !mb-8">
                <i data-lucide="shield-check" class="text-emerald-600" aria-hidden="true"></i>
                <span>Management & Approvals</span>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
                <div class="p-6 bg-white rounded-3xl border border-emerald-100 shadow-sm relative overflow-hidden group">
                    <div class="absolute top-0 right-0 w-32 h-32 bg-emerald-50 rounded-full -mr-16 -mt-16 transition-transform group-hover:scale-110"></div>
                    <div class="relative z-1">
                        <h4 class="text-base font-black text-slate-900 mb-2">Project Sign-off</h4>
                        <p class="text-xs text-slate-500 mb-6 leading-relaxed">Finalize the delivery status for this project. Current state: <span class="px-2 py-0.5 rounded-lg <?php echo $approval_color; ?> font-black uppercase tracking-wider text-[10px]"><?php echo $project['approved_status']; ?></span></p>

                        <form method="POST" action="view?id=<?php echo (int) $id; ?>" class="flex gap-3">
                            <input type="hidden" name="project_approval" value="1">
                            <button type="submit" name="approval_status" value="Approved" class="flex-1 premium-btn premium-btn-primary !py-2.5 !px-4 !rounded-xl !text-xs !font-black !justify-center shadow-lg shadow-emerald-700/20">
                                <i data-lucide="circle-check" class="text-sm" aria-hidden="true"></i>
                                <span>Approve Delivery</span>
                            </button>
                            <button type="submit" name="approval_status" value="Rejected" class="flex-1 premium-btn premium-btn-secondary !py-2.5 !px-4 !rounded-xl !text-xs !font-black !justify-center !text-rose-600 !border-rose-100 hover:!bg-rose-50">
                                <i data-lucide="ban" class="text-sm" aria-hidden="true"></i>
                                <span>Reject Project</span>
                            </button>
                        </form>
                    </div>
                </div>

                <div class="flex flex-column">
                    <h4 class="text-[11px] font-black text-slate-400 uppercase tracking-widest mb-4 flex items-center gap-2">
                        <i data-lucide="graduation-cap" class="text-sm text-emerald-600" aria-hidden="true"></i>
                        Review Queue
                    </h4>
                    <div class="space-y-3 max-h-[160px] overflow-y-auto pr-2 custom-scrollbar">
                        <?php if (empty($pending_review_tasks)): ?>
                            <div class="flex flex-col items-center justify-center p-8 bg-slate-50/50 rounded-2xl border border-dashed border-slate-200">
                                <i data-lucide="list-checks" class="text-slate-300 mb-1" aria-hidden="true"></i>
                                <p class="text-xs text-slate-400 font-medium italic">No tasks pending review.</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($pending_review_tasks as $pTask): ?>
                            <div class="flex items-center justify-between p-4 bg-white border border-slate-100 rounded-2xl shadow-sm hover:border-emerald-200 transition-colors group">
                                <div class="min-w-0">
                                    <p class="text-xs font-black text-slate-800 truncate group-hover:text-emerald-700 transition-colors"><?php echo htmlspecialchars($pTask['name']); ?></p>
                                    <p class="text-[10px] text-slate-400 font-bold mt-0.5">Assigned: <?php echo htmlspecialchars($pTask['assignee_summary']); ?></p>
                                </div>
                                <a href="../tasks/view?id=<?php echo $pTask['id']; ?>" class="p-2.5 bg-emerald-50 text-emerald-600 hover:bg-emerald-600 hover:text-white rounded-xl transition-all shadow-sm" title="Review Task">
                                    <i data-lucide="gavel" class="text-base" aria-hidden="true"></i>
                                </a>
                            </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </section>
        <?php endif; ?>

                        </div>
                        </div>

                        <div class="pv-tab-panel pv-tab-panel--milestones" data-pv-panel="milestones">
                        <div class="pv-tab-content-wrapper">
                    <section id="pv-milestones" class="premium-card">
                        <div class="premium-card-title !mb-2 flex-wrap justify-between gap-2">
                            <span class="inline-flex items-center gap-2">
                                <i data-lucide="flag" class="text-emerald-600" aria-hidden="true"></i>
                                <span>Timeline deliverables</span>
                            </span>
                        </div>
                        <p class="text-sm text-slate-500 m-0 mb-4">Planned dates feed the overview chart; set actual dates when work lands (or link a task — actual can be filled when the task is completed).</p>
                        <?php if ($canManageProjectPm): ?>
                        <form method="post" action="timeline_save" class="mb-6 p-4 bg-slate-50 rounded-xl border border-slate-200 grid grid-cols-1 md:grid-cols-2 gap-3">
                            <input type="hidden" name="project_id" value="<?php echo (int) $id; ?>">
                            <input type="hidden" name="timeline_action" value="create">
                            <div class="md:col-span-2">
                                <label class="block text-xs font-bold text-slate-500 uppercase mb-1">Title</label>
                                <input type="text" name="title" required class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm" placeholder="Milestone title">
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-slate-500 uppercase mb-1">Planned date</label>
                                <?php echo press_datetime_picker_field([
                                    'name' => 'planned_date',
                                    'value' => '',
                                    'mode' => 'date',
                                    'required' => true,
                                    'disable_past' => true,
                                    'class' => 'w-full px-3 py-2 border border-slate-300 rounded-lg text-sm',
                                ]); ?>
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-slate-500 uppercase mb-1">Link task (optional)</label>
                                <select name="linked_task_id" class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm">
                                    <option value="0">— None —</option>
                                    <?php foreach ($tasks as $tk): ?>
                                    <option value="<?php echo (int) $tk['id']; ?>"><?php echo htmlspecialchars((string) $tk['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="md:col-span-2">
                                <label class="block text-xs font-bold text-slate-500 uppercase mb-1">Notes</label>
                                <textarea name="notes" rows="2" class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm" placeholder="Optional context"></textarea>
                            </div>
                            <div class="md:col-span-2">
                                <button type="submit" class="premium-btn premium-btn-primary text-sm" style="padding:0.45rem 1rem">Add deliverable</button>
                            </div>
                        </form>
                        <?php endif; ?>
                        <?php if (empty($timelineItems)): ?>
                        <p class="text-sm text-slate-500 m-0">No timeline rows yet. <?php if (!$canManageProjectPm): ?>Ask the project manager to add planned deliverables.<?php endif; ?></p>
                        <?php else: ?>
                        <ul class="space-y-3 list-none m-0 p-0">
                            <?php foreach ($timelineItems as $tiRow):
                                $tiId = (int) ($tiRow['id'] ?? 0);
                                $plannedFmt = !empty($tiRow['planned_date']) ? date('M j, Y', strtotime((string) $tiRow['planned_date'])) : '—';
                                $actualFmt = !empty($tiRow['actual_date']) ? date('M j, Y', strtotime((string) $tiRow['actual_date'])) : '—';
                                ?>
                            <li class="border border-slate-200 rounded-xl p-4 bg-white">
                                <div class="flex flex-wrap items-start justify-between gap-2">
                                    <div class="min-w-0">
                                        <p class="font-extrabold text-slate-800 m-0"><?php echo htmlspecialchars((string) ($tiRow['title'] ?? '')); ?></p>
                                        <p class="text-xs text-slate-500 m-0 mt-1">Planned <strong class="text-slate-700"><?php echo htmlspecialchars($plannedFmt); ?></strong>
                                            · Actual <strong class="text-slate-700"><?php echo htmlspecialchars($actualFmt); ?></strong>
                                            <?php if (!empty($tiRow['linked_task_name'])): ?>
                                            · Task: <?php echo htmlspecialchars((string) $tiRow['linked_task_name']); ?>
                                            <?php endif; ?>
                                        </p>
                                        <?php if (!empty($tiRow['notes'])): ?>
                                        <p class="text-sm text-slate-600 m-0 mt-2"><?php echo nl2br(htmlspecialchars((string) $tiRow['notes'])); ?></p>
                                        <?php endif; ?>
                                    </div>
                                    <?php if ($canManageProjectPm): ?>
                                    <details class="text-sm w-full md:w-auto">
                                        <summary class="cursor-pointer font-bold text-emerald-700">Edit</summary>
                                        <div class="mt-3 space-y-3 border-t border-slate-100 pt-3">
                                            <form method="post" action="timeline_save" class="grid grid-cols-1 md:grid-cols-2 gap-2">
                                                <input type="hidden" name="project_id" value="<?php echo (int) $id; ?>">
                                                <input type="hidden" name="timeline_action" value="update">
                                                <input type="hidden" name="id" value="<?php echo $tiId; ?>">
                                                <div class="md:col-span-2">
                                                    <label class="block text-xs font-bold text-slate-500 uppercase mb-1">Title</label>
                                                    <input type="text" name="title" required class="w-full px-3 py-2 border rounded-lg text-sm" value="<?php echo htmlspecialchars((string) ($tiRow['title'] ?? '')); ?>">
                                                </div>
                                                <div>
                                                    <label class="block text-xs font-bold text-slate-500 uppercase mb-1">Planned</label>
                                                    <?php echo press_datetime_picker_field([
                                                        'name' => 'planned_date',
                                                        'value' => (string) ($tiRow['planned_date'] ?? ''),
                                                        'mode' => 'date',
                                                        'required' => true,
                                                        'class' => 'w-full px-3 py-2 border rounded-lg text-sm',
                                                    ]); ?>
                                                </div>
                                                <div>
                                                    <label class="block text-xs font-bold text-slate-500 uppercase mb-1">Actual</label>
                                                    <?php echo press_datetime_picker_field([
                                                        'name' => 'actual_date',
                                                        'value' => (string) ($tiRow['actual_date'] ?? ''),
                                                        'mode' => 'date',
                                                        'class' => 'w-full px-3 py-2 border rounded-lg text-sm',
                                                    ]); ?>
                                                </div>
                                                <div class="md:col-span-2">
                                                    <label class="block text-xs font-bold text-slate-500 uppercase mb-1">Link task</label>
                                                    <select name="linked_task_id" class="w-full px-3 py-2 border rounded-lg text-sm">
                                                        <option value="0">— None —</option>
                                                        <?php foreach ($tasks as $tk): ?>
                                                        <option value="<?php echo (int) $tk['id']; ?>" <?php echo (int) ($tiRow['linked_task_id'] ?? 0) === (int) $tk['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars((string) $tk['name']); ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                                <div class="md:col-span-2">
                                                    <label class="block text-xs font-bold text-slate-500 uppercase mb-1">Notes</label>
                                                    <textarea name="notes" rows="2" class="w-full px-3 py-2 border rounded-lg text-sm"><?php echo htmlspecialchars((string) ($tiRow['notes'] ?? '')); ?></textarea>
                                                </div>
                                                <label class="md:col-span-2 flex items-center gap-2 text-sm text-slate-700">
                                                    <input type="checkbox" name="mark_completed" value="1" class="rounded border-slate-300" <?php echo !empty($tiRow['completed_at']) ? 'checked' : ''; ?>>
                                                    Mark completed (sets actual date if empty)
                                                </label>
                                                <div class="md:col-span-2 flex flex-wrap gap-2">
                                                    <button type="submit" class="premium-btn premium-btn-primary text-sm" style="padding:0.4rem 0.9rem">Save</button>
                                                </div>
                                            </form>
                                            <form method="post" action="timeline_save" onsubmit="return confirm('Delete this timeline row?');">
                                                <input type="hidden" name="project_id" value="<?php echo (int) $id; ?>">
                                                <input type="hidden" name="timeline_action" value="delete">
                                                <input type="hidden" name="id" value="<?php echo $tiId; ?>">
                                                <button type="submit" class="text-sm text-rose-600 font-bold hover:underline">Delete</button>
                                            </form>
                                        </div>
                                    </details>
                                    <?php endif; ?>
                                </div>
                            </li>
                            <?php endforeach; ?>
                        </ul>
                        <?php endif; ?>
                    </section>
                    </div>
                    </div>

                    <div class="pv-tab-panel pv-tab-panel--tasks" id="pv-tasks" data-pv-panel="tasks">
                    <div class="pv-tab-content-wrapper">
        <section class="premium-card">
            <div class="premium-card-title w-full flex-wrap justify-between gap-3">
                <span class="inline-flex items-center gap-3 min-w-0">
                    <i data-lucide="list-todo" class="flex-shrink-0 text-emerald-600" aria-hidden="true"></i>
                    <span class="truncate">Task overview</span>
                </span>
                <a href="../tasks/create?project_id=<?php echo $project['id']; ?>" class="premium-btn premium-btn-primary shrink-0" style="padding: 0.5rem 1rem; font-size: 0.8rem;">
                    <i data-lucide="plus" class="text-sm" aria-hidden="true"></i>
                    <span>New Task</span>
                </a>
            </div>

            <?php if (!empty($tasks)): ?>
            <div class="project-task-kanban-shell">
            <div class="project-task-kanban-scroll">
                <?php foreach ($projectKanbanBuckets as $kanbanLabel => $kanbanTasks): ?>
                <div class="project-task-kanban-col">
                    <header class="project-task-kanban-col-head">
                        <span class="project-task-kanban-col-title"><?php echo htmlspecialchars($kanbanLabel); ?></span>
                        <div class="flex items-center gap-1 shrink-0">
                            <span class="project-task-kanban-col-meta"><?php echo count($kanbanTasks); ?></span>
                            <a href="../tasks/create?project_id=<?php echo (int) $project['id']; ?>" class="inline-flex items-center justify-center w-7 h-7 rounded-lg text-emerald-600 hover:bg-emerald-50" title="Add task" aria-label="Add task">
                                <i data-lucide="plus" class="text-lg leading-none" aria-hidden="true"></i>
                            </a>
                        </div>
                    </header>
                    <div class="project-task-kanban-cards">
                    <?php foreach ($kanbanTasks as $task): ?>
                <?php
                $task_status_color = $task['workflow_state']['badge_class'] ?? ($task_status_colors[$task['status']] ?? 'bg-gray-100 text-gray-800');
                $task_priority_color = $task_priority_colors[$task['priority']] ?? 'text-gray-600';
                $is_overdue = !empty($task['due_date']) && $task['status'] !== 'Completed' && strtotime($task['due_date']) < strtotime(date('Y-m-d'));
                ?>
                <article class="project-task-card-kanban" style="<?php echo $is_overdue ? 'border-left: 4px solid #ef4444;' : ''; ?>">
                    <div class="flex items-center justify-between mb-3">
                        <span class="px-2 py-1 rounded-full text-[10px] font-bold uppercase tracking-wider <?php echo $task_status_color; ?>"><?php echo htmlspecialchars($task['workflow_state']['label'] ?? $task['status']); ?></span>
                        <span class="text-[10px] font-bold <?php echo $task_priority_color; ?>">
                            <i data-lucide="flag" class="text-xs align-middle" aria-hidden="true"></i>
                            <?php echo htmlspecialchars($task['priority']); ?>
                        </span>
                    </div>

                    <h3 class="font-bold text-slate-800 text-sm line-clamp-2 mb-2"><?php echo htmlspecialchars($task['name']); ?></h3>
                    <?php if (!empty($task['workflow_state']['description'])): ?>
                    <p class="text-[11px] text-slate-500 mb-3 line-clamp-2"><?php echo htmlspecialchars($task['workflow_state']['description']); ?></p>
                    <?php else: ?>
                    <p class="text-[11px] text-slate-400 mb-3 line-clamp-1"><?php echo !empty($task['description']) ? htmlspecialchars(mb_strimwidth($task['description'], 0, 120, '…', 'UTF-8')) : 'No summary yet.'; ?></p>
                    <?php endif; ?>

                    <div class="grid grid-cols-2 gap-2 mb-3 p-2 bg-slate-50 rounded-lg">
                        <div>
                            <span class="block text-[9px] font-bold text-slate-400 uppercase">Assignee</span>
                            <span class="block text-[11px] font-bold text-slate-700 truncate"><?php echo htmlspecialchars($task['assignee_summary']); ?></span>
                        </div>
                        <div>
                            <span class="block text-[9px] font-bold text-slate-400 uppercase">Due</span>
                            <span class="block text-[11px] font-bold <?php echo $is_overdue ? 'text-red-600' : 'text-slate-700'; ?>">
                                <?php echo !empty($task['due_date']) ? date('M d', strtotime($task['due_date'])) : '—'; ?>
                            </span>
                        </div>
                    </div>

                    <div class="flex items-center gap-1.5 flex-wrap">
                        <a href="../tasks/view?id=<?php echo $task['id']; ?>" class="flex-1 premium-btn premium-btn-primary" style="padding: 0.4rem; justify-content: center; font-size: 0.7rem; min-height: auto;">
                            <i data-lucide="eye" class="text-xs" aria-hidden="true"></i>
                            <span>View</span>
                        </a>
                        <a href="../tasks/view?id=<?php echo $task['id']; ?>#task-documentation-hub" class="premium-btn premium-btn-secondary relative" style="padding: 0.4rem 0.5rem; font-size: 0.65rem;" title="Documentation (<?php echo (int) $task['documentation_count']; ?> file<?php echo (int) $task['documentation_count'] === 1 ? '' : 's'; ?>)">
                            <i data-lucide="file-text" class="text-xs" aria-hidden="true"></i>
                            <?php if ((int) $task['documentation_count'] > 0): ?>
                            <span class="absolute -top-1 -right-1 min-w-[1rem] h-[1rem] px-0.5 flex items-center justify-center rounded-full bg-emerald-600 text-white text-[8px] font-extrabold leading-none"><?php echo (int) $task['documentation_count'] > 9 ? '9+' : (int) $task['documentation_count']; ?></span>
                            <?php endif; ?>
                        </a>
                        <?php if (!empty($task['can_edit'])): ?>
                        <button type="button" onclick="openConfirmModal('Delete Task', 'Are you sure?', '../tasks/delete?id=<?php echo $task['id']; ?>')" class="premium-btn premium-btn-secondary text-red-600 hover:bg-red-50" style="padding: 0.4rem; font-size: 0.65rem;">
                            <i data-lucide="trash-2" class="text-xs" aria-hidden="true"></i>
                        </button>
                        <?php endif; ?>
                    </div>
                </article>
                    <?php endforeach; ?>
                    <?php if (empty($kanbanTasks)): ?>
                    <p class="text-center text-xs text-slate-400 py-8 px-2">Drop work here as you organize delivery.</p>
                    <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            </div>
            <?php else: ?>
            <div class="p-12 text-center">
                <i data-lucide="clipboard-list" class="text-4xl text-slate-200 mb-4" aria-hidden="true"></i>
                <p class="text-slate-500 font-medium">No tasks found for this project.</p>
            </div>
            <?php endif; ?>
        </section>
        </div>
        </div>

        <div class="pv-tab-panel pv-tab-panel--files" data-pv-panel="files">
        <div class="pv-tab-content-wrapper">
        <section class="premium-card doc-hub-container" id="project-documentation-hub" aria-labelledby="project-doc-hub-heading">
            <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between mb-6">
                <div class="min-w-0">
                    <h2 id="project-doc-hub-heading" class="flex items-center gap-3 text-xl font-extrabold text-slate-900 tracking-tight mb-2">
                        <i data-lucide="folder-heart" class="text-emerald-600 flex-shrink-0" aria-hidden="true"></i>
                        <span>Documentation hub</span>
                    </h2>
                    <p class="text-sm text-slate-500">Search and open task files, status evidence, and project library uploads in one place. Files are stored under this project’s folder.</p>
                </div>
                <div class="flex flex-wrap items-center gap-2 shrink-0">
                    <span class="px-3 py-1.5 rounded-full bg-white border border-emerald-200 text-emerald-700 text-xs font-bold uppercase tracking-wide whitespace-nowrap"><?php echo number_format(count($all_project_docs)); ?> file<?php echo count($all_project_docs) === 1 ? '' : 's'; ?></span>
                </div>
            </div>

            <?php if ($canManageProjectPm): ?>
            <form method="post" action="project_file_save" enctype="multipart/form-data" class="mb-6 flex flex-wrap items-end gap-3 p-4 bg-slate-50 rounded-xl border border-slate-200">
                <input type="hidden" name="project_id" value="<?php echo (int) $id; ?>">
                <input type="hidden" name="file_action" value="upload">
                <div class="min-w-0 flex-1">
                    <label class="block text-xs font-bold text-slate-500 uppercase mb-1">Project library upload</label>
                    <input type="file" name="project_file" required class="w-full text-sm text-slate-600 file:mr-3 file:py-2 file:px-3 file:rounded-lg file:border-0 file:bg-emerald-50 file:text-emerald-800 file:text-xs file:font-bold" accept=".jpg,.jpeg,.png,.gif,.webp,.pdf,.doc,.docx">
                </div>
                <button type="submit" class="premium-btn premium-btn-primary text-sm shrink-0" style="padding:0.5rem 1rem">Upload</button>
            </form>
            <?php endif; ?>
            <?php if ($canManageProjectPm && !empty($projectUploadedFiles)): ?>
            <div class="mb-6 rounded-xl border border-slate-200 bg-white p-4">
                <p class="text-xs font-bold uppercase text-slate-500 mb-2">Library files (direct uploads)</p>
                <ul class="text-sm space-y-2 m-0 p-0 list-none">
                    <?php foreach ($projectUploadedFiles as $pfi): ?>
                    <li class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-50 pb-2 last:border-0 last:pb-0">
                        <?php
                        $pff = (string) ($pfi['file_path'] ?? '');
                        $pfh = $pff !== '' ? rtrim(BASE_URL, '/') . '/' . ltrim($pff, '/') : '';
                        ?>
                        <a href="<?php echo htmlspecialchars($pfh); ?>" class="font-semibold text-emerald-700 hover:underline truncate min-w-0" target="_blank" rel="noopener noreferrer"><?php echo htmlspecialchars((string) ($pfi['original_name'] ?? 'File')); ?></a>
                        <form method="post" action="project_file_save" class="shrink-0" onsubmit="return confirm('Remove this file from the project library?');">
                            <input type="hidden" name="project_id" value="<?php echo (int) $id; ?>">
                            <input type="hidden" name="file_action" value="delete">
                            <input type="hidden" name="file_id" value="<?php echo (int) ($pfi['id'] ?? 0); ?>">
                            <button type="submit" class="text-xs font-bold text-rose-600 hover:underline">Remove</button>
                        </form>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>

            <?php if (empty($all_project_docs)): ?>
            <div class="p-8 text-center bg-white/50 rounded-2xl border border-dashed border-emerald-200">
                <i data-lucide="package" class="text-4xl text-emerald-200 mb-2" aria-hidden="true"></i>
                <p class="text-slate-500 font-medium">No documentation has been submitted yet.</p>
            </div>
            <?php else: ?>
            <div class="doc-search-wrapper">
                <label for="projectDocumentationSearch" class="sr-only">Filter documentation</label>
                <i data-lucide="search" class="pointer-events-none absolute left-4 top-1/2 z-[1] -translate-y-1/2 text-slate-400 w-5 h-5" aria-hidden="true"></i>
                <input
                    type="search"
                    id="projectDocumentationSearch"
                    class="doc-search-input"
                    placeholder="Search by file name, task, uploader, or type..."
                    autocomplete="off"
                    aria-controls="projectDocumentationList"
                >
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4" id="projectDocumentationList">
                <?php foreach ($all_project_docs as $projectDoc): ?>
                <?php
                $projectDocHref = !empty($projectDoc['file_path'])
                    ? rtrim(BASE_URL, '/') . '/' . ltrim((string) $projectDoc['file_path'], '/')
                    : '';
                $projectDocFileBase = basename((string) ($projectDoc['file_path'] ?? ''));
                $projectDocDisplayName = trim((string) ($projectDoc['context_label'] ?? '')) !== ''
                    ? (string) $projectDoc['context_label']
                    : ($projectDocFileBase !== '' ? $projectDocFileBase : 'Attachment');
                $projectDocTokens = [
                    (string) ($projectDoc['task_name'] ?? ''),
                    (string) ($projectDoc['uploader_name'] ?? ''),
                    (string) ($projectDoc['file_source'] ?? ''),
                    (string) ($projectDoc['context_label'] ?? ''),
                    $projectDocDisplayName,
                    $projectDocFileBase,
                    date('M d, Y', strtotime($projectDoc['created_at'])),
                ];
                $file_ext = strtolower(pathinfo($projectDoc['file_path'] ?? '', PATHINFO_EXTENSION));
                $icon = 'file';
                if (in_array($file_ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
                    $icon = 'image';
                }
                if ($file_ext === 'pdf') {
                    $icon = 'file-text';
                }
                if (in_array($file_ext, ['doc', 'docx'])) {
                    $icon = 'file-text';
                }
                ?>
                <article class="doc-item project-doc-item group" data-doc-search="<?php echo htmlspecialchars(strtolower(implode(' ', $projectDocTokens))); ?>" title="<?php echo htmlspecialchars($projectDoc['task_name'] . ' · ' . $projectDocDisplayName, ENT_QUOTES, 'UTF-8'); ?>">
                    <div class="doc-icon group-hover:bg-white">
                        <i data-lucide="<?php echo htmlspecialchars($icon); ?>" aria-hidden="true"></i>
                    </div>
                    <div class="doc-info">
                        <h4 class="doc-title truncate"><?php echo htmlspecialchars($projectDocDisplayName); ?></h4>
                        <div class="doc-meta">
                            <span class="truncate max-w-[10rem]" title="<?php echo htmlspecialchars($projectDoc['task_name']); ?>">Task: <?php echo htmlspecialchars($projectDoc['task_name']); ?></span>
                            <span class="hidden sm:inline">•</span>
                            <span class="truncate"><?php echo htmlspecialchars($projectDoc['uploader_name']); ?></span>
                            <span>•</span>
                            <span><?php echo date('M d, Y', strtotime($projectDoc['created_at'])); ?></span>
                        </div>
                        <div class="mt-2 text-xs font-semibold text-slate-600 truncate">
                            <span class="text-emerald-700 font-bold uppercase tracking-tight"><?php echo htmlspecialchars($projectDoc['file_source']); ?></span>
                            <?php if (trim((string) ($projectDoc['context_label'] ?? '')) !== '' && strcasecmp(trim((string) $projectDoc['context_label']), $projectDocDisplayName) !== 0): ?>
                            <span class="text-slate-400 mx-1">·</span>
                            <span class="truncate"><?php echo htmlspecialchars($projectDoc['context_label']); ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php if ($projectDocHref !== ''): ?>
                    <a href="<?php echo htmlspecialchars($projectDocHref); ?>" target="_blank" rel="noopener noreferrer" class="p-2 rounded-lg bg-slate-50 text-slate-600 hover:bg-emerald-600 hover:text-white transition flex-shrink-0 self-center" aria-label="<?php echo htmlspecialchars('Open file: ' . $projectDocDisplayName, ENT_QUOTES, 'UTF-8'); ?>">
                        <i data-lucide="external-link" class="text-lg" aria-hidden="true"></i>
                    </a>
                    <?php endif; ?>
                </article>
                <?php endforeach; ?>
            </div>
            <p id="projectDocumentationEmptyState" class="hidden text-center py-6 text-slate-400 italic">No documentation entries match your search.</p>
            <?php endif; ?>
        </section>
        </div>
        </div>

        <div class="pv-tab-panel pv-tab-panel--budget" id="pv-budget" data-pv-panel="budget">
                        <div class="pv-tab-content-wrapper">
                        <section class="premium-card">
                            <div class="premium-card-title !mb-1">
                                <i data-lucide="chart-pie" class="text-emerald-600" aria-hidden="true"></i>
                                <span>Budget overview</span>
                            </div>
                            <?php if ($budgetEnabled): ?>
                            <p class="text-xs text-slate-500 m-0 mb-1">Spent vs project budget (<?php echo htmlspecialchars($budgetCurDisp); ?>). Configure the cap when you <a href="edit?id=<?php echo (int) $project['id']; ?>" class="text-emerald-700 font-bold hover:underline">edit the project</a>.</p>
                            <div class="mt-3 text-sm font-extrabold text-slate-800">
                                Spent: <?php echo htmlspecialchars(number_format($budgetSpentTotal, 2)); ?>
                                <?php echo htmlspecialchars($budgetCurDisp); ?>
                                <?php if ($budgetCapVal !== null && $budgetCapVal > 0): ?>
                                · Budget: <?php echo htmlspecialchars(number_format($budgetCapVal, 2)); ?> <?php echo htmlspecialchars($budgetCurDisp); ?>
                                (<?php echo (int) $budgetSpendPct; ?>%)
                                <?php elseif ($budgetCapVal === null || $budgetCapVal <= 0): ?>
                                · <span class="text-amber-700">Set a budget amount to compare spend.</span>
                                <?php endif; ?>
                            </div>
                            <div class="pjs-budget-bar mt-2">
                                <div class="pjs-budget-fill <?php echo $budgetSpendPct > 100 ? '!bg-rose-500' : ''; ?>" style="width: <?php echo (int) min(100, max(0, $budgetSpendPct)); ?>%;"></div>
                            </div>
                            <div class="pjs-budget-meta">
                                <span>Task expenses (rolled up)</span>
                                <span>Task progress <?php echo (int) $progress; ?>%</span>
                            </div>
                            <?php else: ?>
                            <p class="text-xs text-slate-500 m-0 mb-1">Budget tracking is off. Enable it on the project <a href="edit?id=<?php echo (int) $project['id']; ?>" class="text-emerald-700 font-bold hover:underline">settings</a> to record task expenses. Meanwhile this bar reflects <strong>work completed</strong> vs. <strong>schedule elapsed</strong>.</p>
                            <div class="mt-3 text-sm font-extrabold text-slate-800">Task completion: <?php echo (int) $progress; ?>% · Timeline elapsed: <?php echo (int) $schedule_elapsed_pct; ?>%</div>
                            <div class="pjs-budget-bar">
                                <div class="pjs-budget-fill" style="width: <?php echo (int) min(100, max(0, $progress)); ?>%;"></div>
                            </div>
                            <div class="pjs-budget-meta">
                                <span>Completed work (<?php echo (int) $progress; ?>%)</span>
                                <span>Schedule (<?php echo (int) $schedule_elapsed_pct; ?>%)</span>
                            </div>
                            <?php endif; ?>
                        </section>
        </div>
        </div>

    <!-- Project Discussion & Comments -->
    <div id="pv-activity" class="pv-tab-panel pv-tab-panel--activity" data-pv-panel="activity">
        <div class="pv-tab-content-wrapper">
        <section class="premium-card">
            <div class="premium-card-title !mb-2 flex-wrap justify-between gap-2">
                <span class="inline-flex items-center gap-2">
                    <i data-lucide="messages-square" class="text-emerald-600" aria-hidden="true"></i>
                    <span>Activity log</span>
                </span>
                <span class="text-xs font-bold text-slate-400 uppercase tracking-wider">Audit trail</span>
            </div>
            <p class="text-sm text-slate-500 m-0 mb-4">Structured record of project changes. Discussion and comments are below.</p>
                <form method="get" action="view" class="flex flex-wrap items-end gap-2 mb-4">
                    <input type="hidden" name="id" value="<?php echo (int) $id; ?>">
                    <div>
                        <label for="pal_action_sel" class="block text-xs font-bold text-slate-500 uppercase mb-1">Filter by action</label>
                        <select name="pal_action" id="pal_action_sel" class="px-3 py-2 border border-slate-300 rounded-lg text-sm">
                            <option value=""<?php echo $activityActionFilter === '' ? ' selected' : ''; ?>>All</option>
                            <option value="project.updated"<?php echo $activityActionFilter === 'project.updated' ? ' selected' : ''; ?>>Project Updated</option>
                            <option value="project.created"<?php echo $activityActionFilter === 'project.created' ? ' selected' : ''; ?>>Project Created</option>
                            <option value="task.status_changed"<?php echo $activityActionFilter === 'task.status_changed' ? ' selected' : ''; ?>>Task Status Changed</option>
                            <option value="task.created"<?php echo $activityActionFilter === 'task.created' ? ' selected' : ''; ?>>Task Created</option>
                            <option value="file.uploaded"<?php echo $activityActionFilter === 'file.uploaded' ? ' selected' : ''; ?>>File Uploaded</option>
                            <option value="comment.posted"<?php echo $activityActionFilter === 'comment.posted' ? ' selected' : ''; ?>>Comment Posted</option>
                            <option value="risk.created"<?php echo $activityActionFilter === 'risk.created' ? ' selected' : ''; ?>>Risk Created</option>
                            <option value="project.team_invitation_sent"<?php echo $activityActionFilter === 'project.team_invitation_sent' ? ' selected' : ''; ?>>Project Team Invitation Sent</option>
                            <option value="project.team_invitation_accepted"<?php echo $activityActionFilter === 'project.team_invitation_accepted' ? ' selected' : ''; ?>>Project Team Invitation Accepted</option>
                            <option value="task.team_invitation_sent"<?php echo $activityActionFilter === 'task.team_invitation_sent' ? ' selected' : ''; ?>>Task Team Invitation Sent</option>
                            <option value="task.team_invitation_accepted"<?php echo $activityActionFilter === 'task.team_invitation_accepted' ? ' selected' : ''; ?>>Task Team Invitation Accepted</option>
                            <option value="timeline.created"<?php echo $activityActionFilter === 'timeline.created' ? ' selected' : ''; ?>>Timeline Item Created</option>
                        </select>
                    </div>
                    <button type="submit" class="premium-btn premium-btn-secondary text-sm" style="padding:0.45rem 1rem">Apply</button>
                </form>
                <?php if (empty($activityLogRows)): ?>
                <p class="text-sm text-slate-500 m-0 py-6 text-center border border-dashed border-slate-200 rounded-xl">No audit events recorded yet.</p>
                <?php else: ?>
                <div class="space-y-2 max-h-[28rem] overflow-y-auto pr-1">
                    <?php foreach ($activityLogRows as $al): ?>
                    <div class="rounded-xl border border-slate-100 bg-slate-50/80 p-3 text-sm">
                        <div class="flex flex-wrap justify-between gap-2">
                            <span class="font-bold text-slate-800"><?php echo htmlspecialchars((string) ($al['user_name'] ?? 'User')); ?></span>
                            <span class="text-xs text-slate-400 font-mono"><?php echo htmlspecialchars(date('M j, Y g:i A', strtotime((string) ($al['created_at'] ?? 'now')))); ?></span>
                        </div>
                        <div class="text-xs text-slate-600 mt-1.5 leading-relaxed">
                            <?php echo translate_project_activity((string) ($al['action'] ?? ''), (string) ($al['entity_type'] ?? null), (string) ($al['metadata'] ?? null)); ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
        </section>

        <?php if (!empty($projectWorkspaceShowGroupChat)): ?>
        <details class="rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden">
            <summary class="px-6 py-4 cursor-pointer list-none font-bold text-sm text-slate-600 hover:bg-slate-50 [&::-webkit-details-marker]:hidden flex items-center justify-between gap-2">
                <span>Advanced discussion (@mentions &amp; replies)</span>
                <i data-lucide="chevron-down" class="text-slate-400" aria-hidden="true"></i>
            </summary>
            <div class="border-t border-slate-100">
        <?php endif; ?>
        <section class="project-view-card" id="project-comments">
            <div class="project-view-section-head">
                <div>
                    <div class="project-view-title-row">
                        <h2 class="project-view-section-title">Discussion</h2>
                        <button type="button" class="project-view-info-btn" title="Use comments, replies, mentions, and attachments to coordinate project updates.">
                            <i data-lucide="info" aria-hidden="true"></i>
                        </button>
                    </div>
                    <p class="project-view-section-caption">+</p>
                </div>
            </div>

            <div class="project-comment-layout">

            <!-- ── Comment Feed ─────────────────────────────── -->
            <div class="project-feed-card">
                <div class="project-comment-feed" id="pc-feed">

                    <?php if (empty($project_comments)): ?>
                    <div class="project-empty-card">
                        <i data-lucide="message-circle" aria-hidden="true"></i>
                        <p>No discussion yet.</p>
                    </div>
                    <?php else: ?>
                    <?php
                    $pcInitials = static function (string $n): string {
                        $p = explode(' ', trim($n));

                        return count($p) >= 2
                            ? strtoupper(substr($p[0], 0, 1) . substr($p[1], 0, 1))
                            : strtoupper(substr($n, 0, 2));
                    };
                    foreach ($project_comments as $pc):
                        $pc_json = json_encode(['id' => (int) $pc['id'], 'body' => $pc['comment'], 'sender' => $pc['user_name']]);
                    ?>
                    <div class="pc-comment group project-comment-card"
                         id="pc-<?php echo $pc['id']; ?>"
                         data-comment='<?php echo htmlspecialchars($pc_json, ENT_QUOTES); ?>'>

                        <!-- Reply quote -->
                        <?php if ($pc['reply_to_id'] && $pc['reply_to_comment']): ?>
                        <div class="pc-reply-quote">
                            <p class="pc-reply-quote-author"><?php echo htmlspecialchars($pc['reply_to_user_name'] ?? 'User'); ?></p>
                            <p class="pc-reply-quote-text"><?php echo htmlspecialchars(mb_substr($pc['reply_to_comment'], 0, 120)); ?><?php echo mb_strlen($pc['reply_to_comment']) > 120 ? '…' : ''; ?></p>
                        </div>
                        <?php endif; ?>

                        <div class="flex items-start gap-3">
                            <?php if (!empty($pc['user_photo']) && $pc['user_photo'] !== 'default.png'): ?>
                                <img src="<?php echo htmlspecialchars(BASE_URL . ltrim($pc['user_photo'], '/')); ?>" alt="<?php echo htmlspecialchars($pc['user_name']); ?>" class="pc-comment-avatar">
                            <?php else: ?>
                                <div class="pc-comment-avatar-fallback" aria-hidden="true">
                                    <?php echo htmlspecialchars($pcInitials($pc['user_name'])); ?>
                                </div>
                            <?php endif; ?>
                            <div class="flex-1 min-w-0">
                                <div class="flex items-center justify-between gap-2 mb-1">
                                    <span class="pc-comment-author"><?php echo htmlspecialchars($pc['user_name']); ?></span>
                                    <span class="pc-comment-time"><?php echo date('M d, Y H:i', strtotime($pc['created_at'])); ?></span>
                                </div>

                                <div class="project-comment-body"><?php
                                    $body = htmlspecialchars($pc['comment']);
                                    $body = preg_replace(
                                        '/@([A-Za-z0-9 ]+?)(?=\s|$|[^A-Za-z0-9 ])/u',
                                        '<span class="pc-mention-chip">@$1</span>',
                                        $body
                                    );
                                    echo nl2br($body);
                                ?></div>

                                <?php if ($pc['document_ref']): ?>
                                <div class="pc-doc-ref">
                                    <i data-lucide="file-text" aria-hidden="true"></i>
                                    <span>Ref: <?php echo htmlspecialchars($pc['document_ref']); ?></span>
                                </div>
                                <?php endif; ?>

                                <?php if (!empty($pc_attach_map[$pc['id']])): ?>
                                <div class="pc-attach-grid">
                                    <?php foreach ($pc_attach_map[$pc['id']] as $pca):
                                        $is_img = strpos($pca['file_type'], 'image/') === 0;
                                        $is_voice = attachment_is_voice($pca);
                                        $pca_url = BASE_URL . $pca['file_path'];
                                    ?>
                                    <?php if ($is_img): ?>
                                    <a href="<?php echo htmlspecialchars($pca_url); ?>" target="_blank" rel="noopener noreferrer">
                                        <img src="<?php echo htmlspecialchars($pca_url); ?>" alt="<?php echo htmlspecialchars($pca['file_name']); ?>"
                                             class="pc-attach-thumb">
                                    </a>
                                    <?php elseif ($is_voice): ?>
                                    <div class="rounded-xl border border-slate-200 bg-slate-50 p-2 flex flex-col gap-1 max-w-[220px]">
                                        <audio class="w-full h-9" controls preload="metadata" src="<?php echo htmlspecialchars($pca_url); ?>"></audio>
                                        <span class="text-[10px] font-bold text-slate-400 truncate"><?php echo htmlspecialchars($pca['file_name']); ?></span>
                                    </div>
                                    <?php else: ?>
                                    <a href="<?php echo htmlspecialchars($pca_url); ?>" target="_blank" rel="noopener noreferrer"
                                       class="pc-attach-file">
                                        <i data-lucide="paperclip" aria-hidden="true"></i>
                                        <span class="truncate max-w-[140px]"><?php echo htmlspecialchars($pca['file_name']); ?></span>
                                    </a>
                                    <?php endif; ?>
                                    <?php endforeach; ?>
                                </div>
                                <?php endif; ?>
                            </div>

                            <!-- Reply button (shown on hover) -->
                            <button type="button"
                                    class="pc-reply-btn flex-shrink-0 p-1.5 rounded-lg text-slate-400"
                                    title="Reply"
                                    aria-label="Reply to this comment"
                                    onclick="pcSetReply(JSON.parse(this.closest('[data-comment]').dataset.comment))">
                                <i data-lucide="reply" aria-hidden="true"></i>
                            </button>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- ── Comment Form ─────────────────────────────── -->
            <div class="project-compose-card">

                <!-- Reply-to preview -->
                <div id="pcReplyBar" class="pc-reply-bar-preview" hidden role="region" aria-label="Replying to comment">
                    <div class="pc-reply-bar-preview__rail" aria-hidden="true"></div>
                    <div class="pc-reply-bar-preview__inner">
                        <div class="pc-reply-bar-preview__row">
                            <span class="pc-reply-bar-preview__badge">
                                <i data-lucide="reply" aria-hidden="true"></i>
                                Replying to
                            </span>
                            <button type="button" onclick="pcCancelReply()" class="pc-reply-bar-preview__close" aria-label="Cancel reply">
                                <i data-lucide="x" aria-hidden="true"></i>
                            </button>
                        </div>
                        <p id="pcReplyBarSender" class="pc-reply-bar-preview__author"></p>
                        <p id="pcReplyBarPreview" class="pc-reply-bar-preview__snippet"></p>
                    </div>
                </div>

                <form method="POST" action="comment" enctype="multipart/form-data" id="pcForm" class="space-y-3" novalidate>
                    <input type="hidden" name="project_id"   value="<?php echo $id; ?>">
                    <input type="hidden" name="redirect_to"  value="modules/projects/view?id=<?php echo $id; ?>">
                    <input type="hidden" name="voice_note_sent" id="pcVoiceNoteFlag" value="0">
                    <input type="hidden" name="reply_to_id"  id="pcReplyToId" value="">
                    <input type="hidden" name="tagged_users" id="pcTaggedUsers" value="">
                    <input type="file"   id="pcFileInput" name="comment_files[]" multiple
                           accept=".jpg,.jpeg,.png,.gif,.webp,.pdf,.doc,.docx,.webm,.ogg,.opus,.mp3,.wav,.m4a,.aac,audio/*" style="display:none">

                    <div class="relative">
                        <label class="pc-form-label" for="pcTextarea">Message</label>
                        <div class="pc-mention-list absolute bottom-full left-0 right-0 bg-white max-h-36 overflow-y-auto z-10 hidden mb-1"></div>
                        <textarea name="comment" id="pcTextarea" rows="4"
                                  class="pc-form-textarea"
                                  placeholder="Share an update… Type @ to mention a teammate"></textarea>
                    </div>

                    <div>
                        <label class="pc-form-label" for="pcDocRef">Document reference <span class="normal-case tracking-normal font-semibold text-slate-400">(optional)</span></label>
                        <input type="text" name="document_ref" id="pcDocRef"
                               class="pc-form-input"
                               placeholder="e.g. Policy-DR-2026-v3">
                    </div>

                    <!-- Attach strip -->
                    <div id="pcAttachStrip" class="pc-attach-strip flex flex-wrap gap-2 mb-1" style="display:none!important;"></div>
                    <p class="text-xs text-slate-500 mb-1 min-h-[1rem]" id="pcVoiceHint" aria-live="polite"></p>

                    <div class="pc-form-actions">
                        <button type="button" onclick="document.getElementById('pcFileInput').click()"
                                class="pc-attach-trigger" title="Attach file" aria-label="Attach file">
                            <i data-lucide="paperclip" aria-hidden="true"></i>
                        </button>
                        <button type="button" id="pcVoiceMicBtn" class="pc-attach-trigger press-voice-btn" title="Voice note" aria-label="Record voice note">
                            <i data-lucide="mic" aria-hidden="true"></i>
                        </button>
                        <button type="submit" class="pc-submit-btn">
                            <i data-lucide="send" class="text-base" aria-hidden="true"></i> Post comment
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </section>
        </div>
        <?php if (!empty($projectWorkspaceShowGroupChat)): ?>
            </div>
        </details>
        <?php endif; ?>
    </div>

                    </div>

                        <aside class="pjs-aside">
                            <div class="pjs-rail-card">
                                <p class="pjs-rail-title">Project snapshot</p>
                                <div class="pjs-snapshot-grid">
                                    <?php foreach ($project_rail_metrics as $rm): ?>
                                    <div class="pjs-snap-tile">
                                        <div class="pjs-snap-val" style="color: <?php echo htmlspecialchars($rm['tone']); ?>;"><?php echo htmlspecialchars($rm['value']); ?></div>
                                        <?php if ($rm['sub'] !== null && $rm['sub'] !== ''): ?><div class="pjs-snap-sub"><?php echo htmlspecialchars($rm['sub']); ?></div><?php endif; ?>
                                        <div class="pjs-snap-lbl"><?php echo htmlspecialchars($rm['label']); ?></div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>

                            <div class="pjs-rail-card">
                                <p class="pjs-rail-title">Project manager</p>
                                <div class="pjs-pm-card">
                                    <?php if ($created_by_photo_url): ?>
                                    <img class="pjs-pm-avatar" src="<?php echo htmlspecialchars($created_by_photo_url); ?>" alt="">
                                    <?php else: ?>
                                    <span class="pjs-pm-fallback"><?php echo htmlspecialchars($project_lead_initials((string) $project['created_by_name'])); ?></span>
                                    <?php endif; ?>
                                    <div class="min-w-0 flex-1">
                                        <div class="pjs-pm-name"><?php echo htmlspecialchars($project['created_by_name']); ?></div>
                                        <?php if (!empty($project['created_by_email'])): ?>
                                        <div class="pjs-pm-email"><?php echo htmlspecialchars($project['created_by_email']); ?></div>
                                        <?php endif; ?>
                                    </div>
                                    <?php
                                    $pmShowContact = !$isProjectManagerViewer && $pmPeerId > 0;
                                    $pmHasAnyChannel = $pmShowContact && (
                                        $pmMessagingHref !== '' || $pmWaHref !== '' || $pmTelHref !== '' || $pmMailtoHref !== ''
                                    );
                                    ?>
                                    <?php if ($pmShowContact): ?>
                                        <?php if ($pmHasAnyChannel): ?>
                                    <div class="pjs-contact-icon-row" aria-label="Contact project manager">
                                        <?php if ($pmMessagingHref !== ''): ?>
                                        <a href="<?php echo htmlspecialchars($pmMessagingHref); ?>" class="pjs-contact-icon-btn" title="Message <?php echo htmlspecialchars($project['created_by_name']); ?>" aria-label="Open conversation with <?php echo htmlspecialchars($project['created_by_name']); ?>"><i data-lucide="message-circle" aria-hidden="true"></i></a>
                                        <?php endif; ?>
                                        <?php if ($pmWaHref !== ''): ?>
                                        <a href="<?php echo htmlspecialchars($pmWaHref); ?>" class="pjs-contact-icon-btn" title="WhatsApp" target="_blank" rel="noopener noreferrer"><i data-lucide="messages-square" aria-hidden="true"></i></a>
                                        <?php endif; ?>
                                        <?php if ($pmTelHref !== ''): ?>
                                        <a href="<?php echo htmlspecialchars($pmTelHref); ?>" class="pjs-contact-icon-btn" title="Call"><i data-lucide="phone" aria-hidden="true"></i></a>
                                        <?php endif; ?>
                                        <?php if ($pmMailtoHref !== ''): ?>
                                        <a href="<?php echo htmlspecialchars($pmMailtoHref); ?>" class="pjs-contact-icon-btn" title="Email"><i data-lucide="mail" aria-hidden="true"></i></a>
                                        <?php endif; ?>
                                    </div>
                                        <?php else: ?>
                                    <p class="text-[0.65rem] text-slate-400 m-0 flex-shrink-0 max-w-[9rem] leading-snug">No phone or email on file for shortcuts.</p>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="pjs-rail-card pjs-rail-card--team">
                                <p class="pjs-rail-title">Stakeholders (<?php echo count($projectWorkspaceParticipants); ?>)</p>
                                <div class="pjs-team-ava mb-2">
                                    <?php foreach (array_slice($projectWorkspaceParticipants, 0, 5) as $sp):
                                        $spu = $user_photo_url($sp['photo'] ?? null); ?>
                                        <?php if ($spu): ?><img src="<?php echo htmlspecialchars($spu); ?>" alt="" width="28" height="28"><?php else: ?><span><?php echo htmlspecialchars($project_lead_initials((string) ($sp['name'] ?? '?'))); ?></span><?php endif; ?>
                                    <?php endforeach; ?>
                                </div>
                                <?php $sx = count($projectWorkspaceParticipants) - 5; ?>
                                <?php if ($sx > 0): ?><p class="text-xs font-bold text-slate-500 m-0">+<?php echo (int) $sx; ?> more</p><?php endif; ?>
                                <?php if (empty($projectWorkspaceParticipants)): ?>
                                    <p class="text-xs text-slate-500 m-0">Participants appear when tasks have assignees.</p>
                                    <?php if (!$isProjectManagerViewer && $canInviteProjectTeam): ?>
                                    <p class="m-0 mt-2">
                                        <a href="<?php echo htmlspecialchars(BASE_URL . 'modules/projects/view?id=' . (int) $id . '&open_team=1'); ?>" class="text-xs font-bold text-teal-700 hover:underline inline-flex items-center gap-1">
                                            <i data-lucide="user-plus" class="w-3.5 h-3.5 shrink-0" aria-hidden="true"></i>
                                            Add collaborator
                                        </a>
                                    </p>
                                    <?php endif; ?>
                                <?php endif; ?>
                                <?php if (!$isProjectManagerViewer && $stakeholderContactRows !== []): ?>
                                <div class="pjs-stakeholder-contact-list" aria-label="Contact stakeholders">
                                    <?php foreach ($stakeholderContactRows as $srow): ?>
                                        <?php
                                        $shAny = ($srow['msg_href'] !== '' || $srow['wa_href'] !== '' || $srow['tel_href'] !== '' || $srow['mailto_href'] !== '');
                                        ?>
                                        <div class="pjs-stakeholder-contact-row">
                                            <span class="pjs-stakeholder-contact-name"><?php echo htmlspecialchars($srow['name']); ?></span>
                                            <?php if ($shAny): ?>
                                            <div class="pjs-contact-icon-row">
                                                <?php if ($srow['msg_href'] !== ''): ?>
                                                <a href="<?php echo htmlspecialchars($srow['msg_href']); ?>" class="pjs-contact-icon-btn" title="Message <?php echo htmlspecialchars($srow['name']); ?>" aria-label="Open conversation with <?php echo htmlspecialchars($srow['name']); ?>"><i data-lucide="message-circle" aria-hidden="true"></i></a>
                                                <?php endif; ?>
                                                <?php if ($srow['wa_href'] !== ''): ?>
                                                <a href="<?php echo htmlspecialchars($srow['wa_href']); ?>" class="pjs-contact-icon-btn" title="WhatsApp" target="_blank" rel="noopener noreferrer"><i data-lucide="messages-square" aria-hidden="true"></i></a>
                                                <?php endif; ?>
                                                <?php if ($srow['tel_href'] !== ''): ?>
                                                <a href="<?php echo htmlspecialchars($srow['tel_href']); ?>" class="pjs-contact-icon-btn" title="Call"><i data-lucide="phone" aria-hidden="true"></i></a>
                                                <?php endif; ?>
                                                <?php if ($srow['mailto_href'] !== ''): ?>
                                                <a href="<?php echo htmlspecialchars($srow['mailto_href']); ?>" class="pjs-contact-icon-btn" title="Email"><i data-lucide="mail" aria-hidden="true"></i></a>
                                                <?php endif; ?>
                                            </div>
                                            <?php else: ?>
                                            <p class="pjs-stakeholder-contact-note">No phone or email on file.</p>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                <?php endif; ?>
                            </div>

                            <div class="pjs-rail-card">
                                <p class="pjs-rail-title">Project tags</p>
                                <div class="pjs-tags">
                                    <span class="pjs-tag"><?php echo htmlspecialchars($project['status']); ?></span>
                                    <span class="pjs-tag"><?php echo htmlspecialchars($project['priority']); ?> priority</span>
                                    <?php foreach ($requirement_badges as $rb): ?>
                                    <span class="pjs-tag"><?php echo htmlspecialchars($rb['label']); ?></span>
                                    <?php endforeach; ?>
                                    <?php if (!empty($project['project_type'] ?? null)): ?><span class="pjs-tag"><?php echo htmlspecialchars((string) $project['project_type']); ?></span><?php endif; ?>
                                </div>
                            </div>

                            <div class="pjs-rail-card">
                                <p class="pjs-rail-title">Quick actions</p>
                                <div class="pjs-quick-grid">
                                    <a class="pjs-quick-btn" href="../tasks/create?project_id=<?php echo (int) $project['id']; ?>"><i data-lucide="list-plus" aria-hidden="true"></i>New task</a>
                                    <a class="pjs-quick-btn" href="#project-documentation-hub"><i data-lucide="upload" aria-hidden="true"></i>Upload file</a>
                                    <a class="pjs-quick-btn" href="../collaboration/invitations.php"><i data-lucide="mail" aria-hidden="true"></i>Team invitations</a>
                                    <a class="pjs-quick-btn" href="#pv-activity"><i data-lucide="sticky-note" aria-hidden="true"></i>Add note</a>
                                </div>
                            </div>

                            <?php
                            if (!empty($projectWorkspaceShowGroupChat)) {
                                $workspaceGroupChatShow = true;
                                $workspaceGroupChatTitle = $projectWorkspaceGroupChatTitle;
                                $workspaceGroupChatFeedId = 'workspace-project-group-chat-feed';
                                $workspaceGroupChatFormAction = 'comment';
                                $workspaceGroupChatFormMethod = 'POST';
                                $workspaceGroupChatFormEnctype = 'multipart/form-data';
                                $workspaceGroupChatHiddenFields = [
                                    'project_id' => (int) $id,
                                    'redirect_to' => 'modules/projects/view?id=' . (int) $id,
                                ];
                                $workspaceGroupChatParticipants = $projectWorkspaceParticipants;
                                $workspaceGroupChatMessages = $projectChatMessages;
                                $workspaceGroupChatCurrentUserId = (int) ($_SESSION['user_id'] ?? 0);
                                include __DIR__ . '/../../includes/partials/workspace_group_chat_sidebar.php';
                            }
                            ?>
                        </aside>
                    </div>

                    <div class="pv-modal" id="pv-modal-risks" hidden role="dialog" aria-modal="true" aria-labelledby="pv-modal-risks-title">
                        <div class="pv-modal__backdrop" data-pv-modal-close tabindex="-1" aria-hidden="true"></div>
                        <div class="pv-modal__sheet">
                            <div class="flex flex-wrap items-start justify-between gap-3 mb-4 pb-3 border-b border-slate-100">
                                <div class="premium-card-title !mb-0">
                                    <i data-lucide="triangle-alert" class="text-amber-600" aria-hidden="true"></i>
                                    <span id="pv-modal-risks-title">Risks</span>
                                </div>
                                <button type="button" class="premium-btn premium-btn-secondary text-sm shrink-0" style="padding:0.4rem 0.75rem" data-pv-modal-close>
                                    <i data-lucide="x" class="text-base" aria-hidden="true"></i>
                                    Close
                                </button>
                            </div>
        <?php if ($canManageProjectPm): ?>
        <form method="post" action="risk_save" class="mb-6 p-4 bg-white/80 rounded-xl border border-amber-100 grid grid-cols-1 md:grid-cols-2 gap-3">
            <input type="hidden" name="project_id" value="<?php echo (int) $id; ?>">
            <input type="hidden" name="risk_action" value="create">
            <div class="md:col-span-2">
                <label class="block text-xs font-bold text-slate-500 uppercase mb-1">Title</label>
                <input type="text" name="title" required class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm" placeholder="Risk title">
            </div>
            <div class="md:col-span-2">
                <label class="block text-xs font-bold text-slate-500 uppercase mb-1">Description</label>
                <textarea name="description" rows="2" class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm"></textarea>
            </div>
            <div class="md:col-span-2">
                <label class="block text-xs font-bold text-slate-500 uppercase mb-1">Mitigation</label>
                <textarea name="mitigation" rows="2" class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm"></textarea>
            </div>
            <div>
                <label class="block text-xs font-bold text-slate-500 uppercase mb-1">Status</label>
                <select name="status" class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm">
                    <?php foreach (['Open', 'Mitigating', 'Resolved', 'Accepted', 'Closed'] as $st): ?>
                    <option value="<?php echo $st; ?>" <?php echo $st === 'Open' ? 'selected' : ''; ?>><?php echo $st; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block text-xs font-bold text-slate-500 uppercase mb-1">Related task</label>
                <select name="task_id" class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm">
                    <option value="0">— None —</option>
                    <?php foreach ($tasks as $tk): ?>
                    <option value="<?php echo (int) $tk['id']; ?>"><?php echo htmlspecialchars((string) $tk['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="md:col-span-2">
                <button type="submit" class="premium-btn premium-btn-primary text-sm" style="padding:0.45rem 1rem">Add risk</button>
            </div>
        </form>
        <?php endif; ?>
        <?php if (empty($projectRisks)): ?>
        <p class="text-sm text-slate-600 m-0">No risks logged. <?php if ($task_stats['overdue'] > 0): ?>You have <strong><?php echo (int) $task_stats['overdue']; ?> overdue task(s)</strong> — review the task board.<?php endif; ?></p>
        <?php else: ?>
        <div class="overflow-x-auto rounded-xl border border-amber-100/80 bg-white/90">
            <table class="min-w-full text-sm">
                <thead class="text-left text-xs uppercase text-slate-500 border-b border-slate-100">
                    <tr>
                        <th class="p-3">Title</th>
                        <th class="p-3">Status</th>
                        <th class="p-3">Task</th>
                        <?php if ($canManageProjectPm): ?>
                        <th class="p-3 w-32">Actions</th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($projectRisks as $rk):
                        $rid = (int) ($rk['id'] ?? 0);
                        ?>
                    <tr class="border-b border-slate-50 align-top">
                        <td class="p-3">
                            <div class="font-bold text-slate-800"><?php echo htmlspecialchars((string) ($rk['title'] ?? '')); ?></div>
                            <?php if (!empty($rk['description'])): ?>
                            <div class="text-slate-600 text-xs mt-1"><?php echo nl2br(htmlspecialchars((string) $rk['description'])); ?></div>
                            <?php endif; ?>
                            <?php if (!empty($rk['mitigation'])): ?>
                            <div class="text-xs text-slate-500 mt-1"><span class="font-bold text-amber-800">Mitigation:</span> <?php echo nl2br(htmlspecialchars((string) $rk['mitigation'])); ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="p-3 whitespace-nowrap"><span class="px-2 py-0.5 rounded-full bg-amber-100 text-amber-900 text-xs font-bold"><?php echo htmlspecialchars((string) ($rk['status'] ?? '')); ?></span></td>
                        <td class="p-3 text-xs text-slate-600"><?php echo htmlspecialchars((string) ($rk['linked_task_name'] ?? '—')); ?></td>
                        <?php if ($canManageProjectPm): ?>
                        <td class="p-3">
                            <details class="text-xs">
                                <summary class="cursor-pointer text-emerald-700 font-bold">Edit</summary>
                                <form method="post" action="risk_save" class="mt-2 space-y-2 min-w-[220px]">
                                    <input type="hidden" name="project_id" value="<?php echo (int) $id; ?>">
                                    <input type="hidden" name="risk_action" value="update">
                                    <input type="hidden" name="id" value="<?php echo $rid; ?>">
                                    <input type="text" name="title" required class="w-full px-2 py-1 border rounded" value="<?php echo htmlspecialchars((string) ($rk['title'] ?? '')); ?>">
                                    <textarea name="description" rows="2" class="w-full px-2 py-1 border rounded"><?php echo htmlspecialchars((string) ($rk['description'] ?? '')); ?></textarea>
                                    <textarea name="mitigation" rows="2" class="w-full px-2 py-1 border rounded"><?php echo htmlspecialchars((string) ($rk['mitigation'] ?? '')); ?></textarea>
                                    <textarea name="solution_applied" rows="2" class="w-full px-2 py-1 border rounded" placeholder="Solution applied"><?php echo htmlspecialchars((string) ($rk['solution_applied'] ?? '')); ?></textarea>
                                    <select name="status" class="w-full px-2 py-1 border rounded">
                                        <?php foreach (['Open', 'Mitigating', 'Resolved', 'Accepted', 'Closed'] as $st): ?>
                                        <option value="<?php echo $st; ?>" <?php echo ($rk['status'] ?? '') === $st ? 'selected' : ''; ?>><?php echo $st; ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <select name="task_id" class="w-full px-2 py-1 border rounded">
                                        <option value="0">— None —</option>
                                        <?php foreach ($tasks as $tk): ?>
                                        <option value="<?php echo (int) $tk['id']; ?>" <?php echo (int) ($rk['task_id'] ?? 0) === (int) $tk['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars((string) $tk['name']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button type="submit" class="w-full premium-btn premium-btn-primary text-xs" style="padding:0.35rem">Save</button>
                                </form>
                                <form method="post" action="risk_save" class="mt-2" onsubmit="return confirm('Delete this risk?');">
                                    <input type="hidden" name="project_id" value="<?php echo (int) $id; ?>">
                                    <input type="hidden" name="risk_action" value="delete">
                                    <input type="hidden" name="id" value="<?php echo $rid; ?>">
                                    <button type="submit" class="text-xs text-rose-600 font-bold">Delete</button>
                                </form>
                            </details>
                        </td>
                        <?php endif; ?>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
                        </div>
                    </div>

                    <div class="pv-modal" id="pv-modal-team" hidden role="dialog" aria-modal="true" aria-labelledby="pv-modal-team-title">
                        <div class="pv-modal__backdrop" data-pv-modal-close tabindex="-1" aria-hidden="true"></div>
                        <div class="pv-modal__sheet">
                            <div class="flex flex-wrap items-start justify-between gap-3 mb-4 pb-3 border-b border-slate-100">
                                <div class="premium-card-title !mb-0">
                                    <i data-lucide="users" class="text-emerald-600" aria-hidden="true"></i>
                                    <span id="pv-modal-team-title">Team &amp; stakeholders</span>
                                </div>
                                <button type="button" class="premium-btn premium-btn-secondary text-sm shrink-0" style="padding:0.4rem 0.75rem" data-pv-modal-close>
                                    <i data-lucide="x" class="text-base" aria-hidden="true"></i>
                                    Close
                                </button>
                            </div>
                            <p class="text-sm text-slate-500 m-0 mb-4">Delivery participants pulled from task assignees and project context.</p>
                            <?php if (empty($projectWorkspaceParticipants)): ?>
                            <p class="text-sm text-slate-600 m-0">No participants yet. Assign tasks to teammates to populate this list.</p>
                            <?php else: ?>
                            <ul class="space-y-3 list-none m-0 p-0">
                                <?php foreach ($projectWorkspaceParticipants as $tp):
                                    $tpu = $user_photo_url($tp['photo'] ?? null);
                                    $tname = (string) ($tp['name'] ?? 'Member');
                                    ?>
                                <li class="flex items-center gap-3 rounded-xl border border-slate-200 bg-slate-50/80 p-3">
                                    <?php if ($tpu): ?>
                                    <img src="<?php echo htmlspecialchars($tpu); ?>" alt="" class="w-11 h-11 rounded-full object-cover border-2 border-white shadow-sm flex-shrink-0" width="44" height="44">
                                    <?php else: ?>
                                    <span class="w-11 h-11 rounded-full flex items-center justify-center text-xs font-extrabold text-white flex-shrink-0 bg-gradient-to-br from-[#0f766e] to-[#14b8a6]"><?php echo htmlspecialchars($project_lead_initials($tname)); ?></span>
                                    <?php endif; ?>
                                    <div class="min-w-0 flex-1">
                                        <p class="font-extrabold text-slate-900 m-0 truncate"><?php echo htmlspecialchars($tname); ?></p>
                                        <?php if (!empty($tp['email'])): ?>
                                        <p class="text-xs text-slate-500 m-0 truncate"><?php echo htmlspecialchars((string) $tp['email']); ?></p>
                                        <?php endif; ?>
                                    </div>
                                </li>
                                <?php endforeach; ?>
                            </ul>
                            <?php endif; ?>

                            <?php if ($canInviteProjectTeam): ?>
                            <div class="collab-invite-panel">
                                <div class="collab-invite-panel__head">
                                    <i data-lucide="user-plus" aria-hidden="true"></i>
                                    <p class="collab-invite-panel__title">Invite to project team</p>
                                </div>
                                <p class="collab-invite-panel__intro">Search for a colleague and send an invitation. They must accept before they appear in the team list.</p>
                                <form method="post" action="team_invite_save" id="pvTeamInviteForm" class="space-y-3">
                                    <input type="hidden" name="project_id" value="<?php echo (int) $id; ?>">
                                    <input type="hidden" name="invitee_user_id" id="pvTeamInviteUserId" value="">
                                    <div class="relative">
                                        <label for="pvTeamInviteSearch" class="collab-invite-field-label">Team member</label>
                                        <input type="text" id="pvTeamInviteSearch" autocomplete="off" class="collab-invite-field" placeholder="Search by name or email">
                                        <div id="pvTeamInviteResults" class="hidden absolute z-50 left-0 right-0 mt-1 max-h-52 overflow-y-auto rounded-xl border border-slate-200 bg-white shadow-lg"></div>
                                    </div>
                                    <div>
                                        <label for="pvTeamInviteNote" class="collab-invite-field-label">Optional note</label>
                                        <textarea id="pvTeamInviteNote" name="note" rows="2" class="collab-invite-field" placeholder="Why you&rsquo;re inviting them—deadline, role, context"></textarea>
                                    </div>
                                    <button type="submit" class="premium-btn premium-btn-primary text-sm w-full sm:w-auto justify-center" style="padding:0.45rem 1rem" id="pvTeamInviteSubmit" disabled>
                                        <i data-lucide="send" class="w-4 h-4" aria-hidden="true"></i>
                                        Send invitation
                                    </button>
                                </form>
                            </div>
                            <?php elseif (!$teamInvitationsReady && $canManageProjectPm): ?>
                            <p class="text-xs text-amber-800 mt-4 m-0">Team invitations need the database tables from <code class="text-[11px]">sql/team_invitations_migration.sql</code>.</p>
                            <?php endif; ?>
                        </div>
                    </div>

            </div>
        </div>
    </div>

<script>
if (typeof window.refreshAppShellIcons === 'function') {
    window.refreshAppShellIcons();
}
(function(){
    var btn = document.getElementById('pjs-favorite-btn');
    if (btn) {
        var storageKey = 'pjs_fav_<?php echo (int) $id; ?>';
        var iconHost = document.getElementById('pjs-fav-icon');
        function syncFav() {
            var on = !!localStorage.getItem(storageKey);
            btn.setAttribute('aria-pressed', on ? 'true' : 'false');
            if (iconHost) {
                iconHost.setAttribute('data-lucide', on ? 'star' : 'star-off');
                iconHost.innerHTML = '';
                if (typeof window.refreshAppShellIcons === 'function') {
                    window.refreshAppShellIcons();
                }
            }
        }
        syncFav();
        btn.addEventListener('click', function () {
            if (localStorage.getItem(storageKey)) {
                localStorage.removeItem(storageKey);
            } else {
                localStorage.setItem(storageKey, '1');
            }
            syncFav();
        });
    }
    var pvShell = document.getElementById('pv-overview');
    if (pvShell) {
        var pvTabMap = {
            '#pv-overview': 'overview',
            '#pv-milestones': 'milestones',
            '#pv-tasks': 'tasks',
            '#project-documentation-hub': 'files',
            '#pv-budget': 'budget',
            '#pv-activity': 'activity'
        };
        /** Drop inline layout overrides so the shared tab shell controls visibility and sizing. */
        function pvClearPanelInlineStyles(col) {
            if (!col) {
                return;
            }
            var props = ['display', 'flex-direction', 'align-items', 'gap', 'width', 'min-width'];
            var i;
            var j;
            var el;
            for (i = 0; i < col.children.length; i++) {
                el = col.children[i];
                if (!el.classList || !el.classList.contains('pv-tab-panel')) {
                    continue;
                }
                for (j = 0; j < props.length; j++) {
                    el.style.removeProperty(props[j]);
                }
            }
        }
        function pvStripActiveStates(el) {
            el.className = el.className.replace(/\bpv-active-\w+\b/g, function () { return ''; }).replace(/\s+/g, ' ').trim();
        }
        function pvActivateFromHash() {
            var h = (window.location.hash || '#pv-overview').split('?')[0];
            if (!pvTabMap[h]) {
                h = '#pv-overview';
            }
            var tabKey = pvTabMap[h] || 'overview';
            pvStripActiveStates(pvShell);
            pvShell.classList.add('pv-active-' + tabKey);
            pvShell.setAttribute('data-pv-tab', tabKey);
            pvClearPanelInlineStyles(pvShell.querySelector('.pjs-main-column'));
            var panels = pvShell.querySelectorAll('.pjs-main-column > .pv-tab-panel');
            panels.forEach(function (panel) {
                panel.classList.toggle('is-active', panel.getAttribute('data-pv-panel') === tabKey);
            });
            var tabLinks = document.querySelectorAll('nav.pjs-tabs .pjs-tab');
            tabLinks.forEach(function (link) {
                var href = link.getAttribute('href') || '';
                link.classList.toggle('pjs-tab--active', href === h);
            });
            requestAnimationFrame(function () {
                var activePanel = pvShell.querySelector('.pjs-main-column > .pv-tab-panel.is-active');
                if (activePanel && typeof activePanel.scrollIntoView === 'function') {
                    activePanel.scrollIntoView({ block: 'nearest', inline: 'nearest' });
                }
                if (window.PressErpDateTimePicker && typeof window.PressErpDateTimePicker.refreshProjectViewTabPickers === 'function') {
                    window.PressErpDateTimePicker.refreshProjectViewTabPickers();
                }
            });
        }
        pvShell.addEventListener('click', function (e) {
            var tgt = e.target;
            var t = null;
            if (tgt && typeof tgt.closest === 'function') {
                t = tgt.closest('a.pjs-tab');
            } else if (tgt) {
                var node = tgt.nodeType === 3 ? tgt.parentElement : tgt;
                while (node && node !== pvShell) {
                    if (node.tagName === 'A' && node.classList && node.classList.contains('pjs-tab')) {
                        t = node;
                        break;
                    }
                    node = node.parentElement;
                }
            }
            if (!t || !pvShell.contains(t)) {
                return;
            }
            var href = t.getAttribute('href') || '';
            if (href.charAt(0) !== '#') {
                return;
            }
            e.preventDefault();
            if (window.history && window.history.replaceState) {
                window.history.replaceState(null, '', window.location.pathname + window.location.search + href);
            } else {
                window.location.hash = href;
            }
            pvActivateFromHash();
        });
        window.addEventListener('hashchange', pvActivateFromHash);
        pvActivateFromHash();
    }
    (function bindPvOverviewModals() {
        var risksModal = document.getElementById('pv-modal-risks');
        var teamModal = document.getElementById('pv-modal-team');
        var openRisks = document.getElementById('pv-open-risks-modal');
        var openTeam = document.getElementById('pv-open-team-modal');
        function refreshIcons() {
            if (typeof window.refreshAppShellIcons === 'function') {
                window.refreshAppShellIcons();
            }
        }
        function closePvModals() {
            if (risksModal) {
                risksModal.setAttribute('hidden', '');
            }
            if (teamModal) {
                teamModal.setAttribute('hidden', '');
            }
            document.body.style.overflow = '';
        }
        function openPvModal(modal) {
            if (!modal) {
                return;
            }
            closePvModals();
            modal.removeAttribute('hidden');
            document.body.style.overflow = 'hidden';
            refreshIcons();
        }
        if (openRisks && risksModal) {
            openRisks.addEventListener('click', function () {
                openPvModal(risksModal);
            });
        }
        if (openTeam && teamModal) {
            openTeam.addEventListener('click', function () {
                openPvModal(teamModal);
            });
        }
        try {
            var qsTeam = new URLSearchParams(window.location.search || '');
            if (teamModal && qsTeam.get('open_team') === '1') {
                openPvModal(teamModal);
            }
        } catch (e) {}
        [risksModal, teamModal].forEach(function (modal) {
            if (!modal) {
                return;
            }
            modal.querySelectorAll('[data-pv-modal-close]').forEach(function (el) {
                el.addEventListener('click', function (e) {
                    e.preventDefault();
                    closePvModals();
                });
            });
        });
        document.addEventListener('keydown', function (e) {
            if (e.key !== 'Escape') {
                return;
            }
            var risksOpen = risksModal && !risksModal.hasAttribute('hidden');
            var teamOpen = teamModal && !teamModal.hasAttribute('hidden');
            if (risksOpen || teamOpen) {
                closePvModals();
            }
        });
    })();
})();
(function(){
    var users = <?php echo $ti_invite_users_json; ?>;
    var curUserId = <?php echo (int) $currentUserId; ?>;
    var search = document.getElementById('pvTeamInviteSearch');
    var results = document.getElementById('pvTeamInviteResults');
    var hidden = document.getElementById('pvTeamInviteUserId');
    var submit = document.getElementById('pvTeamInviteSubmit');
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
            return '<button type="button" class="pv-ti-pick w-full text-left px-3 py-2.5 text-sm hover:bg-emerald-50 border-b border-slate-100 last:border-0" data-user-id="' + Number(u.id) + '" data-label="' +
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
        var b = e.target.closest('.pv-ti-pick');
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
(function(){
    // ── Scroll feed to bottom ──────────────────────────────────
    var feed = document.querySelector('#project-comments .project-comment-feed');
    if (feed) feed.scrollTop = feed.scrollHeight;

    // ── Reply state ────────────────────────────────────────────
    window.pcSetReply = function(data) {
        document.getElementById('pcReplyToId').value = data.id;
        document.getElementById('pcReplyBarSender').textContent = data.sender || '';
        var snippet = (data.body || '').replace(/\s+/g, ' ').trim();
        if (snippet.length > 220) {
            snippet = snippet.substring(0, 220) + '…';
        }
        if (!snippet) {
            snippet = '(No message text)';
        }
        document.getElementById('pcReplyBarPreview').textContent = snippet;
        document.getElementById('pcReplyBar').removeAttribute('hidden');
        document.getElementById('pcTextarea').focus();
    };
    window.pcCancelReply = function() {
        document.getElementById('pcReplyToId').value = '';
        document.getElementById('pcReplyBar').setAttribute('hidden', '');
    };

    // ── File attach strip ──────────────────────────────────────
    var pcFileInput  = document.getElementById('pcFileInput');
    var pcStrip      = document.getElementById('pcAttachStrip');
    var pcSelectedFiles = [];
    var pcTA         = document.getElementById('pcTextarea');

    pcFileInput.addEventListener('change', function(){
        document.getElementById('pcVoiceNoteFlag').value = '0';
        pcSelectedFiles = Array.from(this.files);
        renderPcStrip();
    });

    document.addEventListener('DOMContentLoaded', function(){
    var pcFormEl = document.getElementById('pcForm');
    if (pcFormEl && pcTA) {
        pcFormEl.addEventListener('submit', function(e){
            var msg = String(pcTA.value||'').trim();
            if (!msg && !pcSelectedFiles.length) {
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
    }

    if (window.PressVoiceNote) {
        window.PressVoiceNote.bindToggle({
            button: '#pcVoiceMicBtn',
            fileInput: '#pcFileInput',
            hiddenVoiceInput: '#pcVoiceNoteFlag',
            statusEl: '#pcVoiceHint',
            maxSeconds: 180,
            onFile: function(file){
                pcSelectedFiles.push(file);
                rebuildPcInput();
                renderPcStrip();
            }
        });
    }
    });
    function renderPcStrip(){
        if (!pcSelectedFiles.length){ pcStrip.style.cssText='display:none!important'; return; }
        pcStrip.style.cssText='display:flex!important;flex-wrap:wrap;gap:.4rem;';
        pcStrip.innerHTML = '';
        pcSelectedFiles.forEach(function(f,i){
            var chip = document.createElement('div');
            chip.className = 'flex items-center gap-1 bg-emerald-50 text-emerald-700 text-xs rounded-lg px-2 py-1';
            chip.innerHTML = '<i data-lucide="paperclip" style="width:.85rem;height:.85rem;flex-shrink:0" aria-hidden="true"></i>'
                           + '<span class="truncate max-w-[100px]">'+escHtml(f.name)+'</span>'
                           + '<span class="cursor-pointer ml-1 text-emerald-500 hover:text-red-500" data-i="'+i+'"><i data-lucide="x" style="width:.8rem;height:.8rem;flex-shrink:0" aria-hidden="true"></i></span>';
            pcStrip.appendChild(chip);
        });
        pcStrip.querySelectorAll('[data-i]').forEach(function(btn){
            btn.addEventListener('click', function(){
                pcSelectedFiles.splice(parseInt(this.dataset.i,10),1);
                rebuildPcInput(); renderPcStrip();
            });
        });
        if (typeof window.refreshAppShellIcons === 'function') {
            window.refreshAppShellIcons();
        }
    }
    function rebuildPcInput(){ var dt=new DataTransfer(); pcSelectedFiles.forEach(function(f){dt.items.add(f);}); pcFileInput.files=dt.files; }

    // ── @mention ───────────────────────────────────────────────
    var ALL_USERS = <?php echo $pc_users_json; ?>;
    var pcMention = document.querySelector('.pc-mention-list');
    var pcTagged  = document.getElementById('pcTaggedUsers');
    var pcTagIds  = [];

    pcTA.addEventListener('input', function(){
        var val=this.value, cur=this.selectionStart;
        var before=val.substring(0,cur);
        var m=before.match(/@([A-Za-z0-9 ]*)$/);
        if(!m){ pcMention.classList.add('hidden'); return; }
        var q=m[1].toLowerCase();
        var filtered=ALL_USERS.filter(function(u){ return u.name.toLowerCase().includes(q)&&!pcTagIds.includes(u.id); }).slice(0,6);
        if(!filtered.length){ pcMention.classList.add('hidden'); return; }
        pcMention.innerHTML=filtered.map(function(u){
            var avatarHtml = u.photo
                ? '<img src="'+escHtml(u.photo)+'" alt="" style="width:24px;height:24px;border-radius:50%;object-fit:cover;flex-shrink:0;">'
                : '<div style="width:24px;height:24px;border-radius:50%;background:linear-gradient(135deg,#0f766e,#14b8a6);color:#fff;display:flex;align-items:center;justify-content:center;font-size:.6rem;font-weight:700;flex-shrink:0;">'+escHtml(u.name.substring(0,2).toUpperCase())+'</div>';
            return '<div class="flex items-center gap-2 px-3 py-1.5 cursor-pointer hover:bg-emerald-50 text-sm" data-id="'+u.id+'" data-name="'+escHtml(u.name)+'">'+avatarHtml+escHtml(u.name)+'</div>';
        }).join('');
        pcMention.classList.remove('hidden');
        pcMention.querySelectorAll('[data-id]').forEach(function(item){
            item.addEventListener('mousedown', function(e){
                e.preventDefault();
                var id=parseInt(this.dataset.id,10), name=this.dataset.name;
                var nb=before.replace(/@([A-Za-z0-9 ]*)$/,'@'+name+' ');
                pcTA.value=nb+val.substring(cur);
                pcTA.setSelectionRange(nb.length,nb.length);
                if(!pcTagIds.includes(id)) pcTagIds.push(id);
                pcTagged.value=JSON.stringify(pcTagIds);
                pcMention.classList.add('hidden');
                pcTA.focus();
            });
        });
    });
    pcTA.addEventListener('blur', function(){ setTimeout(function(){ pcMention.classList.add('hidden'); }, 150); });

    // ── Project Documentation Search ────────────────────────────
    var projectDocSearch = document.getElementById('projectDocumentationSearch');
    if (projectDocSearch) {
        var projectDocItems = document.querySelectorAll('.project-doc-item');
        var projectDocEmptyState = document.getElementById('projectDocumentationEmptyState');

        var filterProjectDocs = function() {
            var query = projectDocSearch.value.trim().toLowerCase();
            var visibleCount = 0;

            projectDocItems.forEach(function(item) {
                var searchText = item.getAttribute('data-doc-search') || '';
                var isMatch = query === '' || searchText.indexOf(query) !== -1;
                item.classList.toggle('hidden', !isMatch);
                if (isMatch) {
                    visibleCount++;
                }
            });

            if (projectDocEmptyState) {
                projectDocEmptyState.classList.toggle('hidden', visibleCount > 0);
            }
        };

        projectDocSearch.addEventListener('input', filterProjectDocs);
    }

    function escHtml(s){ return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
})();
</script>

<?php include '../../includes/footer.php'; ?>
