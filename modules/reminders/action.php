<?php
require_once __DIR__ . '/../../config/app.php';
checkAuth();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/reminder_helper.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verify_csrf_token($_POST['_csrf'] ?? null, 'reminder_action')) {
    redirect('modules/reminders/index?error=Invalid reminder action.');
}

$redirectTo = trim((string) ($_POST['redirect_to'] ?? 'modules/reminders/index'));
if ($redirectTo === '' || strpos($redirectTo, 'modules/reminders/') !== 0) {
    $redirectTo = 'modules/reminders/index';
}

$action = trim((string) ($_POST['action'] ?? ''));
$reminderId = (int) ($_POST['id'] ?? 0);

try {
    if ($action === 'delete') {
        delete_personal_reminder($pdo, (int) $_SESSION['user_id'], $reminderId);
    } elseif ($action === 'snooze') {
        snooze_personal_reminder($pdo, (int) $_SESSION['user_id'], $reminderId, (int) ($_POST['minutes'] ?? 10));
    } else {
        update_personal_reminder_status($pdo, (int) $_SESSION['user_id'], $reminderId, $action);
    }
    $successMap = [
        'complete' => 'completed',
        'reopen' => 'reopened',
        'dismiss' => 'dismissed',
        'delete' => 'deleted',
        'snooze' => 'snoozed',
    ];
    redirect(append_query_params_to_path($redirectTo, [
        'success' => $successMap[$action] ?? 'updated',
    ]));
} catch (Exception $e) {
    redirect(append_query_params_to_path($redirectTo, [
        'error' => $e->getMessage(),
    ]));
}
