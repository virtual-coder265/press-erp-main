<?php

require_once __DIR__ . '/../../config/app.php';
checkAuth();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../libs/AuditLogger.php';
require_once __DIR__ . '/_audit_helpers.php';

audit_require_access();

if (!audit_table_exists($pdo, 'audit_logs')) {
    header('HTTP/1.1 404 Not Found');
    exit('Audit logs are not available yet.');
}

$filters = audit_read_filters($_GET);
$filterSql = audit_build_log_filter_sql($filters);

$stmt = $pdo->prepare("
    SELECT
        l.created_at,
        l.category,
        l.event_type,
        l.severity,
        l.status,
        l.ip_address,
        l.route,
        l.request_method,
        l.message,
        actor.name AS actor_name,
        target.name AS target_name,
        l.context_json
    FROM audit_logs l
    LEFT JOIN users actor ON actor.id = l.user_id
    LEFT JOIN users target ON target.id = l.target_user_id
    WHERE {$filterSql['where_sql']}
    ORDER BY l.created_at DESC
");

foreach ($filterSql['params'] as $key => $value) {
    $stmt->bindValue(':' . $key, $value);
}

$stmt->execute();
$rows = $stmt->fetchAll();

$auditLogger = new AuditLogger($pdo);
$auditLogger->log(
    'admin',
    'audit_report_exported',
    'Audit log report exported as CSV.',
    [
        'severity' => 'info',
        'user_id' => $_SESSION['user_id'] ?? null,
        'status' => 'success',
        'context' => $filters,
    ]
);

$filename = 'audit-report-' . date('Ymd-His') . '.csv';

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

$output = fopen('php://output', 'w');
fputcsv($output, ['Date', 'Category', 'Event Type', 'Severity', 'Status', 'Actor', 'Target User', 'IP Address', 'Route', 'Method', 'Message', 'Context JSON']);

foreach ($rows as $row) {
    fputcsv($output, [
        $row['created_at'],
        $row['category'],
        $row['event_type'],
        $row['severity'],
        $row['status'],
        $row['actor_name'],
        $row['target_name'],
        $row['ip_address'],
        $row['route'],
        $row['request_method'],
        $row['message'],
        $row['context_json'],
    ]);
}

fclose($output);
exit;
