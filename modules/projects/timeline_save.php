<?php

require_once __DIR__ . '/../../config/app.php';
checkAuth();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/project_pm_helper.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('modules/projects/list');
}

$timelineAction = (string) ($_POST['timeline_action'] ?? '');
$projectId = (int) ($_POST['project_id'] ?? 0);
$userId = (int) ($_SESSION['user_id'] ?? 0);

$pstmt = $pdo->prepare('SELECT * FROM projects WHERE id = ? LIMIT 1');
$pstmt->execute([$projectId]);
$project = $pstmt->fetch(PDO::FETCH_ASSOC);
$baseRedirect = 'modules/projects/view?id=' . $projectId;

if (!$project || !user_can_manage_project_pm($pdo, $userId, $project)) {
    redirect('modules/projects/list?error=timeline_denied');
}

try {
    if ($timelineAction === 'create') {
        $title = trim((string) ($_POST['title'] ?? ''));
        $planned = trim((string) ($_POST['planned_date'] ?? ''));
        if ($title === '' || $planned === '') {
            redirect($baseRedirect . '&error=timeline_fields');
        }
        $actualRaw = trim((string) ($_POST['actual_date'] ?? ''));
        $actualDate = $actualRaw !== '' ? $actualRaw : null;
        $linked = (int) ($_POST['linked_task_id'] ?? 0);
        if ($linked < 1) {
            $linked = null;
        }
        if ($linked) {
            $chk = $pdo->prepare('SELECT id FROM tasks WHERE id = ? AND project_id = ?');
            $chk->execute([$linked, $projectId]);
            if (!$chk->fetch()) {
                $linked = null;
            }
        }
        $notes = trim((string) ($_POST['notes'] ?? '')) ?: null;
        $maxStmt = $pdo->prepare('SELECT COALESCE(MAX(sort_order), -1) + 1 FROM project_timeline_items WHERE project_id = ?');
        $maxStmt->execute([$projectId]);
        $sort = (int) $maxStmt->fetchColumn();

        $ins = $pdo->prepare(
            'INSERT INTO project_timeline_items (project_id, title, planned_date, actual_date, linked_task_id, sort_order, notes, created_by)
             VALUES (?,?,?,?,?,?,?,?)'
        );
        $ins->execute([$projectId, $title, $planned, $actualDate, $linked, $sort, $notes, $userId]);
        $newId = (int) $pdo->lastInsertId();
        log_project_activity($pdo, $projectId, $userId, 'timeline.created', 'timeline_item', $newId, ['title' => $title]);
    } elseif ($timelineAction === 'update') {
        $tid = (int) ($_POST['id'] ?? 0);
        $title = trim((string) ($_POST['title'] ?? ''));
        $planned = trim((string) ($_POST['planned_date'] ?? ''));
        if ($tid < 1 || $title === '' || $planned === '') {
            redirect($baseRedirect . '&error=timeline_fields');
        }
        $actualRaw = trim((string) ($_POST['actual_date'] ?? ''));
        $actualDate = $actualRaw !== '' ? $actualRaw : null;
        $completedAt = null;
        if (isset($_POST['mark_completed']) && (string) $_POST['mark_completed'] === '1') {
            $completedAt = date('Y-m-d H:i:s');
            if ($actualDate === null) {
                $actualDate = date('Y-m-d');
            }
        }
        $linked = (int) ($_POST['linked_task_id'] ?? 0);
        if ($linked < 1) {
            $linked = null;
        }
        if ($linked) {
            $chk = $pdo->prepare('SELECT id FROM tasks WHERE id = ? AND project_id = ?');
            $chk->execute([$linked, $projectId]);
            if (!$chk->fetch()) {
                $linked = null;
            }
        }
        $notes = trim((string) ($_POST['notes'] ?? '')) ?: null;

        $sql = 'UPDATE project_timeline_items SET title = ?, planned_date = ?, actual_date = ?, linked_task_id = ?, notes = ?';
        $params = [$title, $planned, $actualDate, $linked, $notes];
        if ($completedAt !== null) {
            $sql .= ', completed_at = COALESCE(completed_at, ?)';
            $params[] = $completedAt;
        }
        $sql .= ' WHERE id = ? AND project_id = ?';
        $params[] = $tid;
        $params[] = $projectId;
        $u = $pdo->prepare($sql);
        $u->execute($params);

        log_project_activity($pdo, $projectId, $userId, 'timeline.updated', 'timeline_item', $tid, ['title' => $title]);
    } elseif ($timelineAction === 'delete') {
        $tid = (int) ($_POST['id'] ?? 0);
        if ($tid < 1) {
            redirect($baseRedirect . '&error=timeline_delete');
        }
        $pdo->prepare('DELETE FROM project_timeline_items WHERE id = ? AND project_id = ?')->execute([$tid, $projectId]);
        log_project_activity($pdo, $projectId, $userId, 'timeline.deleted', 'timeline_item', $tid, []);
    } elseif ($timelineAction === 'reorder') {
        $order = json_decode((string) ($_POST['order_json'] ?? ''), true);
        if (!is_array($order)) {
            redirect($baseRedirect . '&error=timeline_order');
        }
        $pos = 0;
        $st = $pdo->prepare('UPDATE project_timeline_items SET sort_order = ? WHERE id = ? AND project_id = ?');
        foreach ($order as $itemId) {
            $st->execute([$pos++, (int) $itemId, $projectId]);
        }
        log_project_activity($pdo, $projectId, $userId, 'timeline.reordered', 'project', $projectId, []);
    }
} catch (Throwable $e) {
    error_log('timeline_save: ' . $e->getMessage());
    redirect($baseRedirect . '&error=' . urlencode('Timeline save failed.'));
}

redirect($baseRedirect . '&success=timeline_saved');
