<?php
require_once __DIR__ . '/../../config/app.php';
checkAuth();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/team_invitation_helper.php';
/**
 * Check if the communication module is available
 
 */


function getProjectRequirementBadges(array $project): array
{
    $badges = [];

    if (!empty($project['require_document_submission'])) {
        $badges[] = [
            'label' => 'Document Submission',
            'class' => 'bg-green-100 text-green-800'
        ];
    }

    if (!empty($project['require_procedure_tracking'])) {
        $badges[] = [
            'label' => 'Procedure Tracking',
            'class' => 'bg-emerald-50 text-emerald-700'
        ];
    }

    return $badges;
}

function portfolio_normalize_card_hex(?string $raw): string
{
    $raw = trim((string) $raw);
    if ($raw !== '' && preg_match('/^#[0-9A-Fa-f]{6}$/', $raw)) {
        return strtolower($raw);
    }
    return '#0f766e';
}

function portfolio_hex_rgb_tuple(string $hex): array
{
    $hex = ltrim($hex, '#');
    return [
        hexdec(substr($hex, 0, 2)),
        hexdec(substr($hex, 2, 2)),
        hexdec(substr($hex, 4, 2)),
    ];
}

function portfolio_card_hero_gradient(string $hex): string
{
    [$r, $g, $b] = portfolio_hex_rgb_tuple($hex);
    $r1 = max(0, min(255, $r - 38));
    $g1 = max(0, min(255, $g - 38));
    $b1 = max(0, min(255, $b - 38));
    $r2 = max(0, min(255, $r + 42));
    $g2 = max(0, min(255, $g + 42));
    $b2 = max(0, min(255, $b + 42));

    return "linear-gradient(135deg, rgb({$r1},{$g1},{$b1}) 0%, {$hex} 48%, rgb({$r2},{$g2},{$b2}) 100%)";
}

/** Two-letter initials from a project title (e.g. "DPS Website Resign" → "DW"). */
function portfolio_project_initials(string $name): string
{
    $name = trim($name);
    if ($name === '') {
        return '?';
    }
    $parts = preg_split('/\s+/u', $name, -1, PREG_SPLIT_NO_EMPTY);
    if (count($parts) >= 2) {
        $a = function_exists('mb_substr') ? mb_substr($parts[0], 0, 1, 'UTF-8') : substr($parts[0], 0, 1);
        $b = function_exists('mb_substr') ? mb_substr($parts[1], 0, 1, 'UTF-8') : substr($parts[1], 0, 1);
        return strtoupper($a . $b);
    }
    $two = function_exists('mb_substr') ? mb_substr($name, 0, 2, 'UTF-8') : substr($name, 0, 2);
    return strtoupper($two);
}

require_once __DIR__ . '/../../includes/project_visibility_helper.php';

$delete_project_csrf = csrf_token('delete_project');

include '../../includes/header.php';
?>

<form id="deleteProjectForm" method="POST" action="delete" class="hidden">
    <input type="hidden" name="id" id="deleteProjectId" value="">
    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($delete_project_csrf); ?>">
</form>

<?php
// Search and filter functionality
$search_query = $_GET['search'] ?? '';
$status_filter = $_GET['status'] ?? '';
$priority_filter = $_GET['priority'] ?? '';

$query = "SELECT p.*, u.name as created_by_name,
          (SELECT COUNT(*) FROM tasks t WHERE t.project_id = p.id) as task_count,
          (SELECT COUNT(*) FROM tasks t WHERE t.project_id = p.id AND t.status = 'Completed') as completed_tasks,
          (SELECT COUNT(*) FROM project_comments pc WHERE pc.project_id = p.id) as comment_count,
          (COALESCE((SELECT COUNT(*) FROM task_attachments ta INNER JOIN tasks t ON ta.task_id = t.id WHERE t.project_id = p.id), 0) +
           COALESCE((SELECT COUNT(*) FROM project_comment_attachments pca INNER JOIN project_comments pc ON pca.comment_id = pc.id WHERE pc.project_id = p.id), 0)) as file_count
          FROM projects p 
          LEFT JOIN users u ON p.created_by = u.id 
          WHERE 1=1";

$params = [];

if (!empty($search_query)) {
    $query .= " AND (p.name LIKE :search OR p.description LIKE :search)";
    $params['search'] = '%' . $search_query . '%';
}

if (!empty($status_filter)) {
    $query .= " AND p.status = :status";
    $params['status'] = $status_filter;
}

if (!empty($priority_filter)) {
    $query .= " AND p.priority = :priority";
    $params['priority'] = $priority_filter;
}

$viewerIdProjects = (int) ($_SESSION['user_id'] ?? 0);
$visFilter = project_visibility_sql_where_for_projects('p', $viewerIdProjects, $pdo);
if ($visFilter['clause'] !== '') {
    $query .= ' ' . $visFilter['clause'];
    foreach ($visFilter['binds'] as $k => $v) {
        $params[$k] = $v;
    }
}

$query .= " ORDER BY p.created_at DESC";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$projects = $stmt->fetchAll();

$portfolioTaskPreviewByProject = [];
if (!empty($projects)) {
    $projectIds = array_values(array_unique(array_map(static fn (array $row): int => (int) $row['id'], $projects)));
    if ($projectIds !== []) {
        $placeholders = implode(',', array_fill(0, count($projectIds), '?'));
        $taskPreviewStmt = $pdo->prepare(
            "SELECT project_id, id, name, status, due_date FROM tasks WHERE project_id IN ($placeholders) ORDER BY project_id ASC, (due_date IS NULL) ASC, due_date ASC, id ASC"
        );
        $taskPreviewStmt->execute($projectIds);
        while ($taskRow = $taskPreviewStmt->fetch(PDO::FETCH_ASSOC)) {
            $pid = (int) $taskRow['project_id'];
            if (!isset($portfolioTaskPreviewByProject[$pid])) {
                $portfolioTaskPreviewByProject[$pid] = [];
            }
            if (count($portfolioTaskPreviewByProject[$pid]) < 4) {
                $portfolioTaskPreviewByProject[$pid][] = $taskRow;
            }
        }
    }
}

$projectDashboardStats = [
    'total' => count($projects),
    'planning' => 0,
    'in_progress' => 0,
    'on_hold' => 0,
    'completed' => 0,
    'overdue' => 0,
    'task_total' => 0,
    'task_completed' => 0,
    'file_total' => 0,
    'comment_total' => 0,
];

$projectActivityItems = [];

foreach ($projects as $project) {
    $status = $project['status'] ?? '';

    if ($status === 'Planning') {
        $projectDashboardStats['planning']++;
    } elseif ($status === 'In Progress') {
        $projectDashboardStats['in_progress']++;
    } elseif ($status === 'On Hold') {
        $projectDashboardStats['on_hold']++;
    } elseif ($status === 'Completed') {
        $projectDashboardStats['completed']++;
    }

    $projectDashboardStats['task_total'] += (int) ($project['task_count'] ?? 0);
    $projectDashboardStats['task_completed'] += (int) ($project['completed_tasks'] ?? 0);
    $projectDashboardStats['file_total'] += (int) ($project['file_count'] ?? 0);
    $projectDashboardStats['comment_total'] += (int) ($project['comment_count'] ?? 0);

    $isOverdue = !empty($project['end_date'])
        && !in_array($status, ['Completed', 'Cancelled'], true)
        && strtotime($project['end_date']) < strtotime(date('Y-m-d'));

    if ($isOverdue) {
        $projectDashboardStats['overdue']++;
    }

    if (count($projectActivityItems) < 4) {
        $projectProgress = ((int) ($project['task_count'] ?? 0)) > 0
            ? round((((int) $project['completed_tasks']) / (int) $project['task_count']) * 100)
            : 0;

        $projectActivityItems[] = [
            'id' => $project['id'],
            'name' => $project['name'],
            'status' => $status ?: 'Project',
            'progress' => $projectProgress,
            'deadline' => !empty($project['end_date']) ? date('M j, Y', strtotime($project['end_date'])) : 'No deadline',
            'tone' => $isOverdue ? 'danger' : ($status === 'Completed' ? 'success' : ($status === 'In Progress' ? 'info' : 'neutral')),
            'subtitle' => ($status !== '' ? $status : 'Unset status') . ' · ' . $projectProgress . '% of tasks complete',
        ];
    }
}

$projectCompletionRate = $projectDashboardStats['task_total'] > 0
    ? (int) round(($projectDashboardStats['task_completed'] / $projectDashboardStats['task_total']) * 100)
    : 0;

$onTrackProjectCount = 0;
foreach ($projects as $_projRow) {
    $_st = $_projRow['status'] ?? '';
    if (in_array($_st, ['Completed', 'Cancelled'], true)) {
        continue;
    }
    $_over = !empty($_projRow['end_date']) && strtotime((string) $_projRow['end_date']) < strtotime(date('Y-m-d'));
    if (!$_over) {
        $onTrackProjectCount++;
    }
}

$taskTrackedPct = $projectDashboardStats['task_total'] > 0
    ? (int) round(($projectDashboardStats['task_completed'] / $projectDashboardStats['task_total']) * 100)
    : 0;

$projectMetricTiles = [
    ['label' => 'In Progress', 'value' => number_format($projectDashboardStats['in_progress']), 'tone' => 'neutral'],
    ['label' => 'Completed', 'value' => number_format($projectDashboardStats['completed']), 'tone' => 'neutral'],
    ['label' => 'On Hold', 'value' => number_format($projectDashboardStats['on_hold']), 'tone' => 'neutral'],
    [
        'label' => 'Overdue',
        'value' => number_format($projectDashboardStats['overdue']),
        'tone' => $projectDashboardStats['overdue'] > 0 ? 'danger' : 'neutral',
    ],
];

$projectFocusItems = [
    [
        'label' => 'Delivery attention',
        'value' => number_format($projectDashboardStats['overdue']),
        'note' => $projectDashboardStats['overdue'] > 0
            ? 'Projects that are past target date and still open.'
            : 'No projects currently need deadline escalation.',
        'tone' => $projectDashboardStats['overdue'] > 0 ? 'danger' : 'success',
        'icon' => $projectDashboardStats['overdue'] > 0 ? 'triangle-alert' : 'badge-check',
        'view_all_href' => 'list',
    ],
    [
        'label' => 'Execution lane',
        'value' => number_format($projectDashboardStats['in_progress']),
        'note' => 'Projects currently moving through delivery and active work.',
        'tone' => 'success',
        'icon' => 'target',
        'view_all_href' => 'list?status=' . urlencode('In Progress'),
    ],
    [
        'label' => 'Scoping pipeline',
        'value' => number_format($projectDashboardStats['planning']),
        'note' => 'Upcoming work still in planning, approvals, or kickoff preparation.',
        'tone' => 'purple',
        'icon' => 'file-text',
        'view_all_href' => 'list?status=' . urlencode('Planning'),
    ],
];

$commFocusProjectId = 0;
if (!empty($projectActivityItems[0]['id'])) {
    $commFocusProjectId = (int) $projectActivityItems[0]['id'];
} elseif (!empty($projects[0]['id'])) {
    $commFocusProjectId = (int) $projects[0]['id'];
}

$commMessageSubject = 'Projects';
if ($commFocusProjectId > 0) {
    $focusProjectName = '';
    foreach ($projects as $pr) {
        if ((int) ($pr['id'] ?? 0) === $commFocusProjectId) {
            $focusProjectName = (string) ($pr['name'] ?? '');
            break;
        }
    }
    if ($focusProjectName === ''
        && !empty($projectActivityItems[0]['id'])
        && (int) $projectActivityItems[0]['id'] === $commFocusProjectId) {
        $focusProjectName = (string) ($projectActivityItems[0]['name'] ?? '');
    }
    $previewTasksForFocus = $portfolioTaskPreviewByProject[$commFocusProjectId] ?? [];
    $focusTaskName = !empty($previewTasksForFocus[0]['name'])
        ? (string) $previewTasksForFocus[0]['name']
        : '';
    if ($focusTaskName !== '' && $focusProjectName !== '') {
        $commMessageSubject = $focusTaskName . ' — ' . $focusProjectName;
    } elseif ($focusProjectName !== '') {
        $commMessageSubject = $focusProjectName;
    } elseif ($focusTaskName !== '') {
        $commMessageSubject = $focusTaskName;
    }
}

$digitsOnly = static function (?string $raw): string {
    return (string) preg_replace('/\D+/', '', (string) $raw);
};

$sessionUid = (int) ($_SESSION['user_id'] ?? 0);
$commTeamContactRows = [];
$commTeamHasOthers = false;
$commFocusProjectRow = null;
$canInviteFocusProjectTeam = false;

if ($commFocusProjectId > 0) {
    try {
        $participants = fetch_delivery_participants_for_project($pdo, $commFocusProjectId);
        $others = [];
        foreach ($participants as $pt) {
            if ((int) ($pt['id'] ?? 0) !== $sessionUid) {
                $others[] = $pt;
            }
        }
        $commTeamHasOthers = count($others) > 0;

        if ($commTeamHasOthers) {
            $ids = array_map(static fn (array $pt): int => (int) $pt['id'], $others);
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $uStmt = $pdo->prepare(
                "SELECT id, name, email, phone, whatsapp_phone FROM users WHERE id IN ($placeholders)"
            );
            $uStmt->execute($ids);
            $contactById = [];
            while ($uRow = $uStmt->fetch(PDO::FETCH_ASSOC)) {
                $contactById[(int) $uRow['id']] = $uRow;
            }
            foreach ($others as $pt) {
                $uid = (int) $pt['id'];
                $uRow = $contactById[$uid] ?? [
                    'name' => $pt['name'] ?? '',
                    'email' => $pt['email'] ?? '',
                    'phone' => '',
                    'whatsapp_phone' => '',
                ];
                $phone = trim((string) ($uRow['phone'] ?? ''));
                $waPhone = trim((string) ($uRow['whatsapp_phone'] ?? ''));
                $email = trim((string) ($uRow['email'] ?? ''));
                $waDigits = $digitsOnly($waPhone !== '' ? $waPhone : $phone);
                $commTeamContactRows[] = [
                    'name' => (string) ($uRow['name'] ?? $pt['name'] ?? 'Teammate'),
                    'wa_href' => $waDigits !== ''
                        ? ('https://wa.me/' . $waDigits . '?text=' . rawurlencode($commMessageSubject . "\n\n"))
                        : '',
                    'tel_href' => $phone !== ''
                        ? ('tel:' . preg_replace('/\s+/', '', $phone))
                        : '',
                    'mailto_href' => $email !== ''
                        ? ('mailto:' . rawurlencode($email)
                            . '?subject=' . rawurlencode($commMessageSubject)
                            . '&body=' . rawurlencode($commMessageSubject . "\n\n"))
                        : '',
                ];
            }
        }

        $pstmt = $pdo->prepare('SELECT * FROM projects WHERE id = ? LIMIT 1');
        $pstmt->execute([$commFocusProjectId]);
        $commFocusProjectRow = $pstmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($commFocusProjectRow && $sessionUid > 0) {
            $canInviteFocusProjectTeam = team_invitation_tables_ready($pdo)
                && user_can_send_project_team_invitation($pdo, $sessionUid, $commFocusProjectRow);
        }
    } catch (Throwable $e) {
        // Hub panel still renders without outbound links.
    }
}

$commAddCollaboratorHref = ($commFocusProjectId > 0)
    ? (BASE_URL . 'modules/projects/view?id=' . $commFocusProjectId . '&open_team=1')
    : '';
?>

<style>
    .project-hub-header {
        display: flex;
        flex-direction: column;
        gap: 1rem;
    }

    .project-hub-header-kicker {
        display: inline-flex;
        align-items: center;
        gap: 0.4rem;
        padding: 0.38rem 0.78rem;
        border-radius: 999px;
        background: rgba(24, 123, 116, 0.12);
        color: #0f766e;
        font-size: 0.74rem;
        font-weight: 700;
        letter-spacing: 0.08em;
        text-transform: uppercase;
    }

    .project-hub-subtitle {
        margin-top: 0.6rem;
        font-size: 0.94rem;
        color: #5f6f82;
    }

    .project-hub-overview {
        display: grid;
        gap: 1.4rem;
    }

    .project-hub-summary,
    .project-hub-activity,
    .project-filter-shell,
    .project-empty-shell,
    .project-card-shell {
        position: relative;
        overflow: hidden;
        border-radius: 1.7rem;
        border: 1px solid rgba(226, 232, 240, 0.94);
        background: linear-gradient(180deg, rgba(255, 255, 255, 0.99), rgba(247, 250, 255, 0.98));
        box-shadow: 0 28px 60px -42px rgba(15, 23, 42, 0.3);
    }

    .project-hub-summary,
    .project-hub-activity,
    .project-filter-shell,
    .project-empty-shell {
        padding: 1.3rem;
    }

    .project-hub-summary-card {
        position: relative;
        overflow: hidden;
        padding: 1.35rem;
        border-radius: 1.5rem;
        background: linear-gradient(135deg, #0f766e 0%, #187b74 58%, #34a38f 100%);
        color: #ffffff;
    }

    .project-hub-summary-card::after {
        content: "";
        position: absolute;
        right: -3rem;
        bottom: -3rem;
        width: 12rem;
        height: 12rem;
        border-radius: 999px;
        background: radial-gradient(circle, rgba(255, 255, 255, 0.2), transparent 68%);
    }

    .project-hub-summary-label {
        position: relative;
        z-index: 1;
        font-size: 0.72rem;
        font-weight: 700;
        letter-spacing: 0.12em;
        text-transform: uppercase;
        color: rgba(255, 255, 255, 0.74);
    }

    .project-hub-summary-value {
        position: relative;
        z-index: 1;
        margin-top: 0.7rem;
        font-size: clamp(2rem, 4vw, 2.8rem);
        line-height: 1;
        font-weight: 800;
        letter-spacing: -0.04em;
    }

    .project-hub-summary-meta {
        position: relative;
        z-index: 1;
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 0.85rem;
        margin-top: 1rem;
    }

    .project-hub-summary-meta span {
        display: block;
        font-size: 0.68rem;
        font-weight: 700;
        letter-spacing: 0.1em;
        text-transform: uppercase;
        color: rgba(255, 255, 255, 0.68);
    }

    .project-hub-summary-meta strong {
        display: block;
        margin-top: 0.28rem;
        font-size: 0.98rem;
        font-weight: 700;
    }

    .project-hub-progress-track {
        position: relative;
        z-index: 1;
        margin-top: 1.2rem;
        height: 0.7rem;
        border-radius: 999px;
        background: rgba(255, 255, 255, 0.16);
        overflow: hidden;
    }

    .project-hub-progress-track > span {
        display: block;
        height: 100%;
        border-radius: inherit;
        background: linear-gradient(90deg, rgba(255, 255, 255, 0.96), rgba(191, 219, 254, 0.92));
    }

    .project-hub-metric-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 0.85rem;
        margin-top: 1rem;
    }

    .project-hub-metric {
        padding: 0.95rem 1rem;
        border-radius: 1.2rem;
        border: 1px solid rgba(226, 232, 240, 0.92);
        background: rgba(248, 250, 255, 0.94);
    }

    .project-hub-metric span {
        display: block;
        font-size: 0.72rem;
        font-weight: 700;
        letter-spacing: 0.1em;
        text-transform: uppercase;
        color: #6c7f78;
    }

    .project-hub-metric strong {
        display: block;
        margin-top: 0.35rem;
        font-size: 1.35rem;
        font-weight: 800;
        letter-spacing: -0.03em;
        color: #14302d;
    }

    .project-hub-metric.is-danger strong {
        color: #b91c1c;
    }

    .project-hub-telemetry {
        display: grid;
        gap: 0.85rem;
        margin-top: 1.1rem;
    }

    @media (min-width: 768px) {
        .project-hub-telemetry {
            grid-template-columns: repeat(3, minmax(0, 1fr));
        }
    }

    .project-hub-telemetry-card {
        padding: 1rem 1.05rem;
        border-radius: 1.2rem;
        border: 1px solid rgba(226, 232, 240, 0.92);
        background: rgba(255, 255, 255, 0.88);
    }

    .project-hub-telemetry-card span {
        display: block;
        font-size: 0.68rem;
        font-weight: 700;
        letter-spacing: 0.1em;
        text-transform: uppercase;
        color: #64748b;
    }

    .project-hub-telemetry-card strong {
        display: block;
        margin-top: 0.4rem;
        font-size: 1.2rem;
        font-weight: 800;
        color: #14302d;
        letter-spacing: -0.02em;
    }

    .project-hub-telemetry-note {
        margin-top: 0.45rem;
        font-size: 0.76rem;
        line-height: 1.45;
        color: #5f6f82;
    }

    .project-hub-reporting-bar {
        margin-top: 1.1rem;
        padding: 1rem 1.15rem;
        border-radius: 1.25rem;
        border: 1px dashed rgba(24, 123, 116, 0.28);
        background: rgba(24, 123, 116, 0.05);
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        justify-content: space-between;
        gap: 0.85rem;
    }

    .project-hub-reporting-bar p {
        margin: 0;
        font-size: 0.84rem;
        color: #3d4f5c;
        line-height: 1.5;
        max-width: 42rem;
    }

    .project-hub-reporting-actions {
        display: flex;
        flex-wrap: wrap;
        gap: 0.5rem;
    }

    .project-hub-reporting-actions a {
        display: inline-flex;
        align-items: center;
        gap: 0.35rem;
        padding: 0.45rem 0.85rem;
        border-radius: 999px;
        font-size: 0.78rem;
        font-weight: 700;
        text-decoration: none;
        border: 1px solid rgba(24, 123, 116, 0.35);
        color: #0f5f59;
        background: rgba(255, 255, 255, 0.92);
    }

    .project-hub-reporting-actions a:hover {
        background: #ffffff;
        border-color: rgba(24, 123, 116, 0.55);
    }

    .project-premium-card {
        position: relative;
        overflow: hidden;
        border-radius: 1.65rem;
        border: 1px solid rgba(226, 232, 240, 0.94);
        background: linear-gradient(180deg, rgba(255, 255, 255, 0.99) 0%, rgba(248, 250, 255, 0.97) 100%);
        box-shadow: 0 28px 60px -42px rgba(15, 23, 42, 0.28);
        transition: box-shadow 0.2s ease, transform 0.2s ease;
        cursor: pointer;
    }

    .project-premium-card:hover {
        box-shadow: 0 34px 70px -38px rgba(15, 23, 42, 0.34);
        transform: translateY(-2px);
    }

    .project-premium-card__hero {
        position: relative;
        padding: 1.15rem 1.35rem 1.05rem;
        color: #ffffff;
        min-height: 4.6rem;
    }

    .project-premium-card__hero::after {
        content: "";
        position: absolute;
        right: -2rem;
        top: -2rem;
        width: 8rem;
        height: 8rem;
        border-radius: 999px;
        background: radial-gradient(circle, rgba(255, 255, 255, 0.22), transparent 68%);
        pointer-events: none;
    }

    .project-premium-card__hero-top {
        position: relative;
        z-index: 1;
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 0.75rem;
    }

    .project-premium-card__hero h3 {
        margin: 0;
        font-size: 1.08rem;
        font-weight: 800;
        letter-spacing: -0.02em;
        line-height: 1.25;
        text-shadow: 0 1px 12px rgba(15, 23, 42, 0.2);
    }

    .project-premium-card__pill {
        position: relative;
        z-index: 1;
        display: inline-flex;
        align-items: center;
        padding: 0.28rem 0.65rem;
        border-radius: 999px;
        font-size: 0.68rem;
        font-weight: 800;
        letter-spacing: 0.06em;
        text-transform: uppercase;
        background: rgba(255, 255, 255, 0.22);
        border: 1px solid rgba(255, 255, 255, 0.38);
        backdrop-filter: blur(6px);
        white-space: nowrap;
    }

    .project-premium-card__body {
        padding: 1.15rem 1.35rem 1.25rem;
    }

    .project-premium-card__meta {
        display: flex;
        flex-wrap: wrap;
        gap: 0.65rem 1rem;
        font-size: 0.78rem;
        color: #64748b;
    }

    .project-premium-card__meta span {
        display: inline-flex;
        align-items: center;
        gap: 0.28rem;
    }

    .project-premium-card__meta svg.lucide {
        width: 1rem;
        height: 1rem;
        color: #94a3b8;
        flex-shrink: 0;
    }

    .project-premium-card__progress {
        margin-top: 1rem;
        padding: 0.85rem 0.95rem;
        border-radius: 1.05rem;
        background: rgba(248, 250, 255, 0.94);
        border: 1px solid rgba(226, 232, 240, 0.92);
    }

    .project-premium-card__progress-top {
        display: flex;
        justify-content: space-between;
        align-items: baseline;
        font-size: 0.72rem;
        font-weight: 700;
        letter-spacing: 0.08em;
        text-transform: uppercase;
        color: #64748b;
    }

    .project-premium-card__progress-top em {
        font-style: normal;
        font-size: 0.86rem;
        font-weight: 800;
        letter-spacing: -0.02em;
        color: #14302d;
    }

    .project-premium-card__track {
        margin-top: 0.45rem;
        height: 0.45rem;
        border-radius: 999px;
        background: rgba(226, 232, 240, 0.95);
        overflow: hidden;
    }

    .project-premium-card__track > span {
        display: block;
        height: 100%;
        border-radius: inherit;
        background: linear-gradient(90deg, rgba(15, 118, 110, 0.95), rgba(52, 163, 143, 0.95));
    }

    .project-premium-card__tasks {
        margin-top: 0.95rem;
    }

    .project-premium-card__tasks header {
        font-size: 0.7rem;
        font-weight: 800;
        letter-spacing: 0.1em;
        text-transform: uppercase;
        color: #6c7f78;
        margin-bottom: 0.5rem;
    }

    .project-premium-card__task-row {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 0.5rem;
        padding: 0.42rem 0;
        border-bottom: 1px solid rgba(241, 245, 249, 0.95);
        font-size: 0.8rem;
    }

    .project-premium-card__task-row:last-child {
        border-bottom: 0;
    }

    .project-premium-card__task-name {
        font-weight: 600;
        color: #1e293b;
        min-width: 0;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .project-premium-card__task-status {
        flex-shrink: 0;
        font-size: 0.68rem;
        font-weight: 700;
        color: #64748b;
    }

    .project-premium-card__docs {
        margin-top: 0.85rem;
        display: flex;
        flex-wrap: wrap;
        gap: 0.5rem;
    }

    .project-premium-chip {
        display: inline-flex;
        align-items: center;
        gap: 0.28rem;
        padding: 0.32rem 0.62rem;
        border-radius: 999px;
        font-size: 0.7rem;
        font-weight: 700;
        background: rgba(24, 123, 116, 0.08);
        color: #0f766e;
        border: 1px solid rgba(24, 123, 116, 0.12);
    }

    .project-premium-chip.is-muted {
        background: rgba(148, 163, 184, 0.14);
        color: #475569;
        border-color: rgba(148, 163, 184, 0.22);
    }

    .project-premium-card__footer {
        margin-top: 1.05rem;
        padding-top: 0.95rem;
        border-top: 1px solid rgba(226, 232, 240, 0.92);
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        justify-content: space-between;
        gap: 0.65rem;
    }

    .project-premium-card__open {
        display: inline-flex;
        align-items: center;
        gap: 0.35rem;
        font-size: 0.8rem;
        font-weight: 700;
        color: #0f766e;
        text-decoration: none;
    }

    .project-premium-card__open:hover {
        text-decoration: underline;
    }

    .project-portfolio-fab {
        position: fixed;
        right: 1.5rem;
        bottom: 1.5rem;
        z-index: 40;
        width: 3.35rem;
        height: 3.35rem;
        border-radius: 999px;
        border: none;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        color: #ffffff;
        background: linear-gradient(135deg, #2563eb 0%, #0d9488 100%);
        box-shadow: 0 16px 36px -12px rgba(37, 99, 235, 0.55), 0 8px 20px -8px rgba(13, 148, 136, 0.45);
        transition: transform 0.18s ease, box-shadow 0.18s ease;
    }

    .project-portfolio-fab:hover {
        transform: scale(1.04);
        box-shadow: 0 20px 40px -12px rgba(37, 99, 235, 0.6), 0 10px 24px -8px rgba(13, 148, 136, 0.5);
    }

    .project-modal-accent {
        height: 5px;
        border-radius: 999px;
        margin: -0.5rem 0 1rem;
    }

    .project-card-grid {
        display: grid;
        gap: 1.25rem;
        margin-bottom: 2rem;
    }

    @media (min-width: 768px) {
        .project-card-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
    }

    @media (min-width: 1280px) {
        .project-card-grid {
            grid-template-columns: repeat(3, minmax(0, 1fr));
        }
    }

    .project-hub-section-head {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 1rem;
        margin-bottom: 1rem;
    }

    .project-hub-section-kicker {
        display: block;
        font-size: 0.72rem;
        font-weight: 700;
        letter-spacing: 0.12em;
        text-transform: uppercase;
        color: #6c7f78;
    }

    .project-hub-section-title {
        margin-top: 0.35rem;
        font-size: 1.45rem;
        line-height: 1.05;
        font-weight: 800;
        letter-spacing: -0.03em;
        color: #14302d;
    }

    .project-hub-highlight-chip {
        display: inline-flex;
        align-items: center;
        gap: 0.42rem;
        padding: 0.58rem 0.86rem;
        border-radius: 999px;
        border: 1px solid rgba(24, 123, 116, 0.14);
        background: rgba(24, 123, 116, 0.08);
        color: #0f766e;
        font-size: 0.78rem;
        font-weight: 700;
        white-space: nowrap;
    }

    .project-hub-highlight-chip i {
        font-size: 1rem;
    }

    .project-hub-activity-list {
        display: grid;
        gap: 1rem;
    }

    .project-hub-activity-item {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 0.8rem;
        padding: 0.95rem 1rem;
        border-radius: 1.2rem;
        border: 1px solid rgba(226, 232, 240, 0.92);
        background: rgba(248, 250, 255, 0.92);
    }

    .project-hub-activity-main {
        display: flex;
        align-items: center;
        gap: 0.8rem;
        min-width: 0;
    }

    .project-hub-activity-mark {
        width: 2.7rem;
        height: 2.7rem;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 1rem;
        flex-shrink: 0;
    }

    .project-hub-activity-mark.is-info,
    .project-hub-activity-mark.is-accent { background: rgba(24, 123, 116, 0.12); color: #0f766e; }
    .project-hub-activity-mark.is-success { background: rgba(16, 185, 129, 0.12); color: #059669; }
    .project-hub-activity-mark.is-danger { background: rgba(239, 68, 68, 0.12); color: #dc2626; }
    .project-hub-activity-mark.is-neutral { background: rgba(148, 163, 184, 0.16); color: #475569; }

    .project-hub-activity-title {
        font-size: 0.92rem;
        font-weight: 700;
        color: #14302d;
        line-height: 1.35;
    }

    .project-hub-activity-subtitle {
        margin-top: 0.24rem;
        font-size: 0.78rem;
        color: #64748b;
    }

    .project-hub-activity-value {
        min-width: 4.8rem;
        text-align: right;
        font-size: 0.8rem;
        font-weight: 700;
        color: #0f5f59;
    }

    .project-hub-activity-value span {
        display: block;
        margin-top: 0.2rem;
        font-size: 0.72rem;
        color: #94a3b8;
    }

    .project-filter-form .todo-modal-body {
        gap: 1rem;
        background: linear-gradient(180deg, rgba(248, 250, 255, 0.65) 0%, rgba(255, 255, 255, 0.96) 100%);
    }

    .project-filter-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 0.9rem 1rem;
    }

    .project-filter-field--search {
        grid-column: 1 / -1;
    }

    .project-filter-field label {
        font-size: 0.75rem;
        font-weight: 700;
        letter-spacing: 0.03em;
        text-transform: uppercase;
        color: #607186;
    }

    .project-filter-field .todo-input,
    .project-filter-field .todo-select {
        min-height: 44px;
        border-radius: 0.82rem;
        border: 1px solid rgba(180, 196, 220, 0.9);
        background: linear-gradient(180deg, rgba(255, 255, 255, 0.98) 0%, rgba(248, 250, 255, 0.98) 100%);
        box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.7), 0 1px 2px rgba(15, 23, 42, 0.05);
        transition: border-color 0.15s ease, box-shadow 0.15s ease, background 0.15s ease;
    }

    .project-filter-field .todo-input::placeholder {
        color: #94a3b8;
    }

    .project-filter-field .todo-input:focus,
    .project-filter-field .todo-select:focus {
        border-color: rgba(24, 123, 116, 0.24);
        box-shadow: 0 0 0 3px rgba(24, 123, 116, 0.14), 0 3px 10px rgba(24, 123, 116, 0.12);
        background: #ffffff;
    }

    .project-filter-form .todo-modal-footer {
        border-top-color: rgba(226, 232, 240, 0.9);
    }

    @media (max-width: 768px) {
        .project-filter-grid {
            grid-template-columns: 1fr;
        }
    }

    .project-card-body {
        padding: 1.35rem;
    }

    .project-card-row {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 0.9rem;
    }

    .project-card-title {
        font-size: 1.15rem;
        font-weight: 800;
        line-height: 1.25;
        color: #14302d;
    }

    .project-card-desc {
        margin-top: 0.85rem;
        min-height: 3.6rem;
        font-size: 0.88rem;
        line-height: 1.55;
        color: #5f6f82;
    }

    .project-card-pills {
        display: flex;
        flex-wrap: wrap;
        gap: 0.5rem;
        margin-top: 0.9rem;
    }

    .project-card-soft-pill {
        display: inline-flex;
        align-items: center;
        gap: 0.35rem;
        padding: 0.42rem 0.72rem;
        border-radius: 999px;
        font-size: 0.72rem;
        font-weight: 700;
        background: rgba(24, 123, 116, 0.08);
        color: #0f766e;
    }

    .project-card-progress,
    .project-card-meta-box {
        border-radius: 1.1rem;
        background: rgba(248, 250, 255, 0.9);
        border: 1px solid rgba(226, 232, 240, 0.9);
    }

    .project-card-progress {
        margin-top: 1rem;
        padding: 0.95rem 1rem;
    }

    .project-card-progress-track {
        margin-top: 0.55rem;
        height: 0.5rem;
        border-radius: 999px;
        background: rgba(226, 232, 240, 0.9);
        overflow: hidden;
    }

    .project-card-progress-track > span {
        display: block;
        height: 100%;
        border-radius: inherit;
        background: linear-gradient(90deg, #187b74, #41b38f);
    }

    .project-card-meta {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 0.75rem;
        margin-top: 1rem;
    }

    .project-card-meta-box {
        padding: 0.88rem 0.92rem;
    }

    .project-card-meta-box span {
        display: block;
        font-size: 0.7rem;
        font-weight: 700;
        letter-spacing: 0.1em;
        text-transform: uppercase;
        color: #94a3b8;
    }

    .project-card-meta-box strong {
        display: block;
        margin-top: 0.35rem;
        font-size: 0.86rem;
        font-weight: 700;
        color: #14302d;
        line-height: 1.4;
    }

    .project-card-footer {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 0.9rem;
        margin-top: 1.1rem;
        padding-top: 1rem;
        border-top: 1px solid rgba(226, 232, 240, 0.92);
    }

    .project-card-actions {
        display: flex;
        align-items: center;
        gap: 0.55rem;
    }

    .project-card-open {
        display: inline-flex;
        align-items: center;
        gap: 0.35rem;
        color: #0f766e;
        font-size: 0.82rem;
        font-weight: 700;
    }

    .project-hub-focus-list {
        display: grid;
        gap: 0.9rem;
    }

    .project-hub-focus-item {
        display: grid;
        grid-template-columns: auto 1fr auto auto;
        gap: 0.85rem;
        align-items: center;
        padding: 0.95rem 1rem;
        border-radius: 1.2rem;
        border: 1px solid rgba(226, 232, 240, 0.92);
        background: rgba(248, 250, 255, 0.92);
        text-decoration: none;
        color: inherit;
        transition: border-color 0.15s ease, background 0.15s ease;
    }

    a.project-hub-focus-item:hover {
        border-color: rgba(15, 118, 110, 0.22);
        background: rgba(255, 255, 255, 0.98);
    }

    .project-hub-focus-icon {
        width: 2.7rem;
        height: 2.7rem;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 1rem;
        flex-shrink: 0;
    }

    .project-hub-focus-icon.is-accent {
        background: rgba(24, 123, 116, 0.12);
        color: #0f766e;
    }

    .project-hub-focus-icon.is-success {
        background: rgba(16, 185, 129, 0.12);
        color: #059669;
    }

    .project-hub-focus-icon.is-danger {
        background: rgba(239, 68, 68, 0.12);
        color: #dc2626;
    }

    .project-hub-focus-icon.is-neutral {
        background: rgba(148, 163, 184, 0.16);
        color: #475569;
    }

    .project-hub-focus-icon.is-purple {
        background: rgba(124, 58, 237, 0.12);
        color: #6d28d9;
    }

    .project-hub-focus-chevron {
        color: #94a3b8;
        flex-shrink: 0;
    }

    .project-hub-focus-chevron svg.lucide {
        width: 1.25rem;
        height: 1.25rem;
    }

    /* ——— Landing header & search (matches portfolio overview mock) ——— */
    .project-landing-title {
        font-size: clamp(1.75rem, 3.2vw, 2.25rem);
        font-weight: 800;
        letter-spacing: -0.045em;
        color: #0c2340;
        margin: 0;
        line-height: 1.15;
    }

    .project-landing-search-wrap {
        display: flex;
        justify-content: center;
        width: 100%;
        padding: 0 0 0.25rem;
        margin-top: 1rem;
    }

    .project-landing-search {
        display: flex;
        align-items: center;
        gap: 0.65rem;
        width: min(560px, 100%);
        padding: 0.65rem 1rem 0.65rem 1rem;
        border-radius: 999px;
        border: 1px solid rgba(203, 213, 225, 0.95);
        background: #ffffff;
        box-shadow: 0 8px 28px -18px rgba(15, 23, 42, 0.25);
    }

    .project-landing-search i[data-lucide] {
        width: 1.15rem;
        height: 1.15rem;
        color: #94a3b8;
        flex-shrink: 0;
    }

    .project-landing-search input[type="search"],
    .project-landing-search input[type="text"] {
        flex: 1;
        min-width: 0;
        border: none;
        outline: none;
        background: transparent;
        font-size: 0.92rem;
        color: #1e293b;
    }

    .project-landing-search input::placeholder {
        color: #94a3b8;
    }

    .project-view-btn.is-active {
        background: rgba(15, 118, 110, 0.12);
        color: #0f766e;
        border-color: rgba(15, 118, 110, 0.28);
    }

    .project-new-dd {
        position: relative;
    }

    .project-new-dd-menu {
        display: none;
        position: absolute;
        right: 0;
        top: calc(100% + 6px);
        min-width: 11.5rem;
        padding: 0.4rem;
        border-radius: 0.75rem;
        border: 1px solid rgba(226, 232, 240, 0.98);
        background: #ffffff;
        box-shadow: 0 18px 40px -24px rgba(15, 23, 42, 0.35);
        z-index: 50;
    }

    .project-new-dd-menu.is-open {
        display: block;
    }

    .project-new-dd-menu a {
        display: flex;
        align-items: center;
        gap: 0.45rem;
        padding: 0.5rem 0.75rem;
        border-radius: 0.5rem;
        font-size: 0.82rem;
        font-weight: 600;
        color: #334155;
        text-decoration: none;
    }

    .project-new-dd-menu a:hover {
        background: rgba(15, 118, 110, 0.08);
        color: #0f766e;
    }

    .project-new-dd-menu a svg.lucide {
        width: 1rem;
        height: 1rem;
        flex-shrink: 0;
    }

    .todo-btn-primary.project-new-split {
        display: inline-flex;
        align-items: center;
        gap: 0.35rem;
        padding-right: 0.55rem;
        background: #047857;
        border: 1px solid rgba(6, 95, 70, 0.35);
    }

    .todo-btn-primary.project-new-split:hover {
        filter: brightness(1.04);
    }

    .project-new-split-main {
        display: inline-flex;
        align-items: center;
        gap: 0.35rem;
        padding: 0 0.2rem;
        text-decoration: none;
        color: inherit;
    }

    .project-new-split-toggle {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 1.75rem;
        height: 100%;
        border: none;
        border-left: 1px solid rgba(255, 255, 255, 0.28);
        background: transparent;
        color: inherit;
        cursor: pointer;
        border-radius: 0 0.45rem 0.45rem 0;
    }

    /* ——— Portfolio health hero ——— */
    .project-portfolio-health {
        position: relative;
        overflow: hidden;
        border-radius: 1.5rem;
        margin-bottom: 1.5rem;
        background: linear-gradient(125deg, #0a5c56 0%, #0f766e 42%, #127f77 100%);
        color: #ffffff;
        box-shadow: 0 28px 60px -42px rgba(15, 23, 42, 0.38);
    }

    .project-portfolio-health__wave {
        pointer-events: none;
        position: absolute;
        inset: 0;
        opacity: 0.55;
        /* Soft teal wash + very faint curves (stroke-opacity baked into SVG) */
        background-image:
            linear-gradient(
                165deg,
                rgba(255, 255, 255, 0.045) 0%,
                transparent 42%,
                rgba(255, 255, 255, 0.025) 78%,
                transparent 100%
            ),
            url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 1200 200' preserveAspectRatio='none'%3E%3Cpath fill='none' stroke='%23ffffff' stroke-opacity='0.14' stroke-width='1' d='M0 122 Q 320 48 600 102 T 1200 82'/%3E%3Cpath fill='none' stroke='%23ffffff' stroke-opacity='0.07' stroke-width='0.65' d='M0 154 Q 420 96 820 132 T 1200 104'/%3E%3C/svg%3E");
        background-repeat: no-repeat;
        background-size: cover;
        background-position: 50% 58%;
    }

    .project-portfolio-health__inner {
        position: relative;
        z-index: 1;
        display: grid;
        gap: 1.35rem;
        padding: 1.5rem 1.45rem 1.55rem;
    }

    @media (min-width: 1024px) {
        .project-portfolio-health__inner {
            grid-template-columns: minmax(0, 1.25fr) minmax(280px, 1fr);
            align-items: stretch;
            gap: 2rem;
            padding: 1.65rem 1.75rem 1.75rem;
        }
    }

    .project-ph-kicker {
        display: block;
        font-size: 0.68rem;
        font-weight: 700;
        letter-spacing: 0.16em;
        text-transform: uppercase;
        color: rgba(255, 255, 255, 0.72);
        margin-bottom: 0.5rem;
    }

    .project-ph-headline {
        margin: 0;
        font-size: clamp(1.35rem, 2.5vw, 1.75rem);
        font-weight: 800;
        letter-spacing: -0.03em;
        line-height: 1.15;
    }

    .project-ph-lede {
        margin: 0.65rem 0 0;
        font-size: 0.9rem;
        line-height: 1.55;
        color: rgba(226, 232, 240, 0.92);
        max-width: 36rem;
    }

    .project-ph-completion {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        gap: 1.1rem;
        margin-top: 1.25rem;
    }

    .project-ph-ring-wrap {
        position: relative;
        width: 5.25rem;
        height: 5.25rem;
        flex-shrink: 0;
    }

    .project-ph-ring-bg,
    .project-ph-ring-fg {
        position: absolute;
        left: 0;
        top: 0;
        width: 5.25rem;
        height: 5.25rem;
    }

    .project-ph-ring-fg {
        transform: rotate(-90deg);
    }

    .project-ph-ring-fg circle {
        transition: stroke-dashoffset 0.6s ease;
    }

    .project-ph-ring-label {
        position: absolute;
        inset: 0;
        z-index: 1;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        text-align: center;
        font-weight: 800;
        font-size: 1.05rem;
        letter-spacing: -0.03em;
    }

    .project-ph-ring-label span {
        display: block;
        font-size: 0.62rem;
        font-weight: 700;
        letter-spacing: 0.12em;
        text-transform: uppercase;
        color: rgba(255, 255, 255, 0.7);
        margin-top: 0.15rem;
    }

    .project-ph-bar-block {
        flex: 1;
        min-width: 200px;
    }

    .project-ph-bar-label {
        display: flex;
        justify-content: space-between;
        align-items: baseline;
        font-size: 0.78rem;
        font-weight: 700;
        color: rgba(255, 255, 255, 0.88);
        margin-bottom: 0.45rem;
    }

    .project-ph-bar-label em {
        font-style: normal;
        font-weight: 800;
        font-size: 0.95rem;
    }

    .project-ph-bar {
        height: 0.55rem;
        border-radius: 999px;
        background: rgba(255, 255, 255, 0.18);
        overflow: hidden;
    }

    .project-ph-bar > span {
        display: block;
        height: 100%;
        border-radius: inherit;
        background: linear-gradient(90deg, rgba(255, 255, 255, 0.98), rgba(186, 230, 253, 0.95));
    }

    .project-ph-stats {
        display: grid;
        grid-template-columns: 1fr 1fr;
        border-radius: 1.1rem;
        overflow: hidden;
        border: 1px solid rgba(255, 255, 255, 0.2);
        background: rgba(0, 0, 0, 0.08);
        align-self: center;
    }

    .project-ph-cell {
        padding: 1rem 1.05rem;
        border-right: 1px solid rgba(255, 255, 255, 0.14);
        border-bottom: 1px solid rgba(255, 255, 255, 0.14);
    }

    .project-ph-cell:nth-child(2n) {
        border-right: 0;
    }

    .project-ph-cell:nth-child(n + 3) {
        border-bottom: 0;
    }

    .project-ph-cell-label {
        display: block;
        font-size: 0.65rem;
        font-weight: 700;
        letter-spacing: 0.12em;
        text-transform: uppercase;
        color: rgba(255, 255, 255, 0.58);
    }

    .project-ph-cell strong {
        display: block;
        margin-top: 0.4rem;
        font-size: 1.2rem;
        font-weight: 800;
        letter-spacing: -0.03em;
    }

    .project-ph-cell .project-ph-muted {
        margin-top: 0.28rem;
        font-size: 0.72rem;
        color: rgba(226, 232, 240, 0.85);
    }

    .project-ph-cell .is-attn {
        color: #fecaca;
        font-weight: 800;
    }

    .project-ph-mini {
        margin-top: 0.45rem;
        height: 0.32rem;
        border-radius: 999px;
        background: rgba(255, 255, 255, 0.14);
        overflow: hidden;
    }

    .project-ph-mini > span {
        display: block;
        height: 100%;
        border-radius: inherit;
        background: rgba(255, 255, 255, 0.82);
    }

    /* ——— Post-grid executive band ——— */
    .project-landing-bottom {
        display: grid;
        gap: 1.25rem;
        margin-top: 0.5rem;
        margin-bottom: 2.5rem;
    }

    @media (min-width: 1024px) {
        .project-landing-bottom {
            grid-template-columns: minmax(0, 1.1fr) minmax(0, 0.9fr);
            align-items: start;
            gap: 1.5rem;
        }
    }

    .project-landing-panel {
        border-radius: 1.5rem;
        border: 1px solid rgba(226, 232, 240, 0.94);
        background: linear-gradient(180deg, rgba(255, 255, 255, 0.99), rgba(248, 250, 255, 0.96));
        box-shadow: 0 24px 56px -40px rgba(15, 23, 42, 0.28);
        padding: 1.25rem 1.35rem 1.35rem;
    }

    .project-landing-panel__head {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 0.75rem;
        margin-bottom: 1.05rem;
    }

    .project-landing-panel__head h2 {
        margin: 0.2rem 0 0;
        font-size: 1.08rem;
        font-weight: 800;
        letter-spacing: -0.025em;
        color: #14302d;
        line-height: 1.25;
    }

    .project-landing-panel__head .project-hub-section-kicker {
        margin-bottom: 0.15rem;
    }

    .project-landing-view-all {
        font-size: 0.78rem;
        font-weight: 700;
        color: #0f766e;
        text-decoration: none;
        white-space: nowrap;
    }

    .project-landing-view-all:hover {
        text-decoration: underline;
    }

    .project-comm-icons {
        display: flex;
        flex-wrap: wrap;
        gap: 0.65rem;
        margin-top: 0.85rem;
    }

    .project-comm-icons a.project-comm-icons__btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 2.5rem;
        height: 2.5rem;
        border-radius: 0.75rem;
        border: 1px solid rgba(226, 232, 240, 0.95);
        background: rgba(248, 250, 255, 0.92);
        color: #475569;
        text-decoration: none;
        transition: background 0.15s ease, border-color 0.15s ease, color 0.15s ease;
    }

    .project-comm-icons a.project-comm-icons__btn:hover {
        background: rgba(240, 253, 250, 0.95);
        border-color: rgba(45, 212, 191, 0.35);
        color: #0f766e;
    }

    .project-comm-icons a.project-comm-icons__btn svg.lucide {
        width: 1.2rem;
        height: 1.2rem;
    }

    .project-comm-team {
        display: flex;
        flex-direction: column;
        gap: 0.55rem;
        margin-top: 0.85rem;
    }

    .project-comm-team-row {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        gap: 0.5rem 0.75rem;
        padding: 0.45rem 0.55rem;
        border-radius: 0.85rem;
        border: 1px solid rgba(226, 232, 240, 0.95);
        background: rgba(255, 255, 255, 0.72);
    }

    .project-comm-team-name {
        flex: 1 1 8rem;
        min-width: 0;
        font-size: 0.84rem;
        font-weight: 650;
        color: #334155;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .project-comm-team-actions {
        display: inline-flex;
        flex-wrap: wrap;
        gap: 0.45rem;
        align-items: center;
    }

    .project-comm-team-note {
        margin: 0;
        font-size: 0.75rem;
        color: #94a3b8;
        flex-basis: 100%;
    }

    .project-comm-invite-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 0.35rem;
        margin-top: 0.85rem;
        padding: 0.42rem 0.95rem;
        border-radius: 0.75rem;
        border: 1px solid rgba(13, 148, 136, 0.35);
        background: rgba(240, 253, 250, 0.92);
        color: #0f766e;
        font-size: 0.8rem;
        font-weight: 700;
        text-decoration: none;
        transition: background 0.15s ease, border-color 0.15s ease;
    }

    .project-comm-invite-btn:hover {
        background: rgba(204, 251, 241, 0.98);
        border-color: rgba(13, 148, 136, 0.55);
    }

    .project-premium-card__hero-row {
        position: relative;
        z-index: 1;
        display: flex;
        align-items: flex-start;
        gap: 0.75rem;
    }

    .project-premium-card__avatar {
        flex-shrink: 0;
        width: 2.5rem;
        height: 2.5rem;
        border-radius: 999px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 0.82rem;
        font-weight: 800;
        letter-spacing: 0.04em;
        background: rgba(255, 255, 255, 0.22);
        border: 1px solid rgba(255, 255, 255, 0.42);
        box-shadow: 0 6px 16px rgba(15, 23, 42, 0.12);
    }

    .project-premium-card__hero-text {
        flex: 1;
        min-width: 0;
        padding-right: 0.25rem;
    }

    .project-premium-card__hero-text h3 {
        margin: 0.1rem 0 0;
    }

    .project-premium-card__hero-row .project-premium-card__pill {
        flex-shrink: 0;
        margin-left: auto;
    }

    .project-card-grid--list {
        grid-template-columns: 1fr;
    }

    .project-add-card {
        border: 2px dashed rgba(148, 163, 184, 0.55);
        border-radius: 1.65rem;
        background: rgba(255, 255, 255, 0.65);
        min-height: 17rem;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        gap: 0.65rem;
        text-decoration: none;
        color: #64748b;
        transition: border-color 0.18s ease, background 0.18s ease, color 0.18s ease;
    }

    .project-add-card:hover {
        border-color: rgba(15, 118, 110, 0.45);
        color: #0f766e;
        background: rgba(15, 118, 110, 0.04);
    }

    .project-add-card i[data-lucide] {
        width: 2rem;
        height: 2rem;
        stroke-width: 1.75;
    }

    .project-add-card span {
        font-size: 0.88rem;
        font-weight: 700;
    }

    .project-section-cards-hr {
        margin: 2rem 0 1.15rem;
    }

    .project-hub-focus-copy strong {
        display: block;
        font-size: 0.9rem;
        font-weight: 800;
        color: #14302d;
    }

    .project-hub-focus-copy span {
        display: block;
        margin-top: 0.28rem;
        font-size: 0.76rem;
        line-height: 1.5;
        color: #5f6f82;
    }

    .project-hub-focus-value {
        font-size: 1.25rem;
        font-weight: 800;
        letter-spacing: -0.03em;
        color: #14302d;
    }

    .project-hub-divider {
        height: 1px;
        margin: 0.35rem 0 0.1rem;
        background: rgba(226, 232, 240, 0.94);
    }

    @media (min-width: 768px) {
        .project-hub-header {
            flex-direction: row;
            align-items: flex-start;
            justify-content: space-between;
        }
    }

    @media (min-width: 1024px) {
        .project-hub-overview {
            grid-template-columns: minmax(0, 1.55fr) minmax(320px, 0.85fr);
        }

        .project-filter-grid {
            grid-template-columns: minmax(0, 2fr) repeat(2, minmax(180px, 1fr)) auto;
            align-items: end;
        }
    }

    .project-hub-header-kicker svg.lucide {
        width: 0.95rem;
        height: 0.95rem;
        flex-shrink: 0;
    }

    .project-hub-highlight-chip svg.lucide,
    .project-hub-focus-icon svg.lucide,
    .project-hub-activity-mark svg.lucide {
        width: 1.25rem;
        height: 1.25rem;
    }

    .project-hub-reporting-actions a svg.lucide {
        width: 1rem;
        height: 1rem;
        flex-shrink: 0;
    }

    .todo-shell--rail .todo-nav-link-left svg.lucide {
        width: 1.125rem;
        height: 1.125rem;
        flex-shrink: 0;
    }

    .todo-header-actions .todo-btn-primary svg.lucide,
    .todo-btn-ghost svg.lucide {
        width: 1rem;
        height: 1rem;
        flex-shrink: 0;
    }

    .todo-add-task svg.lucide.add-icon {
        width: 1.25rem;
        height: 1.25rem;
    }

    .project-portfolio-fab svg.lucide {
        width: 1.5rem;
        height: 1.5rem;
    }

    .project-premium-chip svg.lucide {
        width: 0.95rem;
        height: 0.95rem;
        flex-shrink: 0;
    }

    .project-premium-card__open svg.lucide {
        width: 1rem;
        height: 1rem;
        flex-shrink: 0;
    }

    .todo-empty svg.lucide {
        width: 3rem;
        height: 3rem;
        color: rgba(24, 123, 116, 0.16);
        display: block;
        margin: 0 auto 8px;
    }

    #exportMenu a svg.lucide {
        width: 18px;
        height: 18px;
        flex-shrink: 0;
    }

    .project-card-soft-pill svg.lucide {
        width: 0.875rem;
        height: 0.875rem;
        flex-shrink: 0;
    }

    .todo-modal-footer a svg.lucide,
    .todo-modal-footer button svg.lucide {
        width: 1rem;
        height: 1rem;
        flex-shrink: 0;
    }
</style>

<?php
// Sidebar scopes derived from status/priority filters
$projectSidebar = [
    ['id' => 'all', 'label' => 'All Projects', 'icon' => 'inbox', 'href' => 'list', 'active' => empty($status_filter) && empty($priority_filter) && empty($search_query)],
    ['id' => 'in-progress', 'label' => 'Active', 'icon' => 'circle-play', 'href' => 'list?status=' . urlencode('In Progress'), 'active' => $status_filter === 'In Progress'],
    ['id' => 'planning', 'label' => 'Planning', 'icon' => 'notebook-text', 'href' => 'list?status=' . urlencode('Planning'), 'active' => $status_filter === 'Planning'],
    ['id' => 'on-hold', 'label' => 'On Hold', 'icon' => 'circle-pause', 'href' => 'list?status=' . urlencode('On Hold'), 'active' => $status_filter === 'On Hold'],
    ['id' => 'completed', 'label' => 'Completed', 'icon' => 'circle-check', 'href' => 'list?status=' . urlencode('Completed'), 'active' => $status_filter === 'Completed'],
];

$projectSidebarCounts = [
    'all' => (int) $projectDashboardStats['total'],
    'in-progress' => (int) $projectDashboardStats['in_progress'],
    'planning' => (int) $projectDashboardStats['planning'],
    'on-hold' => (int) $projectDashboardStats['on_hold'],
    'completed' => (int) $projectDashboardStats['completed'],
];

$exportQuery = '';
if (!empty($search_query)) $exportQuery .= '&search=' . urlencode($search_query);
if (!empty($status_filter)) $exportQuery .= '&status=' . urlencode($status_filter);
if (!empty($priority_filter)) $exportQuery .= '&priority=' . urlencode($priority_filter);

?>

<div class="todo-shell todo-shell--sidebar-right todo-shell--rail todo-shell--sidebar-float">
    <button type="button" class="todo-sidebar-toggle" data-ws-sidebar-toggle="wsProjectsSidebar" aria-label="Toggle sidebar">
        <i data-lucide="menu" aria-hidden="true"></i>
    </button>

    <aside class="todo-sidebar todo-sidebar--rail" id="wsProjectsSidebar" aria-label="Projects navigation">
        <div class="todo-sidebar-section-label">Projects</div>
        <?php foreach ($projectSidebar as $nav): ?>
            <a href="<?php echo htmlspecialchars($nav['href']); ?>"
               class="todo-nav-link <?php echo !empty($nav['active']) ? 'is-active' : ''; ?>">
                <span class="todo-nav-link-left">
                    <i data-lucide="<?php echo htmlspecialchars($nav['icon']); ?>" aria-hidden="true"></i>
                    <span><?php echo htmlspecialchars($nav['label']); ?></span>
                </span>
                <span class="todo-nav-badge"><?php echo number_format($projectSidebarCounts[$nav['id']] ?? 0); ?></span>
            </a>
        <?php endforeach; ?>
        <div class="todo-sidebar-divider"></div>
        <div class="todo-sidebar-section-label">Tools</div>
        <a href="#" class="todo-nav-link" data-ws-open="wsProjectsPortfolio">
            <span class="todo-nav-link-left"><i data-lucide="layout-dashboard" aria-hidden="true"></i><span>Portfolio</span></span>
        </a>
        <a href="#" class="todo-nav-link" data-ws-open="wsProjectsFilters">
            <span class="todo-nav-link-left"><i data-lucide="list-filter" aria-hidden="true"></i><span>Filters</span></span>
        </a>
        <a href="analytics" class="todo-nav-link">
            <span class="todo-nav-link-left"><i data-lucide="chart-column" aria-hidden="true"></i><span>Analytics</span></span>
        </a>
        <a href="<?php echo htmlspecialchars(BASE_URL . 'modules/messaging/inbox'); ?>" class="todo-nav-link">
            <span class="todo-nav-link-left"><i data-lucide="messages-square" aria-hidden="true"></i><span>Messages</span></span>
        </a>
    </aside>

    <main class="todo-main todo-main--wide">
        <header class="todo-header" style="flex-direction:column;align-items:stretch;padding-bottom:12px;">
            <div style="display:flex;width:100%;flex-wrap:wrap;justify-content:space-between;align-items:flex-end;gap:12px;">
                <div class="todo-header-copy" style="min-width:min(300px, 100%);">
                    <h1 class="project-landing-title">Project Overview</h1>
                    <p class="todo-header-subtitle" style="margin-top:6px;">
                        <?php echo number_format($projectDashboardStats['total']); ?> projects
                        &middot; <?php echo $projectCompletionRate; ?>% overall completion
                        <?php if ($projectDashboardStats['overdue'] > 0): ?>
                            &middot; <span style="color:#b91c1c;font-weight:600;"><?php echo $projectDashboardStats['overdue']; ?> overdue</span>
                        <?php endif; ?>
                    </p>
                </div>
            <div class="todo-header-actions">
                <button type="button" class="todo-btn-icon project-view-btn is-active" id="projectViewGrid" title="Grid view" aria-label="Grid view" aria-pressed="true">
                    <i data-lucide="layout-grid" aria-hidden="true"></i>
                </button>
                <button type="button" class="todo-btn-icon project-view-btn" id="projectViewList" title="List view" aria-label="List view" aria-pressed="false">
                    <i data-lucide="layout-list" aria-hidden="true"></i>
                </button>
                <button type="button" class="todo-btn-icon" data-ws-open="wsProjectsFilters" title="Filter" aria-label="Filter projects">
                    <i data-lucide="filter" aria-hidden="true"></i>
                </button>
                <div class="relative inline-block">
                    <button id="exportDropdown" type="button" class="todo-btn-icon" title="Export" aria-label="Export projects">
                        <i data-lucide="download" aria-hidden="true"></i>
                    </button>
                    <div id="exportMenu" class="hidden absolute right-0 mt-2 w-48 bg-white rounded-md shadow-lg z-10 border border-gray-200" style="min-width:192px;">
                        <a href="export?format=pdf<?php echo $exportQuery; ?>" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 flex items-center">
                            <i data-lucide="file-text" class="text-red-500 mr-2" aria-hidden="true"></i> Export as PDF
                        </a>
                        <a href="export?format=excel<?php echo $exportQuery; ?>" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 flex items-center">
                            <i data-lucide="table" class="text-green-600 mr-2" aria-hidden="true"></i> Export as Excel
                        </a>
                        <a href="export?format=csv<?php echo $exportQuery; ?>" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 flex items-center">
                            <i data-lucide="file-text" class="text-emerald-600 mr-2" aria-hidden="true"></i> Export as CSV
                        </a>
                    </div>
                </div>
                <a href="analytics" class="todo-btn-icon" title="Analytics" aria-label="Analytics">
                    <i data-lucide="bar-chart-2" aria-hidden="true"></i>
                </a>
                <?php if (hasPermission('manage_projects') || !empty($_SESSION['is_section_head'])): ?>
                <div class="project-new-dd" id="projectNewDd">
                    <div class="todo-btn-primary project-new-split" role="group" aria-label="Create new">
                        <a href="create" class="project-new-split-main">
                            <i data-lucide="plus" aria-hidden="true"></i> New
                        </a>
                        <button type="button" class="project-new-split-toggle" id="projectNewDdToggle" aria-expanded="false" aria-haspopup="true" aria-label="More create options">
                            <i data-lucide="chevron-down" aria-hidden="true"></i>
                        </button>
                    </div>
                    <div class="project-new-dd-menu" id="projectNewDdMenu" role="menu">
                        <a href="create" role="menuitem"><i data-lucide="folder-plus" aria-hidden="true"></i> New program</a>
                        <a href="analytics" role="menuitem"><i data-lucide="line-chart" aria-hidden="true"></i> Open analytics</a>
                    </div>
                </div>
                <?php endif; ?>
            </div>
            </div>
            <form method="get" action="list" class="project-landing-search-wrap" role="search">
                <?php if (!empty($status_filter)): ?>
                    <input type="hidden" name="status" value="<?php echo htmlspecialchars($status_filter); ?>">
                <?php endif; ?>
                <?php if (!empty($priority_filter)): ?>
                    <input type="hidden" name="priority" value="<?php echo htmlspecialchars($priority_filter); ?>">
                <?php endif; ?>
                <div class="project-landing-search">
                    <i data-lucide="search" aria-hidden="true"></i>
                    <label for="projectLandingSearch" class="sr-only">Search projects</label>
                    <input type="search" id="projectLandingSearch" name="search" autocomplete="off"
                           placeholder="Search projects, tasks, documents..."
                           value="<?php echo htmlspecialchars($search_query); ?>">
                </div>
            </form>
        </header>

        <?php if ($search_query || $status_filter || $priority_filter): ?>
            <div style="display:flex;align-items:center;gap:8px;margin-bottom:12px;flex-wrap:wrap;font-size:12px;color:#605e5c;">
                <span>Filtered by:</span>
                <?php if ($search_query): ?><span class="todo-pill">Search: "<?php echo htmlspecialchars($search_query); ?>"</span><?php endif; ?>
                <?php if ($status_filter): ?><span class="todo-pill is-success">Status: <?php echo htmlspecialchars($status_filter); ?></span><?php endif; ?>
                <?php if ($priority_filter): ?><span class="todo-pill is-warning">Priority: <?php echo htmlspecialchars($priority_filter); ?></span><?php endif; ?>
                <a href="list" class="todo-btn-ghost" style="padding:2px 8px;font-size:12px;">
                    <i data-lucide="x" aria-hidden="true"></i> Clear
                </a>
            </div>
        <?php endif; ?>

        <?php
        $phRingCirc = 2 * M_PI * 42;
        $phRingDash = $phRingCirc * ($projectCompletionRate / 100);
        ?>

        <section class="project-portfolio-health" aria-label="Portfolio health">
            <div class="project-portfolio-health__wave" aria-hidden="true"></div>
            <div class="project-portfolio-health__inner">
                <div>
                    <span class="project-ph-kicker">Project portfolio overview</span>
                    <h2 class="project-ph-headline">Lets track how your projects are going</h2>
                    <p class="project-ph-lede">Track, manage, and complete your project and tasks from here</p>
                    <div class="project-ph-completion">
                        <div class="project-ph-ring-wrap" aria-hidden="true">
                            <svg class="project-ph-ring-bg" viewBox="0 0 100 100" width="84" height="84">
                                <circle cx="50" cy="50" r="42" fill="none" stroke="rgba(255,255,255,0.22)" stroke-width="9"/>
                            </svg>
                            <svg class="project-ph-ring-fg" viewBox="0 0 100 100" width="84" height="84" aria-hidden="true">
                                <circle cx="50" cy="50" r="42" fill="none" stroke="rgba(255,255,255,0.96)" stroke-width="9"
                                        stroke-linecap="round"
                                        stroke-dasharray="<?php echo $phRingDash; ?> <?php echo $phRingCirc; ?>"
                                        stroke-dashoffset="0"/>
                            </svg>
                            <div class="project-ph-ring-label">
                                <?php echo (int) $projectCompletionRate; ?>%
                                <span>Complete</span>
                            </div>
                        </div>
                        <div class="project-ph-bar-block">
                            <div class="project-ph-bar-label">
                                <span><?php echo (int) $projectCompletionRate; ?>% Completion rate</span>
                                <em><?php echo number_format($projectDashboardStats['task_completed']); ?> / <?php echo number_format($projectDashboardStats['task_total']); ?> of all tasks</em>
                            </div>
                            <div class="project-ph-bar">
                                <span style="width: <?php echo (int) $projectCompletionRate; ?>%;"></span>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="project-ph-stats" aria-label="Key portfolio figures">
                    <div class="project-ph-cell">
                        <span class="project-ph-cell-label">Total portfolio</span>
                        <strong><?php echo number_format($projectDashboardStats['total']); ?> projects</strong>
                    </div>
                    <div class="project-ph-cell">
                        <span class="project-ph-cell-label">Tracked tasks</span>
                        <strong><?php echo number_format($projectDashboardStats['task_completed']); ?> / <?php echo number_format($projectDashboardStats['task_total']); ?> completed</strong>
                        <div class="project-ph-mini"><span style="width: <?php echo (int) $taskTrackedPct; ?>%;"></span></div>
                    </div>
                    <div class="project-ph-cell">
                        <span class="project-ph-cell-label">Delivery risk</span>
                        <strong class="<?php echo $projectDashboardStats['overdue'] > 0 ? 'is-attn' : ''; ?>"><?php echo number_format($projectDashboardStats['overdue']); ?> overdue</strong>
                        <span class="project-ph-muted"><?php echo $projectDashboardStats['overdue'] > 0 ? 'needs attention' : 'on schedule'; ?></span>
                    </div>
                    <div class="project-ph-cell">
                        <span class="project-ph-cell-label">On track</span>
                        <strong><?php echo number_format($onTrackProjectCount); ?> <?php echo $onTrackProjectCount === 1 ? 'project' : 'projects'; ?></strong>
                    </div>
                </div>
            </div>
        </section>

        <section class="project-card-grid" id="projectCardGrid" role="list" aria-label="Project portfolio cards">
            <?php foreach ($projects as $project): ?>
                <?php
                $progress = $project['task_count'] > 0 ? round(($project['completed_tasks'] / $project['task_count']) * 100) : 0;
                $project_due_copy = !empty($project['end_date']) ? date('M d, Y', strtotime($project['end_date'])) : 'No deadline';
                $isOverdue = !empty($project['end_date'])
                    && !in_array($project['status'], ['Completed', 'Cancelled'], true)
                    && strtotime($project['end_date']) < strtotime(date('Y-m-d'));
                $modalId = 'proj-modal-' . (int) $project['id'];
                $cardAccent = portfolio_normalize_card_hex($project['card_color'] ?? null);
                $heroGradient = htmlspecialchars(portfolio_card_hero_gradient($cardAccent));
                $previewTasks = $portfolioTaskPreviewByProject[(int) $project['id']] ?? [];
                $fileCount = (int) ($project['file_count'] ?? 0);
                $commentCount = (int) ($project['comment_count'] ?? 0);
                ?>
                <article class="project-premium-card"
                         id="proj-card-<?php echo (int) $project['id']; ?>"
                         data-ws-open="<?php echo $modalId; ?>"
                         role="listitem"
                         tabindex="0"
                         onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();openWorkspaceModal('<?php echo $modalId; ?>');}">
                    <div class="project-premium-card__hero" style="background: <?php echo $heroGradient; ?>">
                        <div class="project-premium-card__hero-row">
                            <span class="project-premium-card__avatar" aria-hidden="true"><?php echo htmlspecialchars(portfolio_project_initials($project['name'] ?? '')); ?></span>
                            <div class="project-premium-card__hero-text">
                                <h3><?php echo htmlspecialchars($project['name']); ?></h3>
                            </div>
                            <span class="project-premium-card__pill"><?php echo htmlspecialchars($project['status']); ?></span>
                        </div>
                    </div>
                    <div class="project-premium-card__body">
                        <div class="project-premium-card__meta">
                            <span title="Priority"><i data-lucide="flag" aria-hidden="true"></i><?php echo htmlspecialchars($project['priority'] ?? 'Normal'); ?></span>
                            <span title="Due date" class="<?php echo $isOverdue ? 'text-red-600 font-semibold' : ''; ?>">
                                <i data-lucide="calendar" aria-hidden="true"></i><?php echo htmlspecialchars($project_due_copy); ?>
                            </span>
                            <span title="Owner"><i data-lucide="user" aria-hidden="true"></i><?php echo htmlspecialchars($project['created_by_name'] ?? 'Unassigned'); ?></span>
                        </div>

                        <div class="project-premium-card__progress">
                            <div class="project-premium-card__progress-top">
                                <span>Task completion</span>
                                <em><?php echo (int) $progress; ?>%</em>
                            </div>
                            <div class="project-premium-card__track">
                                <span style="width: <?php echo (int) $progress; ?>%; background: linear-gradient(90deg, <?php echo htmlspecialchars($cardAccent); ?>, rgba(52, 163, 143, 0.95));"></span>
                            </div>
                            <div style="margin-top:0.4rem;font-size:0.72rem;color:#64748b;">
                                <?php echo (int) $project['completed_tasks']; ?> of <?php echo (int) $project['task_count']; ?> tasks closed
                            </div>
                        </div>

                        <div class="project-premium-card__tasks">
                            <header>Upcoming focus</header>
                            <?php if (empty($previewTasks)): ?>
                                <p style="margin:0;font-size:0.8rem;color:#94a3b8;">No tasks yet—open the project to add work items.</p>
                            <?php else: ?>
                                <?php foreach ($previewTasks as $trow): ?>
                                    <div class="project-premium-card__task-row">
                                        <span class="project-premium-card__task-name" title="<?php echo htmlspecialchars($trow['name']); ?>"><?php echo htmlspecialchars($trow['name']); ?></span>
                                        <span class="project-premium-card__task-status"><?php echo htmlspecialchars($trow['status']); ?></span>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>

                        <div class="project-premium-card__docs">
                            <span class="project-premium-chip" title="Files across tasks and comments"><i data-lucide="paperclip" aria-hidden="true"></i><?php echo number_format($fileCount); ?> files</span>
                            <span class="project-premium-chip is-muted"><i data-lucide="message-circle" aria-hidden="true"></i><?php echo number_format($commentCount); ?> comments</span>
                            <?php $requirement_badges = getProjectRequirementBadges($project); ?>
                            <?php foreach ($requirement_badges as $badge): ?>
                                <span class="project-premium-chip is-muted"><?php echo htmlspecialchars($badge['label']); ?></span>
                            <?php endforeach; ?>
                        </div>

                        <div class="project-premium-card__footer" style="justify-content:flex-start;border-top:none;padding-top:0.75rem;margin-top:0.85rem;">
                            <a href="view?id=<?php echo (int) $project['id']; ?>"
                               class="project-premium-card__open"
                               style="color:<?php echo htmlspecialchars($cardAccent); ?>;"
                               onclick="event.stopPropagation();">
                                View program <span aria-hidden="true">→</span>
                            </a>
                        </div>
                    </div>
                </article>
            <?php endforeach; ?>

            <?php if ((hasPermission('manage_projects') || !empty($_SESSION['is_section_head'])) && !empty($projects)): ?>
            <a href="create" class="project-add-card" role="listitem">
                <i data-lucide="plus" aria-hidden="true"></i>
                <span>Add new program</span>
            </a>
            <?php endif; ?>

            <?php if (empty($projects)): ?>
                <div class="todo-empty" style="grid-column: 1 / -1;">
                    <i data-lucide="folder-open" aria-hidden="true"></i>
                    <p>No projects found.</p>
                    <?php if ($search_query || $status_filter || $priority_filter): ?>
                        <p style="font-size:12px;margin-top:4px;">Try clearing your filters to see all projects.</p>
                        <a href="list" class="todo-btn-ghost" style="margin-top:12px;">Clear filters</a>
                    <?php else: ?>
                        <?php if (hasPermission('manage_projects') || !empty($_SESSION['is_section_head'])): ?>
                        <a href="create" class="todo-btn-primary" style="margin-top:12px;">
                            <i data-lucide="plus" aria-hidden="true"></i> Create your first project
                        </a>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </section>

        <div class="project-landing-bottom" aria-label="Executive summary">
            <div class="project-landing-panel">
                <div class="project-landing-panel__head">
                    <div>
                        <h2 style="margin:0;font-size:1.08rem;">Where executive attention should land next</h2>
                    </div>
                    <a href="list" class="project-landing-view-all">View all</a>
                </div>
                <div class="project-hub-focus-list">
                    <?php foreach ($projectFocusItems as $item): ?>
                    <a class="project-hub-focus-item" href="<?php echo htmlspecialchars($item['view_all_href'] ?? 'list'); ?>">
                        <span class="project-hub-focus-icon is-<?php echo htmlspecialchars($item['tone']); ?>">
                            <i data-lucide="<?php echo htmlspecialchars($item['icon']); ?>" aria-hidden="true"></i>
                        </span>
                        <div class="project-hub-focus-copy">
                            <strong><?php echo htmlspecialchars($item['label']); ?></strong>
                            <span><?php echo htmlspecialchars($item['note']); ?></span>
                        </div>
                        <span class="project-hub-focus-value"><?php echo htmlspecialchars($item['value']); ?></span>
                        <span class="project-hub-focus-chevron" aria-hidden="true"><i data-lucide="chevron-right"></i></span>
                    </a>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="project-landing-panel">
                <div>
                    <span class="project-hub-section-kicker">Communication integrations</span>
                    <p class="project-hub-subtitle" style="margin-top:0.45rem;margin-bottom:0;">
                         Pair with
                        <a href="<?php echo htmlspecialchars(BASE_URL . 'modules/messaging/inbox'); ?>" class="font-semibold text-teal-700">internal messaging</a>.
                        Reach delivery teammates (project manager, assignees, collaborators) when they appear on the spotlight program below.
                        Outbound messages use <strong><?php echo htmlspecialchars($commMessageSubject); ?></strong> as the subject line or opening header when supported.
                    </p>
                    <?php if ($commTeamHasOthers): ?>
                        <div class="project-comm-team" aria-label="Contact project team">
                            <?php foreach ($commTeamContactRows as $row): ?>
                                <?php
                                $hasAnyChannel = ($row['wa_href'] !== '' || $row['tel_href'] !== '' || $row['mailto_href'] !== '');
                                ?>
                                <div class="project-comm-team-row">
                                    <span class="project-comm-team-name"><?php echo htmlspecialchars($row['name']); ?></span>
                                    <?php if ($hasAnyChannel): ?>
                                        <div class="project-comm-team-actions project-comm-icons" aria-label="Contact <?php echo htmlspecialchars($row['name']); ?>">
                                            <?php if ($row['wa_href'] !== ''): ?>
                                                <a href="<?php echo htmlspecialchars($row['wa_href']); ?>"
                                                   class="project-comm-icons__btn"
                                                   title="WhatsApp <?php echo htmlspecialchars($row['name']); ?>"
                                                   target="_blank"
                                                   rel="noopener noreferrer">
                                                    <i data-lucide="messages-square" aria-hidden="true"></i>
                                                </a>
                                            <?php endif; ?>
                                            <?php if ($row['tel_href'] !== ''): ?>
                                                <a href="<?php echo htmlspecialchars($row['tel_href']); ?>"
                                                   class="project-comm-icons__btn"
                                                   title="Call <?php echo htmlspecialchars($row['name']); ?>">
                                                    <i data-lucide="phone" aria-hidden="true"></i>
                                                </a>
                                            <?php endif; ?>
                                            <?php if ($row['mailto_href'] !== ''): ?>
                                                <a href="<?php echo htmlspecialchars($row['mailto_href']); ?>"
                                                   class="project-comm-icons__btn"
                                                   title="Email <?php echo htmlspecialchars($row['name']); ?>">
                                                    <i data-lucide="mail" aria-hidden="true"></i>
                                                </a>
                                            <?php endif; ?>
                                        </div>
                                    <?php else: ?>
                                        <p class="project-comm-team-note">No phone or email on file for this teammate.</p>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php elseif ($commFocusProjectId > 0 && $canInviteFocusProjectTeam): ?>
                        <p class="project-hub-subtitle" style="margin-top:0.65rem;margin-bottom:0;font-size:0.82rem;">
                            No teammates are linked on this program yet (beyond assignments appearing here once tasks have owners).
                        </p>
                        <a href="<?php echo htmlspecialchars($commAddCollaboratorHref); ?>" class="project-comm-invite-btn">
                            <i data-lucide="user-plus" aria-hidden="true"></i>
                            Add collaborator
                        </a>
                    <?php elseif ($commFocusProjectId > 0): ?>
                        <p class="project-hub-subtitle" style="margin-top:0.65rem;margin-bottom:0;font-size:0.82rem;">
                            No teammates to reach yet on this program. Ask the project owner to assign tasks or invite collaborators.
                        </p>
                        <a href="<?php echo htmlspecialchars(BASE_URL . 'modules/projects/view?id=' . $commFocusProjectId); ?>" class="project-comm-invite-btn" style="background:rgba(248,250,252,0.95);border-color:rgba(226,232,240,0.98);color:#475569;">
                            Open project
                        </a>
                    <?php else: ?>
                        <p class="project-hub-subtitle" style="margin-top:0.65rem;margin-bottom:0;font-size:0.82rem;">
                            Create or open a project to build a team and unlock outbound shortcuts here.
                        </p>
                    <?php endif; ?>
                </div>
                <?php if (!empty($projectActivityItems)): ?>
                <div class="project-landing-panel__head" style="margin-top:1.35rem;">
                    <span class="project-hub-section-kicker">Recent movement</span>
                    <a href="list" class="project-landing-view-all">View all</a>
                </div>
                <div class="project-hub-activity-list">
                    <?php foreach ($projectActivityItems as $item): ?>
                        <a href="view?id=<?php echo $item['id']; ?>" class="project-hub-activity-item">
                            <div class="project-hub-activity-main">
                                <span class="project-hub-activity-mark is-<?php echo htmlspecialchars($item['tone']); ?>">
                                    <i data-lucide="folder-open" aria-hidden="true"></i>
                                </span>
                                <div class="min-w-0">
                                    <p class="project-hub-activity-title"><?php echo htmlspecialchars($item['name']); ?></p>
                                    <p class="project-hub-activity-subtitle"><?php echo htmlspecialchars($item['subtitle'] ?? $item['status']); ?></p>
                                </div>
                            </div>
                            <div class="project-hub-activity-value">
                                <?php echo $item['progress']; ?>%
                                <span><?php echo htmlspecialchars($item['deadline']); ?></span>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>

    </main>
</div>

<!-- Portfolio modal -->
<div class="todo-modal-overlay" id="wsProjectsPortfolio" role="dialog" aria-labelledby="wsProjectsPortfolioTitle" aria-hidden="true">
    <div class="todo-modal todo-modal--lg">
        <div class="todo-modal-header">
            <h3 class="todo-modal-title" id="wsProjectsPortfolioTitle">Portfolio</h3>
            <button class="todo-modal-close" data-ws-close aria-label="Close">&times;</button>
        </div>
        <div class="todo-modal-body">
            <div class="project-hub-summary-card">
                <span class="project-hub-summary-label">Portfolio</span>
                <h2 class="project-hub-summary-value"><?php echo number_format($projectDashboardStats['total']); ?></h2>
                <div class="project-hub-summary-meta">
                    <div>
                        <span>Completion</span>
                        <strong><?php echo $projectCompletionRate; ?>%</strong>
                    </div>
                    <div>
                        <span>Tasks</span>
                        <strong><?php echo number_format($projectDashboardStats['task_completed']); ?>/<?php echo number_format($projectDashboardStats['task_total']); ?></strong>
                    </div>
                </div>
                <div class="project-hub-progress-track">
                    <span style="width: <?php echo $projectCompletionRate; ?>%"></span>
                </div>
            </div>
            <div class="project-hub-metric-grid">
                <?php foreach ($projectMetricTiles as $tile): ?>
                <div class="project-hub-metric <?php echo ($tile['tone'] ?? '') === 'danger' ? 'is-danger' : ''; ?>">
                    <span><?php echo htmlspecialchars($tile['label']); ?></span>
                    <strong><?php echo htmlspecialchars($tile['value']); ?></strong>
                </div>
                <?php endforeach; ?>
            </div>
            <?php if (!empty($projectActivityItems)): ?>
                <h4 style="font-weight:700;color:#1f2937;margin:8px 0 0;">Recent project activity</h4>
                <div class="project-hub-activity-list">
                    <?php foreach ($projectActivityItems as $item): ?>
                        <a href="view?id=<?php echo $item['id']; ?>" class="project-hub-activity-item">
                            <div class="project-hub-activity-main">
                                <span class="project-hub-activity-mark is-<?php echo htmlspecialchars($item['tone']); ?>">
                                    <i data-lucide="folder-open" aria-hidden="true"></i>
                                </span>
                                <div class="min-w-0">
                                    <p class="project-hub-activity-title"><?php echo htmlspecialchars($item['name']); ?></p>
                                    <p class="project-hub-activity-subtitle"><?php echo htmlspecialchars($item['subtitle'] ?? $item['status']); ?></p>
                                </div>
                            </div>
                            <div class="project-hub-activity-value">
                                <?php echo $item['progress']; ?>%
                                <span><?php echo htmlspecialchars($item['deadline']); ?></span>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        <div class="todo-modal-footer">
            <a href="analytics" class="todo-btn-ghost">Open Analytics</a>
            <button type="button" class="todo-btn-primary" data-ws-close>Close</button>
        </div>
    </div>
</div>

<!-- Filters modal -->
<div class="todo-modal-overlay" id="wsProjectsFilters" role="dialog" aria-labelledby="wsProjectsFiltersTitle" aria-hidden="true">
    <div class="todo-modal">
        <div class="todo-modal-header">
            <h3 class="todo-modal-title" id="wsProjectsFiltersTitle">Filter projects</h3>
            <button class="todo-modal-close" data-ws-close aria-label="Close">&times;</button>
        </div>
        <form method="GET" action="list" class="project-filter-form">
            <div class="todo-modal-body">
                <div class="project-filter-grid">
                    <div class="todo-field project-filter-field project-filter-field--search">
                        <label for="filterSearch">Search</label>
                        <input type="text" id="filterSearch" name="search" class="todo-input" placeholder="Search by project name or description..."
                               value="<?php echo htmlspecialchars($search_query); ?>">
                    </div>
                    <div class="todo-field project-filter-field">
                        <label for="filterStatus">Status</label>
                        <select id="filterStatus" name="status" class="todo-select">
                            <option value="">All Statuses</option>
                            <option value="Planning" <?php echo $status_filter == 'Planning' ? 'selected' : ''; ?>>Planning</option>
                            <option value="In Progress" <?php echo $status_filter == 'In Progress' ? 'selected' : ''; ?>>In Progress</option>
                            <option value="On Hold" <?php echo $status_filter == 'On Hold' ? 'selected' : ''; ?>>On Hold</option>
                            <option value="Completed" <?php echo $status_filter == 'Completed' ? 'selected' : ''; ?>>Completed</option>
                            <option value="Cancelled" <?php echo $status_filter == 'Cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                        </select>
                    </div>
                    <div class="todo-field project-filter-field">
                        <label for="filterPriority">Priority</label>
                        <select id="filterPriority" name="priority" class="todo-select">
                            <option value="">All Priorities</option>
                            <option value="Low" <?php echo $priority_filter == 'Low' ? 'selected' : ''; ?>>Low</option>
                            <option value="Medium" <?php echo $priority_filter == 'Medium' ? 'selected' : ''; ?>>Medium</option>
                            <option value="High" <?php echo $priority_filter == 'High' ? 'selected' : ''; ?>>High</option>
                            <option value="Urgent" <?php echo $priority_filter == 'Urgent' ? 'selected' : ''; ?>>Urgent</option>
                        </select>
                    </div>
                </div>
            </div>
            <div class="todo-modal-footer">
                <a href="list" class="todo-btn-ghost">Clear all</a>
                <button type="submit" class="todo-btn-primary">
                    <i data-lucide="search" aria-hidden="true"></i> Apply
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Per-project detail modals -->
<?php foreach ($projects as $project): ?>
    <?php
    $status_colors = [
        'Planning' => 'bg-emerald-50 text-emerald-700',
        'In Progress' => 'bg-green-100 text-green-800',
        'On Hold' => 'bg-yellow-100 text-yellow-800',
        'Completed' => 'bg-gray-100 text-gray-800',
        'Cancelled' => 'bg-red-100 text-red-800'
    ];
    $priority_colors = [
        'Low' => 'text-gray-600',
        'Medium' => 'text-emerald-600',
        'High' => 'text-orange-600',
        'Urgent' => 'text-red-600'
    ];
    $status_color = $status_colors[$project['status']] ?? 'bg-gray-100 text-gray-800';
    $priority_color = $priority_colors[$project['priority']] ?? 'text-gray-600';
    $progress = $project['task_count'] > 0 ? round(($project['completed_tasks'] / $project['task_count']) * 100) : 0;
    $project_due_copy = !empty($project['end_date']) ? date('M d, Y', strtotime($project['end_date'])) : 'No deadline';
    $modalId = 'proj-modal-' . (int) $project['id'];
    $modalAccent = portfolio_normalize_card_hex($project['card_color'] ?? null);
    $modalFileCount = (int) ($project['file_count'] ?? 0);
    $modalCommentCount = (int) ($project['comment_count'] ?? 0);
    ?>
    <div class="todo-modal-overlay" id="<?php echo $modalId; ?>" role="dialog" aria-hidden="true">
        <div class="todo-modal todo-modal--lg">
            <div class="todo-modal-header">
                <h3 class="todo-modal-title"><?php echo htmlspecialchars($project['name']); ?></h3>
                <button class="todo-modal-close" data-ws-close aria-label="Close">&times;</button>
            </div>
            <div class="todo-modal-body">
                <div class="project-modal-accent" style="height:7px;border-radius:999px;margin:-0.35rem 0 1rem;background: <?php echo htmlspecialchars(portfolio_card_hero_gradient($modalAccent)); ?>"></div>
                <div class="project-card-pills">
                    <span class="px-2.5 py-1 text-xs font-semibold rounded-full <?php echo $status_color; ?>">
                        <?php echo htmlspecialchars($project['status']); ?>
                    </span>
                    <?php if ($project['approved_status'] != 'Pending'): ?>
                        <span class="px-2.5 py-1 text-[10px] font-bold rounded-full uppercase <?php
                            echo $project['approved_status'] == 'Approved' ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700'; ?>">
                            <?php echo $project['approved_status']; ?>
                        </span>
                    <?php endif; ?>
                    <span class="project-card-soft-pill">
                        <i data-lucide="flag" class="text-sm <?php echo $priority_color; ?>" aria-hidden="true"></i>
                        <?php echo htmlspecialchars($project['priority']); ?>
                    </span>
                </div>

                <p class="project-card-desc break-words">
                    <?php echo nl2br(htmlspecialchars($project['description'] ?? 'No description')); ?>
                </p>

                <div class="project-card-progress">
                    <div class="flex justify-between text-sm">
                        <span class="text-slate-500 font-semibold">Progress</span>
                        <span class="font-semibold text-slate-700"><?php echo $progress; ?>%</span>
                    </div>
                    <div class="project-card-progress-track">
                        <span style="width: <?php echo $progress; ?>%; background: linear-gradient(90deg, <?php echo htmlspecialchars($modalAccent); ?>, rgba(52, 163, 143, 0.95));"></span>
                    </div>
                    <div class="text-xs text-slate-500 mt-2">
                        <?php echo $project['completed_tasks']; ?> of <?php echo $project['task_count']; ?> tasks completed
                    </div>
                </div>

                <div class="project-card-pills">
                    <?php $requirement_badges = getProjectRequirementBadges($project); ?>
                    <?php if (!empty($requirement_badges)): ?>
                        <?php foreach ($requirement_badges as $badge): ?>
                            <span class="px-2 py-1 text-xs font-semibold rounded-full <?php echo $badge['class']; ?>">
                                <?php echo htmlspecialchars($badge['label']); ?>
                            </span>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <span class="project-card-soft-pill">Standard Workflow</span>
                    <?php endif; ?>
                </div>

                <div class="project-card-meta">
                    <div class="project-card-meta-box">
                        <span>Start</span>
                        <strong><?php echo !empty($project['start_date']) ? date('M d, Y', strtotime($project['start_date'])) : 'Not set'; ?></strong>
                    </div>
                    <div class="project-card-meta-box">
                        <span>End</span>
                        <strong><?php echo htmlspecialchars($project_due_copy); ?></strong>
                    </div>
                    <div class="project-card-meta-box">
                        <span>Owner</span>
                        <strong class="break-words"><?php echo htmlspecialchars($project['created_by_name']); ?></strong>
                    </div>
                    <div class="project-card-meta-box">
                        <span>Created</span>
                        <strong><?php echo date('M d, Y', strtotime($project['created_at'])); ?></strong>
                    </div>
                </div>

                <div style="margin-top:1rem;padding:0.9rem 1rem;border-radius:1.15rem;border:1px solid rgba(226,232,240,0.95);background:rgba(248,250,255,0.92);">
                    <div style="font-size:0.7rem;font-weight:800;letter-spacing:0.1em;text-transform:uppercase;color:#64748b;">Documents &amp; collaboration</div>
                    <p style="margin:0.45rem 0 0;font-size:0.82rem;color:#475569;line-height:1.55;">
                        <?php echo number_format($modalFileCount); ?> files across tasks and comments,
                        <?php echo number_format($modalCommentCount); ?> project comments.
                        <a href="view?id=<?php echo (int) $project['id']; ?>#project-documentation-hub" class="font-semibold text-teal-700">Open the document hub</a>
                        or <a href="view?id=<?php echo (int) $project['id']; ?>#project-comments" class="font-semibold text-teal-700">join the discussion</a>.
                    </p>
                    <p style="margin:0.55rem 0 0;font-size:0.78rem;color:#64748b;line-height:1.5;">
                        <strong>Integrations:</strong> Route updates through
                        <a href="<?php echo htmlspecialchars(BASE_URL . 'modules/messaging/inbox'); ?>" class="font-semibold text-teal-700">Press ERP messaging</a>.
                      <small>
                       Project Communication Modules will be available soon.
                      </small>
                    </p>
                </div>
            </div>
            <div class="todo-modal-footer">
                <a href="#" onclick="document.getElementById('deleteProjectId').value = '<?php echo (int) $project['id']; ?>'; openConfirmModal('Delete Project', 'Are you sure you want to delete this project? All associated tasks will also be deleted.', 'form:deleteProjectForm'); return false;"
                   class="todo-btn-ghost" style="color:#b91c1c;border-color:#fecaca;">
                    <i data-lucide="trash-2" aria-hidden="true"></i> Delete
                </a>
                <a href="edit?id=<?php echo $project['id']; ?>" class="todo-btn-ghost">
                    <i data-lucide="square-pen" aria-hidden="true"></i> Edit
                </a>
                <a href="view?id=<?php echo $project['id']; ?>" class="todo-btn-primary">
                    <i data-lucide="eye" aria-hidden="true"></i> Open Project
                </a>
            </div>
        </div>
    </div>
<?php endforeach; ?>


<?php include '../../includes/footer.php'; ?>

<script>
// Export dropdown toggle
document.addEventListener('DOMContentLoaded', function() {
    if (typeof window.refreshAppShellIcons === 'function') {
        window.refreshAppShellIcons();
    }
    const exportDropdown = document.getElementById('exportDropdown');
    const exportMenu = document.getElementById('exportMenu');

    if (exportDropdown && exportMenu) {
        exportDropdown.addEventListener('click', function(e) {
            e.stopPropagation();
            exportMenu.classList.toggle('hidden');
        });

        document.addEventListener('click', function(e) {
            if (!exportMenu.classList.contains('hidden') && !exportMenu.contains(e.target) && e.target !== exportDropdown) {
                exportMenu.classList.add('hidden');
            }
        });
    }

    const projectCardGrid = document.getElementById('projectCardGrid');
    const projectViewGrid = document.getElementById('projectViewGrid');
    const projectViewList = document.getElementById('projectViewList');

    function refreshIfPossible() {
        if (typeof window.refreshAppShellIcons === 'function') {
            window.refreshAppShellIcons();
        }
    }

    function setProjectsView(isList) {
        if (!projectCardGrid) return;
        projectCardGrid.classList.toggle('project-card-grid--list', isList);
        if (projectViewGrid) {
            projectViewGrid.classList.toggle('is-active', !isList);
            projectViewGrid.setAttribute('aria-pressed', (!isList).toString());
        }
        if (projectViewList) {
            projectViewList.classList.toggle('is-active', isList);
            projectViewList.setAttribute('aria-pressed', isList.toString());
        }
        try {
            localStorage.setItem('pressProjectsView', isList ? 'list' : 'grid');
        } catch (e) { /* ignore */ }
        refreshIfPossible();
    }

    if (projectViewGrid) {
        projectViewGrid.addEventListener('click', function() { setProjectsView(false); });
    }
    if (projectViewList) {
        projectViewList.addEventListener('click', function() { setProjectsView(true); });
    }
    try {
        if (localStorage.getItem('pressProjectsView') === 'list') {
            setProjectsView(true);
        }
    } catch (e) { /* ignore */ }

    const newDdToggle = document.getElementById('projectNewDdToggle');
    const newDdMenu = document.getElementById('projectNewDdMenu');
    const newDd = document.getElementById('projectNewDd');
    if (newDdToggle && newDdMenu && newDd) {
        newDdToggle.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            const open = newDdMenu.classList.toggle('is-open');
            newDdToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
            refreshIfPossible();
        });
        document.addEventListener('click', function(e) {
            if (newDd.contains(e.target)) return;
            newDdMenu.classList.remove('is-open');
            newDdToggle.setAttribute('aria-expanded', 'false');
        });
    }
});
</script>
