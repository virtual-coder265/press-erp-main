<?php
require_once __DIR__ . '/../../../config/app.php';
checkAuth();
require_once __DIR__ . '/../../../config/database.php';

checkPermission('manage_roles');

$id = $_GET['id'] ?? 0;

if ($id) {
    try {
        $stmt = $pdo->prepare("DELETE FROM roles WHERE id = ?");
        $stmt->execute([$id]);
        redirect('modules/hr/roles/list?success=deleted');
    } catch (PDOException $e) {
        redirect('modules/hr/roles/list?error=cannot_delete_role_in_use');
    }
} else {
    redirect('modules/hr/roles/list');
}
