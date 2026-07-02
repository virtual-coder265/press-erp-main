<?php
/**
 * EXPORT TEMPLATE
 * Copy this file to your module and customize as needed
 * 
 * Filename: export.php
 * Location: modules/YOUR_MODULE/export.php
 */

require_once __DIR__ . '/../../config/app.php';
checkAuth();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../libs/ExportManager.php';

// ============================================
// CUSTOMIZE THIS SECTION
// ============================================

// 1. Add any permission checks if needed
// Example:
// if ($_SESSION['role'] != 'System Admin') {
//     die("Access Denied.");
// }

// 2. Get filter parameters from GET request
$format = $_GET['format'] ?? 'pdf';
// Add your filter variables here
// Example:
// $search_query = $_GET['search'] ?? '';
// $date_from = $_GET['date_from'] ?? '';
// $date_to = $_GET['date_to'] ?? '';

// 3. Build your database query
$query = "SELECT * FROM your_table WHERE 1=1";
$params = [];

// Add your filters to the query
// Example:
// if (!empty($search_query)) {
//     $query .= " AND column_name LIKE :search";
//     $params['search'] = '%' . $search_query . '%';
// }

$query .= " ORDER BY created_at DESC"; // Add your ordering

// 4. Execute query and fetch data
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$data = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 5. Define columns for export
// Format: 'database_column_key' => 'Display Label'
$columns = [
    'id' => 'ID',
    'name' => 'Name',
    'created_at' => 'Created Date',
    // Add more columns as needed
];

// 6. Set export metadata
$filename = 'Export_' . date('Y-m-d_His'); // Customize filename
$title = 'Your Module Export'; // Customize title

// ============================================
// DO NOT MODIFY BELOW THIS LINE
// ============================================

// Export based on format
switch ($format) {
    case 'pdf':
        ExportManager::exportToPDF($data, $columns, $title, $filename, [
            'orientation' => 'L', // L=Landscape, P=Portrait
            'pageSize' => 'A4',
            'fontSize' => 9
        ]);
        break;
        
    case 'excel':
    case 'xlsx':
        ExportManager::exportToExcel($data, $columns, $title, $filename);
        break;
        
    case 'csv':
        ExportManager::exportToCSV($data, $columns, $filename);
        break;
        
    default:
        $_SESSION['error'] = 'Invalid export format';
        redirect('modules/YOUR_MODULE/list'); // Update redirect path
}
