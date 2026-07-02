<?php

require_once __DIR__ . '/../../config/app.php';
checkAuth();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/team_invitation_helper.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('modules/projects/list');
}

$projectId = (int) ($_POST['project_id'] ?? 0);
$userId = (int) ($_SESSION['user_id'] ?? 0);
$inviteeId = (int) ($_POST['invitee_user_id'] ?? 0);
$note = isset($_POST['note']) ? trim((string) $_POST['note']) : '';
$base = 'modules/projects/view?id=' . $projectId;

if ($projectId < 1 || $userId < 1) {
    redirect('modules/projects/list?error=' . urlencode('Invalid project.'));
}

$result = team_invitation_create_project($pdo, $projectId, $inviteeId, $userId, $note === '' ? null : $note);

if (!$result['ok']) {
    redirect($base . '&error=' . urlencode((string) ($result['error'] ?? 'Invite failed')));
}

$pstmt = $pdo->prepare('SELECT name FROM projects WHERE id = ?');
$pstmt->execute([$projectId]);
$pname = (string) ($pstmt->fetchColumn() ?: 'Project');

$link = 'modules/collaboration/invitation_respond?token=' . rawurlencode((string) $result['token']);
team_invitation_send_notification(
    $pdo,
    $inviteeId,
    'Project team invitation',
    $_SESSION['user_name'] . ' invited you to join the project team: ' . $pname . '.',
    $link
);

redirect($base . '&success=team_invite_sent');
