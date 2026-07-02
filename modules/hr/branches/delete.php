<?php
require_once __DIR__ . '/../../../config/app.php';
checkAuth();
require_once __DIR__ . '/../../../config/database.php';

if (!hasPermission('manage_branches')) {
    die("Access Denied.");
}

$id = $_GET['id'] ?? 0;

if ($id) {
    try {
        $stmt = $pdo->prepare("DELETE FROM branches WHERE id = ?");
        $stmt->execute([$id]);
        redirect('modules/hr/branches/list?success=deleted');
    } catch (PDOException $e) {
        redirect('modules/hr/branches/list?error=cannot_delete_branch_in_use');
    }
} else {
    redirect('modules/hr/branches/list');
}
