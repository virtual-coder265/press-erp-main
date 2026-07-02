<?php
/**
 * Estimation Delete Endpoint
 *
 * POST-only endpoint that removes an estimation and all of its child rows
 * via the existing ON DELETE CASCADE foreign keys. Protected with the
 * standard CSRF token from config/app.php.
 *
 * Form posts must hit this URL extensionless (`.../estimations/delete`) so
 * the .htaccess rewrite forwards them as a true POST. Posting to
 * `delete.php` directly would be 301'd and the body would be lost.
 */

require_once __DIR__ . '/../../config/app.php';
checkAuth();
require_once __DIR__ . '/../../config/database.php';

if (function_exists('checkPermission')) {
    checkPermission('manage_estimations');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('modules/estimations/list');
}

$token = $_POST['csrf_token'] ?? '';
if (!verify_csrf_token($token, 'estimation_delete')) {
    $_SESSION['error'] = 'Security check failed. Please reload the page and try again.';
    redirect('modules/estimations/list?error=csrf');
}

$id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
if ($id <= 0) {
    $_SESSION['error'] = 'Invalid estimation reference.';
    redirect('modules/estimations/list?error=invalid');
}

try {
    // Block deletion when an invoice already references this estimation so
    // we never orphan a billing record. The user must remove the invoice
    // (or use a future "void" workflow) first.
    $invStmt = $pdo->prepare("SELECT COUNT(*) FROM invoices WHERE estimation_id = ?");
    $invStmt->execute([$id]);
    if ((int) $invStmt->fetchColumn() > 0) {
        $_SESSION['error'] = 'This estimation has linked invoices and cannot be deleted. Delete or void the related invoice(s) first.';
        redirect('modules/estimations/list?error=has_invoices');
    }

    $exists = $pdo->prepare("SELECT estimation_number FROM estimations WHERE id = ?");
    $exists->execute([$id]);
    $row = $exists->fetch();
    if (!$row) {
        $_SESSION['error'] = 'Estimation not found or already deleted.';
        redirect('modules/estimations/list?error=not_found');
    }

    $del = $pdo->prepare("DELETE FROM estimations WHERE id = ?");
    $del->execute([$id]);

    $_SESSION['success'] = sprintf('Estimation %s deleted.', $row['estimation_number']);
    redirect('modules/estimations/list?success=deleted');
} catch (PDOException $e) {
    error_log('Estimation delete failed: ' . $e->getMessage());
    $_SESSION['error'] = 'Could not delete estimation. ' . $e->getMessage();
    redirect('modules/estimations/list?error=db');
}
