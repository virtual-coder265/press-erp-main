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

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $name = trim($_POST['name'] ?? '');

    if (!empty($name)) {
        try {
            $stmt = $pdo->prepare("UPDATE roles SET name = ? WHERE id = ?");
            $stmt->execute([$name, $id]);
            redirect('modules/hr/roles/list?success=updated');
        } catch (PDOException $e) {
            $error = "Failed to update role. Name might be duplicated.";
        }
    } else {
        $error = "Role name is required.";
    }
}

include '../../../includes/header.php';
?>

<div class="mb-6">
    <a href="list" class="text-blue-600 hover:underline flex items-center mb-4">
        <i class="material-icons text-sm mr-1">arrow_back</i> Back to Roles
    </a>
    <h1 class="text-3xl font-bold text-gray-800">Edit Role</h1>
</div>

<div class="bg-white shadow rounded-lg p-8 max-w-2xl">
    <?php if (isset($error)): ?>
        <div class="bg-red-50 border border-red-200 text-red-600 p-4 rounded mb-6 flex items-center">
            <i class="material-icons mr-2">error</i> <?php echo htmlspecialchars($error); ?>
        </div>
    <?php endif; ?>

    <form method="POST" action="edit?id=<?php echo (int) $id; ?>">
        <div class="mb-6">
            <label class="block text-gray-700 font-bold mb-2">Role Name</label>
            <input type="text" name="name" value="<?php echo htmlspecialchars($roleRecord['name'] ?? ''); ?>" class="w-full border rounded-lg p-3 focus:ring-2 focus:ring-blue-500 focus:border-blue-500" required autofocus>
        </div>
        <div>
            <button type="submit" class="bg-blue-600 text-white px-6 py-3 rounded-lg font-bold hover:bg-blue-700 transition shadow flex items-center">
                <i class="material-icons mr-2">save</i> Update Role
            </button>
        </div>
    </form>
</div>

<?php include '../../../includes/footer.php'; ?>
