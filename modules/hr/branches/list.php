<?php
require_once __DIR__ . '/../../../config/app.php';
checkAuth();
require_once __DIR__ . '/../../../config/database.php';

if (!hasPermission('view_branches')) {
    die("Access Denied.");
}

include '../../../includes/header.php';

$branches = $pdo->query("SELECT * FROM branches ORDER BY name ASC")->fetchAll();
?>

<div class="list-toolbar mb-6">
    <div class="min-w-0">
        <h1 class="text-3xl font-bold text-gray-800 break-words">Branches</h1>
        <p class="text-sm text-gray-500 mt-1">Maintain branch locations without forcing wide table layouts on smaller screens.</p>
    </div>
    <div class="list-toolbar-actions">
        <a href="create" class="list-action-btn bg-green-600 text-white" aria-label="Add branch">
            <i class="material-icons sm:mr-1">add</i>
            <span class="hidden sm:inline">Add Branch</span>
        </a>
    </div>
</div>

<div class="list-view-shell">
    <div class="list-mobile-stack">
        <?php foreach ($branches as $b): ?>
        <div class="list-mobile-card">
            <div class="flex items-start justify-between gap-3">
                <div class="min-w-0">
                    <p class="list-card-title"><?php echo htmlspecialchars($b['name']); ?></p>
                    <p class="list-card-meta mt-1">Branch Name</p>
                </div>
            </div>
            <div class="list-row-actions two-up mt-4">
                <a href="edit?id=<?php echo $b['id']; ?>" class="list-icon-action bg-blue-600 text-white" aria-label="Edit branch">
                    <i class="material-icons text-sm">edit</i>
                </a>
                <a href="#" onclick="openConfirmModal('Delete Branch', 'Are you sure you want to delete the <?php echo addslashes($b['name']); ?> branch? This may fail if users are still assigned to it.', 'delete?id=<?php echo $b['id']; ?>'); return false;" class="list-icon-action bg-red-600 text-white" aria-label="Delete branch">
                    <i class="material-icons text-sm">delete</i>
                </a>
            </div>
        </div>
        <?php endforeach; ?>
        <?php if (empty($branches)): ?>
        <div class="list-mobile-card text-center text-gray-500">
            <i class="material-icons text-gray-400 text-4xl mb-2 block">business</i>
            No branches found.
        </div>
        <?php endif; ?>
    </div>

    <div class="list-desktop-table overflow-x-auto">
    <table class="divide-y divide-gray-200">
        <thead class="bg-gray-50">
            <tr>
                <th class="text-left text-xs font-medium text-gray-500 uppercase">Branch Name</th>
                <th class="text-right text-xs font-medium text-gray-500 uppercase">Actions</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-200 bg-white">
            <?php foreach ($branches as $b): ?>
            <tr>
                <td class="text-sm text-gray-900 cell-wrap"><?php echo htmlspecialchars($b['name']); ?></td>
                <td class="text-right text-sm font-medium">
                    <div class="flex items-center justify-end space-x-2">
                        <a href="edit?id=<?php echo $b['id']; ?>" class="text-gray-400 hover:text-blue-600 transition-colors" title="Edit Branch">
                            <i class="material-icons">edit</i>
                        </a>
                        <a href="#"
                           onclick="openConfirmModal('Delete Branch', 'Are you sure you want to delete the <?php echo addslashes($b['name']); ?> branch? This may fail if users are still assigned to it.', 'delete?id=<?php echo $b['id']; ?>'); return false;"
                           class="text-gray-400 hover:text-red-600 transition-colors" title="Delete Branch">
                            <i class="material-icons">delete</i>
                        </a>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($branches)): ?>
            <tr>
                <td colspan="2" class="text-center text-gray-500">No branches found.</td>
            </tr>
            <?php endif; ?>
        </tbody>
    </table>
    </div>
</div>

<?php include '../../../includes/footer.php'; ?>
