<?php
require_once __DIR__ . '/../../config/app.php';
checkAuth();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/work_order_helper.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('modules/work_orders/list');
}

if (!verify_csrf_token($_POST['csrf_token'] ?? '', 'work_order_costing')) {
    $_SESSION['error'] = 'Security check failed. Please try again.';
    redirect('modules/work_orders/list?error=csrf');
}

if (!hasPermission('manage_work_orders') && !hasPermission('manage_invoices')) {
    http_response_code(403);
    die('Access Denied.');
}

$invoiceId = (int) ($_POST['invoice_id'] ?? 0);
$workOrderId = (int) ($_POST['work_order_id'] ?? 0);
$userId = (int) ($_SESSION['user_id'] ?? 0);

try {
    if ($workOrderId > 0) {
        if (!hasPermission('manage_work_orders')) {
            throw new RuntimeException('You do not have permission to edit work orders.');
        }
        work_order_update_traveler($pdo, $workOrderId, $userId, $_POST);
        $_SESSION['success'] = 'Work order traveler updated successfully.';
        redirect('modules/work_orders/view?id=' . $workOrderId);
    }

    if ($invoiceId <= 0) {
        throw new RuntimeException('Please select an invoice to link this work order to.');
    }

    $costingFields = work_order_parse_costing_fields($_POST);
    $productionForm = work_order_build_production_form($_POST);

    if (!empty($costingFields['binding_type_id'])) {
        $bindingStmt = $pdo->prepare("SELECT name FROM work_order_binding_types WHERE id = ? LIMIT 1");
        $bindingStmt->execute([(int) $costingFields['binding_type_id']]);
        $bindingName = $bindingStmt->fetchColumn();
        if ($bindingName) {
            $costingFields['binding_type_name'] = (string) $bindingName;
        }
    }

    $result = work_order_create_from_invoice($pdo, $invoiceId, $userId, [
        'costing_fields' => $costingFields,
        'production_form' => $productionForm,
        'remarks' => $costingFields['remarks'] ?? 'Issued from costing workflow.',
    ]);

    $_SESSION['success'] = 'Work order ' . $result['work_order_number'] . ' saved as draft. Send to Origination when ready.';
    redirect('modules/work_orders/view?id=' . (int) $result['id']);
} catch (Throwable $exception) {
    $_SESSION['error'] = $exception->getMessage();
    if ($workOrderId > 0) {
        redirect('modules/work_orders/edit?id=' . $workOrderId);
    }
    if ($invoiceId > 0) {
        redirect('modules/work_orders/create?invoice_id=' . $invoiceId);
    }
    redirect('modules/work_orders/create');
}
