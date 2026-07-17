<?php
require_once __DIR__ . '/../../config/app.php';
checkAuth();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/permissions_helper.php';
require_once __DIR__ . '/../../includes/reports_helper.php';

$available = reports_available_modules();
if (empty($available)) {
    http_response_code(403);
    die('Access Denied.');
}

include '../../includes/header.php';
?>

<div class="list-toolbar mb-6">
    <div class="min-w-0">
        <h1 class="text-3xl font-bold text-gray-800 break-words">Reports Centre</h1>
        <p class="text-sm text-gray-500 mt-1">Analytical reports across invoices, sales, materials, work orders, and dispatch with filterable ranges and branded exports.</p>
    </div>
</div>

<div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-6">
    <?php foreach ($available as $key => $meta): ?>
        <a href="<?php echo htmlspecialchars($meta['href']); ?>"
           class="bg-white shadow rounded-xl p-6 border border-gray-100 hover:border-indigo-200 hover:shadow-md transition group">
            <div class="flex items-start gap-4">
                <span class="flex h-12 w-12 items-center justify-center rounded-xl bg-indigo-50 text-indigo-600 group-hover:bg-indigo-100">
                    <i data-lucide="<?php echo htmlspecialchars($meta['icon']); ?>" class="h-6 w-6" aria-hidden="true"></i>
                </span>
                <div class="min-w-0">
                    <h2 class="text-lg font-bold text-gray-900"><?php echo htmlspecialchars($meta['title']); ?></h2>
                    <p class="text-sm text-gray-500 mt-1"><?php echo htmlspecialchars($meta['description']); ?></p>
                    <p class="text-sm font-semibold text-indigo-600 mt-3">Open report →</p>
                </div>
            </div>
        </a>
    <?php endforeach; ?>
</div>

<?php include '../../includes/footer.php'; ?>
