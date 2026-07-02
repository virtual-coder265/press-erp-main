<?php
/**
 * Invoice Save Endpoint
 *
 * Persists a new invoice using the schema columns the rest of the module
 * relies on (subtotal, tax_amount, vat_percent, last_edited_*, etc.). Items
 * are normalised before being JSON-encoded so the existing PDF / view
 * templates see consistent keys (`description`, `quantity`, `unit_price`,
 * `total_price`).
 */

require_once __DIR__ . '/../../config/app.php';
checkAuth();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../libs/InvoiceAuditMigrator.php';

InvoiceAuditMigrator::ensure($pdo);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('modules/invoices/list');
}

try {
    $pdo->beginTransaction();

    $est_id     = !empty($_POST['estimation_id']) ? (int) $_POST['estimation_id'] : null;
    $inv_number = 'INV-' . date('Ymd') . '-' . mt_rand(100, 999);

    $rawItems    = $_POST['items'] ?? [];
    $shipping    = (float) ($_POST['shipping_fee'] ?? 0);
    $vatPercent  = (float) ($_POST['vat_percent']  ?? 0);

    // Normalise items so downstream consumers see a consistent shape.
    $items = [];
    $subtotal = 0.0;
    foreach ($rawItems as $row) {
        $desc  = trim((string) ($row['description'] ?? ''));
        $price = (float) ($row['price'] ?? 0);
        if ($desc === '' && $price <= 0) {
            continue;
        }
        $items[] = [
            'description' => $desc,
            'quantity'    => 1,
            'unit_price'  => $price,
            'total_price' => $price,
        ];
        $subtotal += $price;
    }

    if (empty($items)) {
        throw new RuntimeException('At least one line item is required.');
    }

    $vatAmount  = round($subtotal * ($vatPercent / 100), 2);
    $grandTotal = round($subtotal + $vatAmount + $shipping, 2);
    // If the client sent a total, prefer it (server still recomputes for
    // safety; mismatches are silently overridden by the server-calculated
    // value to prevent client tampering).
    $items_json = json_encode($items);

    $stmt = $pdo->prepare("
        INSERT INTO invoices
            (invoice_number, estimation_id,
             customer_name, customer_email, customer_phone,
             generated_date, due_date,
             subtotal, vat_percent, tax_amount, shipping_fee,
             total_amount, paid_amount, balance,
             status, items_json,
             created_by, last_edited_at, last_edited_by)
        VALUES
            (:num, :eid,
             :name, :email, :phone,
             CURDATE(), DATE_ADD(CURDATE(), INTERVAL 30 DAY),
             :subtotal, :vat_pct, :vat_amt, :ship,
             :total, 0, :balance,
             'Unpaid', :json,
             :user, NOW(), :editor)
    ");

    $stmt->execute([
        'num'      => $inv_number,
        'eid'      => $est_id,
        'name'     => $_POST['customer_name']  ?? 'Unknown',
        'email'    => $_POST['customer_email'] ?? '',
        'phone'    => $_POST['customer_phone'] ?? '',
        'subtotal' => $subtotal,
        'vat_pct'  => $vatPercent,
        'vat_amt'  => $vatAmount,
        'ship'     => $shipping,
        'total'    => $grandTotal,
        'balance'  => $grandTotal,
        'json'     => $items_json,
        'user'     => $_SESSION['user_id'] ?? null,
        'editor'   => $_SESSION['user_id'] ?? null,
    ]);

    // Update Estimation Status if linked (record in history when the
    // estimation_status_history table is present so it shows on the audit
    // trail).
    if ($est_id) {
        $statusStmt = $pdo->prepare("SELECT status FROM estimations WHERE id = ?");
        $statusStmt->execute([$est_id]);
        $oldStatus = $statusStmt->fetchColumn();

        $pdo->prepare("UPDATE estimations SET status = 'Invoiced', updated_at = NOW() WHERE id = ?")->execute([$est_id]);

        try {
            $hStmt = $pdo->prepare("
                INSERT INTO estimation_status_history
                    (estimation_id, old_status, new_status, changed_by, change_reason)
                VALUES
                    (:eid, :old, 'Invoiced', :user, :reason)
            ");
            $hStmt->execute([
                'eid'    => $est_id,
                'old'    => $oldStatus,
                'user'   => $_SESSION['user_id'] ?? null,
                'reason' => 'Invoice ' . $inv_number . ' generated.',
            ]);
        } catch (PDOException $ignored) {
            // History table not present in this install; safe to skip.
        }
    }

    $pdo->commit();
    $_SESSION['success'] = 'Invoice ' . $inv_number . ' created.';
    redirect('modules/invoices/list?success=created');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Invoice save failed: ' . $e->getMessage());
    $_SESSION['error'] = 'Could not save invoice: ' . $e->getMessage();
    redirect('modules/invoices/create' . ($est_id ? '?estimation_id=' . $est_id : ''));
}
