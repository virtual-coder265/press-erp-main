<?php
require_once __DIR__ . '/../../config/app.php';
checkAuth();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/permissions_helper.php';
permissions_require_one_of(['manage_sales', 'manage_invoices']);
require_once __DIR__ . '/../../libs/InvoicePaymentGrMigrator.php';

InvoicePaymentGrMigrator::ensure($pdo);


// Fetch unpaid/partially paid invoices
$invoicesCountQuery = "
    SELECT id, invoice_number, balance, customer_name, total_amount, (SELECT estimation_number FROM estimations e WHERE e.id = invoices.estimation_id) as est_num
    FROM invoices 
    WHERE balance > 0 OR status IN ('Unpaid', 'Partially Paid', 'Overdue')
    ORDER BY id DESC
";
$invoices = $pdo->query($invoicesCountQuery)->fetchAll();

// Line items per invoice (for GR payment allocation)
$invoiceItemsByInvoice = [];
if (!empty($invoices)) {
    $invoiceIds = array_column($invoices, 'id');
    $placeholders = implode(',', array_fill(0, count($invoiceIds), '?'));
    $itemStmt = $pdo->prepare("SELECT id, invoice_id, item_type, description, total_price FROM invoice_items WHERE invoice_id IN ($placeholders) ORDER BY id ASC");
    $itemStmt->execute($invoiceIds);
    foreach ($itemStmt->fetchAll() as $row) {
        $invoiceItemsByInvoice[(int) $row['invoice_id']][] = $row;
    }
}

// Fetch Products and Services for direct sales
$products = $pdo->query("SELECT id, name, price FROM products ORDER BY name ASC")->fetchAll();
$services = $pdo->query("SELECT id, name, price FROM services ORDER BY name ASC")->fetchAll();

include '../../includes/header.php';
?>

<div class="mb-6">
    <a href="index.php" class="text-green-600 hover:underline flex items-center">
        <i class="material-icons text-sm mr-1">arrow_back</i> Back to Sales
    </a>
    <h1 class="text-3xl font-bold text-gray-800 mt-2">Record Sale / Payment</h1>
</div>

<div class="bg-white shadow rounded-lg p-6 max-w-4xl mx-auto">

    <!-- Mode Selection -->
    <div class="flex items-center space-x-6 mb-8 border-b pb-4">
        <label class="flex items-center space-x-2 cursor-pointer">
            <input type="radio" name="sale_mode" value="invoice" class="form-radio h-5 w-5 text-green-600" checked
                onchange="toggleMode()">
            <span class="text-lg font-medium text-gray-700">Pay Existing Invoice / Account</span>
        </label>
        <label class="flex items-center space-x-2 cursor-pointer">
            <input type="radio" name="sale_mode" value="direct" class="form-radio h-5 w-5 text-green-600"
                onchange="toggleMode()">
            <span class="text-lg font-medium text-gray-700">New Direct Sale</span>
        </label>
    </div>

    <!-- Form -->
    <form action="process_sale" method="POST" id="saleForm">
        <input type="hidden" name="mode" id="formMode" value="invoice">

        <!-- ================= INVOICE PAYMENT SECTION ================= -->
        <div id="invoiceSection">
            <h2 class="text-xl font-bold text-gray-800 mb-4 border-b pb-2">Invoice Selection</h2>
            <div class="mb-6">
                <label class="block text-gray-700 font-semibold mb-2">Select Invoice *</label>
                <select name="invoice_id" id="invoice_id"
                    class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500 bg-white"
                    onchange="updateBalanceHint()">
                    <option value="">-- Choose an Invoice --</option>
                    <?php foreach ($invoices as $inv): ?>
                        <option value="<?php echo $inv['id']; ?>" data-balance="<?php echo $inv['balance']; ?>">
                            <?php echo $inv['invoice_number']; ?>
                            (
                            <?php echo $inv['customer_name'] ?: ($inv['est_num'] ? "Est: " . $inv['est_num'] : 'Unknown'); ?>)
                            - Bal: MK
                            <?php echo number_format($inv['balance'], 2); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <p id="balanceHint" class="text-sm text-gray-500 mt-2 font-medium"></p>
            </div>
        </div>

        <!-- ================= DIRECT SALE SECTION ================= -->
        <div id="directSaleSection" class="hidden">
            <h2 class="text-xl font-bold text-gray-800 mb-4 border-b pb-2">Customer Details</h2>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                <div>
                    <label class="block text-gray-700 font-semibold mb-2">Customer Name *</label>
                    <input type="text" name="customer_name" id="ds_customer_name"
                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500">
                </div>
                <div>
                    <label class="block text-gray-700 font-semibold mb-2">Customer Phone</label>
                    <input type="text" name="customer_phone"
                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500">
                </div>
                <div class="md:col-span-2">
                    <label class="block text-gray-700 font-semibold mb-2">Customer Email</label>
                    <input type="email" name="customer_email"
                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500">
                </div>
            </div>

            <h2 class="text-xl font-bold text-gray-800 mb-4 border-b pb-2 flex justify-between items-center">
                Items
                <button type="button" onclick="addItemRow()"
                    class="text-sm bg-gray-200 text-gray-700 px-3 py-1 rounded hover:bg-gray-300 transition">Add
                    Item</button>
            </h2>
            <div class="bg-gray-50 p-4 rounded-lg mb-6 border border-gray-200" id="itemsContainer">
                <!-- Item Row Template -->
                <div class="item-row grid grid-cols-12 gap-4 mb-4 items-center">
                    <div class="col-span-3">
                        <label class="block text-xs font-semibold text-gray-600 uppercase mb-1">Type</label>
                        <select name="item_type[]"
                            class="w-full px-3 py-2 border rounded-md focus:outline-none focus:ring-2 focus:ring-green-500 item-type-select"
                            onchange="handleTypeChange(this)">
                            <option value="Product">Product</option>
                            <option value="Service">Service</option>
                            <option value="Other">Other / Custom</option>
                        </select>
                    </div>
                    <div class="col-span-4 item-selector-container">
                        <label class="block text-xs font-semibold text-gray-600 uppercase mb-1">Item /
                            Description</label>
                        <select name="item_id[]"
                            class="w-full px-3 py-2 border rounded-md focus:outline-none focus:ring-2 focus:ring-green-500 item-select"
                            onchange="updatePrice(this)">
                            <option value="">Select Product...</option>
                            <?php foreach ($products as $p): ?>
                                <option value="<?php echo $p['id']; ?>" data-price="<?php echo $p['price']; ?>"
                                    data-name="<?php echo htmlspecialchars($p['name']); ?>">
                                    <?php echo htmlspecialchars($p['name']); ?> (MK
                                    <?php echo number_format($p['price'], 2); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <input type="hidden" name="item_desc[]" class="item-desc-hidden">
                    </div>
                    <div class="col-span-2">
                        <label class="block text-xs font-semibold text-gray-600 uppercase mb-1">Qty</label>
                        <input type="number" step="1" min="1" name="quantity[]" value="1"
                            class="w-full px-3 py-2 border rounded-md focus:outline-none focus:ring-2 focus:ring-green-500 item-qty"
                            oninput="calculateRowTotal(this)">
                    </div>
                    <div class="col-span-2">
                        <label class="block text-xs font-semibold text-gray-600 uppercase mb-1">Unit Price</label>
                        <input type="number" step="0.01" name="unit_price[]" value="0.00"
                            class="w-full px-3 py-2 border rounded-md focus:outline-none focus:ring-2 focus:ring-green-500 item-price"
                            oninput="calculateRowTotal(this)">
                    </div>
                    <div class="col-span-1 text-center pt-5">
                        <button type="button" onclick="removeItemRow(this)"
                            class="text-red-500 hover:bg-red-100 p-2 rounded-full transition">
                            <i class="material-icons text-sm">close</i>
                        </button>
                    </div>
                </div>
            </div>

            <!-- Totals -->
            <div class="flex justify-end gap-x-8 mb-6 border-t pt-4">
                <div class="text-right">
                    <p class="text-gray-600">Subtotal: <span id="ds_subtotal"
                            class="font-semibold text-gray-900">0.00</span></p>
                    <p class="text-gray-600 flex items-center justify-end mt-2">
                        Tax (%): <input type="number" name="tax_rate" id="ds_tax_rate" value="0" min="0" max="100"
                            class="w-16 ml-2 px-2 py-1 border rounded text-right focus:outline-none"
                            oninput="calculateGrandTotal()">
                    </p>
                    <p class="text-gray-600 flex items-center justify-end mt-2">
                        Discount (MK): <input type="number" name="discount" id="ds_discount" value="0" min="0"
                            step="0.01" class="w-24 ml-2 px-2 py-1 border rounded text-right focus:outline-none"
                            oninput="calculateGrandTotal()">
                    </p>
                    <p class="text-2xl font-bold text-gray-900 mt-4 border-t pt-2">
                        Total: MK <span id="ds_total">0.00</span>
                    </p>
                    <input type="hidden" name="direct_total_amount" id="ds_total_input" value="0">
                </div>
            </div>

            <div class="mb-4">
                <label class="flex items-center space-x-2">
                    <input type="checkbox" name="generate_invoice_number" value="1" checked
                        class="form-checkbox text-green-600 rounded">
                    <span class="text-sm text-gray-700">Auto-generate Invoice Number</span>
                </label>
            </div>
        </div>

        <!-- ================= PAYMENT CAPTURE SECTION ================= -->
        <h2 class="text-xl font-bold text-gray-800 mb-4 border-b pb-2">Payment Details</h2>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
            <div>
                <label class="block text-gray-700 font-semibold mb-2">General Receipt (GR) Number *</label>
                <input type="text" name="gr_number" id="gr_number" maxlength="50"
                    placeholder="e.g. 7855123"
                    class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500 font-mono">
                <p class="text-xs text-gray-500 mt-1">Enter the physical GR number from the receipt book. Each payment must have a unique GR.</p>
            </div>
            <div id="invoiceItemContainer" class="md:col-span-2">
                <label class="block text-gray-700 font-semibold mb-2">Product / Service (optional)</label>
                <select name="invoice_item_id" id="invoice_item_id"
                    class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500 bg-white">
                    <option value="">— Whole invoice / not item-specific —</option>
                </select>
                <p class="text-xs text-gray-500 mt-1">Link this payment to a specific line item when paying for one service multiple times.</p>
            </div>
            <div>
                <label class="block text-gray-700 font-semibold mb-2">Payment Amount (MK) *</label>
                <input type="number" step="0.01" name="amount" id="payment_amount" required
                    class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500 text-xl font-bold text-gray-800">
            </div>
            <div>
                <label class="block text-gray-700 font-semibold mb-2">Payment Method *</label>
                <select name="payment_method" id="payment_method" required
                    class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500 bg-white"
                    onchange="toggleTransactionId()">
                    <option value="Cash">Cash</option>
                    <option value="Bank Transfer">Bank Transfer</option>
                    <option value="Cheque">Cheque</option>
                    <option value="PayChangu" disabled>PayChangu (Coming Soon)</option>
                </select>
            </div>
            <div id="txIdContainer" class="hidden">
                <label class="block text-gray-700 font-semibold mb-2">Transaction / Cheque ID *</label>
                <input type="text" name="transaction_id" id="transaction_id"
                    class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500">
            </div>
            <div class="md:col-span-3 hidden" id="paymentHintContainer">
                <p class="text-sm bg-yellow-50 text-yellow-800 p-3 rounded border border-yellow-200 flex items-start">
                    <i class="material-icons text-base mr-2 mt-0.5">info</i>
                    <span>This payment will be recorded as a settlement. If the amount is less than the total balance,
                        the invoice status will become "Partially Paid".</span>
                </p>
            </div>
        </div>

        <div class="flex justify-end pt-4 border-t border-gray-200">
            <button type="submit"
                class="bg-green-600 hover:bg-green-700 text-white font-bold py-3 px-8 rounded-lg shadow-lg transition flex items-center">
                <i class="material-icons mr-2">save</i> Process Sale / Payment
            </button>
        </div>
    </form>
</div>

<!-- Raw data for JS logic -->
<script>
    const productsData = <?php echo json_encode($products); ?>;
    const servicesData = <?php echo json_encode($services); ?>;
    const invoiceItemsByInvoice = <?php echo json_encode($invoiceItemsByInvoice); ?>;

    function toggleMode() {
        const mode = document.querySelector('input[name="sale_mode"]:checked').value;
        document.getElementById('formMode').value = mode;

        const invoiceSection = document.getElementById('invoiceSection');
        const directSaleSection = document.getElementById('directSaleSection');

        // Form requirements
        const invoiceSelect = document.getElementById('invoice_id');
        const customerNameInput = document.getElementById('ds_customer_name');

        if (mode === 'invoice') {
            invoiceSection.classList.remove('hidden');
            directSaleSection.classList.add('hidden');
            document.getElementById('paymentHintContainer').classList.remove('hidden');
            document.getElementById('invoiceItemContainer').classList.remove('hidden');
            syncGrRequirement();

            invoiceSelect.required = true;
            customerNameInput.required = false;

            updateBalanceHint();
        } else {
            invoiceSection.classList.add('hidden');
            directSaleSection.classList.remove('hidden');
            document.getElementById('paymentHintContainer').classList.add('hidden');
            document.getElementById('invoiceItemContainer').classList.add('hidden');
            document.getElementById('invoice_item_id').value = '';
            syncGrRequirement();

            invoiceSelect.required = false;
            customerNameInput.required = true;

            // clear hint
            document.getElementById('balanceHint').textContent = '';
            calculateGrandTotal(); // resets ds_total output to payment amount
        }
    }

    function updateInvoiceItemOptions() {
        const select = document.getElementById('invoice_id');
        const itemSelect = document.getElementById('invoice_item_id');
        const invoiceId = select.value;

        itemSelect.innerHTML = '<option value="">— Whole invoice / not item-specific —</option>';

        if (!invoiceId || !invoiceItemsByInvoice[invoiceId]) {
            return;
        }

        invoiceItemsByInvoice[invoiceId].forEach(item => {
            const opt = document.createElement('option');
            opt.value = item.id;
            opt.textContent = `[${item.item_type}] ${item.description} (MK ${parseFloat(item.total_price).toLocaleString(undefined, { minimumFractionDigits: 2 })})`;
            itemSelect.appendChild(opt);
        });
    }

    function updateBalanceHint() {
        const select = document.getElementById('invoice_id');
        updateInvoiceItemOptions();
        if (select.selectedIndex <= 0) {
            document.getElementById('balanceHint').textContent = '';
            return;
        }

        const option = select.options[select.selectedIndex];
        const balance = option.getAttribute('data-balance');
        document.getElementById('balanceHint').textContent = 'Current Balance: MK ' + parseFloat(balance).toLocaleString(undefined, { minimumFractionDigits: 2 });

        // Auto-fill payment amount
        document.getElementById('payment_amount').value = parseFloat(balance).toFixed(2);
    }

    function syncGrRequirement() {
        const mode = document.getElementById('formMode').value;
        const amount = parseFloat(document.getElementById('payment_amount').value) || 0;
        const grInput = document.getElementById('gr_number');
        grInput.required = mode === 'invoice' || amount > 0;
    }

    function toggleTransactionId() {
        const method = document.getElementById('payment_method').value;
        const txContainer = document.getElementById('txIdContainer');
        const txInput = document.getElementById('transaction_id');

        if (method === 'Bank Transfer' || method === 'Cheque') {
            txContainer.classList.remove('hidden');
            txInput.required = true;
        } else {
            txContainer.classList.add('hidden');
            txInput.required = false;
            txInput.value = '';
        }
    }

    // Direct Sale Items Logic
    function handleTypeChange(selectElem) {
        const row = selectElem.closest('.item-row');
        const container = row.querySelector('.item-selector-container');
        const type = selectElem.value;

        let html = '';
        if (type === 'Product' || type === 'Service') {
            const dataList = type === 'Product' ? productsData : servicesData;
            html = `<select name="item_id[]" class="w-full px-3 py-2 border rounded-md focus:outline-none focus:ring-2 focus:ring-green-500 item-select" onchange="updatePrice(this)">
                        <option value="">Select ${type}...</option>`;
            dataList.forEach(item => {
                html += `<option value="${item.id}" data-price="${item.price}" data-name="${item.name}">${item.name} (MK ${parseFloat(item.price).toLocaleString()})</option>`;
            });
            html += `</select><input type="hidden" name="item_desc[]" class="item-desc-hidden">`;
        } else {
            // Other / Custom text input
            html = `
                <input type="hidden" name="item_id[]" value="">
                <input type="text" name="item_desc[]" placeholder="Enter custom item description" required class="w-full px-3 py-2 border rounded-md focus:outline-none focus:ring-2 focus:ring-green-500">
            `;
            // clear price
            const priceInput = row.querySelector('.item-price');
            priceInput.value = "0.00";
        }

        container.innerHTML = html;
        calculateRowTotal(selectElem);
    }

    function updatePrice(selectElem) {
        if (!selectElem.value) return;
        const option = selectElem.options[selectElem.selectedIndex];
        const price = option.getAttribute('data-price');
        const name = option.getAttribute('data-name');

        const row = selectElem.closest('.item-row');
        row.querySelector('.item-price').value = parseFloat(price).toFixed(2);
        row.querySelector('.item-desc-hidden').value = name;

        calculateRowTotal(selectElem);
    }

    function calculateRowTotal(elem) {
        calculateGrandTotal();
    }

    function calculateGrandTotal() {
        let itemsTotal = 0;
        const rows = document.querySelectorAll('.item-row');
        rows.forEach(row => {
            const qty = parseFloat(row.querySelector('.item-qty').value) || 0;
            const price = parseFloat(row.querySelector('.item-price').value) || 0;
            itemsTotal += (qty * price);
        });

        document.getElementById('ds_subtotal').textContent = itemsTotal.toLocaleString(undefined, { minimumFractionDigits: 2 });

        const taxRate = parseFloat(document.getElementById('ds_tax_rate').value) || 0;
        const discount = parseFloat(document.getElementById('ds_discount').value) || 0;

        const taxAmount = itemsTotal * (taxRate / 100);
        let finalTotal = itemsTotal + taxAmount - discount;
        if (finalTotal < 0) finalTotal = 0;

        document.getElementById('ds_total').textContent = finalTotal.toLocaleString(undefined, { minimumFractionDigits: 2 });
        document.getElementById('ds_total_input').value = finalTotal.toFixed(2);

        // Auto fill payment amount for direct sale mode
        if (document.getElementById('formMode').value === 'direct') {
            document.getElementById('payment_amount').value = finalTotal.toFixed(2);
        }
    }

    function addItemRow() {
        const container = document.getElementById('itemsContainer');
        const clone = container.querySelector('.item-row').cloneNode(true);

        // Reset values
        clone.querySelector('select.item-type-select').value = 'Product';

        // Reset selector HTML
        const selContainer = clone.querySelector('.item-selector-container');
        let html = `<select name="item_id[]" class="w-full px-3 py-2 border rounded-md focus:outline-none focus:ring-2 focus:ring-green-500 item-select" onchange="updatePrice(this)"><option value="">Select Product...</option>`;
        productsData.forEach(item => {
            html += `<option value="${item.id}" data-price="${item.price}" data-name="${item.name}">${item.name} (MK ${parseFloat(item.price).toLocaleString()})</option>`;
        });
        html += `</select><input type="hidden" name="item_desc[]" class="item-desc-hidden">`;
        selContainer.innerHTML = html;

        clone.querySelector('.item-qty').value = '1';
        clone.querySelector('.item-price').value = '0.00';

        container.appendChild(clone);
    }

    function removeItemRow(btn) {
        const rows = document.querySelectorAll('.item-row');
        if (rows.length > 1) {
            btn.closest('.item-row').remove();
            calculateGrandTotal();
        } else {
            alert('At least one item is required.');
        }
    }

    // Initialize
    toggleMode();
    toggleTransactionId();
    document.getElementById('payment_amount').addEventListener('input', syncGrRequirement);
    document.getElementById('saleForm').addEventListener('submit', function (e) {
        syncGrRequirement();
        const grInput = document.getElementById('gr_number');
        if (grInput.required && !grInput.value.trim()) {
            e.preventDefault();
            alert('General Receipt (GR) number is required for every payment transaction.');
            grInput.focus();
        }
    });
</script>

<?php include '../../includes/footer.php'; ?>