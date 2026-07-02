<?php

require_once __DIR__ . '/../../config/app.php';
checkAuth();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/project_pm_helper.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('modules/projects/list');
}

$riskAction = (string) ($_POST['risk_action'] ?? '');
$projectId = (int) ($_POST['project_id'] ?? 0);
$userId = (int) ($_SESSION['user_id'] ?? 0);

$pstmt = $pdo->prepare('SELECT * FROM projects WHERE id = ? LIMIT 1');
$pstmt->execute([$projectId]);
$project = $pstmt->fetch(PDO::FETCH_ASSOC);
$baseRedirect = 'modules/projects/view?id=' . $projectId;

if (!$project || !user_can_manage_project_pm($pdo, $userId, $project)) {
    redirect('modules/projects/list?error=risk_denied');
}

$allowedStatus = ['Open', 'Mitigating', 'Resolved', 'Accepted', 'Closed'];

try {
    if ($riskAction === 'create') {
        $title = trim((string) ($_POST['title'] ?? ''));
        if ($title === '') {
            redirect($baseRedirect . '&error=risk_fields');
        }
        $description = trim((string) ($_POST['description'] ?? '')) ?: null;
        $mitigation = trim((string) ($_POST['mitigation'] ?? '')) ?: null;
        $solution = trim((string) ($_POST['solution_applied'] ?? '')) ?: null;
        $status = (string) ($_POST['status'] ?? 'Open');
        if (!in_array($status, $allowedStatus, true)) {
            $status = 'Open';
        }
        $taskId = (int) ($_POST['task_id'] ?? 0);
        if ($taskId > 0) {
            $chk = $pdo->prepare('SELECT id FROM tasks WHERE id = ? AND project_id = ?');
            $chk->execute([$taskId, $projectId]);
            if (!$chk->fetch()) {
                $taskId = null;
            }
        } else {
            $taskId = null;
        }

        $ins = $pdo->prepare(
            'INSERT INTO project_risks (project_id, task_id, title, description, mitigation, solution_applied, status, reported_by)
             VALUES (?,?,?,?,?,?,?,?)'
        );
        $ins->execute([$projectId, $taskId, $title, $description, $mitigation, $solution, $status, $userId]);
        $newId = (int) $pdo->lastInsertId();
        log_project_activity($pdo, $projectId, $userId, 'risk.created', 'project_risk', $newId, ['title' => $title, 'status' => $status]);
    } elseif ($riskAction === 'update') {
        $rid = (int) ($_POST['id'] ?? 0);
        $title = trim((string) ($_POST['title'] ?? ''));
        if ($rid < 1 || $title === '') {
            redirect($baseRedirect . '&error=risk_fields');
        }
        $description = trim((string) ($_POST['description'] ?? '')) ?: null;
        $mitigation = trim((string) ($_POST['mitigation'] ?? '')) ?: null;
        $solution = trim((string) ($_POST['solution_applied'] ?? '')) ?: null;
        $status = (string) ($_POST['status'] ?? 'Open');
        if (!in_array($status, $allowedStatus, true)) {
            $status = 'Open';
        }
        $taskId = (int) ($_POST['task_id'] ?? 0);
        if ($taskId > 0) {
            $chk = $pdo->prepare('SELECT id FROM tasks WHERE id = ? AND project_id = ?');
            $chk->execute([$taskId, $projectId]);
            if (!$chk->fetch()) {
                $taskId = null;
            }
        } else {
            $taskId = null;
        }

        $pdo->prepare(
            'UPDATE project_risks SET task_id = ?, title = ?, description = ?, mitigation = ?, solution_applied = ?, status = ?
             WHERE id = ? AND project_id = ?'
        )->execute([$taskId, $title, $description, $mitigation, $solution, $status, $rid, $projectId]);

        log_project_activity($pdo, $projectId, $userId, 'risk.updated', 'project_risk', $rid, ['title' => $title, 'status' => $status]);
    } elseif ($riskAction === 'delete') {
        $rid = (int) ($_POST['id'] ?? 0);
        if ($rid < 1) {
            redirect($baseRedirect . '&error=risk_delete');
        }
        $pdo->prepare('DELETE FROM project_risks WHERE id = ? AND project_id = ?')->execute([$rid, $projectId]);
        log_project_activity($pdo, $projectId, $userId, 'risk.deleted', 'project_risk', $rid, []);
    }
} catch (Throwable $e) {
    error_log('risk_save: ' . $e->getMessage());
    redirect($baseRedirect . '&error=' . urlencode('Risk save failed.'));
}

redirect($baseRedirect . '&success=risk_saved');
