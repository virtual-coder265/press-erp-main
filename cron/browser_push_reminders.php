<?php

require_once __DIR__ . '/../includes/cron_http_guard.php';
cron_require_cli_or_secret();

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/reminder_helper.php';
require_once __DIR__ . '/../libs/BrowserPushManager.php';

$userLimit = isset($_GET['users']) ? max(1, (int) $_GET['users']) : 100;
$perUserLimit = isset($_GET['limit']) ? max(1, (int) $_GET['limit']) : 10;

$pushManager = new BrowserPushManager($pdo);

if (!$pushManager->isEnabled()) {
    echo json_encode([
        'success' => true,
        'message' => 'Browser push reminders are disabled.',
        'users_checked' => 0,
        'alarms_sent' => 0,
    ], JSON_PRETTY_PRINT);
    exit;
}

$stats = [
    'users_checked' => 0,
    'alarms_sent' => 0,
    'alarms_failed' => 0,
];

foreach ($pushManager->getUsersWithActiveSubscriptions($userLimit) as $userId) {
    $stats['users_checked']++;
    $alarms = fetch_due_reminder_alarms($pdo, (int) $userId, $perUserLimit);

    foreach ($alarms as $alarm) {
        $result = $pushManager->sendReminderAlarm((int) $userId, $alarm);
        if (!empty($result['success'])) {
            $stats['alarms_sent'] += max(1, (int) ($result['sent'] ?? 1));
        } else {
            $stats['alarms_failed']++;
        }
    }
}

echo json_encode([
    'success' => true,
    'stats' => $stats,
], JSON_PRETTY_PRINT);
