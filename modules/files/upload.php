<?php
require_once __DIR__ . '/../../config/app.php';
checkAuth();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/file_management_helper.php';
require_once __DIR__ . '/../../includes/upload_helper.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('modules/files/index');
}

$returnFolder = isset($_POST['return_folder']) ? (int) $_POST['return_folder'] : 0;
$returnFolder = $returnFolder > 0 ? $returnFolder : null;

if (!verify_csrf_token($_POST['_csrf'] ?? null, 'file_hub')) {
    $q = 'error=csrf';
    if ($returnFolder) {
        $q .= '&folder=' . (int) $returnFolder;
    }
    redirect('modules/files/index?' . $q);
}

if (!file_hub_user_can_manage_library()) {
    $q = 'error=access';
    if ($returnFolder) {
        $q .= '&folder=' . (int) $returnFolder;
    }
    redirect('modules/files/index?' . $q);
}

if (!file_hub_table_exists($pdo, 'file_library_files')) {
    $q = 'error=' . rawurlencode('The file library is not available yet.');
    if ($returnFolder) {
        $q .= '&folder=' . (int) $returnFolder;
    }
    redirect('modules/files/index?' . $q);
}

$userId = (int) ($_SESSION['user_id'] ?? 0);
$file = $_FILES['file'] ?? null;

if ($file === null || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
    $q = 'error=' . rawurlencode('No file was uploaded.');
    if ($returnFolder) {
        $q .= '&folder=' . (int) $returnFolder;
    }
    redirect('modules/files/index?' . $q);
}

try {
    $folderId = isset($_POST['folder_id']) ? (int) $_POST['folder_id'] : 0;
    $folderId = $folderId > 0 ? $folderId : null;

    $dirRel = '';
    if ($folderId) {
        $dirRel = file_library_folder_relative_path($pdo, $folderId);
        if ($dirRel === null) {
            throw new RuntimeException('Target folder was not found.');
        }
    }

    $absDir = file_library_absolute_root();
    if ($dirRel !== '') {
        $absDir .= DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $dirRel);
    }

    $publicPrefix = file_library_web_prefix();
    if ($dirRel !== '') {
        $publicPrefix .= '/' . $dirRel;
    }

    $publicPath = store_validated_uploaded_file(
        $file,
        'file_library',
        $absDir,
        rtrim($publicPrefix, '/')
    );

    $originalName = trim((string) ($file['name'] ?? ''));
    if ($originalName === '') {
        $originalName = basename($publicPath);
    }

    $localPath = ROOT_PATH . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, ltrim($publicPath, '/'));
    if (!is_file($localPath)) {
        throw new RuntimeException('Upload did not persist correctly.');
    }

    $mime = detect_uploaded_mime_type($localPath);
    $size = (int) filesize($localPath);
    $designation = file_designation_from_filename($originalName);

    $stmt = $pdo->prepare('
        INSERT INTO file_library_files (folder_id, relative_path, original_name, mime_type, file_size, designation, uploaded_by)
        VALUES (?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            mime_type = VALUES(mime_type),
            file_size = VALUES(file_size),
            original_name = VALUES(original_name),
            designation = VALUES(designation),
            uploaded_by = VALUES(uploaded_by)
    ');
    $stmt->execute([
        $folderId,
        $publicPath,
        $originalName,
        $mime,
        $size,
        $designation,
        $userId,
    ]);

    $q = 'ok=upload';
    if ($folderId) {
        $q .= '&folder=' . (int) $folderId;
    }
    redirect('modules/files/index?' . $q);
} catch (Throwable $e) {
    $q = 'error=' . rawurlencode($e->getMessage());
    if ($returnFolder) {
        $q .= '&folder=' . (int) $returnFolder;
    }
    redirect('modules/files/index?' . $q);
}
