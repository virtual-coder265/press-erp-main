<?php
/**
 * Invoice Delete Endpoint
 *
 * POST-only with CSRF protection. Blocks deletion when payments have been
 * recorded (those represent real money and must be reversed manually
 * first). When the invoice was generated from an estimation we revert the
 * estimation status so the source record is once again open for billing,
 * and append an entry to estimation_status_history if that table exists.
 *
 * Routing: form posts go to the extensionless URL `.../invoices/delete`
 * so .htaccess preserves the POST body.
 */

require_once __DIR__ . '/../../config/app.php';
checkAuth();
require_once __DIR__ . '/../../config/database.php';

if (function_exists('checkPermission')) {
    checkPermission('manage_invoices');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('modules/invoices/list');
}

$token = $_POST['csrf_token'] ?? '';
if (!verify_csrf_token($token, 'invoice_delete')) {
    $_SESSION['error'] = 'Security check failed. Please reload the page and try again.';
    redirect('modules/invoices/list?error=csrf');
}

$id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
if ($id <= 0) {
    $_SESSION['error'] = 'Invalid invoice reference.';
    redirect('modules/invoices/list?error=invalid');
}

try {
    // Block when payments are recorded — they represent real money and
    // need to be reversed manually before the invoice can be removed.
    $payStmt = $pdo->prepare("SELECT COUNT(*) FROM invoice_payments WHERE invoice_id = ?");
    $payStmt->execute([$id]);
    $payCount = (int) $payStmt->fetchColumn();

    if ($payCount > 0) {
        $_SESSION['error'] = 'This invoice has recorded payments and cannot be deleted. Reverse the payment(s) first.';
        redirect('modules/invoices/list?error=has_payments');
    }

    $info = $pdo->prepare("SELECT invoice_number, estimation_id FROM invoices WHERE id = ?");
    $info->execute([$id]);
    $row = $info->fetch();
    if (!$row) {
        $_SESSION['error'] = 'Invoice not found or already deleted.';
        redirect('modules/invoices/list?error=not_found');
    }

    $pdo->beginTransaction();

    $del = $pdo->prepare("DELETE FROM invoices WHERE id = ?");
    $del->execute([$id]);

    // If the invoice was created from an estimation, revert the estimation
    // status so it can be re-invoiced. We log the transition in the
    // history table when present so the audit trail stays clean.
    if (!empty($row['estimation_id'])) {
        $estId = (int) $row['estimation_id'];
        $newStatus = 'Approved';

        try {
            $cur = $pdo->prepare("SELECT status FROM estimations WHERE id = ?");
            $cur->execute([$estId]);
            $oldStatus = $cur->fetchColumn();

            $pdo->prepare("UPDATE estimations SET status = ?, updated_at = NOW() WHERE id = ?")
                ->execute([$newStatus, $estId]);

            try {
                $hStmt = $pdo->prepare("
                    INSERT INTO estimation_status_history
                        (estimation_id, old_status, new_status, changed_by, change_reason)
                    VALUES
                        (:eid, :old, :new, :user, :reason)
                ");
                $hStmt->execute([
                    'eid'    => $estId,
                    'old'    => $oldStatus,
                    'new'    => $newStatus,
                    'user'   => $_SESSION['user_id'] ?? null,
                    'reason' => 'Invoice ' . $row['invoice_number'] . ' deleted; estimation reopened.',
                ]);
            } catch (PDOException $ignored) {
                // History table missing — non-fatal.
            }
        } catch (PDOException $ignored) {
            // If reverting the estimation fails, we still want the invoice
            // deletion to succeed; surface a warning instead of rolling back.
            $_SESSION['error'] = 'Invoice deleted, but the linked estimation status could not be reverted automatically.';
        }
    }

    $pdo->commit();

    if (empty($_SESSION['error'])) {
        $_SESSION['success'] = sprintf('Invoice %s deleted.', $row['invoice_number']);
    }
    redirect('modules/invoices/list?success=deleted');
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Invoice delete failed: ' . $e->getMessage());
    $_SESSION['error'] = 'Could not delete invoice: ' . $e->getMessage();
    redirect('modules/invoices/list?error=db');
}
