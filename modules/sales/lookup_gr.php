<?php
require_once __DIR__ . '/../../config/app.php';
checkAuth();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../libs/InvoicePaymentGrMigrator.php';

if ($_SESSION['role'] != 'System Admin' && $_SESSION['role'] != 'Costing') {
    die('Access Denied.');
}

InvoicePaymentGrMigrator::ensure($pdo);

$query = trim((string) ($_GET['q'] ?? ''));
$result = null;
$relatedPayments = [];

if ($query !== '') {
    $stmt = $pdo->prepare("
        SELECT p.*,
               u.name AS recorder_name,
               i.id AS invoice_id,
               i.invoice_number,
               i.customer_name,
               i.customer_phone,
               i.total_amount AS invoice_total,
               i.status AS invoice_status,
               e.estimation_number,
               ii.description AS item_description,
               ii.item_type AS item_type_label,
               ii.quantity AS item_quantity,
               ii.unit_price AS item_unit_price,
               ii.total_price AS item_total_price
        FROM invoice_payments p
        INNER JOIN invoices i ON p.invoice_id = i.id
        LEFT JOIN users u ON p.recorded_by = u.id
        LEFT JOIN estimations e ON i.estimation_id = e.id
        LEFT JOIN invoice_items ii ON p.invoice_item_id = ii.id
        WHERE p.gr_number = ?
        LIMIT 1
    ");
    $stmt->execute([$query]);
    $result = $stmt->fetch();

    if ($result) {
        $relatedStmt = $pdo->prepare("
            SELECT p.gr_number, p.amount, p.payment_date, p.payment_method,
                   ii.description AS item_description, ii.item_type AS item_type_label
            FROM invoice_payments p
            LEFT JOIN invoice_items ii ON p.invoice_item_id = ii.id
            WHERE p.invoice_id = ?
            ORDER BY p.payment_date DESC, p.id DESC
        ");
        $relatedStmt->execute([(int) $result['invoice_id']]);
        $relatedPayments = $relatedStmt->fetchAll();
    }
}

include '../../includes/header.php';
?>

<div class="mb-6">
    <a href="index.php" class="text-green-600 hover:underline flex items-center">
        <i class="material-icons text-sm mr-1">arrow_back</i> Back to Sales
    </a>
    <h1 class="text-3xl font-bold text-gray-800 mt-2">General Receipt (GR) Lookup</h1>
    <p class="text-sm text-gray-500 mt-1">Search by GR number to trace a payment to its invoice, product/service, and transaction details.</p>
</div>

<div class="bg-white shadow rounded-lg p-6 max-w-3xl mx-auto mb-8">
    <form method="GET" action="lookup_gr.php" class="flex flex-col sm:flex-row gap-3">
        <div class="flex-1">
            <label for="q" class="block text-gray-700 font-semibold mb-2">GR Number</label>
            <input type="text" name="q" id="q" value="<?php echo htmlspecialchars($query); ?>" required maxlength="50"
                placeholder="Enter the General Receipt number"
                class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500 font-mono text-lg">
        </div>
        <div class="sm:self-end">
            <button type="submit"
                class="w-full sm:w-auto bg-green-600 hover:bg-green-700 text-white font-bold py-3 px-6 rounded-lg shadow transition flex items-center justify-center">
                <i class="material-icons mr-2">search</i> Lookup
            </button>
        </div>
    </form>
</div>

<?php if ($query !== ''): ?>
    <?php if (!$result): ?>
        <div class="bg-yellow-50 border border-yellow-200 text-yellow-900 rounded-lg p-6 max-w-3xl mx-auto">
            <p class="font-semibold">No payment found for GR number <span class="font-mono"><?php echo htmlspecialchars($query); ?></span>.</p>
            <p class="text-sm mt-2">Check the number and try again, or confirm the payment was recorded in the sales module.</p>
        </div>
    <?php else: ?>
        <div class="max-w-5xl mx-auto space-y-8">
            <div class="bg-white shadow rounded-lg p-6 border-l-4 border-indigo-500">
                <div class="flex flex-col md:flex-row md:items-start md:justify-between gap-4">
                    <div>
                        <p class="text-xs uppercase tracking-wider text-gray-500 font-bold">General Receipt</p>
                        <p class="text-3xl font-mono font-bold text-indigo-700 mt-1"><?php echo htmlspecialchars($result['gr_number']); ?></p>
                    </div>
                    <div class="text-right">
                        <p class="text-2xl font-bold text-green-700">MK <?php echo number_format((float) $result['amount'], 2); ?></p>
                        <p class="text-sm text-gray-500"><?php echo htmlspecialchars($result['payment_method']); ?> · <?php echo date('M d, Y', strtotime($result['payment_date'])); ?></p>
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
                <div class="bg-white shadow rounded-lg p-6">
                    <h2 class="text-xl font-bold text-gray-800 mb-4 border-b pb-2">Transaction Details</h2>
                    <dl class="space-y-3 text-sm">
                        <div class="flex justify-between gap-4">
                            <dt class="text-gray-500">Payment ID</dt>
                            <dd class="font-medium text-gray-900">#<?php echo (int) $result['id']; ?></dd>
                        </div>
                        <div class="flex justify-between gap-4">
                            <dt class="text-gray-500">Recorded by</dt>
                            <dd class="font-medium text-gray-900"><?php echo htmlspecialchars($result['recorder_name'] ?: 'System'); ?></dd>
                        </div>
                        <div class="flex justify-between gap-4">
                            <dt class="text-gray-500">Recorded on</dt>
                            <dd class="font-medium text-gray-900"><?php echo date('M d, Y H:i', strtotime($result['created_at'])); ?></dd>
                        </div>
                        <?php if (!empty($result['transaction_id'])): ?>
                        <div class="flex justify-between gap-4">
                            <dt class="text-gray-500">Bank / cheque reference</dt>
                            <dd class="font-medium text-gray-900"><?php echo htmlspecialchars($result['transaction_id']); ?></dd>
                        </div>
                        <?php endif; ?>
                    </dl>
                </div>

                <div class="bg-white shadow rounded-lg p-6">
                    <h2 class="text-xl font-bold text-gray-800 mb-4 border-b pb-2">Invoice &amp; Customer</h2>
                    <dl class="space-y-3 text-sm">
                        <div class="flex justify-between gap-4">
                            <dt class="text-gray-500">Invoice</dt>
                            <dd class="font-medium">
                                <a href="view_invoice.php?id=<?php echo (int) $result['invoice_id']; ?>" class="text-indigo-600 hover:underline">
                                    <?php echo htmlspecialchars($result['invoice_number']); ?>
                                </a>
                            </dd>
                        </div>
                        <div class="flex justify-between gap-4">
                            <dt class="text-gray-500">Customer</dt>
                            <dd class="font-medium text-gray-900"><?php echo htmlspecialchars($result['customer_name'] ?: ('Estimation #' . ($result['estimation_number'] ?: 'N/A'))); ?></dd>
                        </div>
                        <?php if (!empty($result['customer_phone'])): ?>
                        <div class="flex justify-between gap-4">
                            <dt class="text-gray-500">Phone</dt>
                            <dd class="font-medium text-gray-900"><?php echo htmlspecialchars($result['customer_phone']); ?></dd>
                        </div>
                        <?php endif; ?>
                        <div class="flex justify-between gap-4">
                            <dt class="text-gray-500">Invoice total</dt>
                            <dd class="font-medium text-gray-900">MK <?php echo number_format((float) $result['invoice_total'], 2); ?></dd>
                        </div>
                        <div class="flex justify-between gap-4">
                            <dt class="text-gray-500">Invoice status</dt>
                            <dd class="font-medium text-gray-900"><?php echo htmlspecialchars($result['invoice_status']); ?></dd>
                        </div>
                    </dl>
                </div>
            </div>

            <div class="bg-white shadow rounded-lg p-6">
                <h2 class="text-xl font-bold text-gray-800 mb-4 border-b pb-2">Product / Service Covered</h2>
                <?php if (!empty($result['item_description'])): ?>
                    <div class="bg-gray-50 rounded-lg p-4">
                        <p class="text-xs uppercase font-bold text-gray-500"><?php echo htmlspecialchars($result['item_type_label']); ?></p>
                        <p class="text-lg font-semibold text-gray-900 mt-1"><?php echo htmlspecialchars($result['item_description']); ?></p>
                        <p class="text-sm text-gray-600 mt-2">
                            Qty: <?php echo number_format((float) $result['item_quantity'], 2); ?>
                            · Unit: MK <?php echo number_format((float) $result['item_unit_price'], 2); ?>
                            · Line total: MK <?php echo number_format((float) $result['item_total_price'], 2); ?>
                        </p>
                    </div>
                <?php else: ?>
                    <p class="text-gray-600 text-sm">This payment applies to the invoice as a whole (not linked to a specific line item).</p>
                <?php endif; ?>
            </div>

            <?php if (count($relatedPayments) > 1): ?>
            <div class="bg-white shadow rounded-lg overflow-hidden">
                <div class="p-6 border-b">
                    <h2 class="text-xl font-bold text-gray-800">All Payments on This Invoice</h2>
                    <p class="text-sm text-gray-500 mt-1">Each payment has its own unique GR number.</p>
                </div>
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">GR Number</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Product / Service</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Amount</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <?php foreach ($relatedPayments as $pay): ?>
                            <tr class="<?php echo ($pay['gr_number'] === $result['gr_number']) ? 'bg-indigo-50' : ''; ?>">
                                <td class="px-6 py-3 font-mono text-sm font-semibold text-indigo-700"><?php echo htmlspecialchars($pay['gr_number'] ?: '—'); ?></td>
                                <td class="px-6 py-3 text-sm text-gray-700"><?php echo date('M d, Y', strtotime($pay['payment_date'])); ?></td>
                                <td class="px-6 py-3 text-sm text-gray-700"><?php echo !empty($pay['item_description']) ? htmlspecialchars($pay['item_type_label'] . ' — ' . $pay['item_description']) : '—'; ?></td>
                                <td class="px-6 py-3 text-sm font-semibold text-right">MK <?php echo number_format((float) $pay['amount'], 2); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
<?php endif; ?>

<?php include '../../includes/footer.php'; ?>
