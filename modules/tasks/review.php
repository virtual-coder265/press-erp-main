<?php
require_once __DIR__ . '/../../config/app.php';
checkAuth();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/task_management_helper.php';
require_once __DIR__ . '/../../includes/reminder_helper.php';

function resolveReviewRedirectTarget(int $taskId, ?string $redirectTo): string
{
    $redirectTo = trim((string) $redirectTo);
    if ($redirectTo === '' || strpos($redirectTo, 'modules/') !== 0) {
        return 'modules/tasks/review?id=' . $taskId;
    }

    return $redirectTo;
}

$task_id = (int) ($_GET['id'] ?? 0);
$user_id = (int) ($_SESSION['user_id'] ?? 0);
$task = fetch_task_workflow_context($pdo, $task_id);

if (!$task) {
    redirect('modules/tasks/list?error=task_not_found');
}

if ((int) ($task['pm_id'] ?? 0) !== $user_id) {
    redirect('modules/tasks/list?error=access_denied');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $review_action = $_POST['review_action'] ?? '';
    $note = trim((string) ($_POST['note'] ?? ''));
    $score = !empty($_POST['score']) ? (int) $_POST['score'] : null;
    $redirectTo = resolveReviewRedirectTarget($task_id, $_POST['redirect_to'] ?? null);

    if (!in_array($review_action, ['approved', 'rejected', 'requested_changes'], true)) {
        redirect(append_query_params_to_path($redirectTo, ['error' => 'invalid_action']));
    }

    if ($task['status'] !== 'In Review' && !task_has_changes_since_last_decision($pdo, $task, $task['latest_review'] ?? null)) {
        redirect(append_query_params_to_path($redirectTo, [
            'error' => 'No task changes were detected since the last PM decision.',
        ]));
    }

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("INSERT INTO task_reviews (task_id, reviewer_id, action, score, note) VALUES (?,?,?,?,?)");
        $stmt->execute([$task_id, $user_id, $review_action, $score, $note]);

        if ($review_action === 'approved') {
            $newStatus = 'Completed';
            $stmt = $pdo->prepare("
                UPDATE tasks
                SET status = ?, approved_by = ?, approved_at = NOW(), score = ?, review_note = ?, completed_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$newStatus, $user_id, $score, $note, $task_id]);
        } elseif ($review_action === 'rejected') {
            $newStatus = 'Cancelled';
            $stmt = $pdo->prepare("
                UPDATE tasks
                SET status = ?, approved_by = NULL, approved_at = NULL, score = NULL, review_note = ?, completed_at = NULL
                WHERE id = ?
            ");
            $stmt->execute([$newStatus, $note, $task_id]);
        } else {
            $newStatus = 'In Progress';
            $stmt = $pdo->prepare("
                UPDATE tasks
                SET status = ?, approved_by = NULL, approved_at = NULL, score = NULL, review_note = ?, completed_at = NULL
                WHERE id = ?
            ");
            $stmt->execute([$newStatus, $note, $task_id]);
        }

        $pdo->commit();

        run_best_effort_task_side_effect(
            'Task review notification failed for task #' . $task_id,
            static function () use ($pdo, $task, $newStatus, $user_id, $review_action, $note, $score, $task_id): void {
                notify_task_workflow_event(
                    $pdo,
                    array_merge($task, ['status' => $newStatus]),
                    $user_id,
                    $review_action,
                    [
                        'old_status' => $task['status'],
                        'new_status' => $newStatus,
                        'note' => $note,
                        'score' => $score,
                        'link' => 'modules/tasks/view?id=' . $task_id,
                    ]
                );
            }
        );

        run_best_effort_task_side_effect(
            'Task reminder sync failed after review for task #' . $task_id,
            static function () use ($pdo, $task_id, $user_id): void {
                sync_task_assignment_reminders_for_task($pdo, $task_id, $user_id);
            }
        );
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        error_log('Task review action failed for task #' . $task_id . ': ' . $e->getMessage());
        redirect(append_query_params_to_path($redirectTo, [
            'error' => normalize_workflow_exception_message(
                $e->getMessage(),
                'Unable to complete the review action right now.'
            ),
        ]));
    }

    redirect(append_query_params_to_path($redirectTo, ['success' => $review_action]));
}

$task_assignees = $task['task_assignees'] ?? fetch_task_assignees($pdo, $task_id);
$task_assignee_summary = format_task_assignee_summary($task_assignees, $task['assigned_to_name'] ?? null);
$procedure_steps = fetch_task_procedure_steps($pdo, $task_id);
$general_attachments = fetch_task_attachments($pdo, $task_id, 'general');
$progress_logs = fetch_task_progress_logs($pdo, $task_id, 30);
$latest_progress_log = $progress_logs[0] ?? null;
$workflow_state = get_task_workflow_state($task, $task['latest_review'] ?? null);
$task_last_decision_at = fetch_task_last_decision_at($task, $task['latest_review'] ?? null);
$task_has_changes_since_decision = task_has_changes_since_last_decision($pdo, $task, $task['latest_review'] ?? null);
$review_decision_locked = $task['status'] !== 'In Review' && $task_last_decision_at !== null && !$task_has_changes_since_decision;
$task_last_decision_label = !empty($task['approved_at']) ? 'approved' : (($task['latest_review']['action'] ?? '') === 'rejected' ? 'rejected' : 'reviewed');
$task_last_decision_stamp = !empty($task_last_decision_at) ? date('M d, Y g:i A', strtotime($task_last_decision_at)) : null;

$commentsStmt = $pdo->prepare("
    SELECT tc.*, COALESCE(u.name, 'Deleted User') AS author_name
    FROM task_comments tc
    LEFT JOIN users u ON tc.user_id = u.id
    WHERE tc.task_id = ?
    ORDER BY tc.created_at ASC
");
$commentsStmt->execute([$task_id]);
$comments = $commentsStmt->fetchAll(PDO::FETCH_ASSOC);

$reviewsStmt = $pdo->prepare("
    SELECT tr.*, COALESCE(u.name, 'Deleted User') AS reviewer_name
    FROM task_reviews tr
    LEFT JOIN users u ON tr.reviewer_id = u.id
    WHERE tr.task_id = ?
    ORDER BY tr.created_at DESC
");
$reviewsStmt->execute([$task_id]);
$reviews = $reviewsStmt->fetchAll(PDO::FETCH_ASSOC);

$docsStmt = $pdo->prepare("
    SELECT td.*, COALESCE(u.name, 'Deleted User') AS uploader_name
    FROM task_documentation td
    LEFT JOIN users u ON td.user_id = u.id
    WHERE td.task_id = ?
    ORDER BY td.created_at DESC
");
$docsStmt->execute([$task_id]);
$docs = $docsStmt->fetchAll(PDO::FETCH_ASSOC);

$progress_attachment_count = 0;
foreach ($progress_logs as $progress_log) {
    $progress_attachment_count += (int) ($progress_log['attachment_count'] ?? 0);
}

$status_colors = [
    'Not Started' => 'bg-gray-100 text-gray-700',
    'In Progress' => 'bg-emerald-50 text-emerald-700',
    'In Review' => 'bg-yellow-100 text-yellow-800',
    'Completed' => 'bg-green-100 text-green-800',
    'Cancelled' => 'bg-red-100 text-red-800',
];
$action_labels = [
    'approved' => ['label' => 'Approved', 'class' => 'bg-green-100 text-green-800'],
    'rejected' => ['label' => 'Rejected', 'class' => 'bg-red-100 text-red-800'],
    'requested_changes' => ['label' => 'Changes Requested', 'class' => 'bg-yellow-100 text-yellow-800'],
];

$success_msg = $_GET['success'] ?? '';
$error_msg = $_GET['error'] ?? '';

include '../../includes/header.php';
?>

<?php
$procedureCount = count($procedure_steps);
$docsCount = count($docs);
$progressLogCount = count($progress_logs);
$commentsCount = count($comments);
$reviewsCount = count($reviews);
$taskIsOverdue = !empty($task['due_date'])
    && !in_array($task['status'], ['Completed', 'Cancelled'], true)
    && strtotime((string) $task['due_date']) < strtotime(date('Y-m-d'));
$reviewOverviewTiles = [
    [
        'label' => 'Evidence Pack',
        'value' => number_format($progressLogCount + $progress_attachment_count + $docsCount),
        'note' => 'Work logs, evidence files, and legacy documentation ready for review.',
    ],
    [
        'label' => 'Discussion Trail',
        'value' => number_format($commentsCount + $reviewsCount),
        'note' => 'Comments, review history, and workflow decisions in one trail.',
    ],
    [
        'label' => 'Decision State',
        'value' => $review_decision_locked ? 'Locked' : $workflow_state['label'],
        'note' => $review_decision_locked
            ? 'No new changes detected since the last PM decision.'
            : 'Decision controls are active for this review cycle.',
    ],
];
$reviewFlowSteps = [
    ['icon' => 'file-text', 'title' => '1. Context', 'copy' => 'Confirm the brief, assignees, priority, and due date before you decide.'],
    ['icon' => 'clipboard-list', 'title' => '2. Evidence', 'copy' => 'Validate the latest work log, recorded procedures, attachments, and older documentation trail.'],
    ['icon' => 'circle-check', 'title' => '3. Decision', 'copy' => 'Approve, request changes, or reject with clear reviewer notes.'],
];
?>

<style>
    .review-overview-grid {
        display: grid;
        gap: 1rem;
        margin-bottom: 1rem;
    }

    .review-overview-card {
        position: relative;
        overflow: hidden;
        border-radius: 1.5rem;
        border: 1px solid rgba(226, 232, 240, 0.92);
        background: linear-gradient(180deg, rgba(255, 255, 255, 0.99), rgba(246, 250, 248, 0.98));
        box-shadow: 0 24px 56px -42px rgba(15, 23, 42, 0.4);
        padding: 1.25rem;
    }

    .review-overview-card.is-primary {
        background: linear-gradient(135deg, #0f766e 0%, #187b74 58%, #34a38f 100%);
        border-color: rgba(15, 118, 110, 0.18);
        color: #ffffff;
    }

    .review-overview-card.is-primary::after {
        content: "";
        position: absolute;
        right: -3rem;
        bottom: -3rem;
        width: 12rem;
        height: 12rem;
        border-radius: 999px;
        background: radial-gradient(circle, rgba(255, 255, 255, 0.2), transparent 70%);
        pointer-events: none;
    }

    .review-overview-kicker {
        display: inline-flex;
        align-items: center;
        gap: 0.42rem;
        padding: 0.4rem 0.72rem;
        border-radius: 999px;
        background: rgba(24, 123, 116, 0.1);
        color: #0f766e;
        font-size: 0.72rem;
        font-weight: 700;
        letter-spacing: 0.1em;
        text-transform: uppercase;
    }

    .review-overview-card.is-primary .review-overview-kicker {
        background: rgba(255, 255, 255, 0.14);
        color: rgba(255, 255, 255, 0.9);
    }

    .review-overview-title {
        margin: 0.9rem 0 0;
        font-size: clamp(1.4rem, 2.2vw, 1.8rem);
        line-height: 1.05;
        font-weight: 800;
        letter-spacing: -0.04em;
        color: #14302d;
    }

    .review-overview-card.is-primary .review-overview-title {
        color: #ffffff;
    }

    .review-overview-subtitle {
        margin: 0.6rem 0 0;
        font-size: 0.88rem;
        line-height: 1.6;
        color: #5f6f82;
    }

    .review-overview-card.is-primary .review-overview-subtitle {
        color: rgba(255, 255, 255, 0.82);
    }

    .review-overview-chip-row {
        display: flex;
        flex-wrap: wrap;
        gap: 0.6rem;
        margin-top: 1rem;
    }

    .review-overview-chip {
        display: inline-flex;
        align-items: center;
        gap: 0.38rem;
        padding: 0.48rem 0.72rem;
        border-radius: 999px;
        background: rgba(255, 255, 255, 0.14);
        border: 1px solid rgba(255, 255, 255, 0.16);
        font-size: 0.76rem;
        font-weight: 700;
        color: #ffffff;
    }

    .review-overview-flow {
        display: grid;
        gap: 0.75rem;
        margin-top: 1rem;
    }

    .review-overview-step {
        display: grid;
        grid-template-columns: auto 1fr;
        gap: 0.8rem;
        align-items: flex-start;
        padding: 0.9rem 1rem;
        border-radius: 1.15rem;
        background: rgba(255, 255, 255, 0.12);
        border: 1px solid rgba(255, 255, 255, 0.12);
    }

    .review-overview-step i {
        width: 2.35rem;
        height: 2.35rem;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 0.9rem;
        background: rgba(255, 255, 255, 0.16);
        color: #ffffff;
        flex-shrink: 0;
    }

    .review-overview-step strong {
        display: block;
        font-size: 0.84rem;
        font-weight: 800;
        color: #ffffff;
    }

    .review-overview-step span {
        display: block;
        margin-top: 0.28rem;
        font-size: 0.76rem;
        line-height: 1.5;
        color: rgba(255, 255, 255, 0.82);
    }

    .review-overview-stat-grid {
        display: grid;
        gap: 0.8rem;
        margin-top: 1rem;
    }

    .review-overview-stat {
        padding: 0.95rem 1rem;
        border-radius: 1.15rem;
        border: 1px solid rgba(226, 232, 240, 0.92);
        background: rgba(248, 250, 252, 0.92);
    }

    .review-overview-stat span {
        display: block;
        font-size: 0.7rem;
        font-weight: 700;
        letter-spacing: 0.12em;
        text-transform: uppercase;
        color: #6c7f78;
    }

    .review-overview-stat strong {
        display: block;
        margin-top: 0.35rem;
        font-size: 1.12rem;
        font-weight: 800;
        color: #14302d;
    }

    .review-overview-stat p {
        margin: 0.35rem 0 0;
        font-size: 0.76rem;
        line-height: 1.5;
        color: #5f6f82;
    }

    .review-action-grid {
        display: grid;
        gap: 0.7rem;
        margin-top: 1rem;
    }

    .review-note-card {
        border-radius: 1rem;
        border: 1px solid rgba(245, 158, 11, 0.26);
        background: linear-gradient(180deg, rgba(255, 251, 235, 0.96), rgba(255, 247, 214, 0.92));
        padding: 0.95rem 1rem;
        font-size: 13px;
        margin-bottom: 16px;
    }

    .review-note-card-label {
        font-size: 11px;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        color: #b26b00;
        font-weight: 700;
        margin-bottom: 4px;
    }

    .review-decision-card {
        max-width: none;
        max-height: none;
        border: 1px solid rgba(15, 118, 110, 0.14);
        box-shadow: 0 28px 70px -46px rgba(15, 23, 42, 0.46);
    }

    .review-decision-card .todo-modal-header {
        align-items: flex-start;
    }

    .review-decision-copy {
        display: flex;
        flex-direction: column;
        gap: 0.3rem;
    }

    .review-decision-copy p {
        margin: 0;
        font-size: 0.8rem;
        line-height: 1.55;
        color: #5f6f82;
    }

    .review-banner {
        border-radius: 1rem;
        padding: 0.9rem 1rem;
        font-size: 13px;
        line-height: 1.55;
    }

    .review-banner.is-muted {
        border: 1px solid #e2e8f0;
        background: #f8fafc;
        color: #475569;
    }

    .review-banner.is-accent {
        border: 1px solid rgba(15, 118, 110, 0.18);
        background: rgba(236, 253, 245, 0.9);
        color: #0f5f59;
    }

    .review-score-grid {
        display: grid;
        grid-template-columns: repeat(5, minmax(0, 1fr));
        gap: 8px;
        max-width: 380px;
    }

    .review-score-option {
        position: relative;
        cursor: pointer;
    }

    .review-score-option input {
        position: absolute;
        opacity: 0;
        pointer-events: none;
    }

    .review-score-option span {
        display: block;
        border: 1px solid #dbe3ea;
        border-radius: 0.85rem;
        padding: 0.75rem 0.7rem;
        text-align: center;
        font-weight: 700;
        color: #344152;
        background: #ffffff;
        transition: border-color 0.14s ease, background 0.14s ease, color 0.14s ease, transform 0.14s ease;
    }

    .review-score-option:hover span {
        border-color: rgba(15, 118, 110, 0.24);
        background: rgba(236, 253, 245, 0.92);
        transform: translateY(-1px);
    }

    .review-score-option input:checked + span {
        border-color: rgba(15, 118, 110, 0.28);
        background: rgba(236, 253, 245, 0.96);
        color: #0f766e;
        box-shadow: 0 0 0 3px rgba(15, 118, 110, 0.1);
    }

    .review-surface-link {
        color: #0f766e;
        font-weight: 700;
        text-decoration: none;
    }

    .review-surface-link:hover {
        text-decoration: underline;
    }

    @media (min-width: 1024px) {
        .review-overview-grid {
            grid-template-columns: minmax(0, 1.45fr) minmax(320px, 0.95fr);
        }
    }

    .todo-sidebar-toggle svg.lucide,
    .todo-nav-link svg.lucide {
        width: 1.125rem;
        height: 1.125rem;
        flex-shrink: 0;
    }

    .review-overview-kicker svg.lucide,
    .review-overview-chip svg.lucide,
    .review-overview-step svg.lucide {
        width: 1rem;
        height: 1rem;
        flex-shrink: 0;
    }

    .todo-btn-primary svg.lucide,
    .todo-btn-ghost svg.lucide {
        width: 1rem;
        height: 1rem;
        flex-shrink: 0;
    }

    .todo-empty svg.lucide {
        width: 1.75rem;
        height: 1.75rem;
    }
</style>

<div class="todo-shell">
    <button type="button" class="todo-sidebar-toggle" data-ws-sidebar-toggle="wsReviewSidebar" aria-label="Toggle sidebar">
        <i data-lucide="menu" aria-hidden="true"></i>
    </button>

    <aside class="todo-sidebar" id="wsReviewSidebar">
        <div class="todo-sidebar-section-label">Review</div>
        <a href="../tasks/view?id=<?php echo $task_id; ?>" class="todo-nav-link">
            <span class="todo-nav-link-left"><i data-lucide="arrow-left" aria-hidden="true"></i><span>Back to Task</span></span>
        </a>
        <a href="../projects/view?id=<?php echo (int) $task['project_id']; ?>" class="todo-nav-link">
            <span class="todo-nav-link-left"><i data-lucide="folder-open" aria-hidden="true"></i><span>Open Project</span></span>
        </a>
        <div class="todo-sidebar-divider"></div>
        <div class="todo-sidebar-section-label">Context</div>
        <a href="#" class="todo-nav-link" data-ws-open="wsReviewProcedure">
            <span class="todo-nav-link-left"><i data-lucide="clipboard-list" aria-hidden="true"></i><span>Work Log &amp; Evidence</span></span>
            <span class="todo-nav-badge"><?php echo $progressLogCount + $docsCount; ?></span>
        </a>
        <a href="#" class="todo-nav-link" data-ws-open="wsReviewDiscussion">
            <span class="todo-nav-link-left"><i data-lucide="messages-square" aria-hidden="true"></i><span>Discussion &amp; History</span></span>
            <span class="todo-nav-badge"><?php echo $commentsCount + $reviewsCount; ?></span>
        </a>
        <div class="todo-sidebar-divider"></div>
        <a href="../tasks/list" class="todo-nav-link">
            <span class="todo-nav-link-left"><i data-lucide="list-todo" aria-hidden="true"></i><span>All Tasks</span></span>
        </a>
    </aside>

    <main class="todo-main">
        <header class="todo-header">
            <div class="todo-header-copy">
                <h1 class="todo-header-title"><?php echo htmlspecialchars($task['name']); ?></h1>
                <p class="todo-header-subtitle">
                    Review Task &middot;
                    Project:
                    <a href="../projects/view?id=<?php echo (int) $task['project_id']; ?>" class="review-surface-link">
                        <?php echo htmlspecialchars($task['project_name']); ?>
                    </a>
                </p>
            </div>
            <div class="todo-header-actions">
                <span class="px-3 py-1 text-sm font-semibold rounded-full <?php echo $status_colors[$task['status']] ?? 'bg-gray-100 text-gray-700'; ?>">
                    <?php echo htmlspecialchars($task['status']); ?>
                </span>
                <span class="px-3 py-1 text-sm font-semibold rounded-full <?php echo $workflow_state['badge_class']; ?>">
                    <?php echo htmlspecialchars($workflow_state['label']); ?>
                </span>
            </div>
        </header>

        <section class="review-overview-grid" aria-label="Task review overview dashboard">
            <div class="review-overview-card is-primary">
                <span class="review-overview-kicker">
                    <i data-lucide="award" aria-hidden="true"></i>
                    Task Overview
                </span>
                <h2 class="review-overview-title">Review desk for this task</h2>
                <p class="review-overview-subtitle">Validate the delivery context, inspect the evidence pack, and make a clear PM decision without losing your place in the task workspace.</p>
                <div class="review-overview-chip-row">
                    <span class="review-overview-chip">
                        <i data-lucide="users" aria-hidden="true"></i>
                        <?php echo htmlspecialchars($task_assignee_summary); ?>
                    </span>
                    <span class="review-overview-chip">
                        <i data-lucide="flag" aria-hidden="true"></i>
                        <?php echo htmlspecialchars($task['priority']); ?>
                    </span>
                    <span class="review-overview-chip">
                        <i data-lucide="<?php echo $taskIsOverdue ? 'triangle-alert' : 'calendar'; ?>" aria-hidden="true"></i>
                        <?php echo $task['due_date'] ? date('M d, Y', strtotime($task['due_date'])) : 'No due date'; ?>
                    </span>
                </div>
                <div class="review-overview-flow">
                    <?php foreach ($reviewFlowSteps as $step): ?>
                    <div class="review-overview-step">
                        <i data-lucide="<?php echo htmlspecialchars($step['icon'], ENT_QUOTES, 'UTF-8'); ?>" aria-hidden="true"></i>
                        <div>
                            <strong><?php echo htmlspecialchars($step['title']); ?></strong>
                            <span><?php echo htmlspecialchars($step['copy']); ?></span>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="review-overview-card">
                <span class="review-overview-kicker">
                    <i data-lucide="badge-check" aria-hidden="true"></i>
                    Review Signals
                </span>
                <h2 class="review-overview-title">What to verify before signing off</h2>
                <p class="review-overview-subtitle">Use these compact signals to decide whether to inspect evidence, discussion, or the latest review note first.</p>
                <div class="review-overview-stat-grid">
                    <?php foreach ($reviewOverviewTiles as $tile): ?>
                    <div class="review-overview-stat">
                        <span><?php echo htmlspecialchars($tile['label']); ?></span>
                        <strong><?php echo htmlspecialchars($tile['value']); ?></strong>
                        <p><?php echo htmlspecialchars($tile['note']); ?></p>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>

        <?php if ($success_msg): ?>
        <div class="bg-green-50 border border-green-200 text-green-800 rounded-lg p-4" style="margin-bottom:16px;font-size:13px;">
            <?php if ($success_msg === 'approved'): ?>
                Task approved and marked as completed.
            <?php elseif ($success_msg === 'rejected'): ?>
                Task rejected.
            <?php elseif ($success_msg === 'requested_changes'): ?>
                Changes requested and the team has been notified.
            <?php else: ?>
                Review saved successfully.
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if ($error_msg): ?>
        <div class="bg-red-50 border border-red-200 text-red-800 rounded-lg p-4" style="margin-bottom:16px;font-size:13px;">
            <?php echo htmlspecialchars($error_msg); ?>
        </div>
        <?php endif; ?>

        <div class="todo-kpi-row" style="margin-bottom:16px;">
            <div class="todo-kpi">
                <div class="todo-kpi-label">Assigned Team</div>
                <div class="todo-kpi-value" style="font-size:14px;"><?php echo htmlspecialchars($task_assignee_summary); ?></div>
            </div>
            <div class="todo-kpi">
                <div class="todo-kpi-label">Priority</div>
                <div class="todo-kpi-value" style="font-size:14px;"><?php echo htmlspecialchars($task['priority']); ?></div>
            </div>
            <div class="todo-kpi">
                <div class="todo-kpi-label">Due Date</div>
                <div class="todo-kpi-value" style="font-size:14px;"><?php echo $task['due_date'] ? date('M d, Y', strtotime($task['due_date'])) : 'No due date'; ?></div>
            </div>
        </div>

        <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:16px;">
            <button type="button" class="todo-btn-ghost" data-ws-open="wsReviewProcedure">
                <i data-lucide="clipboard-list" aria-hidden="true"></i>
                Work log &amp; evidence
                <span class="todo-pill is-neutral" style="margin-left:4px;"><?php echo $progressLogCount + $docsCount; ?></span>
            </button>
            <button type="button" class="todo-btn-ghost" data-ws-open="wsReviewDiscussion">
                <i data-lucide="messages-square" aria-hidden="true"></i>
                Discussion &amp; History
                <span class="todo-pill is-neutral" style="margin-left:4px;"><?php echo $commentsCount + $reviewsCount; ?></span>
            </button>
            <?php if (!empty($task['description'])): ?>
            <button type="button" class="todo-btn-ghost" data-ws-open="wsReviewDescription">
                <i data-lucide="file-text" aria-hidden="true"></i>
                Description
            </button>
            <?php endif; ?>
        </div>

        <?php if (!empty($task['review_note'])): ?>
        <div class="review-note-card">
            <div class="review-note-card-label">Latest Review Note</div>
            <div style="color:#374151;white-space:pre-wrap;"><?php echo htmlspecialchars($task['review_note']); ?></div>
        </div>
        <?php endif; ?>

        <?php if (!empty($latest_progress_log)): ?>
        <?php
        $latest_work_done = trim((string) ($latest_progress_log['work_done'] ?? ''));
        $latest_next_work = trim((string) ($latest_progress_log['next_work'] ?? ''));
        $latest_outcome = trim((string) ($latest_progress_log['outcome_text'] ?? ''));
        $latest_status_line = !empty($latest_progress_log['has_status_change'])
            ? trim((string) ($latest_progress_log['old_status'] ?? '')) . ' -> ' . trim((string) ($latest_progress_log['new_status'] ?? ''))
            : ('Logged under ' . trim((string) ($latest_progress_log['new_status'] ?? ($task['status'] ?? 'Current status'))));
        ?>
        <div class="todo-modal review-decision-card" style="margin-bottom:16px;">
            <div class="todo-modal-header">
                <div class="review-decision-copy">
                    <h3 class="todo-modal-title">Latest recorded work</h3>
                    <p>Review the newest progress entry before making a PM decision.</p>
                </div>
                <span class="todo-pill"><?php echo htmlspecialchars($latest_status_line); ?></span>
            </div>
            <div class="todo-modal-body">
                <p style="margin:0 0 12px;font-size:12px;color:#64748b;">
                    <?php echo htmlspecialchars((string) ($latest_progress_log['user_name'] ?? 'Unknown')); ?>
                    · <?php echo date('M d, Y H:i', strtotime((string) ($latest_progress_log['created_at'] ?? 'now'))); ?>
                </p>
                <?php if ($latest_work_done !== ''): ?>
                <div class="todo-field">
                    <label>Work done</label>
                    <div style="font-size:13px;color:#374151;white-space:pre-wrap;"><?php echo htmlspecialchars($latest_work_done); ?></div>
                </div>
                <?php endif; ?>
                <?php if (!empty($latest_progress_log['steps'])): ?>
                <div class="todo-field">
                    <label>Procedures performed</label>
                    <div style="display:flex;flex-direction:column;gap:10px;">
                        <?php foreach (($latest_progress_log['steps'] ?? []) as $latest_step): ?>
                        <div style="border-radius:10px;border:1px solid rgba(15, 118, 110, 0.18);background:rgba(236, 253, 245, 0.82);padding:12px 14px;">
                            <div style="font-size:11px;font-weight:700;letter-spacing:0.05em;text-transform:uppercase;color:#0f766e;margin-bottom:4px;">Procedure <?php echo (int) ($latest_step['step_order'] ?? 0); ?></div>
                            <div style="font-weight:600;color:#1f2937;"><?php echo htmlspecialchars((string) ($latest_step['procedure_text'] ?? '')); ?></div>
                            <?php if (!empty($latest_step['output_text'])): ?>
                            <div style="font-size:12px;color:#4b5563;margin-top:6px;white-space:pre-wrap;"><?php echo htmlspecialchars((string) $latest_step['output_text']); ?></div>
                            <?php endif; ?>
                            <?php if (!empty($latest_step['attachments'])): ?>
                            <div style="display:flex;flex-wrap:wrap;gap:8px;margin-top:8px;">
                                <?php foreach (($latest_step['attachments'] ?? []) as $latest_step_attachment): ?>
                                <a href="<?php echo htmlspecialchars('../../' . ltrim((string) ($latest_step_attachment['file_path'] ?? ''), '/')); ?>" target="_blank" rel="noopener noreferrer" style="display:inline-flex;align-items:center;gap:4px;color:#0f766e;font-size:12px;font-weight:700;">
                                    <i data-lucide="paperclip" aria-hidden="true" style="width:16px;height:16px;flex-shrink:0;"></i>
                                    <span><?php echo htmlspecialchars((string) ($latest_step_attachment['original_name'] ?? 'Evidence')); ?></span>
                                </a>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
                <?php if ($latest_next_work !== '' || $latest_outcome !== ''): ?>
                <div style="display:grid;gap:12px;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));">
                    <?php if ($latest_next_work !== ''): ?>
                    <div class="todo-field">
                        <label>Next scheduled work</label>
                        <div style="font-size:13px;color:#374151;white-space:pre-wrap;"><?php echo htmlspecialchars($latest_next_work); ?></div>
                    </div>
                    <?php endif; ?>
                    <?php if ($latest_outcome !== ''): ?>
                    <div class="todo-field">
                        <label>Current outcome</label>
                        <div style="font-size:13px;color:#374151;white-space:pre-wrap;"><?php echo htmlspecialchars($latest_outcome); ?></div>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
                <?php if (!empty($latest_progress_log['entry_attachments'])): ?>
                <div class="todo-field">
                    <label>Entry evidence</label>
                    <div style="display:flex;flex-wrap:wrap;gap:8px;">
                        <?php foreach (($latest_progress_log['entry_attachments'] ?? []) as $latest_entry_attachment): ?>
                        <a href="<?php echo htmlspecialchars('../../' . ltrim((string) ($latest_entry_attachment['file_path'] ?? ''), '/')); ?>" target="_blank" rel="noopener noreferrer" style="display:inline-flex;align-items:center;gap:4px;color:#0f766e;font-size:12px;font-weight:700;">
                            <i data-lucide="paperclip" aria-hidden="true" style="width:16px;height:16px;flex-shrink:0;"></i>
                            <span><?php echo htmlspecialchars((string) ($latest_entry_attachment['original_name'] ?? 'Evidence')); ?></span>
                        </a>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <div class="todo-modal review-decision-card">
            <div class="todo-modal-header">
                <div class="review-decision-copy">
                    <h3 class="todo-modal-title">Decision</h3>
                    <p>Choose one workflow action and leave enough context for the assigned team to continue confidently.</p>
                </div>
                <span class="todo-pill">One action advances the workflow</span>
            </div>
            <div class="todo-modal-body">
                <?php if ($review_decision_locked): ?>
                    <div class="review-banner is-muted">
                        No new task changes were detected since this task was <?php echo htmlspecialchars($task_last_decision_label); ?><?php echo $task_last_decision_stamp ? ' on ' . htmlspecialchars($task_last_decision_stamp) : ''; ?>.
                    </div>
                <?php else: ?>
                    <?php if ($task['status'] !== 'In Review' && $task_has_changes_since_decision): ?>
                        <div class="review-banner is-accent">
                            The task changed after the last PM decision, so the sign-off controls are available again.
                        </div>
                    <?php endif; ?>
                    <form method="POST" action="review?id=<?php echo $task_id; ?>">
                        <input type="hidden" name="redirect_to" value="modules/tasks/review?id=<?php echo $task_id; ?>">
                        <div class="todo-field">
                            <label>Score (optional)</label>
                            <div class="review-score-grid">
                                <?php for ($i = 1; $i <= 5; $i++): ?>
                                <label class="review-score-option">
                                    <input type="radio" name="score" value="<?php echo $i; ?>">
                                    <span><?php echo $i; ?>/5</span>
                                </label>
                                <?php endfor; ?>
                            </div>
                        </div>
                        <div class="todo-field">
                            <label for="review_note">Review Note</label>
                            <textarea id="review_note" name="note" rows="4" class="todo-textarea" placeholder="Add feedback for the assignee team..."></textarea>
                        </div>
                        <div class="review-action-grid sm:grid-cols-3">
                            <button type="submit" name="review_action" value="approved" class="todo-btn-primary" style="background:#16a34a;border-color:#16a34a;">
                                <i data-lucide="circle-check" aria-hidden="true"></i> Approve
                            </button>
                            <button type="submit" name="review_action" value="requested_changes" class="todo-btn-primary" style="background:#f59e0b;border-color:#f59e0b;">
                                <i data-lucide="pencil" aria-hidden="true"></i> Request Changes
                            </button>
                            <button type="submit" name="review_action" value="rejected" class="todo-btn-primary" style="background:#dc2626;border-color:#dc2626;" onclick="return confirm('Reject this task?');">
                                <i data-lucide="circle-x" aria-hidden="true"></i> Reject
                            </button>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </main>
</div>

<?php if (!empty($task['description'])): ?>
<!-- Description modal -->
<div class="todo-modal-overlay" id="wsReviewDescription" role="dialog" aria-labelledby="wsReviewDescriptionTitle" aria-hidden="true">
    <div class="todo-modal todo-modal--lg">
        <div class="todo-modal-header">
            <h3 class="todo-modal-title" id="wsReviewDescriptionTitle">Task description</h3>
            <button class="todo-modal-close" data-ws-close aria-label="Close">&times;</button>
        </div>
        <div class="todo-modal-body">
            <div style="color:#374151;white-space:pre-wrap;line-height:1.55;"><?php echo htmlspecialchars($task['description']); ?></div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Work log & Evidence modal -->
<div class="todo-modal-overlay" id="wsReviewProcedure" role="dialog" aria-labelledby="wsReviewProcedureTitle" aria-hidden="true">
    <div class="todo-modal todo-modal--lg">
        <div class="todo-modal-header">
            <h3 class="todo-modal-title" id="wsReviewProcedureTitle">Work log &amp; evidence</h3>
            <button class="todo-modal-close" data-ws-close aria-label="Close">&times;</button>
        </div>
        <div class="todo-modal-body">
            <?php if (!empty($progress_logs)): ?>
                <h4 style="font-weight:700;color:#1f2937;margin:0;">Recorded work log</h4>
                <div style="display:flex;flex-direction:column;gap:10px;">
                    <?php foreach ($progress_logs as $progress_log): ?>
                    <?php
                    $progress_status_line = !empty($progress_log['has_status_change'])
                        ? trim((string) ($progress_log['old_status'] ?? '')) . ' -> ' . trim((string) ($progress_log['new_status'] ?? ''))
                        : ('Logged under ' . trim((string) ($progress_log['new_status'] ?? ($task['status'] ?? 'Current status'))));
                    ?>
                    <div style="border-radius:10px;border:1px solid #e5e7eb;background:#ffffff;padding:12px 14px;">
                        <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:8px;flex-wrap:wrap;">
                            <div>
                                <div style="font-weight:600;color:#1f2937;font-size:13px;"><?php echo htmlspecialchars((string) ($progress_log['user_name'] ?? 'Unknown')); ?></div>
                                <div style="font-size:11px;color:#6b7280;margin-top:2px;"><?php echo date('M d, Y H:i', strtotime((string) ($progress_log['created_at'] ?? 'now'))); ?></div>
                            </div>
                            <span class="todo-pill is-neutral"><?php echo htmlspecialchars($progress_status_line); ?></span>
                        </div>
                        <?php if (!empty($progress_log['work_done'])): ?>
                        <div style="font-size:13px;color:#374151;white-space:pre-wrap;margin-top:8px;"><?php echo htmlspecialchars((string) $progress_log['work_done']); ?></div>
                        <?php endif; ?>
                        <?php if (!empty($progress_log['steps'])): ?>
                        <div style="display:flex;flex-direction:column;gap:8px;margin-top:10px;">
                            <?php foreach (($progress_log['steps'] ?? []) as $progress_step): ?>
                            <div style="border-radius:10px;border:1px solid rgba(15, 118, 110, 0.18);background:rgba(236, 253, 245, 0.82);padding:12px 14px;">
                                <div style="font-size:11px;font-weight:700;letter-spacing:0.05em;text-transform:uppercase;color:#0f766e;margin-bottom:4px;">Procedure <?php echo (int) ($progress_step['step_order'] ?? 0); ?></div>
                                <div style="font-weight:600;color:#1f2937;"><?php echo htmlspecialchars((string) ($progress_step['procedure_text'] ?? '')); ?></div>
                                <?php if (!empty($progress_step['output_text'])): ?>
                                <div style="font-size:12px;color:#4b5563;margin-top:6px;white-space:pre-wrap;"><?php echo htmlspecialchars((string) $progress_step['output_text']); ?></div>
                                <?php endif; ?>
                                <?php if (!empty($progress_step['attachments'])): ?>
                                <div style="display:flex;flex-wrap:wrap;gap:8px;margin-top:8px;">
                                    <?php foreach (($progress_step['attachments'] ?? []) as $progress_step_attachment): ?>
                                    <a href="<?php echo htmlspecialchars('../../' . ltrim((string) ($progress_step_attachment['file_path'] ?? ''), '/')); ?>" target="_blank" rel="noopener noreferrer" style="display:inline-flex;align-items:center;gap:4px;color:#0f766e;font-size:12px;font-weight:700;">
                                        <i data-lucide="paperclip" aria-hidden="true" style="width:16px;height:16px;flex-shrink:0;"></i>
                                        <span><?php echo htmlspecialchars((string) ($progress_step_attachment['original_name'] ?? 'Evidence')); ?></span>
                                    </a>
                                    <?php endforeach; ?>
                                </div>
                                <?php endif; ?>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($progress_log['next_work']) || !empty($progress_log['outcome_text']) || !empty($progress_log['entry_attachments'])): ?>
                        <div style="display:flex;flex-direction:column;gap:8px;margin-top:10px;">
                            <?php if (!empty($progress_log['next_work'])): ?>
                            <div style="font-size:12px;color:#4b5563;white-space:pre-wrap;"><strong>Next scheduled work:</strong> <?php echo htmlspecialchars((string) $progress_log['next_work']); ?></div>
                            <?php endif; ?>
                            <?php if (!empty($progress_log['outcome_text'])): ?>
                            <div style="font-size:12px;color:#4b5563;white-space:pre-wrap;"><strong>Current outcome:</strong> <?php echo htmlspecialchars((string) $progress_log['outcome_text']); ?></div>
                            <?php endif; ?>
                            <?php if (!empty($progress_log['entry_attachments'])): ?>
                            <div style="display:flex;flex-wrap:wrap;gap:8px;">
                                <?php foreach (($progress_log['entry_attachments'] ?? []) as $progress_entry_attachment): ?>
                                <a href="<?php echo htmlspecialchars('../../' . ltrim((string) ($progress_entry_attachment['file_path'] ?? ''), '/')); ?>" target="_blank" rel="noopener noreferrer" style="display:inline-flex;align-items:center;gap:4px;color:#0f766e;font-size:12px;font-weight:700;">
                                    <i data-lucide="paperclip" aria-hidden="true" style="width:16px;height:16px;flex-shrink:0;"></i>
                                    <span><?php echo htmlspecialchars((string) ($progress_entry_attachment['original_name'] ?? 'Evidence')); ?></span>
                                </a>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($procedure_steps)): ?>
                <h4 style="font-weight:700;color:#1f2937;margin:8px 0 0;">Planned checklist</h4>
                <div style="display:flex;flex-direction:column;gap:10px;">
                    <?php foreach ($procedure_steps as $step): ?>
                    <div style="border-radius:10px;border:1px solid rgba(15, 118, 110, 0.18);background:rgba(236, 253, 245, 0.82);padding:12px 14px;">
                        <div style="font-size:11px;font-weight:700;letter-spacing:0.05em;text-transform:uppercase;color:#0f766e;margin-bottom:4px;">Step <?php echo (int) $step['step_order']; ?></div>
                        <div style="font-weight:600;color:#1f2937;"><?php echo htmlspecialchars($step['instruction']); ?></div>
                        <?php if (!empty($step['note'])): ?>
                        <div style="font-size:12px;color:#4b5563;margin-top:6px;"><?php echo htmlspecialchars($step['note']); ?></div>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($docs)): ?>
                <h4 style="font-weight:700;color:#1f2937;margin:8px 0 0;">Documentation &amp; evidence</h4>
                <div style="display:flex;flex-direction:column;gap:10px;">
                    <?php foreach ($docs as $doc): ?>
                    <div style="border-radius:10px;border:1px solid #e5e7eb;background:#f9fafb;padding:12px 14px;">
                        <div style="font-weight:600;color:#1f2937;font-size:13px;"><?php echo htmlspecialchars($doc['uploader_name']); ?></div>
                        <div style="font-size:11px;color:#6b7280;margin-top:2px;"><?php echo date('M d, Y H:i', strtotime($doc['created_at'])); ?></div>
                        <?php if (!empty($doc['documentation_text'])): ?>
                        <div style="font-size:13px;color:#374151;white-space:pre-wrap;margin-top:8px;"><?php echo htmlspecialchars($doc['documentation_text']); ?></div>
                        <?php endif; ?>
                        <?php if (!empty($doc['document_path'])): ?>
                        <a href="<?php echo htmlspecialchars('../../' . $doc['document_path']); ?>" target="_blank" style="display:inline-flex;align-items:center;gap:4px;margin-top:8px;color:#0f766e;font-size:13px;font-weight:700;">
                            <i data-lucide="paperclip" aria-hidden="true" style="width:16px;height:16px;flex-shrink:0;"></i>
                            <span>Open Evidence</span>
                        </a>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if (empty($progress_logs) && empty($procedure_steps) && empty($docs)): ?>
                <div class="todo-empty">
                    <i data-lucide="clipboard-list" aria-hidden="true"></i>
                    <p>No work log or evidence attached.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Discussion & History modal -->
<div class="todo-modal-overlay" id="wsReviewDiscussion" role="dialog" aria-labelledby="wsReviewDiscussionTitle" aria-hidden="true">
    <div class="todo-modal todo-modal--lg">
        <div class="todo-modal-header">
            <h3 class="todo-modal-title" id="wsReviewDiscussionTitle">Discussion &amp; review history</h3>
            <button class="todo-modal-close" data-ws-close aria-label="Close">&times;</button>
        </div>
        <div class="todo-modal-body">
            <div class="todo-tabs" data-ws-tab-group="wsReviewTabs" role="tablist">
                <button type="button" class="todo-tab is-active" data-ws-tab="comments" data-ws-tab-group-ref="wsReviewTabs">Comments (<?php echo $commentsCount; ?>)</button>
                <button type="button" class="todo-tab" data-ws-tab="reviews" data-ws-tab-group-ref="wsReviewTabs">Review history (<?php echo $reviewsCount; ?>)</button>
            </div>

            <div class="todo-tab-panel is-active" data-ws-tab-panel="comments" data-ws-tab-group-ref="wsReviewTabs">
                <div style="display:flex;flex-direction:column;gap:10px;">
                    <?php if (empty($comments)): ?>
                        <div class="todo-empty"><i data-lucide="messages-square" aria-hidden="true"></i><p>No comments yet.</p></div>
                    <?php else: ?>
                        <?php foreach ($comments as $comment): ?>
                            <div style="border-radius:10px;border:1px solid #e5e7eb;background:#ffffff;padding:12px 14px;">
                                <div style="display:flex;align-items:center;justify-content:space-between;gap:8px;">
                                    <span style="font-weight:600;color:#1f2937;font-size:13px;"><?php echo htmlspecialchars($comment['author_name']); ?></span>
                                    <span style="font-size:11px;color:#9ca3af;"><?php echo date('M d, Y H:i', strtotime($comment['created_at'])); ?></span>
                                </div>
                                <div style="font-size:13px;color:#374151;white-space:pre-wrap;margin-top:6px;"><?php echo htmlspecialchars($comment['comment']); ?></div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <div class="todo-tab-panel" data-ws-tab-panel="reviews" data-ws-tab-group-ref="wsReviewTabs">
                <div style="display:flex;flex-direction:column;gap:10px;">
                    <?php if (empty($reviews)): ?>
                        <div class="todo-empty"><i data-lucide="history" aria-hidden="true"></i><p>No review history yet.</p></div>
                    <?php else: ?>
                        <?php foreach ($reviews as $review): ?>
                            <?php $label = $action_labels[$review['action']] ?? ['label' => $review['action'], 'class' => 'bg-gray-100 text-gray-700']; ?>
                            <div style="border-radius:10px;border:1px solid #e5e7eb;background:#f9fafb;padding:12px 14px;">
                                <div style="display:flex;align-items:center;justify-content:space-between;gap:8px;flex-wrap:wrap;">
                                    <div style="display:flex;align-items:center;gap:8px;">
                                        <span style="font-weight:600;color:#1f2937;font-size:13px;"><?php echo htmlspecialchars($review['reviewer_name']); ?></span>
                                        <span class="<?php echo $label['class']; ?>" style="font-size:11px;padding:2px 8px;border-radius:999px;font-weight:600;"><?php echo htmlspecialchars($label['label']); ?></span>
                                    </div>
                                    <span style="font-size:11px;color:#9ca3af;"><?php echo date('M d, Y H:i', strtotime($review['created_at'])); ?></span>
                                </div>
                                <?php if (!empty($review['score'])): ?>
                                <div style="font-size:12px;color:#4b5563;margin-top:4px;">Score: <?php echo (int) $review['score']; ?>/5</div>
                                <?php endif; ?>
                                <?php if (!empty($review['note'])): ?>
                                <div style="font-size:13px;color:#374151;white-space:pre-wrap;margin-top:6px;"><?php echo htmlspecialchars($review['note']); ?></div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script>if (typeof window.refreshAppShellIcons === 'function') { window.refreshAppShellIcons(); }</script>
<?php include '../../includes/footer.php'; ?>
