<?php
/**
 * Invoices List
 *
 * Each row exposes four explicit actions: View, Download, Record Payment
 * and Delete. The View action opens the rich summary page; only the
 * Download action triggers a file download. Record Payment is hidden when
 * the balance is zero or the invoice is cancelled. Delete posts to the
 * extensionless `delete` route with a CSRF token so the .htaccess rewrite
 * preserves the POST body, and is disabled once any payment is on file.
 */

require_once __DIR__ . '/../../config/app.php';
checkAuth();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/permissions_helper.php';
permissions_require_one_of(['view_invoices']);
require_once __DIR__ . '/../../libs/InvoiceAuditMigrator.php';
require_once __DIR__ . '/../../includes/work_order_helper.php';

InvoiceAuditMigrator::ensure($pdo);
work_order_bootstrap($pdo);

include '../../includes/header.php';

// Surface flash messages set by save / delete / payment endpoints.
$flashSuccess = $_SESSION['success'] ?? null;
$flashError   = $_SESSION['error']   ?? null;
unset($_SESSION['success'], $_SESSION['error']);

if (isset($_GET['success']) && $_GET['success'] === 'created') {
    $flashSuccess = $flashSuccess ?? 'Invoice created.';
}
if (isset($_GET['success']) && $_GET['success'] === 'deleted') {
    $flashSuccess = $flashSuccess ?? 'Invoice deleted.';
}
if (isset($_GET['error'])) {
    $errorMap = [
        'csrf'         => 'Security check failed. Please reload the page and try again.',
        'invalid'      => 'Invalid invoice reference.',
        'has_payments' => 'Cannot delete: this invoice has recorded payments.',
        'not_found'    => 'Invoice not found or already deleted.',
        'db'           => 'Database error while deleting invoice.',
        'exists'       => 'An invoice already exists for that estimation.',
    ];
    $flashError = $flashError ?? ($errorMap[$_GET['error']] ?? null);
}

$search_query  = $_GET['search'] ?? '';
$status_filter = $_GET['status'] ?? '';

$query = "SELECT i.*,
                 e.estimation_number,
                 wo.id AS work_order_id,
                 wo.work_order_number,
                 wo.status AS work_order_status,
                 (SELECT COUNT(*) FROM invoice_payments p WHERE p.invoice_id = i.id) AS payment_count
          FROM invoices i
          LEFT JOIN estimations e ON i.estimation_id = e.id
          LEFT JOIN work_orders wo ON wo.invoice_id = i.id
          WHERE 1=1";

$params = [];

if (!empty($search_query)) {
    $searchTerm = '%' . $search_query . '%';
    $query .= " AND (i.invoice_number LIKE :search_invoice OR i.customer_name LIKE :search_customer OR e.estimation_number LIKE :search_estimation)";
    $params['search_invoice'] = $searchTerm;
    $params['search_customer'] = $searchTerm;
    $params['search_estimation'] = $searchTerm;
}

if (!empty($status_filter)) {
    if ($status_filter === 'Overdue') {
        $query .= " AND (
            i.status = 'Overdue'
            OR (
                i.status IN ('Unpaid', 'Partially Paid')
                AND i.due_date IS NOT NULL
                AND i.due_date < CURDATE()
                AND COALESCE(i.balance, 0) > 0
            )
        )";
    } else {
        $query .= " AND i.status = :status";
        $params['status'] = $status_filter;
    }
}

$query .= " ORDER BY i.generated_date DESC, i.id DESC";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$invoices = $stmt->fetchAll();

foreach ($invoices as &$invoice) {
    $isPastDue = !empty($invoice['due_date'])
        && strtotime((string) $invoice['due_date']) < strtotime(date('Y-m-d'))
        && in_array((string) ($invoice['status'] ?? ''), ['Unpaid', 'Partially Paid'], true)
        && (float) ($invoice['balance'] ?? 0) > 0;
    $invoice['display_status'] = $isPastDue ? 'Overdue' : (string) ($invoice['status'] ?? '');
}
unset($invoice);

/** Pretty Tailwind class for a given invoice status. */
function invoice_status_class(string $status): string
{
    static $map = [
        'Paid'           => 'bg-green-100 text-green-800',
        'Partially Paid' => 'bg-yellow-100 text-yellow-800',
        'Cancelled'      => 'bg-gray-200 text-gray-800',
        'Overdue'        => 'bg-red-100 text-red-800',
    ];
    return $map[$status] ?? 'bg-red-100 text-red-800';
}
?>

<div class="list-toolbar mb-6">
    <div class="min-w-0">
        <h1 class="text-3xl font-bold text-gray-800 break-words">Invoices</h1>
        <p class="text-sm text-gray-500 mt-1">Monitor invoice status, customer billing, and linked estimation records.</p>
    </div>
    <div class="list-toolbar-actions">
        <a href="create" class="list-action-btn bg-green-600 text-white" aria-label="Create invoice">
            <i data-lucide="plus" class="sm:mr-1 inline-block h-5 w-5 flex-shrink-0" aria-hidden="true"></i>
            <span class="hidden sm:inline">Create Invoice</span>
        </a>
    </div>
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

<!-- Search & Filter Bar -->
<div class="bg-white shadow rounded-lg p-6 mb-6">
    <form method="GET" action="" class="list-filters-grid md:grid-cols-12">
        <div class="md:col-span-7 min-w-0">
            <input type="text" name="search" placeholder="Search by Invoice #, Customer, or Estimation #..."
                class="w-full px-4 py-3 border border-gray-300 rounded-lg"
                value="<?php echo htmlspecialchars($search_query); ?>">
        </div>
        <div class="md:col-span-3">
            <select name="status" class="w-full px-4 py-3 border border-gray-300 rounded-lg">
                <option value="">All Statuses</option>
                <option value="Unpaid" <?php echo $status_filter == 'Unpaid' ? 'selected' : ''; ?>>Unpaid</option>
                <option value="Partially Paid" <?php echo $status_filter == 'Partially Paid' ? 'selected' : ''; ?>>Partially Paid</option>
                <option value="Paid" <?php echo $status_filter == 'Paid' ? 'selected' : ''; ?>>Paid</option>
                <option value="Overdue" <?php echo $status_filter == 'Overdue' ? 'selected' : ''; ?>>Overdue</option>
                <option value="Cancelled" <?php echo $status_filter == 'Cancelled' ? 'selected' : ''; ?>>Cancelled</option>
            </select>
        </div>
        <div class="md:col-span-2 flex flex-col sm:flex-row gap-2">
            <button type="submit" class="bg-green-600 text-white px-4 py-3 rounded hover:bg-green-700 transition flex items-center justify-center" aria-label="Search invoices">
                <i data-lucide="search" class="sm:mr-2 inline-block h-5 w-5 flex-shrink-0" aria-hidden="true"></i>
                <span class="hidden sm:inline">Search</span>
            </button>
            <?php if ($search_query || $status_filter): ?>
                <a href="list" class="bg-gray-300 text-gray-700 px-4 py-3 rounded hover:bg-gray-400 transition flex items-center justify-center" aria-label="Clear invoice filters">
                    <i data-lucide="x" class="sm:mr-2 inline-block h-5 w-5 flex-shrink-0" aria-hidden="true"></i>
                    <span class="hidden sm:inline">Clear</span>
                </a>
            <?php endif; ?>
        </div>
    </form>
</div>

<div class="list-view-shell">
    <div class="list-mobile-stack">
        <?php foreach ($invoices as $inv):
            $hasPayments = (int) ($inv['payment_count'] ?? 0) > 0;
            $balance = (float) ($inv['balance'] ?? 0);
            $canRecordPayment = $balance > 0 && $inv['display_status'] !== 'Cancelled'; ?>
            <div class="list-mobile-card">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <p class="list-card-title"><?php echo htmlspecialchars($inv['invoice_number']); ?></p>
                        <p class="text-sm text-gray-500 mt-1 break-words"><?php echo htmlspecialchars($inv['customer_name'] ?: '—'); ?></p>
                    </div>
                    <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full <?php echo invoice_status_class($inv['display_status']); ?>">
                        <?php echo htmlspecialchars($inv['display_status']); ?>
                    </span>
                </div>
                <div class="grid grid-cols-2 gap-3 mt-4 text-sm">
                    <div>
                        <p class="list-card-meta">Estimation</p>
                        <p class="list-card-value"><?php echo $inv['estimation_number'] ? htmlspecialchars($inv['estimation_number']) : '-'; ?></p>
                    </div>
                    <div>
                        <p class="list-card-meta">Date</p>
                        <p class="list-card-value"><?php echo htmlspecialchars($inv['generated_date']); ?></p>
                    </div>
                    <div>
                        <p class="list-card-meta">Total</p>
                        <p class="list-card-value font-semibold text-gray-900">MK <?php echo number_format((float) $inv['total_amount'], 2); ?></p>
                    </div>
                    <div>
                        <p class="list-card-meta">Balance</p>
                        <p class="list-card-value font-semibold <?php echo $balance > 0 ? 'text-red-600' : 'text-green-600'; ?>">MK <?php echo number_format($balance, 2); ?></p>
                    </div>
                </div>
                <div class="mt-3 text-xs text-gray-500">
                    Work order:
                    <?php if (!empty($inv['work_order_id'])): ?>
                        <a href="<?php echo BASE_URL; ?>modules/work_orders/view?id=<?php echo (int) $inv['work_order_id']; ?>" class="font-semibold text-indigo-600 hover:underline">
                            <?php echo htmlspecialchars($inv['work_order_number']); ?>
                        </a>
                        <span class="text-gray-400">(<?php echo htmlspecialchars($inv['work_order_status']); ?>)</span>
                    <?php elseif (!empty($inv['customer_accepted_at'])): ?>
                        <span class="text-amber-700 font-medium">Accepted, pending generation</span>
                    <?php else: ?>
                        <span>Not generated</span>
                    <?php endif; ?>
                </div>
                <div class="grid grid-cols-4 gap-2 mt-4">
                    <a href="view?id=<?php echo (int) $inv['id']; ?>" class="list-icon-action bg-blue-600 text-white" aria-label="View invoice" title="View">
                        <i data-lucide="eye" class="h-4 w-4" aria-hidden="true"></i>
                    </a>
                    <a href="download?id=<?php echo (int) $inv['id']; ?>" class="list-icon-action bg-red-600 text-white" aria-label="Download invoice PDF" title="Download PDF">
                        <i data-lucide="download" class="h-4 w-4" aria-hidden="true"></i>
                    </a>
                    <?php if ($canRecordPayment): ?>
                        <a href="record_payment?id=<?php echo (int) $inv['id']; ?>" class="list-icon-action bg-green-600 text-white" aria-label="Record payment" title="Record Payment">
                            <i data-lucide="banknote" class="h-4 w-4" aria-hidden="true"></i>
                        </a>
                    <?php else: ?>
                        <span class="list-icon-action bg-gray-200 text-gray-400 cursor-not-allowed" title="<?php echo $balance <= 0 ? 'No balance outstanding' : 'Invoice cancelled'; ?>">
                            <i data-lucide="banknote" class="h-4 w-4" aria-hidden="true"></i>
                        </span>
                    <?php endif; ?>
                    <?php if (!$hasPayments): ?>
                        <button type="button"
                            onclick="openDeleteModal(<?php echo (int) $inv['id']; ?>, '<?php echo htmlspecialchars(addslashes($inv['invoice_number']), ENT_QUOTES); ?>')"
                            class="list-icon-action bg-red-100 text-red-700" aria-label="Delete invoice" title="Delete">
                            <i data-lucide="trash-2" class="h-4 w-4" aria-hidden="true"></i>
                        </button>
                    <?php else: ?>
                        <span class="list-icon-action bg-gray-200 text-gray-400 cursor-not-allowed" title="Cannot delete (payments recorded)">
                            <i data-lucide="trash-2" class="h-4 w-4" aria-hidden="true"></i>
                        </span>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
        <?php if (empty($invoices)): ?>
            <div class="list-mobile-card text-center text-gray-500">
                <i data-lucide="receipt" class="mx-auto mb-2 block h-12 w-12 text-gray-400" aria-hidden="true"></i>
                No invoices found.
            </div>
        <?php endif; ?>
    </div>

    <div class="list-desktop-table overflow-x-auto">
        <table class="divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="text-left text-xs font-medium text-gray-500 uppercase">Invoice #</th>
                    <th class="text-left text-xs font-medium text-gray-500 uppercase">Estimation #</th>
                    <th class="text-left text-xs font-medium text-gray-500 uppercase">Customer</th>
                    <th class="text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                    <th class="text-left text-xs font-medium text-gray-500 uppercase">Work Order</th>
                    <th class="text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                    <th class="text-right text-xs font-medium text-gray-500 uppercase">Balance</th>
                    <th class="text-right text-xs font-medium text-gray-500 uppercase">Actions</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                <?php foreach ($invoices as $inv):
                    $hasPayments = (int) ($inv['payment_count'] ?? 0) > 0;
                    $balance = (float) ($inv['balance'] ?? 0);
                    $canRecordPayment = $balance > 0 && $inv['display_status'] !== 'Cancelled'; ?>
                    <tr>
                        <td class="text-sm font-medium text-gray-900 cell-wrap"><?php echo htmlspecialchars($inv['invoice_number']); ?></td>
                        <td class="text-sm text-gray-500 cell-wrap">
                            <?php echo $inv['estimation_number'] ? htmlspecialchars($inv['estimation_number']) : '<span class="text-gray-400">-</span>'; ?>
                        </td>
                        <td class="text-sm text-gray-900 cell-wrap">
                            <?php echo htmlspecialchars($inv['customer_name'] ?: '—'); ?>
                        </td>
                        <td class="text-sm text-gray-500"><?php echo htmlspecialchars($inv['generated_date']); ?></td>
                        <td class="text-sm text-gray-700 cell-wrap">
                            <?php if (!empty($inv['work_order_id'])): ?>
                                <a href="<?php echo BASE_URL; ?>modules/work_orders/view?id=<?php echo (int) $inv['work_order_id']; ?>" class="font-semibold text-indigo-600 hover:underline">
                                    <?php echo htmlspecialchars($inv['work_order_number']); ?>
                                </a>
                                <div class="text-xs text-gray-500"><?php echo htmlspecialchars($inv['work_order_status']); ?></div>
                            <?php elseif (!empty($inv['customer_accepted_at'])): ?>
                                <span class="text-amber-700">Accepted</span>
                            <?php else: ?>
                                <span class="text-gray-400">Not generated</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full <?php echo invoice_status_class($inv['display_status']); ?>">
                                <?php echo htmlspecialchars($inv['display_status']); ?>
                            </span>
                        </td>
                        <td class="text-right text-sm font-semibold <?php echo $balance > 0 ? 'text-red-600' : 'text-green-600'; ?>">
                            MK <?php echo number_format($balance, 2); ?>
                        </td>
                        <td class="text-right text-sm font-medium">
                            <div class="flex items-center justify-end space-x-2">
                                <a href="view?id=<?php echo (int) $inv['id']; ?>"
                                    class="text-gray-400 hover:text-blue-600 transition-colors" title="View invoice" aria-label="View invoice">
                                    <i data-lucide="eye" class="h-5 w-5" aria-hidden="true"></i>
                                </a>
                                <a href="download?id=<?php echo (int) $inv['id']; ?>"
                                    class="text-gray-400 hover:text-red-600 transition-colors" title="Download PDF" aria-label="Download invoice PDF">
                                    <i data-lucide="download" class="h-5 w-5" aria-hidden="true"></i>
                                </a>
                                <?php if ($canRecordPayment): ?>
                                    <a href="record_payment?id=<?php echo (int) $inv['id']; ?>"
                                        class="text-gray-400 hover:text-green-600 transition-colors" title="Record payment" aria-label="Record payment for invoice">
                                        <i data-lucide="banknote" class="h-5 w-5" aria-hidden="true"></i>
                                    </a>
                                <?php else: ?>
                                    <span class="text-gray-300 cursor-not-allowed" title="<?php echo $balance <= 0 ? 'No outstanding balance' : 'Invoice cancelled'; ?>">
                                        <i data-lucide="banknote" class="h-5 w-5" aria-hidden="true"></i>
                                    </span>
                                <?php endif; ?>
                                <?php if (!$hasPayments): ?>
                                    <button type="button"
                                        onclick="openDeleteModal(<?php echo (int) $inv['id']; ?>, '<?php echo htmlspecialchars(addslashes($inv['invoice_number']), ENT_QUOTES); ?>')"
                                        class="text-gray-400 hover:text-red-600 transition-colors" title="Delete invoice" aria-label="Delete invoice">
                                        <i data-lucide="trash-2" class="h-5 w-5" aria-hidden="true"></i>
                                    </button>
                                <?php else: ?>
                                    <span class="text-gray-300 cursor-not-allowed" title="Cannot delete (payments recorded)">
                                        <i data-lucide="trash-2" class="h-5 w-5" aria-hidden="true"></i>
                                    </span>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($invoices)): ?>
                    <tr>
                        <td colspan="8" class="text-center text-gray-500">No invoices found.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Delete confirmation modal (single instance reused for every row) -->
<div id="deleteModal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
    <div class="relative top-20 mx-auto p-6 border w-full max-w-md shadow-lg rounded-md bg-white">
        <div class="flex items-start justify-between mb-4">
            <div>
                <h3 class="text-lg font-bold text-gray-900">Delete invoice?</h3>
                <p class="text-sm text-gray-600 mt-1">
                    This permanently removes <strong id="deleteTargetLabel">this invoice</strong>.
                    Linked estimations are reopened so they can be re-invoiced.
                </p>
            </div>
            <button onclick="closeDeleteModal()" class="text-gray-400 hover:text-gray-600">
                <i data-lucide="x" class="h-5 w-5" aria-hidden="true"></i>
            </button>
        </div>
        <form id="deleteForm" method="POST" action="delete" class="flex justify-end gap-2 mt-6">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token('invoice_delete')); ?>">
            <input type="hidden" name="id" id="deleteTargetId" value="">
            <button type="button" onclick="closeDeleteModal()"
                class="px-4 py-2 border border-gray-300 rounded-lg hover:bg-gray-50 transition">Cancel</button>
            <button type="submit"
                class="px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 transition flex items-center gap-1">
                <i data-lucide="trash-2" class="h-4 w-4" aria-hidden="true"></i> Delete permanently
            </button>
        </form>
    </div>
</div>

<script>
    function openDeleteModal(id, label) {
        document.getElementById('deleteTargetId').value = id;
        document.getElementById('deleteTargetLabel').textContent = label;
        document.getElementById('deleteModal').classList.remove('hidden');
        if (typeof window.refreshAppShellIcons === 'function') window.refreshAppShellIcons();
    }
    function closeDeleteModal() {
        document.getElementById('deleteModal').classList.add('hidden');
    }
    document.addEventListener('DOMContentLoaded', () => {
        const m = document.getElementById('deleteModal');
        m.addEventListener('click', (e) => { if (e.target === m) closeDeleteModal(); });
        if (typeof window.refreshAppShellIcons === 'function') window.refreshAppShellIcons();
    });
</script>

<?php include '../../includes/footer.php'; ?>
