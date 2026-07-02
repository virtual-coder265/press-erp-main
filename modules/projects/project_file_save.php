<?php

require_once __DIR__ . '/../../config/app.php';
checkAuth();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/upload_helper.php';
require_once __DIR__ . '/../../includes/project_pm_helper.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('modules/projects/list');
}

$fileAction = (string) ($_POST['file_action'] ?? 'upload');
$projectId = (int) ($_POST['project_id'] ?? 0);
$userId = (int) ($_SESSION['user_id'] ?? 0);
$baseRedirect = 'modules/projects/view?id=' . $projectId;

$pstmt = $pdo->prepare('SELECT * FROM projects WHERE id = ? LIMIT 1');
$pstmt->execute([$projectId]);
$project = $pstmt->fetch(PDO::FETCH_ASSOC);

if (!$project || !user_can_manage_project_pm($pdo, $userId, $project)) {
    redirect('modules/projects/list?error=file_denied');
}

try {
    if ($fileAction === 'delete') {
        $fid = (int) ($_POST['file_id'] ?? 0);
        if ($fid < 1) {
            redirect($baseRedirect . '&error=file_delete');
        }
        $fst = $pdo->prepare('SELECT * FROM project_files WHERE id = ? AND project_id = ?');
        $fst->execute([$fid, $projectId]);
        $row = $fst->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $abs = dirname(__DIR__, 2) . '/' . ltrim((string) $row['file_path'], '/');
            if (is_file($abs)) {
                @unlink($abs);
            }
            $pdo->prepare('DELETE FROM project_files WHERE id = ? AND project_id = ?')->execute([$fid, $projectId]);
            log_project_activity($pdo, $projectId, $userId, 'project_file.deleted', 'project_file', $fid, []);
        }
        redirect($baseRedirect . '&success=file_deleted');
    }

    if (empty($_FILES['project_file']['name']) || (int) ($_FILES['project_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        redirect($baseRedirect . '&error=no_file');
    }

    $paths = ensure_project_storage_directory($projectId);
    $destDir = $paths['fs_base'] . DIRECTORY_SEPARATOR . 'library';
    if (!is_dir($destDir) && !mkdir($destDir, 0755, true) && !is_dir($destDir)) {
        throw new RuntimeException('Cannot create library folder.');
    }
    $webPrefix = $paths['web_base'] . '/library/';
    $publicPath = store_validated_uploaded_file(
        $_FILES['project_file'],
        'task_document',
        $destDir . DIRECTORY_SEPARATOR,
        $webPrefix,
        'pfile-'
    );

    $mime = null;
    $size = isset($_FILES['project_file']['size']) ? (int) $_FILES['project_file']['size'] : null;
    if (function_exists('detect_uploaded_mime_type')) {
        $abs = dirname(__DIR__, 2) . '/' . ltrim($publicPath, '/');
        if (is_file($abs)) {
            $mime = detect_uploaded_mime_type($abs);
        }
    }

    $ins = $pdo->prepare(
        'INSERT INTO project_files (project_id, original_name, file_path, mime_type, file_size, uploaded_by)
         VALUES (?,?,?,?,?,?)'
    );
    $ins->execute([
        $projectId,
        (string) ($_FILES['project_file']['name'] ?? 'file'),
        $publicPath,
        $mime,
        $size,
        $userId,
    ]);
    $newId = (int) $pdo->lastInsertId();
    log_project_activity($pdo, $projectId, $userId, 'file.uploaded', 'project_file', $newId, ['path' => $publicPath]);
} catch (Throwable $e) {
    error_log('project_file_save: ' . $e->getMessage());
    redirect($baseRedirect . '&error=' . urlencode('Upload failed.'));
}

redirect($baseRedirect . '&success=file_uploaded#project-documentation-hub');
