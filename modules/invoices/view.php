<?php
/**
 * Invoice View Page
 *
 * Renders a complete HTML summary for an invoice: header card with the
 * audit notice, customer block, line items, totals (with VAT line), the
 * payment history, the linked estimation, and the four explicit actions
 * (Edit, Download PDF, Record Payment, Delete).
 *
 * This page never streams a PDF. Downloads only happen via the explicit
 * Download action which routes to `download?id=X`.
 */

require_once __DIR__ . '/../../config/app.php';
checkAuth();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../libs/InvoiceAuditMigrator.php';
require_once __DIR__ . '/../../libs/InvoicePaymentGrMigrator.php';

InvoiceAuditMigrator::ensure($pdo);
InvoicePaymentGrMigrator::ensure($pdo);

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    redirect('modules/invoices/list');
}

$stmt = $pdo->prepare("
    SELECT i.*,
           e.id              AS est_id,
           e.estimation_number,
           e.status          AS est_status,
           e.pre_vat_total   AS est_pre_vat_total,
           e.vat_percent     AS est_vat_percent,
           uc.name           AS created_by_name,
           ue.name           AS last_edited_by_name
    FROM invoices i
    LEFT JOIN estimations e ON i.estimation_id = e.id
    LEFT JOIN users uc      ON i.created_by    = uc.id
    LEFT JOIN users ue      ON i.last_edited_by = ue.id
    WHERE i.id = :id
");
$stmt->execute(['id' => $id]);
$invoice = $stmt->fetch();

if (!$invoice) {
    http_response_code(404);
    die('Invoice not found.');
}

// Best-effort customer fallback when the invoice predates the
// customer_* columns being copied directly onto the row.
if (empty($invoice['customer_name'])) {
    $custStmt = $pdo->prepare("SELECT customer_name, customer_email, customer_phone FROM estimations WHERE id = ?");
    $custStmt->execute([$invoice['estimation_id']]);
    $cust = $custStmt->fetch();
    if ($cust) {
        $invoice['customer_name']  = $invoice['customer_name']  ?: $cust['customer_name'];
        $invoice['customer_email'] = $invoice['customer_email'] ?: $cust['customer_email'];
        $invoice['customer_phone'] = $invoice['customer_phone'] ?: $cust['customer_phone'];
    }
}

$items = json_decode((string) ($invoice['items_json'] ?? '[]'), true);
if (!is_array($items)) {
    $items = [];
}

$paymentsStmt = $pdo->prepare("
    SELECT p.*, u.name AS recorded_by_name,
           ii.description AS item_description, ii.item_type AS item_type_label
    FROM invoice_payments p
    LEFT JOIN users u ON p.recorded_by = u.id
    LEFT JOIN invoice_items ii ON p.invoice_item_id = ii.id
    WHERE p.invoice_id = :id
    ORDER BY p.payment_date DESC, p.id DESC
");
$paymentsStmt->execute(['id' => $id]);
$payments = $paymentsStmt->fetchAll();
$paymentCount = count($payments);

// Compute / coalesce derived fields so older rows still render sensibly.
$subtotal   = (float) ($invoice['subtotal']     ?? 0);
$vatAmount  = (float) ($invoice['tax_amount']   ?? 0);
$shipping   = (float) ($invoice['shipping_fee'] ?? 0);
$total      = (float) ($invoice['total_amount'] ?? 0);
$paid       = (float) ($invoice['paid_amount']  ?? 0);
$balance    = (float) ($invoice['balance']      ?? max(0, $total - $paid));

if ($subtotal <= 0 && !empty($items)) {
    $subtotal = array_sum(array_map(fn($r) => (float) ($r['total_price'] ?? $r['unit_price'] ?? $r['price'] ?? 0), $items));
}

$vatPercent = isset($invoice['vat_percent']) && $invoice['vat_percent'] !== null
    ? (float) $invoice['vat_percent']
    : ($subtotal > 0 ? round(($vatAmount / $subtotal) * 100, 2) : 0);

$lastEditedAt = $invoice['last_edited_at'] ?? $invoice['generated_date'] ?? null;
$lastEditedBy = $invoice['last_edited_by_name'] ?? $invoice['created_by_name'] ?? 'System';

$displayStatus = (string) ($invoice['status'] ?? '');
$isPastDue = !empty($invoice['due_date'])
    && strtotime((string) $invoice['due_date']) < strtotime(date('Y-m-d'))
    && in_array($displayStatus, ['Unpaid', 'Partially Paid'], true)
    && $balance > 0;
if ($isPastDue) {
    $displayStatus = 'Overdue';
}

$statusBadgeMap = [
    'Paid'           => 'bg-green-100 text-green-800',
    'Partially Paid' => 'bg-yellow-100 text-yellow-800',
    'Cancelled'      => 'bg-gray-200 text-gray-800',
    'Overdue'        => 'bg-red-100 text-red-800',
];
$statusBadgeClass = $statusBadgeMap[$displayStatus] ?? 'bg-red-100 text-red-800';

include '../../includes/header.php';

$flashSuccess = $_SESSION['success'] ?? null;
$flashError   = $_SESSION['error']   ?? null;
unset($_SESSION['success'], $_SESSION['error']);
?>

<div class="mb-6">
    <a href="list" class="text-blue-600 hover:underline inline-flex items-center text-sm">
        <i data-lucide="arrow-left" class="mr-1 inline-block h-4 w-4 flex-shrink-0" aria-hidden="true"></i>
        Back to invoices
    </a>
</div>

<?php if ($flashSuccess): ?>
    <div class="bg-green-50 border border-green-200 text-green-800 rounded-lg p-4 mb-4">
        <?php echo htmlspecialchars((string) $flashSuccess); ?>
    </div>
<?php endif; ?>
<?php if ($flashError): ?>
    <div class="bg-red-50 border border-red-200 text-red-800 rounded-lg p-4 mb-4">
        <?php echo htmlspecialchars((string) $flashError); ?>
    </div>
<?php endif; ?>

<div class="bg-white shadow rounded-xl p-6 mb-6">
    <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
        <div class="min-w-0">
            <h1 class="text-3xl font-bold text-gray-800 break-words">
                Invoice <?php echo htmlspecialchars($invoice['invoice_number']); ?>
            </h1>
            <div class="flex flex-wrap items-center gap-3 mt-3">
                <span class="px-2 py-1 text-xs leading-5 font-semibold rounded-full <?php echo $statusBadgeClass; ?>">
                    <?php echo htmlspecialchars($displayStatus); ?>
                </span>
                <span class="text-sm text-gray-500">
                    Total <strong class="text-gray-800">MK <?php echo number_format($total, 2); ?></strong>
                </span>
                <span class="text-sm text-gray-500">
                    Balance <strong class="<?php echo $balance > 0 ? 'text-red-600' : 'text-green-600'; ?>">MK <?php echo number_format($balance, 2); ?></strong>
                </span>
                <span class="text-sm text-gray-500">
                    Generated <?php echo date('M j, Y', strtotime($invoice['generated_date'])); ?>
                    <?php if (!empty($invoice['created_by_name'])): ?>
                        by <strong class="text-gray-800"><?php echo htmlspecialchars($invoice['created_by_name']); ?></strong>
                    <?php endif; ?>
                </span>
                <?php if (!empty($invoice['due_date'])): ?>
                    <span class="text-sm text-gray-500">
                        Due <strong class="text-gray-800"><?php echo date('M j, Y', strtotime($invoice['due_date'])); ?></strong>
                    </span>
                <?php endif; ?>
            </div>
            <?php if ($lastEditedAt): ?>
                <p class="mt-3 text-sm text-gray-600">
                    <i data-lucide="history" class="inline-block h-4 w-4 mr-1 align-text-bottom text-gray-400" aria-hidden="true"></i>
                    Last edited on
                    <strong><?php echo date('M j, Y \a\t g:i A', strtotime($lastEditedAt)); ?></strong>
                    by <strong><?php echo htmlspecialchars($lastEditedBy); ?></strong>
                </p>
            <?php endif; ?>
        </div>

        <div class="flex flex-wrap gap-2">
            <?php if ($paymentCount === 0): ?>
                <a href="edit?id=<?php echo (int) $invoice['id']; ?>"
                    class="inline-flex items-center gap-1 bg-blue-600 text-white px-4 py-2 rounded-lg shadow hover:bg-blue-700 transition">
                    <i data-lucide="pencil" class="h-4 w-4" aria-hidden="true"></i> Edit
                </a>
            <?php else: ?>
                <span class="inline-flex items-center gap-1 bg-amber-50 text-amber-700 px-4 py-2 rounded-lg text-sm" title="Edits are locked once payments are recorded">
                    <i data-lucide="lock" class="h-4 w-4" aria-hidden="true"></i>
                    Edit locked (payments recorded)
                </span>
            <?php endif; ?>

            <a href="download?id=<?php echo (int) $invoice['id']; ?>"
                class="inline-flex items-center gap-1 bg-red-600 text-white px-4 py-2 rounded-lg shadow hover:bg-red-700 transition">
                <i data-lucide="download" class="h-4 w-4" aria-hidden="true"></i> Download PDF
            </a>

            <?php if ($paid > 0): ?>
                <a href="receipt?id=<?php echo (int) $invoice['id']; ?>"
                    class="inline-flex items-center gap-1 bg-slate-700 text-white px-4 py-2 rounded-lg shadow hover:bg-slate-800 transition">
                    <i data-lucide="receipt" class="h-4 w-4" aria-hidden="true"></i> Payment receipt PDF
                </a>
            <?php endif; ?>
            <a href="record_payment?id=<?php echo (int) $invoice['id']; ?>"
                class="inline-flex items-center gap-1 bg-green-600 text-white px-4 py-2 rounded-lg shadow hover:bg-green-700 transition">
                <i data-lucide="banknote" class="h-4 w-4" aria-hidden="true"></i> Record Payment
            </a>

            <?php if ($paymentCount === 0): ?>
                <button type="button" onclick="openDeleteModal()"
                    class="inline-flex items-center gap-1 bg-red-100 text-red-700 px-4 py-2 rounded-lg shadow hover:bg-red-200 transition">
                    <i data-lucide="trash-2" class="h-4 w-4" aria-hidden="true"></i> Delete
                </button>
            <?php else: ?>
                <span class="inline-flex items-center gap-1 bg-gray-100 text-gray-500 px-4 py-2 rounded-lg text-sm" title="Cannot delete an invoice with recorded payments">
                    <i data-lucide="lock" class="h-4 w-4" aria-hidden="true"></i>
                    Delete locked
                </span>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-6">
    <div class="bg-white shadow rounded-lg p-6">
        <h3 class="text-lg font-bold text-gray-700 mb-4 border-b pb-2">Bill to</h3>
        <dl class="space-y-2 text-sm">
            <div>
                <dt class="text-gray-500">Name</dt>
                <dd class="font-semibold text-gray-800"><?php echo htmlspecialchars($invoice['customer_name'] ?: '—'); ?></dd>
            </div>
            <div>
                <dt class="text-gray-500">Email</dt>
                <dd class="text-gray-800 break-all"><?php echo htmlspecialchars($invoice['customer_email'] ?: '—'); ?></dd>
            </div>
            <div>
                <dt class="text-gray-500">Phone</dt>
                <dd class="text-gray-800"><?php echo htmlspecialchars($invoice['customer_phone'] ?: '—'); ?></dd>
            </div>
            <?php if (!empty($invoice['customer_address'])): ?>
                <div>
                    <dt class="text-gray-500">Address</dt>
                    <dd class="text-gray-800 whitespace-pre-line"><?php echo htmlspecialchars($invoice['customer_address']); ?></dd>
                </div>
            <?php endif; ?>
        </dl>
    </div>

    <div class="bg-white shadow rounded-lg p-6 md:col-span-2">
        <h3 class="text-lg font-bold text-gray-700 mb-4 border-b pb-2">Source &amp; references</h3>
        <dl class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-sm">
            <div>
                <dt class="text-gray-500">Estimation</dt>
                <dd class="text-gray-800">
                    <?php if (!empty($invoice['estimation_number'])): ?>
                        <a href="<?php echo BASE_URL; ?>modules/estimations/view?id=<?php echo (int) $invoice['est_id']; ?>"
                            class="text-blue-600 hover:underline">
                            <?php echo htmlspecialchars($invoice['estimation_number']); ?>
                        </a>
                        <?php if (!empty($invoice['est_status'])): ?>
                            <span class="text-xs text-gray-500">(<?php echo htmlspecialchars($invoice['est_status']); ?>)</span>
                        <?php endif; ?>
                    <?php else: ?>
                        <span class="text-gray-400">Created without an estimation</span>
                    <?php endif; ?>
                </dd>
            </div>
            <div>
                <dt class="text-gray-500">Generated date</dt>
                <dd class="text-gray-800"><?php echo htmlspecialchars($invoice['generated_date']); ?></dd>
            </div>
            <div>
                <dt class="text-gray-500">Due date</dt>
                <dd class="text-gray-800"><?php echo htmlspecialchars($invoice['due_date'] ?? '—'); ?></dd>
            </div>
            <div>
                <dt class="text-gray-500">VAT rate applied</dt>
                <dd class="text-gray-800"><?php echo number_format($vatPercent, 2); ?>%</dd>
            </div>
        </dl>
    </div>
</div>

<div class="bg-white shadow rounded-lg p-6 mb-6">
    <h3 class="text-lg font-bold text-gray-700 mb-4">Line items</h3>
    <?php if (empty($items)): ?>
        <p class="text-sm text-gray-500 italic">No line items recorded.</p>
    <?php else: ?>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">#</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Description</th>
                        <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Amount</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    <?php foreach ($items as $i => $row):
                        $price = (float) ($row['total_price'] ?? $row['unit_price'] ?? $row['price'] ?? 0); ?>
                        <tr>
                            <td class="px-4 py-2 text-sm text-gray-500"><?php echo $i + 1; ?></td>
                            <td class="px-4 py-2 text-sm text-gray-800 whitespace-pre-line"><?php echo htmlspecialchars((string) ($row['description'] ?? '—')); ?></td>
                            <td class="px-4 py-2 text-sm font-semibold text-gray-900 text-right">MK <?php echo number_format($price, 2); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<div class="bg-white shadow rounded-lg p-6 mb-6">
    <h3 class="text-lg font-bold text-gray-700 mb-4">Totals</h3>
    <dl class="grid grid-cols-1 sm:grid-cols-2 gap-3 text-sm">
        <div class="flex items-center justify-between bg-gray-50 rounded-lg p-3">
            <dt class="text-gray-500">Subtotal</dt>
            <dd class="font-semibold text-gray-800">MK <?php echo number_format($subtotal, 2); ?></dd>
        </div>
        <div class="flex items-center justify-between bg-gray-50 rounded-lg p-3">
            <dt class="text-gray-500">VAT (<?php echo number_format($vatPercent, 2); ?>%)</dt>
            <dd class="font-semibold text-gray-800">MK <?php echo number_format($vatAmount, 2); ?></dd>
        </div>
        <?php if ($shipping > 0): ?>
            <div class="flex items-center justify-between bg-gray-50 rounded-lg p-3">
                <dt class="text-gray-500">Shipping</dt>
                <dd class="font-semibold text-gray-800">MK <?php echo number_format($shipping, 2); ?></dd>
            </div>
        <?php endif; ?>
        <div class="flex items-center justify-between bg-gray-50 rounded-lg p-3">
            <dt class="text-gray-500">Paid to date</dt>
            <dd class="font-semibold text-gray-800">MK <?php echo number_format($paid, 2); ?></dd>
        </div>
    </dl>

    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 mt-4">
        <div class="flex items-center justify-between bg-blue-600 text-white rounded-lg p-4">
            <span class="text-lg font-semibold">Grand Total</span>
            <span class="text-2xl font-bold">MK <?php echo number_format($total, 2); ?></span>
        </div>
        <div class="flex items-center justify-between rounded-lg p-4 <?php echo $balance > 0 ? 'bg-red-600 text-white' : 'bg-green-600 text-white'; ?>">
            <span class="text-lg font-semibold">Outstanding Balance</span>
            <span class="text-2xl font-bold">MK <?php echo number_format($balance, 2); ?></span>
        </div>
    </div>
</div>

<div class="bg-white shadow rounded-lg p-6 mb-6">
    <h3 class="text-lg font-bold text-gray-700 mb-4 flex items-center">
        <i data-lucide="banknote" class="mr-2 h-5 w-5 flex-shrink-0 text-green-600" aria-hidden="true"></i>
        Payment history
    </h3>
    <?php if (empty($payments)): ?>
        <p class="text-sm text-gray-500 italic">No payments recorded yet.</p>
    <?php else: ?>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">GR Number</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Method</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Reference</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Product / Service</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Recorded by</th>
                        <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Amount</th>
                        <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Receipt</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    <?php foreach ($payments as $p): ?>
                        <tr>
                            <td class="px-4 py-2 text-sm font-mono font-semibold text-indigo-700"><?php echo htmlspecialchars($p['gr_number'] ?: '—'); ?></td>
                            <td class="px-4 py-2 text-sm text-gray-700"><?php echo htmlspecialchars($p['payment_date']); ?></td>
                            <td class="px-4 py-2 text-sm text-gray-700"><?php echo htmlspecialchars($p['payment_method']); ?></td>
                            <td class="px-4 py-2 text-sm text-gray-700"><?php echo htmlspecialchars($p['transaction_id'] ?: '—'); ?></td>
                            <td class="px-4 py-2 text-sm text-gray-700"><?php echo !empty($p['item_description']) ? htmlspecialchars($p['item_type_label'] . ' — ' . $p['item_description']) : '—'; ?></td>
                            <td class="px-4 py-2 text-sm text-gray-700"><?php echo htmlspecialchars($p['recorded_by_name'] ?? 'System'); ?></td>
                            <td class="px-4 py-2 text-sm font-semibold text-gray-900 text-right">MK <?php echo number_format((float) $p['amount'], 2); ?></td>
                            <td class="px-4 py-2 text-right">
                                <a href="receipt?id=<?php echo (int) $invoice['id']; ?>&payment_id=<?php echo (int) $p['id']; ?>"
                                    class="text-blue-600 hover:underline text-sm font-medium">PDF</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<!-- Delete confirmation modal -->
<?php if ($paymentCount === 0): ?>
<div id="deleteModal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
    <div class="relative top-20 mx-auto p-6 border w-full max-w-md shadow-lg rounded-md bg-white">
        <div class="flex items-start justify-between mb-4">
            <div>
                <h3 class="text-lg font-bold text-gray-900">Delete invoice?</h3>
                <p class="text-sm text-gray-600 mt-1">
                    This permanently removes <strong><?php echo htmlspecialchars($invoice['invoice_number']); ?></strong>.
                    <?php if (!empty($invoice['estimation_number'])): ?>
                        The linked estimation <strong><?php echo htmlspecialchars($invoice['estimation_number']); ?></strong>
                        will be reopened (status reverted to <em>Approved</em>) so it can be re-invoiced.
                    <?php endif; ?>
                </p>
            </div>
            <button onclick="closeDeleteModal()" class="text-gray-400 hover:text-gray-600">
                <i data-lucide="x" class="h-5 w-5" aria-hidden="true"></i>
            </button>
        </div>
        <form method="POST" action="delete" class="flex justify-end gap-2 mt-6">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token('invoice_delete')); ?>">
            <input type="hidden" name="id" value="<?php echo (int) $invoice['id']; ?>">
            <button type="button" onclick="closeDeleteModal()"
                class="px-4 py-2 border border-gray-300 rounded-lg hover:bg-gray-50 transition">Cancel</button>
            <button type="submit"
                class="px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 transition flex items-center gap-1">
                <i data-lucide="trash-2" class="h-4 w-4" aria-hidden="true"></i> Delete permanently
            </button>
        </form>
    </div>
</div>
<?php endif; ?>

<script>
    function openDeleteModal() {
        const m = document.getElementById('deleteModal');
        if (!m) return;
        m.classList.remove('hidden');
        if (typeof window.refreshAppShellIcons === 'function') window.refreshAppShellIcons();
    }
    function closeDeleteModal() {
        const m = document.getElementById('deleteModal');
        if (!m) return;
        m.classList.add('hidden');
    }
    document.addEventListener('DOMContentLoaded', () => {
        const m = document.getElementById('deleteModal');
        if (m) {
            m.addEventListener('click', (e) => { if (e.target === m) closeDeleteModal(); });
        }
        if (typeof window.refreshAppShellIcons === 'function') window.refreshAppShellIcons();
    });
</script>

<?php include '../../includes/footer.php'; ?>
