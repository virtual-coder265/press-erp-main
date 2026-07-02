<?php
require_once __DIR__ . '/../../config/app.php';
checkAuth();
require_once __DIR__ . '/../../config/database.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $ids = $_POST['ids'] ?? [];
    
    if (!empty($ids) && is_array($ids)) {
        try {
            // Create placeholders for the IN clause
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            
            $stmt = $pdo->prepare("DELETE FROM dispatch_register WHERE id IN ($placeholders)");
            $stmt->execute($ids);
            
            $count = $stmt->rowCount();
            redirect('modules/dispatch/list?success=' . urlencode("$count entries deleted successfully"));
        } catch (Exception $e) {
            redirect('modules/dispatch/list?error=' . urlencode($e->getMessage()));
        }
    } else {
        redirect('modules/dispatch/list?error=' . urlencode('No items selected for deletion'));
    }
} else {
    redirect('modules/dispatch/list');
}
