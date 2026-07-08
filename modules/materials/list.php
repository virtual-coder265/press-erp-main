<?php
require_once __DIR__ . '/../../config/app.php';
checkAuth();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/permissions_helper.php';
permissions_require_one_of(['view_materials']);

include '../../includes/header.php';

// Search functionality
$search_query = $_GET['search'] ?? '';
$category_filter = $_GET['category'] ?? '';

$query = "
    SELECT m.*, r.rate as current_rate 
    FROM materials m 
    LEFT JOIN (
        SELECT material_id, rate 
        FROM material_rates 
        WHERE id IN (SELECT MAX(id) FROM material_rates GROUP BY material_id)
    ) r ON m.id = r.material_id 
    WHERE 1=1
";
$params = [];

if (!empty($search_query)) {
    $query .= " AND (m.name LIKE :search OR m.description LIKE :search)";
    $params['search'] = '%' . $search_query . '%';
}

if (!empty($category_filter)) {
    $query .= " AND m.category_id = :category";
    $params['category'] = $category_filter;
}

$query .= " ORDER BY m.name";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$materials = $stmt->fetchAll();

// Get categories for filter
$categories = $pdo->query("SELECT * FROM material_categories ORDER BY name")->fetchAll();
?>

<div class="list-toolbar mb-6">
    <div class="min-w-0">
        <h1 class="text-3xl font-bold text-gray-800 break-words">Materials Management</h1>
        <p class="text-sm text-gray-500 mt-1">Manage material inventory and current market rates used across estimations.</p>
    </div>
    <div class="list-toolbar-actions">
        <a href="create" class="list-action-btn bg-green-600 text-white" aria-label="Add material">
            <i class="material-icons sm:mr-1">add</i>
            <span class="hidden sm:inline">Add Material</span>
        </a>
    </div>
</div>

<!-- Standard Materials Quick Rates -->
<div class="mb-10">
    <div class="flex items-center gap-2 mb-6">
        <i class="material-icons text-blue-600">stars</i>
        <h2 class="text-2xl font-bold text-gray-800">Standard Material Rates</h2>
    </div>
    
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
        <?php 
        $standard_names = ['Proofing Paper', 'Film', 'Plate', 'Colour Separation'];
        $std_map = [];
        foreach($materials as $m) if(in_array($m['name'], $standard_names)) $std_map[$m['name']] = $m;

        foreach($standard_names as $name):
            $mat = $std_map[$name] ?? null;
        ?>
        <div class="bg-white border-2 <?php echo $mat ? 'border-blue-100 shadow-sm' : 'border-dashed border-gray-200'; ?> rounded-2xl p-6 transition-all hover:shadow-md h-full flex flex-col">
            <div class="flex-1">
                <h3 class="font-bold text-gray-800 text-lg mb-1"><?php echo $name; ?></h3>
                <p class="text-xs text-gray-500 mb-4 uppercase tracking-wider"><?php echo $mat ? 'Unit: ' . htmlspecialchars($mat['unit']) : 'Not Configured'; ?></p>
                
                <?php if($mat): ?>
                <form action="save" method="POST" class="mt-4">
                    <input type="hidden" name="action" value="update">
                    <input type="hidden" name="id" value="<?php echo $mat['id']; ?>">
                    <input type="hidden" name="name" value="<?php echo htmlspecialchars($mat['name']); ?>">
                    <input type="hidden" name="unit" value="<?php echo htmlspecialchars($mat['unit']); ?>">
                    <input type="hidden" name="category_id" value="<?php echo $mat['category_id']; ?>">
                    <input type="hidden" name="description" value="<?php echo htmlspecialchars($mat['description']); ?>">
                    
                    <div class="relative group">
                        <label class="block text-xs font-bold text-blue-600 mb-1">CURRENT RATE (MKW)</label>
                        <div class="flex gap-2">
                            <input type="number" step="0.01" name="rate" value="<?php echo $mat['current_rate']; ?>" 
                                   class="w-full px-3 py-2 bg-blue-50/50 border border-blue-100 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 text-lg font-bold text-gray-900">
                            <button type="submit" class="bg-blue-600 text-white p-2 rounded-lg hover:bg-blue-700 transition flex items-center justify-center shrink-0 shadow-sm" title="Update Rate">
                                <i class="material-icons text-sm">save</i>
                            </button>
                        </div>
                    </div>
                </form>
                <?php else: ?>
                <div class="flex-1 flex items-center justify-center py-6">
                    <a href="create?name=<?php echo urlencode($name); ?>" class="text-gray-400 hover:text-blue-600 flex flex-col items-center gap-2 group">
                        <i class="material-icons text-4xl group-hover:scale-110 transition-transform">add_circle_outline</i>
                        <span class="text-xs font-semibold">Initialize Material</span>
                    </a>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<hr class="border-gray-100 mb-10">

<!-- Search & Filter Bar -->
<div class="bg-white shadow rounded-lg p-6 mb-6">
    <div class="flex items-center gap-2 mb-4">
        <i class="material-icons text-gray-400">search</i>
        <h3 class="font-bold text-gray-700">Filter Materials</h3>
    </div>
    <form method="GET" action="" class="list-filters-grid md:grid-cols-12">
        <div class="md:col-span-7 min-w-0">
            <input type="text" name="search" placeholder="Search by material name or description..." 
                   class="w-full px-4 py-3 border border-gray-300 rounded-lg"
                   value="<?php echo htmlspecialchars($search_query); ?>">
        </div>
        <div class="md:col-span-3">
            <select name="category" class="w-full px-4 py-3 border border-gray-300 rounded-lg">
                <option value="">All Categories</option>
                <?php foreach($categories as $cat): ?>
                <option value="<?php echo $cat['id']; ?>" <?php echo $category_filter == $cat['id'] ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($cat['name']); ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="md:col-span-2 flex flex-col sm:flex-row gap-2">
            <button type="submit" class="bg-green-600 text-white px-4 py-3 rounded hover:bg-green-700 transition flex items-center justify-center whitespace-nowrap" aria-label="Search materials">
                <i class="material-icons sm:mr-2">search</i>
                <span class="hidden sm:inline">Search</span>
            </button>
            <?php if($search_query || $category_filter): ?>
            <a href="list" class="bg-gray-300 text-gray-700 px-4 py-3 rounded hover:bg-gray-400 transition flex items-center justify-center" aria-label="Clear material filters">
                <i class="material-icons sm:mr-2">close</i>
                <span class="hidden sm:inline">Clear</span>
            </a>
            <?php endif; ?>
        </div>
    </form>
</div>

<div class="list-view-shell">
    <div class="p-6 border-b border-gray-100">
        <p class="text-gray-600">Manage printing materials and their current market rates.</p>
    </div>

    <div class="list-mobile-stack">
        <?php 
        $standard_names = ['Proofing Paper', 'Film', 'Plate', 'Colour Separation'];
        foreach ($materials as $mat): 
            $is_standard = in_array($mat['name'], $standard_names);
        ?>
        <div class="list-mobile-card <?php echo $is_standard ? 'bg-blue-50/30' : ''; ?>">
            <div class="flex items-start justify-between gap-3">
                <div class="min-w-0">
                    <p class="list-card-title"><?php echo htmlspecialchars($mat['name']); ?></p>
                    <p class="text-sm text-gray-500 mt-1"><?php echo htmlspecialchars($mat['unit']); ?></p>
                </div>
                <?php if($is_standard): ?>
                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-blue-100 text-blue-800 flex-shrink-0">Standard</span>
                <?php endif; ?>
            </div>
            <div class="grid grid-cols-2 gap-3 mt-4 text-sm">
                <div>
                    <p class="list-card-meta">Current Rate</p>
                    <p class="list-card-value font-semibold text-gray-900"><?php echo number_format($mat['current_rate'], 2); ?></p>
                </div>
                <div class="flex items-end justify-end">
                    <a href="edit?id=<?php echo $mat['id']; ?>" class="list-icon-action bg-green-600 text-white w-full" aria-label="Edit material">
                        <i class="material-icons text-sm">edit</i>
                    </a>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
        <?php if (empty($materials)): ?>
        <div class="list-mobile-card text-center text-gray-500">
            <i class="material-icons text-gray-400 text-4xl mb-2 block">inventory_2</i>
            No materials found.
        </div>
        <?php endif; ?>
    </div>

    <div class="list-desktop-table overflow-x-auto">
    <table class="divide-y divide-gray-200">
        <thead class="bg-gray-50">
            <tr>
                <th class="text-left text-xs font-medium text-gray-500 uppercase">Name</th>
                <th class="text-left text-xs font-medium text-gray-500 uppercase">Unit</th>
                <th class="text-left text-xs font-medium text-gray-500 uppercase">Current Rate (MK)</th>
                <th class="text-right text-xs font-medium text-gray-500 uppercase">Actions</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-200">
            <?php 
            foreach ($materials as $mat): 
                $is_standard = in_array($mat['name'], $standard_names);
            ?>
            <tr class="<?php echo $is_standard ? 'bg-blue-50/30' : ''; ?>">
                <td>
                    <div class="font-medium text-gray-900"><?php echo htmlspecialchars($mat['name']); ?></div>
                    <?php if($is_standard): ?>
                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-blue-100 text-blue-800">Standard Material</span>
                    <?php endif; ?>
                </td>
                <td class="text-gray-600"><?php echo htmlspecialchars($mat['unit']); ?></td>
                <td class="font-semibold text-gray-900"><?php echo number_format($mat['current_rate'], 2); ?></td>
                <td class="text-right">
                    <a href="edit?id=<?php echo $mat['id']; ?>" class="text-gray-400 hover:text-green-600 transition-colors" title="Edit Material">
                        <i class="material-icons">edit</i>
                    </a>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($materials)): ?>
            <tr>
                <td colspan="4" class="text-center text-gray-500">No materials found.</td>
            </tr>
            <?php endif; ?>
        </tbody>
    </table>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>
