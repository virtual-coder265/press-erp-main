<?php
require_once __DIR__ . '/../../config/app.php';
checkAuthApi();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/permissions_helper.php';
permissions_require_one_of(['manage_estimations']);

header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method not allowed']);
        exit;
    }

    $est_id = isset($_POST['est_id']) ? (int)$_POST['est_id'] : null;

    if (!$est_id) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Estimation ID required']);
        exit;
    }

    // Verify ownership and status
    $stmt = $pdo->prepare("SELECT id, status FROM estimations WHERE id = :id AND created_by = :user");
    $stmt->execute(['id' => $est_id, 'user' => $_SESSION['user_id']]);
    $est = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$est) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Estimation not found or unauthorized']);
        exit;
    }

    // Only allow discarding drafts
    if ($est['status'] !== 'Draft') {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Only draft estimations can be discarded']);
        exit;
    }

    // Delete the draft estimation and all related items
    $pdo->beginTransaction();
    
    // Delete items first (foreign key constraint)
    $stmt = $pdo->prepare("DELETE FROM estimation_items WHERE estimation_id = :id");
    $stmt->execute(['id' => $est_id]);

    // Delete the estimation
    $stmt = $pdo->prepare("DELETE FROM estimations WHERE id = :id");
    $stmt->execute(['id' => $est_id]);

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'message' => 'Draft discarded successfully'
    ]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error discarding draft: ' . $e->getMessage()
    ]);
}
?>
