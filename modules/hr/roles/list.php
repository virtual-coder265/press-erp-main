<?php
require_once __DIR__ . '/../../../config/app.php';
checkAuth();
require_once __DIR__ . '/../../../config/database.php';

if (!hasPermission('view_roles')) {
    die("Access Denied.");
}

include '../../../includes/header.php';

$roles = $pdo->query("SELECT * FROM roles ORDER BY name ASC")->fetchAll();
?>

<div class="list-toolbar mb-6">
    <div class="min-w-0">
        <h1 class="text-3xl font-bold text-gray-800 break-words">Roles & Permissions</h1>
        <p class="text-sm text-gray-500 mt-1">Keep security roles manageable across wide tables on desktop and tap-friendly cards on mobile.</p>
    </div>
    <div class="list-toolbar-actions">
        <?php if (hasPermission('manage_roles')): ?>
        <a href="create" class="list-action-btn bg-green-600 text-white" aria-label="Add role">
            <i class="material-icons sm:mr-1">add</i>
            <span class="hidden sm:inline">Add Role</span>
        </a>
        <?php endif; ?>
    </div>
</div>

<div class="list-view-shell">
    <div class="list-mobile-stack">
        <?php foreach ($roles as $r): ?>
        <div class="list-mobile-card">
            <div class="flex items-start justify-between gap-3">
                <div class="min-w-0">
                    <p class="list-card-title"><?php echo htmlspecialchars($r['name']); ?></p>
                    <p class="list-card-meta mt-1">Role Name</p>
                </div>
            </div>
            <div class="list-row-actions mt-4">
                <?php if (hasPermission('manage_roles')): ?>
                <a href="permissions?id=<?php echo $r['id']; ?>" class="list-icon-action bg-purple-600 text-white" aria-label="Manage permissions">
                    <i class="material-icons text-sm">security</i>
                </a>
                <a href="edit?id=<?php echo $r['id']; ?>" class="list-icon-action bg-blue-600 text-white" aria-label="Edit role">
                    <i class="material-icons text-sm">edit</i>
                </a>
                <a href="#" onclick="openConfirmModal('Delete Role', 'Are you sure you want to delete the <?php echo addslashes($r['name']); ?> role? This action cannot be undone and may fail if users are assigned.', 'delete?id=<?php echo $r['id']; ?>'); return false;" class="list-icon-action bg-red-600 text-white" aria-label="Delete role">
                    <i class="material-icons text-sm">delete</i>
                </a>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
        <?php if (empty($roles)): ?>
        <div class="list-mobile-card text-center text-gray-500">
            <i class="material-icons text-gray-400 text-4xl mb-2 block">admin_panel_settings</i>
            No roles found.
        </div>
        <?php endif; ?>
    </div>

    <div class="list-desktop-table overflow-x-auto">
    <table class="divide-y divide-gray-200">
        <thead class="bg-gray-50">
            <tr>
                <th class="text-left text-xs font-medium text-gray-500 uppercase">Role Name</th>
                <th class="text-right text-xs font-medium text-gray-500 uppercase">Actions</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-200 bg-white">
            <?php foreach ($roles as $r): ?>
            <tr>
                <td class="text-sm text-gray-900 cell-wrap"><?php echo htmlspecialchars($r['name']); ?></td>
                <td class="text-right text-sm font-medium">
                    <div class="flex items-center justify-end space-x-2">
                        <?php if (hasPermission('manage_roles')): ?>
                        <a href="permissions?id=<?php echo $r['id']; ?>" class="text-gray-400 hover:text-purple-600 transition-colors" title="Manage Permissions">
                            <i class="material-icons">security</i>
                        </a>
                        <a href="edit?id=<?php echo $r['id']; ?>" class="text-gray-400 hover:text-blue-600 transition-colors" title="Edit Role">
                            <i class="material-icons">edit</i>
                        </a>
                        <a href="#"
                           onclick="openConfirmModal('Delete Role', 'Are you sure you want to delete the <?php echo addslashes($r['name']); ?> role? This action cannot be undone and may fail if users are assigned.', 'delete?id=<?php echo $r['id']; ?>'); return false;"
                           class="text-gray-400 hover:text-red-600 transition-colors" title="Delete Role">
                            <i class="material-icons">delete</i>
                        </a>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($roles)): ?>
            <tr>
                <td colspan="2" class="text-center text-gray-500">No roles found.</td>
            </tr>
            <?php endif; ?>
        </tbody>
    </table>
    </div>
</div>

<?php include '../../../includes/footer.php'; ?>
