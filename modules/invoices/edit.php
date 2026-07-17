<?php
/**
 * Invoice Edit Page
 *
 * Lets a user adjust the customer-facing fields on an invoice — header
 * info, line items, VAT %, shipping fee — and refresh the audit columns
 * surfaced on the view page.
 *
 * Editing is blocked once any payment has been recorded so the audit
 * trail of paid invoices stays trustworthy.
 *
 * Routing: this file is reached via the extensionless URL
 * `modules/invoices/edit?id=X` so .htaccess preserves both GET and POST.
 */

require_once __DIR__ . '/../../config/app.php';
checkAuth();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../libs/InvoiceAuditMigrator.php';

if (function_exists('checkPermission')) {
    checkPermission('manage_invoices');
}

InvoiceAuditMigrator::ensure($pdo);

$id = isset($_GET['id']) ? (int) $_GET['id'] : (int) ($_POST['id'] ?? 0);
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

$payStmt = $pdo->prepare("SELECT COUNT(*) FROM invoice_payments WHERE invoice_id = ?");
$payStmt->execute([$id]);
$paymentCount = (int) $payStmt->fetchColumn();

$readOnly = $paymentCount > 0;

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$readOnly) {
    $token = $_POST['csrf_token'] ?? '';
    if (!verify_csrf_token($token, 'invoice_edit')) {
        $errors[] = 'Security check failed. Please reload the page and try again.';
    }

    $customer_name  = trim((string) ($_POST['customer_name']  ?? ''));
    $customer_email = trim((string) ($_POST['customer_email'] ?? ''));
    $customer_phone = trim((string) ($_POST['customer_phone'] ?? ''));
    $vatPercent     = (float) ($_POST['vat_percent']  ?? 0);
    $shipping       = (float) ($_POST['shipping_fee'] ?? 0);
    $rawItems       = $_POST['items'] ?? [];

    if ($customer_name === '') {
        $errors[] = 'Customer name is required.';
    }
    if ($customer_email !== '' && !filter_var($customer_email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Customer email is not a valid address.';
    }

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
        $errors[] = 'At least one line item is required.';
    }

    if (empty($errors)) {
        $vatAmount  = round($subtotal * ($vatPercent / 100), 2);
        $grandTotal = round($subtotal + $vatAmount + $shipping, 2);
        $paid       = (float) $invoice['paid_amount'];
        $balance    = round($grandTotal - $paid, 2);
        $newStatus  = $invoice['status'];
        if (!in_array($newStatus, ['Cancelled'], true)) {
            if ($balance <= 0 && $paid > 0) {
                $newStatus = 'Paid';
            } elseif ($paid > 0) {
                $newStatus = 'Partially Paid';
            } else {
                $newStatus = 'Unpaid';
            }
        }

        try {
            $update = $pdo->prepare("
                UPDATE invoices
                SET customer_name = :name,
                    customer_email = :email,
                    customer_phone = :phone,
                    items_json = :json,
                    subtotal = :subtotal,
                    vat_percent = :vat_pct,
                    tax_amount = :vat_amt,
                    shipping_fee = :ship,
                    total_amount = :total,
                    balance = :balance,
                    status = :status,
                    last_edited_at = NOW(),
                    last_edited_by = :editor
                WHERE id = :id
            ");
            $update->execute([
                'name'     => $customer_name,
                'email'    => $customer_email,
                'phone'    => $customer_phone,
                'json'     => json_encode($items),
                'subtotal' => $subtotal,
                'vat_pct'  => $vatPercent,
                'vat_amt'  => $vatAmount,
                'ship'     => $shipping,
                'total'    => $grandTotal,
                'balance'  => $balance,
                'status'   => $newStatus,
                'editor'   => (int) $_SESSION['user_id'],
                'id'       => $id,
            ]);

            $_SESSION['success'] = 'Invoice updated.';
            redirect('modules/invoices/view?id=' . $id);
        } catch (PDOException $e) {
            error_log('Invoice edit failed: ' . $e->getMessage());
            $errors[] = 'Could not save changes: ' . $e->getMessage();
        }
    }

    // Re-render with submitted (rejected) values so the user does not lose typing.
    $invoice['customer_name']  = $customer_name;
    $invoice['customer_email'] = $customer_email;
    $invoice['customer_phone'] = $customer_phone;
    $invoice['vat_percent']    = $vatPercent;
    $invoice['shipping_fee']   = $shipping;
    $invoice['items_json']     = json_encode($items ?? []);
}

$existingItems = json_decode((string) ($invoice['items_json'] ?? '[]'), true);
if (!is_array($existingItems)) {
    $existingItems = [];
}

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
        Edit Invoice <?php echo htmlspecialchars($invoice['invoice_number']); ?>
    </h1>
    <p class="text-gray-600 mt-1">
        Update the invoice details below. Edits refresh the audit notice on the view page.
    </p>
</div>

<?php if ($readOnly): ?>
    <div class="bg-amber-50 border border-amber-200 text-amber-900 rounded-lg p-4 mb-6 text-sm">
        This invoice has <?php echo (int) $paymentCount; ?> recorded payment(s) and cannot be edited.
        Reverse the payment(s) first if you need to make changes.
    </div>
<?php endif; ?>

<?php if (!empty($errors)): ?>
    <div class="bg-red-50 border border-red-200 text-red-800 rounded-lg p-4 mb-6">
        <p class="font-semibold mb-1">We couldn't save your changes:</p>
        <ul class="list-disc list-inside text-sm">
            <?php foreach ($errors as $err): ?>
                <li><?php echo htmlspecialchars($err); ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<form id="invoiceEditForm" method="POST" action="edit?id=<?php echo (int) $invoice['id']; ?>" class="bg-white shadow-md rounded-xl p-8 space-y-8" <?php echo $readOnly ? 'onsubmit="return false;"' : 'data-unsaved-guard data-unsaved-label="the invoice form"'; ?>>
    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token('invoice_edit')); ?>">
    <input type="hidden" name="id" value="<?php echo (int) $invoice['id']; ?>">

    <section>
        <h2 class="text-xl font-bold text-gray-800 mb-4">Customer details</h2>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <div>
                <label class="block text-gray-700 font-semibold mb-2" for="customer_name">Customer name *</label>
                <input id="customer_name" name="customer_name" type="text" required <?php echo $readOnly ? 'disabled' : ''; ?>
                    value="<?php echo htmlspecialchars((string) $invoice['customer_name']); ?>"
                    class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500 disabled:bg-gray-100">
            </div>
            <div>
                <label class="block text-gray-700 font-semibold mb-2" for="customer_email">Email</label>
                <input id="customer_email" name="customer_email" type="email" <?php echo $readOnly ? 'disabled' : ''; ?>
                    value="<?php echo htmlspecialchars((string) $invoice['customer_email']); ?>"
                    class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500 disabled:bg-gray-100">
            </div>
            <div>
                <label class="block text-gray-700 font-semibold mb-2" for="customer_phone">Phone</label>
                <input id="customer_phone" name="customer_phone" type="text" <?php echo $readOnly ? 'disabled' : ''; ?>
                    value="<?php echo htmlspecialchars((string) $invoice['customer_phone']); ?>"
                    class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500 disabled:bg-gray-100">
            </div>
        </div>
    </section>

    <section>
        <h2 class="text-xl font-bold text-gray-800 mb-4">Line items</h2>
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Description</th>
                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider w-48">Amount (MWK)</th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider w-20">Action</th>
                </tr>
            </thead>
            <tbody id="itemsBody" class="divide-y divide-gray-200">
                <?php foreach ($existingItems as $idx => $item):
                    $price = (float) ($item['total_price'] ?? $item['unit_price'] ?? $item['price'] ?? 0); ?>
                    <tr>
                        <td class="px-4 py-3 align-top">
                            <textarea name="items[<?php echo (int) $idx; ?>][description]" rows="2" required <?php echo $readOnly ? 'disabled' : ''; ?>
                                class="w-full border-gray-300 rounded p-2 disabled:bg-gray-100"><?php echo htmlspecialchars((string) ($item['description'] ?? '')); ?></textarea>
                        </td>
                        <td class="px-4 py-3 text-right align-top">
                            <input type="number" step="0.01" name="items[<?php echo (int) $idx; ?>][price]" required <?php echo $readOnly ? 'disabled' : ''; ?>
                                value="<?php echo number_format($price, 2, '.', ''); ?>"
                                class="w-full text-right border-gray-300 rounded p-2 item-price disabled:bg-gray-100"
                                oninput="recalc()">
                        </td>
                        <td class="px-4 py-3 text-center align-top">
                            <?php if (!$readOnly): ?>
                                <button type="button" onclick="removeRow(this)" class="text-red-500 hover:text-red-700">
                                    <i data-lucide="trash-2" class="h-5 w-5" aria-hidden="true"></i>
                                </button>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php if (!$readOnly): ?>
            <div class="mt-4">
                <button type="button" onclick="addRow()" class="text-blue-600 hover:text-blue-800 font-bold flex items-center">
                    <i data-lucide="plus" class="h-4 w-4 mr-1" aria-hidden="true"></i>
                    Add line
                </button>
            </div>
        <?php endif; ?>
    </section>

    <section class="flex justify-end">
        <div class="w-full md:w-1/2 lg:w-1/3 bg-gray-50 p-6 rounded-lg space-y-3">
            <div class="flex justify-between items-center">
                <span class="text-gray-600">Subtotal:</span>
                <span class="font-bold">MWK <span id="displaySubtotal">0.00</span></span>
            </div>
            <div class="flex justify-between items-center">
                <span class="text-gray-600 flex items-center">
                    VAT %
                    <input type="number" id="vatPercent" name="vat_percent" step="0.01" <?php echo $readOnly ? 'disabled' : ''; ?>
                        value="<?php echo number_format((float) ($invoice['vat_percent'] ?? 0), 2, '.', ''); ?>"
                        class="w-20 ml-2 border rounded p-1 text-right disabled:bg-gray-100"
                        oninput="recalc()">
                </span>
                <span class="font-bold">MWK <span id="displayVat">0.00</span></span>
            </div>
            <div class="flex justify-between items-center">
                <span class="text-gray-600">Shipping fee:</span>
                <input type="number" step="0.01" name="shipping_fee" id="shippingFee" <?php echo $readOnly ? 'disabled' : ''; ?>
                    value="<?php echo number_format((float) ($invoice['shipping_fee'] ?? 0), 2, '.', ''); ?>"
                    class="w-32 border rounded p-1 text-right disabled:bg-gray-100" oninput="recalc()">
            </div>
            <div class="border-t pt-3 flex justify-between items-center">
                <span class="font-bold text-gray-800 text-xl">Grand total:</span>
                <span class="font-bold text-xl text-blue-600">MWK <span id="displayGrandTotal">0.00</span></span>
            </div>
        </div>
    </section>

    <div class="flex flex-col-reverse sm:flex-row sm:justify-end gap-3 pt-6 border-t border-gray-200">
        <a href="view?id=<?php echo (int) $invoice['id']; ?>"
            class="inline-flex items-center justify-center px-5 py-3 rounded-lg border border-gray-300 text-gray-700 hover:bg-gray-50 transition">
            Cancel
        </a>
        <?php if (!$readOnly): ?>
            <button type="submit"
                class="inline-flex items-center justify-center px-5 py-3 rounded-lg bg-green-600 text-white font-semibold hover:bg-green-700 transition">
                <i data-lucide="save" class="h-4 w-4 mr-2" aria-hidden="true"></i>
                Save changes
            </button>
        <?php endif; ?>
    </div>
</form>

<script>
    let rowCount = <?php echo count($existingItems); ?>;

    function recalc() {
        let subtotal = 0;
        document.querySelectorAll('.item-price').forEach(i => { subtotal += parseFloat(i.value) || 0; });
        const vatPct = parseFloat(document.getElementById('vatPercent').value) || 0;
        const ship = parseFloat(document.getElementById('shippingFee').value) || 0;
        const vat = subtotal * (vatPct / 100);
        const grand = subtotal + vat + ship;

        document.getElementById('displaySubtotal').textContent = subtotal.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        document.getElementById('displayVat').textContent = vat.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        document.getElementById('displayGrandTotal').textContent = grand.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function addRow() {
        const tbody = document.getElementById('itemsBody');
        const tr = document.createElement('tr');
        tr.innerHTML = `
            <td class="px-4 py-3 align-top">
                <textarea name="items[${rowCount}][description]" rows="2" required class="w-full border-gray-300 rounded p-2"></textarea>
            </td>
            <td class="px-4 py-3 text-right align-top">
                <input type="number" step="0.01" name="items[${rowCount}][price]" required value="0.00"
                    class="w-full text-right border-gray-300 rounded p-2 item-price" oninput="recalc()">
            </td>
            <td class="px-4 py-3 text-center align-top">
                <button type="button" onclick="removeRow(this)" class="text-red-500 hover:text-red-700">
                    <i data-lucide="trash-2" class="h-5 w-5" aria-hidden="true"></i>
                </button>
            </td>`;
        tbody.appendChild(tr);
        rowCount++;
        if (typeof window.refreshAppShellIcons === 'function') window.refreshAppShellIcons();
    }

    function removeRow(btn) {
        const rows = document.querySelectorAll('#itemsBody tr');
        if (rows.length > 1) {
            btn.closest('tr').remove();
            recalc();
        } else {
            alert('At least one line item is required.');
        }
    }

    recalc();
    if (typeof window.refreshAppShellIcons === 'function') window.refreshAppShellIcons();

    document.addEventListener('form-unsaved-discarded', function (event) {
        if (event.detail && event.detail.action === 'restore') {
            recalc();
        }
    });
</script>

<?php include '../../includes/footer.php'; ?>
