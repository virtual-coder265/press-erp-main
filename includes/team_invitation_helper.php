<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/project_pm_helper.php';
require_once __DIR__ . '/task_management_helper.php';

function team_invitation_tables_ready(PDO $pdo): bool
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    try {
        $stmt = $pdo->query(
            "SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema = DATABASE()
               AND table_name IN ('project_team_invitations','task_team_invitations','project_team_members')"
        );
        $cache = ((int) $stmt->fetchColumn()) >= 3;
    } catch (Throwable $e) {
        $cache = false;
    }

    return $cache;
}

function user_can_send_project_team_invitation(PDO $pdo, int $userId, array $project): bool
{
    return user_can_manage_project_pm($pdo, $userId, $project);
}

/**
 * Project PM / manage_projects, or task creator.
 */
function user_can_send_task_team_invitation(PDO $pdo, int $userId, array $task): bool
{
    $pmId = (int) ($task['pm_id'] ?? 0);
    if ($pmId > 0 && user_can_manage_project_pm($pdo, $userId, ['created_by' => $pmId])) {
        return true;
    }

    return (int) ($task['created_by'] ?? 0) === $userId;
}

function team_invitation_generate_token(): string
{
    return bin2hex(random_bytes(32));
}

function team_invitation_default_expires(): string
{
    return date('Y-m-d H:i:s', strtotime('+14 days'));
}

/**
 * @return array{ok:bool, error?:string, id?:int, token?:string}
 */
function team_invitation_create_project(PDO $pdo, int $projectId, int $inviteeUserId, int $invitedByUserId, ?string $note): array
{
    if (!team_invitation_tables_ready($pdo)) {
        return ['ok' => false, 'error' => 'Team invitations are not enabled (run database migration).'];
    }
    if ($inviteeUserId <= 0 || $inviteeUserId === $invitedByUserId) {
        return ['ok' => false, 'error' => 'Invalid invitee.'];
    }

    $pstmt = $pdo->prepare('SELECT * FROM projects WHERE id = ? LIMIT 1');
    $pstmt->execute([$projectId]);
    $project = $pstmt->fetch(PDO::FETCH_ASSOC);
    if (!$project || !user_can_send_project_team_invitation($pdo, $invitedByUserId, $project)) {
        return ['ok' => false, 'error' => 'You cannot invite people to this project.'];
    }

    if (team_invitation_user_already_project_participant($pdo, $projectId, $inviteeUserId)) {
        return ['ok' => false, 'error' => 'That user is already on this project team.'];
    }

    $dup = $pdo->prepare(
        "SELECT id FROM project_team_invitations
         WHERE project_id = ? AND invitee_user_id = ? AND status = 'pending' LIMIT 1"
    );
    $dup->execute([$projectId, $inviteeUserId]);
    if ($dup->fetch()) {
        return ['ok' => false, 'error' => 'An invitation is already pending for this person.'];
    }

    $token = team_invitation_generate_token();
    $expires = team_invitation_default_expires();
    $noteTrim = $note !== null ? trim($note) : '';
    $noteVal = $noteTrim === '' ? null : $noteTrim;

    $ins = $pdo->prepare(
        "INSERT INTO project_team_invitations
         (project_id, invitee_user_id, invited_by_user_id, status, token, note, expires_at)
         VALUES (?,?,?,'pending',?,?,?)"
    );
    $ins->execute([$projectId, $inviteeUserId, $invitedByUserId, $token, $noteVal, $expires]);
    $id = (int) $pdo->lastInsertId();

    log_project_activity($pdo, $projectId, $invitedByUserId, 'project.team_invitation_sent', 'user', $inviteeUserId, [
        'invitation_id' => $id,
    ]);

    return ['ok' => true, 'id' => $id, 'token' => $token];
}

/**
 * @return array{ok:bool, error?:string, id?:int, token?:string}
 */
function team_invitation_create_task(PDO $pdo, int $taskId, int $inviteeUserId, int $invitedByUserId, ?string $note): array
{
    if (!team_invitation_tables_ready($pdo)) {
        return ['ok' => false, 'error' => 'Team invitations are not enabled (run database migration).'];
    }
    if ($inviteeUserId <= 0 || $inviteeUserId === $invitedByUserId) {
        return ['ok' => false, 'error' => 'Invalid invitee.'];
    }

    $stmt = $pdo->prepare(
        'SELECT t.*, p.name AS project_name, p.created_by AS pm_id
         FROM tasks t JOIN projects p ON p.id = t.project_id WHERE t.id = ? LIMIT 1'
    );
    $stmt->execute([$taskId]);
    $task = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$task || !user_can_send_task_team_invitation($pdo, $invitedByUserId, $task)) {
        return ['ok' => false, 'error' => 'You cannot invite people to this task.'];
    }

    if (team_invitation_user_is_task_assignee($pdo, $taskId, $inviteeUserId)) {
        return ['ok' => false, 'error' => 'That user is already assigned to this task.'];
    }

    $dup = $pdo->prepare(
        "SELECT id FROM task_team_invitations
         WHERE task_id = ? AND invitee_user_id = ? AND status = 'pending' LIMIT 1"
    );
    $dup->execute([$taskId, $inviteeUserId]);
    if ($dup->fetch()) {
        return ['ok' => false, 'error' => 'An invitation is already pending for this person.'];
    }

    $token = team_invitation_generate_token();
    $expires = team_invitation_default_expires();
    $noteTrim = $note !== null ? trim($note) : '';
    $noteVal = $noteTrim === '' ? null : $noteTrim;

    $ins = $pdo->prepare(
        "INSERT INTO task_team_invitations
         (task_id, invitee_user_id, invited_by_user_id, status, token, note, expires_at)
         VALUES (?,?,?,'pending',?,?,?)"
    );
    $ins->execute([$taskId, $inviteeUserId, $invitedByUserId, $token, $noteVal, $expires]);
    $id = (int) $pdo->lastInsertId();

    log_project_activity($pdo, (int) $task['project_id'], $invitedByUserId, 'task.team_invitation_sent', 'task', $taskId, [
        'invitation_id' => $id,
        'invitee_user_id' => $inviteeUserId,
    ]);

    return ['ok' => true, 'id' => $id, 'token' => $token];
}

function team_invitation_user_already_project_participant(PDO $pdo, int $projectId, int $userId): bool
{
    $participants = fetch_delivery_participants_for_project($pdo, $projectId);
    foreach ($participants as $row) {
        if ((int) ($row['id'] ?? 0) === $userId) {
            return true;
        }
    }

    return false;
}

function team_invitation_user_is_task_assignee(PDO $pdo, int $taskId, int $userId): bool
{
    $assignees = fetch_task_assignees($pdo, $taskId);
    foreach ($assignees as $a) {
        if ((int) ($a['user_id'] ?? 0) === $userId) {
            return true;
        }
    }
    $stmt = $pdo->prepare('SELECT assigned_to FROM tasks WHERE id = ? LIMIT 1');
    $stmt->execute([$taskId]);
    $aid = $stmt->fetchColumn();

    return $aid !== false && (int) $aid === $userId;
}

/**
 * @return array{type:'project'|'task', row:array}|null
 */
function team_invitation_find_by_token(PDO $pdo, string $token): ?array
{
    if ($token === '' || !team_invitation_tables_ready($pdo)) {
        return null;
    }

    $pst = $pdo->prepare(
        "SELECT i.*, p.name AS project_name, COALESCE(ibu.name, 'Team member') AS invited_by_name
         FROM project_team_invitations i
         JOIN projects p ON p.id = i.project_id
         JOIN users ibu ON ibu.id = i.invited_by_user_id
         WHERE i.token = ? LIMIT 1"
    );
    $pst->execute([$token]);
    $prow = $pst->fetch(PDO::FETCH_ASSOC);
    if ($prow) {
        return ['type' => 'project', 'row' => $prow];
    }

    $tst = $pdo->prepare(
        "SELECT i.*, t.name AS task_name, t.project_id, p.name AS project_name, COALESCE(ibu.name, 'Team member') AS invited_by_name
         FROM task_team_invitations i
         JOIN tasks t ON t.id = i.task_id
         JOIN projects p ON p.id = t.project_id
         JOIN users ibu ON ibu.id = i.invited_by_user_id
         WHERE i.token = ? LIMIT 1"
    );
    $tst->execute([$token]);
    $trow = $tst->fetch(PDO::FETCH_ASSOC);
    if ($trow) {
        return ['type' => 'task', 'row' => $trow];
    }

    return null;
}

/**
 * @return array{ok:bool, error?:string}
 */
function team_invitation_accept(PDO $pdo, string $token, int $actingUserId): array
{
    $found = team_invitation_find_by_token($pdo, $token);
    if (!$found) {
        return ['ok' => false, 'error' => 'Invitation not found or expired.'];
    }
    $row = $found['row'];
    if ($row['status'] !== 'pending') {
        return ['ok' => false, 'error' => 'This invitation is no longer active.'];
    }
    if (strtotime((string) $row['expires_at']) < time()) {
        return ['ok' => false, 'error' => 'This invitation has expired.'];
    }
    if ((int) $row['invitee_user_id'] !== $actingUserId) {
        return ['ok' => false, 'error' => 'You are not the invited user.'];
    }

    if ($found['type'] === 'project') {
        return team_invitation_accept_project_row($pdo, $row, $actingUserId);
    }

    return team_invitation_accept_task_row($pdo, $row, $actingUserId);
}

/**
 * @param array<string,mixed> $row
 * @return array{ok:bool, error?:string}
 */
function team_invitation_accept_project_row(PDO $pdo, array $row, int $actingUserId): array
{
    $projectId = (int) $row['project_id'];
    $invId = (int) $row['id'];

    try {
        $pdo->beginTransaction();

        $upd = $pdo->prepare(
            "UPDATE project_team_invitations SET status = 'accepted', responded_at = NOW() WHERE id = ? AND status = 'pending'"
        );
        $upd->execute([$invId]);
        if ($upd->rowCount() !== 1) {
            $pdo->rollBack();

            return ['ok' => false, 'error' => 'Could not update invitation.'];
        }

        $mem = $pdo->prepare(
            "INSERT IGNORE INTO project_team_members (project_id, user_id, source) VALUES (?,?, 'invitation')"
        );
        $mem->execute([$projectId, $actingUserId]);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('team_invitation_accept_project: ' . $e->getMessage());

        return ['ok' => false, 'error' => 'Could not complete acceptance.'];
    }

    log_project_activity($pdo, $projectId, $actingUserId, 'project.team_invitation_accepted', 'user', $actingUserId, [
        'invitation_id' => $invId,
    ]);

    return ['ok' => true];
}

/**
 * @param array<string,mixed> $row
 * @return array{ok:bool, error?:string}
 */
function team_invitation_accept_task_row(PDO $pdo, array $row, int $actingUserId): array
{
    $taskId = (int) $row['task_id'];
    $invId = (int) $row['id'];
    $projectId = (int) ($row['project_id'] ?? 0);
    if ($projectId < 1) {
        $ps = $pdo->prepare('SELECT project_id FROM tasks WHERE id = ?');
        $ps->execute([$taskId]);
        $projectId = (int) $ps->fetchColumn();
    }

    $assigneeIds = array_map(static function (array $a): int {
        return (int) $a['user_id'];
    }, fetch_task_assignees($pdo, $taskId));

    $tstmt = $pdo->prepare('SELECT assigned_to FROM tasks WHERE id = ?');
    $tstmt->execute([$taskId]);
    $assignedTo = $tstmt->fetchColumn();
    if ($assignedTo !== false && (int) $assignedTo > 0) {
        $assigneeIds[] = (int) $assignedTo;
    }
    $assigneeIds = array_values(array_unique(array_filter($assigneeIds, static fn (int $id): bool => $id > 0)));

    if (!in_array($actingUserId, $assigneeIds, true)) {
        $assigneeIds[] = $actingUserId;
    }

    $primary = determine_primary_assignee($assigneeIds, $assignedTo !== false ? (int) $assignedTo : null);

    try {
        $pdo->beginTransaction();

        $upd = $pdo->prepare(
            "UPDATE task_team_invitations SET status = 'accepted', responded_at = NOW() WHERE id = ? AND status = 'pending'"
        );
        $upd->execute([$invId]);
        if ($upd->rowCount() !== 1) {
            $pdo->rollBack();

            return ['ok' => false, 'error' => 'Could not update invitation.'];
        }

        sync_task_assignees($pdo, $taskId, $assigneeIds, (int) $row['invited_by_user_id'], $primary);

        if ($primary !== null) {
            $ut = $pdo->prepare('UPDATE tasks SET assigned_to = ? WHERE id = ?');
            $ut->execute([$primary, $taskId]);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('team_invitation_accept_task: ' . $e->getMessage());

        return ['ok' => false, 'error' => 'Could not add you to this task.'];
    }

    if ($projectId > 0) {
        log_project_activity($pdo, $projectId, $actingUserId, 'task.team_invitation_accepted', 'task', $taskId, [
            'invitation_id' => $invId,
        ]);
    }

    return ['ok' => true];
}

/**
 * @return array{ok:bool, error?:string}
 */
function team_invitation_decline(PDO $pdo, string $token, int $actingUserId): array
{
    $found = team_invitation_find_by_token($pdo, $token);
    if (!$found) {
        return ['ok' => false, 'error' => 'Invitation not found.'];
    }
    $row = $found['row'];
    if ($row['status'] !== 'pending') {
        return ['ok' => false, 'error' => 'This invitation is no longer active.'];
    }
    if ((int) $row['invitee_user_id'] !== $actingUserId) {
        return ['ok' => false, 'error' => 'You are not the invited user.'];
    }

    if ($found['type'] === 'project') {
        $upd = $pdo->prepare(
            "UPDATE project_team_invitations SET status = 'declined', responded_at = NOW() WHERE id = ? AND status = 'pending'"
        );
        $upd->execute([(int) $row['id']]);
    } else {
        $upd = $pdo->prepare(
            "UPDATE task_team_invitations SET status = 'declined', responded_at = NOW() WHERE id = ? AND status = 'pending'"
        );
        $upd->execute([(int) $row['id']]);
    }

    return $upd->rowCount() === 1 ? ['ok' => true] : ['ok' => false, 'error' => 'Could not update invitation.'];
}

/**
 * Pending invitations for dashboard (invitee = current user).
 *
 * @return list<array<string,mixed>>
 */
function team_invitation_fetch_pending_for_user(PDO $pdo, int $userId): array
{
    if (!team_invitation_tables_ready($pdo) || $userId < 1) {
        return [];
    }

    $out = [];

    $pstmt = $pdo->prepare(
        "SELECT 'project' AS invitation_type, i.id, i.token, i.note, i.created_at, i.expires_at,
                i.project_id AS context_id, NULL AS context_name_extra,
                p.name AS context_label,
                COALESCE(u.name, 'User') AS invited_by_name
         FROM project_team_invitations i
         JOIN projects p ON p.id = i.project_id
         JOIN users u ON u.id = i.invited_by_user_id
         WHERE i.invitee_user_id = ? AND i.status = 'pending' AND i.expires_at > NOW()
         ORDER BY i.created_at DESC"
    );
    $pstmt->execute([$userId]);
    while ($r = $pstmt->fetch(PDO::FETCH_ASSOC)) {
        $out[] = $r;
    }

    $tstmt = $pdo->prepare(
        "SELECT 'task' AS invitation_type, i.id, i.token, i.note, i.created_at, i.expires_at,
                i.task_id AS context_id, pr.name AS context_name_extra,
                t.name AS context_label,
                COALESCE(u.name, 'User') AS invited_by_name
         FROM task_team_invitations i
         JOIN tasks t ON t.id = i.task_id
         JOIN projects pr ON pr.id = t.project_id
         JOIN users u ON u.id = i.invited_by_user_id
         WHERE i.invitee_user_id = ? AND i.status = 'pending' AND i.expires_at > NOW()
         ORDER BY i.created_at DESC"
    );
    $tstmt->execute([$userId]);
    while ($r = $tstmt->fetch(PDO::FETCH_ASSOC)) {
        $out[] = $r;
    }

    usort($out, static function (array $a, array $b): int {
        return strtotime((string) $b['created_at']) <=> strtotime((string) $a['created_at']);
    });

    return $out;
}

function team_invitation_send_notification(PDO $pdo, int $inviteeUserId, string $title, string $body, string $relativeLink): void
{
    try {
        require_once dirname(__DIR__) . '/libs/NotificationManager.php';
        $nm = new NotificationManager($pdo);
        $nm->notify($inviteeUserId, 'task', $title, $body, $relativeLink, null, false, false, [], []);
    } catch (Throwable $e) {
        error_log('team_invitation_send_notification: ' . $e->getMessage());
    }
}
