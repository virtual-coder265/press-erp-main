<?php
require_once __DIR__ . '/../../config/app.php';
checkAuth();
require_once __DIR__ . '/../../config/database.php';

$id = $_GET['id'] ?? 0;

if ($id) {
    try {
        $stmt = $pdo->prepare("DELETE FROM dispatch_register WHERE id = ?");
        $stmt->execute([$id]);
        redirect('modules/dispatch/list?success=entry_deleted');
    } catch (Exception $e) {
        redirect('modules/dispatch/list?error=' . urlencode($e->getMessage()));
    }
} else {
    redirect('modules/dispatch/list?error=invalid_id');
}
?>


