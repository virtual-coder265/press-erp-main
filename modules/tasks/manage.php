<?php
require_once __DIR__ . '/../../config/app.php';
checkAuth();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/task_management_helper.php';
require_once __DIR__ . '/../../includes/project_pm_helper.php';

function getTaskRequirementFlags(PDO $pdo, array $task): array
{
    if (array_key_exists('require_document_submission', $task) && array_key_exists('require_procedure_tracking', $task)) {
        return [
            'require_document_submission' => (int) !empty($task['require_document_submission']),
            'require_procedure_tracking' => (int) !empty($task['require_procedure_tracking']),
        ];
    }

    $stmt = $pdo->prepare("SELECT require_document_submission, require_procedure_tracking FROM projects WHERE id = ?");
    $stmt->execute([(int) $task['project_id']]);
    $project = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    return [
        'require_document_submission' => (int) !empty($project['require_document_submission']),
        'require_procedure_tracking' => (int) !empty($project['require_procedure_tracking']),
    ];
}

function task_manage_uploaded_file_count(array $files): int
{
    $count = 0;
    foreach (normalize_uploaded_files_array($files) as $file) {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $count++;
        }
    }

    return $count;
}

function task_manage_grouped_uploaded_file_count(array $filesByGroup): int
{
    $count = 0;
    foreach ($filesByGroup as $files) {
        foreach ($files as $file) {
            if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                $count++;
            }
        }
    }

    return $count;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('modules/tasks/list');
}

$taskId = (int) ($_POST['task_id'] ?? 0);
$userId = (int) ($_SESSION['user_id'] ?? 0);
$task = fetch_task_access_context($pdo, $taskId, $userId);

if (!$task) {
    redirect('modules/tasks/list?error=task_not_found');
}

if (empty($task['can_manage'])) {
    redirect('modules/tasks/list?error=access_denied');
}

$status = $_POST['status'] ?? $task['status'];
$workflowAction = $_POST['workflow_action'] ?? 'save_progress';
$progressEntry = [
    'old_status' => (string) ($task['status'] ?? 'Not Started'),
    'new_status' => (string) $status,
    'work_done' => trim((string) ($_POST['progress_work_done'] ?? '')),
    'next_work' => trim((string) ($_POST['progress_next_work'] ?? '')),
    'outcome_text' => trim((string) ($_POST['progress_outcome'] ?? '')),
];
$progressSteps = normalize_task_progress_log_steps(
    $_POST['progress_step_procedure'] ?? [],
    $_POST['progress_step_output'] ?? []
);
$progressEntryFiles = isset($_FILES['progress_entry_attachments']) && is_array($_FILES['progress_entry_attachments'])
    ? $_FILES['progress_entry_attachments']
    : [];
$progressStepFilesBySlot = isset($_FILES['progress_step_attachments']) && is_array($_FILES['progress_step_attachments'])
    ? normalize_uploaded_file_groups($_FILES['progress_step_attachments'])
    : [];
$referenceFiles = isset($_FILES['task_reference_attachments']) && is_array($_FILES['task_reference_attachments'])
    ? $_FILES['task_reference_attachments']
    : (isset($_FILES['task_attachments']) && is_array($_FILES['task_attachments']) ? $_FILES['task_attachments'] : []);
$requirements = getTaskRequirementFlags($pdo, $task);

if ($workflowAction === 'submit_for_review') {
    $status = 'In Review';
    $progressEntry['new_status'] = 'In Review';
}

try {
    if ($status === 'Completed' && empty($task['is_pm'])) {
        throw new RuntimeException('Use "Submit for Approval" to send this task to the project manager for sign-off.');
    }

    foreach ($progressSteps as $step) {
        if (trim((string) ($step['procedure_text'] ?? '')) === '') {
            throw new RuntimeException('Each recorded procedure row needs a procedure description.');
        }
    }

    foreach ($progressStepFilesBySlot as $slot => $files) {
        $hasFiles = false;
        foreach ($files as $file) {
            if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                $hasFiles = true;
                break;
            }
        }

        if (!$hasFiles) {
            continue;
        }

        $slotHasRecordedStep = false;
        foreach ($progressSteps as $step) {
            if ((int) ($step['input_index'] ?? -1) === (int) $slot) {
                $slotHasRecordedStep = true;
                break;
            }
        }

        if (!$slotHasRecordedStep) {
            throw new RuntimeException('Each procedure evidence row needs a procedure or result before attachments can be added.');
        }
    }

    if (!task_progress_log_has_payload($progressEntry, $progressSteps, $progressEntryFiles, $progressStepFilesBySlot)) {
        throw new RuntimeException('Record what was done, the next step, an outcome, a procedure row, or attach evidence before saving.');
    }

    if ($requirements['require_procedure_tracking'] && empty($progressSteps)) {
        throw new RuntimeException('At least one executed procedure is required for this task.');
    }

    $entryEvidenceCount = task_manage_uploaded_file_count($progressEntryFiles);
    $stepEvidenceCount = task_manage_grouped_uploaded_file_count($progressStepFilesBySlot);
    $requiresCurrentEntryEvidence = !empty($requirements['require_document_submission'])
        && ($task['status'] !== $status || $workflowAction === 'submit_for_review');

    if ($requiresCurrentEntryEvidence && ($entryEvidenceCount + $stepEvidenceCount) < 1) {
        throw new RuntimeException('Attach at least one evidence file before changing status or submitting for review.');
    }

    $pdo->beginTransaction();

    $statusPayload = build_task_status_update_payload($task, $status, !empty($task['is_pm']), $userId);

    $stmt = $pdo->prepare("
        UPDATE tasks
        SET status = ?, completed_at = ?, approved_by = ?, approved_at = ?, score = ?
        WHERE id = ?
    ");
    $stmt->execute([
        $status,
        $statusPayload['completed_at'],
        $statusPayload['approved_by'],
        $statusPayload['approved_at'],
        $statusPayload['score'],
        $taskId,
    ]);

    $progressLog = store_task_progress_log(
        $pdo,
        $taskId,
        $userId,
        $progressEntry,
        $progressSteps,
        $progressEntryFiles,
        $progressStepFilesBySlot
    );
    $referenceAttachments = store_task_attachments($pdo, $taskId, $userId, $referenceFiles);

    $workflowEvent = null;
    if ($task['status'] !== $status) {
        $workflowEvent = ($workflowAction === 'submit_for_review' || $status === 'In Review')
            ? 'submitted_for_review'
            : ($status === 'Completed' && !empty($task['is_pm']) ? 'approved' : 'status_changed');
    }

    $pdo->commit();

    if ($task['status'] !== $status && $status === 'Completed' && !empty($task['is_pm'])) {
        sync_project_timeline_for_completed_task($pdo, $taskId);
    }

    run_best_effort_task_side_effect(
        'Task progress activity logging failed for task #' . $taskId,
        static function () use ($pdo, $task, $userId, $progressLog, $workflowAction, $status): void {
            log_project_activity($pdo, (int) $task['project_id'], $userId, 'task.progress_logged', 'task_progress_log', (int) ($progressLog['id'] ?? 0), [
                'workflow_action' => $workflowAction,
                'new_status' => $status,
            ]);
        }
    );

    foreach (($progressLog['attachments'] ?? []) as $attachment) {
        run_best_effort_task_side_effect(
            'Task progress attachment logging failed for task #' . $taskId,
            static function () use ($pdo, $task, $userId, $attachment): void {
                log_project_activity($pdo, (int) $task['project_id'], $userId, 'file.uploaded', 'task_progress_log_attachment', null, [
                    'path' => $attachment['file_path'] ?? '',
                    'original_name' => $attachment['original_name'] ?? '',
                ]);
            }
        );
    }

    foreach ($referenceAttachments as $attachment) {
        run_best_effort_task_side_effect(
            'Task reference attachment logging failed for task #' . $taskId,
            static function () use ($pdo, $task, $userId, $attachment): void {
                log_project_activity($pdo, (int) $task['project_id'], $userId, 'file.uploaded', 'task_attachment', null, [
                    'path' => $attachment['file_path'] ?? '',
                    'original_name' => $attachment['original_name'] ?? '',
                ]);
            }
        );
    }

    if ($workflowEvent !== null) {
        run_best_effort_task_side_effect(
            'Task workflow notification failed for task #' . $taskId,
            static function () use ($pdo, $task, $status, $userId, $workflowEvent, $taskId): void {
                notify_task_workflow_event(
                    $pdo,
                    array_merge($task, ['status' => $status]),
                    $userId,
                    $workflowEvent,
                    [
                        'old_status' => $task['status'],
                        'new_status' => $status,
                        'link' => 'modules/tasks/view?id=' . $taskId,
                    ]
                );
            }
        );
    }

    if ($workflowAction === 'submit_for_review') {
        redirect('modules/tasks/view?id=' . $taskId . '&notice=submitted_for_review');
    }

    redirect('modules/tasks/view?id=' . $taskId . '&success=work_updated');
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log('Task workflow update failed for task #' . $taskId . ': ' . $e->getMessage());
    redirect('modules/tasks/view?id=' . $taskId . '&error=' . urlencode(
        normalize_workflow_exception_message(
            $e->getMessage(),
            'Unable to update the task right now.'
        )
    ));
}
