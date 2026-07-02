<?php
require_once __DIR__ . '/../../config/app.php';
checkAuth();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/task_management_helper.php';

$id = $_GET['id'] ?? 0;
$user_id = (int) ($_SESSION['user_id'] ?? 0);

if ($id) {
    try {
        $task = fetch_task_access_context($pdo, (int) $id, $user_id);
        if (!$task) {
            redirect('modules/tasks/list?error=task_not_found');
        }
        if (empty($task['can_edit'])) {
            redirect('modules/tasks/list?error=delete_access_denied');
        }

        // Get project_id before deleting for potential redirect
        $stmt = $pdo->prepare("DELETE FROM tasks WHERE id = ?");
        $stmt->execute([$id]);
        redirect('modules/tasks/list?success=task_deleted');
    } catch (Exception $e) {
        redirect('modules/tasks/list?error=' . urlencode($e->getMessage()));
    }
} else {
    redirect('modules/tasks/list?error=invalid_id');
}
?>


