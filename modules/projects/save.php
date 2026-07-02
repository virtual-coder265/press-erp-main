<?php

require_once __DIR__ . '/../../config/app.php';
checkAuth();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/project_pm_helper.php';
require_once __DIR__ . '/../../includes/project_visibility_helper.php';

function project_save_is_ajax(): bool
{
    return strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';
}

function project_save_json(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

function project_save_normalize_card_color(?string $raw): ?string
{
    $raw = trim((string) $raw);
    if ($raw === '') {
        return null;
    }

    return preg_match('/^#[0-9A-Fa-f]{6}$/', $raw) ? strtolower($raw) : null;
}

function project_save_normalize_budget_currency(string $raw): string
{
    $c = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $raw), 0, 3));

    return $c !== '' ? $c : 'USD';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $name = $_POST['name'] ?? '';
    $description = $_POST['description'] ?? '';
    $status = $_POST['status'] ?? 'Planning';
    $priority = $_POST['priority'] ?? 'Medium';
    $start_date = !empty($_POST['start_date']) ? $_POST['start_date'] : null;
    $end_date = !empty($_POST['end_date']) ? $_POST['end_date'] : null;
    $card_color = project_save_normalize_card_color($_POST['card_color'] ?? null);
    $require_document_submission = isset($_POST['require_document_submission']) ? 1 : 0;
    $require_procedure_tracking = isset($_POST['require_procedure_tracking']) ? 1 : 0;
    $budget_tracking_enabled = isset($_POST['budget_tracking_enabled']) ? 1 : 0;
    $budget_currency = project_save_normalize_budget_currency((string) ($_POST['budget_currency'] ?? 'USD'));
    $budget_amount = null;
    if ($budget_tracking_enabled) {
        $rawAmt = trim((string) ($_POST['budget_amount'] ?? ''));
        if ($rawAmt !== '') {
            $budget_amount = round((float) $rawAmt, 2);
        }
    }
    $user_id = (int) $_SESSION['user_id'];
    $legacy_project_type = 'Generic Reminders';

    if ($require_document_submission && !$require_procedure_tracking) {
        $legacy_project_type = 'Document Submission';
    } elseif ($require_procedure_tracking && !$require_document_submission) {
        $legacy_project_type = 'Documented - ICT';
    }

    $hasVisCols = project_visibility_projects_table_ready($pdo);

    $rawScope = strtolower(trim((string) ($_POST['visibility_scope'] ?? 'department')));
    $visibility_scope = in_array($rawScope, ['public', 'department', 'private'], true) ? $rawScope : 'department';
    $posted_dept_id = (int) ($_POST['project_department_id'] ?? 0);

    $can_manage_projects = hasPermission('manage_projects');
    $session_section_head = !empty($_SESSION['is_section_head']) && (int) $_SESSION['is_section_head'] === 1;

    if ($action === 'create') {
        if (!$can_manage_projects && !$session_section_head) {
            if (project_save_is_ajax()) {
                project_save_json(['ok' => false, 'error' => 'You do not have permission to create projects.'], 403);
            }
            redirect('modules/projects/list?error=access_denied');
        }

        if ($hasVisCols && $visibility_scope === 'public' && !$can_manage_projects) {
            if (project_save_is_ajax()) {
                project_save_json(['ok' => false, 'error' => 'Only project administrators can publish organization-wide projects.'], 422);
            }
            redirect('modules/projects/list?error=public_scope_denied');
        }
    }

    if (empty($name)) {
        if (project_save_is_ajax()) {
            project_save_json(['ok' => false, 'error' => 'Project name is required.'], 422);
        }
        redirect('modules/projects/list?error=name_required');
    }

    $creatorDept = project_visibility_creator_department_id($pdo, $user_id);
    $resolved_department_id = $posted_dept_id > 0 ? $posted_dept_id : $creatorDept;

    try {
        if ($action === 'create') {
            if ($hasVisCols) {
                if ($visibility_scope === 'department') {
                    if ($resolved_department_id === null || (int) $resolved_department_id < 1) {
                        throw new InvalidArgumentException('Choose a department for departmental projects.');
                    }
                }

                $stmt = $pdo->prepare(
                    "INSERT INTO projects (name, description, project_type, require_document_submission, require_procedure_tracking, status, priority, visibility_scope, department_id, start_date, end_date, created_by, card_color, budget_tracking_enabled, budget_amount, budget_currency)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
                );
                $stmt->execute([
                    $name,
                    $description,
                    $legacy_project_type,
                    $require_document_submission,
                    $require_procedure_tracking,
                    $status,
                    $priority,
                    $visibility_scope,
                    ($resolved_department_id !== null && (int) $resolved_department_id > 0) ? $resolved_department_id : null,
                    $start_date,
                    $end_date,
                    $user_id,
                    $card_color,
                    $budget_tracking_enabled,
                    $budget_amount,
                    $budget_currency,
                ]);
            } else {
                $stmt = $pdo->prepare("INSERT INTO projects (name, description, project_type, require_document_submission, require_procedure_tracking, status, priority, start_date, end_date, created_by, card_color, budget_tracking_enabled, budget_amount, budget_currency) 
                                      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([
                    $name, $description, $legacy_project_type, $require_document_submission, $require_procedure_tracking,
                    $status, $priority, $start_date, $end_date, $user_id, $card_color,
                    $budget_tracking_enabled, $budget_amount, $budget_currency,
                ]);
            }
            $project_id = (int) $pdo->lastInsertId();
            try {
                ensure_project_storage_directory($project_id);
            } catch (Throwable $e) {
                error_log('ensure_project_storage_directory: ' . $e->getMessage());
            }
            log_project_activity($pdo, $project_id, $user_id, 'project.created', 'project', $project_id, [
                'name' => $name,
                'status' => $status,
                'budget_tracking_enabled' => (bool) $budget_tracking_enabled,
                'visibility_scope' => $hasVisCols ? $visibility_scope : null,
            ]);
            if (project_save_is_ajax()) {
                project_save_json([
                    'ok' => true,
                    'id' => $project_id,
                    'title' => 'Project created',
                    'message' => 'Project created',
                    'open_url' => BASE_URL . 'modules/projects/view?id=' . $project_id,
                ]);
            }
            redirect('modules/projects/view?id=' . $project_id . '&success=project_created');
        } elseif ($action === 'update') {
            $id = (int) ($_POST['id'] ?? 0);
            $prev = null;
            if ($id > 0) {
                $prevStmt = $pdo->prepare('SELECT * FROM projects WHERE id = ? LIMIT 1');
                $prevStmt->execute([$id]);
                $prev = $prevStmt->fetch(PDO::FETCH_ASSOC) ?: null;
            }

            if (!$prev) {
                throw new InvalidArgumentException('Project not found.');
            }

            $updDept = null;
            if (!user_can_manage_project_pm($pdo, $user_id, $prev)) {
                throw new RuntimeException('You cannot update this project.');
            }

            if ($hasVisCols) {
                if ($visibility_scope === 'public' && !$can_manage_projects) {
                    $visibility_scope = project_visibility_normalized_scope($prev['visibility_scope'] ?? 'department');
                }

                $updDept = $posted_dept_id > 0 ? $posted_dept_id : ($prev['department_id'] ?? null);
                if (($updDept === null || (int) $updDept < 1) && $creatorDept !== null) {
                    $updDept = $creatorDept;
                }
                if ($visibility_scope === 'department' && ((int) $updDept < 1)) {
                    throw new InvalidArgumentException('Departmental projects require a section/department.');
                }

                $stmt = $pdo->prepare(
                    "UPDATE projects SET name = ?, description = ?, project_type = ?, require_document_submission = ?, require_procedure_tracking = ?, status = ?, priority = ?, visibility_scope = ?, department_id = ?, start_date = ?, end_date = ?, card_color = ?, budget_tracking_enabled = ?, budget_amount = ?, budget_currency = ?
                     WHERE id = ?"
                );
                $stmt->execute([
                    $name,
                    $description,
                    $legacy_project_type,
                    $require_document_submission,
                    $require_procedure_tracking,
                    $status,
                    $priority,
                    $visibility_scope,
                    ($updDept !== null && (int) $updDept > 0) ? (int) $updDept : null,
                    $start_date,
                    $end_date,
                    $card_color,
                    $budget_tracking_enabled,
                    $budget_amount,
                    $budget_currency,
                    $id,
                ]);
            } else {
                $stmt = $pdo->prepare(
                    "UPDATE projects SET name = ?, description = ?, project_type = ?, require_document_submission = ?, require_procedure_tracking = ?, status = ?, priority = ?, start_date = ?, end_date = ?, card_color = ?, budget_tracking_enabled = ?, budget_amount = ?, budget_currency = ?
                                      WHERE id = ?"
                );
                $stmt->execute([
                    $name, $description, $legacy_project_type, $require_document_submission, $require_procedure_tracking,
                    $status, $priority, $start_date, $end_date, $card_color,
                    $budget_tracking_enabled, $budget_amount, $budget_currency,
                    $id,
                ]);
            }
            try {
                ensure_project_storage_directory($id);
            } catch (Throwable $e) {
                error_log('ensure_project_storage_directory: ' . $e->getMessage());
            }

            $delta = [];
            if (is_array($prev)) {
                $after = [
                    'name' => $name,
                    'description' => $description,
                    'status' => $status,
                    'priority' => $priority,
                    'start_date' => $start_date,
                    'end_date' => $end_date,
                    'budget_tracking_enabled' => $budget_tracking_enabled,
                    'budget_amount' => $budget_amount,
                    'budget_currency' => $budget_currency,
                ];
                if ($hasVisCols) {
                    $after['visibility_scope'] = $visibility_scope;
                    $after['department_id'] = $updDept;
                }
                foreach ($after as $f => $nowVal) {
                    $wasVal = $prev[$f] ?? null;
                    if ((string) ($wasVal ?? '') !== (string) ($nowVal ?? '')) {
                        $delta[$f] = ['from' => $wasVal, 'to' => $nowVal];
                    }
                }
            }
            if (!empty($delta)) {
                log_project_activity($pdo, $id, $user_id, 'project.updated', 'project', $id, ['changes' => $delta]);
            }

            redirect('modules/projects/view?id=' . $id . '&success=project_updated');
        }
    } catch (Exception $e) {
        if (project_save_is_ajax()) {
            project_save_json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
        redirect('modules/projects/list?error=' . urlencode($e->getMessage()));
    }
} else {
    redirect('modules/projects/list');
}
