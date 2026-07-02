<?php

declare(strict_types=1);

/**
 * Project PM utilities: storage paths, activity log, budget helpers.
 */
function project_pm_helper_uploads_root(): string
{
    return dirname(__DIR__) . '/uploads/projects';
}

/**
 * @return array{
 *   fs_base: string,
 *   web_base: string,
 *   fs_attachments: string,
 *   web_attachments_prefix: string,
 *   fs_documentation: string,
 *   web_documentation_prefix: string,
 *   fs_discussion: string,
 *   web_discussion_prefix: string,
 *   fs_receipts: string,
 *   web_receipts_prefix: string
 * }
 */
function ensure_project_storage_directory(int $projectId): array
{
    if ($projectId < 1) {
        throw new InvalidArgumentException('Invalid project id.');
    }

    $root = project_pm_helper_uploads_root();
    $base = $root . DIRECTORY_SEPARATOR . $projectId;

    foreach (['attachments', 'documentation', 'receipts', 'discussion'] as $sub) {
        $dir = $base . DIRECTORY_SEPARATOR . $sub;
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException('Unable to create project storage: ' . $dir);
        }
    }

    $web = 'uploads/projects/' . $projectId;

    return [
        'fs_base' => $base,
        'web_base' => $web,
        'fs_attachments' => $base . DIRECTORY_SEPARATOR . 'attachments',
        'web_attachments_prefix' => $web . '/attachments/',
        'fs_documentation' => $base . DIRECTORY_SEPARATOR . 'documentation',
        'web_documentation_prefix' => $web . '/documentation/',
        'fs_discussion' => $base . DIRECTORY_SEPARATOR . 'discussion',
        'web_discussion_prefix' => $web . '/discussion/',
        'fs_receipts' => $base . DIRECTORY_SEPARATOR . 'receipts',
        'web_receipts_prefix' => $web . '/receipts/',
    ];
}

function project_budget_enabled(array $project): bool
{
    return !empty($project['budget_tracking_enabled']);
}

function user_can_manage_project_pm(PDO $pdo, int $userId, array $project): bool
{
    require_once __DIR__ . '/project_visibility_helper.php';

    return project_user_can_manage_project($pdo, $userId, $project);
}

function log_project_activity(
    PDO $pdo,
    int $projectId,
    int $userId,
    string $action,
    ?string $entityType = null,
    ?int $entityId = null,
    ?array $metadata = null
): void {
    if ($projectId < 1 || $userId < 1) {
        return;
    }

    try {
        $metaJson = $metadata !== null
            ? json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)
            : null;
        $stmt = $pdo->prepare(
            'INSERT INTO project_activity_log (project_id, user_id, action, entity_type, entity_id, metadata) VALUES (?,?,?,?,?,?)'
        );
        $stmt->execute([$projectId, $userId, $action, $entityType, $entityId, $metaJson]);
    } catch (Throwable $e) {
        error_log('log_project_activity failed: ' . $e->getMessage());
    }
}

/**
 * When a linked task is completed, stamp timeline actual/completed.
 */
function sync_project_timeline_for_completed_task(PDO $pdo, int $taskId): void
{
    if ($taskId < 1) {
        return;
    }

    try {
        $stmt = $pdo->prepare(
            'UPDATE project_timeline_items
             SET actual_date = COALESCE(actual_date, CURDATE()),
                 completed_at = COALESCE(completed_at, NOW())
             WHERE linked_task_id = ?'
        );
        $stmt->execute([$taskId]);
    } catch (Throwable $e) {
        error_log('sync_project_timeline_for_completed_task: ' . $e->getMessage());
    }
}

/**
 * Translates a project activity log action and its metadata JSON into human-readable HTML.
 */
function translate_project_activity(string $action, ?string $entityType, ?string $metadataJson): string
{
    $meta = [];
    if (!empty($metadataJson)) {
        $meta = json_decode($metadataJson, true) ?: [];
    }

    $entityType = $entityType ?? '';

    switch ($action) {
        case 'project.created':
            $name = htmlspecialchars($meta['name'] ?? 'Project');
            $status = htmlspecialchars($meta['status'] ?? 'Planning');
            $budget = !empty($meta['budget_tracking_enabled']) ? 'Enabled' : 'Disabled';
            $visibility = isset($meta['visibility_scope']) ? ucfirst(htmlspecialchars($meta['visibility_scope'])) : 'Department';
            return "Created the project <strong>{$name}</strong> (Status: <strong>{$status}</strong>, Budget Tracking: <strong>{$budget}</strong>, Visibility: <strong>{$visibility}</strong>).";

        case 'project.updated':
            $changes = $meta['changes'] ?? [];
            if (empty($changes)) {
                return "Updated the project settings.";
            }
            $lines = [];
            $fieldLabels = [
                'name' => 'Name',
                'description' => 'Description',
                'status' => 'Status',
                'priority' => 'Priority',
                'start_date' => 'Start Date',
                'end_date' => 'End Date',
                'budget_tracking_enabled' => 'Budget Tracking',
                'budget_amount' => 'Budget Amount',
                'budget_currency' => 'Budget Currency',
                'visibility_scope' => 'Visibility Scope',
                'department_id' => 'Department ID',
            ];
            foreach ($changes as $field => $change) {
                $label = $fieldLabels[$field] ?? ucfirst($field);
                $from = $change['from'] ?? null;
                $to = $change['to'] ?? null;
                if ($field === 'budget_tracking_enabled') {
                    $from = $from ? 'Enabled' : 'Disabled';
                    $to = $to ? 'Enabled' : 'Disabled';
                }
                $fromStr = $from !== null ? htmlspecialchars((string)$from) : 'None';
                $toStr = $to !== null ? htmlspecialchars((string)$to) : 'None';
                $lines[] = "changed <strong>{$label}</strong> from <em>\"{$fromStr}\"</em> to <em>\"{$toStr}\"</em>";
            }
            return "Updated project details: " . implode(', ', $lines) . ".";

        case 'project.team_invitation_sent':
            $invId = htmlspecialchars((string)($meta['invitation_id'] ?? ''));
            return "Sent a project team invitation" . ($invId !== '' ? " (Invitation ID: <strong>{$invId}</strong>)" : "") . ".";

        case 'project.team_invitation_accepted':
            $invId = htmlspecialchars((string)($meta['invitation_id'] ?? ''));
            return "Accepted the project team invitation" . ($invId !== '' ? " (Invitation ID: <strong>{$invId}</strong>)" : "") . ".";

        case 'task.created':
            $name = htmlspecialchars($meta['name'] ?? 'Task');
            $status = htmlspecialchars($meta['status'] ?? 'Pending');
            return "Created task <strong>{$name}</strong> (Status: <strong>{$status}</strong>).";

        case 'task.updated':
            $changes = $meta['changes'] ?? [];
            if (empty($changes)) {
                return "Updated the task details.";
            }
            $lines = [];
            $fieldLabels = [
                'name' => 'Name',
                'description' => 'Description',
                'status' => 'Status',
                'priority' => 'Priority',
                'due_date' => 'Due Date',
                'assignee_id' => 'Assignee ID',
            ];
            foreach ($changes as $field => $change) {
                $label = $fieldLabels[$field] ?? ucfirst($field);
                $fromStr = htmlspecialchars((string)($change['from'] ?? 'None'));
                $toStr = htmlspecialchars((string)($change['to'] ?? 'None'));
                $lines[] = "changed <strong>{$label}</strong> from <em>\"{$fromStr}\"</em> to <em>\"{$toStr}\"</em>";
            }
            return "Updated task details: " . implode(', ', $lines) . ".";

        case 'task.status_changed':
            $from = htmlspecialchars($meta['from'] ?? 'None');
            $to = htmlspecialchars($meta['to'] ?? 'None');
            return "Changed task status from <strong>{$from}</strong> to <strong>{$to}</strong>.";

        case 'task.progress_logged':
            $actionName = htmlspecialchars($meta['workflow_action'] ?? 'Update');
            $newStatus = htmlspecialchars($meta['new_status'] ?? 'Active');
            return "Logged progress on task: performed action <strong>{$actionName}</strong>, moving status to <strong>{$newStatus}</strong>.";

        case 'task.team_invitation_sent':
            $invId = htmlspecialchars((string)($meta['invitation_id'] ?? ''));
            return "Sent a task team invitation" . ($invId !== '' ? " (ID: <strong>{$invId}</strong>)" : "") . ".";

        case 'task.team_invitation_accepted':
            $invId = htmlspecialchars((string)($meta['invitation_id'] ?? ''));
            return "Accepted a task team invitation" . ($invId !== '' ? " (ID: <strong>{$invId}</strong>)" : "") . ".";

        case 'comment.posted':
            $hasAttachments = !empty($meta['has_attachments']);
            return "Posted a comment" . ($hasAttachments ? " with attachment(s)" : "") . ".";

        case 'file.uploaded':
            $path = $meta['path'] ?? '';
            $filename = $meta['original_name'] ?? ($path !== '' ? basename($path) : 'File');
            $filename = htmlspecialchars((string)$filename);
            switch ($entityType) {
                case 'project_comment_attachment':
                    return "Uploaded comment attachment: <strong>{$filename}</strong>";
                case 'project_file':
                    return "Uploaded project library file: <strong>{$filename}</strong>";
                case 'task_expense_receipt':
                    return "Uploaded task expense receipt: <strong>{$filename}</strong>";
                case 'task_progress_log_attachment':
                    return "Uploaded task progress attachment: <strong>{$filename}</strong>";
                case 'task_attachment':
                    return "Uploaded task attachment: <strong>{$filename}</strong>";
                case 'task_documentation':
                    return "Uploaded task documentation: <strong>{$filename}</strong>";
                default:
                    return "Uploaded file: <strong>{$filename}</strong>";
            }

        case 'project_file.deleted':
            return "Deleted a project library file.";

        case 'risk.created':
            $title = htmlspecialchars($meta['title'] ?? 'Risk');
            $status = htmlspecialchars($meta['status'] ?? 'Open');
            return "Created risk <strong>{$title}</strong> (Status: <strong>{$status}</strong>).";

        case 'risk.updated':
            $title = htmlspecialchars($meta['title'] ?? 'Risk');
            $status = htmlspecialchars($meta['status'] ?? 'Open');
            return "Updated risk <strong>{$title}</strong> (Status: <strong>{$status}</strong>).";

        case 'risk.deleted':
            return "Deleted a project risk.";

        case 'timeline.created':
            $title = htmlspecialchars($meta['title'] ?? 'Item');
            return "Created timeline item <strong>{$title}</strong>.";

        case 'timeline.updated':
            $title = htmlspecialchars($meta['title'] ?? 'Item');
            return "Updated timeline item <strong>{$title}</strong>.";

        case 'timeline.deleted':
            return "Deleted a timeline item.";

        case 'timeline.reordered':
            return "Reordered the project timeline.";

        case 'task_expense.created':
            $amount = htmlspecialchars((string)($meta['amount'] ?? '0.00'));
            return "Recorded a task expense of <strong>{$amount}</strong>.";

        case 'task_expense.deleted':
            return "Deleted a task expense.";

        default:
            $actionLabel = htmlspecialchars(ucfirst(str_replace('_', ' ', str_replace('.', ': ', $action))));
            return "Performed action: <strong>{$actionLabel}</strong>";
    }
}

