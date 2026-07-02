<?php
require_once __DIR__ . '/../../config/app.php';
checkAuth();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/reminder_helper.php';

function reminder_save_is_ajax(): bool
{
    return strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';
}

function reminder_save_json(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verify_csrf_token($_POST['_csrf'] ?? null, 'reminder_save')) {
    if (reminder_save_is_ajax()) {
        reminder_save_json(['ok' => false, 'error' => 'Invalid reminder request.'], 400);
    }
    redirect('modules/reminders/index?error=Invalid reminder request.');
}

$redirectTo = trim((string) ($_POST['redirect_to'] ?? 'modules/reminders/index'));
if ($redirectTo === '' || strpos($redirectTo, 'modules/reminders/') !== 0) {
    $redirectTo = 'modules/reminders/index';
}

try {
    $reminderId = !empty($_POST['id']) ? (int) $_POST['id'] : null;
    $savedReminderId = $reminderId;
    $isTaskLinked = false;

    if ($reminderId) {
        $existing = fetch_user_reminder($pdo, (int) $_SESSION['user_id'], $reminderId);
        if ($existing && $existing['source'] === 'task_assignment') {
            $isTaskLinked = true;
            update_reminder_alarm_settings(
                $pdo,
                (int) $_SESSION['user_id'],
                $reminderId,
                $_POST
            );
        }
    }

    if (!$isTaskLinked) {
        $savedReminderId = save_personal_reminder($pdo, (int) $_SESSION['user_id'], $_POST, $reminderId);
    }

    if (reminder_save_is_ajax()) {
        reminder_save_json([
            'ok' => true,
            'id' => (int) $savedReminderId,
            'title' => $reminderId ? ($isTaskLinked ? 'Reminder alarm updated' : 'Reminder updated') : 'Reminder created',
            'message' => $reminderId ? ($isTaskLinked ? 'Reminder alarm updated' : 'Reminder updated') : 'Reminder created',
            'open_url' => BASE_URL . 'modules/reminders/index?detail=' . (int) $savedReminderId,
        ]);
    }

    redirect(append_query_params_to_path($redirectTo, [
        'success' => $reminderId ? ($isTaskLinked ? 'alarm_updated' : 'updated') : 'created',
        'detail' => $savedReminderId,
    ]));
} catch (Exception $e) {
    if (reminder_save_is_ajax()) {
        reminder_save_json(['ok' => false, 'error' => $e->getMessage()], 422);
    }

    $params = ['error' => $e->getMessage()];
    if (!empty($_POST['id'])) {
        $params['edit'] = (int) $_POST['id'];
    }

    redirect(append_query_params_to_path($redirectTo, $params));
}
