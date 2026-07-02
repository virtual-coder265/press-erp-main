<?php
require_once dirname(dirname(__DIR__)) . '/config/app.php';
checkAuth();
require_once ROOT_PATH . 'config/database.php';
require_once ROOT_PATH . 'includes/task_management_helper.php';

$user_id = intval($_SESSION['user_id']);
$tasks = [];
$error_message = '';

try {
    $stmt = $pdo->prepare("
        SELECT id, name as title, description, status, due_date, priority
        FROM tasks
        WHERE assigned_to = ?
           OR EXISTS (SELECT 1 FROM task_assignees ta WHERE ta.task_id = tasks.id AND ta.user_id = ?)
        ORDER BY due_date ASC
    ");
    $stmt->execute([$user_id, $user_id]);
    $tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $assigneeMap = fetch_task_assignees_for_tasks($pdo, array_column($tasks, 'id'));
    foreach ($tasks as &$task) {
        $task['assignee_summary'] = format_task_assignee_summary($assigneeMap[$task['id']] ?? []);
    }
    unset($task);
} catch (Exception $e) {
    $error_message = "Error fetching tasks: " . $e->getMessage();
}

$status_styles = [
    'Not Started' => 'bg-amber-100 text-amber-800 border-amber-200',
    'In Progress' => 'bg-blue-100 text-blue-800 border-blue-200',
    'In Review' => 'bg-yellow-100 text-yellow-800 border-yellow-200',
    'Completed' => 'bg-emerald-100 text-emerald-800 border-emerald-200',
    'Cancelled' => 'bg-rose-100 text-rose-800 border-rose-200'
];

$priority_styles = [
    'Low' => 'bg-gray-100 text-gray-700',
    'Medium' => 'bg-orange-50 text-orange-700',
    'High' => 'bg-red-50 text-red-700',
    'Urgent' => 'bg-red-100 text-red-800'
];

include ROOT_PATH . 'includes/header.php';
?>

<div class="max-w-4xl mx-auto">
    <div class="mb-8 flex justify-between items-end">
        <div>
            <h1 class="text-3xl font-extrabold text-gray-900 tracking-tight">Assigned Tasks</h1>
            <p class="text-gray-500 mt-1">Review and manage your active project responsibilities.</p>
        </div>
        <div class="hidden md:flex items-center space-x-2">
            <span class="text-xs font-bold text-gray-400 uppercase">Status Guide:</span>
            <span class="w-3 h-3 rounded-full bg-amber-400"></span>
            <span class="w-3 h-3 rounded-full bg-blue-400"></span>
            <span class="w-3 h-3 rounded-full bg-emerald-400"></span>
            <a href="../reminders/index?source=task_assignment" class="ml-3 inline-flex items-center gap-1 rounded-full border border-gray-200 bg-white px-3 py-2 text-xs font-semibold text-gray-700 hover:border-teal-200 hover:text-teal-700">
                <i class="material-icons text-sm">alarm</i>
                <span>Reminder Board</span>
            </a>
        </div>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-3 gap-8">
        <!-- Sidebar Navigation -->
        <div class="space-y-2">
            <a href="profile" class="flex items-center space-x-3 px-4 py-3 text-gray-600 hover:bg-white hover:shadow-sm rounded-xl transition-all">
                <i class="material-icons">person</i>
                <span class="font-medium">Public Profile</span>
            </a>
            <a href="security" class="flex items-center space-x-3 px-4 py-3 text-gray-600 hover:bg-white hover:shadow-sm rounded-xl transition-all">
                <i class="material-icons">security</i>
                <span class="font-medium">Security</span>
            </a>
            <a href="tasks" class="flex items-center space-x-3 px-4 py-3 bg-blue-600 text-white rounded-xl shadow-md transition-all">
                <i class="material-icons">assignment</i>
                <span class="font-medium">My Tasks</span>
            </a>
            <a href="../reminders/index" class="flex items-center space-x-3 px-4 py-3 text-gray-600 hover:bg-white hover:shadow-sm rounded-xl transition-all">
                <i class="material-icons">alarm</i>
                <span class="font-medium">Reminders</span>
            </a>
        </div>

        <!-- Task List -->
        <div class="md:col-span-2 space-y-4">
            <?php if ($error_message): ?>
                <div class="p-4 bg-red-50 text-red-700 rounded-xl border border-red-100 flex items-center">
                    <i class="material-icons mr-2">error</i>
                    <?php echo htmlspecialchars($error_message); ?>
                </div>
            <?php endif; ?>

            <?php if (empty($tasks)): ?>
                <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-12 text-center">
                    <div class="w-20 h-20 bg-gray-50 rounded-full flex items-center justify-center mx-auto mb-6">
                        <i class="material-icons text-4xl text-gray-300">verified</i>
                    </div>
                    <h2 class="text-xl font-bold text-gray-800">No Assigned Tasks</h2>
                    <p class="text-gray-500 mt-2">You're all caught up! There are currently no tasks assigned to your account.</p>
                </div>
            <?php else: ?>
                <div class="space-y-4">
                    <?php foreach ($tasks as $task): ?>
                        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6 hover:shadow-lg transition-all group">
                            <div class="flex justify-between items-start mb-4">
                                <div class="flex-1">
                                    <div class="flex items-center gap-2 mb-1">
                                        <h2 class="text-lg font-bold text-gray-900 group-hover:text-blue-600 transition-colors">
                                            <?php echo htmlspecialchars($task['title']); ?>
                                        </h2>
                                        <span class="px-2 py-0.5 text-[10px] font-bold uppercase rounded <?php echo $priority_styles[$task['priority']] ?? 'bg-gray-100 text-gray-600'; ?>">
                                            <?php echo $task['priority']; ?>
                                        </span>
                                    </div>
                                    <p class="text-gray-600 text-sm line-clamp-2"><?php echo htmlspecialchars($task['description']); ?></p>
                                    <?php if (!empty($task['assignee_summary'])): ?>
                                    <p class="text-xs text-gray-400 mt-2">Team: <?php echo htmlspecialchars($task['assignee_summary']); ?></p>
                                    <?php endif; ?>
                                </div>
                                <span class="px-3 py-1 text-xs font-bold rounded-lg border <?php echo $status_styles[$task['status']] ?? 'bg-gray-100'; ?>">
                                    <?php echo htmlspecialchars($task['status']); ?>
                                </span>
                            </div>
                            
                            <div class="flex items-center justify-between border-t border-gray-50 pt-4 mt-2">
                                <div class="flex items-center text-xs font-medium text-gray-500">
                                    <i class="material-icons text-sm mr-2 text-blue-400">calendar_today</i>
                                    Due Date: <?php echo !empty($task['due_date']) ? date('M d, Y', strtotime($task['due_date'])) : 'Not set'; ?>
                                </div>
                                <a href="../tasks/view?id=<?php echo (int) $task['id']; ?>" class="text-blue-600 hover:text-blue-800 font-bold text-xs uppercase tracking-wider flex items-center">
                                    Details <i class="material-icons text-sm ml-1">chevron_right</i>
                                </a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php include ROOT_PATH . 'includes/footer.php'; ?>
