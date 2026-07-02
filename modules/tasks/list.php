<?php
require_once __DIR__ . '/../../config/app.php';
checkAuth();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/task_management_helper.php';
require_once __DIR__ . '/../../includes/project_visibility_helper.php';

$userId = (int) ($_SESSION['user_id'] ?? 0);
$tasksVis = project_visibility_sql_where_for_projects('p', $userId, $pdo);
$tasksVisClause = $tasksVis['clause'];
$tasksVisBinds = $tasksVis['binds'];

$mngDept = project_visibility_viewer_department_id($pdo, $userId);
$mngDeptPh = ($mngDept !== null && $mngDept > 0) ? $mngDept : -999999;
$mngSec = project_visibility_viewer_is_section_head($pdo, $userId);
$mngGlobal = hasPermission('manage_projects') ? 1 : 0;
$listHasVis = project_visibility_projects_table_ready($pdo);
$managedExtraSql = '';
if ($listHasVis) {
    $managedExtraSql = " AND (
        p.created_by = :m_uid
        OR (
            p.visibility_scope = 'department'
            AND p.department_id IS NOT NULL
            AND :m_d1 > 0
            AND p.department_id = :m_d2
            AND (:m_sh = 1 OR :m_mng = 1)
        )
    )";
} else {
    $managedExtraSql = ' AND p.created_by = :m_uid';
}
$viewTab = $_GET['tab'] ?? 'assigned';

// Allowed tabs
if (!in_array($viewTab, ['assigned', 'managed', 'personal'])) {
    $viewTab = 'assigned';
}

// 1. Fetch Stats
// Total Assigned
$assignedCountStmt = $pdo->prepare("
    SELECT COUNT(DISTINCT t.id)
    FROM tasks t
    JOIN task_assignees ta ON ta.task_id = t.id
    WHERE ta.user_id = ? AND t.status != 'Cancelled'
");
$assignedCountStmt->execute([$userId]);
$totalAssigned = (int)$assignedCountStmt->fetchColumn();

// Managed by PM / section lead / department coordinators
$managedCountSql = "
    SELECT COUNT(DISTINCT t.id)
    FROM tasks t
    JOIN projects p ON p.id = t.project_id
    WHERE t.status != 'Cancelled'
    $managedExtraSql
";
$managedCountStmt = $pdo->prepare($managedCountSql);
$managedCountStmt->bindValue(':m_uid', $userId, PDO::PARAM_INT);
if ($listHasVis) {
    $managedCountStmt->bindValue(':m_d1', $mngDeptPh, PDO::PARAM_INT);
    $managedCountStmt->bindValue(':m_d2', $mngDeptPh, PDO::PARAM_INT);
    $managedCountStmt->bindValue(':m_sh', $mngSec, PDO::PARAM_INT);
    $managedCountStmt->bindValue(':m_mng', $mngGlobal, PDO::PARAM_INT);
}
$managedCountStmt->execute();
$totalManaged = (int) $managedCountStmt->fetchColumn();

// Open tasks (Assigned to me)
$openCountStmt = $pdo->prepare("
    SELECT COUNT(DISTINCT t.id)
    FROM tasks t
    JOIN task_assignees ta ON ta.task_id = t.id
    WHERE ta.user_id = ? AND t.status NOT IN ('Completed', 'Cancelled')
");
$openCountStmt->execute([$userId]);
$openAssigned = (int)$openCountStmt->fetchColumn();

// Overdue tasks (Assigned to me)
$overdueCountStmt = $pdo->prepare("
    SELECT COUNT(DISTINCT t.id)
    FROM tasks t
    JOIN task_assignees ta ON ta.task_id = t.id
    WHERE ta.user_id = ? AND t.status NOT IN ('Completed', 'Cancelled')
      AND t.due_date IS NOT NULL AND t.due_date < CURDATE()
");
$overdueCountStmt->execute([$userId]);
$overdueAssigned = (int)$overdueCountStmt->fetchColumn();

// Fetch Tasks for the selected view
$tasks = [];
if ($viewTab === 'assigned') {
    $stmt = $pdo->prepare("
        SELECT t.*, p.name AS project_name, p.created_by AS pm_id,
               COALESCE(u.name, 'Deleted User') AS creator_name
        FROM tasks t
        JOIN task_assignees ta ON ta.task_id = t.id
        JOIN projects p ON p.id = t.project_id
        LEFT JOIN users u ON u.id = t.created_by
        WHERE ta.user_id = :ta_uid
          $tasksVisClause
        ORDER BY 
            CASE WHEN t.status NOT IN ('Completed', 'Cancelled') THEN 0 ELSE 1 END,
            t.due_date ASC, t.created_at DESC
    ");
    $stmt->bindValue(':ta_uid', $userId, PDO::PARAM_INT);
    foreach ($tasksVisBinds as $bk => $bv) {
        $stmt->bindValue(':' . $bk, $bv, PDO::PARAM_INT);
    }
    $stmt->execute();
    $tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);
} elseif ($viewTab === 'managed') {
    $stmt = $pdo->prepare("
        SELECT t.*, p.name AS project_name, p.created_by AS pm_id,
               COALESCE(u.name, 'Deleted User') AS creator_name
        FROM tasks t
        JOIN projects p ON p.id = t.project_id
        LEFT JOIN users u ON u.id = t.created_by
        WHERE t.status != 'Cancelled'
          $managedExtraSql
        ORDER BY 
            CASE WHEN t.status NOT IN ('Completed', 'Cancelled') THEN 0 ELSE 1 END,
            t.due_date ASC, t.created_at DESC
    ");
    $stmt->bindValue(':m_uid', $userId, PDO::PARAM_INT);
    if ($listHasVis) {
        $stmt->bindValue(':m_d1', $mngDeptPh, PDO::PARAM_INT);
        $stmt->bindValue(':m_d2', $mngDeptPh, PDO::PARAM_INT);
        $stmt->bindValue(':m_sh', $mngSec, PDO::PARAM_INT);
        $stmt->bindValue(':m_mng', $mngGlobal, PDO::PARAM_INT);
    }
    $stmt->execute();
    $tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);
} elseif ($viewTab === 'personal') {
    // "personal" tasks = tasks assigned to user AND created by user
    $stmt = $pdo->prepare("
        SELECT t.*, p.name AS project_name, p.created_by AS pm_id,
               COALESCE(u.name, 'Deleted User') AS creator_name
        FROM tasks t
        JOIN task_assignees ta ON ta.task_id = t.id
        JOIN projects p ON p.id = t.project_id
        LEFT JOIN users u ON u.id = t.created_by
        WHERE ta.user_id = :tp_u1 AND t.created_by = :tp_u2
          $tasksVisClause
        ORDER BY 
            CASE WHEN t.status NOT IN ('Completed', 'Cancelled') THEN 0 ELSE 1 END,
            t.due_date ASC, t.created_at DESC
    ");
    $stmt->bindValue(':tp_u1', $userId, PDO::PARAM_INT);
    $stmt->bindValue(':tp_u2', $userId, PDO::PARAM_INT);
    foreach ($tasksVisBinds as $bk => $bv) {
        $stmt->bindValue(':' . $bk, $bv, PDO::PARAM_INT);
    }
    $stmt->execute();
    $tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Prefetch assignees for the rendered tasks
$taskIds = array_column($tasks, 'id');
$assigneesByTask = !empty($taskIds) ? fetch_task_assignees_for_tasks($pdo, $taskIds) : [];

function get_task_cta(array $task, bool $isPm): array {
    $status = $task['status'] ?? 'Not Started';
    if ($status === 'Not Started') {
        return ['label' => 'Start Task', 'icon' => 'circle-play', 'class' => 'bg-emerald-600 hover:bg-emerald-700 text-white border border-transparent shadow-sm'];
    } elseif ($status === 'In Progress') {
        return ['label' => 'Proceed Task', 'icon' => 'arrow-right', 'class' => 'bg-blue-600 hover:bg-blue-700 text-white border border-transparent shadow-sm'];
    } elseif ($status === 'In Review') {
        if ($isPm) {
            return ['label' => 'Review Task', 'icon' => 'gavel', 'class' => 'bg-amber-600 hover:bg-amber-700 text-white border border-transparent shadow-sm'];
        }
        return ['label' => 'View Review', 'icon' => 'clipboard-list', 'class' => 'bg-amber-100 hover:bg-amber-200 text-amber-800 border border-amber-300'];
    } elseif ($status === 'Completed') {
        return ['label' => 'View Details', 'icon' => 'eye', 'class' => 'bg-gray-100 hover:bg-gray-200 text-gray-700 border border-gray-300'];
    }
    return ['label' => 'View Task', 'icon' => 'external-link', 'class' => 'bg-gray-100 hover:bg-gray-200 text-gray-700 border border-gray-300'];
}

$statusColors = [
    'Not Started' => 'bg-slate-100 text-slate-700 border border-slate-200',
    'In Progress' => 'bg-blue-50 text-blue-700 border border-blue-200',
    'In Review' => 'bg-amber-50 text-amber-700 border border-amber-200',
    'Completed' => 'bg-emerald-50 text-emerald-700 border border-emerald-200',
    'Cancelled' => 'bg-rose-50 text-rose-700 border border-rose-200'
];

$priorityColors = [
    'Low' => 'bg-slate-100 text-slate-600',
    'Medium' => 'bg-emerald-100 text-emerald-700',
    'High' => 'bg-orange-100 text-orange-700',
    'Urgent' => 'bg-rose-100 text-rose-700'
];

include '../../includes/header.php';
?>

<style>
    .dashboard-hero {
        position: relative;
        overflow: hidden;
        border-radius: 1.25rem;
        border: 1px solid rgba(226, 232, 240, 0.92);
        background: linear-gradient(180deg, rgba(255, 255, 255, 0.99), rgba(246, 250, 248, 0.98));
        box-shadow: 0 20px 40px -20px rgba(15, 23, 42, 0.1);
        padding: 1.25rem;
        margin-bottom: 1.5rem;
    }

    @media (min-width: 768px) {
        .dashboard-hero {
            padding: 2rem;
            border-radius: 1.5rem;
            margin-bottom: 2rem;
        }
    }

    .dashboard-hero::before {
        content: "";
        position: absolute;
        top: 0; left: 0; right: 0; bottom: 0;
        background:
            radial-gradient(circle at top right, rgba(24, 123, 116, 0.08), transparent 40%),
            radial-gradient(circle at bottom left, rgba(51, 196, 129, 0.05), transparent 40%);
        pointer-events: none;
    }

    .stat-card {
        padding: 1rem;
        border-radius: 0.85rem;
        background: rgba(255, 255, 255, 0.8);
        border: 1px solid rgba(226, 232, 240, 0.8);
        transition: transform 0.2s ease, box-shadow 0.2s ease;
    }

    @media (min-width: 768px) {
        .stat-card {
            padding: 1.25rem;
            border-radius: 1rem;
        }
    }

    .stat-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 10px 25px -10px rgba(15, 23, 42, 0.1);
    }

    .nav-pill {
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        padding: 0.6rem 1.2rem;
        border-radius: 999px;
        font-weight: 600;
        font-size: 0.9rem;
        transition: all 0.2s ease;
        border: 1px solid transparent;
    }

    .nav-pill:hover:not(.active) {
        background: #f1f5f9;
        color: #0f766e;
    }

    .nav-pill.active {
        background: #0f766e;
        color: #ffffff;
        box-shadow: 0 4px 12px rgba(15, 118, 110, 0.25);
    }

    .task-table-container {
        border-radius: 1.2rem;
        overflow: hidden;
        border: 1px solid rgba(226, 232, 240, 0.8);
        background: white;
        box-shadow: 0 10px 25px -10px rgba(15, 23, 42, 0.05);
    }

    .task-table th {
        background: #f8fafc;
        text-transform: uppercase;
        font-size: 0.75rem;
        letter-spacing: 0.05em;
        font-weight: 700;
        color: #64748b;
        padding: 1rem 1.25rem;
        border-bottom: 1px solid #e2e8f0;
    }

    .task-table td {
        padding: 1rem 1.25rem;
        border-bottom: 1px solid #f1f5f9;
        vertical-align: middle;
    }

    .task-table tr:last-child td {
        border-bottom: none;
    }

    .task-table tr:hover {
        background: #fcfdfd;
    }

    .avatar-stack {
        display: flex;
        align-items: center;
    }

    .avatar-stack > div {
        width: 32px;
        height: 32px;
        border-radius: 999px;
        background: #e2e8f0;
        border: 2px solid #ffffff;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 0.75rem;
        font-weight: 700;
        color: #475569;
        margin-left: -8px;
        position: relative;
    }

    .avatar-stack > div:first-child {
        margin-left: 0;
    }

    .avatar-stack > div:hover {
        z-index: 10;
    }

    @media (max-width: 767px) {
        .task-dashboard-table-wrap.task-table-container {
            background: transparent;
            border: none;
            box-shadow: none;
            padding: 0;
        }

        .task-dashboard-table-wrap .task-table thead {
            display: none;
        }

        .task-dashboard-table-wrap .overflow-x-auto {
            overflow-x: visible;
        }

        .task-dashboard-table-wrap .task-table tbody tr {
            display: block;
            padding: 1rem 0.85rem;
            margin-bottom: 0.65rem;
            border: 1px solid #e2e8f0;
            border-radius: 1rem;
            background: #fff;
            box-shadow: 0 8px 24px -16px rgba(15, 23, 42, 0.12);
        }

        .task-dashboard-table-wrap .task-table tbody tr:last-child {
            margin-bottom: 0;
        }

        .task-dashboard-table-wrap .task-table td {
            display: grid;
            grid-template-columns: 6.75rem minmax(0, 1fr);
            gap: 0.25rem 0.65rem;
            align-items: start;
            padding: 0.4rem 0;
            border: none;
            white-space: normal;
            vertical-align: top;
        }

        .task-dashboard-table-wrap .task-table td::before {
            content: attr(data-label);
            font-size: 0.62rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: #94a3b8;
            padding-top: 0.15rem;
        }

        .task-dashboard-table-wrap .task-table td.text-right {
            text-align: left;
        }

        .task-dashboard-table-wrap .task-dashboard-actions {
            grid-template-columns: 1fr;
            padding-top: 0.65rem;
        }

        .task-dashboard-table-wrap .task-dashboard-actions::before {
            display: none;
        }

        .task-dashboard-table-wrap .task-dashboard-actions a {
            width: 100%;
            justify-content: center;
        }
    }

    .stat-card svg.lucide {
        width: 1.5rem;
        height: 1.5rem;
    }

    .nav-pill svg.lucide {
        width: 1rem;
        height: 1rem;
        flex-shrink: 0;
    }

    .task-table svg.lucide {
        width: 1rem;
        height: 1rem;
        flex-shrink: 0;
    }

    .task-table td svg.lucide.text-xs {
        width: 0.875rem;
        height: 0.875rem;
    }
</style>

<div class="max-w-7xl mx-auto px-3 sm:px-6 lg:px-8 py-4 md:py-8">
    
    <!-- Hero / Progress Overview -->
    <div class="dashboard-hero">
        <div class="relative z-10">
            <h1 class="text-2xl sm:text-3xl font-extrabold text-slate-900 tracking-tight mb-2">Task Dashboard</h1>
            <p class="text-slate-600 mb-6 md:mb-8 max-w-2xl text-base sm:text-lg">Manage your assignments, track project tasks, and stay on top of overdue alerts.</p>

            <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 sm:gap-4">
                <div class="stat-card">
                    <div class="flex items-center gap-3 mb-2">
                        <div class="p-2 bg-blue-50 text-blue-600 rounded-lg">
                            <i data-lucide="clipboard-list" aria-hidden="true"></i>
                        </div>
                        <span class="text-sm font-semibold text-slate-500 uppercase tracking-wider">Total Assigned</span>
                    </div>
                    <div class="text-3xl font-bold text-slate-800"><?php echo number_format($totalAssigned); ?></div>
                </div>

                <div class="stat-card">
                    <div class="flex items-center gap-3 mb-2">
                        <div class="p-2 bg-emerald-50 text-emerald-600 rounded-lg">
                            <i data-lucide="circle-play" aria-hidden="true"></i>
                        </div>
                        <span class="text-sm font-semibold text-slate-500 uppercase tracking-wider">Open Tasks</span>
                    </div>
                    <div class="text-3xl font-bold text-slate-800"><?php echo number_format($openAssigned); ?></div>
                </div>

                <div class="stat-card <?php echo $overdueAssigned > 0 ? 'border-rose-200 bg-rose-50/30' : ''; ?>">
                    <div class="flex items-center gap-3 mb-2">
                        <div class="p-2 <?php echo $overdueAssigned > 0 ? 'bg-rose-100 text-rose-600' : 'bg-slate-100 text-slate-500'; ?> rounded-lg">
                            <i data-lucide="triangle-alert" aria-hidden="true"></i>
                        </div>
                        <span class="text-sm font-semibold <?php echo $overdueAssigned > 0 ? 'text-rose-600' : 'text-slate-500'; ?> uppercase tracking-wider">Overdue Alerts</span>
                    </div>
                    <div class="text-3xl font-bold <?php echo $overdueAssigned > 0 ? 'text-rose-600' : 'text-slate-800'; ?>"><?php echo number_format($overdueAssigned); ?></div>
                </div>

                <div class="stat-card">
                    <div class="flex items-center gap-3 mb-2">
                        <div class="p-2 bg-indigo-50 text-indigo-600 rounded-lg">
                            <i data-lucide="gavel" aria-hidden="true"></i>
                        </div>
                        <span class="text-sm font-semibold text-slate-500 uppercase tracking-wider">Projects Managed</span>
                    </div>
                    <div class="text-3xl font-bold text-slate-800"><?php echo number_format($totalManaged); ?></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Navigation Pills -->
    <div class="flex flex-col sm:flex-row sm:flex-wrap gap-2 mb-6">
        <a href="?tab=assigned" class="nav-pill <?php echo $viewTab === 'assigned' ? 'active' : 'text-slate-600'; ?>">
            <i data-lucide="user-check" class="text-sm" aria-hidden="true"></i>
            My Assigned Tasks
        </a>
        <a href="?tab=managed" class="nav-pill <?php echo $viewTab === 'managed' ? 'active' : 'text-slate-600'; ?>">
            <i data-lucide="folder-heart" class="text-sm" aria-hidden="true"></i>
            Projects I Manage
        </a>
        <a href="?tab=personal" class="nav-pill <?php echo $viewTab === 'personal' ? 'active' : 'text-slate-600'; ?>">
            <i data-lucide="user" class="text-sm" aria-hidden="true"></i>
            Personal Tasks
        </a>
    </div>

    <!-- Tabular Data View -->
    <div class="task-table-container task-dashboard-table-wrap">
        <?php if (empty($tasks)): ?>
            <div class="flex flex-col items-center justify-center p-12 text-center">
                <div class="w-16 h-16 bg-slate-50 rounded-full flex items-center justify-center mb-4">
                    <i data-lucide="list-todo" class="text-3xl text-slate-300" aria-hidden="true"></i>
                </div>
                <h3 class="text-lg font-bold text-slate-800 mb-1">No tasks found</h3>
                <p class="text-slate-500">You don't have any tasks in this view yet.</p>
            </div>
        <?php else: ?>
            <div class="overflow-x-auto">
                <table class="w-full text-left task-table whitespace-nowrap">
                    <thead>
                        <tr>
                            <th>Task & Project</th>
                            <th>Status</th>
                            <th>Priority</th>
                            <th>Due Date</th>
                            <th>Assignees</th>
                            <th class="text-right">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($tasks as $task): ?>
                            <?php 
                                $taskId = (int)$task['id'];
                                $isOverdue = !empty($task['due_date']) && strtotime($task['due_date']) < strtotime('today') && !in_array($task['status'], ['Completed', 'Cancelled']);
                                $assignees = $assigneesByTask[$taskId] ?? [];
                                $isPm = ((int)$task['pm_id'] === $userId);
                                $cta = get_task_cta($task, $isPm);
                            ?>
                            <tr>
                                <td data-label="Task">
                                    <a href="view?id=<?php echo $taskId; ?>" class="block font-bold text-slate-900 hover:text-emerald-700 transition">
                                        <?php echo htmlspecialchars($task['name']); ?>
                                    </a>
                                    <span class="text-sm text-slate-500 mt-0.5 flex items-center gap-1">
                                        <i data-lucide="folder" class="text-xs" aria-hidden="true"></i>
                                        <?php echo htmlspecialchars($task['project_name']); ?>
                                    </span>
                                </td>
                                <td data-label="Status">
                                    <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-bold <?php echo $statusColors[$task['status']] ?? $statusColors['Not Started']; ?>">
                                        <?php echo htmlspecialchars($task['status']); ?>
                                    </span>
                                </td>
                                <td data-label="Priority">
                                    <span class="inline-flex items-center px-2.5 py-1 rounded text-xs font-bold <?php echo $priorityColors[$task['priority']] ?? $priorityColors['Medium']; ?>">
                                        <?php echo htmlspecialchars($task['priority']); ?>
                                    </span>
                                </td>
                                <td data-label="Due">
                                    <?php if (!empty($task['due_date'])): ?>
                                        <span class="inline-flex flex-wrap items-center gap-1.5 text-sm font-medium <?php echo $isOverdue ? 'text-rose-600 bg-rose-50 px-2 py-0.5 rounded border border-rose-200' : 'text-slate-600'; ?>">
                                            <i data-lucide="<?php echo $isOverdue ? 'triangle-alert' : 'calendar'; ?>" class="text-sm" aria-hidden="true"></i>
                                            <?php echo date('M d, Y', strtotime($task['due_date'])); ?>
                                            <?php if ($isOverdue): ?><span class="text-xs font-bold uppercase ml-1">(Overdue)</span><?php endif; ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="text-sm text-slate-400">No deadline</span>
                                    <?php endif; ?>
                                </td>
                                <td data-label="Assignees">
                                    <?php if (!empty($assignees)): ?>
                                        <div class="avatar-stack" title="<?php echo htmlspecialchars(implode(', ', array_column($assignees, 'name'))); ?>">
                                            <?php foreach (array_slice($assignees, 0, 3) as $assignee): ?>
                                                <?php 
                                                    $initials = strtoupper(substr($assignee['name'], 0, 1));
                                                    $parts = explode(' ', $assignee['name']);
                                                    if (count($parts) > 1) {
                                                        $initials = strtoupper(substr($parts[0], 0, 1) . substr($parts[1], 0, 1));
                                                    }
                                                ?>
                                                <div><?php echo htmlspecialchars($initials); ?></div>
                                            <?php endforeach; ?>
                                            <?php if (count($assignees) > 3): ?>
                                                <div class="bg-slate-100 text-slate-500 border-slate-200">+<?php echo count($assignees) - 3; ?></div>
                                            <?php endif; ?>
                                        </div>
                                    <?php else: ?>
                                        <span class="text-sm text-slate-400 italic">Unassigned</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-right task-dashboard-actions">
                                    <a href="view?id=<?php echo $taskId; ?>" class="inline-flex items-center justify-center gap-1.5 px-3 py-1.5 rounded-lg text-sm font-semibold transition-all <?php echo $cta['class']; ?>">
                                        <i data-lucide="<?php echo htmlspecialchars($cta['icon'], ENT_QUOTES, 'UTF-8'); ?>" class="text-sm" aria-hidden="true"></i>
                                        <?php echo $cta['label']; ?>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

</div>

<script>if (typeof window.refreshAppShellIcons === 'function') { window.refreshAppShellIcons(); }</script>
<?php include '../../includes/footer.php'; ?>
