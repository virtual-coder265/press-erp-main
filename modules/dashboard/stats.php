<?php
require_once __DIR__ . '/../../config/app.php';
checkAuth();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/dashboard_metrics_helper.php';

header('Content-Type: application/json');

try {
    $viewerStats = (int) ($_SESSION['user_id'] ?? 0);
    $metrics = dashboard_fetch_summary_stats($pdo, $viewerStats);
    echo json_encode([
        'success' => true,
        'estimations' => (int) ($metrics['estimations']['val'] ?? 0),
        'invoices' => (int) ($metrics['invoices']['val'] ?? 0),
        'unpaid_invoices' => (int) ($metrics['unpaid_invoices']['val'] ?? 0),
        'active_projects' => (int) ($metrics['active_projects']['val'] ?? 0),
        'dispatched' => (int) ($metrics['dispatched']['val'] ?? 0),
        'users' => (int) ($metrics['users']['val'] ?? 0),
        'total_revenue' => (float) ($metrics['total_revenue']['val'] ?? 0),
        'collected' => (float) ($metrics['collected']['val'] ?? 0),
        'outstanding' => (float) ($metrics['outstanding']['val'] ?? 0),
        'partially_paid' => (int) ($metrics['partially_paid']['val'] ?? 0),
    ]);
    exit;
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Failed to fetch stats']);
    exit;
}
