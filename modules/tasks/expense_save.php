<?php

require_once __DIR__ . '/../../config/app.php';
checkAuth();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/upload_helper.php';
require_once __DIR__ . '/../../includes/task_management_helper.php';
require_once __DIR__ . '/../../includes/project_pm_helper.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('modules/tasks/list');
}

$taskId = (int) ($_POST['task_id'] ?? 0);
$userId = (int) ($_SESSION['user_id'] ?? 0);
$redirect = trim((string) ($_POST['redirect_to'] ?? ''));
if ($redirect === '') {
    $redirect = 'modules/tasks/view?id=' . $taskId;
}

$ctx = fetch_task_access_context($pdo, $taskId, $userId);
if (!$ctx || empty($ctx['can_manage'])) {
    redirect($redirect . (strpos($redirect, '?') !== false ? '&' : '?') . 'error=expense_denied');
}

if (!project_budget_enabled($ctx)) {
    redirect($redirect . (strpos($redirect, '?') !== false ? '&' : '?') . 'error=budget_disabled');
}

$amountRaw = trim((string) ($_POST['amount'] ?? ''));
if ($amountRaw === '' || !is_numeric($amountRaw)) {
    redirect($redirect . (strpos($redirect, '?') !== false ? '&' : '?') . 'error=expense_amount');
}
$amount = round((float) $amountRaw, 2);
$currency = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', (string) ($_POST['currency'] ?? ($ctx['budget_currency'] ?? 'USD'))), 0, 3));
if ($currency === '') {
    $currency = 'USD';
}
$description = trim((string) ($_POST['description'] ?? ''));

$projectId = (int) ($ctx['project_id'] ?? 0);
$receiptPath = null;
if (!empty($_FILES['receipt']['name']) && (int) ($_FILES['receipt']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK && $projectId > 0) {
    try {
        $paths = ensure_project_storage_directory($projectId);
        $receiptPath = store_validated_uploaded_file(
            $_FILES['receipt'],
            'task_document',
            $paths['fs_receipts'] . DIRECTORY_SEPARATOR,
            $paths['web_receipts_prefix'],
            'receipt-'
        );
        log_project_activity($pdo, $projectId, $userId, 'file.uploaded', 'task_expense_receipt', $taskId, ['path' => $receiptPath]);
    } catch (Throwable $e) {
        error_log('expense receipt: ' . $e->getMessage());
    }
}

$stmt = $pdo->prepare(
    'INSERT INTO task_expenses (task_id, amount, currency, description, receipt_file_path, created_by) VALUES (?,?,?,?,?,?)'
);
$stmt->execute([$taskId, $amount, $currency, $description !== '' ? $description : null, $receiptPath, $userId]);

log_project_activity($pdo, $projectId, $userId, 'task_expense.created', 'task_expense', (int) $pdo->lastInsertId(), [
    'task_id' => $taskId,
    'amount' => $amount,
    'currency' => $currency,
]);

redirect($redirect . (strpos($redirect, '?') !== false ? '&' : '?') . 'success=expense_saved');
