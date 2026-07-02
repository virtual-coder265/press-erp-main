<?php
require_once __DIR__ . '/../../config/app.php';
checkAuth();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/reminder_helper.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$userId = (int) ($_SESSION['user_id'] ?? 0);
$year = (int) ($_GET['year'] ?? 0);
$month = (int) ($_GET['month'] ?? 0);

if ($year < 1970 || $year > 2100 || $month < 1 || $month > 12) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid year or month.']);
    exit;
}

$status = trim((string) ($_GET['status'] ?? 'active'));
if (!in_array($status, ['active', 'all'], true)) {
    $status = 'active';
}

$source = trim((string) ($_GET['source'] ?? 'all'));
if (!in_array($source, ['all', 'self', 'task_assignment'], true)) {
    $source = 'all';
}

$search = trim((string) ($_GET['search'] ?? ''));

if (!reminder_module_ready($pdo)) {
    echo json_encode([
        'success' => true,
        'module_ready' => false,
        'events' => [],
        'by_day' => [],
    ]);
    exit;
}

$start = sprintf('%04d-%02d-01', $year, $month);
$end = date('Y-m-t', strtotime($start));

$rows = fetch_reminders_for_calendar_range($pdo, $userId, $start, $end, [
    'status' => $status,
    'source' => $source,
    'search' => $search,
]);
$events = [];
foreach ($rows as $r) {
    $day = (string) ($r['calendar_day'] ?? '');
    if ($day === '') {
        continue;
    }
    $events[] = [
        'id' => (int) ($r['id'] ?? 0),
        'calendar_day' => $day,
        'title' => (string) ($r['title'] ?? ''),
        'source' => (string) ($r['source'] ?? 'self'),
        'source_label' => $r['source_label'] ?? '',
        'status' => (string) ($r['status'] ?? 'active'),
        'priority' => (string) ($r['priority'] ?? 'Medium'),
        'task_id' => !empty($r['task_id']) ? (int) $r['task_id'] : null,
        'project_name' => $r['project_name'] ?? null,
        'due_compact' => $r['due_meta']['compact_label'] ?? '',
        'time_hint' => !empty($r['remind_at_display']) ? $r['remind_at_display'] : null,
    ];
}

echo json_encode([
    'success' => true,
    'module_ready' => true,
    'range' => ['start' => $start, 'end' => $end],
    'events' => $events,
    'server_time' => date('c'),
]);
