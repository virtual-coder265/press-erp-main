<?php
require_once __DIR__ . '/../../config/app.php';
checkAuth();
require_once __DIR__ . '/../../config/database.php';

header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method not allowed']);
        exit;
    }

    $pdo->beginTransaction();

    // Get the estimation ID if editing, or create a new draft
    $est_id = isset($_POST['est_id']) ? (int)$_POST['est_id'] : null;
    $current_step = isset($_POST['current_step']) ? (int)$_POST['current_step'] : 1;

    // Prepare the form data as JSON, excluding submission fields
    $form_data = $_POST;
    unset($form_data['est_id']);
    unset($form_data['current_step']);
    unset($form_data['save_draft']);
    unset($form_data['action']);

    $draft_json = json_encode($form_data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    if (!$est_id) {
        // Create a new draft estimation
        $est_number = 'DRAFT-' . date('YmdHis') . '-' . mt_rand(1000, 9999);
        
        $stmt = $pdo->prepare("
            INSERT INTO estimations 
            (estimation_number, customer_name, customer_email, customer_phone, job_description, 
             status, created_by, draft_data, draft_step, last_auto_saved, total_amount)
            VALUES 
            (:num, :name, :email, :phone, :job, 'Draft', :user, :draft, :step, NOW(), 0)
        ");
        
        $customer_name = $_POST['customer_name'] ?? 'Unnamed Customer';
        $stmt->execute([
            'num' => $est_number,
            'name' => $customer_name,
            'email' => $_POST['customer_email'] ?? '',
            'phone' => $_POST['customer_phone'] ?? '',
            'job' => $_POST['job_title'] ?? '',
            'user' => $_SESSION['user_id'],
            'draft' => $draft_json,
            'step' => $current_step,
        ]);
        
        $est_id = $pdo->lastInsertId();
    } else {
        // Update existing draft
        $stmt = $pdo->prepare("
            UPDATE estimations 
            SET draft_data = :draft, 
                draft_step = :step, 
                last_auto_saved = NOW(),
                customer_name = :name,
                customer_email = :email,
                customer_phone = :phone,
                job_description = :job
            WHERE id = :id AND created_by = :user
        ");
        
        $stmt->execute([
            'id' => $est_id,
            'user' => $_SESSION['user_id'],
            'draft' => $draft_json,
            'step' => $current_step,
            'name' => $_POST['customer_name'] ?? 'Unnamed Customer',
            'email' => $_POST['customer_email'] ?? '',
            'phone' => $_POST['customer_phone'] ?? '',
            'job' => $_POST['job_title'] ?? '',
        ]);
    }

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'est_id' => $est_id,
        'message' => 'Draft saved successfully',
        'timestamp' => date('Y-m-d H:i:s')
    ]);

} catch (Exception $e) {
    $pdo->rollBack();
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error saving draft: ' . $e->getMessage()
    ]);
}
?>
