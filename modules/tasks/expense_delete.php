<?php

require_once __DIR__ . '/../../config/app.php';
checkAuth();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/task_management_helper.php';
require_once __DIR__ . '/../../includes/project_pm_helper.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('modules/tasks/list');
}

$expenseId = (int) ($_POST['expense_id'] ?? 0);
$userId = (int) ($_SESSION['user_id'] ?? 0);
$redirect = trim((string) ($_POST['redirect_to'] ?? ''));

$stmt = $pdo->prepare(
    'SELECT te.*, t.project_id, p.created_by AS pm_id
     FROM task_expenses te
     JOIN tasks t ON t.id = te.task_id
     JOIN projects p ON p.id = t.project_id
     WHERE te.id = ?'
);
$stmt->execute([$expenseId]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row) {
    redirect('modules/tasks/list?error=expense_not_found');
}

$taskId = (int) $row['task_id'];
if ($redirect === '') {
    $redirect = 'modules/tasks/view?id=' . $taskId;
}

$isPm = (int) ($row['pm_id'] ?? 0) === $userId;
$isCreator = (int) ($row['created_by'] ?? 0) === $userId;
if (!$isPm && !$isCreator && !hasPermission('manage_projects')) {
    redirect($redirect . (strpos($redirect, '?') !== false ? '&' : '?') . 'error=expense_delete_denied');
}

$projectId = (int) $row['project_id'];
if (!empty($row['receipt_file_path'])) {
    $abs = dirname(__DIR__, 2) . '/' . ltrim((string) $row['receipt_file_path'], '/');
    if (is_file($abs)) {
        @unlink($abs);
    }
}

$pdo->prepare('DELETE FROM task_expenses WHERE id = ?')->execute([$expenseId]);

log_project_activity($pdo, $projectId, $userId, 'task_expense.deleted', 'task_expense', $expenseId, [
    'task_id' => $taskId,
]);

redirect($redirect . (strpos($redirect, '?') !== false ? '&' : '?') . 'success=expense_deleted');
