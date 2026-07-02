<?php
require_once __DIR__ . '/../../../config/app.php';
checkAuth();
require_once __DIR__ . '/../../../config/database.php';

if (!hasPermission('manage_departments')) {
    die("Access Denied.");
}

$id = $_GET['id'] ?? 0;

if ($id) {
    try {
        $stmt = $pdo->prepare("DELETE FROM departments WHERE id = ?");
        $stmt->execute([$id]);
        redirect('modules/hr/departments/list?success=deleted');
    } catch (PDOException $e) {
        // Handle FK constraint errors
        redirect('modules/hr/departments/list?error=cannot_delete_section_in_use');
    }
} else {
    redirect('modules/hr/departments/list');
}
