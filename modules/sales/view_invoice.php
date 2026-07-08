<?php
require_once __DIR__ . '/../../config/app.php';
checkAuth();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/permissions_helper.php';
permissions_require_one_of(['view_sales', 'view_invoices', 'view_dashboard_revenue']);
require_once __DIR__ . '/../../libs/InvoicePaymentGrMigrator.php';

InvoicePaymentGrMigrator::ensure($pdo);

if (!isset($_GET['id'])) {
    die("Invoice ID is required.");
}

$id = $_GET['id'];

// Get Invoice Details
$stmt = $pdo->prepare("
    SELECT i.*, e.estimation_number 
    FROM invoices i 
    LEFT JOIN estimations e ON i.estimation_id = e.id 
    WHERE i.id = ?
");
$stmt->execute([$id]);
$invoice = $stmt->fetch();

if (!$invoice) {
    die("Invoice not found.");
}

// Get Items
$items = $pdo->prepare("SELECT * FROM invoice_items WHERE invoice_id = ?");
$items->execute([$id]);
$invoice_items = $items->fetchAll();

// Get Payments
$payments = $pdo->prepare("
    SELECT p.*, u.name as recorder_name,
           ii.description AS item_description, ii.item_type AS item_type
    FROM invoice_payments p 
    LEFT JOIN users u ON p.recorded_by = u.id 
    LEFT JOIN invoice_items ii ON p.invoice_item_id = ii.id
    WHERE p.invoice_id = ? 
    ORDER BY p.payment_date DESC, p.id DESC
");
$payments->execute([$id]);
$payment_history = $payments->fetchAll();

include '../../includes/header.php';
?>

<?php if (isset($_GET['msg'])): ?>
    <div class="mb-4 bg-green-100 border border-green-400 text-green-800 px-4 py-3 rounded-lg flex items-center">
        <i class="material-icons mr-2 text-green-600">check_circle</i>
        <?php echo htmlspecialchars($_GET['msg']); ?>
    </div>
<?php endif; ?>


<div class="flex justify-between items-start mb-6">
    <div>
        <a href="index.php" class="text-green-600 hover:underline flex items-center mb-2">
            <i class="material-icons text-sm mr-1">arrow_back</i> Back to Sales
        </a>
        <h1 class="text-3xl font-bold text-gray-800">Invoice #
            <?php echo htmlspecialchars($invoice['invoice_number']); ?>
        </h1>
        <p class="text-gray-500">Generated on
            <?php echo date('M d, Y', strtotime($invoice['generated_date'])); ?>
        </p>
    </div>
    <div class="space-x-2">
        <?php if ($invoice['balance'] > 0): ?>
            <a href="record_sale.php?invoice_id=<?php echo $invoice['id']; ?>"
                class="bg-green-600 text-white px-4 py-2 rounded shadow hover:bg-green-700 transition">
                <i class="material-icons align-middle text-sm">payment</i> Pay Balance
            </a>
        <?php endif; ?>
        <a href="../invoices/pdf.php?id=<?php echo $invoice['id']; ?>" target="_blank"
            class="bg-gray-100 text-gray-700 px-4 py-2 rounded shadow hover:bg-gray-200 transition">
            <i class="material-icons align-middle text-sm">picture_as_pdf</i> Export PDF
        </a>
    </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-8">

    <!-- Summary and Items -->
    <div class="lg:col-span-2 space-y-8">

        <!-- Info Card -->
        <div class="bg-white shadow rounded-lg p-6">
            <h2 class="text-xl font-bold text-gray-800 mb-4 border-b pb-2">Customer Information</h2>
            <div class="grid grid-cols-2 gap-4 text-sm">
                <div>
                    <p class="text-gray-500 uppercase font-semibold">Name</p>
                    <p class="text-gray-900 font-medium">
                        <?php echo htmlspecialchars($invoice['customer_name'] ?: 'N/A'); ?>
                    </p>
                </div>
                <div>
                    <p class="text-gray-500 uppercase font-semibold">Phone</p>
                    <p class="text-gray-900 font-medium">
                        <?php echo htmlspecialchars($invoice['customer_phone'] ?: 'N/A'); ?>
                    </p>
                </div>
                <div>
                    <p class="text-gray-500 uppercase font-semibold">Email</p>
                    <p class="text-gray-900 font-medium">
                        <?php echo htmlspecialchars($invoice['customer_email'] ?: 'N/A'); ?>
                    </p>
                </div>
                <div>
                    <p class="text-gray-500 uppercase font-semibold">Address</p>
                    <p class="text-gray-900 font-medium">
                        <?php echo nl2br(htmlspecialchars($invoice['customer_address'] ?: 'N/A')); ?>
                    </p>
                </div>
            </div>
        </div>

        <!-- Items Card -->
        <div class="bg-white shadow rounded-lg overflow-hidden">
            <div class="p-6 border-b">
                <h2 class="text-xl font-bold text-gray-800">Invoice Items</h2>
            </div>
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Item</th>
                        <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase">Qty</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Unit Price</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Total</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                    <?php if (empty($invoice_items) && $invoice['estimation_id']): ?>
                        <tr>
                            <td colspan="4" class="px-6 py-4 text-center text-gray-500 italic">
                                This invoice was generated from Estimation #
                                <?php echo htmlspecialchars($invoice['estimation_number']); ?>.
                                <br>Individual items are managed in the estimations module.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($invoice_items as $item): ?>
                            <tr>
                                <td class="px-6 py-4">
                                    <span class="text-xs text-gray-400 block uppercase font-bold">
                                        <?php echo $item['item_type']; ?>
                                    </span>
                                    <span class="font-medium">
                                        <?php echo htmlspecialchars($item['description']); ?>
                                    </span>
                                </td>
                                <td class="px-6 py-4 text-center">
                                    <?php echo number_format($item['quantity'], 2); ?>
                                </td>
                                <td class="px-6 py-4 text-right">MK
                                    <?php echo number_format($item['unit_price'], 2); ?>
                                </td>
                                <td class="px-6 py-4 text-right font-semibold text-gray-900">MK
                                    <?php echo number_format($item['total_price'], 2); ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($invoice_items)): ?>
                            <tr>
                                <td colspan="4" class="px-6 py-4 text-center text-gray-500">No specific line items found.</td>
                            </tr>
                        <?php endif; ?>
                    <?php endif; ?>
                </tbody>
                <tfoot class="bg-gray-50 font-medium">
                    <tr>
                        <td colspan="3" class="px-6 py-3 text-right text-gray-500">Subtotal</td>
                        <td class="px-6 py-3 text-right">MK
                            <?php
                            $displaySubtotal = ($invoice['subtotal'] > 0) ? $invoice['subtotal'] : $invoice['total_amount'];
                            echo number_format($displaySubtotal, 2);
                            ?>
                        </td>
                    </tr>
                    <tr>
                        <td colspan="3" class="px-6 py-3 text-right text-gray-500">Tax</td>
                        <td class="px-6 py-3 text-right">MK
                            <?php echo number_format($invoice['tax_amount'], 2); ?>
                        </td>
                    </tr>
                    <tr>
                        <td colspan="3" class="px-6 py-3 text-right text-gray-500">Discount</td>
                        <td class="px-6 py-3 text-right text-red-600">- MK
                            <?php echo number_format($invoice['discount'], 2); ?>
                        </td>
                    </tr>
                    <tr class="text-lg text-gray-900 border-t-2">
                        <td colspan="3" class="px-6 py-4 text-right font-bold">Total Amount</td>
                        <td class="px-6 py-4 text-right font-bold text-green-700">MK
                            <?php echo number_format($invoice['total_amount'], 2); ?>
                        </td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>

    <!-- Payment History and Sidebar -->
    <div class="space-y-8">

        <!-- Status Card -->
        <div class="bg-white shadow rounded-lg p-6">
            <h2 class="text-xl font-bold text-gray-800 mb-4 border-b pb-2">Financial Status</h2>
            <div class="space-y-4">
                <div class="flex justify-between items-center">
                    <span class="text-gray-500">Current Status:</span>
                    <?php
                    $statusColor = 'bg-gray-100 text-gray-800';
                    if ($invoice['status'] == 'Paid')
                        $statusColor = 'bg-green-100 text-green-800';
                    elseif ($invoice['status'] == 'Partially Paid')
                        $statusColor = 'bg-yellow-100 text-yellow-800';
                    elseif ($invoice['status'] == 'Overdue')
                        $statusColor = 'bg-red-100 text-red-800';
                    ?>
                    <span class="px-3 py-1 font-bold rounded-full text-xs <?php echo $statusColor; ?>">
                        <?php echo strtoupper($invoice['status']); ?>
                    </span>
                </div>
                <div class="bg-gray-50 p-4 rounded-lg">
                    <div class="flex justify-between mb-1">
                        <span class="text-xs text-gray-500 uppercase font-bold tracking-wider">Paid Amount</span>
                        <span class="text-sm font-bold text-green-600">MK
                            <?php echo number_format($invoice['paid_amount'], 2); ?>
                        </span>
                    </div>
                    <div class="w-full bg-gray-200 rounded-full h-2">
                        <?php
                        $percent = $invoice['total_amount'] > 0 ? ($invoice['paid_amount'] / $invoice['total_amount']) * 100 : 0;
                        if ($percent > 100)
                            $percent = 100;
                        ?>
                        <div class="bg-green-600 h-2 rounded-full" style="width: <?php echo $percent; ?>%"></div>
                    </div>
                    <div class="flex justify-between mt-2">
                        <span class="text-xs text-gray-500 uppercase font-bold tracking-wider">Remaining Balance</span>
                        <span class="text-sm font-bold text-red-600">MK
                            <?php echo number_format($invoice['balance'], 2); ?>
                        </span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Payments Log -->
        <div class="bg-white shadow rounded-lg overflow-hidden">
            <div class="p-6 border-b flex justify-between items-center">
                <h2 class="text-xl font-bold text-gray-800">Payment Settlements</h2>
                <?php if ($invoice['balance'] > 0): ?>
                    <button onclick="document.getElementById('paymentModal').classList.remove('hidden')"
                        class="flex items-center bg-green-600 text-white text-sm font-semibold px-4 py-2 rounded-lg hover:bg-green-700 transition shadow">
                        <i class="material-icons text-sm mr-1">add_card</i> Record Payment
                    </button>
                <?php endif; ?>
            </div>
            <div class="p-6">
                <?php if (empty($payment_history)): ?>
                    <p class="text-center text-gray-500 text-sm italic">No payments recorded yet.</p>
                <?php else: ?>
                    <div class="flow-root">
                        <ul role="list" class="-mb-8">
                            <?php foreach ($payment_history as $idx => $pay): ?>
                                <li>
                                    <div class="relative pb-8">
                                        <?php if ($idx < count($payment_history) - 1): ?>
                                            <span class="absolute top-4 left-4 -ml-px h-full w-0.5 bg-gray-200"
                                                aria-hidden="true"></span>
                                        <?php endif; ?>
                                        <div class="relative flex space-x-3">
                                            <div>
                                                <span
                                                    class="h-8 w-8 rounded-full bg-green-500 flex items-center justify-center ring-8 ring-white">
                                                    <i class="material-icons text-white text-sm">check</i>
                                                </span>
                                            </div>
                                            <div class="min-w-0 flex-1 pt-1.5 flex justify-between space-x-4">
                                                <div>
                                                    <p class="text-sm text-gray-900 font-bold">MK
                                                        <?php echo number_format($pay['amount'], 2); ?>
                                                    </p>
                                                    <?php if (!empty($pay['gr_number'])): ?>
                                                        <p class="text-xs font-semibold text-indigo-700">GR:
                                                            <?php echo htmlspecialchars($pay['gr_number']); ?>
                                                        </p>
                                                    <?php endif; ?>
                                                    <p class="text-xs text-gray-500">via
                                                        <?php echo $pay['payment_method']; ?>
                                                    </p>
                                                    <?php if (!empty($pay['item_description'])): ?>
                                                        <p class="text-xs text-gray-600">For:
                                                            <?php echo htmlspecialchars($pay['item_type'] . ' — ' . $pay['item_description']); ?>
                                                        </p>
                                                    <?php endif; ?>
                                                    <?php if ($pay['transaction_id']): ?>
                                                        <p class="text-xs text-gray-400 italic">TXID:
                                                            <?php echo htmlspecialchars($pay['transaction_id']); ?>
                                                        </p>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="text-right text-xs whitespace-nowrap text-gray-500">
                                                    <time datetime="<?php echo $pay['payment_date']; ?>">
                                                        <?php echo date('M d, Y', strtotime($pay['payment_date'])); ?>
                                                    </time>
                                                    <br>
                                                    <span>by
                                                        <?php echo htmlspecialchars($pay['recorder_name']); ?>
                                                    </span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>
            </div>
        </div>

    </div>
</div>

<?php include '../../includes/footer.php'; ?>

<!-- ===================== Record Payment Modal ===================== -->
<?php if ($invoice['balance'] > 0): ?>
    <div id="paymentModal" class="hidden fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center p-4">
        <div class="bg-white rounded-xl shadow-2xl w-full max-w-md">
            <div class="flex justify-between items-center p-6 border-b">
                <h3 class="text-xl font-bold text-gray-800 flex items-center">
                    <i class="material-icons text-green-600 mr-2">add_card</i> Record Payment
                </h3>
                <button onclick="document.getElementById('paymentModal').classList.add('hidden')"
                    class="text-gray-400 hover:text-gray-700">
                    <i class="material-icons">close</i>
                </button>
            </div>
            <form action="process_sale" method="POST" onsubmit="return validatePaymentForm()">
                <input type="hidden" name="mode" value="invoice">
                <input type="hidden" name="invoice_id" value="<?php echo $invoice['id']; ?>">
                <div class="p-6 space-y-5">

                    <!-- Balance hint -->
                    <div class="bg-blue-50 border border-blue-200 rounded-lg p-3 text-sm">
                        <p class="text-gray-600">Outstanding Balance:
                            <span class="font-bold text-red-600">
                                MK <?php echo number_format($invoice['balance'], 2); ?>
                            </span>
                        </p>
                    </div>

                    <!-- GR Number -->
                    <div>
                        <label class="block text-gray-700 font-semibold mb-2">General Receipt (GR) Number *</label>
                        <input type="text" name="gr_number" id="pm_gr_number" required maxlength="50"
                            placeholder="e.g. 7855123"
                            class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500 font-mono">
                    </div>

                    <?php if (!empty($invoice_items)): ?>
                    <div>
                        <label class="block text-gray-700 font-semibold mb-2">Product / Service (optional)</label>
                        <select name="invoice_item_id" id="pm_invoice_item_id"
                            class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500 bg-white">
                            <option value="">— Whole invoice / not item-specific —</option>
                            <?php foreach ($invoice_items as $item): ?>
                                <option value="<?php echo (int) $item['id']; ?>">
                                    [<?php echo htmlspecialchars($item['item_type']); ?>]
                                    <?php echo htmlspecialchars($item['description']); ?>
                                    (MK <?php echo number_format($item['total_price'], 2); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>

                    <!-- Amount -->
                    <div>
                        <label class="block text-gray-700 font-semibold mb-2">Payment Amount (MK) *</label>
                        <input type="number" name="amount" id="pm_amount" step="0.01" min="0.01"
                            value="<?php echo number_format($invoice['balance'], 2, '.', ''); ?>" required
                            class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500 text-lg font-bold">
                    </div>

                    <!-- Payment Method -->
                    <div>
                        <label class="block text-gray-700 font-semibold mb-2">Payment Method *</label>
                        <select name="payment_method" id="pm_method" required onchange="toggleTxId()"
                            class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500 bg-white">
                            <option value="Cash">Cash</option>
                            <option value="Bank Transfer">Bank Transfer</option>
                            <option value="Cheque">Cheque</option>
                        </select>
                    </div>

                    <!-- Transaction ID (hidden by default) -->
                    <div id="pm_txid_row" class="hidden">
                        <label class="block text-gray-700 font-semibold mb-2">Transaction / Cheque ID *</label>
                        <input type="text" name="transaction_id" id="pm_txid"
                            class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500">
                    </div>
                </div>

                <div class="flex justify-end gap-3 p-6 border-t">
                    <button type="button" onclick="document.getElementById('paymentModal').classList.add('hidden')"
                        class="bg-gray-100 text-gray-700 px-5 py-2 rounded-lg hover:bg-gray-200 transition font-semibold">
                        Cancel
                    </button>
                    <button type="submit"
                        class="bg-green-600 text-white px-6 py-2 rounded-lg hover:bg-green-700 transition font-semibold shadow flex items-center">
                        <i class="material-icons text-sm mr-1">save</i> Save Payment
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function toggleTxId() {
            const method = document.getElementById('pm_method').value;
            const txRow = document.getElementById('pm_txid_row');
            const txInput = document.getElementById('pm_txid');
            if (method === 'Bank Transfer' || method === 'Cheque') {
                txRow.classList.remove('hidden');
                txInput.required = true;
            } else {
                txRow.classList.add('hidden');
                txInput.required = false;
                txInput.value = '';
            }
        }

        function validatePaymentForm() {
            const amount = parseFloat(document.getElementById('pm_amount').value);
            const balance = <?php echo floatval($invoice['balance']); ?>;
            if (amount <= 0) { alert('Please enter a valid payment amount.'); return false; }
            if (amount > balance) {
                return confirm(`Warning: The amount (MK ${amount.toLocaleString()}) exceeds the current balance (MK ${balance.toLocaleString()}). Continue anyway?`);
            }
            return true;
        }

        // Close modal when clicking outside
        document.getElementById('paymentModal').addEventListener('click', function (e) {
            if (e.target === this) this.classList.add('hidden');
        });
    </script>
<?php endif; ?>