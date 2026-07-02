<?php

require_once __DIR__ . '/../../config/app.php';
checkAuth();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/team_invitation_helper.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('modules/tasks/list');
}

$taskId = (int) ($_POST['task_id'] ?? 0);
$userId = (int) ($_SESSION['user_id'] ?? 0);
$inviteeId = (int) ($_POST['invitee_user_id'] ?? 0);
$note = isset($_POST['note']) ? trim((string) $_POST['note']) : '';
$base = 'modules/tasks/view?id=' . $taskId;

if ($taskId < 1 || $userId < 1) {
    redirect('modules/tasks/list?error=' . urlencode('Invalid task.'));
}

$result = team_invitation_create_task($pdo, $taskId, $inviteeId, $userId, $note === '' ? null : $note);

if (!$result['ok']) {
    redirect($base . '&error=' . urlencode((string) ($result['error'] ?? 'Invite failed')));
}

$stmt = $pdo->prepare('SELECT t.name FROM tasks t WHERE t.id = ?');
$stmt->execute([$taskId]);
$tname = (string) ($stmt->fetchColumn() ?: 'Task');

$link = 'modules/collaboration/invitation_respond?token=' . rawurlencode((string) $result['token']);
team_invitation_send_notification(
    $pdo,
    $inviteeId,
    'Task team invitation',
    $_SESSION['user_name'] . ' invited you to collaborate on: ' . $tname . '.',
    $link
);

redirect($base . '&success=team_invite_sent');
