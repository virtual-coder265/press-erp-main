<?php
require_once __DIR__ . '/../../config/app.php';
checkAuth();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/permissions_helper.php';
require_once __DIR__ . '/../../includes/reports_helper.php';
require_once __DIR__ . '/../../libs/ExportManager.php';

$reportKey = trim((string) ($_GET['report'] ?? ''));
$format = trim((string) ($_GET['format'] ?? 'pdf'));

if ($reportKey === '' || !reports_can_access($reportKey)) {
    http_response_code(403);
    die('Access Denied.');
}

if ($reportKey === 'work_orders') {
    require_once __DIR__ . '/../../includes/work_order_helper.php';
    work_order_bootstrap($pdo);
}

if (in_array($reportKey, ['invoices', 'sales'], true)) {
    require_once __DIR__ . '/../../libs/InvoiceAuditMigrator.php';
    InvoiceAuditMigrator::ensure($pdo);
}

$filters = reports_read_filters();
$data = reports_fetch_rows($pdo, $reportKey, $filters);
$columns = reports_get_columns($reportKey);
$title = reports_get_title($reportKey);
$filename = ucfirst(str_replace('_', '', $reportKey)) . '_Report_' . date('Y-m-d_His');
$periodLabel = reports_filter_period_label($filters);

$exportOptions = [
    'orientation' => 'L',
    'pageSize' => 'A4',
    'fontSize' => count($columns) > 8 ? 7 : 8,
    'branded' => true,
    'periodLabel' => $periodLabel,
];

switch ($format) {
    case 'pdf':
        ExportManager::exportToPDF($data, $columns, $title, $filename, $exportOptions);
        break;

    case 'excel':
    case 'xlsx':
        ExportManager::exportToExcel($data, $columns, $title, $filename, $exportOptions);
        break;

    case 'csv':
        ExportManager::exportToCSV($data, $columns, $filename);
        break;

    default:
        $_SESSION['error'] = 'Invalid export format';
        redirect('modules/reports/' . $reportKey);
}
