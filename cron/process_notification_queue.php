<?php
require_once __DIR__ . '/../includes/cron_http_guard.php';
cron_require_cli_or_secret();

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../libs/NotificationManager.php';
require_once __DIR__ . '/../libs/AuditLogger.php';

$limit = isset($_GET['limit']) ? max(1, (int) $_GET['limit']) : 25;

$manager = new NotificationManager($pdo);
$stats = $manager->processQueue($limit);

$auditLogger = new AuditLogger($pdo);
$auditLogger->log(
    'queue',
    'notification_queue_batch_processed',
    'Notification queue batch processed.',
    [
        'severity' => ($stats['failed'] ?? 0) > 0 ? 'warning' : 'info',
        'status' => (($stats['failed'] ?? 0) > 0) ? 'partial_failure' : 'success',
        'context' => array_merge($stats, ['limit' => $limit]),
    ]
);

echo json_encode([
    'success' => true,
    'stats' => $stats,
], JSON_PRETTY_PRINT);
