<?php
require_once __DIR__ . '/../../config/app.php';
checkAuth();
require_once __DIR__ . '/../../config/database.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $work_order_number = !empty($_POST['work_order_number']) ? $_POST['work_order_number'] : null;
    $date_in = $_POST['date_in'] ?? '';
    $ministry_department = $_POST['ministry_department'] ?? '';
    $job_description = $_POST['job_description'] ?? '';
    $remarks = trim($_POST['remarks'] ?? '');
    $quantity = $_POST['quantity'] ?? 0;
    $date_out = !empty($_POST['date_out']) ? $_POST['date_out'] : null;
    $delivery_note_number = !empty($_POST['delivery_note_number']) ? $_POST['delivery_note_number'] : null;
    $authorised_dispatcher_id = !empty($_POST['authorised_dispatcher_id']) ? $_POST['authorised_dispatcher_id'] : null;
    $user_id = $_SESSION['user_id'];

    if (empty($date_in) || empty($ministry_department)) {
        redirect('modules/dispatch/list?error=required_fields_missing');
    }

    try {
        if ($action === 'create') {
            $stmt = $pdo->prepare("INSERT INTO dispatch_register (work_order_number, date_in, ministry_department, job_description, remarks, quantity, date_out, delivery_note_number, authorised_dispatcher_id, created_by) 
                                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$work_order_number, $date_in, $ministry_department, $job_description, $remarks ?: null, $quantity, $date_out, $delivery_note_number, $authorised_dispatcher_id, $user_id]);
            $dispatch_id = $pdo->lastInsertId();
            redirect('modules/dispatch/view?id=' . $dispatch_id . '&success=entry_created');
        } elseif ($action === 'update') {
            $id = $_POST['id'] ?? 0;
            $stmt = $pdo->prepare("UPDATE dispatch_register SET work_order_number = ?, date_in = ?, ministry_department = ?, job_description = ?, remarks = ?, quantity = ?, date_out = ?, delivery_note_number = ?, authorised_dispatcher_id = ? 
                                  WHERE id = ?");
            $stmt->execute([$work_order_number, $date_in, $ministry_department, $job_description, $remarks ?: null, $quantity, $date_out, $delivery_note_number, $authorised_dispatcher_id, $id]);
            redirect('modules/dispatch/view?id=' . $id . '&success=entry_updated');
        }
    } catch (Exception $e) {
        redirect('modules/dispatch/list?error=' . urlencode($e->getMessage()));
    }
} else {
    redirect('modules/dispatch/list');
}
?>

