<?php
require_once __DIR__ . '/../../config/app.php';
checkAuth();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/permissions_helper.php';
permissions_require_one_of(['view_dispatch']);
require_once __DIR__ . '/../../includes/work_order_helper.php';
require_once __DIR__ . '/../../includes/module_kpi_helper.php';

include '../../includes/header.php';
work_order_bootstrap($pdo);
$kpis = dispatch_module_kpis($pdo);

// Search and filter functionality
$search_query = $_GET['search'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$work_order_filter = $_GET['work_order'] ?? '';

$query = "SELECT d.*, u1.name as authorised_dispatcher_name, u2.name as created_by_name, wo.status AS work_order_status
          FROM dispatch_register d 
          LEFT JOIN users u1 ON d.authorised_dispatcher_id = u1.id 
          LEFT JOIN users u2 ON d.created_by = u2.id 
          LEFT JOIN work_orders wo ON d.work_order_id = wo.id
          WHERE 1=1";

$params = [];

if (!empty($search_query)) {
    $query .= " AND (d.work_order_number LIKE :search OR d.ministry_department LIKE :search OR d.job_description LIKE :search OR d.delivery_note_number LIKE :search OR d.remarks LIKE :search)";
    $params['search'] = '%' . $search_query . '%';
}

if (!empty($work_order_filter)) {
    $query .= " AND d.work_order_number LIKE :work_order";
    $params['work_order'] = '%' . $work_order_filter . '%';
}

if (!empty($date_from)) {
    $query .= " AND d.date_in >= :date_from";
    $params['date_from'] = $date_from;
}

if (!empty($date_to)) {
    $query .= " AND d.date_in <= :date_to";
    $params['date_to'] = $date_to;
}

$query .= " ORDER BY d.date_in DESC, d.created_at DESC";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$dispatches = $stmt->fetchAll();

// Check for import errors in session
$import_errors = $_SESSION['import_errors'] ?? null;
if ($import_errors) {
    unset($_SESSION['import_errors']);
}
?>

<?php if ($import_errors): ?>
<div class="bg-yellow-50 border border-yellow-200 rounded-lg p-4 mb-6">
    <h3 class="text-lg font-bold text-yellow-800 mb-2 flex items-center">
        <i class="material-icons mr-2">warning</i> Import Errors
    </h3>
    <ul class="list-disc list-inside text-sm text-yellow-700 space-y-1 max-h-40 overflow-y-auto">
        <?php foreach ($import_errors as $error): ?>
        <li><?php echo htmlspecialchars($error); ?></li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<?php if (isset($_GET['success'])): ?>
<div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded relative mb-6" role="alert">
    <strong class="font-bold">Success!</strong>
    <span class="block sm:inline"><?php echo htmlspecialchars($_GET['success']); ?></span>
</div>
<?php endif; ?>

<?php if (isset($_GET['error'])): ?>
<div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative mb-6" role="alert">
    <strong class="font-bold">Error!</strong>
    <span class="block sm:inline"><?php echo htmlspecialchars($_GET['error']); ?></span>
</div>
<?php endif; ?>

<?php include __DIR__ . '/../../includes/partials/module_kpi_strip.php'; ?>

<div class="list-toolbar mb-6">
    <div class="min-w-0">
        <h1 class="text-3xl font-bold text-gray-800 break-words">Dispatch Register</h1>
        <p class="text-sm text-gray-500 mt-1">Manage all dispatch entries and records</p>
    </div>
    <div class="list-toolbar-actions">
        <div class="relative inline-block">
            <button id="exportDropdown" type="button" class="list-action-btn is-export" aria-label="Export dispatch records">
                <i data-lucide="download" class="sm:mr-1 inline-block h-4 w-4" aria-hidden="true"></i>
                <span class="hidden sm:inline">Export</span>
            </button>
            <div id="exportMenu" class="hidden absolute right-0 mt-2 w-48 bg-white rounded-md shadow-lg z-10 border border-gray-200">
                <a href="export?format=pdf<?php echo !empty($search_query) ? '&search=' . urlencode($search_query) : ''; ?><?php echo !empty($work_order_filter) ? '&work_order=' . urlencode($work_order_filter) : ''; ?><?php echo !empty($date_from) ? '&date_from=' . urlencode($date_from) : ''; ?><?php echo !empty($date_to) ? '&date_to=' . urlencode($date_to) : ''; ?>"
                   class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">Export as PDF</a>
                <a href="export?format=excel<?php echo !empty($search_query) ? '&search=' . urlencode($search_query) : ''; ?><?php echo !empty($work_order_filter) ? '&work_order=' . urlencode($work_order_filter) : ''; ?><?php echo !empty($date_from) ? '&date_from=' . urlencode($date_from) : ''; ?><?php echo !empty($date_to) ? '&date_to=' . urlencode($date_to) : ''; ?>"
                   class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">Export as Excel</a>
                <a href="export?format=csv<?php echo !empty($search_query) ? '&search=' . urlencode($search_query) : ''; ?><?php echo !empty($work_order_filter) ? '&work_order=' . urlencode($work_order_filter) : ''; ?><?php echo !empty($date_from) ? '&date_from=' . urlencode($date_from) : ''; ?><?php echo !empty($date_to) ? '&date_to=' . urlencode($date_to) : ''; ?>"
                   class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">Export as CSV</a>
            </div>
        </div>
        <a href="import" class="list-action-btn is-export" aria-label="Import dispatch entries">
            <i data-lucide="upload" class="sm:mr-1 inline-block h-4 w-4" aria-hidden="true"></i>
            <span class="hidden sm:inline">Import</span>
        </a>
        <a href="create" class="list-action-btn is-create text-white" aria-label="Create dispatch entry">
            <i data-lucide="plus" class="sm:mr-1 inline-block h-4 w-4" aria-hidden="true"></i>
            <span class="hidden sm:inline">New Entry</span>
        </a>
    </div>
</div>

<!-- Search & Filter Bar -->
<div class="bg-white shadow rounded-lg p-5 mb-6">
    <form method="GET" action="">
        <div class="grid grid-cols-1 md:grid-cols-12 gap-4">
            <div class="md:col-span-5 min-w-0">
                <label class="block text-xs font-medium text-gray-500 mb-1">Search Keywords</label>
                <div class="relative">
                    <i class="material-icons absolute left-3 top-2.5 text-gray-400">search</i>
                    <input type="text" name="search" placeholder="Work Order, Ministry, Desc..." 
                           class="w-full min-w-0 pl-10 pr-4 py-3 border border-gray-300 rounded-lg"
                           value="<?php echo htmlspecialchars($search_query); ?>">
                </div>
            </div>
            <div class="md:col-span-2">
                <label class="block text-xs font-medium text-gray-500 mb-1">Date From</label>
                <?php echo press_datetime_picker_field([
                    'name' => 'date_from',
                    'value' => $date_from,
                    'mode' => 'date',
                    'class' => 'w-full px-3 py-3 border border-gray-300 rounded-lg',
                ]); ?>
            </div>
            <div class="md:col-span-2">
                <label class="block text-xs font-medium text-gray-500 mb-1">Date To</label>
                <?php echo press_datetime_picker_field([
                    'name' => 'date_to',
                    'value' => $date_to,
                    'mode' => 'date',
                    'class' => 'w-full px-3 py-3 border border-gray-300 rounded-lg',
                ]); ?>
            </div>
            <div class="md:col-span-3 flex items-end gap-2 flex-col sm:flex-row">
                <button type="submit" class="w-full sm:flex-1 bg-green-600 text-white px-4 py-3 rounded-lg hover:bg-green-700 transition flex items-center justify-center" aria-label="Filter dispatch records">
                    <i class="material-icons sm:mr-2">search</i>
                    <span class="hidden sm:inline">Filter</span>
                </button>
                <?php if($search_query || $work_order_filter || $date_from || $date_to): ?>
                <a href="list" class="w-full sm:w-auto bg-gray-200 text-gray-700 px-4 py-3 rounded-lg hover:bg-gray-300 transition flex items-center justify-center" title="Clear Filters" aria-label="Clear dispatch filters">
                    <i class="material-icons sm:mr-2 text-gray-500">close</i>
                    <span class="hidden sm:inline">Clear</span>
                </a>
                <?php endif; ?>
            </div>
        </div>
    </form>
</div>

<!-- Dispatch Register Table -->
<form method="POST" action="bulk_delete" id="bulkActionForm">
    <div class="bg-white shadow rounded-lg overflow-hidden">
        <!-- Table Header Control Bar -->
        <div class="px-6 py-4 border-b border-gray-200 bg-gray-50 flex justify-between items-center hidden" id="bulkActionBar">
            <div class="flex items-center text-sm text-gray-600">
                <span id="selectedCount" class="font-bold mr-1">0</span> items selected
            </div>
            <button type="submit" onclick="return confirm('Are you sure you want to delete the selected items?');" class="text-red-600 hover:text-red-800 text-sm font-medium flex items-center">
                <i class="material-icons mr-1 text-base">delete</i> Delete Selected
            </button>
        </div>

        <div class="md:hidden divide-y divide-gray-100">
            <?php foreach ($dispatches as $dispatch): ?>
            <div class="p-4 space-y-4">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <p class="text-sm font-semibold text-gray-900 break-words"><?php echo htmlspecialchars($dispatch['work_order_number'] ?: 'No work order'); ?></p>
                        <?php if (!empty($dispatch['work_order_status'])): ?>
                            <p class="text-xs text-indigo-600 mt-1"><?php echo htmlspecialchars($dispatch['work_order_status']); ?></p>
                        <?php endif; ?>
                        <p class="text-xs text-gray-500 mt-1"><?php echo date('M d, Y', strtotime($dispatch['date_in'])); ?> at <?php echo date('h:i A', strtotime($dispatch['created_at'])); ?></p>
                    </div>
                    <?php if ($dispatch['date_out']): ?>
                        <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium bg-green-100 text-green-800 flex-shrink-0">
                            Dispatched
                        </span>
                    <?php else: ?>
                        <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium bg-yellow-100 text-yellow-800 flex-shrink-0">
                            Pending
                        </span>
                    <?php endif; ?>
                </div>

                <div class="grid grid-cols-1 gap-2 text-sm">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wide text-gray-400">Ministry</p>
                        <p class="text-gray-700 break-words"><?php echo htmlspecialchars($dispatch['ministry_department']); ?></p>
                    </div>
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wide text-gray-400">Job Description</p>
                        <p class="text-gray-700 break-words"><?php echo htmlspecialchars($dispatch['job_description'] ?? '-'); ?></p>
                    </div>
                    <?php if (!empty($dispatch['remarks'])): ?>
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wide text-gray-400">Remarks</p>
                        <p class="text-gray-600 break-words"><?php echo htmlspecialchars($dispatch['remarks']); ?></p>
                    </div>
                    <?php endif; ?>
                </div>

                <div class="flex items-center justify-between gap-3 text-xs text-gray-500">
                    <span>Qty: <?php echo number_format($dispatch['quantity']); ?></span>
                    <?php if ($dispatch['date_out']): ?>
                        <span>Out: <?php echo date('M d', strtotime($dispatch['date_out'])); ?></span>
                    <?php endif; ?>
                </div>

                <div class="grid grid-cols-3 gap-2">
                    <a href="view?id=<?php echo $dispatch['id']; ?>" class="bg-blue-600 text-white px-3 py-3 rounded-lg flex items-center justify-center" title="View dispatch" aria-label="View dispatch">
                        <i class="material-icons text-sm">visibility</i>
                    </a>
                    <a href="edit?id=<?php echo $dispatch['id']; ?>" class="bg-gray-700 text-white px-3 py-3 rounded-lg flex items-center justify-center" title="Edit dispatch" aria-label="Edit dispatch">
                        <i class="material-icons text-sm">edit</i>
                    </a>
                    <a href="#" onclick="openConfirmModal('Delete Entry', 'Are you sure you want to delete this dispatch entry?', 'delete?id=<?php echo $dispatch['id']; ?>'); return false;" class="bg-red-600 text-white px-3 py-3 rounded-lg flex items-center justify-center" title="Delete dispatch" aria-label="Delete dispatch">
                        <i class="material-icons text-sm">delete</i>
                    </a>
                </div>
            </div>
            <?php endforeach; ?>

            <?php if (empty($dispatches)): ?>
            <div class="px-6 py-12 text-center text-gray-500">
                <div class="flex flex-col items-center justify-center">
                    <div class="bg-gray-100 p-4 rounded-full mb-3">
                        <i class="material-icons text-gray-400 text-4xl">inventory_2</i>
                    </div>
                    <h3 class="text-lg font-medium text-gray-900">No dispatch entries found</h3>
                    <p class="text-sm text-gray-500 mt-1">Try adjusting your search or filters to find what you're looking for.</p>
                    <div class="mt-4">
                        <a href="create" class="inline-flex items-center px-4 py-3 rounded-md shadow-sm text-sm font-medium text-white bg-green-600 hover:bg-green-700">
                            <i class="material-icons mr-2 text-sm">add</i> New Entry
                        </a>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <div class="hidden md:block overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th scope="col" class="px-6 py-3 text-left w-10">
                            <input type="checkbox" id="selectAll" class="rounded border-gray-300 text-green-600 shadow-sm focus:border-green-300 focus:ring focus:ring-green-200 focus:ring-opacity-50">
                        </th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date In</th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Work Order</th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider md:table-cell hidden">Ministry</th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Job Description</th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider lg:table-cell hidden">Status/Out</th>
                        <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php foreach ($dispatches as $dispatch): ?>
                    <tr class="hover:bg-gray-50 group transition-colors">
                        <td class="px-6 py-4 whitespace-nowrap">
                            <input type="checkbox" name="ids[]" value="<?php echo $dispatch['id']; ?>" class="row-checkbox rounded border-gray-300 text-green-600 shadow-sm focus:border-green-300 focus:ring focus:ring-green-200 focus:ring-opacity-50">
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                            <div class="font-medium text-gray-900"><?php echo date('M d, Y', strtotime($dispatch['date_in'])); ?></div>
                            <div class="text-xs text-gray-400"><?php echo date('h:i A', strtotime($dispatch['created_at'])); ?></div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                            <?php echo htmlspecialchars($dispatch['work_order_number'] ?: '-'); ?>
                            <div class="text-xs font-normal text-gray-500 lg:hidden mt-0.5">
                                <?php echo htmlspecialchars($dispatch['ministry_department']); ?>
                            </div>
                            <?php if (!empty($dispatch['work_order_status'])): ?>
                                <div class="text-xs font-normal text-indigo-600 mt-0.5"><?php echo htmlspecialchars($dispatch['work_order_status']); ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="px-6 py-4 text-sm text-gray-500 md:table-cell hidden">
                            <div class="truncate max-w-xs" title="<?php echo htmlspecialchars($dispatch['ministry_department']); ?>">
                                <?php echo htmlspecialchars($dispatch['ministry_department']); ?>
                            </div>
                        </td>
                        <td class="px-6 py-4 text-sm text-gray-500">
                            <div class="truncate max-w-xs" title="<?php echo htmlspecialchars($dispatch['job_description'] ?? ''); ?>">
                                <?php echo htmlspecialchars($dispatch['job_description'] ?? '-'); ?>
                            </div>
                            <?php if (!empty($dispatch['remarks'])): ?>
                                <div class="text-xs text-gray-400 mt-1 truncate max-w-xs">
                                    <i class="material-icons text-[10px] align-middle mr-0.5">comment</i> 
                                    <?php echo htmlspecialchars($dispatch['remarks']); ?>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 lg:table-cell hidden">
                            <?php if ($dispatch['date_out']): ?>
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
                                    Dispatched <?php echo date('M d', strtotime($dispatch['date_out'])); ?>
                                </span>
                            <?php else: ?>
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-yellow-100 text-yellow-800">
                                    Pending
                                </span>
                            <?php endif; ?>
                            <div class="text-xs mt-1 text-gray-400">Qty: <?php echo number_format($dispatch['quantity']); ?></div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                            <div class="flex items-center justify-end space-x-2">
                                <a href="view?id=<?php echo $dispatch['id']; ?>" class="text-gray-400 hover:text-blue-600 transition-colors" title="View Details">
                                    <i class="material-icons">visibility</i>
                                </a>
                                <a href="edit?id=<?php echo $dispatch['id']; ?>" class="text-gray-400 hover:text-green-600 transition-colors" title="Edit">
                                    <i class="material-icons">edit</i>
                                </a>
                                <a href="#" onclick="openConfirmModal('Delete Entry', 'Are you sure you want to delete this dispatch entry?', 'delete?id=<?php echo $dispatch['id']; ?>'); return false;" 
                                   class="text-gray-400 hover:text-red-600 transition-colors" title="Delete">
                                    <i class="material-icons">delete</i>
                                </a>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    
                    <?php if (empty($dispatches)): ?>
                    <tr>
                        <td colspan="7" class="px-6 py-12 text-center text-gray-500">
                            <div class="flex flex-col items-center justify-center">
                                <div class="bg-gray-100 p-4 rounded-full mb-3">
                                    <i class="material-icons text-gray-400 text-4xl">inventory_2</i>
                                </div>
                                <h3 class="text-lg font-medium text-gray-900">No dispatch entries found</h3>
                                <p class="text-sm text-gray-500 mt-1">Try adjusting your search or filters to find what you're looking for.</p>
                                <div class="mt-4">
                                    <a href="create" class="inline-flex items-center px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-green-600 hover:bg-green-700 focus:outline-none">
                                        <i class="material-icons mr-2 text-sm">add</i> New Entry
                                    </a>
                                </div>
                            </div>
                        </td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        
        <!-- Pagination (Static for now, but good for UI completeness) -->
        <div class="bg-white px-4 py-3 flex items-center justify-between border-t border-gray-200 sm:px-6">
            <div class="hidden sm:flex-1 sm:flex sm:items-center sm:justify-between">
                <div>
                    <p class="text-sm text-gray-700">
                        Showing <span class="font-medium"><?php echo count($dispatches); ?></span> results
                    </p>
                </div>
            </div>
        </div>
    </div>
</form>

<?php include '../../includes/footer.php'; ?>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Export dropdown toggle
    const exportDropdown = document.getElementById('exportDropdown');
    const exportMenu = document.getElementById('exportMenu');
    
    if (exportDropdown && exportMenu) {
        exportDropdown.addEventListener('click', function(e) {
            e.stopPropagation();
            exportMenu.classList.toggle('hidden');
        });
        
        document.addEventListener('click', function(e) {
            if (!exportMenu.classList.contains('hidden') && !exportMenu.contains(e.target)) {
                exportMenu.classList.add('hidden');
            }
        });
    }

    // Bulk Actions Logic
    const selectAll = document.getElementById('selectAll');
    const rowCheckboxes = document.querySelectorAll('.row-checkbox');
    const bulkActionBar = document.getElementById('bulkActionBar');
    const selectedCountSpan = document.getElementById('selectedCount');

    function updateBulkActionBar() {
        const selectedCount = document.querySelectorAll('.row-checkbox:checked').length;
        selectedCountSpan.textContent = selectedCount;
        
        if (selectedCount > 0) {
            bulkActionBar.classList.remove('hidden');
        } else {
            bulkActionBar.classList.add('hidden');
        }
    }

    if (selectAll) {
        selectAll.addEventListener('change', function() {
            rowCheckboxes.forEach(checkbox => {
                checkbox.checked = this.checked;
            });
            updateBulkActionBar();
        });
    }

    rowCheckboxes.forEach(checkbox => {
        checkbox.addEventListener('change', function() {
            updateBulkActionBar();
            // Update "Select All" state
            if (!this.checked) {
                selectAll.checked = false;
            } else if (document.querySelectorAll('.row-checkbox:checked').length === rowCheckboxes.length) {
                selectAll.checked = true;
            }
        });
    });
});
</script>

