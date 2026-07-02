<?php
require_once __DIR__ . '/../../config/app.php';
checkAuth();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../libs/ExportManager.php';
require_once __DIR__ . '/../../includes/project_visibility_helper.php';

// Get export format and filters
$format = $_GET['format'] ?? 'pdf';
$search_query = $_GET['search'] ?? '';
$status_filter = $_GET['status'] ?? '';
$priority_filter = $_GET['priority'] ?? '';
$viewerIdExport = (int) ($_SESSION['user_id'] ?? 0);

// Build query with same filters as list page
$query = "SELECT p.*, u.name as created_by_name,
          (SELECT COUNT(*) FROM tasks t WHERE t.project_id = p.id) as task_count,
          (SELECT COUNT(*) FROM tasks t WHERE t.project_id = p.id AND t.status = 'Completed') as completed_tasks
          FROM projects p 
          LEFT JOIN users u ON p.created_by = u.id 
          WHERE 1=1";

$params = [];

if (!empty($search_query)) {
    $query .= " AND (p.name LIKE :search OR p.description LIKE :search)";
    $params['search'] = '%' . $search_query . '%';
}

if (!empty($status_filter)) {
    $query .= " AND p.status = :status";
    $params['status'] = $status_filter;
}

if (!empty($priority_filter)) {
    $query .= " AND p.priority = :priority";
    $params['priority'] = $priority_filter;
}

$visFilterEx = project_visibility_sql_where_for_projects('p', $viewerIdExport, $pdo);
if ($visFilterEx['clause'] !== '') {
    $query .= ' ' . $visFilterEx['clause'];
    foreach ($visFilterEx['binds'] as $k => $v) {
        $params[$k] = $v;
    }
}

$query .= " ORDER BY p.created_at DESC";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$projects = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate progress for each project
foreach ($projects as &$project) {
    $project['progress'] = $project['task_count'] > 0 
        ? round(($project['completed_tasks'] / $project['task_count']) * 100) . '%'
        : '0%';

    $requirements = [];
    if (!empty($project['require_document_submission'])) {
        $requirements[] = 'Document Submission';
    }
    if (!empty($project['require_procedure_tracking'])) {
        $requirements[] = 'Procedure Tracking';
    }
    $project['requirements'] = !empty($requirements) ? implode(', ', $requirements) : 'None';
}

// Define columns for export
$columns = [
    'name' => 'Project Name',
    'status' => 'Status',
    'priority' => 'Priority',
    'requirements' => 'Requirements',
    'start_date' => 'Start Date',
    'end_date' => 'End Date',
    'task_count' => 'Total Tasks',
    'completed_tasks' => 'Completed Tasks',
    'progress' => 'Progress',
    'created_by_name' => 'Created By',
    'created_at' => 'Created Date'
];

// Generate filename
$filename = 'Projects_Export_' . date('Y-m-d_His');
$title = 'Projects List';

// Export based on format
switch ($format) {
    case 'pdf':
        ExportManager::exportToPDF($projects, $columns, $title, $filename, [
            'orientation' => 'L',
            'pageSize' => 'A4',
            'fontSize' => 8
        ]);
        break;
        
    case 'excel':
    case 'xlsx':
        ExportManager::exportToExcel($projects, $columns, $title, $filename);
        break;
        
    case 'csv':
        ExportManager::exportToCSV($projects, $columns, $filename);
        break;
        
    default:
        $_SESSION['error'] = 'Invalid export format';
        redirect('modules/projects/list');
}
