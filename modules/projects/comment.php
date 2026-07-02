<?php
require_once __DIR__ . '/../../config/app.php';
checkAuth();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/upload_helper.php';
require_once __DIR__ . '/../../includes/project_pm_helper.php';
require_once __DIR__ . '/../../includes/project_visibility_helper.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('modules/projects/list');
}

$project_id   = (int)  ($_POST['project_id']   ?? 0);
$comment      = trim(  $_POST['comment']        ?? '');
$voice_note_sent = !empty($_POST['voice_note_sent']);
$document_ref = trim(  $_POST['document_ref']   ?? '');
$reply_to_id  = (int)  ($_POST['reply_to_id']   ?? 0) ?: null;
$tagged_users = trim(  $_POST['tagged_users']   ?? '') ?: null;
$redirect_to  =        $_POST['redirect_to']    ?? 'modules/projects/list';
$user_id      = (int)  $_SESSION['user_id'];
$sep          = strpos($redirect_to, '?') !== false ? '&' : '?';

$fileField = isset($_FILES['comment_files']) && is_array($_FILES['comment_files']) ? $_FILES['comment_files'] : null;

if (!$project_id || ($comment === '' && !upload_form_has_any_ok($fileField))) {
    redirect($redirect_to . $sep . 'error=empty_comment');
}

if ($comment === '') {
    $comment = $voice_note_sent ? '🎤 Voice message' : '📎 Attachment';
}

$stmt = $pdo->prepare('SELECT * FROM projects WHERE id = ? LIMIT 1');
$stmt->execute([$project_id]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$row) {
    redirect($redirect_to . $sep . 'error=project_not_found');
}
if (!project_user_can_view_project($pdo, $user_id, $row)) {
    redirect($redirect_to . $sep . 'error=access_denied');
}

$stmt = $pdo->prepare(
    "INSERT INTO project_comments (project_id, user_id, comment, document_ref, reply_to_id, tagged_users)
     VALUES (?, ?, ?, ?, ?, ?)"
);
$stmt->execute([
    $project_id,
    $user_id,
    $comment,
    $document_ref ?: null,
    $reply_to_id,
    $tagged_users,
]);
$comment_id = (int) $pdo->lastInsertId();

log_project_activity($pdo, $project_id, $user_id, 'comment.posted', 'project_comment', $comment_id, [
    'has_attachments' => !empty($_FILES['comment_files']['name'][0]),
]);

// Handle file attachments
if (!empty($_FILES['comment_files']['name'][0])) {
    try {
        $paths = ensure_project_storage_directory($project_id);
        $upload_dir = $paths['fs_discussion'] . DIRECTORY_SEPARATOR;
        $upload_prefix = $paths['web_discussion_prefix'];
    } catch (Throwable $e) {
        error_log('project comment upload dir: ' . $e->getMessage());
        $upload_dir = __DIR__ . '/../../uploads/project_comments/';
        $upload_prefix = 'uploads/project_comments/';
    }
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }
    $count = count($_FILES['comment_files']['name']);
    for ($i = 0; $i < $count; $i++) {
        if ($_FILES['comment_files']['error'][$i] !== UPLOAD_ERR_OK) {
            continue;
        }
        $single = [
            'name'     => $_FILES['comment_files']['name'][$i],
            'type'     => $_FILES['comment_files']['type'][$i],
            'tmp_name' => $_FILES['comment_files']['tmp_name'][$i],
            'error'    => $_FILES['comment_files']['error'][$i],
            'size'     => $_FILES['comment_files']['size'][$i],
        ];
        try {
            $path = store_validated_uploaded_file(
                $single, 'message_attachment', $upload_dir, $upload_prefix, 'pcomment-'
            );
            $pdo->prepare(
                "INSERT INTO project_comment_attachments
                    (comment_id, file_name, file_path, file_type, file_size)
                 VALUES (?, ?, ?, ?, ?)"
            )->execute([
                $comment_id,
                htmlspecialchars($single['name']),
                $path,
                $single['type'],
                $single['size'],
            ]);
            log_project_activity($pdo, $project_id, $user_id, 'file.uploaded', 'project_comment_attachment', $comment_id, [
                'path' => $path,
            ]);
        } catch (Exception $e) {
            // Skip invalid files; comment was already saved
        }
    }
}

redirect($redirect_to . $sep . 'success=comment_posted#pv-activity');
