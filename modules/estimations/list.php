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
require_once __DIR__ . '/../../includes/permissions_helper.php';
permissions_require_one_of(['view_estimations']);
require_once __DIR__ . '/../../libs/EstimationStatusManager.php';
require_once __DIR__ . '/../../libs/EstimationAuditMigrator.php';
require_once __DIR__ . '/../../includes/estimation_list_helper.php';
require_once __DIR__ . '/../../includes/module_kpi_helper.php';

EstimationAuditMigrator::ensure($pdo);
$kpis = estimations_module_kpis($pdo);

include '../../includes/header.php';

$listView = estimation_normalize_list_view($_GET['view'] ?? 'drafts');
$draftKindFilter = strtolower(trim((string) ($_GET['draft_kind'] ?? '')));
if ($listView !== 'drafts') {
    $draftKindFilter = '';
}
$listViewTitles = estimation_list_views();
$pageTitle = $listViewTitles[$listView] . ' estimations';
$pageDescriptions = [
    'drafts' => 'In-progress estimates — autosaved, manually saved, auto-recovered, and abandoned drafts.',
    'completed' => 'Finished estimates ready for approval or conversion to invoice.',
    'invoiced' => 'Estimates that have been invoiced to customers.',
];
$pageDescription = $pageDescriptions[$listView] ?? '';

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

$listFilters = estimation_list_query_filters($listView, $draftKindFilter);
$query .= $listFilters['sql'];
$params = array_merge($params, $listFilters['params']);

if (!empty($search_query)) {
    $query .= " AND (e.estimation_number LIKE :search OR e.customer_name LIKE :search OR e.job_description LIKE :search)";
    $params['search'] = '%' . $search_query . '%';
}

if (!empty($status_filter) && $listView === 'completed') {
    if ($status_filter === 'Draft') {
        $query .= " AND e.status = 'Draft' AND (e.draft_data IS NULL OR e.draft_data = '')";
    } else {
        $query .= " AND e.status = :status";
        $params['status'] = $status_filter;
    }
}

if ($listView === 'drafts') {
    $query .= " ORDER BY COALESCE(e.last_auto_saved, e.updated_at, e.created_at) DESC";
} else {
    $query .= " ORDER BY e.created_at DESC";
}

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$estimations = $stmt->fetchAll();
?>

<?php include __DIR__ . '/../../includes/partials/module_kpi_strip.php'; ?>

<div class="list-toolbar mb-6">
    <div class="min-w-0">
        <h1 class="text-3xl font-bold text-gray-800 break-words"><?php echo htmlspecialchars($pageTitle); ?></h1>
        <p class="text-sm text-gray-500 mt-1"><?php echo htmlspecialchars($pageDescription); ?></p>
    </div>
    <div class="list-toolbar-actions">
        <?php if (hasPermission('manage_estimations')): ?>
        <a href="create" class="list-action-btn bg-green-600 text-white" aria-label="Create estimation">
            <i data-lucide="plus" class="sm:mr-1 inline-block h-5 w-5 flex-shrink-0" aria-hidden="true"></i>
            <span class="hidden sm:inline">Create New</span>
        </a>
        <?php endif; ?>
    </div>
</div>

<?php if ($listView === 'drafts'): ?>
<div class="flex flex-wrap gap-2 mb-4">
    <?php foreach (estimation_draft_kind_filters() as $kindKey => $kindLabel): ?>
        <?php
        $isActive = $draftKindFilter === $kindKey;
        $href = 'list?view=drafts' . ($kindKey !== '' ? '&draft_kind=' . urlencode($kindKey) : '');
        if ($search_query !== '') {
            $href .= '&search=' . urlencode($search_query);
        }
        ?>
        <a href="<?php echo htmlspecialchars($href); ?>"
            class="px-3 py-1.5 rounded-full text-sm font-semibold border transition <?php echo $isActive ? 'bg-green-600 text-white border-green-600' : 'bg-white text-gray-700 border-gray-300 hover:border-green-400'; ?>">
            <?php echo htmlspecialchars($kindLabel); ?>
        </a>
    <?php endforeach; ?>
</div>
<?php endif; ?>

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
        <input type="hidden" name="view" value="<?php echo htmlspecialchars($listView); ?>">
        <?php if ($listView === 'drafts' && $draftKindFilter !== ''): ?>
            <input type="hidden" name="draft_kind" value="<?php echo htmlspecialchars($draftKindFilter); ?>">
        <?php endif; ?>
        <div class="md:col-span-<?php echo $listView === 'drafts' ? '10' : '7'; ?> min-w-0">
            <input type="text" name="search" placeholder="Search by Est #, Customer, or Job Description..."
                class="w-full px-4 py-3 border border-gray-300 rounded-lg"
                value="<?php echo htmlspecialchars($search_query); ?>">
        </div>
        <?php if ($listView === 'drafts'): ?>
        <div class="md:col-span-2 flex flex-col sm:flex-row gap-2">
            <button type="submit" class="bg-green-600 text-white px-4 py-3 rounded hover:bg-green-700 transition flex items-center justify-center" aria-label="Search estimations">
                <i data-lucide="search" class="sm:mr-2 inline-block h-5 w-5 flex-shrink-0" aria-hidden="true"></i>
                <span class="hidden sm:inline">Search</span>
            </button>
            <?php if ($search_query || $draftKindFilter): ?>
                <a href="list?view=drafts" class="bg-gray-300 text-gray-700 px-4 py-3 rounded hover:bg-gray-400 transition flex items-center justify-center" aria-label="Clear estimation filters">
                    <i data-lucide="x" class="sm:mr-2 inline-block h-5 w-5 flex-shrink-0" aria-hidden="true"></i>
                    <span class="hidden sm:inline">Clear</span>
                </a>
            <?php endif; ?>
        </div>
        <?php else: ?>
        <div class="md:col-span-3">
            <select name="status" class="w-full px-4 py-3 border border-gray-300 rounded-lg">
                <option value="">All Statuses</option>
                <?php if ($listView === 'completed'): ?>
                    <option value="Approved" <?php echo $status_filter == 'Approved' ? 'selected' : ''; ?>>Approved</option>
                    <option value="Performer Invoiced" <?php echo $status_filter == 'Performer Invoiced' ? 'selected' : ''; ?>>Performer Invoiced</option>
                    <option value="Draft" <?php echo $status_filter == 'Draft' ? 'selected' : ''; ?>>Completed (Draft status)</option>
                <?php endif; ?>
            </select>
        </div>
        <div class="md:col-span-2 flex flex-col sm:flex-row gap-2">
            <button type="submit" class="bg-green-600 text-white px-4 py-3 rounded hover:bg-green-700 transition flex items-center justify-center" aria-label="Search estimations">
                <i data-lucide="search" class="sm:mr-2 inline-block h-5 w-5 flex-shrink-0" aria-hidden="true"></i>
                <span class="hidden sm:inline">Search</span>
            </button>
            <?php if ($search_query || $status_filter): ?>
                <a href="list?view=<?php echo urlencode($listView); ?>" class="bg-gray-300 text-gray-700 px-4 py-3 rounded hover:bg-gray-400 transition flex items-center justify-center" aria-label="Clear estimation filters">
                    <i data-lucide="x" class="sm:mr-2 inline-block h-5 w-5 flex-shrink-0" aria-hidden="true"></i>
                    <span class="hidden sm:inline">Clear</span>
                </a>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </form>
</div>

<div class="list-view-shell">
    <div class="list-mobile-stack">
        <?php foreach ($estimations as $est): ?>
            <?php
            $hasInvoice = (int) ($est['invoice_count'] ?? 0) > 0;
            $canContinue = estimation_can_continue_draft($est);
            ?>
            <div class="list-mobile-card">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <p class="list-card-title"><?php echo htmlspecialchars($est['estimation_number']); ?></p>
                        <p class="text-sm text-gray-500 mt-1 break-words"><?php echo htmlspecialchars($est['customer_name']); ?></p>
                    </div>
                    <div class="flex-shrink-0 flex flex-col items-end gap-1">
                        <?php echo EstimationStatusManager::getStatusBadgeHtml($est['status']); ?>
                        <?php if ($listView === 'drafts'): ?>
                            <?php echo estimation_draft_kind_badge_html($est); ?>
                        <?php endif; ?>
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
                            <p class="list-card-meta"><?php echo $listView === 'drafts' ? 'Last saved' : 'Date'; ?></p>
                            <p class="list-card-value">
                                <?php
                                if ($listView === 'drafts' && !empty($est['last_auto_saved'])) {
                                    echo date('M j, Y H:i', strtotime($est['last_auto_saved']));
                                } else {
                                    echo date('M j, Y', strtotime($est['created_at']));
                                }
                                ?>
                            </p>
                        </div>
                    </div>
                    <?php if ($listView === 'drafts'): ?>
                    <div>
                        <p class="list-card-meta">Wizard step</p>
                        <p class="list-card-value">Step <?php echo (int) ($est['draft_step'] ?? 1); ?> of 8</p>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="grid grid-cols-<?php echo $canContinue ? '6' : '4'; ?> gap-2 mt-4">
                    <?php if ($canContinue): ?>
                    <a href="edit_draft?id=<?php echo (int) $est['id']; ?>" class="list-icon-action bg-amber-600 text-white" aria-label="Continue draft" title="Continue">
                        <i data-lucide="play" class="h-4 w-4" aria-hidden="true"></i>
                    </a>
                    <button type="button"
                        onclick="openDraftHistoryModal(<?php echo (int) $est['id']; ?>, '<?php echo htmlspecialchars(addslashes($est['estimation_number']), ENT_QUOTES); ?>')"
                        class="list-icon-action bg-slate-600 text-white" aria-label="Draft history" title="Version history">
                        <i data-lucide="history" class="h-4 w-4" aria-hidden="true"></i>
                    </button>
                    <?php endif; ?>
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
                No <?php echo htmlspecialchars(strtolower($listViewTitles[$listView])); ?> estimations found.
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
                    <?php if ($listView === 'drafts'): ?>
                    <th scope="col" class="text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Draft type</th>
                    <th scope="col" class="text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Step</th>
                    <th scope="col" class="text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Last saved</th>
                    <?php else: ?>
                    <th scope="col" class="text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                    <?php endif; ?>
                    <th scope="col" class="text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Amount</th>
                    <th scope="col" class="text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date</th>
                    <th scope="col" class="text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                <?php foreach ($estimations as $est): ?>
                    <?php
                    $hasInvoice = (int) ($est['invoice_count'] ?? 0) > 0;
                    $canContinue = estimation_can_continue_draft($est);
                    ?>
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
                        <?php if ($listView === 'drafts'): ?>
                        <td><?php echo estimation_draft_kind_badge_html($est); ?></td>
                        <td class="text-sm text-gray-500"><?php echo (int) ($est['draft_step'] ?? 1); ?> / 8</td>
                        <td class="text-sm text-gray-500">
                            <?php echo !empty($est['last_auto_saved']) ? date('M j, Y H:i', strtotime($est['last_auto_saved'])) : '—'; ?>
                        </td>
                        <?php else: ?>
                        <td>
                            <?php echo EstimationStatusManager::getStatusBadgeHtml($est['status']); ?>
                        </td>
                        <?php endif; ?>
                        <td class="text-sm text-gray-500">
                            MK <?php echo number_format((float) $est['total_amount'], 2); ?>
                        </td>
                        <td class="text-sm text-gray-500">
                            <?php echo date('M j, Y', strtotime($est['created_at'])); ?>
                        </td>
                        <td class="text-right text-sm font-medium">
                            <div class="flex items-center justify-end gap-2">
                                <?php if ($canContinue): ?>
                                <a href="edit_draft?id=<?php echo (int) $est['id']; ?>"
                                    class="text-gray-400 hover:text-amber-600 transition-colors" title="Continue draft" aria-label="Continue draft">
                                    <i data-lucide="play" class="h-5 w-5" aria-hidden="true"></i>
                                </a>
                                <button type="button"
                                    onclick="openDraftHistoryModal(<?php echo (int) $est['id']; ?>, '<?php echo htmlspecialchars(addslashes($est['estimation_number']), ENT_QUOTES); ?>')"
                                    class="text-gray-400 hover:text-slate-700 transition-colors" title="Version history" aria-label="Draft version history">
                                    <i data-lucide="history" class="h-5 w-5" aria-hidden="true"></i>
                                </button>
                                <?php endif; ?>
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
                        <td colspan="<?php echo $listView === 'drafts' ? '9' : '7'; ?>" class="text-center text-gray-500">
                            No <?php echo htmlspecialchars(strtolower($listViewTitles[$listView])); ?> estimations found.
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if ($listView === 'drafts'): ?>
<div id="draftHistoryModal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
    <div class="relative top-20 mx-auto p-6 border w-full max-w-lg shadow-lg rounded-md bg-white">
        <div class="flex items-start justify-between mb-4">
            <div>
                <h3 class="text-lg font-bold text-gray-900">Draft version history</h3>
                <p class="text-sm text-gray-600 mt-1">
                    <span id="draftHistoryModalLabel">Draft</span> — up to 4 recent saves
                </p>
            </div>
            <button type="button" onclick="closeDraftHistoryModal()" class="text-gray-400 hover:text-gray-600">
                <i data-lucide="x" class="h-5 w-5" aria-hidden="true"></i>
            </button>
        </div>
        <div id="draftHistoryModalList" class="border border-gray-200 rounded-lg divide-y divide-gray-100 max-h-80 overflow-y-auto">
            <p class="px-4 py-6 text-sm text-gray-500 text-center">Loading…</p>
        </div>
        <div class="flex justify-end gap-2 mt-6">
            <button type="button" onclick="closeDraftHistoryModal()"
                class="px-4 py-2 border border-gray-300 rounded-lg hover:bg-gray-50 transition">Close</button>
            <a id="draftHistoryContinueLink" href="#"
                class="px-4 py-2 bg-amber-600 text-white rounded-lg hover:bg-amber-700 transition font-semibold">
                Continue editing
            </a>
        </div>
    </div>
</div>
<?php endif; ?>

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

    <?php if ($listView === 'drafts'): ?>
    let draftHistoryTargetId = null;

    function formatListDraftTime(savedAt) {
        if (!savedAt) return 'Unknown time';
        const dt = new Date(String(savedAt).replace(' ', 'T'));
        if (isNaN(dt.getTime())) return savedAt;
        return dt.toLocaleString(undefined, { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' });
    }

    function openDraftHistoryModal(estId, estNumber) {
        draftHistoryTargetId = estId;
        const modal = document.getElementById('draftHistoryModal');
        const listEl = document.getElementById('draftHistoryModalList');
        const labelEl = document.getElementById('draftHistoryModalLabel');
        const continueLink = document.getElementById('draftHistoryContinueLink');
        if (!modal || !listEl) return;

        labelEl.textContent = estNumber || ('Draft #' + estId);
        continueLink.href = 'edit_draft?id=' + encodeURIComponent(estId);
        listEl.innerHTML = '<p class="px-4 py-6 text-sm text-gray-500 text-center">Loading…</p>';
        modal.classList.remove('hidden');
        if (typeof window.refreshAppShellIcons === 'function') window.refreshAppShellIcons();

        fetch('draft_versions?est_id=' + encodeURIComponent(estId), { credentials: 'same-origin' })
            .then(function (response) { return response.json(); })
            .then(function (data) {
                if (!data.success) throw new Error(data.message || 'Failed to load history');
                const versions = data.versions || [];
                if (!versions.length) {
                    listEl.innerHTML = '<p class="px-4 py-6 text-sm text-gray-500 text-center">No saved versions yet.</p>';
                    return;
                }
                listEl.innerHTML = versions.map(function (item) {
                    const label = item.is_current ? 'Current' : (item.label || ('rev ' + item.revision));
                    const time = formatListDraftTime(item.saved_at);
                    const step = item.draft_step || 1;
                    let action = item.is_current
                        ? '<span class="text-xs text-gray-400">Active</span>'
                        : '<button type="button" class="text-xs font-semibold text-amber-700 hover:text-amber-900" data-revision="' + item.revision + '">Restore &amp; open</button>';
                    return '<div class="flex items-center justify-between gap-3 px-4 py-3">' +
                        '<div><p class="text-sm font-semibold text-gray-800">' + label + '</p>' +
                        '<p class="text-xs text-gray-500">' + time + ' · Step ' + step + '</p></div>' +
                        action + '</div>';
                }).join('');

                listEl.querySelectorAll('button[data-revision]').forEach(function (btn) {
                    btn.addEventListener('click', function () {
                        const revision = btn.getAttribute('data-revision');
                        if (!revision || !draftHistoryTargetId) return;
                        if (!confirm('Restore this version and open the draft editor? Current unsaved work in the editor will be replaced.')) {
                            return;
                        }
                        btn.disabled = true;
                        const body = new URLSearchParams();
                        body.append('action', 'restore');
                        body.append('est_id', String(draftHistoryTargetId));
                        body.append('revision', revision);
                        fetch('draft_versions', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                            body: body.toString(),
                            credentials: 'same-origin',
                        })
                            .then(function (response) { return response.json(); })
                            .then(function (result) {
                                if (!result.success) throw new Error(result.message || 'Restore failed');
                                window.location.href = 'edit_draft?id=' + encodeURIComponent(draftHistoryTargetId);
                            })
                            .catch(function (err) {
                                alert('Could not restore version: ' + err.message);
                                btn.disabled = false;
                            });
                    });
                });
            })
            .catch(function (err) {
                listEl.innerHTML = '<p class="px-4 py-6 text-sm text-red-600 text-center">' + (err.message || 'Could not load history') + '</p>';
            });
    }

    function closeDraftHistoryModal() {
        const modal = document.getElementById('draftHistoryModal');
        if (modal) modal.classList.add('hidden');
        draftHistoryTargetId = null;
    }
    <?php endif; ?>

    document.addEventListener('DOMContentLoaded', () => {
        const m = document.getElementById('deleteModal');
        m.addEventListener('click', (e) => { if (e.target === m) closeDeleteModal(); });
        <?php if ($listView === 'drafts'): ?>
        const hm = document.getElementById('draftHistoryModal');
        if (hm) hm.addEventListener('click', (e) => { if (e.target === hm) closeDraftHistoryModal(); });
        <?php endif; ?>
        if (typeof window.refreshAppShellIcons === 'function') window.refreshAppShellIcons();
    });
</script>

<?php include '../../includes/footer.php'; ?>
