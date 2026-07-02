<?php
require_once __DIR__ . '/../../../config/app.php';
checkAuth();
require_once __DIR__ . '/../../../config/database.php';

checkPermission('manage_roles');

$id = $_GET['id'] ?? 0;
$stmt = $pdo->prepare("SELECT * FROM roles WHERE id = ?");
$stmt->execute([$id]);
$roleRecord = $stmt->fetch();

if (!$roleRecord) {
    redirect('modules/hr/roles/list');
}

// Fetch all permissions grouped by module
$all_perms = $pdo->query("SELECT * FROM permissions ORDER BY module ASC, name ASC")->fetchAll();
$grouped_perms = [];
foreach ($all_perms as $p) {
    $grouped_perms[$p['module']][] = $p;
}

// Fetch currently assigned permissions
$assigned_stmt = $pdo->prepare("SELECT permission_id FROM role_permissions WHERE role_id = ?");
$assigned_stmt->execute([$id]);
$assigned_perms = $assigned_stmt->fetchAll(PDO::FETCH_COLUMN);

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $selected_permissions = $_POST['permissions'] ?? [];
    if (!is_array($selected_permissions)) {
        $selected_permissions = [];
    }
    $selected_permissions = array_values(array_unique(array_filter(array_map('intval', $selected_permissions))));
    
    // Begin transaction
    $pdo->beginTransaction();
    try {
        // Clear old ones
        $del_stmt = $pdo->prepare("DELETE FROM role_permissions WHERE role_id = ?");
        $del_stmt->execute([$id]);

        // Insert new ones
        if (!empty($selected_permissions)) {
            $insert_query = "INSERT INTO role_permissions (role_id, permission_id) VALUES ";
            $insert_values = [];
            $insert_params = [];
            foreach ($selected_permissions as $perm_id) {
                $insert_values[] = "(?, ?)";
                $insert_params[] = $id;
                $insert_params[] = $perm_id;
            }
            $insert_query .= implode(", ", $insert_values);
            $ins_stmt = $pdo->prepare($insert_query);
            $ins_stmt->execute($insert_params);
        }
        
        $pdo->commit();
        
        // If the current user's role was updated, update their session permissions
        if (($_SESSION['role'] ?? null) === $roleRecord['name'] && $roleRecord['name'] !== 'System Admin') {
            $sess_stmt = $pdo->prepare("SELECT p.slug FROM permissions p 
                                        JOIN role_permissions rp ON p.id = rp.permission_id 
                                        WHERE rp.role_id = ?");
            $sess_stmt->execute([$id]);
            $_SESSION['permissions'] = $sess_stmt->fetchAll(PDO::FETCH_COLUMN);
        }
        
        $success = "Permissions successfully updated.";
        // Refresh assigned permissions
        $assigned_stmt->execute([$id]);
        $assigned_perms = $assigned_stmt->fetchAll(PDO::FETCH_COLUMN);
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = "An error occurred while saving permissions.";
    }
}

include '../../../includes/header.php';
?>

<div class="mb-6">
    <a href="list" class="text-blue-600 hover:underline flex items-center mb-4">
        <i class="material-icons text-sm mr-1">arrow_back</i> Back to Roles
    </a>
    <h1 class="text-3xl font-bold text-gray-800 break-words">Manage Permissions: <span class="text-blue-600"><?php echo htmlspecialchars($roleRecord['name']); ?></span></h1>
    <p class="text-sm text-gray-500 mt-1">Select the actions this role is authorized to perform across the system.</p>
</div>

<?php if (isset($error)): ?>
    <div class="bg-red-50 border border-red-200 text-red-600 p-4 rounded mb-6 flex items-center">
        <i class="material-icons mr-2">error</i> <?php echo htmlspecialchars($error); ?>
    </div>
<?php endif; ?>

<?php if (isset($success)): ?>
    <div class="bg-green-50 border border-green-200 text-green-700 p-4 rounded mb-6 flex items-center">
        <i class="material-icons mr-2">check_circle</i> <?php echo htmlspecialchars($success); ?>
    </div>
<?php endif; ?>

<?php if ($roleRecord['name'] === 'System Admin'): ?>
    <div class="bg-blue-50 border border-blue-200 text-blue-800 p-6 rounded-lg mb-6 flex items-start shadow-sm max-w-4xl">
        <i class="material-icons text-blue-500 mr-3 text-3xl">info</i>
        <div>
            <h3 class="font-bold text-lg mb-1">System Admin role</h3>
            <p>The System Admin role bypasses permission checks and has full access to the system natively. You do not need to check boxes below for this role.</p>
        </div>
    </div>
<?php endif; ?>

<form method="POST" action="permissions?id=<?php echo (int) $id; ?>">
    <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-6 mb-8 max-w-7xl">
        <?php foreach ($grouped_perms as $module => $perms): ?>
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden flex flex-col">
            <div class="bg-gray-50 px-5 py-4 border-b border-gray-200 flex items-center gap-2">
                <i class="material-icons text-gray-500">category</i>
                <h3 class="font-bold text-gray-800 text-lg uppercase tracking-wider text-sm"><?php echo htmlspecialchars($module); ?></h3>
            </div>
            <div class="p-5 flex-1">
                <div class="space-y-4">
                    <?php foreach ($perms as $p): ?>
                        <?php $isChecked = in_array($p['id'], $assigned_perms); ?>
                        <label class="flex items-start gap-3 cursor-pointer group">
                            <div class="pt-0.5">
                                <input type="checkbox" name="permissions[]" value="<?php echo $p['id']; ?>" class="w-5 h-5 text-blue-600 rounded border-gray-300 focus:ring-blue-500 cursor-pointer" <?php echo $isChecked ? 'checked' : ''; ?>>
                            </div>
                            <div>
                                <div class="font-medium text-gray-900 group-hover:text-blue-700 transition"><?php echo htmlspecialchars($p['name']); ?></div>
                                <div class="text-sm text-gray-500 mt-0.5"><?php echo htmlspecialchars($p['description']); ?></div>
                            </div>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    
    <div class="sticky bottom-0 bg-white/90 backdrop-blur-md p-4 border-t border-gray-200 shadow-[0_-4px_6px_-1px_rgba(0,0,0,0.05)] rounded-t-xl max-w-7xl flex items-center justify-between">
        <span class="text-sm text-gray-500">Unsaved changes will be lost if you leave this page.</span>
        <button type="submit" class="bg-blue-600 text-white px-8 py-3 rounded-lg font-bold hover:bg-blue-700 transition shadow flex items-center">
            <i class="material-icons mr-2">save</i> Save Permissions
        </button>
    </div>
</form>

<?php include '../../../includes/footer.php'; ?>
