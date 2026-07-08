<?php
require_once __DIR__ . '/../../config/app.php';
checkAuth();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/service.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$csrfHeader = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
$csrfPost = (string) ($_POST['_csrf'] ?? '');
$csrfToken = $csrfHeader !== '' ? $csrfHeader : $csrfPost;

if (!verify_csrf_token($csrfToken, 'ai_assistant_chat')) {
    http_response_code(419);
    echo json_encode(['success' => false, 'message' => 'Invalid security token.']);
    exit;
}

$rawInput = file_get_contents('php://input');
$payload = [];
if (is_string($rawInput) && trim($rawInput) !== '') {
    $decoded = json_decode($rawInput, true);
    if (is_array($decoded)) {
        $payload = $decoded;
    }
}

$message = trim((string) ($payload['message'] ?? ($_POST['message'] ?? '')));

$result = ai_chat($pdo, (int) ($_SESSION['user_id'] ?? 0), $message);
$statusCode = (int) ($result['status_code'] ?? 200);
unset($result['status_code']);

http_response_code($statusCode);
echo json_encode($result);
exit;

?>
