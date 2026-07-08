<?php
require_once __DIR__ . '/../../config/app.php';
checkAuth();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/permissions_helper.php';
permissions_require_one_of(['view_dispatch']);
require_once __DIR__ . '/../../libs/ExportManager.php';

// Get export format
$format = $_GET['format'] ?? 'pdf';
$search_query = $_GET['search'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$work_order_filter = $_GET['work_order'] ?? '';

// Build query with same filters as list page
$query = "SELECT d.*, 
          u1.name as authorised_dispatcher_name, 
          u2.name as created_by_name 
          FROM dispatch_register d 
          LEFT JOIN users u1 ON d.authorised_dispatcher_id = u1.id 
          LEFT JOIN users u2 ON d.created_by = u2.id 
          WHERE 1=1";

$params = [];

if (!empty($search_query)) {
    $query .= " AND (d.work_order_number LIKE :search OR d.ministry_department LIKE :search OR d.job_description LIKE :search OR d.delivery_note_number LIKE :search OR d.remarks LIKE :search)";
    $params['search'] = '%' . $search_query . '%';
}

if (!empty($work_order_filter)) {
    $query .= " AND d.work_order_number LIKE :work_order";
    $params['work_order'] = '%' . $work_order_filter . '%';
}

if (!empty($date_from)) {
    $query .= " AND d.date_in >= :date_from";
    $params['date_from'] = $date_from;
}

if (!empty($date_to)) {
    $query .= " AND d.date_in <= :date_to";
    $params['date_to'] = $date_to;
}

$query .= " ORDER BY d.date_in DESC, d.created_at DESC";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$dispatches = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Define columns for export
$columns = [
    'work_order_number' => 'Work Order #',
    'date_in' => 'Date In',
    'ministry_department' => 'Ministry/Department',
    'job_description' => 'Job Description',
    'remarks' => 'Remarks',
    'quantity' => 'Quantity',
    'date_out' => 'Date Out',
    'delivery_note_number' => 'Delivery Note #',
    'authorised_dispatcher_name' => 'Authorised By',
    'created_by_name' => 'Created By'
];

// Generate filename
$filename = 'Dispatch_Register_' . date('Y-m-d_His');
$title = 'Dispatch Register';

// Export based on format
switch ($format) {
    case 'pdf':
        ExportManager::exportToPDF($dispatches, $columns, $title, $filename, [
            'orientation' => 'L',
            'pageSize' => 'A4',
            'fontSize' => 8
        ]);
        break;
        
    case 'excel':
    case 'xlsx':
        ExportManager::exportToExcel($dispatches, $columns, $title, $filename);
        break;
        
    case 'csv':
        ExportManager::exportToCSV($dispatches, $columns, $filename);
        break;
        
    default:
        $_SESSION['error'] = 'Invalid export format';
        redirect('modules/dispatch/list');
}
