<?php
require_once __DIR__ . '/../../config/app.php';
checkAuth();
require_once __DIR__ . '/../../config/database.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if (($_SESSION['role'] ?? '') !== 'System Admin' && !hasPermission('manage_settings')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

try {
    $summaryStmt = $pdo->query("
        SELECT
            COUNT(*) AS requests,
            COALESCE(SUM(total_tokens), 0) AS total_tokens,
            COALESCE(SUM(estimated_cost), 0) AS estimated_cost
        FROM ai_usage_events
        WHERE created_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
          AND created_at < DATE_FORMAT(DATE_ADD(CURDATE(), INTERVAL 1 MONTH), '%Y-%m-01')
    ");
    $summary = $summaryStmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $eventsStmt = $pdo->query("
        SELECT id, user_id, feature, model, total_tokens, estimated_cost, status, created_at
        FROM ai_usage_events
        ORDER BY created_at DESC
        LIMIT 50
    ");
    $events = $eventsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    echo json_encode([
        'success' => true,
        'summary' => [
            'requests' => (int) ($summary['requests'] ?? 0),
            'total_tokens' => (int) ($summary['total_tokens'] ?? 0),
            'estimated_cost' => (float) ($summary['estimated_cost'] ?? 0),
        ],
        'events' => $events,
    ]);
    exit;
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unable to load AI usage data.']);
    exit;
}

?>
