<?php
require_once __DIR__ . '/../../config/app.php';
checkAuth();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/project_pm_helper.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('modules/projects/list?error=invalid_request');
}

if (!verify_csrf_token($_POST['csrf_token'] ?? null, 'delete_project')) {
    redirect('modules/projects/list?error=invalid_csrf');
}

$id = isset($_POST['id']) ? (int) $_POST['id'] : 0;

if ($id > 0) {
    try {
        $stmt = $pdo->prepare('SELECT * FROM projects WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $project = $stmt->fetch(PDO::FETCH_ASSOC);
        $viewer = (int) ($_SESSION['user_id'] ?? 0);
        if (!$project || !user_can_manage_project_pm($pdo, $viewer, $project)) {
            redirect('modules/projects/list?error=access_denied');
        }
        $pdo->prepare('DELETE FROM projects WHERE id = ?')->execute([$id]);
        redirect('modules/projects/list?success=project_deleted');
    } catch (Exception $e) {
        redirect('modules/projects/list?error=' . urlencode($e->getMessage()));
    }
}

redirect('modules/projects/list?error=invalid_id');
