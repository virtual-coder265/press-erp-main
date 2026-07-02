<?php
require_once __DIR__ . '/../../config/app.php';
checkAuth();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/reminder_helper.php';

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    echo json_encode([
        'success' => false,
        'message' => 'Invalid request method.',
    ]);
    exit;
}

try {
    $alarms = fetch_due_reminder_alarms($pdo, (int) ($_SESSION['user_id'] ?? 0), 10);
    echo json_encode([
        'success' => true,
        'count' => count($alarms),
        'alarms' => $alarms,
        'server_time' => date('c'),
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Unable to load reminder alarms.',
    ]);
}
