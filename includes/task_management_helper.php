<?php
require_once __DIR__ . '/upload_helper.php';

function fetch_project_requirement_defaults(PDO $pdo): array
{
    $defaults = [];
    $rows = $pdo->query("SELECT id, require_document_submission, require_procedure_tracking FROM projects")->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $row) {
        $defaults[(int) $row['id']] = [
            'require_document_submission' => (int) !empty($row['require_document_submission']),
            'require_procedure_tracking' => (int) !empty($row['require_procedure_tracking']),
        ];
    }

    return $defaults;
}

function fetch_task_assignee_workload(PDO $pdo): array
{
    $workload = [];
    $rows = $pdo->query("
        SELECT
            u.id,
            COUNT(DISTINCT ta.task_id) AS total_tasks,
            COUNT(DISTINCT CASE
                WHEN t.status != 'Completed' AND t.status != 'Cancelled' THEN ta.task_id
                ELSE NULL
            END) AS open_tasks
        FROM users u
        LEFT JOIN task_assignees ta ON ta.user_id = u.id
        LEFT JOIN tasks t ON t.id = ta.task_id
        GROUP BY u.id
    ")->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $row) {
        $workload[(int) $row['id']] = [
            'total_tasks' => (int) ($row['total_tasks'] ?? 0),
            'open_tasks' => (int) ($row['open_tasks'] ?? 0),
        ];
    }

    return $workload;
}

function fetch_task_assignees_for_tasks(PDO $pdo, array $taskIds): array
{
    $taskIds = array_values(array_unique(array_filter(array_map('intval', $taskIds))));
    if (empty($taskIds)) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($taskIds), '?'));
    $stmt = $pdo->prepare("
        SELECT ta.task_id, ta.user_id, ta.is_primary, u.name, u.email
        FROM task_assignees ta
        JOIN users u ON u.id = ta.user_id
        WHERE ta.task_id IN ($placeholders)
        ORDER BY ta.task_id ASC, ta.is_primary DESC, u.name ASC
    ");
    $stmt->execute($taskIds);

    $assigneesByTask = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $taskId = (int) $row['task_id'];
        if (!isset($assigneesByTask[$taskId])) {
            $assigneesByTask[$taskId] = [];
        }

        $assigneesByTask[$taskId][] = [
            'user_id' => (int) $row['user_id'],
            'name' => $row['name'],
            'email' => $row['email'],
            'is_primary' => (int) !empty($row['is_primary']),
        ];
    }

    return $assigneesByTask;
}

function fetch_task_assignees(PDO $pdo, int $taskId): array
{
    $assignees = fetch_task_assignees_for_tasks($pdo, [$taskId]);
    return $assignees[$taskId] ?? [];
}

function fetch_task_latest_reviews_for_tasks(PDO $pdo, array $taskIds): array
{
    $taskIds = array_values(array_unique(array_filter(array_map('intval', $taskIds))));
    if (empty($taskIds)) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($taskIds), '?'));
    $stmt = $pdo->prepare("
        SELECT tr.*, COALESCE(u.name, 'Deleted User') AS reviewer_name
        FROM task_reviews tr
        INNER JOIN (
            SELECT task_id, MAX(id) AS latest_id
            FROM task_reviews
            WHERE task_id IN ($placeholders)
            GROUP BY task_id
        ) latest_review ON latest_review.latest_id = tr.id
        LEFT JOIN users u ON u.id = tr.reviewer_id
    ");
    $stmt->execute($taskIds);

    $reviewsByTask = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $review) {
        $reviewsByTask[(int) $review['task_id']] = $review;
    }

    return $reviewsByTask;
}

function fetch_task_latest_review(PDO $pdo, int $taskId): ?array
{
    $reviews = fetch_task_latest_reviews_for_tasks($pdo, [$taskId]);
    return $reviews[$taskId] ?? null;
}

function format_task_assignee_summary(array $assignees, ?string $fallbackName = null): string
{
    if (!empty($assignees)) {
        return implode(', ', array_map(static function (array $assignee): string {
            return $assignee['name'] . (!empty($assignee['is_primary']) ? ' (Primary)' : '');
        }, $assignees));
    }

    if (!empty($fallbackName)) {
        return $fallbackName;
    }

    return 'Unassigned';
}

function fetch_task_workflow_context(PDO $pdo, int $taskId): ?array
{
    $stmt = $pdo->prepare("
        SELECT
            t.*,
            p.name AS project_name,
            p.created_by AS pm_id,
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
        return null;
    }

    $task['task_assignees'] = fetch_task_assignees($pdo, $taskId);
    $task['latest_review'] = fetch_task_latest_review($pdo, $taskId);

    return $task;
}

function get_task_workflow_state(array $task, ?array $latestReview = null): array
{
    $status = $task['status'] ?? 'Not Started';
    $latestReview = $latestReview ?? ($task['latest_review'] ?? null);
    $latestAction = $latestReview['action'] ?? null;

    if ($status === 'Completed' && !empty($task['approved_by'])) {
        return [
            'key' => 'approved',
            'label' => 'Approved',
            'description' => 'Work is complete and has been signed off by the project manager.',
            'badge_class' => 'bg-emerald-100 text-emerald-800',
            'manager_action_required' => false,
        ];
    }

    if ($status === 'In Review') {
        return [
            'key' => 'awaiting_approval',
            'label' => 'Awaiting Approval',
            'description' => 'The task has been submitted and is waiting for a project manager decision.',
            'badge_class' => 'bg-amber-100 text-amber-800',
            'manager_action_required' => true,
        ];
    }

    if ($latestAction === 'requested_changes' && $status === 'In Progress') {
        return [
            'key' => 'changes_requested',
            'label' => 'Changes Requested',
            'description' => 'The project manager requested updates before approval can continue.',
            'badge_class' => 'bg-orange-100 text-orange-800',
            'manager_action_required' => false,
        ];
    }

    if ($latestAction === 'rejected' && $status === 'Cancelled') {
        return [
            'key' => 'rejected',
            'label' => 'Rejected',
            'description' => 'The task was rejected during review and is no longer active.',
            'badge_class' => 'bg-rose-100 text-rose-800',
            'manager_action_required' => false,
        ];
    }

    if ($status === 'Completed') {
        return [
            'key' => 'completed',
            'label' => 'Completed',
            'description' => 'The task is marked as completed.',
            'badge_class' => 'bg-green-100 text-green-800',
            'manager_action_required' => false,
        ];
    }

    if ($status === 'Cancelled') {
        return [
            'key' => 'cancelled',
            'label' => 'Cancelled',
            'description' => 'The task has been cancelled.',
            'badge_class' => 'bg-red-100 text-red-800',
            'manager_action_required' => false,
        ];
    }

    if ($status === 'Not Started') {
        return [
            'key' => 'not_started',
            'label' => 'Not Started',
            'description' => 'Work has not started yet.',
            'badge_class' => 'bg-slate-100 text-slate-700',
            'manager_action_required' => false,
        ];
    }

    return [
        'key' => 'active',
        'label' => 'In Progress',
        'description' => 'The assignee team is actively working on this task.',
        'badge_class' => 'bg-blue-100 text-blue-800',
        'manager_action_required' => false,
    ];
}

function build_task_status_update_payload(array $task, string $newStatus, bool $actorIsPm, int $actorId): array
{
    $oldStatus = $task['status'] ?? null;
    $completedAt = $task['completed_at'] ?? null;
    $approvedBy = $task['approved_by'] ?? null;
    $approvedAt = $task['approved_at'] ?? null;
    $score = $task['score'] ?? null;

    if ($newStatus === 'Completed') {
        $completedAt = ($oldStatus === 'Completed' && !empty($completedAt)) ? $completedAt : date('Y-m-d H:i:s');

        if ($actorIsPm) {
            $approvedBy = $actorId;
            $approvedAt = !empty($approvedAt) ? $approvedAt : date('Y-m-d H:i:s');
        }
    } else {
        if ($oldStatus === 'Completed') {
            $completedAt = null;
        }

        if (!empty($approvedBy)) {
            $approvedBy = null;
            $approvedAt = null;
            $score = null;
        }
    }

    return [
        'completed_at' => $completedAt,
        'approved_by' => $approvedBy,
        'approved_at' => $approvedAt,
        'score' => $score,
    ];
}

function append_query_params_to_path(string $path, array $params): string
{
    $separator = strpos($path, '?') === false ? '?' : '&';
    return $path . $separator . http_build_query($params);
}

function parse_workflow_datetime(?string $value): ?int
{
    $value = trim((string) $value);
    if ($value === '') {
        return null;
    }

    $timestamp = strtotime($value);
    return $timestamp === false ? null : $timestamp;
}

function latest_workflow_datetime(array $values): ?string
{
    $latestTimestamp = null;
    $latestValue = null;

    foreach ($values as $value) {
        $timestamp = parse_workflow_datetime($value);
        if ($timestamp === null) {
            continue;
        }

        if ($latestTimestamp === null || $timestamp > $latestTimestamp) {
            $latestTimestamp = $timestamp;
            $latestValue = date('Y-m-d H:i:s', $timestamp);
        }
    }

    return $latestValue;
}

function has_workflow_changes_after(?string $latestChangeAt, ?string $referenceAt): bool
{
    $latestTimestamp = parse_workflow_datetime($latestChangeAt);
    $referenceTimestamp = parse_workflow_datetime($referenceAt);

    if ($latestTimestamp === null || $referenceTimestamp === null) {
        return false;
    }

    return $latestTimestamp > $referenceTimestamp;
}

function task_progress_tables_ready(PDO $pdo): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }

    try {
        $stmt = $pdo->query("
            SELECT COUNT(*)
            FROM information_schema.tables
            WHERE table_schema = DATABASE()
              AND table_name IN ('task_progress_logs', 'task_progress_log_steps', 'task_progress_log_attachments')
        ");
        $ready = ((int) $stmt->fetchColumn()) === 3;
    } catch (Throwable $e) {
        $ready = false;
    }

    return $ready;
}

function fetch_task_latest_change_at(PDO $pdo, int $taskId, ?array $task = null): ?string
{
    if ($taskId <= 0) {
        return null;
    }

    $fallback = latest_workflow_datetime([
        $task['updated_at'] ?? null,
        $task['created_at'] ?? null,
    ]);

    try {
        $progressUnions = '';
        $params = [
            'task_id_main' => $taskId,
            'task_id_steps' => $taskId,
            'task_id_attachments' => $taskId,
            'task_id_docs' => $taskId,
        ];
        if (task_progress_tables_ready($pdo)) {
            $progressUnions = "

                UNION ALL

                SELECT MAX(tpl.updated_at) AS change_at
                FROM task_progress_logs tpl
                WHERE tpl.task_id = :task_id_progress_logs

                UNION ALL

                SELECT MAX(tpls.updated_at) AS change_at
                FROM task_progress_log_steps tpls
                INNER JOIN task_progress_logs tpl ON tpl.id = tpls.progress_log_id
                WHERE tpl.task_id = :task_id_progress_steps

                UNION ALL

                SELECT MAX(tpla.created_at) AS change_at
                FROM task_progress_log_attachments tpla
                INNER JOIN task_progress_logs tpl ON tpl.id = tpla.progress_log_id
                WHERE tpl.task_id = :task_id_progress_attachments
            ";
            $params['task_id_progress_logs'] = $taskId;
            $params['task_id_progress_steps'] = $taskId;
            $params['task_id_progress_attachments'] = $taskId;
        }

        $stmt = $pdo->prepare("
            SELECT MAX(change_at) AS latest_change_at
            FROM (
                SELECT t.updated_at AS change_at
                FROM tasks t
                WHERE t.id = :task_id_main

                UNION ALL

                SELECT MAX(tps.updated_at) AS change_at
                FROM task_procedure_steps tps
                WHERE tps.task_id = :task_id_steps

                UNION ALL

                SELECT MAX(ta.created_at) AS change_at
                FROM task_attachments ta
                WHERE ta.task_id = :task_id_attachments

                UNION ALL

                SELECT MAX(td.created_at) AS change_at
                FROM task_documentation td
                WHERE td.task_id = :task_id_docs
                $progressUnions
            ) task_changes
        ");
        $stmt->execute($params);

        return latest_workflow_datetime([
            $fallback,
            $stmt->fetchColumn() ?: null,
        ]);
    } catch (Exception $e) {
        return $fallback;
    }
}

function fetch_task_last_decision_at(array $task, ?array $latestReview = null): ?string
{
    if (!empty($task['approved_at'])) {
        return $task['approved_at'];
    }

    if (!empty($latestReview['created_at']) && in_array($latestReview['action'] ?? '', ['approved', 'rejected', 'requested_changes'], true)) {
        return $latestReview['created_at'];
    }

    if (!empty($task['completed_at'])) {
        return $task['completed_at'];
    }

    return null;
}

function task_has_changes_since_last_decision(PDO $pdo, array $task, ?array $latestReview = null): bool
{
    $taskId = (int) ($task['id'] ?? 0);
    if ($taskId <= 0) {
        return false;
    }

    $lastDecisionAt = fetch_task_last_decision_at($task, $latestReview);
    if ($lastDecisionAt === null) {
        return false;
    }

    return has_workflow_changes_after(
        fetch_task_latest_change_at($pdo, $taskId, $task),
        $lastDecisionAt
    );
}

function fetch_project_latest_change_at(PDO $pdo, int $projectId, ?array $project = null): ?string
{
    if ($projectId <= 0) {
        return null;
    }

    $fallback = latest_workflow_datetime([
        $project['updated_at'] ?? null,
        $project['created_at'] ?? null,
    ]);

    try {
        $progressUnions = '';
        $params = [
            'project_id_main' => $projectId,
            'project_id_tasks' => $projectId,
            'project_id_steps' => $projectId,
            'project_id_attachments' => $projectId,
            'project_id_docs' => $projectId,
            'project_id_comments' => $projectId,
        ];
        if (task_progress_tables_ready($pdo)) {
            $progressUnions = "

                UNION ALL

                SELECT MAX(tpl.updated_at) AS change_at
                FROM task_progress_logs tpl
                INNER JOIN tasks t ON t.id = tpl.task_id
                WHERE t.project_id = :project_id_progress_logs

                UNION ALL

                SELECT MAX(tpls.updated_at) AS change_at
                FROM task_progress_log_steps tpls
                INNER JOIN task_progress_logs tpl ON tpl.id = tpls.progress_log_id
                INNER JOIN tasks t ON t.id = tpl.task_id
                WHERE t.project_id = :project_id_progress_steps

                UNION ALL

                SELECT MAX(tpla.created_at) AS change_at
                FROM task_progress_log_attachments tpla
                INNER JOIN task_progress_logs tpl ON tpl.id = tpla.progress_log_id
                INNER JOIN tasks t ON t.id = tpl.task_id
                WHERE t.project_id = :project_id_progress_attachments
            ";
            $params['project_id_progress_logs'] = $projectId;
            $params['project_id_progress_steps'] = $projectId;
            $params['project_id_progress_attachments'] = $projectId;
        }

        $stmt = $pdo->prepare("
            SELECT MAX(change_at) AS latest_change_at
            FROM (
                SELECT p.updated_at AS change_at
                FROM projects p
                WHERE p.id = :project_id_main

                UNION ALL

                SELECT MAX(t.updated_at) AS change_at
                FROM tasks t
                WHERE t.project_id = :project_id_tasks

                UNION ALL

                SELECT MAX(tps.updated_at) AS change_at
                FROM task_procedure_steps tps
                JOIN tasks t ON t.id = tps.task_id
                WHERE t.project_id = :project_id_steps

                UNION ALL

                SELECT MAX(ta.created_at) AS change_at
                FROM task_attachments ta
                JOIN tasks t ON t.id = ta.task_id
                WHERE t.project_id = :project_id_attachments

                UNION ALL

                SELECT MAX(td.created_at) AS change_at
                FROM task_documentation td
                JOIN tasks t ON t.id = td.task_id
                WHERE t.project_id = :project_id_docs
                $progressUnions

                UNION ALL

                SELECT MAX(pc.created_at) AS change_at
                FROM project_comments pc
                WHERE pc.project_id = :project_id_comments
            ) project_changes
        ");
        $stmt->execute($params);

        return latest_workflow_datetime([
            $fallback,
            $stmt->fetchColumn() ?: null,
        ]);
    } catch (Exception $e) {
        return $fallback;
    }
}

function project_has_changes_since_last_approval(PDO $pdo, array $project): bool
{
    $projectId = (int) ($project['id'] ?? 0);
    if ($projectId <= 0 || ($project['approved_status'] ?? 'Pending') === 'Pending') {
        return false;
    }

    $approvedAt = $project['approved_at'] ?? null;
    if ($approvedAt === null) {
        return false;
    }

    return has_workflow_changes_after(
        fetch_project_latest_change_at($pdo, $projectId, $project),
        $approvedAt
    );
}

function normalize_workflow_exception_message(?string $message, string $fallback): string
{
    $message = trim((string) $message);
    if ($message === '') {
        return $fallback;
    }

    $normalized = strtolower($message);
    foreach ([
        'there is no active transaction',
        'no active transaction',
        'no transaction',
    ] as $needle) {
        if (strpos($normalized, $needle) !== false) {
            return $fallback;
        }
    }

    return $message;
}

function run_best_effort_task_side_effect(string $label, callable $callback): bool
{
    try {
        $callback();
        return true;
    } catch (Throwable $e) {
        error_log($label . ': ' . $e->getMessage());
        return false;
    }
}

function fetch_task_notification_participants(PDO $pdo, array $task): array
{
    $participants = [];

    $registerParticipant = static function (int $userId, string $role) use (&$participants): void {
        if ($userId <= 0) {
            return;
        }

        if (!isset($participants[$userId])) {
            $participants[$userId] = ['roles' => []];
        }

        if (!in_array($role, $participants[$userId]['roles'], true)) {
            $participants[$userId]['roles'][] = $role;
        }
    };

    $registerParticipant((int) ($task['pm_id'] ?? 0), 'project_manager');
    $registerParticipant((int) ($task['created_by'] ?? 0), 'creator');

    $assignees = $task['task_assignees'] ?? fetch_task_assignees($pdo, (int) ($task['id'] ?? 0));
    foreach ($assignees as $assignee) {
        $registerParticipant((int) ($assignee['user_id'] ?? 0), 'assignee');
    }

    if (empty($assignees) && !empty($task['assigned_to'])) {
        $registerParticipant((int) $task['assigned_to'], 'assignee');
    }

    return $participants;
}

function build_task_workflow_notification_message(string $event, array $task, array $roles, string $actorName, array $context = []): ?array
{
    $taskName = $task['name'] ?? 'Task';
    $projectName = $task['project_name'] ?? 'the project';
    $oldStatus = $context['old_status'] ?? null;
    $newStatus = $context['new_status'] ?? null;
    $note = trim((string) ($context['note'] ?? ''));
    $score = !empty($context['score']) ? (int) $context['score'] : null;

    switch ($event) {
        case 'submitted_for_review':
            if (in_array('project_manager', $roles, true)) {
                return [
                    'title' => 'Task Awaiting Approval',
                    'description' => $actorName . ' submitted "' . $taskName . '" for your approval in project "' . $projectName . '".',
                ];
            }

            return [
                'title' => 'Task Submitted for Review',
                'description' => $actorName . ' submitted "' . $taskName . '" for project manager approval.',
            ];

        case 'approved':
            return [
                'title' => 'Task Approved',
                'description' => $actorName . ' approved "' . $taskName . '"' . ($score ? ' with a score of ' . $score . '/5.' : '.'),
            ];

        case 'requested_changes':
            return [
                'title' => 'Changes Requested on Task',
                'description' => $actorName . ' requested changes on "' . $taskName . '"' . ($note !== '' ? '. Note: ' . $note : '.'),
            ];

        case 'rejected':
            return [
                'title' => 'Task Rejected',
                'description' => $actorName . ' rejected "' . $taskName . '"' . ($note !== '' ? '. Note: ' . $note : '.'),
            ];

        case 'status_changed':
            if ($newStatus === 'Cancelled') {
                return [
                    'title' => 'Task Cancelled',
                    'description' => $actorName . ' cancelled "' . $taskName . '"' . ($oldStatus ? ' after it was ' . $oldStatus . '.' : '.'),
                ];
            }

            if ($newStatus === 'In Progress' && $oldStatus === 'Not Started') {
                return [
                    'title' => 'Task Started',
                    'description' => $actorName . ' started work on "' . $taskName . '".',
                ];
            }

            if ($newStatus === 'Not Started' && $oldStatus && $oldStatus !== 'Not Started') {
                return [
                    'title' => 'Task Reopened',
                    'description' => $actorName . ' moved "' . $taskName . '" back to Not Started.',
                ];
            }

            if ($oldStatus && $newStatus) {
                return [
                    'title' => 'Task Status Updated',
                    'description' => $actorName . ' changed "' . $taskName . '" from ' . $oldStatus . ' to ' . $newStatus . '.',
                ];
            }
            return null;
    }

    return null;
}

function notify_task_workflow_event(PDO $pdo, array $task, int $actorId, string $event, array $context = []): void
{
    $taskId = (int) ($task['id'] ?? 0);
    if ($taskId <= 0) {
        return;
    }

    $taskContext = $task;
    if (empty($taskContext['project_name']) || !isset($taskContext['pm_id'])) {
        $fetchedTask = fetch_task_workflow_context($pdo, $taskId);
        if (!$fetchedTask) {
            return;
        }
        $taskContext = array_merge($fetchedTask, $taskContext);
    }

    $participants = fetch_task_notification_participants($pdo, $taskContext);
    if (empty($participants)) {
        return;
    }

    $actorStmt = $pdo->prepare("SELECT name FROM users WHERE id = ?");
    $actorStmt->execute([$actorId]);
    $actorName = (string) $actorStmt->fetchColumn();
    if ($actorName === '') {
        $actorName = 'A team member';
    }

    require_once __DIR__ . '/../libs/NotificationManager.php';
    $notifManager = new NotificationManager($pdo);
    $link = $context['link'] ?? ('modules/tasks/view?id=' . $taskId);
    $oldStatus = $context['old_status'] ?? null;
    $newStatus = $context['new_status'] ?? null;

    foreach ($participants as $recipientId => $participant) {
        if (empty($context['notify_actor']) && (int) $recipientId === $actorId) {
            continue;
        }

        $message = build_task_workflow_notification_message($event, $taskContext, $participant['roles'], $actorName, $context);
        if (!$message) {
            continue;
        }

        $notifManager->notify(
            (int) $recipientId,
            'task',
            $message['title'],
            $message['description'],
            $link,
            $taskId,
            false,
            false,
            [
                'taskTitle' => $taskContext['name'] ?? null,
                'projectName' => $taskContext['project_name'] ?? null,
                'event' => $event,
                'oldStatus' => $oldStatus ?? null,
                'newStatus' => $newStatus ?? null,
            ]
        );
    }
}

function normalize_selected_user_ids($values): array
{
    if (!is_array($values)) {
        return [];
    }

    $normalized = [];
    foreach ($values as $value) {
        $id = (int) $value;
        if ($id > 0) {
            $normalized[$id] = $id;
        }
    }

    return array_values($normalized);
}

function determine_primary_assignee(array $assigneeIds, $requestedPrimaryId): ?int
{
    $requestedPrimaryId = (int) $requestedPrimaryId;
    if (!empty($assigneeIds) && in_array($requestedPrimaryId, $assigneeIds, true)) {
        return $requestedPrimaryId;
    }

    return !empty($assigneeIds) ? (int) $assigneeIds[0] : null;
}

function sync_task_assignees(PDO $pdo, int $taskId, array $assigneeIds, int $assignedBy, ?int $primaryAssigneeId): array
{
    $assigneeIds = normalize_selected_user_ids($assigneeIds);
    $primaryAssigneeId = determine_primary_assignee($assigneeIds, $primaryAssigneeId);

    $deleteStmt = $pdo->prepare("DELETE FROM task_assignees WHERE task_id = ?");
    $deleteStmt->execute([$taskId]);

    if (!empty($assigneeIds)) {
        $insertStmt = $pdo->prepare("
            INSERT INTO task_assignees (task_id, user_id, is_primary, assigned_by)
            VALUES (?, ?, ?, ?)
        ");

        foreach ($assigneeIds as $assigneeId) {
            $insertStmt->execute([
                $taskId,
                $assigneeId,
                $primaryAssigneeId === $assigneeId ? 1 : 0,
                $assignedBy,
            ]);
        }
    }

    return [
        'assignee_ids' => $assigneeIds,
        'primary_assignee_id' => $primaryAssigneeId,
    ];
}

function fetch_task_procedure_steps(PDO $pdo, int $taskId): array
{
    $stmt = $pdo->prepare("
        SELECT id, step_order, instruction, note
        FROM task_procedure_steps
        WHERE task_id = ?
        ORDER BY step_order ASC, id ASC
    ");
    $stmt->execute([$taskId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function normalize_task_procedure_steps($instructions, $notes = []): array
{
    $instructions = is_array($instructions) ? $instructions : [];
    $notes = is_array($notes) ? $notes : [];
    $steps = [];

    foreach ($instructions as $index => $instruction) {
        $instruction = trim((string) $instruction);
        $note = trim((string) ($notes[$index] ?? ''));
        if ($instruction === '' && $note === '') {
            continue;
        }

        $steps[] = [
            'step_order' => count($steps) + 1,
            'instruction' => $instruction,
            'note' => $note,
        ];
    }

    return $steps;
}

function sync_task_procedure_steps(PDO $pdo, int $taskId, array $steps, int $userId): array
{
    $deleteStmt = $pdo->prepare("DELETE FROM task_procedure_steps WHERE task_id = ?");
    $deleteStmt->execute([$taskId]);

    if (!empty($steps)) {
        $insertStmt = $pdo->prepare("
            INSERT INTO task_procedure_steps (task_id, step_order, instruction, note, created_by)
            VALUES (?, ?, ?, ?, ?)
        ");

        foreach ($steps as $index => $step) {
            $insertStmt->execute([
                $taskId,
                $index + 1,
                $step['instruction'],
                $step['note'] ?? null,
                $userId,
            ]);
        }
    }

    return $steps;
}

function summarize_procedure_steps(array $steps): string
{
    if (empty($steps)) {
        return '';
    }

    $lines = [];
    foreach ($steps as $index => $step) {
        $instruction = trim((string) ($step['instruction'] ?? ''));
        $note = trim((string) ($step['note'] ?? ''));
        if ($instruction === '') {
            continue;
        }

        $line = ($index + 1) . '. ' . $instruction;
        if ($note !== '') {
            $line .= ' | Note: ' . $note;
        }

        $lines[] = $line;
    }

    return implode(PHP_EOL, $lines);
}

function normalize_task_progress_log_steps($procedures, $outputs = []): array
{
    $procedures = is_array($procedures) ? $procedures : [];
    $outputs = is_array($outputs) ? $outputs : [];
    $rowCount = max(count($procedures), count($outputs));
    $steps = [];

    for ($index = 0; $index < $rowCount; $index++) {
        $procedure = trim((string) ($procedures[$index] ?? ''));
        $output = trim((string) ($outputs[$index] ?? ''));
        if ($procedure === '' && $output === '') {
            continue;
        }

        $steps[] = [
            'input_index' => $index,
            'step_order' => count($steps) + 1,
            'procedure_text' => $procedure,
            'output_text' => $output,
        ];
    }

    return $steps;
}

function normalize_uploaded_files_array(array $files): array
{
    if (!isset($files['name'])) {
        return [];
    }

    if (!is_array($files['name'])) {
        return [$files];
    }

    $normalized = [];
    foreach ($files['name'] as $index => $name) {
        $normalized[] = [
            'name' => $name,
            'type' => $files['type'][$index] ?? '',
            'tmp_name' => $files['tmp_name'][$index] ?? '',
            'error' => $files['error'][$index] ?? UPLOAD_ERR_NO_FILE,
            'size' => $files['size'][$index] ?? 0,
        ];
    }

    return $normalized;
}

function normalize_uploaded_file_groups(array $files): array
{
    if (!isset($files['name']) || !is_array($files['name'])) {
        return [];
    }

    $normalized = [];
    foreach ($files['name'] as $groupIndex => $groupNames) {
        $groupNames = is_array($groupNames) ? $groupNames : [$groupNames];
        foreach ($groupNames as $fileIndex => $name) {
            $normalized[(int) $groupIndex][] = [
                'name' => $name,
                'type' => $files['type'][$groupIndex][$fileIndex] ?? '',
                'tmp_name' => $files['tmp_name'][$groupIndex][$fileIndex] ?? '',
                'error' => $files['error'][$groupIndex][$fileIndex] ?? UPLOAD_ERR_NO_FILE,
                'size' => $files['size'][$groupIndex][$fileIndex] ?? 0,
            ];
        }
    }

    return $normalized;
}

function task_progress_log_has_payload(array $entry, array $steps, array $entryFiles = [], array $stepFilesBySlot = []): bool
{
    foreach (['work_done', 'next_work', 'outcome_text'] as $field) {
        if (trim((string) ($entry[$field] ?? '')) !== '') {
            return true;
        }
    }

    if (!empty($steps)) {
        return true;
    }

    foreach (normalize_uploaded_files_array($entryFiles) as $file) {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            return true;
        }
    }

    foreach ($stepFilesBySlot as $files) {
        foreach ($files as $file) {
            if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                return true;
            }
        }
    }

    return trim((string) ($entry['old_status'] ?? '')) !== trim((string) ($entry['new_status'] ?? ''));
}

function store_task_progress_log(
    PDO $pdo,
    int $taskId,
    int $userId,
    array $entry,
    array $steps,
    array $entryFiles = [],
    array $stepFilesBySlot = []
): array {
    if (!task_progress_tables_ready($pdo)) {
        throw new RuntimeException('Task progress logging requires sql/task_progress_worklog_refresh.sql.');
    }

    require_once __DIR__ . '/project_pm_helper.php';

    $projStmt = $pdo->prepare('SELECT project_id FROM tasks WHERE id = ?');
    $projStmt->execute([$taskId]);
    $projectId = (int) $projStmt->fetchColumn();

    $webPrefix = 'uploads/tasks/';
    $destinationDirectory = __DIR__ . '/../uploads/tasks/';
    if ($projectId > 0) {
        try {
            $paths = ensure_project_storage_directory($projectId);
            $destinationDirectory = $paths['fs_documentation'] . DIRECTORY_SEPARATOR;
            $webPrefix = $paths['web_documentation_prefix'];
        } catch (Throwable $e) {
            error_log('store_task_progress_log: ' . $e->getMessage());
        }
    }

    if (!is_dir($destinationDirectory) && !mkdir($destinationDirectory, 0755, true) && !is_dir($destinationDirectory)) {
        throw new RuntimeException('Unable to prepare the task progress upload directory.');
    }

    $logStmt = $pdo->prepare("
        INSERT INTO task_progress_logs (task_id, user_id, old_status, new_status, work_done, next_work, outcome_text)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    $logStmt->execute([
        $taskId,
        $userId > 0 ? $userId : null,
        $entry['old_status'] ?? null,
        $entry['new_status'] ?? null,
        trim((string) ($entry['work_done'] ?? '')) ?: null,
        trim((string) ($entry['next_work'] ?? '')) ?: null,
        trim((string) ($entry['outcome_text'] ?? '')) ?: null,
    ]);
    $logId = (int) $pdo->lastInsertId();

    $stepIdsByInputIndex = [];
    if (!empty($steps)) {
        $stepStmt = $pdo->prepare("
            INSERT INTO task_progress_log_steps (progress_log_id, step_order, procedure_text, output_text)
            VALUES (?, ?, ?, ?)
        ");
        foreach ($steps as $step) {
            $stepStmt->execute([
                $logId,
                (int) ($step['step_order'] ?? 0),
                trim((string) ($step['procedure_text'] ?? '')),
                trim((string) ($step['output_text'] ?? '')) ?: null,
            ]);
            $stepIdsByInputIndex[(int) ($step['input_index'] ?? 0)] = (int) $pdo->lastInsertId();
        }
    }

    $attachmentStmt = $pdo->prepare("
        INSERT INTO task_progress_log_attachments (
            progress_log_id, progress_step_id, uploaded_by, original_name, file_path, mime_type, file_size
        ) VALUES (?, ?, ?, ?, ?, ?, ?)
    ");

    $storeAttachments = static function (?int $progressStepId, array $files) use (
        $attachmentStmt,
        $destinationDirectory,
        $logId,
        $userId,
        $webPrefix
    ): array {
        $stored = [];
        foreach ($files as $file) {
            if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                continue;
            }

            $validated = validate_uploaded_file($file, 'task_document');
            $filename = build_safe_uploaded_filename($validated['extension'], 'progress-');
            $targetPath = $destinationDirectory . $filename;
            if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
                throw new RuntimeException('Unable to save one of the progress evidence files.');
            }

            $publicPath = rtrim($webPrefix, '/') . '/' . $filename;
            $attachmentStmt->execute([
                $logId,
                $progressStepId,
                $userId > 0 ? $userId : null,
                $validated['original_name'],
                $publicPath,
                $validated['mime_type'],
                $validated['size'],
            ]);

            $stored[] = [
                'progress_step_id' => $progressStepId,
                'original_name' => $validated['original_name'],
                'file_path' => $publicPath,
                'mime_type' => $validated['mime_type'],
                'file_size' => $validated['size'],
            ];
        }

        return $stored;
    };

    $storedAttachments = $storeAttachments(null, normalize_uploaded_files_array($entryFiles));
    foreach ($stepFilesBySlot as $slot => $files) {
        $progressStepId = $stepIdsByInputIndex[(int) $slot] ?? null;
        if ($progressStepId === null) {
            continue;
        }
        $storedAttachments = array_merge($storedAttachments, $storeAttachments($progressStepId, $files));
    }

    return [
        'id' => $logId,
        'attachments' => $storedAttachments,
        'steps' => $stepIdsByInputIndex,
    ];
}

function store_task_attachments(PDO $pdo, int $taskId, int $userId, array $files, string $category = 'general'): array
{
    $stored = [];
    require_once __DIR__ . '/project_pm_helper.php';

    $projStmt = $pdo->prepare('SELECT project_id FROM tasks WHERE id = ?');
    $projStmt->execute([$taskId]);
    $projectId = (int) $projStmt->fetchColumn();

    $webPrefix = 'uploads/tasks/';
    $destinationDirectory = __DIR__ . '/../uploads/tasks/';
    if ($projectId > 0) {
        try {
            $paths = ensure_project_storage_directory($projectId);
            $destinationDirectory = $paths['fs_attachments'] . DIRECTORY_SEPARATOR;
            $webPrefix = $paths['web_attachments_prefix'];
        } catch (Throwable $e) {
            error_log('store_task_attachments: ' . $e->getMessage());
        }
    }

    if (!is_dir($destinationDirectory) && !mkdir($destinationDirectory, 0755, true) && !is_dir($destinationDirectory)) {
        throw new RuntimeException('Unable to prepare the task upload directory.');
    }

    $insertStmt = $pdo->prepare("
        INSERT INTO task_attachments (task_id, uploaded_by, original_name, file_path, mime_type, file_size, attachment_category)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");

    foreach (normalize_uploaded_files_array($files) as $file) {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            continue;
        }

        $validated = validate_uploaded_file($file, 'task_document');
        $filename = build_safe_uploaded_filename($validated['extension'], 'task-');
        $targetPath = $destinationDirectory . $filename;

        if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
            throw new RuntimeException('Unable to save one of the uploaded attachments.');
        }

        $publicPath = rtrim($webPrefix, '/') . '/' . $filename;
        $insertStmt->execute([
            $taskId,
            $userId,
            $validated['original_name'],
            $publicPath,
            $validated['mime_type'],
            $validated['size'],
            $category,
        ]);

        $stored[] = [
            'original_name' => $validated['original_name'],
            'file_path' => $publicPath,
            'mime_type' => $validated['mime_type'],
            'file_size' => $validated['size'],
            'attachment_category' => $category,
        ];
    }

    return $stored;
}

function fetch_task_progress_logs(PDO $pdo, int $taskId, int $limit = 50): array
{
    if ($taskId <= 0 || !task_progress_tables_ready($pdo)) {
        return [];
    }

    $limit = max(1, min(200, $limit));
    $stmt = $pdo->prepare("
        SELECT tpl.*, COALESCE(u.name, 'Deleted User') AS user_name
        FROM task_progress_logs tpl
        LEFT JOIN users u ON u.id = tpl.user_id
        WHERE tpl.task_id = ?
        ORDER BY tpl.created_at DESC, tpl.id DESC
        LIMIT $limit
    ");
    $stmt->execute([$taskId]);
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (empty($logs)) {
        return [];
    }

    $logIds = array_values(array_unique(array_map(static function (array $row): int {
        return (int) ($row['id'] ?? 0);
    }, $logs)));
    $placeholders = implode(',', array_fill(0, count($logIds), '?'));

    $stepsStmt = $pdo->prepare("
        SELECT *
        FROM task_progress_log_steps
        WHERE progress_log_id IN ($placeholders)
        ORDER BY progress_log_id DESC, step_order ASC, id ASC
    ");
    $stepsStmt->execute($logIds);
    $stepRows = $stepsStmt->fetchAll(PDO::FETCH_ASSOC);

    $attachmentsStmt = $pdo->prepare("
        SELECT tpla.*, COALESCE(u.name, 'Deleted User') AS uploader_name
        FROM task_progress_log_attachments tpla
        LEFT JOIN users u ON u.id = tpla.uploaded_by
        WHERE tpla.progress_log_id IN ($placeholders)
        ORDER BY tpla.created_at ASC, tpla.id ASC
    ");
    $attachmentsStmt->execute($logIds);
    $attachmentRows = $attachmentsStmt->fetchAll(PDO::FETCH_ASSOC);

    $stepsByLog = [];
    foreach ($stepRows as $step) {
        $logId = (int) ($step['progress_log_id'] ?? 0);
        $stepId = (int) ($step['id'] ?? 0);
        $step['attachments'] = [];
        if (!isset($stepsByLog[$logId])) {
            $stepsByLog[$logId] = [];
        }
        $stepsByLog[$logId][$stepId] = $step;
    }

    $attachmentsByLog = [];
    $entryAttachmentsByLog = [];
    foreach ($attachmentRows as $attachment) {
        $logId = (int) ($attachment['progress_log_id'] ?? 0);
        $stepId = isset($attachment['progress_step_id']) ? (int) $attachment['progress_step_id'] : 0;
        if ($stepId > 0 && isset($stepsByLog[$logId][$stepId])) {
            $stepsByLog[$logId][$stepId]['attachments'][] = $attachment;
        } else {
            $entryAttachmentsByLog[$logId][] = $attachment;
        }
        $attachmentsByLog[$logId][] = $attachment;
    }

    foreach ($logs as &$log) {
        $logId = (int) ($log['id'] ?? 0);
        $log['steps'] = isset($stepsByLog[$logId]) ? array_values($stepsByLog[$logId]) : [];
        $log['attachments'] = $attachmentsByLog[$logId] ?? [];
        $log['entry_attachments'] = $entryAttachmentsByLog[$logId] ?? [];
        $log['attachment_count'] = count($log['attachments']);
        $log['has_status_change'] = trim((string) ($log['old_status'] ?? '')) !== trim((string) ($log['new_status'] ?? ''));
    }
    unset($log);

    return $logs;
}

function fetch_task_attachments(PDO $pdo, int $taskId, ?string $category = null): array
{
    $sql = "
        SELECT ta.*, COALESCE(u.name, 'Deleted User') AS uploader_name
        FROM task_attachments ta
        LEFT JOIN users u ON u.id = ta.uploaded_by
        WHERE ta.task_id = ?
    ";
    $params = [$taskId];

    if ($category !== null) {
        $sql .= " AND ta.attachment_category = ?";
        $params[] = $category;
    }

    $sql .= " ORDER BY ta.created_at DESC, ta.id DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function fetch_task_access_context(PDO $pdo, int $taskId, int $userId): ?array
{
    $stmt = $pdo->prepare("
        SELECT
            t.*,
            p.name AS project_name,
            p.created_by AS pm_id,
            p.visibility_scope,
            p.department_id AS project_department_id,
            p.budget_tracking_enabled,
            p.budget_amount,
            p.budget_currency,
            EXISTS(
                SELECT 1
                FROM task_assignees ta
                WHERE ta.task_id = t.id AND ta.user_id = ?
            ) AS is_task_assignee
        FROM tasks t
        JOIN projects p ON p.id = t.project_id
        WHERE t.id = ?
    ");
    $stmt->execute([$userId, $taskId]);
    $task = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$task) {
        return null;
    }

    require_once __DIR__ . '/project_visibility_helper.php';
    $projVisibility = [
        'id' => (int) ($task['project_id'] ?? 0),
        'visibility_scope' => $task['visibility_scope'] ?? null,
        'department_id' => isset($task['project_department_id']) ? $task['project_department_id'] : null,
        'created_by' => (int) ($task['pm_id'] ?? 0),
    ];
    if (!project_user_can_view_project($pdo, $userId, $projVisibility)) {
        return null;
    }

    $isPm = ((int) $task['pm_id'] === $userId);
    $isCreator = ((int) $task['created_by'] === $userId);
    $isAssignee = ((int) $task['assigned_to'] === $userId) || !empty($task['is_task_assignee']);

    $task['is_pm'] = $isPm;
    $task['is_creator'] = $isCreator;
    $task['is_assignee'] = $isAssignee;
    $task['can_edit'] = $isPm || $isCreator;
    $task['can_manage'] = $task['can_edit'] || $isAssignee;

    return $task;
}

/**
 * Delivery participants for a project (PM, task creators, assignees, legacy assigned_to).
 *
 * @return list<array{id:int,name:string,photo:?string,email:?string}>
 */
function fetch_delivery_participants_for_project(PDO $pdo, int $projectId): array
{
    static $projectTeamMembersTableExists = null;
    if ($projectTeamMembersTableExists === null) {
        try {
            $chk = $pdo->query(
                "SELECT 1 FROM information_schema.tables
                 WHERE table_schema = DATABASE() AND table_name = 'project_team_members' LIMIT 1"
            );
            $projectTeamMembersTableExists = (bool) $chk->fetchColumn();
        } catch (Throwable $e) {
            $projectTeamMembersTableExists = false;
        }
    }

    $memberUnion = $projectTeamMembersTableExists
        ? 'UNION
            SELECT ptm.user_id FROM project_team_members ptm WHERE ptm.project_id = ?'
        : '';

    $params = [$projectId, $projectId, $projectId, $projectId];
    if ($projectTeamMembersTableExists) {
        $params[] = $projectId;
    }

    $stmt = $pdo->prepare("
        SELECT DISTINCT u.id, u.name, u.photo, u.email
        FROM (
            SELECT p.created_by AS uid FROM projects p WHERE p.id = ? AND p.created_by IS NOT NULL
            UNION
            SELECT t.created_by FROM tasks t WHERE t.project_id = ? AND t.created_by IS NOT NULL
            UNION
            SELECT t.assigned_to FROM tasks t WHERE t.project_id = ? AND t.assigned_to IS NOT NULL
            UNION
            SELECT ta.user_id FROM task_assignees ta
            INNER JOIN tasks t ON t.id = ta.task_id
            WHERE t.project_id = ?
            $memberUnion
        ) ids
        INNER JOIN users u ON u.id = ids.uid AND ids.uid IS NOT NULL AND ids.uid > 0
        ORDER BY u.name ASC
    ");
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Delivery participants for a single task (PM, creator, assignees).
 *
 * @param array<string,mixed> $task Row must include pm_id / created_by / assigned_to / id as needed.
 * @return list<array{id:int,name:string,photo:?string,email:?string,roles:list<string>}>
 */
function fetch_delivery_participants_for_task(PDO $pdo, array $task): array
{
    $taskId = (int) ($task['id'] ?? 0);
    $assignees = fetch_task_assignees($pdo, $taskId);
    $mergedTask = array_merge($task, ['task_assignees' => $assignees]);
    $roleMap = fetch_task_notification_participants($pdo, $mergedTask);
    $ids = array_keys($roleMap);
    if ($ids === []) {
        return [];
    }
    sort($ids);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("SELECT id, name, photo, email FROM users WHERE id IN ($placeholders) ORDER BY name ASC");
    $stmt->execute($ids);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$row) {
        $uid = (int) $row['id'];
        $row['roles'] = $roleMap[$uid]['roles'] ?? [];
    }
    unset($row);

    return $rows;
}
