<?php
/**
 * Project Documentation PDF Export Handler
 * Fetches all project documentation evidence and generates a PDF report.
 */

require_once __DIR__ . '/../../config/app.php';
checkAuth();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/pdf_helper.php';
require_once __DIR__ . '/../../includes/project_visibility_helper.php';

$project_id = (int) ($_GET['id'] ?? 0);
$viewerDoc = (int) ($_SESSION['user_id'] ?? 0);

if (!$project_id) {
    die("Error: Project ID is required.");
}

// 1. Fetch Project Data
$stmt = $pdo->prepare("SELECT p.*, u.name as creator_name 
                      FROM projects p 
                      LEFT JOIN users u ON p.created_by = u.id 
                      WHERE p.id = ?");
$stmt->execute([$project_id]);
$project = $stmt->fetch();

if (!$project) {
    die("Error: Project not found.");
}

if (!project_user_can_view_project($pdo, $viewerDoc, $project)) {
    die('Error: You do not have access to export this project.');
}

// 2. Fetch Tasks with Assignee info
$taskStmt = $pdo->prepare("SELECT t.*, COALESCE(u.name, 'Unassigned') as assigned_to_name 
                          FROM tasks t 
                          LEFT JOIN users u ON t.assigned_to = u.id 
                          WHERE t.project_id = ? 
                          ORDER BY t.created_at ASC");
$taskStmt->execute([$project_id]);
$tasks = $taskStmt->fetchAll();

// 3. Fetch Documentation Entries (Evidence Logs)
$docStmt = $pdo->prepare("SELECT td.*, COALESCE(u.name, 'System/Deleted') as uploader_name 
                         FROM task_documentation td 
                         JOIN tasks t ON td.task_id = t.id 
                         LEFT JOIN users u ON td.user_id = u.id 
                         WHERE t.project_id = ? 
                         ORDER BY td.created_at DESC");
$docStmt->execute([$project_id]);
$documentation = $docStmt->fetchAll();

// 4. Generate the PDF
generateProjectDocumentationPdf($project, $tasks, $documentation);
exit;
