<?php
require_once __DIR__ . '/../../config/app.php';
checkAuth();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/permissions_helper.php';
permissions_require_one_of(['manage_dispatch']);
require_once __DIR__ . '/../../includes/work_order_helper.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    work_order_bootstrap($pdo);
    $work_order_id = !empty($_POST['work_order_id']) ? (int) $_POST['work_order_id'] : null;
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

    if (empty($date_in) || (empty($ministry_department) && empty($_POST['work_order_id']))) {
        redirect('modules/dispatch/list?error=required_fields_missing');
    }

    try {
        $linkedWorkOrder = null;
        if ($work_order_id) {
            $woStmt = $pdo->prepare("SELECT * FROM work_orders WHERE id = ? LIMIT 1");
            $woStmt->execute([$work_order_id]);
            $linkedWorkOrder = $woStmt->fetch(PDO::FETCH_ASSOC);
            if (!$linkedWorkOrder) {
                throw new RuntimeException('Selected work order was not found.');
            }

            $work_order_number = $linkedWorkOrder['work_order_number'];
            if ($ministry_department === '') {
                $ministry_department = (string) ($linkedWorkOrder['ministry_department'] ?? '');
            }
            if ($job_description === '') {
                $job_description = (string) ($linkedWorkOrder['job_description'] ?? '');
            }
        }

        if ($action === 'create') {
            $stmt = $pdo->prepare("INSERT INTO dispatch_register (work_order_id, work_order_number, date_in, ministry_department, job_description, remarks, quantity, date_out, delivery_note_number, authorised_dispatcher_id, created_by, customer_name) 
                                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$work_order_id, $work_order_number, $date_in, $ministry_department, $job_description, $remarks ?: null, $quantity, $date_out, $delivery_note_number, $authorised_dispatcher_id, $user_id, $linkedWorkOrder['customer_name'] ?? null]);
            $dispatch_id = $pdo->lastInsertId();
            if ($linkedWorkOrder) {
                $pdo->prepare("
                    UPDATE work_orders
                    SET status = CASE WHEN ? IS NULL OR ? = '' THEN 'Awaiting Dispatch' ELSE 'Dispatched' END,
                        dispatched_at = CASE WHEN ? IS NULL OR ? = '' THEN dispatched_at ELSE COALESCE(dispatched_at, NOW()) END,
                        updated_by = ?
                    WHERE id = ?
                ")->execute([$date_out, $date_out, $date_out, $date_out, $user_id, $work_order_id]);

                $pdo->prepare("
                    INSERT INTO production_movements
                        (work_order_id, movement_type, sender_user_id, remarks)
                    VALUES (?, 'dispatch_recorded', ?, ?)
                ")->execute([$work_order_id, $user_id, 'Dispatch register entry created.']);
            }
            redirect('modules/dispatch/view?id=' . $dispatch_id . '&success=entry_created');
        } elseif ($action === 'update') {
            $id = $_POST['id'] ?? 0;
            $stmt = $pdo->prepare("UPDATE dispatch_register SET work_order_id = ?, work_order_number = ?, date_in = ?, ministry_department = ?, job_description = ?, remarks = ?, quantity = ?, date_out = ?, delivery_note_number = ?, authorised_dispatcher_id = ?, customer_name = ? 
                                  WHERE id = ?");
            $stmt->execute([$work_order_id, $work_order_number, $date_in, $ministry_department, $job_description, $remarks ?: null, $quantity, $date_out, $delivery_note_number, $authorised_dispatcher_id, $linkedWorkOrder['customer_name'] ?? null, $id]);
            if ($linkedWorkOrder) {
                $pdo->prepare("
                    UPDATE work_orders
                    SET status = CASE WHEN ? IS NULL OR ? = '' THEN 'Awaiting Dispatch' ELSE 'Dispatched' END,
                        dispatched_at = CASE WHEN ? IS NULL OR ? = '' THEN dispatched_at ELSE COALESCE(dispatched_at, NOW()) END,
                        updated_by = ?
                    WHERE id = ?
                ")->execute([$date_out, $date_out, $date_out, $date_out, $user_id, $work_order_id]);
            }
            redirect('modules/dispatch/view?id=' . $id . '&success=entry_updated');
        }
    } catch (Exception $e) {
        redirect('modules/dispatch/list?error=' . urlencode($e->getMessage()));
    }
} else {
    redirect('modules/dispatch/list');
}
?>

