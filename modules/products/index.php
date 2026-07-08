<?php
require_once __DIR__ . '/../../config/app.php';
checkAuth();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/permissions_helper.php';
permissions_require_one_of(['view_products']);

$query = "
    SELECT p.*, c.name as category_name
    FROM products p
    LEFT JOIN product_categories c ON p.category_id = c.id
    ORDER BY p.name ASC
";
$products = $pdo->query($query)->fetchAll();

include '../../includes/header.php';
?>

<div class="list-toolbar mb-6">
    <div class="min-w-0">
        <h1 class="text-3xl font-bold text-gray-800 break-words">Products</h1>
        <p class="text-sm text-gray-500 mt-1">Manage priced products and category assignments used by operations teams.</p>
    </div>
    <div class="list-toolbar-actions">
        <a href="categories/index"
            class="list-action-btn bg-indigo-100 text-indigo-700" aria-label="Manage product categories">
            <i class="material-icons sm:mr-1 text-sm">format_list_bulleted</i>
            <span class="hidden sm:inline">Manage Categories</span>
        </a>
        <a href="create" class="list-action-btn bg-indigo-600 text-white" aria-label="Add product">
            <i class="material-icons sm:mr-1 text-sm">add</i>
            <span class="hidden sm:inline">Add Product</span>
        </a>
    </div>
</div>

<div class="list-view-shell">
    <div class="list-mobile-stack">
        <?php foreach ($products as $product): ?>
        <div class="list-mobile-card">
            <div class="flex items-start justify-between gap-3">
                <div class="min-w-0">
                    <p class="list-card-title"><?php echo htmlspecialchars($product['name']); ?></p>
                    <p class="text-sm text-gray-500 mt-1 break-words"><?php echo htmlspecialchars($product['description']); ?></p>
                </div>
                <span class="text-sm font-semibold text-gray-900 flex-shrink-0"><?php echo number_format($product['price'], 2); ?></span>
            </div>
            <div class="mt-3">
                <p class="list-card-meta">Category</p>
                <p class="list-card-value">
                    <?php echo $product['category_name'] ? htmlspecialchars($product['category_name']) : 'Uncategorized'; ?>
                </p>
            </div>
            <div class="list-row-actions two-up mt-4">
                <a href="edit?id=<?php echo $product['id']; ?>" class="list-icon-action bg-indigo-600 text-white" aria-label="Edit product">
                    <i class="material-icons text-sm">edit</i>
                </a>
                <a href="save?action=delete&id=<?php echo $product['id']; ?>" onclick="return confirm('Are you sure you want to delete this product?')" class="list-icon-action bg-red-600 text-white" aria-label="Delete product">
                    <i class="material-icons text-sm">delete</i>
                </a>
            </div>
        </div>
        <?php endforeach; ?>
        <?php if (empty($products)): ?>
        <div class="list-mobile-card text-center text-gray-500">
            <i class="material-icons text-gray-400 text-4xl mb-2 block">inventory</i>
            No products found. Add one to get started.
        </div>
        <?php endif; ?>
    </div>

    <div class="list-desktop-table overflow-x-auto">
    <table class="divide-y divide-gray-200" id="productsTable">
        <thead class="bg-gray-50">
            <tr>
                <th class="text-left text-xs font-medium text-gray-500 uppercase">Product Name</th>
                <th class="text-left text-xs font-medium text-gray-500 uppercase">Category</th>
                <th class="text-left text-xs font-medium text-gray-500 uppercase">Price (MK)</th>
                <th class="text-left text-xs font-medium text-gray-500 uppercase">Description</th>
                <th class="text-right text-xs font-medium text-gray-500 uppercase">Actions</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-200">
            <?php foreach ($products as $product): ?>
                <tr>
                    <td class="font-medium text-gray-900 cell-wrap">
                        <?php echo htmlspecialchars($product['name']); ?>
                    </td>
                    <td class="text-gray-600 cell-wrap">
                        <?php if ($product['category_name']): ?>
                            <span class="px-2 py-1 bg-indigo-50 text-indigo-700 rounded text-xs">
                                <?php echo htmlspecialchars($product['category_name']); ?>
                            </span>
                        <?php else: ?>
                            <span class="text-gray-400 italic">Uncategorized</span>
                        <?php endif; ?>
                    </td>
                    <td class="font-semibold text-gray-900">
                        <?php echo number_format($product['price'], 2); ?>
                    </td>
                    <td class="text-sm text-gray-500 cell-wrap">
                        <?php echo htmlspecialchars(substr($product['description'], 0, 50)) . (strlen($product['description']) > 50 ? '...' : ''); ?>
                    </td>
                    <td class="text-right">
                        <div class="flex items-center justify-end space-x-2">
                            <a href="edit?id=<?php echo $product['id']; ?>"
                                class="text-gray-400 hover:text-indigo-600 transition-colors" title="Edit">
                                <i class="material-icons">edit</i>
                            </a>
                            <a href="save?action=delete&id=<?php echo $product['id']; ?>"
                                onclick="return confirm('Are you sure you want to delete this product?')"
                                class="text-gray-400 hover:text-red-600 transition-colors" title="Delete">
                                <i class="material-icons">delete</i>
                            </a>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($products)): ?>
                <tr>
                    <td colspan="5" class="text-center text-gray-500">No products found. Add one to get started.
                    </td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
    </div>
</div>

<script>
    // Initialize DataTable if available
    document.addEventListener('DOMContentLoaded', function () {
        if (typeof $ !== 'undefined' && $.fn.DataTable) {
            $('#productsTable').DataTable({
                "pageLength": 10
            });
        }
    });
</script>

<?php include '../../includes/footer.php'; ?>
