<?php
/**
 * Estimations List
 *
 * Each row exposes four explicit actions: View, Download, Convert to Invoice
 * and Delete. The View action opens the rich summary page; only the
 * Download action triggers a file download. Delete posts to the
 * extensionless `delete` route with a CSRF token so the .htaccess rewrite
 * preserves the POST body.
 */

require_once __DIR__ . '/../../config/app.php';
checkAuth();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../libs/EstimationStatusManager.php';
require_once __DIR__ . '/../../libs/EstimationAuditMigrator.php';

EstimationAuditMigrator::ensure($pdo);

include '../../includes/header.php';

// Surface flash messages set by save / delete / status-change endpoints.
$flashSuccess = $_SESSION['success'] ?? null;
$flashError   = $_SESSION['error']   ?? null;
unset($_SESSION['success'], $_SESSION['error']);

if (isset($_GET['success']) && $_GET['success'] === 'created') {
    $flashSuccess = $flashSuccess ?? 'Estimation created.';
}
if (isset($_GET['success']) && $_GET['success'] === 'deleted') {
    $flashSuccess = $flashSuccess ?? 'Estimation deleted.';
}
if (isset($_GET['error'])) {
    $errorMap = [
        'csrf'         => 'Security check failed. Please reload the page and try again.',
        'invalid'      => 'Invalid estimation reference.',
        'has_invoices' => 'Cannot delete: this estimation has linked invoices.',
        'not_found'    => 'Estimation not found or already deleted.',
        'db'           => 'Database error while deleting estimation.',
    ];
    $flashError = $flashError ?? ($errorMap[$_GET['error']] ?? null);
}

$search_query  = $_GET['search'] ?? '';
$status_filter = $_GET['status'] ?? '';

$query = "SELECT e.*,
                 u.name AS created_by_name,
                 (SELECT COUNT(*) FROM invoices i WHERE i.estimation_id = e.id) AS invoice_count
          FROM estimations e
          LEFT JOIN users u ON e.created_by = u.id
          WHERE 1=1";

$params = [];

if (!empty($search_query)) {
    $query .= " AND (e.estimation_number LIKE :search OR e.customer_name LIKE :search OR e.job_description LIKE :search)";
    $params['search'] = '%' . $search_query . '%';
}

if (!empty($status_filter)) {
    $query .= " AND e.status = :status";
    $params['status'] = $status_filter;
}

$query .= " ORDER BY e.created_at DESC";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$estimations = $stmt->fetchAll();
?>

<div class="list-toolbar mb-6">
    <div class="min-w-0">
        <h1 class="text-3xl font-bold text-gray-800 break-words">Estimations</h1>
        <p class="text-sm text-gray-500 mt-1">Review customer estimates, track status changes, and open detailed costing records.</p>
    </div>
    <div class="list-toolbar-actions">
        <a href="create" class="list-action-btn bg-green-600 text-white" aria-label="Create estimation">
            <i data-lucide="plus" class="sm:mr-1 inline-block h-5 w-5 flex-shrink-0" aria-hidden="true"></i>
            <span class="hidden sm:inline">Create New</span>
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
            <input type="text" name="search" placeholder="Search by Est #, Customer, or Job Description..."
                class="w-full px-4 py-3 border border-gray-300 rounded-lg"
                value="<?php echo htmlspecialchars($search_query); ?>">
        </div>
        <div class="md:col-span-3">
            <select name="status" class="w-full px-4 py-3 border border-gray-300 rounded-lg">
                <option value="">All Statuses</option>
                <option value="Draft" <?php echo $status_filter == 'Draft' ? 'selected' : ''; ?>>Draft</option>
                <option value="Performer Invoiced" <?php echo $status_filter == 'Performer Invoiced' ? 'selected' : ''; ?>>Performer Invoiced</option>
                <option value="Approved" <?php echo $status_filter == 'Approved' ? 'selected' : ''; ?>>Approved</option>
                <option value="Invoiced" <?php echo $status_filter == 'Invoiced' ? 'selected' : ''; ?>>Invoiced</option>
            </select>
        </div>
        <div class="md:col-span-2 flex flex-col sm:flex-row gap-2">
            <button type="submit" class="bg-green-600 text-white px-4 py-3 rounded hover:bg-green-700 transition flex items-center justify-center" aria-label="Search estimations">
                <i data-lucide="search" class="sm:mr-2 inline-block h-5 w-5 flex-shrink-0" aria-hidden="true"></i>
                <span class="hidden sm:inline">Search</span>
            </button>
            <?php if ($search_query || $status_filter): ?>
                <a href="list" class="bg-gray-300 text-gray-700 px-4 py-3 rounded hover:bg-gray-400 transition flex items-center justify-center" aria-label="Clear estimation filters">
                    <i data-lucide="x" class="sm:mr-2 inline-block h-5 w-5 flex-shrink-0" aria-hidden="true"></i>
                    <span class="hidden sm:inline">Clear</span>
                </a>
            <?php endif; ?>
        </div>
    </form>
</div>

<div class="list-view-shell">
    <div class="list-mobile-stack">
        <?php foreach ($estimations as $est): ?>
            <?php $hasInvoice = (int) ($est['invoice_count'] ?? 0) > 0; ?>
            <div class="list-mobile-card">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <p class="list-card-title"><?php echo htmlspecialchars($est['estimation_number']); ?></p>
                        <p class="text-sm text-gray-500 mt-1 break-words"><?php echo htmlspecialchars($est['customer_name']); ?></p>
                    </div>
                    <div class="flex-shrink-0">
                        <?php echo EstimationStatusManager::getStatusBadgeHtml($est['status']); ?>
                    </div>
                </div>
                <div class="grid grid-cols-1 gap-3 mt-4 text-sm">
                    <div>
                        <p class="list-card-meta">Description</p>
                        <p class="list-card-value"><?php echo htmlspecialchars($est['job_description'] ?: 'No description'); ?></p>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <p class="list-card-meta">Amount</p>
                            <p class="list-card-value font-semibold text-gray-900">MK <?php echo number_format((float) $est['total_amount'], 2); ?></p>
                        </div>
                        <div>
                            <p class="list-card-meta">Date</p>
                            <p class="list-card-value"><?php echo date('M j, Y', strtotime($est['created_at'])); ?></p>
                        </div>
                    </div>
                </div>
                <div class="grid grid-cols-4 gap-2 mt-4">
                    <a href="view?id=<?php echo (int) $est['id']; ?>" class="list-icon-action bg-blue-600 text-white" aria-label="View estimation" title="View">
                        <i data-lucide="eye" class="h-4 w-4" aria-hidden="true"></i>
                    </a>
                    <a href="download?id=<?php echo (int) $est['id']; ?>" class="list-icon-action bg-red-600 text-white" aria-label="Download estimation PDF" title="Download PDF">
                        <i data-lucide="download" class="h-4 w-4" aria-hidden="true"></i>
                    </a>
                    <?php if ($hasInvoice): ?>
                        <span class="list-icon-action bg-gray-200 text-gray-400 cursor-not-allowed" title="Invoice already exists">
                            <i data-lucide="receipt" class="h-4 w-4" aria-hidden="true"></i>
                        </span>
                        <span class="list-icon-action bg-gray-200 text-gray-400 cursor-not-allowed" title="Cannot delete (linked invoice)">
                            <i data-lucide="trash-2" class="h-4 w-4" aria-hidden="true"></i>
                        </span>
                    <?php else: ?>
                        <a href="<?php echo BASE_URL; ?>modules/invoices/create?estimation_id=<?php echo (int) $est['id']; ?>"
                            class="list-icon-action bg-green-600 text-white" aria-label="Convert to invoice" title="Convert to Invoice">
                            <i data-lucide="receipt" class="h-4 w-4" aria-hidden="true"></i>
                        </a>
                        <button type="button"
                            onclick="openDeleteModal(<?php echo (int) $est['id']; ?>, '<?php echo htmlspecialchars(addslashes($est['estimation_number']), ENT_QUOTES); ?>')"
                            class="list-icon-action bg-red-100 text-red-700" aria-label="Delete estimation" title="Delete">
                            <i data-lucide="trash-2" class="h-4 w-4" aria-hidden="true"></i>
                        </button>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
        <?php if (empty($estimations)): ?>
            <div class="list-mobile-card text-center text-gray-500">
                <i data-lucide="calculator" class="mx-auto mb-2 block h-12 w-12 text-gray-400" aria-hidden="true"></i>
                No estimations found.
            </div>
        <?php endif; ?>
    </div>

    <div class="list-desktop-table overflow-x-auto">
        <table class="divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th scope="col" class="text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Est #</th>
                    <th scope="col" class="text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Customer</th>
                    <th scope="col" class="text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Description</th>
                    <th scope="col" class="text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                    <th scope="col" class="text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Amount</th>
                    <th scope="col" class="text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date</th>
                    <th scope="col" class="text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                <?php foreach ($estimations as $est): ?>
                    <?php $hasInvoice = (int) ($est['invoice_count'] ?? 0) > 0; ?>
                    <tr>
                        <td class="text-sm font-medium text-gray-900">
                            <?php echo htmlspecialchars($est['estimation_number']); ?>
                        </td>
                        <td class="text-sm text-gray-500 cell-wrap">
                            <?php echo htmlspecialchars($est['customer_name']); ?>
                        </td>
                        <td class="text-sm text-gray-500 cell-wrap">
                            <?php echo htmlspecialchars($est['job_description'] ? substr($est['job_description'], 0, 60) . (strlen($est['job_description']) > 60 ? '...' : '') : '-'); ?>
                        </td>
                        <td>
                            <?php echo EstimationStatusManager::getStatusBadgeHtml($est['status']); ?>
                        </td>
                        <td class="text-sm text-gray-500">
                            MK <?php echo number_format((float) $est['total_amount'], 2); ?>
                        </td>
                        <td class="text-sm text-gray-500">
                            <?php echo date('M j, Y', strtotime($est['created_at'])); ?>
                        </td>
                        <td class="text-right text-sm font-medium">
                            <div class="flex items-center justify-end gap-2">
                                <a href="view?id=<?php echo (int) $est['id']; ?>"
                                    class="text-gray-400 hover:text-blue-600 transition-colors" title="View estimation" aria-label="View estimation">
                                    <i data-lucide="eye" class="h-5 w-5" aria-hidden="true"></i>
                                </a>
                                <a href="download?id=<?php echo (int) $est['id']; ?>"
                                    class="text-gray-400 hover:text-red-600 transition-colors" title="Download PDF" aria-label="Download estimation PDF">
                                    <i data-lucide="download" class="h-5 w-5" aria-hidden="true"></i>
                                </a>
                                <?php if ($hasInvoice): ?>
                                    <span class="text-gray-300 cursor-not-allowed" title="Invoice already exists">
                                        <i data-lucide="receipt" class="h-5 w-5" aria-hidden="true"></i>
                                    </span>
                                    <span class="text-gray-300 cursor-not-allowed" title="Cannot delete (linked invoice)">
                                        <i data-lucide="trash-2" class="h-5 w-5" aria-hidden="true"></i>
                                    </span>
                                <?php else: ?>
                                    <a href="<?php echo BASE_URL; ?>modules/invoices/create?estimation_id=<?php echo (int) $est['id']; ?>"
                                        class="text-gray-400 hover:text-green-600 transition-colors" title="Convert to Invoice" aria-label="Convert estimation to invoice">
                                        <i data-lucide="receipt" class="h-5 w-5" aria-hidden="true"></i>
                                    </a>
                                    <button type="button"
                                        onclick="openDeleteModal(<?php echo (int) $est['id']; ?>, '<?php echo htmlspecialchars(addslashes($est['estimation_number']), ENT_QUOTES); ?>')"
                                        class="text-gray-400 hover:text-red-600 transition-colors" title="Delete estimation" aria-label="Delete estimation">
                                        <i data-lucide="trash-2" class="h-5 w-5" aria-hidden="true"></i>
                                    </button>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($estimations)): ?>
                    <tr>
                        <td colspan="7" class="text-center text-gray-500">No estimations found.</td>
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
                <h3 class="text-lg font-bold text-gray-900">Delete estimation?</h3>
                <p class="text-sm text-gray-600 mt-1">
                    This permanently removes <strong id="deleteTargetLabel">this estimation</strong>
                    and all of its related papers, ink, binding, labour and item rows.
                </p>
            </div>
            <button onclick="closeDeleteModal()" class="text-gray-400 hover:text-gray-600">
                <i data-lucide="x" class="h-5 w-5" aria-hidden="true"></i>
            </button>
        </div>
        <form id="deleteForm" method="POST" action="delete" class="flex justify-end gap-2 mt-6">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token('estimation_delete')); ?>">
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
