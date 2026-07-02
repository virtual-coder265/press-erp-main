<?php
/**
 * Cron Script for Automated Reminders and Notifications
 * 
 * This script should be run via cron (Linux) or Task Scheduler (Windows) twice a day:
 * e.g., at 08:00 AM and 17:00 PM.
 * 
 * Command example: php /path/to/press-erp/cron/reminders.php
 */

// Since this is a CLI script, setting up path and require basics
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../libs/NotificationManager.php';

echo "Starting Notifications Cron Job: " . date('Y-m-d H:i:s') . "\n";

try {
    $notifManager = new NotificationManager($pdo);
    
    // 1. Fetch Urgent Projects Tasks
    $stmt = $pdo->prepare("
        SELECT t.*, p.name as project_name, u.name as assigned_name, u.email as assigned_email
        FROM tasks t
        JOIN projects p ON t.project_id = p.id
        LEFT JOIN users u ON t.assigned_to = u.id
        WHERE t.status != 'Completed' AND t.status != 'Cancelled'
        AND (t.priority = 'Urgent' OR p.priority = 'Urgent')
        AND t.assigned_to IS NOT NULL
    ");
    $stmt->execute();
    $urgentTasks = $stmt->fetchAll();

    echo "Found " . count($urgentTasks) . " urgent tasks.\n";

    foreach ($urgentTasks as $task) {
        $title = "URGENT REMINDER: Task '{$task['name']}'";
        $description = "This is an automated reminder that your assigned task '{$task['name']}' in project '{$task['project_name']}' is marked as URGENT. Please prioritize this task.";
        $link = 'modules/tasks/list?id=' . $task['id'];
        
        // Send notification via email and SMS (true flag)
        $notifManager->notify($task['assigned_to'], 'reminder', $title, $description, $link, $task['id'], true, false);
    }

    // 2. Fetch Due Today or Overdue Tasks
    $today = date('Y-m-d');
    $stmt = $pdo->prepare("
        SELECT t.*, p.name as project_name, u.name as assigned_name, u.email as assigned_email
        FROM tasks t
        JOIN projects p ON t.project_id = p.id
        LEFT JOIN users u ON t.assigned_to = u.id
        WHERE t.status != 'Completed' AND t.status != 'Cancelled'
        AND t.due_date IS NOT NULL AND t.due_date <= :today
        AND t.assigned_to IS NOT NULL
    ");
    $stmt->execute(['today' => $today]);
    $dueTasks = $stmt->fetchAll();

    echo "Found " . count($dueTasks) . " due or overdue tasks.\n";

    foreach ($dueTasks as $task) {
        // Skip if already sent as urgent
        $isUrgent = false;
        foreach ($urgentTasks as $ut) {
            if ($ut['id'] == $task['id']) {
                $isUrgent = true;
                break;
            }
        }
        if ($isUrgent) continue;

        $isOverdue = $task['due_date'] < $today;
        $title = $isOverdue ? "OVERDUE TASK: '{$task['name']}'" : "DUE TODAY: '{$task['name']}'";
        
        $description = "This is an automated reminder. The task '{$task['name']}' in project '{$task['project_name']}' is ";
        $description .= $isOverdue ? "past its due date ({$task['due_date']}). " : "due today. ";
        $description .= "Please update the task status as soon as possible.";
        
        $link = 'modules/tasks/list?id=' . $task['id'];
        
        // Send notification via email and SMS (true flag)
        $notifManager->notify($task['assigned_to'], 'reminder', $title, $description, $link, $task['id'], true, false);
    }

    echo "Cron Job completed successfully: " . date('Y-m-d H:i:s') . "\n";
    
} catch (Exception $e) {
    echo "Error processing cron job: " . $e->getMessage() . "\n";
}
