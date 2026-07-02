<?php
require_once __DIR__ . '/../../../config/app.php';
checkAuth();
require_once __DIR__ . '/../../../config/database.php';

if (!hasPermission('view_departments')) {
    die("Access Denied.");
}

include '../../../includes/header.php';

$depts = $pdo->query("SELECT * FROM departments ORDER BY name ASC")->fetchAll();
?>

<div class="list-toolbar mb-6">
    <div class="min-w-0">
        <h1 class="text-3xl font-bold text-gray-800 break-words">Departments</h1>
        <p class="text-sm text-gray-500 mt-1">Keep department records readable on desktop and easy to manage from mobile cards.</p>
    </div>
    <div class="list-toolbar-actions">
        <a href="create" class="list-action-btn bg-green-600 text-white" aria-label="Add department">
            <i class="material-icons sm:mr-1">add</i>
            <span class="hidden sm:inline">Add Department</span>
        </a>
    </div>
</div>

<div class="list-view-shell">
    <div class="list-mobile-stack">
        <?php foreach ($depts as $d): ?>
        <div class="list-mobile-card">
            <div class="flex items-start justify-between gap-3">
                <div class="min-w-0">
                    <p class="list-card-title"><?php echo htmlspecialchars($d['name']); ?></p>
                    <p class="list-card-meta mt-1">Department Name</p>
                </div>
            </div>
            <div class="list-row-actions two-up mt-4">
                <a href="edit?id=<?php echo $d['id']; ?>" class="list-icon-action bg-blue-600 text-white" aria-label="Edit department">
                    <i class="material-icons text-sm">edit</i>
                </a>
                <a href="#" onclick="openConfirmModal('Delete Section', 'Are you sure you want to delete the <?php echo addslashes($d['name']); ?> section? This may fail if members are still assigned.', 'delete?id=<?php echo $d['id']; ?>'); return false;" class="list-icon-action bg-red-600 text-white" aria-label="Delete department">
                    <i class="material-icons text-sm">delete</i>
                </a>
            </div>
        </div>
        <?php endforeach; ?>
        <?php if (empty($depts)): ?>
        <div class="list-mobile-card text-center text-gray-500">
            <i class="material-icons text-gray-400 text-4xl mb-2 block">account_tree</i>
            No departments found.
        </div>
        <?php endif; ?>
    </div>

    <div class="list-desktop-table overflow-x-auto">
    <table class="divide-y divide-gray-200">
        <thead class="bg-gray-50">
            <tr>
                <th class="text-left text-xs font-medium text-gray-500 uppercase">Name</th>
                <th class="text-right text-xs font-medium text-gray-500 uppercase">Actions</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-200 bg-white">
            <?php foreach ($depts as $d): ?>
            <tr>
                <td class="text-sm text-gray-900 cell-wrap"><?php echo htmlspecialchars($d['name']); ?></td>
                <td class="text-right text-sm font-medium">
                    <div class="flex items-center justify-end space-x-2">
                        <a href="edit?id=<?php echo $d['id']; ?>" class="text-gray-400 hover:text-blue-600 transition-colors" title="Edit Department">
                            <i class="material-icons">edit</i>
                        </a>
                        <a href="#"
                           onclick="openConfirmModal('Delete Section', 'Are you sure you want to delete the <?php echo addslashes($d['name']); ?> section? This may fail if members are still assigned.', 'delete?id=<?php echo $d['id']; ?>'); return false;"
                           class="text-gray-400 hover:text-red-600 transition-colors" title="Delete Department">
                            <i class="material-icons">delete</i>
                        </a>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($depts)): ?>
            <tr>
                <td colspan="2" class="text-center text-gray-500">No departments found.</td>
            </tr>
            <?php endif; ?>
        </tbody>
    </table>
    </div>
</div>

<?php include '../../../includes/footer.php'; ?>
