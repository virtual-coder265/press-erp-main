<?php
require_once __DIR__ . '/../../../config/app.php';
checkAuth();
require_once __DIR__ . '/../../../config/database.php';


$categories = $pdo->query("SELECT * FROM material_categories ORDER BY name")->fetchAll();

include '../../../includes/header.php';
?>

<div class="list-toolbar mb-6">
    <div class="min-w-0">
        <h1 class="text-3xl font-bold text-gray-800 break-words">Material Categories</h1>
        <p class="text-sm text-gray-500 mt-1">Use the same responsive category management pattern across desktop tables and mobile cards.</p>
    </div>
    <div class="list-toolbar-actions">
        <button onclick="openCategoryModal()" class="list-action-btn bg-green-600 text-white" aria-label="Add category">
            <i class="material-icons sm:mr-1">add</i>
            <span class="hidden sm:inline">Add Category</span>
        </button>
    </div>
</div>

<div class="list-view-shell">
    <div class="list-mobile-stack">
        <?php foreach ($categories as $cat): ?>
        <div class="list-mobile-card">
            <div class="flex items-start justify-between gap-3">
                <div class="min-w-0">
                    <p class="list-card-title"><?php echo htmlspecialchars($cat['name']); ?></p>
                    <p class="list-card-meta mt-1">Category Name</p>
                </div>
            </div>
            <div class="list-row-actions two-up mt-4">
                <button onclick='openCategoryModal(<?php echo json_encode($cat); ?>)' class="list-icon-action bg-blue-600 text-white" aria-label="Edit category">
                    <i class="material-icons text-sm">edit</i>
                </button>
                <a href="save?action=delete&id=<?php echo $cat['id']; ?>" onclick="return confirm('Delete this category? Items using it will have no category.')" class="list-icon-action bg-red-600 text-white" aria-label="Delete category">
                    <i class="material-icons text-sm">delete</i>
                </a>
            </div>
        </div>
        <?php endforeach; ?>
        <?php if (empty($categories)): ?>
        <div class="list-mobile-card text-center text-gray-500">
            <i class="material-icons text-gray-400 text-4xl mb-2 block">category</i>
            No categories found.
        </div>
        <?php endif; ?>
    </div>

    <div class="list-desktop-table overflow-x-auto">
    <table class="divide-y divide-gray-200">
        <thead class="bg-gray-50">
            <tr>
                <th class="text-left text-xs font-medium text-gray-500 uppercase">Category Name</th>
                <th class="text-right text-xs font-medium text-gray-500 uppercase">Actions</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-200">
            <?php foreach ($categories as $cat): ?>
            <tr>
                <td class="font-medium cell-wrap"><?php echo htmlspecialchars($cat['name']); ?></td>
                <td class="text-right">
                    <div class="flex items-center justify-end space-x-2">
                        <button onclick='openCategoryModal(<?php echo json_encode($cat); ?>)' class="text-gray-400 hover:text-blue-600 transition-colors" title="Edit Category">
                            <i class="material-icons">edit</i>
                        </button>
                        <a href="save?action=delete&id=<?php echo $cat['id']; ?>" onclick="return confirm('Delete this category? Items using it will have no category.')" class="text-gray-400 hover:text-red-600 transition-colors" title="Delete Category">
                            <i class="material-icons">delete</i>
                        </a>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($categories)): ?>
            <tr>
                <td colspan="2" class="text-center text-gray-500">No categories found.</td>
            </tr>
            <?php endif; ?>
        </tbody>
    </table>
    </div>
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
                    <input type="text" name="name" id="categoryName" required class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 outline-none">
                </div>
                <div class="pt-4 flex gap-3">
                    <button type="button" onclick="closeCategoryModal()" class="flex-1 bg-gray-200 text-gray-700 font-bold py-3 rounded-lg hover:bg-gray-300 transition">Cancel</button>
                    <button type="submit" class="flex-1 bg-green-600 text-white font-bold py-3 rounded-lg hover:bg-green-700 transition shadow-lg">Save</button>
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
        } else {
            $('#modalTitle').text('Add Category');
            $('#formAction').val('create');
            $('#categoryId').val('');
            $('#categoryName').val('');
        }
        $('#categoryModal').removeClass('hidden');
    }

    function closeCategoryModal() {
        $('#categoryModal').addClass('hidden');
    }
</script>

<?php include '../../../includes/footer.php'; ?>
