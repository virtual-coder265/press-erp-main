<?php

require_once __DIR__ . '/../../config/app.php';
checkAuth();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../libs/BrowserPushManager.php';

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Method not allowed.',
    ]);
    exit;
}

$payload = json_decode(file_get_contents('php://input') ?: '{}', true);
if (!is_array($payload)) {
    $payload = $_POST;
}

if (!verify_csrf_token($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($payload['_csrf'] ?? null), 'push_test')) {
    http_response_code(419);
    echo json_encode([
        'success' => false,
        'message' => 'Invalid push test token.',
    ]);
    exit;
}

$manager = new BrowserPushManager($pdo);
$result = $manager->sendTestNotification((int) ($_SESSION['user_id'] ?? 0));

if (!empty($result['success'])) {
    echo json_encode([
        'success' => true,
        'message' => 'Test push sent successfully.',
        'result' => $result,
    ]);
    exit;
}

http_response_code(422);
echo json_encode([
    'success' => false,
    'message' => !empty($result['reason']) && $result['reason'] === 'no_subscriptions'
        ? 'No browser subscription is active yet. Allow notification permission and enable browser push for this browser first.'
        : (!empty($result['reason']) ? 'Unable to send test push: ' . $result['reason'] : 'Unable to send test push.'),
    'result' => $result,
]);
