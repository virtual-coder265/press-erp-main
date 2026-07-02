<?php
/**
 * Daily Task Reminders Cron
 * Run via Task Scheduler: php cron/task_reminders.php
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../libs/NotificationManager.php';

$notifManager = new NotificationManager($pdo);

// Find tasks due within the next 48 hours that are NOT completed/cancelled
// and haven't been reminded about in the last 24 hours.
$query = "
    SELECT t.*, u.name as assigned_name, p.name as project_name
    FROM tasks t
    JOIN users u ON t.assigned_to = u.id
    JOIN projects p ON t.project_id = p.id
    LEFT JOIN task_reminder_log rl ON t.id = rl.task_id AND rl.reminded_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
    WHERE t.status NOT IN ('Completed', 'Cancelled')
      AND t.due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 2 DAY)
      AND rl.id IS NULL
";

$tasks = $pdo->query($query)->fetchAll();
$count = 0;

foreach ($tasks as $task) {
    $dueDate = date('M d, Y', strtotime($task['due_date']));
    $title = "Reminder: Task Due Soon";
    $description = "Hello {$task['assigned_name']}, your task \"{$task['name']}\" in project \"{$task['project_name']}\" is due on {$dueDate}.";
    
    // Notify via system channels
    $notifManager->notify(
        $task['assigned_to'],
        'task',
        $title,
        $description,
        'modules/tasks/list?id=' . $task['id'],
        $task['id'],
        false, // silent (don't force SMS if they haven't opted in for reminders)
        false, // not persistent
        ['dueDate' => $task['due_date'], 'taskTitle' => $task['name']]
    );

    // Log the reminder
    $logStmt = $pdo->prepare("INSERT INTO task_reminder_log (task_id) VALUES (?)");
    $logStmt->execute([$task['id']]);
    
    $count++;
}

echo "Task Reminders Cron Executed: Sent {$count} reminders.\n";
