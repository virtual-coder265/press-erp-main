<?php
require_once __DIR__ . '/../../config/app.php';
checkAuth();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/upload_helper.php';
require_once __DIR__ . '/../../includes/task_management_helper.php';
require_once __DIR__ . '/../../includes/reminder_helper.php';
require_once __DIR__ . '/../../includes/project_pm_helper.php';
require_once __DIR__ . '/../../includes/project_visibility_helper.php';

function task_save_is_ajax(): bool
{
    return strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';
}

function task_save_json(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

function storeTaskDocumentUpload(array $file, int $projectId): ?string
{
    if (empty($file) || !isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($projectId < 1) {
        return store_validated_uploaded_file(
            $file,
            'task_document',
            __DIR__ . '/../../uploads/tasks/',
            'uploads/tasks/'
        );
    }
    $paths = ensure_project_storage_directory($projectId);

    return store_validated_uploaded_file(
        $file,
        'task_document',
        $paths['fs_documentation'] . DIRECTORY_SEPARATOR,
        $paths['web_documentation_prefix']
    );
}

function notifyTaskAssignees(
    PDO $pdo,
    int $actorId,
    array $assigneeIds,
    ?int $primaryAssigneeId,
    string $taskName,
    ?string $dueDate,
    string $priority,
    int $taskId,
    string $title,
    bool $isUpdate
): void {
    $assigneeIds = normalize_selected_user_ids($assigneeIds);
    if (empty($assigneeIds)) {
        return;
    }

    require_once __DIR__ . '/../../libs/NotificationManager.php';
    $notifManager = new NotificationManager($pdo);
    $assignerStmt = $pdo->prepare("SELECT name FROM users WHERE id = :id");
    $assignerStmt->execute(['id' => $actorId]);
    $assigner = (string) $assignerStmt->fetchColumn();
    $dueDateFormatted = !empty($dueDate) ? date('d M Y', strtotime($dueDate)) : null;

    foreach ($assigneeIds as $assigneeId) {
        $roleLabel = ((int) $primaryAssigneeId === (int) $assigneeId) ? 'primary owner' : 'collaborator';
        $description = $assigner . ' assigned "' . $taskName . '" to you as ' . $roleLabel;
        if ($dueDateFormatted) {
            $description .= ', due ' . $dueDateFormatted;
        }
        $description .= '.';

        $notifManager->notify(
            $assigneeId,
            'task',
            $title,
            $description,
            'modules/tasks/list?id=' . $taskId,
            $taskId,
            $isUpdate,
            false,
            [
                'assigner' => $assigner,
                'taskTitle' => $taskName,
                'dueDate' => $dueDate,
                'priority' => $priority,
                'assignmentRole' => $roleLabel,
            ]
        );
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $name = $_POST['name'] ?? '';
    $project_id = $_POST['project_id'] ?? 0;
    $description = $_POST['description'] ?? '';
    $status = $_POST['status'] ?? 'Not Started';
    $priority = $_POST['priority'] ?? 'Medium';
    $due_date = !empty($_POST['due_date']) ? $_POST['due_date'] : null;
    $user_id = $_SESSION['user_id'];
    $project_defaults = [];
    $assignee_ids = normalize_selected_user_ids($_POST['assignee_ids'] ?? []);
    if (empty($assignee_ids) && !empty($_POST['assigned_to'])) {
        $assignee_ids = normalize_selected_user_ids([$_POST['assigned_to']]);
    }
    $requested_primary_assignee = $_POST['primary_assignee_id'] ?? ($_POST['assigned_to'] ?? null);
    $primary_assignee_id = determine_primary_assignee($assignee_ids, $requested_primary_assignee);
    $assigned_to = $primary_assignee_id;
    $procedure_steps = normalize_task_procedure_steps(
        $_POST['procedure_step_instruction'] ?? [],
        $_POST['procedure_step_note'] ?? []
    );
    $procedure_summary = summarize_procedure_steps($procedure_steps);

    if (empty($name) || empty($project_id)) {
        if (task_save_is_ajax()) {
            task_save_json(['ok' => false, 'error' => 'Task name and project are required.'], 422);
        }
        redirect('modules/tasks/list?error=name_and_project_required');
    }

    try {
        reminder_module_ready($pdo, true);

        $projGateStmt = $pdo->prepare('SELECT * FROM projects WHERE id = ? LIMIT 1');
        $projGateStmt->execute([(int) $project_id]);
        $projGateRow = $projGateStmt->fetch(PDO::FETCH_ASSOC);
        if (!$projGateRow) {
            throw new RuntimeException('Selected project was not found.');
        }
        $project_created_by = (int) ($projGateRow['created_by'] ?? 0);
        $can_act_as_project_manager = project_user_can_manage_project($pdo, (int) $user_id, $projGateRow);
        $is_pm = $can_act_as_project_manager;
        $project_requirements = [
            'require_document_submission' => !empty($projGateRow['require_document_submission']),
            'require_procedure_tracking' => !empty($projGateRow['require_procedure_tracking']),
        ];

        if ($action === 'create' && !$can_act_as_project_manager) {
            throw new RuntimeException('You do not have permission to create tasks in this project.');
        }

        if ($status === 'Completed' && !$can_act_as_project_manager) {
            throw new RuntimeException('Only the project manager can move a task directly to Completed.');
        }

        if ($action === 'update') {
            $accessContext = fetch_task_access_context($pdo, (int) ($_POST['id'] ?? 0), (int) $user_id);
            if (!$accessContext) {
                throw new RuntimeException('Task not found.');
            }
            if (empty($accessContext['can_edit'])) {
                throw new RuntimeException('Only the task creator or project manager can edit this task.');
            }
        }

        $require_document_submission = isset($_POST['require_document_submission'])
            ? (int) !empty($_POST['require_document_submission'])
            : (int) $project_requirements['require_document_submission'];
        $require_procedure_tracking = isset($_POST['require_procedure_tracking'])
            ? (int) !empty($_POST['require_procedure_tracking'])
            : (int) $project_requirements['require_procedure_tracking'];
        $old_status = null;
        $task_id = null;
        $old_assignee_ids = [];
        $old_primary_assignee_id = null;
        $old_task = null;

        $pdo->beginTransaction();

        $status_payload = [
            'completed_at' => null,
            'approved_by' => null,
            'approved_at' => null,
            'score' => null,
        ];
        if ($action === 'update') {
            $stmt = $pdo->prepare("SELECT status, approved_by, approved_at, completed_at, score FROM tasks WHERE id = ?");
            $stmt->execute([$_POST['id']]);
            $existing_task = $stmt->fetch(PDO::FETCH_ASSOC);
            $old_status = $existing_task['status'];

            // Block reverting a PM-approved (Completed) task by non-PM
            if ($existing_task['approved_by'] && !$is_pm && $old_status === 'Completed') {
                throw new RuntimeException('This task has been approved by the Project Manager and cannot be changed.');
            }

            $status_payload = build_task_status_update_payload($existing_task, $status, $is_pm, (int) $user_id);

            $old_task_id = (int) $_POST['id'];
            $old_assignees = fetch_task_assignees($pdo, $old_task_id);
            $old_assignee_ids = array_map(static function (array $assignee): int {
                return (int) $assignee['user_id'];
            }, $old_assignees);
            foreach ($old_assignees as $oldAssignee) {
                if (!empty($oldAssignee['is_primary'])) {
                    $old_primary_assignee_id = (int) $oldAssignee['user_id'];
                    break;
                }
            }
        } else {
            $status_payload = build_task_status_update_payload(
                ['status' => null, 'completed_at' => null, 'approved_by' => null, 'approved_at' => null, 'score' => null],
                $status,
                $is_pm,
                (int) $user_id
            );
        }

        $requires_documentation = false;
        if ($action === 'create') {
            $requires_documentation = $status !== 'Not Started' && (
                $require_document_submission ||
                $require_procedure_tracking
            );
        } elseif ($action === 'update') {
            $requires_documentation = $old_status !== $status && (
                $require_document_submission ||
                $require_procedure_tracking
            );
        }

        if ($require_procedure_tracking && empty($procedure_steps)) {
            throw new RuntimeException('At least one procedure step is required for this task.');
        }

        if (
            $requires_documentation &&
            $require_document_submission &&
            (!isset($_FILES['documentation_file']) || $_FILES['documentation_file']['error'] === UPLOAD_ERR_NO_FILE)
        ) {
            throw new RuntimeException('A supporting document is required for this task.');
        }

        $doc_path = storeTaskDocumentUpload($_FILES['documentation_file'] ?? [], (int) $project_id);
        $myAlarmEnabled = isset($_POST['my_alarm_enabled']) ? (int) $_POST['my_alarm_enabled'] : null;
        $myAlarmOffset = (int) ($_POST['my_alarm_offset_minutes'] ?? 30);

        if ($action === 'create') {
            $stmt = $pdo->prepare("
                INSERT INTO tasks (
                    project_id, name, description, status, priority, assigned_to, due_date, created_by, completed_at,
                    require_document_submission, require_procedure_tracking, approved_by, approved_at, score
                )
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $project_id,
                $name,
                $description,
                $status,
                $priority,
                $assigned_to,
                $due_date,
                $user_id,
                $status_payload['completed_at'],
                $require_document_submission,
                $require_procedure_tracking,
                $status_payload['approved_by'],
                $status_payload['approved_at'],
                $status_payload['score'],
            ]);
            $task_id = $pdo->lastInsertId();
            sync_task_assignees($pdo, (int) $task_id, $assignee_ids, (int) $user_id, $primary_assignee_id);
            sync_task_procedure_steps($pdo, (int) $task_id, $procedure_steps, (int) $user_id);
            $newAttachments = store_task_attachments($pdo, (int) $task_id, (int) $user_id, $_FILES['task_attachments'] ?? []);

            if ((!empty($procedure_summary) || !empty($doc_path)) && $status !== 'Not Started') {
                $docStmt = $pdo->prepare("INSERT INTO task_documentation (task_id, user_id, old_status, new_status, documentation_text, document_path) VALUES (?, ?, ?, ?, ?, ?)");
                $docStmt->execute([$task_id, $user_id, null, $status, $procedure_summary, $doc_path]);
            }

            $pdo->commit();

            if ($status === 'Completed') {
                sync_project_timeline_for_completed_task($pdo, (int) $task_id);
            }
            log_project_activity($pdo, (int) $project_id, (int) $user_id, 'task.created', 'task', (int) $task_id, [
                'name' => $name,
                'status' => $status,
            ]);
            if (!empty($doc_path)) {
                log_project_activity($pdo, (int) $project_id, (int) $user_id, 'file.uploaded', 'task_documentation', (int) $task_id, [
                    'path' => $doc_path,
                ]);
            }
            foreach ($newAttachments as $na) {
                log_project_activity($pdo, (int) $project_id, (int) $user_id, 'file.uploaded', 'task_attachment', (int) $task_id, [
                    'path' => $na['file_path'] ?? '',
                    'original_name' => $na['original_name'] ?? '',
                ]);
            }

            run_best_effort_task_side_effect(
                'Task assignment notification failed for task #' . (int) $task_id,
                static function () use ($pdo, $user_id, $assignee_ids, $primary_assignee_id, $name, $due_date, $priority, $task_id): void {
                    notifyTaskAssignees(
                        $pdo,
                        (int) $user_id,
                        $assignee_ids,
                        $primary_assignee_id,
                        $name,
                        $due_date,
                        $priority,
                        (int) $task_id,
                        'New Task Assigned',
                        false
                    );
                }
            );

            if ($status === 'In Review') {
                run_best_effort_task_side_effect(
                    'Task workflow notification failed for task #' . (int) $task_id,
                    static function () use ($pdo, $task_id, $name, $project_id, $user_id, $project_created_by, $status): void {
                        notify_task_workflow_event(
                            $pdo,
                            [
                                'id' => (int) $task_id,
                                'name' => $name,
                                'project_id' => (int) $project_id,
                                'created_by' => (int) $user_id,
                                'pm_id' => $project_created_by,
                                'task_assignees' => fetch_task_assignees($pdo, (int) $task_id),
                                'status' => $status,
                            ],
                            (int) $user_id,
                            'submitted_for_review',
                            [
                                'new_status' => $status,
                                'link' => 'modules/tasks/view?id=' . (int) $task_id,
                            ]
                        );
                    }
                );
            } elseif ($status === 'Completed' && $is_pm) {
                run_best_effort_task_side_effect(
                    'Task workflow notification failed for task #' . (int) $task_id,
                    static function () use ($pdo, $task_id, $name, $project_id, $user_id, $project_created_by, $status): void {
                        notify_task_workflow_event(
                            $pdo,
                            [
                                'id' => (int) $task_id,
                                'name' => $name,
                                'project_id' => (int) $project_id,
                                'created_by' => (int) $user_id,
                                'pm_id' => $project_created_by,
                                'task_assignees' => fetch_task_assignees($pdo, (int) $task_id),
                                'status' => $status,
                            ],
                            (int) $user_id,
                            'approved',
                            [
                                'new_status' => $status,
                                'link' => 'modules/tasks/view?id=' . (int) $task_id,
                            ]
                        );
                    }
                );
            }

            run_best_effort_task_side_effect(
                'Task reminder sync failed for task #' . (int) $task_id,
                static function () use ($pdo, $task_id, $user_id, $myAlarmEnabled, $myAlarmOffset): void {
                    sync_task_assignment_reminders_for_task($pdo, (int) $task_id, (int) $user_id);

                    if ($myAlarmEnabled === null) {
                        return;
                    }

                    $remStmt = $pdo->prepare("SELECT id FROM reminders WHERE user_id = ? AND task_id = ? AND source = 'task_assignment'");
                    $remStmt->execute([(int) $user_id, (int) $task_id]);
                    $remId = (int) $remStmt->fetchColumn();
                    if ($remId > 0) {
                        update_reminder_alarm_settings($pdo, (int) $user_id, $remId, [
                            'alarm_enabled' => $myAlarmEnabled,
                            'alarm_offset_minutes' => $myAlarmOffset,
                        ]);
                    }
                }
            );

            if (task_save_is_ajax()) {
                task_save_json([
                    'ok' => true,
                    'id' => (int) $task_id,
                    'title' => 'Task created',
                    'message' => 'Task created',
                    'open_url' => BASE_URL . 'modules/tasks/view?id=' . (int) $task_id,
                ]);
            }

            redirect('modules/tasks/list?success=task_created');
        } elseif ($action === 'update') {
            $id = $_POST['id'] ?? 0;

            $oldTaskStmt = $pdo->prepare("SELECT assigned_to, name, created_by, due_date FROM tasks WHERE id = ?");
            $oldTaskStmt->execute([$id]);
            $old_task = $oldTaskStmt->fetch();

            $stmt = $pdo->prepare("
                UPDATE tasks
                SET project_id = ?, name = ?, description = ?, status = ?, priority = ?, assigned_to = ?, due_date = ?, completed_at = ?,
                    approved_by = ?, approved_at = ?, score = ?,
                    require_document_submission = ?, require_procedure_tracking = ?
                WHERE id = ?
            ");
            $stmt->execute([
                $project_id,
                $name,
                $description,
                $status,
                $priority,
                $assigned_to,
                $due_date,
                $status_payload['completed_at'],
                $status_payload['approved_by'],
                $status_payload['approved_at'],
                $status_payload['score'],
                $require_document_submission,
                $require_procedure_tracking,
                $id,
            ]);
            sync_task_assignees($pdo, (int) $id, $assignee_ids, (int) $user_id, $primary_assignee_id);
            sync_task_procedure_steps($pdo, (int) $id, $procedure_steps, (int) $user_id);
            $addedAttachments = store_task_attachments($pdo, (int) $id, (int) $user_id, $_FILES['task_attachments'] ?? []);

            // Handle Documentation
            if (isset($old_status) && $old_status != $status) {
                if (!empty($procedure_summary) || !empty($doc_path)) {
                    $docStmt = $pdo->prepare("INSERT INTO task_documentation (task_id, user_id, old_status, new_status, documentation_text, document_path) VALUES (?, ?, ?, ?, ?, ?)");
                    $docStmt->execute([$id, $user_id, $old_status, $status, $procedure_summary, $doc_path]);
                }
            }
            $pdo->commit();

            if ($status === 'Completed' && isset($old_status) && $old_status !== 'Completed') {
                sync_project_timeline_for_completed_task($pdo, (int) $id);
            }
            if (isset($old_status) && $old_status !== $status) {
                log_project_activity($pdo, (int) $project_id, (int) $user_id, 'task.status_changed', 'task', (int) $id, [
                    'from' => $old_status,
                    'to' => $status,
                ]);
            }
            $fieldChanges = [];
            if (is_array($old_task)) {
                if ((string) ($old_task['name'] ?? '') !== (string) $name) {
                    $fieldChanges['name'] = ['from' => $old_task['name'], 'to' => $name];
                }
                if ((int) ($old_task['assigned_to'] ?? 0) !== (int) $assigned_to) {
                    $fieldChanges['assigned_to'] = ['from' => $old_task['assigned_to'], 'to' => $assigned_to];
                }
                $oldDue = $old_task['due_date'] ? date('Y-m-d', strtotime((string) $old_task['due_date'])) : '';
                $newDue = $due_date ? date('Y-m-d', strtotime((string) $due_date)) : '';
                if ($oldDue !== $newDue) {
                    $fieldChanges['due_date'] = ['from' => $old_task['due_date'], 'to' => $due_date];
                }
            }
            if (!empty($fieldChanges)) {
                log_project_activity($pdo, (int) $project_id, (int) $user_id, 'task.updated', 'task', (int) $id, [
                    'changes' => $fieldChanges,
                ]);
            }
            if (isset($old_status) && $old_status != $status && !empty($doc_path)) {
                log_project_activity($pdo, (int) $project_id, (int) $user_id, 'file.uploaded', 'task_documentation', (int) $id, [
                    'path' => $doc_path,
                ]);
            }
            foreach ($addedAttachments as $na) {
                log_project_activity($pdo, (int) $project_id, (int) $user_id, 'file.uploaded', 'task_attachment', (int) $id, [
                    'path' => $na['file_path'] ?? '',
                    'original_name' => $na['original_name'] ?? '',
                ]);
            }

            $newlyAssignedIds = array_values(array_diff($assignee_ids, $old_assignee_ids));
            $primaryChanged = $primary_assignee_id !== $old_primary_assignee_id;
            if (!empty($newlyAssignedIds) || $primaryChanged) {
                $notifyIds = !empty($newlyAssignedIds)
                    ? array_values(array_unique(array_merge($newlyAssignedIds, $primaryChanged && $primary_assignee_id ? [$primary_assignee_id] : [])))
                    : ($primary_assignee_id ? [$primary_assignee_id] : []);

                run_best_effort_task_side_effect(
                    'Task assignment notification failed for task #' . (int) $id,
                    static function () use ($pdo, $user_id, $notifyIds, $primary_assignee_id, $name, $due_date, $priority, $id): void {
                        notifyTaskAssignees(
                            $pdo,
                            (int) $user_id,
                            $notifyIds,
                            $primary_assignee_id,
                            $name,
                            $due_date,
                            $priority,
                            (int) $id,
                            'Task Assignment Updated',
                            true
                        );
                    }
                );
            }

            if (isset($old_status) && $old_status !== $status) {
                $event = $status === 'In Review'
                    ? 'submitted_for_review'
                    : ($status === 'Completed' && $is_pm ? 'approved' : 'status_changed');

                run_best_effort_task_side_effect(
                    'Task workflow notification failed for task #' . (int) $id,
                    static function () use ($pdo, $id, $name, $project_id, $old_task, $user_id, $project_created_by, $status, $event, $old_status): void {
                        notify_task_workflow_event(
                            $pdo,
                            [
                                'id' => (int) $id,
                                'name' => $name,
                                'project_id' => (int) $project_id,
                                'created_by' => (int) ($old_task['created_by'] ?? $user_id),
                                'pm_id' => $project_created_by,
                                'task_assignees' => fetch_task_assignees($pdo, (int) $id),
                                'status' => $status,
                            ],
                            (int) $user_id,
                            $event,
                            [
                                'old_status' => $old_status,
                                'new_status' => $status,
                                'link' => 'modules/tasks/view?id=' . (int) $id,
                            ]
                        );
                    }
                );
            }

            run_best_effort_task_side_effect(
                'Task reminder sync failed for task #' . (int) $id,
                static function () use ($pdo, $id, $user_id, $myAlarmEnabled, $myAlarmOffset): void {
                    sync_task_assignment_reminders_for_task($pdo, (int) $id, (int) $user_id);

                    if ($myAlarmEnabled === null) {
                        return;
                    }

                    $remStmt = $pdo->prepare("SELECT id FROM reminders WHERE user_id = ? AND task_id = ? AND source = 'task_assignment'");
                    $remStmt->execute([(int) $user_id, (int) $id]);
                    $remId = (int) $remStmt->fetchColumn();
                    if ($remId > 0) {
                        update_reminder_alarm_settings($pdo, (int) $user_id, $remId, [
                            'alarm_enabled' => $myAlarmEnabled,
                            'alarm_offset_minutes' => $myAlarmOffset,
                        ]);
                    }
                }
            );

            redirect('modules/tasks/list?success=task_updated');
        }
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Task save failed: ' . $e->getMessage());
        if (task_save_is_ajax()) {
            task_save_json([
                'ok' => false,
                'error' => normalize_workflow_exception_message(
                    $e->getMessage(),
                    'Unable to save the task right now.'
                ),
            ], 422);
        }
        redirect('modules/tasks/list?error=' . urlencode(
            normalize_workflow_exception_message(
                $e->getMessage(),
                'Unable to save the task right now.'
            )
        ));
    }
} else {
    redirect('modules/tasks/list');
}
?>
