<?php
require_once __DIR__ . '/../../config/app.php';
checkAuth();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/permissions_helper.php';
permissions_require_one_of(['manage_sales', 'manage_invoices']);
require_once __DIR__ . '/../../libs/InvoicePaymentGrMigrator.php';

InvoicePaymentGrMigrator::ensure($pdo);


if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: index.php");
    exit;
}

try {
    $pdo->beginTransaction();

    $mode = $_POST['mode'] ?? 'invoice';
    $payment_amount = floatval($_POST['amount'] ?? 0);
    $payment_method = $_POST['payment_method'] ?? 'Cash';
    $transaction_id = trim((string) ($_POST['transaction_id'] ?? '')) ?: null;
    $gr_number = trim((string) ($_POST['gr_number'] ?? ''));
    $invoice_item_id = !empty($_POST['invoice_item_id']) ? (int) $_POST['invoice_item_id'] : null;
    $recorded_by = $_SESSION['user_id'];
    $payment_date = date('Y-m-d');

    $invoice_id = null;

    $recordPayment = static function (
        PDO $pdo,
        int $invoiceId,
        float $amount,
        string $date,
        string $method,
        ?string $txId,
        string $grNumber,
        ?int $itemId,
        int $userId
    ): void {
        if ($amount <= 0) {
            return;
        }

        $grNumber = InvoicePaymentGrMigrator::validateGrNumber($pdo, $grNumber);

        if ($itemId !== null && $itemId > 0) {
            $itemCheck = $pdo->prepare('SELECT id FROM invoice_items WHERE id = ? AND invoice_id = ?');
            $itemCheck->execute([$itemId, $invoiceId]);
            if (!$itemCheck->fetch()) {
                throw new InvalidArgumentException('Selected product/service does not belong to this invoice.');
            }
        } else {
            $itemId = null;
        }

        $stmt = $pdo->prepare(
            'INSERT INTO invoice_payments
                (invoice_id, amount, payment_date, payment_method, transaction_id, gr_number, invoice_item_id, recorded_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$invoiceId, $amount, $date, $method, $txId, $grNumber, $itemId, $userId]);
    };

    if ($mode === 'invoice') {
        // --- PAY EXISTING INVOICE ---
        $invoice_id = $_POST['invoice_id'] ?? null;
        if (!$invoice_id)
            throw new Exception("Invoice ID is required.");

        // Fetch current balance
        $stmt = $pdo->prepare("SELECT balance, total_amount, paid_amount FROM invoices WHERE id = ?");
        $stmt->execute([$invoice_id]);
        $invoice = $stmt->fetch();

        if (!$invoice)
            throw new Exception("Invoice not found.");

        if ($payment_amount <= 0) {
            throw new InvalidArgumentException('Payment amount must be greater than zero.');
        }

        $recordPayment(
            $pdo,
            (int) $invoice_id,
            $payment_amount,
            $payment_date,
            $payment_method,
            $transaction_id,
            $gr_number,
            $invoice_item_id,
            (int) $recorded_by
        );

        // Update Invoice Totals and Status
        $new_paid_amount = $invoice['paid_amount'] + $payment_amount;
        $new_balance = $invoice['total_amount'] - $new_paid_amount;

        $new_status = 'Paid';
        if ($new_balance > 0) {
            $new_status = 'Partially Paid';
        } elseif ($new_balance < 0) {
            // Overpayment handling? Just keep as Paid for now.
            $new_status = 'Paid';
        }

        $stmt = $pdo->prepare("UPDATE invoices SET paid_amount = ?, balance = ?, status = ? WHERE id = ?");
        $stmt->execute([$new_paid_amount, $new_balance, $new_status, $invoice_id]);

    } else {
        // --- DIRECT SALE ---
        $customer_name = $_POST['customer_name'] ?? 'Walk-in Customer';
        $customer_email = $_POST['customer_email'] ?? null;
        $customer_phone = $_POST['customer_phone'] ?? null;
        $total_amount = floatval($_POST['direct_total_amount'] ?? 0);
        $tax_rate = floatval($_POST['tax_rate'] ?? 0);
        $discount = floatval($_POST['discount'] ?? 0);

        // Generate Invoice Number
        $invoice_number = "INV-" . date('Ymd') . "-" . strtoupper(substr(uniqid(), -4));
        if (isset($_POST['generate_invoice_number']) && $_POST['generate_invoice_number'] == '1') {
            // Use auto-generated one
        } else {
            // Placeholder for manual if added later
        }

        // Subtotal calculation (redundant check but good for safety)
        $subtotal = 0;
        $item_types = $_POST['item_type'] ?? [];
        $item_ids = $_POST['item_id'] ?? [];
        $item_descs = $_POST['item_desc'] ?? [];
        $quantities = $_POST['quantity'] ?? [];
        $unit_prices = $_POST['unit_price'] ?? [];

        // Create Invoice Record
        $stmt = $pdo->prepare("INSERT INTO invoices (invoice_number, customer_name, customer_email, customer_phone, generated_date, due_date, status, subtotal, tax_amount, discount, total_amount, paid_amount, balance) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

        $initial_paid = $payment_amount;
        $initial_balance = $total_amount - $initial_paid;
        $initial_status = ($initial_balance <= 0) ? 'Paid' : ($initial_paid > 0 ? 'Partially Paid' : 'Unpaid');

        // Calculate tax amount from rate
        // Recalculate subtotal for verification
        for ($i = 0; $i < count($item_types); $i++) {
            $subtotal += floatval($quantities[$i]) * floatval($unit_prices[$i]);
        }
        $tax_amount = $subtotal * ($tax_rate / 100);

        $stmt->execute([
            $invoice_number,
            $customer_name,
            $customer_email,
            $customer_phone,
            $payment_date,
            $payment_date,
            $initial_status,
            $subtotal,
            $tax_amount,
            $discount,
            $total_amount,
            $initial_paid,
            $initial_balance
        ]);
        $invoice_id = $pdo->lastInsertId();

        // Create Invoice Items
        $itemStmt = $pdo->prepare("INSERT INTO invoice_items (invoice_id, item_type, item_id, description, quantity, unit_price, total_price) VALUES (?, ?, ?, ?, ?, ?, ?)");
        for ($i = 0; $i < count($item_types); $i++) {
            $row_total = floatval($quantities[$i]) * floatval($unit_prices[$i]);
            $item_id = !empty($item_ids[$i]) ? $item_ids[$i] : null;
            $itemStmt->execute([
                $invoice_id,
                $item_types[$i],
                $item_id,
                $item_descs[$i],
                $quantities[$i],
                $unit_prices[$i],
                $row_total
            ]);
        }

        if ($payment_amount > 0) {
            $recordPayment(
                $pdo,
                (int) $invoice_id,
                $payment_amount,
                $payment_date,
                $payment_method,
                $transaction_id,
                $gr_number,
                null,
                (int) $recorded_by
            );
        }
    }

    $pdo->commit();

    if ($mode === 'invoice') {
        header("Location: view_invoice.php?id={$invoice_id}&msg=Payment+recorded+successfully");
    } else {
        header("Location: view_invoice.php?id={$invoice_id}&msg=Sale+created+successfully");
    }
    exit;

} catch (Exception $e) {
    if ($pdo->inTransaction())
        $pdo->rollBack();
    die("Error processing sale: " . $e->getMessage());
}
?>