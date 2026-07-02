<?php
require_once __DIR__ . '/../../../config/app.php';
checkAuth();
require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../libs/ExportManager.php';

// Only Admin
if (!hasPermission('view_users')) {
    die("Access Denied.");
}

// Get export format
$format = $_GET['format'] ?? 'pdf';
$search_query = $_GET['search'] ?? '';
$role_filter = $_GET['role'] ?? '';
$dept_filter = $_GET['department'] ?? '';

// Build query with same filters as list page
$query = "SELECT u.*, r.name as role_name, d.name as dept_name, b.name as branch_name 
          FROM users u 
          JOIN roles r ON u.role_id = r.id 
          JOIN departments d ON u.department_id = d.id 
          LEFT JOIN branches b ON u.branch_id = b.id 
          WHERE 1=1";

$params = [];

if (!empty($search_query)) {
    $query .= " AND (u.name LIKE :search OR u.email LIKE :search)";
    $params['search'] = '%' . $search_query . '%';
}

if (!empty($role_filter)) {
    $query .= " AND u.role_id = :role";
    $params['role'] = $role_filter;
}

if (!empty($dept_filter)) {
    $query .= " AND u.department_id = :dept";
    $params['dept'] = $dept_filter;
}

$query .= " ORDER BY u.name ASC";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Define columns for export
$columns = [
    'name' => 'Name',
    'email' => 'Email',
    'role_name' => 'Role',
    'dept_name' => 'Department',
    'branch_name' => 'Branch',
    'created_at' => 'Created Date'
];

// Generate filename
$filename = 'Users_Export_' . date('Y-m-d_His');
$title = 'User Management Export';

// Export based on format
switch ($format) {
    case 'pdf':
        ExportManager::exportToPDF($users, $columns, $title, $filename, [
            'orientation' => 'L',
            'pageSize' => 'A4',
            'fontSize' => 9
        ]);
        break;
        
    case 'excel':
    case 'xlsx':
        ExportManager::exportToExcel($users, $columns, $title, $filename);
        break;
        
    case 'csv':
        ExportManager::exportToCSV($users, $columns, $filename);
        break;
        
    default:
        $_SESSION['error'] = 'Invalid export format';
        redirect('modules/hr/users/list');
}
