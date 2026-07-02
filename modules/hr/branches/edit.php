<?php
require_once __DIR__ . '/../../../config/app.php';
checkAuth();
require_once __DIR__ . '/../../../config/database.php';

if (!hasPermission('manage_branches')) {
    die("Access Denied.");
}

$id = $_GET['id'] ?? 0;
$stmt = $pdo->prepare("SELECT * FROM branches WHERE id = ?");
$stmt->execute([$id]);
$branch = $stmt->fetch();

if (!$branch) {
    redirect('modules/hr/branches/list?error=not_found');
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $name = $_POST['name'];
    $stmt = $pdo->prepare("UPDATE branches SET name=? WHERE id=?");
    $stmt->execute([$name, $id]);
    redirect('modules/hr/branches/list?success=updated');
}

include '../../../includes/header.php';
?>

<div class="mb-6">
    <a href="list" class="text-blue-600 hover:underline flex items-center mb-4">
        <i class="material-icons text-sm mr-1">arrow_back</i> Back to List
    </a>
    <h1 class="text-3xl font-bold text-gray-800">Edit Branch</h1>
</div>

<div class="bg-white shadow rounded-lg p-8 max-w-lg">
    <form method="POST" action="edit?id=<?php echo (int) $id; ?>">
        <div class="mb-4">
            <label class="block text-gray-700 font-bold mb-2">Branch Name</label>
            <input type="text" name="name" class="w-full border rounded p-2 focus:ring-2 focus:ring-blue-500" value="<?php echo htmlspecialchars($branch['name']); ?>" required>
        </div>
        <button type="submit" class="bg-blue-600 text-white px-6 py-2 rounded font-bold hover:bg-blue-700 transition">
            Update Branch
        </button>
    </form>
</div>

<?php include '../../../includes/footer.php'; ?>
