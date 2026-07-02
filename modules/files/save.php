<?php
require_once __DIR__ . '/../../config/app.php';
checkAuth();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/file_management_helper.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('modules/files/index');
}

$returnFolderEarly = isset($_POST['return_folder']) ? (int) $_POST['return_folder'] : 0;
$returnFolderEarly = $returnFolderEarly > 0 ? $returnFolderEarly : null;

if (!verify_csrf_token($_POST['_csrf'] ?? null, 'file_hub')) {
    $q = 'error=csrf';
    if ($returnFolderEarly) {
        $q .= '&folder=' . (int) $returnFolderEarly;
    }
    redirect('modules/files/index?' . $q);
}

if (!file_hub_user_can_manage_library()) {
    $q = 'error=access';
    if ($returnFolderEarly) {
        $q .= '&folder=' . (int) $returnFolderEarly;
    }
    redirect('modules/files/index?' . $q);
}

$action = (string) ($_POST['action'] ?? '');
$userId = (int) ($_SESSION['user_id'] ?? 0);
$returnFolder = isset($_POST['return_folder']) ? (int) $_POST['return_folder'] : 0;
$returnFolder = $returnFolder > 0 ? file_library_normalize_folder_id($pdo, $returnFolder) : null;

try {
    if ($action === 'create_folder') {
        if (!file_hub_table_exists($pdo, 'file_library_folders')) {
            throw new RuntimeException('The file library is not available. Ask an administrator to finish setup.');
        }

        $name = trim((string) ($_POST['folder_name'] ?? ''));
        if ($name === '') {
            throw new RuntimeException('Folder name is required.');
        }

        $parentId = isset($_POST['parent_folder_id']) ? (int) $_POST['parent_folder_id'] : 0;
        $parentId = $parentId > 0 ? file_library_normalize_folder_id($pdo, $parentId) : null;
        if (!empty($_POST['parent_folder_id']) && (int) $_POST['parent_folder_id'] > 0 && $parentId === null) {
            throw new RuntimeException('Parent folder was not found.');
        }

        $baseSlug = file_library_slugify($name);
        $slug = file_library_next_child_slug($pdo, $parentId, $baseSlug);
        $parentPath = $parentId ? (file_library_folder_relative_path($pdo, $parentId) ?? '') : '';
        $relativePath = $parentPath === '' ? $slug : $parentPath . '/' . $slug;

        $absolute = file_library_absolute_root() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        if (!is_dir($absolute) && !mkdir($absolute, 0755, true) && !is_dir($absolute)) {
            throw new RuntimeException('Unable to create this folder right now. Try again or contact support.');
        }

        $stmt = $pdo->prepare('
            INSERT INTO file_library_folders (parent_id, name, slug_segment, relative_path, created_by)
            VALUES (?, ?, ?, ?, ?)
        ');
        $stmt->execute([$parentId, $name, $slug, $relativePath, $userId]);

        $newId = (int) $pdo->lastInsertId();
        redirect('modules/files/index?ok=folder&folder=' . $newId);
    }

    if ($action === 'sync_disk') {
        if (!file_hub_table_exists($pdo, 'file_library_files')) {
            throw new RuntimeException('The file library is not available. Ask an administrator to finish setup.');
        }

        $result = file_library_sync_disk($pdo, $userId);
        $reg = (int) ($result['registered'] ?? 0);
        $sk = (int) ($result['skipped'] ?? 0);
        $suffix = 'ok=sync&registered=' . $reg . '&skipped=' . $sk;
        if ($returnFolder) {
            $suffix .= '&folder=' . (int) $returnFolder;
        }
        redirect('modules/files/index?' . $suffix);
    }
} catch (Throwable $e) {
    $suffix = 'error=' . rawurlencode($e->getMessage());
    if ($returnFolder) {
        $suffix .= '&folder=' . (int) $returnFolder;
    }
    redirect('modules/files/index?' . $suffix);
}

redirect('modules/files/index');
