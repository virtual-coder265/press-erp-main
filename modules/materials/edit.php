<?php
require_once __DIR__ . '/../../config/app.php';
checkAuth();
require_once __DIR__ . '/../../config/database.php';

// Role Check
if ($_SESSION['role'] != 'System Admin' && $_SESSION['role'] != 'Procurement' && $_SESSION['role'] != 'Costing') {
    die("Access Denied. You do not have permission to view this page.");
}

$id = $_GET['id'] ?? null;
if (!$id) {
    header("Location: list");
    exit;
}

// Fetch material and latest rate
$stmt = $pdo->prepare("
    SELECT m.*, r.rate 
    FROM materials m 
    LEFT JOIN material_rates r ON m.id = r.material_id 
    WHERE m.id = ? 
    ORDER BY r.effective_date DESC LIMIT 1
");
$stmt->execute([$id]);
$material = $stmt->fetch();

if (!$material) {
    die("Material not found.");
}

$categories = $pdo->query("SELECT * FROM material_categories ORDER BY name")->fetchAll();

include '../../includes/header.php';
?>

<div class="mb-6">
    <a href="list" class="text-green-600 hover:underline flex items-center">
        <i class="material-icons text-sm mr-1">arrow_back</i> Back to List
    </a>
    <h1 class="text-3xl font-bold text-gray-800 mt-2">Edit Material</h1>
</div>

<div class="bg-white shadow rounded-lg p-6 max-w-2xl">
    <form action="save" method="POST">
        <input type="hidden" name="action" value="update">
        <input type="hidden" name="id" value="<?php echo $material['id']; ?>">
        
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
            <div>
                <label class="block text-gray-700 font-semibold mb-2">Material Name *</label>
                <input type="text" name="name" value="<?php echo htmlspecialchars($material['name']); ?>" required class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500">
            </div>
            <div>
                <label class="block text-gray-700 font-semibold mb-2">Category *</label>
                <select name="category_id" required class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500">
                    <?php if (empty($categories)): ?>
                        <option value="">No categories found - create one first</option>
                    <?php else: ?>
                        <option value="">Select Category</option>
                        <?php foreach($categories as $cat): ?>
                        <option value="<?php echo $cat['id']; ?>" <?php echo $material['category_id'] == $cat['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($cat['name']); ?>
                        </option>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </select>
                <?php if (empty($categories)): ?>
                <p class="text-xs text-red-500 mt-1">
                    <a href="categories/list" class="underline">Click here to manage categories</a>
                </p>
                <?php endif; ?>
            </div>
            <div>
                <label class="block text-gray-700 font-semibold mb-2">Unit *</label>
                <input type="text" name="unit" value="<?php echo htmlspecialchars($material['unit']); ?>" required class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500">
            </div>
            <div>
                <label class="block text-gray-700 font-semibold mb-2">Current Rate (MK) *</label>
                <input type="number" step="0.01" name="rate" value="<?php echo htmlspecialchars($material['rate'] ?? 0); ?>" required class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500">
                <p class="text-xs text-gray-500 mt-1">Changing this will create a new rate entry.</p>
            </div>
        </div>

        <div class="mb-6">
            <label class="block text-gray-700 font-semibold mb-2">Description</label>
            <textarea name="description" rows="3" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500"><?php echo htmlspecialchars($material['description']); ?></textarea>
        </div>

        <div class="flex justify-end gap-4">
            <a href="list" class="bg-gray-300 text-gray-700 px-6 py-2 rounded-lg hover:bg-gray-400 transition">Cancel</a>
            <button type="submit" class="bg-green-600 text-white px-6 py-2 rounded-lg hover:bg-green-700 transition">Update Material</button>
        </div>
    </form>
</div>

<?php include '../../includes/footer.php'; ?>
