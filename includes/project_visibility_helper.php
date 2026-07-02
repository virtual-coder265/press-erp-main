<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/app.php';

/**
 * Projects visibility: public / department (section-only) / private (team/participants).
 * Enforced on list/export/analytics/dashboard and task/project views.
 */

function project_visibility_projects_table_ready(PDO $pdo): bool
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    try {
        $stmt = $pdo->query(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'projects'
               AND COLUMN_NAME IN ('visibility_scope','department_id')"
        );
        $cache = ((int) $stmt->fetchColumn()) >= 2;
    } catch (Throwable $e) {
        $cache = false;
    }

    return $cache;
}

function project_visibility_team_members_table_ready(PDO $pdo): bool
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    try {
        $stmt = $pdo->query(
            "SELECT 1 FROM information_schema.tables
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'project_team_members' LIMIT 1"
        );
        $cache = (bool) $stmt->fetchColumn();
    } catch (Throwable $e) {
        $cache = false;
    }

    return $cache;
}

function project_visibility_normalized_scope(?string $scope): string
{
    $s = strtolower(trim((string) $scope));

    return in_array($s, ['public', 'department', 'private'], true) ? $s : 'department';
}

function project_visibility_skip_filter_for_actor(): bool
{
    return isset($_SESSION['role']) && $_SESSION['role'] === 'System Admin';
}

function project_visibility_session_can_browse(): int
{
    return hasPermission('view_projects') ? 1 : 0;
}

/** @return int|null Department id from session/fresh DB fallback */
function project_visibility_viewer_department_id(PDO $pdo, int $viewerId): ?int
{
    if ($viewerId < 1) {
        return null;
    }
    $sid = $_SESSION['department_id'] ?? null;
    if ($sid !== null && $sid !== '' && (int) $sid > 0) {
        return (int) $sid;
    }

    try {
        $stmt = $pdo->prepare('SELECT department_id FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$viewerId]);
        $did = $stmt->fetchColumn();
        if ($did !== false && $did !== null && (int) $did > 0) {
            return (int) $did;
        }
    } catch (Throwable $e) {
    }

    return null;
}

function project_visibility_viewer_is_section_head(PDO $pdo, int $viewerId): int
{
    if ($viewerId < 1) {
        return 0;
    }
    $v = $_SESSION['is_section_head'] ?? null;
    if ($v === 1 || $v === true || $v === '1') {
        return 1;
    }

    try {
        $chk = $pdo->query(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'is_section_head'"
        );
        if ((int) $chk->fetchColumn() < 1) {
            return 0;
        }
        $stmt = $pdo->prepare('SELECT is_section_head FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$viewerId]);
        $row = $stmt->fetchColumn();

        return !empty($row) ? 1 : 0;
    } catch (Throwable $e) {
        return 0;
    }
}

/**
 * Combined SQL predicate (AND ...) for rows in `projects` visible to viewer.
 *
 * @return array{clause:string, binds:array<string,int>}
 */
function project_visibility_sql_where_for_projects(string $alias, int $viewerId, PDO $pdo): array
{
    if ($viewerId < 1 || !project_visibility_projects_table_ready($pdo) || project_visibility_skip_filter_for_actor()) {
        return ['clause' => '', 'binds' => []];
    }

    $canBrowse = project_visibility_can_browse();
    $viewerDeptId = project_visibility_viewer_department_id($pdo, $viewerId);
    $viewerDeptPlaceholder = ($viewerDeptId !== null ? $viewerDeptId : -999999);
    $isSectionHead = project_visibility_viewer_is_section_head($pdo, $viewerId);

    $teamFrag = '';
    if (project_visibility_team_members_table_ready($pdo)) {
        $teamFrag = "
            OR EXISTS (
                SELECT 1 FROM project_team_members ptm_vis
                WHERE ptm_vis.project_id = {$alias}.id AND ptm_vis.user_id = :pv_u4
            )";
    }

    $clause = "
        AND (
            {$alias}.created_by = :pv_u1
            {$teamFrag}
            OR EXISTS (
                SELECT 1 FROM tasks pte_vis
                LEFT JOIN task_assignees pta_vis
                  ON pta_vis.task_id = pte_vis.id AND pta_vis.user_id = :pv_u2
                WHERE pte_vis.project_id = {$alias}.id
                  AND (
                       pte_vis.created_by = :pv_u3
                    OR pte_vis.assigned_to = :pv_u5
                    OR pta_vis.user_id IS NOT NULL
                  )
            )
            OR (
                {$alias}.visibility_scope = 'public' AND :pv_can_browse = 1
            )
            OR (
                {$alias}.visibility_scope = 'department'
                AND :pv_ud > 0
                AND {$alias}.department_id IS NOT NULL
                AND {$alias}.department_id = :pv_ud2
                AND (:pv_can_browse = 1 OR :pv_section_head = 1)
            )
        )
    ";

    $binds = [
        'pv_u1' => $viewerId,
        'pv_u2' => $viewerId,
        'pv_u3' => $viewerId,
        'pv_u5' => $viewerId,
        'pv_can_browse' => $canBrowse ? 1 : 0,
        'pv_ud' => $viewerDeptPlaceholder,
        'pv_ud2' => $viewerDeptPlaceholder,
        'pv_section_head' => $isSectionHead,
    ];
    if ($teamFrag !== '') {
        $binds['pv_u4'] = $viewerId;
    }

    return ['clause' => trim($clause), 'binds' => $binds];
}

/** Can user browse public / same-department catalogs (respecting view_projects). */
function project_visibility_can_browse(): bool
{
    return hasPermission('view_projects');
}

function project_visibility_user_engaged_delivery(PDO $pdo, int $projectId, int $userId): bool
{
    if ($projectId < 1 || $userId < 1) {
        return false;
    }

    try {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM tasks t
             LEFT JOIN task_assignees ta ON ta.task_id = t.id AND ta.user_id = ?
             WHERE t.project_id = ?
               AND (
                    t.created_by = ?
                 OR t.assigned_to = ?
                 OR ta.user_id IS NOT NULL
               )
             LIMIT 1"
        );
        $stmt->execute([$userId, $projectId, $userId, $userId]);

        return ((int) $stmt->fetchColumn()) > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function project_visibility_user_on_team(PDO $pdo, int $projectId, int $userId): bool
{
    if (!project_visibility_team_members_table_ready($pdo) || $projectId < 1 || $userId < 1) {
        return false;
    }

    try {
        $stmt = $pdo->prepare(
            'SELECT 1 FROM project_team_members WHERE project_id = ? AND user_id = ? LIMIT 1'
        );
        $stmt->execute([$projectId, $userId]);

        return (bool) $stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * @param array<string,mixed> $project Projects row (+ optional id)
 */
function project_user_can_view_project(PDO $pdo, int $userId, array $project): bool
{
    if ($userId < 1 || $project === []) {
        return false;
    }

    if (project_visibility_skip_filter_for_actor()) {
        return true;
    }

    if (!project_visibility_projects_table_ready($pdo)) {
        return hasPermission('view_projects');
    }

    $projectId = (int) ($project['id'] ?? 0);
    if ($projectId < 1) {
        return false;
    }

    if ((int) ($project['created_by'] ?? 0) === $userId) {
        return true;
    }

    if (project_visibility_user_on_team($pdo, $projectId, $userId)) {
        return true;
    }

    if (project_visibility_user_engaged_delivery($pdo, $projectId, $userId)) {
        return true;
    }

    $scope = project_visibility_normalized_scope($project['visibility_scope'] ?? 'department');

    if ($scope === 'public') {
        return project_visibility_can_browse();
    }

    if ($scope === 'department') {
        $vdid = project_visibility_viewer_department_id($pdo, $userId);
        $pdid = (int) ($project['department_id'] ?? 0);
        $sameDept = $vdid !== null && $pdid > 0 && $vdid === $pdid;

        return $sameDept
            && (
                project_visibility_can_browse()
                || project_visibility_viewer_is_section_head($pdo, $userId) === 1
            );
    }

    // Private: creators, teammates, engaged delivery — already ruled out above.

    return false;
}

function project_visibility_creator_department_id(PDO $pdo, int $userId): ?int
{
    if ($userId < 1) {
        return null;
    }
    try {
        $stmt = $pdo->prepare('SELECT department_id FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$userId]);
        $v = $stmt->fetchColumn();
        if ($v !== false && $v !== null && (int) $v > 0) {
            return (int) $v;
        }
    } catch (Throwable $e) {
    }

    return null;
}

/**
 * @param array<string,mixed> $project
 */
function project_user_can_manage_project(PDO $pdo, int $userId, array $project): bool
{
    if ($userId < 1 || $project === []) {
        return false;
    }

    if (project_visibility_skip_filter_for_actor()) {
        return true;
    }

    if ((int) ($project['created_by'] ?? 0) === $userId) {
        return true;
    }

    if (!project_visibility_projects_table_ready($pdo)) {
        return hasPermission('manage_projects');
    }

    $projectId = (int) ($project['id'] ?? 0);
    $scope = project_visibility_normalized_scope($project['visibility_scope'] ?? 'department');

    if ($scope === 'public') {
        return false;
    }

    if ($scope === 'private') {
        return project_visibility_user_on_team($pdo, $projectId, $userId);
    }

    // Department / section scoped
    $vdid = project_visibility_viewer_department_id($pdo, $userId);
    $pdid = (int) ($project['department_id'] ?? 0);
    $sameDept = $vdid !== null && $pdid > 0 && $vdid === $pdid;

    if (!$sameDept) {
        return false;
    }

    if (project_visibility_viewer_is_section_head($pdo, $userId) === 1) {
        return true;
    }

    return hasPermission('manage_projects');
}
