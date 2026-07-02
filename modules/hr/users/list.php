<?php
require_once __DIR__ . '/../../../config/app.php';
checkAuth();
require_once __DIR__ . '/../../../config/database.php';

// Only Admin
if (!hasPermission('view_users')) {
    die("Access Denied.");
}

include '../../../includes/header.php';

$error_message = $_SESSION['hr_users_error'] ?? '';
unset($_SESSION['hr_users_error']);
$delete_user_csrf = csrf_token('delete_user');

// Search functionality
$search_query = $_GET['search'] ?? '';
$role_filter = $_GET['role'] ?? '';
$dept_filter = $_GET['department'] ?? '';

$query = "SELECT u.*, r.name as role_name, d.name as dept_name, b.name as branch_name 
          FROM users u 
          JOIN roles r ON u.role_id = r.id 
          JOIN departments d ON u.department_id = d.id 
          LEFT JOIN branches b ON u.branch_id = b.id 
          WHERE 1=1";

$params = [];

if (!empty($search_query)) {
    $query .= " AND (u.name LIKE :search OR u.email LIKE :search)";
    $params['search'] = '%' . $search_query . '%';
}

if (!empty($role_filter)) {
    $query .= " AND u.role_id = :role";
    $params['role'] = $role_filter;
}

if (!empty($dept_filter)) {
    $query .= " AND u.department_id = :dept";
    $params['dept'] = $dept_filter;
}

$query .= " ORDER BY u.name ASC";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$users = $stmt->fetchAll();

// Get roles and departments for filters
$roles = $pdo->query("SELECT * FROM roles")->fetchAll();
$depts = $pdo->query("SELECT * FROM departments")->fetchAll();
?>

<div class="list-toolbar mb-6">
    <div class="min-w-0">
        <h1 class="text-3xl font-bold text-gray-800 break-words">User Management</h1>
        <p class="text-sm text-gray-500 mt-1">Manage staff accounts, roles, departments, and assigned branches.</p>
    </div>
    <div class="list-toolbar-actions">
        <a href="create" class="list-action-btn bg-green-600 text-white" aria-label="Add user">
            <i class="material-icons sm:mr-1">add</i>
            <span class="hidden sm:inline">Add User</span>
        </a>
    </div>
</div>

<?php if ($error_message): ?>
<div class="mb-6 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
    <?php echo htmlspecialchars($error_message); ?>
</div>
<?php endif; ?>

<form id="deleteUserForm" method="POST" action="delete" class="hidden">
    <input type="hidden" name="id" id="deleteUserId" value="">
    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($delete_user_csrf); ?>">
</form>

<!-- Search & Filter Bar -->
<div class="bg-white shadow rounded-lg p-6 mb-6">
    <form method="GET" action="" class="list-filters-grid lg:grid-cols-12">
        <div class="lg:col-span-4 min-w-0">
            <input type="text" name="search" placeholder="Search by name or email..." 
                   class="w-full px-4 py-3 border border-gray-300 rounded-lg"
                   value="<?php echo htmlspecialchars($search_query); ?>">
        </div>
        <div class="lg:col-span-3">
            <select name="role" class="w-full px-4 py-3 border border-gray-300 rounded-lg">
                <option value="">All Roles</option>
                <?php foreach($roles as $r): ?>
                <option value="<?php echo $r['id']; ?>" <?php echo $role_filter == $r['id'] ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($r['name']); ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="lg:col-span-3">
            <select name="department" class="w-full px-4 py-3 border border-gray-300 rounded-lg">
                <option value="">All Departments</option>
                <?php foreach($depts as $d): ?>
                <option value="<?php echo $d['id']; ?>" <?php echo $dept_filter == $d['id'] ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($d['name']); ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="lg:col-span-2 flex flex-col sm:flex-row gap-2">
            <button type="submit" class="flex-1 bg-blue-600 text-white px-4 py-3 rounded hover:bg-blue-700 transition flex items-center justify-center" aria-label="Search users">
                <i class="material-icons sm:mr-2">search</i>
                <span class="hidden sm:inline">Search</span>
            </button>
            <?php if($search_query || $role_filter || $dept_filter): ?>
            <a href="list" class="bg-gray-300 text-gray-700 px-4 py-3 rounded hover:bg-gray-400 transition flex items-center justify-center" aria-label="Clear user filters">
                <i class="material-icons sm:mr-2">close</i>
                <span class="hidden sm:inline">Clear</span>
            </a>
            <?php endif; ?>
        </div>
    </form>
</div>

<div class="list-view-shell">
    <div class="list-mobile-stack">
        <?php foreach ($users as $u): ?>
        <div class="list-mobile-card">
            <div class="flex items-start justify-between gap-3">
                <div class="flex items-center gap-3 min-w-0">
                    <?php if (!empty($u['photo']) && $u['photo'] !== 'default.png'): ?>
                        <img src="<?php echo htmlspecialchars(BASE_URL . ltrim($u['photo'], '/')); ?>" alt="<?php echo htmlspecialchars($u['name']); ?>" class="w-10 h-10 rounded-full object-cover flex-shrink-0">
                    <?php else: ?>
                        <span class="w-10 h-10 rounded-full bg-gradient-to-br from-teal-600 to-blue-600 flex items-center justify-center text-white font-bold text-sm flex-shrink-0"><?php echo htmlspecialchars(strtoupper(substr($u['name'], 0, 1))); ?></span>
                    <?php endif; ?>
                    <div class="min-w-0">
                        <p class="list-card-title"><?php echo htmlspecialchars($u['name']); ?></p>
                        <p class="text-sm text-gray-500 mt-1 break-words"><?php echo htmlspecialchars($u['email']); ?></p>
                    </div>
                </div>
                <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-semibold bg-blue-50 text-blue-700 flex-shrink-0">
                    <?php echo htmlspecialchars($u['role_name']); ?>
                </span>
            </div>
            <div class="grid grid-cols-1 gap-3 mt-4 text-sm">
                <div>
                    <p class="list-card-meta">Department</p>
                    <p class="list-card-value"><?php echo htmlspecialchars($u['dept_name']); ?></p>
                </div>
                <div>
                    <p class="list-card-meta">Branch</p>
                    <p class="list-card-value"><?php echo $u['branch_name'] ? htmlspecialchars($u['branch_name']) : 'Not set'; ?></p>
                </div>
            </div>
            <div class="list-row-actions two-up mt-4">
                <a href="edit?id=<?php echo $u['id']; ?>" class="list-icon-action bg-blue-600 text-white" aria-label="Edit user">
                    <i class="material-icons text-sm">edit</i>
                </a>
                <a href="#" onclick="document.getElementById('deleteUserId').value = '<?php echo $u['id']; ?>'; openConfirmModal('Delete User', 'Are you sure you want to delete <?php echo addslashes($u['name']); ?>? This action cannot be undone.', 'form:deleteUserForm'); return false;" class="list-icon-action bg-red-600 text-white" aria-label="Delete user">
                    <i class="material-icons text-sm">delete</i>
                </a>
            </div>
        </div>
        <?php endforeach; ?>
        <?php if (empty($users)): ?>
        <div class="list-mobile-card text-center text-gray-500">
            <i class="material-icons text-gray-400 text-4xl mb-2 block">people</i>
            No users found.
        </div>
        <?php endif; ?>
    </div>

    <div class="list-desktop-table overflow-x-auto">
    <table class="divide-y divide-gray-200">
        <thead class="bg-gray-50">
            <tr>
                <th class="text-left text-xs font-medium text-gray-500 uppercase">Name</th>
                <th class="text-left text-xs font-medium text-gray-500 uppercase">Email</th>
                <th class="text-left text-xs font-medium text-gray-500 uppercase">Role</th>
                <th class="text-left text-xs font-medium text-gray-500 uppercase">Department (Section)</th>
                <th class="text-left text-xs font-medium text-gray-500 uppercase">Branch</th>
                <th class="text-right text-xs font-medium text-gray-500 uppercase">Actions</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-200 bg-white">
            <?php foreach ($users as $u): ?>
            <tr>
                <td class="text-sm text-gray-900 cell-wrap">
                    <div class="flex items-center gap-3">
                        <?php if (!empty($u['photo']) && $u['photo'] !== 'default.png'): ?>
                            <img src="<?php echo htmlspecialchars(BASE_URL . ltrim($u['photo'], '/')); ?>" alt="<?php echo htmlspecialchars($u['name']); ?>" class="w-8 h-8 rounded-full object-cover flex-shrink-0">
                        <?php else: ?>
                            <span class="w-8 h-8 rounded-full bg-gradient-to-br from-teal-600 to-blue-600 flex items-center justify-center text-white font-bold text-xs flex-shrink-0"><?php echo htmlspecialchars(strtoupper(substr($u['name'], 0, 1))); ?></span>
                        <?php endif; ?>
                        <?php echo htmlspecialchars($u['name']); ?>
                    </div>
                </td>
                <td class="text-sm text-gray-500 cell-wrap"><?php echo htmlspecialchars($u['email']); ?></td>
                <td class="text-sm text-gray-500 cell-wrap"><?php echo htmlspecialchars($u['role_name']); ?></td>
                <td class="text-sm text-gray-500 cell-wrap"><?php echo htmlspecialchars($u['dept_name']); ?></td>
                <td class="text-sm text-gray-500 cell-wrap"><?php echo $u['branch_name'] ? htmlspecialchars($u['branch_name']) : '<span class="text-gray-400">Not set</span>'; ?></td>
                <td class="text-right text-sm font-medium">
                    <div class="flex items-center justify-end space-x-2">
                        <a href="edit?id=<?php echo $u['id']; ?>" class="text-gray-400 hover:text-blue-600 transition-colors" title="Edit User">
                            <i class="material-icons">edit</i>
                        </a>
                        <a href="#" onclick="document.getElementById('deleteUserId').value = '<?php echo $u['id']; ?>'; openConfirmModal('Delete User', 'Are you sure you want to delete <?php echo addslashes($u['name']); ?>? This action cannot be undone.', 'form:deleteUserForm'); return false;" 
                           class="text-gray-400 hover:text-red-600 transition-colors" title="Delete User">
                            <i class="material-icons">delete</i>
                        </a>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($users)): ?>
            <tr>
                <td colspan="6" class="text-center text-gray-500">No users found.</td>
            </tr>
            <?php endif; ?>
        </tbody>
    </table>
    </div>
</div>

<?php include '../../../includes/footer.php'; ?>
