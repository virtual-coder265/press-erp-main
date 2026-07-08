<?php
require_once __DIR__ . '/../../config/app.php';
checkAuth();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/permissions_helper.php';
require_once __DIR__ . '/../../includes/work_order_helper.php';

permissions_require_one_of(['manage_work_orders', 'manage_invoices']);

$invoiceId = (int) ($_GET['invoice_id'] ?? $_POST['invoice_id'] ?? 0);
if ($invoiceId <= 0) {
    $_SESSION['error'] = 'Invalid invoice reference.';
    redirect('modules/invoices/list?error=invalid');
}

redirect('modules/work_orders/create?invoice_id=' . $invoiceId);
