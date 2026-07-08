<?php
require_once __DIR__ . '/../../config/app.php';
checkAuth();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/permissions_helper.php';
permissions_require_one_of(['manage_products']);


$categories = $pdo->query("SELECT * FROM product_categories ORDER BY name")->fetchAll();

include '../../includes/header.php';
?>

<div class="mb-6">
    <a href="index" class="text-indigo-600 hover:underline flex items-center">
        <i class="material-icons text-sm mr-1">arrow_back</i> Back to List
    </a>
    <h1 class="text-3xl font-bold text-gray-800 mt-2">Add New Product</h1>
</div>

<div class="bg-white shadow rounded-lg p-6 max-w-2xl">
    <form action="save" method="POST">
        <input type="hidden" name="action" value="create">

        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
            <div class="md:col-span-2">
                <label class="block text-gray-700 font-semibold mb-2">Product Name *</label>
                <input type="text" name="name" required
                    class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500">
            </div>

            <div>
                <label class="block text-gray-700 font-semibold mb-2">Category *</label>
                <select name="category_id" required
                    class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 bg-white">
                    <?php if (empty($categories)): ?>
                        <option value="">No categories found - create one first</option>
                    <?php else: ?>
                        <option value="">Select Category</option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?php echo $cat['id']; ?>">
                                <?php echo htmlspecialchars($cat['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </select>
                <?php if (empty($categories)): ?>
                    <p class="text-xs text-red-500 mt-1">
                        <a href="categories/index" class="underline">Click here to manage categories</a>
                    </p>
                <?php endif; ?>
            </div>

            <div>
                <label class="block text-gray-700 font-semibold mb-2">Price (MK) *</label>
                <input type="number" step="0.01" name="price" required
                    class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500">
            </div>
        </div>

        <div class="mb-6">
            <label class="block text-gray-700 font-semibold mb-2">Description</label>
            <textarea name="description" rows="4"
                class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500"></textarea>
        </div>

        <div class="flex justify-end gap-4">
            <a href="index"
                class="bg-gray-300 text-gray-700 px-6 py-2 rounded-lg hover:bg-gray-400 transition">Cancel</a>
            <button type="submit"
                class="bg-indigo-600 text-white px-6 py-2 rounded-lg hover:bg-indigo-700 transition shadow-lg">Save
                Product</button>
        </div>
    </form>
</div>

<?php include '../../includes/footer.php'; ?>