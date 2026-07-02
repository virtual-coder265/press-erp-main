<?php
/**
 * Invoice Record Payment Endpoint
 *
 * Captures a payment against a single invoice, updates the running paid /
 * balance / status fields, and bounces back to the view page.
 *
 * GET  -> render a small form pre-filled with the outstanding balance.
 * POST -> validate + insert into invoice_payments and update the parent
 *         invoice. Protected with the invoice_payment CSRF token.
 *
 * The legacy `modules/sales/record_sale.php` flow is left untouched; this
 * is just a focused per-invoice action so the list / view CRUD pattern
 * has a fourth action ("Record Payment") that mirrors the estimations
 * "Convert to Invoice" affordance.
 */

require_once __DIR__ . '/../../config/app.php';
checkAuth();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../libs/InvoiceAuditMigrator.php';
require_once __DIR__ . '/../../libs/InvoicePaymentGrMigrator.php';

if (function_exists('checkPermission')) {
    checkPermission('manage_invoices');
}

InvoiceAuditMigrator::ensure($pdo);
InvoicePaymentGrMigrator::ensure($pdo);

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
if ($id <= 0) {
    redirect('modules/invoices/list');
}

$stmt = $pdo->prepare("SELECT * FROM invoices WHERE id = ?");
$stmt->execute([$id]);
$invoice = $stmt->fetch();
if (!$invoice) {
    http_response_code(404);
    die('Invoice not found.');
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!verify_csrf_token($token, 'invoice_payment')) {
        $errors[] = 'Security check failed. Please reload the page and try again.';
    }

    $amount         = (float) ($_POST['amount'] ?? 0);
    $payment_method = trim((string) ($_POST['payment_method'] ?? 'Cash'));
    $transaction_id = trim((string) ($_POST['transaction_id'] ?? ''));
    $gr_number      = trim((string) ($_POST['gr_number'] ?? ''));
    $invoice_item_id = !empty($_POST['invoice_item_id']) ? (int) $_POST['invoice_item_id'] : null;
    $payment_date   = trim((string) ($_POST['payment_date'] ?? date('Y-m-d')));

    if ($amount <= 0) {
        $errors[] = 'Payment amount must be greater than zero.';
    }
    if ($payment_method === '') {
        $errors[] = 'Payment method is required.';
    }
    if ($gr_number === '') {
        $errors[] = 'General Receipt (GR) number is required for every payment transaction.';
    }
    if ($payment_date === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $payment_date)) {
        $errors[] = 'Payment date must be in YYYY-MM-DD format.';
        $payment_date = date('Y-m-d');
    }

    if (empty($errors)) {
        try {
            $gr_number = InvoicePaymentGrMigrator::validateGrNumber($pdo, $gr_number);

            if ($invoice_item_id !== null && $invoice_item_id > 0) {
                $itemCheck = $pdo->prepare('SELECT id FROM invoice_items WHERE id = ? AND invoice_id = ?');
                $itemCheck->execute([$invoice_item_id, $id]);
                if (!$itemCheck->fetch()) {
                    $errors[] = 'Selected product/service does not belong to this invoice.';
                }
            } else {
                $invoice_item_id = null;
            }
        } catch (InvalidArgumentException $e) {
            $errors[] = $e->getMessage();
        }
    }

    if (empty($errors)) {
        try {
            $pdo->beginTransaction();

            $payStmt = $pdo->prepare("
                INSERT INTO invoice_payments
                    (invoice_id, amount, payment_date, payment_method, transaction_id, gr_number, invoice_item_id, recorded_by)
                VALUES
                    (:iid, :amount, :date, :method, :tx, :gr, :item, :user)
            ");
            $payStmt->execute([
                'iid'    => $id,
                'amount' => $amount,
                'date'   => $payment_date,
                'method' => $payment_method,
                'tx'     => $transaction_id ?: null,
                'gr'     => $gr_number,
                'item'   => $invoice_item_id,
                'user'   => $_SESSION['user_id'] ?? null,
            ]);

            $newPaid    = (float) $invoice['paid_amount'] + $amount;
            $newBalance = round((float) $invoice['total_amount'] - $newPaid, 2);
            $newStatus  = $newBalance <= 0 ? 'Paid' : 'Partially Paid';

            $upd = $pdo->prepare("
                UPDATE invoices
                SET paid_amount = :paid,
                    balance     = :balance,
                    status      = :status,
                    last_edited_at = NOW(),
                    last_edited_by = :editor
                WHERE id = :id
            ");
            $upd->execute([
                'paid'    => $newPaid,
                'balance' => $newBalance,
                'status'  => $newStatus,
                'editor'  => $_SESSION['user_id'] ?? null,
                'id'      => $id,
            ]);

            $pdo->commit();
            $_SESSION['success'] = sprintf('Payment of MK %s recorded.', number_format($amount, 2));
            redirect('modules/invoices/view?id=' . $id);
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Invoice payment failed: ' . $e->getMessage());
            $errors[] = 'Could not record payment: ' . $e->getMessage();
        }
    }
}

$form_payment_date = date('Y-m-d');
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($errors)) {
    $pd = trim((string) ($_POST['payment_date'] ?? ''));
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $pd)) {
        $form_payment_date = $pd;
    }
}

$balance = (float) ($invoice['balance'] ?? 0);

$payableItems = $pdo->prepare('SELECT id, item_type, description, total_price FROM invoice_items WHERE invoice_id = ? ORDER BY id ASC');
$payableItems->execute([$id]);
$invoiceLineItems = $payableItems->fetchAll();

include '../../includes/header.php';
?>

<div class="mb-6">
    <div class="flex items-center gap-2 mb-4">
        <a href="view?id=<?php echo (int) $invoice['id']; ?>" class="text-blue-600 hover:underline flex items-center">
            <i data-lucide="arrow-left" class="mr-1 inline-block h-4 w-4 flex-shrink-0" aria-hidden="true"></i>
            Back to invoice
        </a>
    </div>
    <h1 class="text-3xl font-bold text-gray-800">
        Record payment — <?php echo htmlspecialchars($invoice['invoice_number']); ?>
    </h1>
    <p class="text-gray-600 mt-1">
        Outstanding balance: <strong>MK <?php echo number_format($balance, 2); ?></strong>
        of MK <?php echo number_format((float) $invoice['total_amount'], 2); ?>.
    </p>
</div>

<?php if (!empty($errors)): ?>
    <div class="bg-red-50 border border-red-200 text-red-800 rounded-lg p-4 mb-6">
        <p class="font-semibold mb-1">Could not record the payment:</p>
        <ul class="list-disc list-inside text-sm">
            <?php foreach ($errors as $err): ?>
                <li><?php echo htmlspecialchars($err); ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<?php if ($balance <= 0): ?>
    <div class="bg-green-50 border border-green-200 text-green-800 rounded-lg p-4 mb-6">
        This invoice is fully settled. No further payments are required.
    </div>
<?php endif; ?>

<form method="POST" action="record_payment?id=<?php echo (int) $invoice['id']; ?>" class="bg-white shadow rounded-xl p-8 space-y-6 max-w-2xl">
    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token('invoice_payment')); ?>">
    <input type="hidden" name="id" value="<?php echo (int) $invoice['id']; ?>">

    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
        <div>
            <label class="block text-gray-700 font-semibold mb-2" for="gr_number">General Receipt (GR) number *</label>
            <input id="gr_number" name="gr_number" type="text" required maxlength="50"
                placeholder="e.g. 7855123"
                class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500 font-mono">
            <p class="text-xs text-gray-500 mt-1">Each payment must have a unique GR from the receipt book.</p>
        </div>
        <div>
            <label class="block text-gray-700 font-semibold mb-2" for="amount">Amount (MK) *</label>
            <input id="amount" name="amount" type="number" step="0.01" min="0.01" required
                value="<?php echo $balance > 0 ? number_format($balance, 2, '.', '') : ''; ?>"
                class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500 text-xl font-bold">
        </div>
        <div>
            <label class="block text-gray-700 font-semibold mb-2" for="payment_date">Payment date *</label>
            <?php echo press_datetime_picker_field([
                'name' => 'payment_date',
                'id' => 'payment_date',
                'value' => $form_payment_date,
                'mode' => 'date',
                'required' => true,
                'class' => 'w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500',
            ]); ?>
        </div>
        <div>
            <label class="block text-gray-700 font-semibold mb-2" for="payment_method">Payment method *</label>
            <select id="payment_method" name="payment_method" required
                class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500 bg-white">
                <option value="Cash">Cash</option>
                <option value="Bank Transfer">Bank Transfer</option>
                <option value="Cheque">Cheque</option>
                <option value="Mobile Money">Mobile Money</option>
            </select>
        </div>
        <div>
            <label class="block text-gray-700 font-semibold mb-2" for="transaction_id">Transaction / cheque ID</label>
            <input id="transaction_id" name="transaction_id" type="text"
                class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
        </div>
        <?php if (!empty($invoiceLineItems)): ?>
        <div class="md:col-span-2">
            <label class="block text-gray-700 font-semibold mb-2" for="invoice_item_id">Product / service (optional)</label>
            <select id="invoice_item_id" name="invoice_item_id"
                class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500 bg-white">
                <option value="">— Whole invoice / not item-specific —</option>
                <?php foreach ($invoiceLineItems as $item): ?>
                    <option value="<?php echo (int) $item['id']; ?>">
                        [<?php echo htmlspecialchars($item['item_type']); ?>]
                        <?php echo htmlspecialchars($item['description']); ?>
                        (MK <?php echo number_format((float) $item['total_price'], 2); ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>
    </div>

    <div class="flex flex-col-reverse sm:flex-row sm:justify-end gap-3 pt-6 border-t border-gray-200">
        <a href="view?id=<?php echo (int) $invoice['id']; ?>"
            class="inline-flex items-center justify-center px-5 py-3 rounded-lg border border-gray-300 text-gray-700 hover:bg-gray-50 transition">
            Cancel
        </a>
        <button type="submit" <?php echo $balance <= 0 ? 'disabled' : ''; ?>
            class="inline-flex items-center justify-center px-5 py-3 rounded-lg bg-green-600 text-white font-semibold hover:bg-green-700 transition disabled:opacity-50 disabled:cursor-not-allowed">
            <i data-lucide="banknote" class="h-4 w-4 mr-2" aria-hidden="true"></i>
            Record payment
        </button>
    </div>
</form>

<script>if (typeof window.refreshAppShellIcons === 'function') { window.refreshAppShellIcons(); }</script>

<?php include '../../includes/footer.php'; ?>
