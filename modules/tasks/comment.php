<?php
require_once __DIR__ . '/../../config/app.php';
checkAuth();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/task_management_helper.php';
require_once __DIR__ . '/../../includes/upload_helper.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('modules/tasks/list');
}

$task_id      = (int)  ($_POST['task_id']      ?? 0);
$comment      = trim(  ($_POST['comment']       ?? ''));
$voice_note_sent = !empty($_POST['voice_note_sent']);
$reply_to_id  = (int)  ($_POST['reply_to_id']  ?? 0) ?: null;
$tagged_users = trim(  $_POST['tagged_users']  ?? '') ?: null;
$redirect_to  =        $_POST['redirect_to']   ?? 'modules/tasks/list';
$user_id      = (int)  $_SESSION['user_id'];
$sep          = strpos($redirect_to, '?') !== false ? '&' : '?';

$fileField = isset($_FILES['comment_files']) && is_array($_FILES['comment_files']) ? $_FILES['comment_files'] : null;

if (!$task_id || ($comment === '' && !upload_form_has_any_ok($fileField))) {
    redirect($redirect_to . $sep . 'error=empty_comment');
}

if ($comment === '') {
    $comment = $voice_note_sent ? '🎤 Voice message' : '📎 Attachment';
}

$task_info = fetch_task_access_context($pdo, $task_id, $user_id);
if (!$task_info) {
    redirect($redirect_to . $sep . 'error=task_not_found');
}
if (empty($task_info['can_manage'])) {
    redirect($redirect_to . $sep . 'error=access_denied');
}

$stmt = $pdo->prepare(
    "INSERT INTO task_comments (task_id, user_id, comment, reply_to_id, tagged_users)
     VALUES (?, ?, ?, ?, ?)"
);
$stmt->execute([$task_id, $user_id, $comment, $reply_to_id, $tagged_users]);
$comment_id = (int) $pdo->lastInsertId();

// Handle file attachments
if (!empty($_FILES['comment_files']['name'][0])) {
    $upload_dir    = __DIR__ . '/../../uploads/task_comments/';
    $upload_prefix = 'uploads/task_comments';
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
                $single, 'message_attachment', $upload_dir, $upload_prefix, 'tcomment-'
            );
            $pdo->prepare(
                "INSERT INTO task_comment_attachments
                    (comment_id, file_name, file_path, file_type, file_size)
                 VALUES (?, ?, ?, ?, ?)"
            )->execute([
                $comment_id,
                htmlspecialchars($single['name']),
                $path,
                $single['type'],
                $single['size'],
            ]);
        } catch (Exception $e) {
            // Skip invalid files; comment was already saved
        }
    }
}

redirect($redirect_to . $sep . 'success=comment_posted#comments');
