<?php
require_once __DIR__ . '/../../config/app.php';
checkAuth();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/reminder_helper.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verify_csrf_token($_POST['_csrf'] ?? null, 'reminder_alarm')) {
    redirect('modules/reminders/index?error=Invalid reminder alarm request.');
}

$redirectTo = trim((string) ($_POST['redirect_to'] ?? 'modules/reminders/index'));
if ($redirectTo === '' || strpos($redirectTo, 'modules/reminders/') !== 0) {
    $redirectTo = 'modules/reminders/index';
}

$reminderId = (int) ($_POST['id'] ?? 0);

try {
    update_reminder_alarm_settings($pdo, (int) ($_SESSION['user_id'] ?? 0), $reminderId, $_POST);
    redirect(append_query_params_to_path($redirectTo, [
        'success' => 'alarm_updated',
    ]));
} catch (Exception $e) {
    redirect(append_query_params_to_path($redirectTo, [
        'error' => $e->getMessage(),
    ]));
}
