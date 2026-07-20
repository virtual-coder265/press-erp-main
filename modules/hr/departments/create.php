<?php
require_once __DIR__ . '/../../../config/app.php';
checkAuth();
require_once __DIR__ . '/../../../config/database.php';

if (!hasPermission('manage_departments')) {
    die("Access Denied.");
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');

    if ($name === '') {
        $error = 'Department name is required.';
    } else {
        try {
            $stmt = $pdo->prepare('INSERT INTO departments (name) VALUES (?)');
            $stmt->execute([$name]);
            redirect('modules/hr/departments/list?success=created');
        } catch (PDOException $e) {
            $error = 'Failed to create department. The name may already exist.';
        }
    }
}

include '../../../includes/header.php';
?>

<div class="mb-6">
    <a href="list" class="text-blue-600 hover:underline flex items-center mb-4">
        <i class="material-icons text-sm mr-1">arrow_back</i> Back to List
    </a>
    <h1 class="text-3xl font-bold text-gray-800">Add Department (Section)</h1>
</div>

<div class="bg-white shadow rounded-lg p-8 max-w-lg">
    <?php if ($error !== null): ?>
        <div class="bg-red-50 border border-red-200 text-red-600 p-4 rounded mb-6 flex items-center">
            <i class="material-icons mr-2">error</i> <?php echo htmlspecialchars($error); ?>
        </div>
    <?php endif; ?>

    <form method="POST" action="create">
        <div class="mb-4">
            <label class="block text-gray-700 font-bold mb-2">Department Name</label>
            <input type="text" name="name" class="w-full border rounded p-2 focus:ring-2 focus:ring-blue-500" value="<?php echo htmlspecialchars($_POST['name'] ?? ''); ?>" required autofocus>
        </div>
        <button type="submit" class="bg-green-600 text-white px-6 py-2 rounded font-bold hover:bg-green-700 transition">
            Save Department
        </button>
    </form>
</div>

<?php include '../../../includes/footer.php'; ?>
