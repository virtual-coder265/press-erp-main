<?php
require_once __DIR__ . '/../../../config/app.php';
checkAuth();
require_once __DIR__ . '/../../../config/database.php';


$categories = $pdo->query("SELECT * FROM product_categories ORDER BY name")->fetchAll();

include '../../../includes/header.php';
?>

<div class="flex justify-between items-center mb-6">
    <h1 class="text-3xl font-bold text-gray-800">Product Categories</h1>
    <div class="space-x-2">
        <a href="../../products/index"
            class="bg-gray-600 text-white px-4 py-2 rounded shadow hover:bg-gray-700 transition">
            <i class="material-icons align-middle">arrow_back</i> Back to Products
        </a>
        <button onclick="openCategoryModal()"
            class="bg-indigo-600 text-white px-4 py-2 rounded shadow hover:bg-indigo-700 transition">
            <i class="material-icons align-middle">add</i> Add Category
        </button>
    </div>
</div>

<div class="bg-white shadow rounded-lg p-6 overflow-x-auto">
    <table class="min-w-full divide-y divide-gray-200">
        <thead class="bg-gray-50">
            <tr>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Category Name</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Description</th>
                <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Actions</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-200">
            <?php foreach ($categories as $cat): ?>
                <tr>
                    <td class="px-6 py-4 font-medium">
                        <?php echo htmlspecialchars($cat['name']); ?>
                    </td>
                    <td class="px-6 py-4 text-gray-600">
                        <?php echo htmlspecialchars($cat['description']); ?>
                    </td>
                    <td class="px-6 py-4 text-right">
                        <div class="flex items-center justify-end space-x-2">
                            <button onclick='openCategoryModal(<?php echo json_encode($cat); ?>)'
                                class="text-gray-400 hover:text-blue-600 transition-colors" title="Edit Category">
                                <i class="material-icons">edit</i>
                            </button>
                            <a href="save?action=delete&id=<?php echo $cat['id']; ?>"
                                onclick="return confirm('Delete this category? Items using it will have no category.')"
                                class="text-gray-400 hover:text-red-600 transition-colors" title="Delete Category">
                                <i class="material-icons">delete</i>
                            </a>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($categories)): ?>
                <tr>
                    <td colspan="3" class="px-6 py-4 text-center text-gray-500">No categories found.</td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- Category Modal -->
<div id="categoryModal" class="fixed inset-0 bg-black bg-opacity-50 hidden flex items-center justify-center z-50">
    <div class="bg-white rounded-xl shadow-2xl p-8 w-full max-w-md">
        <div class="flex justify-between items-center mb-6">
            <h3 id="modalTitle" class="text-2xl font-bold text-gray-800">Add Category</h3>
            <button onclick="closeCategoryModal()" class="text-gray-400 hover:text-gray-600">
                <i class="material-icons">close</i>
            </button>
        </div>
        <form action="save" method="POST">
            <input type="hidden" name="action" id="formAction" value="create">
            <input type="hidden" name="id" id="categoryId" value="">
            <div class="space-y-4">
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">Category Name *</label>
                    <input type="text" name="name" id="categoryName" required
                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 outline-none">
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">Description</label>
                    <textarea name="description" id="categoryDescription" rows="3"
                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 outline-none"></textarea>
                </div>
                <div class="pt-4 flex gap-3">
                    <button type="button" onclick="closeCategoryModal()"
                        class="flex-1 bg-gray-200 text-gray-700 font-bold py-3 rounded-lg hover:bg-gray-300 transition">Cancel</button>
                    <button type="submit"
                        class="flex-1 bg-indigo-600 text-white font-bold py-3 rounded-lg hover:bg-indigo-700 transition shadow-lg">Save</button>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
    function openCategoryModal(cat = null) {
        if (cat) {
            $('#modalTitle').text('Edit Category');
            $('#formAction').val('update');
            $('#categoryId').val(cat.id);
            $('#categoryName').val(cat.name);
            $('#categoryDescription').val(cat.description);
        } else {
            $('#modalTitle').text('Add Category');
            $('#formAction').val('create');
            $('#categoryId').val('');
            $('#categoryName').val('');
            $('#categoryDescription').val('');
        }
        $('#categoryModal').removeClass('hidden');
    }

    function closeCategoryModal() {
        $('#categoryModal').addClass('hidden');
    }
</script>

<?php include '../../../includes/footer.php'; ?>