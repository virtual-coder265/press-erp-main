<?php
require_once __DIR__ . '/../../config/app.php';
checkAuth();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/project_visibility_helper.php';

$analyticsUid = (int) ($_SESSION['user_id'] ?? 0);
$vis = project_visibility_sql_where_for_projects('p', $analyticsUid, $pdo);
$visClause = $vis['clause'];
$visBinds = $vis['binds'];

$total_projects = 0;
$active_projects = 0;
$completed_projects = 0;
$task_stats = ['total' => 0, 'completed' => 0, 'in_review' => 0, 'overdue' => 0];
$project_performance = [];
$assignee_workload = [];

try {
    $baseCount = "SELECT COUNT(*) FROM projects p WHERE 1=1 $visClause";
    $st = $pdo->prepare($baseCount);
    foreach ($visBinds as $k => $v) {
        $st->bindValue(':' . $k, $v, PDO::PARAM_INT);
    }
    $st->execute();
    $total_projects = (int) $st->fetchColumn();

    $st = $pdo->prepare("SELECT COUNT(*) FROM projects p WHERE p.status = 'In Progress' $visClause");
    foreach ($visBinds as $k => $v) {
        $st->bindValue(':' . $k, $v, PDO::PARAM_INT);
    }
    $st->execute();
    $active_projects = (int) $st->fetchColumn();

    $st = $pdo->prepare("SELECT COUNT(*) FROM projects p WHERE p.status = 'Completed' $visClause");
    foreach ($visBinds as $k => $v) {
        $st->bindValue(':' . $k, $v, PDO::PARAM_INT);
    }
    $st->execute();
    $completed_projects = (int) $st->fetchColumn();

    $taskSql = "
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN t.status = 'Completed' THEN 1 ELSE 0 END) as completed,
        SUM(CASE WHEN t.status = 'In Review' THEN 1 ELSE 0 END) as in_review,
        SUM(CASE WHEN t.due_date < CURDATE() AND t.status != 'Completed' AND t.status != 'Cancelled' THEN 1 ELSE 0 END) as overdue
    FROM tasks t
    INNER JOIN projects p ON p.id = t.project_id
    WHERE 1=1 $visClause";
    $st = $pdo->prepare($taskSql);
    foreach ($visBinds as $k => $v) {
        $st->bindValue(':' . $k, $v, PDO::PARAM_INT);
    }
    $st->execute();
    $task_stats = $st->fetch(PDO::FETCH_ASSOC) ?: $task_stats;

    $perfSql = "
    SELECT 
        p.id, p.name, p.status, p.approved_status,
        COUNT(t.id) as total_tasks,
        SUM(CASE WHEN t.status = 'Completed' THEN 1 ELSE 0 END) as completed_tasks,
        SUM(CASE WHEN t.due_date < CURDATE() AND t.status != 'Completed' AND t.status != 'Cancelled' THEN 1 ELSE 0 END) as overdue_tasks,
        AVG(t.score) as avg_score
    FROM projects p
    LEFT JOIN tasks t ON p.id = t.project_id
    WHERE 1=1 $visClause
    GROUP BY p.id
    ORDER BY p.created_at DESC";
    $st = $pdo->prepare($perfSql);
    foreach ($visBinds as $k => $v) {
        $st->bindValue(':' . $k, $v, PDO::PARAM_INT);
    }
    $st->execute();
    $project_performance = $st->fetchAll(PDO::FETCH_ASSOC);

    $awSql = "
    SELECT 
        u.name,
        COUNT(DISTINCT ta.task_id) as total_tasks,
        COUNT(DISTINCT CASE WHEN t.status != 'Completed' AND t.status != 'Cancelled' THEN ta.task_id ELSE NULL END) as open_tasks,
        COUNT(DISTINCT CASE WHEN t.due_date < CURDATE() AND t.status != 'Completed' AND t.status != 'Cancelled' THEN ta.task_id ELSE NULL END) as overdue_tasks,
        AVG(t.score) as avg_score
    FROM users u
    JOIN task_assignees ta ON u.id = ta.user_id
    JOIN tasks t ON ta.task_id = t.id
    INNER JOIN projects p ON p.id = t.project_id
    WHERE 1=1 $visClause
    GROUP BY u.id
    ORDER BY open_tasks DESC";
    $st = $pdo->prepare($awSql);
    foreach ($visBinds as $k => $v) {
        $st->bindValue(':' . $k, $v, PDO::PARAM_INT);
    }
    $st->execute();
    $assignee_workload = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('modules/projects/analytics: ' . $e->getMessage());
}

include '../../includes/header.php';
?>

<div class="workspace-stack">
    <div class="workspace-header">
        <div class="min-w-0">
            <a href="list" class="workspace-back-link mb-4">
                <i data-lucide="arrow-left" class="text-sm" aria-hidden="true"></i>
                <span>Back to Projects</span>
            </a>
            <h1 class="text-3xl font-bold text-gray-800 break-words">Project & Task Analytics</h1>
            <p class="text-gray-600 mt-1">Robust reporting on delivery health, team performance, and outcome scores.</p>
        </div>
        <div class="workspace-header-actions">
            <button onclick="window.print()" class="surface-button text-gray-600">
                <i data-lucide="printer" class="text-sm sm:mr-1" aria-hidden="true"></i>
                <span class="hidden sm:inline">Print Report</span>
            </button>
        </div>
    </div>

    <!-- KPI Cards -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6">
        <div class="workspace-panel p-6 bg-white border-l-4 border-blue-500">
            <p class="text-sm font-bold text-gray-500 uppercase mb-2">Total Projects</p>
            <p class="text-3xl font-bold text-gray-800"><?php echo $total_projects; ?></p>
            <p class="text-xs text-blue-600 mt-2 font-semibold"><?php echo $active_projects; ?> effectively in progress</p>
        </div>
        <div class="workspace-panel p-6 bg-white border-l-4 border-green-500">
            <p class="text-sm font-bold text-gray-500 uppercase mb-2">Task Completion</p>
            <p class="text-3xl font-bold text-gray-800">
                <?php 
                    $completion_rate = $task_stats['total'] > 0 ? round(($task_stats['completed'] / $task_stats['total']) * 100) : 0;
                    echo $completion_rate . '%';
                ?>
            </p>
            <p class="text-xs text-green-600 mt-2 font-semibold"><?php echo $task_stats['completed']; ?> of <?php echo $task_stats['total']; ?> tasks done</p>
        </div>
        <div class="workspace-panel p-6 bg-white border-l-4 border-yellow-500">
            <p class="text-sm font-bold text-gray-500 uppercase mb-2">Pending Review</p>
            <p class="text-3xl font-bold text-gray-800"><?php echo $task_stats['in_review'] ?? 0; ?></p>
            <p class="text-xs text-yellow-600 mt-2 font-semibold">Awaiting PM validation</p>
        </div>
        <div class="workspace-panel p-6 bg-white border-l-4 border-red-500">
            <p class="text-sm font-bold text-gray-500 uppercase mb-2">Overdue Alerts</p>
            <p class="text-3xl font-bold text-gray-800"><?php echo $task_stats['overdue'] ?? 0; ?></p>
            <p class="text-xs text-red-600 mt-2 font-semibold">Immediate attention required</p>
        </div>
    </div>

    <!-- Project Performance -->
    <div class="workspace-panel p-6">
        <h2 class="text-xl font-bold text-gray-800 mb-6 flex items-center gap-2">
            <i data-lucide="clipboard-check" class="text-blue-600 w-5 h-5" aria-hidden="true"></i> Project Delivery Health
        </h2>
        <div class="overflow-x-auto">
            <table class="w-full text-sm text-left border-collapse">
                <thead class="bg-gray-50 text-gray-600 font-bold uppercase text-xs">
                    <tr>
                        <th class="px-4 py-3 border-b">Project Name</th>
                        <th class="px-4 py-3 border-b">Status</th>
                        <th class="px-4 py-3 border-b">Approval</th>
                        <th class="px-4 py-3 border-b">Task Progress</th>
                        <th class="px-4 py-3 border-b text-center">Overdue</th>
                        <th class="px-4 py-3 border-b text-center">Avg Score</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    <?php foreach ($project_performance as $pp): ?>
                    <?php 
                        $prog = $pp['total_tasks'] > 0 ? round(($pp['completed_tasks'] / $pp['total_tasks']) * 100) : 0;
                    ?>
                    <tr class="hover:bg-gray-50 transition">
                        <td class="px-4 py-4 font-bold text-gray-800">
                            <a href="view?id=<?php echo $pp['id']; ?>" class="text-blue-600 hover:underline">
                                <?php echo htmlspecialchars($pp['name']); ?>
                            </a>
                        </td>
                        <td class="px-4 py-4">
                            <span class="px-2 py-0.5 rounded-full text-xs font-semibold <?php 
                                echo $pp['status'] == 'Completed' ? 'bg-green-100 text-green-800' : 'bg-blue-100 text-blue-800'; 
                            ?>">
                                <?php echo $pp['status']; ?>
                            </span>
                        </td>
                        <td class="px-4 py-4 text-xs font-bold uppercase">
                            <span class="<?php 
                                echo $pp['approved_status'] == 'Approved' ? 'text-green-600' : 
                                    ($pp['approved_status'] == 'Rejected' ? 'text-red-600' : 'text-gray-400'); 
                            ?>">
                                <?php echo $pp['approved_status']; ?>
                            </span>
                        </td>
                        <td class="px-4 py-4">
                            <div class="flex items-center gap-3">
                                <div class="w-24 bg-gray-200 rounded-full h-1.5 flex-shrink-0">
                                    <div class="bg-green-500 h-1.5 rounded-full" style="width: <?php echo $prog; ?>%"></div>
                                </div>
                                <span class="font-bold text-gray-700"><?php echo $prog; ?>%</span>
                            </div>
                        </td>
                        <td class="px-4 py-4 text-center">
                            <span class="<?php echo $pp['overdue_tasks'] > 0 ? 'text-red-600 font-bold' : 'text-gray-400'; ?>">
                                <?php echo $pp['overdue_tasks']; ?>
                            </span>
                        </td>
                        <td class="px-4 py-4 text-center">
                            <?php if ($pp['avg_score']): ?>
                                <span class="text-yellow-600 font-bold"><?php echo number_format($pp['avg_score'], 1); ?> ★</span>
                            <?php else: ?>
                                <span class="text-gray-300">-</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Team Workload -->
    <div class="workspace-panel p-6">
        <h2 class="text-xl font-bold text-gray-800 mb-6 flex items-center gap-2">
            <i data-lucide="users" class="text-green-600 w-5 h-5" aria-hidden="true"></i> Team Workload & Performance
        </h2>
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
            <?php foreach ($assignee_workload as $aw): ?>
            <div class="bg-gray-50 border border-gray-100 p-5 rounded-2xl">
                <div class="flex items-center justify-between mb-4">
                    <span class="text-lg font-bold text-gray-800 truncate"><?php echo htmlspecialchars($aw['name']); ?></span>
                    <?php if ($aw['avg_score']): ?>
                        <span class="bg-yellow-100 text-yellow-800 px-3 py-1 rounded-full text-xs font-bold">
                            <?php echo number_format($aw['avg_score'], 1); ?> ★ Score
                        </span>
                    <?php endif; ?>
                </div>
                
                <div class="grid grid-cols-2 gap-4">
                    <div class="bg-white p-3 rounded-xl border border-gray-100">
                        <p class="text-[10px] text-gray-400 uppercase font-bold mb-1">Open Tasks</p>
                        <p class="text-xl font-bold text-blue-600"><?php echo $aw['open_tasks']; ?></p>
                    </div>
                    <div class="bg-white p-3 rounded-xl border border-gray-100">
                        <p class="text-[10px] text-gray-400 uppercase font-bold mb-1">Overdue</p>
                        <p class="text-xl font-bold text-red-600"><?php echo $aw['overdue_tasks']; ?></p>
                    </div>
                </div>

                <div class="mt-4">
                    <div class="flex justify-between text-xs text-gray-500 mb-1 font-bold italic">
                        <span>Total Assigned: <?php echo $aw['total_tasks']; ?></span>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<script>if (typeof window.refreshAppShellIcons === 'function') { window.refreshAppShellIcons(); }</script>
<?php include '../../includes/footer.php'; ?>
