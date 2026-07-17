<?php
/**
 * Invoice Create / Convert From Estimation
 *
 * When `?estimation_id=X` is supplied this page pre-fills the form so the
 * customer-facing invoice contains exactly:
 *
 *   - ONE service line for the estimation's job description, priced at the
 *     pre-VAT total stored on the estimation. Material rates from the
 *     estimation wizard are NEVER quoted on the invoice; they are internal
 *     costing data.
 *   - The same VAT % that was used in the estimation form.
 *   - Empty rows the user can add manually for any extra costs that did not
 *     make it into the estimation.
 *
 * The page also still supports building an invoice from scratch (no
 * estimation_id), in which case the user manages every line manually.
 */

require_once __DIR__ . '/../../config/app.php';
checkAuth();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../libs/EstimationAuditMigrator.php';
require_once __DIR__ . '/../../libs/InvoiceAuditMigrator.php';
require_once __DIR__ . '/../../includes/permissions_helper.php';
permissions_require_one_of(['manage_invoices']);

EstimationAuditMigrator::ensure($pdo);
InvoiceAuditMigrator::ensure($pdo);

$est_id     = isset($_GET['estimation_id']) ? (int) $_GET['estimation_id'] : 0;
$estimation = null;

// Default VAT (used when building from scratch). 17.5 mirrors the
// default in the estimation wizard.
$prefillVatPercent  = 17.5;
$prefillJobDesc     = '';
$prefillJobAmount   = 0.00;
$prefillCustomer    = ['name' => '', 'email' => '', 'phone' => ''];
$prefillEstNumber   = '';

if ($est_id > 0) {
    // Block double-invoicing: each estimation can only mint one invoice.
    $check = $pdo->prepare("SELECT id, invoice_number FROM invoices WHERE estimation_id = ?");
    $check->execute([$est_id]);
    if ($existing = $check->fetch()) {
        $_SESSION['error'] = 'An invoice already exists for this estimation: ' . $existing['invoice_number'];
        redirect('modules/invoices/list?error=exists');
    }

    $stmt = $pdo->prepare("SELECT * FROM estimations WHERE id = ?");
    $stmt->execute([$est_id]);
    $estimation = $stmt->fetch();
    if (!$estimation) {
        $_SESSION['error'] = 'Estimation not found.';
        redirect('modules/invoices/list?error=not_found');
    }

    $prefillEstNumber = (string) $estimation['estimation_number'];
    $prefillCustomer  = [
        'name'  => (string) ($estimation['customer_name']  ?? ''),
        'email' => (string) ($estimation['customer_email'] ?? ''),
        'phone' => (string) ($estimation['customer_phone'] ?? ''),
    ];
    $prefillJobDesc = trim((string) ($estimation['job_description'] ?? ''));
    if ($prefillJobDesc === '') {
        $prefillJobDesc = 'Printing services per estimation ' . $prefillEstNumber;
    }

    // Pre-VAT total is what the customer is billed for the job line.
    // Fall back to (total_amount - vat_amount) if the breakdown column is
    // missing on legacy rows; final fallback is total_amount itself.
    $prefillJobAmount = isset($estimation['pre_vat_total']) && $estimation['pre_vat_total'] !== null
        ? (float) $estimation['pre_vat_total']
        : max(0.0, (float) ($estimation['total_amount'] ?? 0) - (float) ($estimation['vat_amount'] ?? 0));

    if ($prefillJobAmount <= 0) {
        $prefillJobAmount = (float) ($estimation['total_amount'] ?? 0);
    }

    if (isset($estimation['vat_percent']) && $estimation['vat_percent'] !== null) {
        $prefillVatPercent = (float) $estimation['vat_percent'];
    }
}

include '../../includes/header.php';
?>

<div class="mb-6">
    <div class="flex items-center gap-2 mb-4">
        <a href="list" class="text-blue-600 hover:underline flex items-center">
            <i data-lucide="arrow-left" class="mr-1 inline-block h-4 w-4 flex-shrink-0" aria-hidden="true"></i>
            Back to invoices
        </a>
    </div>
    <h1 class="text-3xl font-bold text-gray-800">
        <?php echo $est_id ? 'Convert estimation to invoice' : 'Create new invoice'; ?>
    </h1>
    <?php if ($est_id): ?>
        <p class="text-gray-600 mt-1">
            Building from estimation <strong><?php echo htmlspecialchars($prefillEstNumber); ?></strong>.
            Material rates remain internal — the customer will see one job-description line plus VAT.
        </p>
    <?php endif; ?>
</div>

<?php if ($est_id): ?>
    <div class="bg-blue-50 border border-blue-200 text-blue-900 rounded-lg p-4 mb-6 text-sm">
        <p class="font-semibold mb-1">How this conversion works</p>
        <ul class="list-disc list-inside space-y-1">
            <li>The first line is locked to the <strong>job description</strong> with the
                estimation's <strong>pre-VAT total</strong>
                (MK <?php echo number_format($prefillJobAmount, 2); ?>).</li>
            <li>VAT is pre-loaded at <strong><?php echo number_format($prefillVatPercent, 2); ?>%</strong>,
                exactly as applied in the estimation form.</li>
            <li>Add rows below for any <strong>extra costs that were not part of the estimation</strong>
                (e.g. delivery, design surcharge, urgent-job fee).</li>
        </ul>
    </div>
<?php endif; ?>

<div class="bg-white shadow-md rounded-xl p-8">
    <form id="invoiceForm" method="POST" action="save" data-unsaved-guard data-unsaved-label="the invoice form" data-unsaved-discard="reload">
        <input type="hidden" name="estimation_id" value="<?php echo (int) $est_id; ?>">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token('invoice_create')); ?>">

        <h3 class="text-lg font-bold text-gray-800 mb-4 border-b pb-2">Client details</h3>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-8">
            <div>
                <label class="block text-gray-700 font-bold mb-2">Customer name *</label>
                <input type="text" name="customer_name" required
                    value="<?php echo htmlspecialchars($prefillCustomer['name']); ?>"
                    class="w-full border rounded p-2 focus:outline-none focus:ring-2 focus:ring-blue-500">
            </div>
            <div>
                <label class="block text-gray-700 font-bold mb-2">Customer email</label>
                <input type="email" name="customer_email"
                    value="<?php echo htmlspecialchars($prefillCustomer['email']); ?>"
                    class="w-full border rounded p-2 focus:outline-none focus:ring-2 focus:ring-blue-500">
            </div>
            <div>
                <label class="block text-gray-700 font-bold mb-2">Customer phone</label>
                <input type="text" name="customer_phone"
                    value="<?php echo htmlspecialchars($prefillCustomer['phone']); ?>"
                    class="w-full border rounded p-2 focus:outline-none focus:ring-2 focus:ring-blue-500">
            </div>
            <div>
                <label class="block text-gray-700 font-bold mb-2">Department / organisation</label>
                <input type="text" name="department" placeholder="Client department or organisation"
                    class="w-full border rounded p-2 focus:outline-none focus:ring-2 focus:ring-blue-500">
            </div>
        </div>

        <div class="mb-8">
            <h3 class="text-lg font-bold text-gray-800 mb-4 border-b pb-2">Invoice lines</h3>
            <table class="min-w-full divide-y divide-gray-200" id="itemsTable">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Description</th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider w-48">Amount (MWK)</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider w-20">Action</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200" id="itemsBody">
                    <?php if ($est_id): ?>
                        <tr class="bg-blue-50/40">
                            <td class="px-4 py-3 align-top">
                                <textarea name="items[0][description]" rows="3" required
                                    class="w-full border-gray-300 rounded p-2 focus:ring-blue-500 focus:border-blue-500"><?php echo htmlspecialchars($prefillJobDesc); ?></textarea>
                                <p class="text-xs text-gray-500 mt-1">
                                    <i data-lucide="lock" class="inline-block h-3 w-3 align-text-bottom" aria-hidden="true"></i>
                                    Service line from estimation <?php echo htmlspecialchars($prefillEstNumber); ?>.
                                    Adjust the wording if needed; the price is the estimation's pre-VAT total.
                                </p>
                            </td>
                            <td class="px-4 py-3 text-right align-top">
                                <input type="number" step="0.01" name="items[0][price]" required
                                    value="<?php echo number_format($prefillJobAmount, 2, '.', ''); ?>"
                                    class="w-full text-right border-gray-300 rounded p-2 item-price"
                                    oninput="calculateTotal()">
                            </td>
                            <td class="px-4 py-3 text-center text-gray-300 align-top" title="The estimation line cannot be removed">
                                <i data-lucide="lock" class="h-5 w-5 inline-block" aria-hidden="true"></i>
                            </td>
                        </tr>
                    <?php else: ?>
                        <tr>
                            <td class="px-4 py-3">
                                <input type="text" name="items[0][description]" required placeholder="Item description"
                                    class="w-full border rounded p-2">
                            </td>
                            <td class="px-4 py-3 text-right">
                                <input type="number" step="0.01" name="items[0][price]" required placeholder="0.00"
                                    class="w-full text-right border rounded p-2 item-price"
                                    oninput="calculateTotal()">
                            </td>
                            <td class="px-4 py-3 text-center">
                                <button type="button" onclick="removeRow(this)" class="text-red-500 hover:text-red-700">
                                    <i data-lucide="trash-2" class="h-5 w-5" aria-hidden="true"></i>
                                </button>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
            <div class="mt-4">
                <button type="button" onclick="addRow()" class="text-blue-600 hover:text-blue-800 font-bold flex items-center">
                    <i data-lucide="plus" class="h-4 w-4 mr-1" aria-hidden="true"></i>
                    Add extra line (cost not in estimation)
                </button>
            </div>
        </div>

        <div class="flex justify-end">
            <div class="w-full md:w-1/2 lg:w-1/3 bg-gray-50 p-6 rounded-lg space-y-3">
                <div class="flex justify-between items-center">
                    <span class="text-gray-600">Subtotal:</span>
                    <span class="font-bold">MWK <span id="displaySubtotal">0.00</span></span>
                    <input type="hidden" name="subtotal" id="inputSubtotal">
                </div>

                <div class="flex justify-between items-center">
                    <span class="text-gray-600 flex items-center">
                        VAT %
                        <input type="number" id="vatPercent" name="vat_percent" step="0.01"
                            class="w-20 ml-2 border rounded p-1 text-right"
                            value="<?php echo number_format($prefillVatPercent, 2, '.', ''); ?>"
                            oninput="calculateTotal()">
                    </span>
                    <span class="font-bold">MWK <span id="displayVat">0.00</span></span>
                    <input type="hidden" name="vat_amount" id="inputVat">
                </div>

                <div class="flex justify-between items-center">
                    <span class="text-gray-600">Shipping fee:</span>
                    <input type="number" step="0.01" name="shipping_fee" id="shippingFee"
                        class="w-32 border rounded p-1 text-right focus:ring-blue-500" value="0.00" oninput="calculateTotal()">
                </div>

                <div class="border-t pt-3 flex justify-between items-center">
                    <span class="font-bold text-gray-800 text-xl">Grand total:</span>
                    <span class="font-bold text-xl text-blue-600">MWK <span id="displayGrandTotal">0.00</span></span>
                    <input type="hidden" name="total_amount" id="inputGrandTotal">
                </div>

                <?php if ($est_id): ?>
                    <p class="text-xs text-gray-500 mt-2">
                        VAT pre-loaded from estimation <?php echo htmlspecialchars($prefillEstNumber); ?>.
                        Editing the rate here will recalculate the totals but will not change the estimation.
                    </p>
                <?php endif; ?>
            </div>
        </div>

        <div class="mt-8 flex justify-end gap-4">
            <a href="list" class="px-6 py-3 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300 transition">Cancel</a>
            <button type="submit"
                class="px-6 py-3 bg-green-600 text-white font-bold rounded-lg shadow hover:bg-green-700 transition flex items-center">
                <i data-lucide="save" class="h-4 w-4 mr-2" aria-hidden="true"></i>
                Save invoice
            </button>
        </div>
    </form>
</div>

<script>
    let rowCount = 1;

    function calculateTotal() {
        let subtotal = 0;
        document.querySelectorAll('.item-price').forEach(input => {
            subtotal += parseFloat(input.value) || 0;
        });

        document.getElementById('displaySubtotal').innerText = subtotal.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        document.getElementById('inputSubtotal').value = subtotal.toFixed(2);

        const vatPercent = parseFloat(document.getElementById('vatPercent').value) || 0;
        const vatAmount = subtotal * (vatPercent / 100);
        document.getElementById('displayVat').innerText = vatAmount.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        document.getElementById('inputVat').value = vatAmount.toFixed(2);

        const shipping = parseFloat(document.getElementById('shippingFee').value) || 0;
        const grandTotal = subtotal + vatAmount + shipping;
        document.getElementById('displayGrandTotal').innerText = grandTotal.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        document.getElementById('inputGrandTotal').value = grandTotal.toFixed(2);
    }

    function addRow() {
        const tbody = document.getElementById('itemsBody');
        const row = document.createElement('tr');
        row.innerHTML = `
            <td class="px-4 py-3">
                <input type="text" name="items[${rowCount}][description]" required placeholder="Extra cost not in estimation"
                    class="w-full border rounded p-2">
            </td>
            <td class="px-4 py-3 text-right">
                <input type="number" step="0.01" name="items[${rowCount}][price]" required placeholder="0.00"
                    class="w-full text-right border rounded p-2 item-price" oninput="calculateTotal()">
            </td>
            <td class="px-4 py-3 text-center">
                <button type="button" onclick="removeRow(this)" class="text-red-500 hover:text-red-700">
                    <i data-lucide="trash-2" class="h-5 w-5" aria-hidden="true"></i>
                </button>
            </td>`;
        tbody.appendChild(row);
        rowCount++;
        if (typeof window.refreshAppShellIcons === 'function') window.refreshAppShellIcons();
    }

    function removeRow(btn) {
        const row = btn.closest('tr');
        const remaining = document.querySelectorAll('#itemsBody tr').length;
        if (remaining > 1) {
            row.remove();
            calculateTotal();
        } else {
            alert('At least one line item is required.');
        }
    }

    calculateTotal();
    if (typeof window.refreshAppShellIcons === 'function') window.refreshAppShellIcons();
</script>

<?php include '../../includes/footer.php'; ?>
