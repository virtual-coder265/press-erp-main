<?php

require_once __DIR__ . '/../../config/app.php';
checkAuth();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../libs/BrowserPushManager.php';

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$manager = new BrowserPushManager($pdo);
$userId = (int) ($_SESSION['user_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    echo json_encode([
        'success' => true,
        'status' => $manager->getStatus($userId),
    ]);
    exit;
}

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

if (!verify_csrf_token($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($payload['_csrf'] ?? null), 'push_subscription')) {
    http_response_code(419);
    echo json_encode([
        'success' => false,
        'message' => 'Invalid push subscription request token.',
    ]);
    exit;
}

$action = trim((string) ($payload['action'] ?? 'save'));

try {
    if ($action === 'delete') {
        $endpoint = trim((string) ($payload['endpoint'] ?? ''));
        $success = $manager->deactivateSubscription($userId, $endpoint);

        echo json_encode([
            'success' => $success,
            'message' => $success ? 'Subscription removed.' : 'Subscription was not found.',
            'status' => $manager->getStatus($userId),
        ]);
        exit;
    }

    $subscription = $payload['subscription'] ?? null;
    if (!is_array($subscription)) {
        throw new InvalidArgumentException('A valid browser subscription payload is required.');
    }

    $result = $manager->saveSubscription($userId, $subscription, [
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
    ]);

    echo json_encode([
        'success' => !empty($result['success']),
        'message' => !empty($result['success']) ? 'Subscription saved.' : ($result['message'] ?? 'Subscription could not be saved.'),
        'status' => $manager->getStatus($userId),
    ]);
} catch (Throwable $exception) {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'message' => $exception->getMessage(),
        'status' => $manager->getStatus($userId),
    ]);
}
