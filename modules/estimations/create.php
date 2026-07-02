<?php
require_once __DIR__ . '/../../config/app.php';
checkAuth();
require_once __DIR__ . '/../../config/database.php';

// Fetch all materials with their latest rates
$stmt = $pdo->query("
    SELECT m.*, r.rate, mc.name as category_name
    FROM materials m
    LEFT JOIN (
        SELECT material_id, rate
        FROM material_rates
        WHERE id IN (SELECT MAX(id) FROM material_rates GROUP BY material_id)
    ) r ON m.id = r.material_id
    LEFT JOIN material_categories mc ON m.category_id = mc.id
    ORDER BY m.name
");
$all_materials = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Separate binding materials
$binding_materials = array_filter($all_materials, fn($m) => strtolower($m['category_name'] ?? '') === 'binding materials');
$binding_cat_id = null;
$catStmt = $pdo->query("SELECT id FROM material_categories WHERE name='Binding Materials' LIMIT 1");
$binding_cat_id = $catStmt->fetchColumn();

// Fetch existing drafts for the current user
$stmt = $pdo->prepare("
    SELECT id, estimation_number, customer_name, job_description, draft_step, last_auto_saved, created_at
    FROM estimations
    WHERE created_by = :user AND status = 'Draft'
    ORDER BY last_auto_saved DESC, created_at DESC
    LIMIT 5
");
$stmt->execute(['user' => $_SESSION['user_id']]);
$existing_drafts = $stmt->fetchAll(PDO::FETCH_ASSOC);

include '../../includes/header.php';
?>

<style>
    /* Hide number input spinners to prevent accidental value changes on scroll */
    input[type="number"]::-webkit-outer-spin-button,
    input[type="number"]::-webkit-inner-spin-button {
        -webkit-appearance: none;
        margin: 0;
    }
    input[type="number"] {
        -moz-appearance: textfield;
    }

    button.inline-flex svg.lucide,
    button.flex svg.lucide {
        width: 1.125rem;
        height: 1.125rem;
        flex-shrink: 0;
    }

    /* Auto-save notification animation */
    @keyframes fadeInOut {
        0% {
            opacity: 0;
            transform: translateY(10px);
        }
        10% {
            opacity: 1;
            transform: translateY(0);
        }
        90% {
            opacity: 1;
            transform: translateY(0);
        }
        100% {
            opacity: 0;
            transform: translateY(10px);
        }
    }
</style>

<div class="mb-6">
    <div class="flex items-center gap-2 mb-4">
        <a href="list" class="text-green-600 hover:underline flex items-center">
            <i data-lucide="arrow-left" class="mr-1 inline-block h-4 w-4 flex-shrink-0" aria-hidden="true"></i> Back to Estimations
        </a>
    </div>
    <h1 class="text-3xl font-bold text-gray-800">New Estimation</h1>
    <p class="text-gray-600">Complete the steps below to generate a cost estimation.</p>
</div>

<!-- Existing Drafts Section -->
<?php if (!empty($existing_drafts)): ?>
<div class="bg-amber-50 border border-amber-200 rounded-lg p-6 mb-8">
    <div class="flex items-start gap-3 mb-4">
        <i data-lucide="file-text" class="h-5 w-5 text-amber-600 flex-shrink-0 mt-0.5"></i>
        <div>
            <h3 class="font-semibold text-amber-900">Resume Your Draft</h3>
            <p class="text-sm text-amber-800 mt-1">You have <?php echo count($existing_drafts); ?> unsaved draft(s) in progress.</p>
        </div>
    </div>
    <div class="space-y-2">
        <?php foreach ($existing_drafts as $draft): ?>
            <div class="flex items-center justify-between bg-white p-3 rounded-lg border border-amber-100">
                <div class="flex-1 min-w-0">
                    <p class="font-semibold text-gray-800 truncate">
                        <?php echo htmlspecialchars($draft['customer_name'] ?? 'Unnamed'); ?> 
                        <span class="text-xs text-gray-500 font-normal">#<?php echo htmlspecialchars($draft['estimation_number']); ?></span>
                    </p>
                    <p class="text-xs text-gray-600 truncate">
                        Step <?php echo $draft['draft_step']; ?> 
                        <?php if ($draft['last_auto_saved']): ?>
                            • Last saved: <?php echo date('M d H:i', strtotime($draft['last_auto_saved'])); ?>
                        <?php endif; ?>
                    </p>
                </div>
                <a href="edit_draft?id=<?php echo $draft['id']; ?>"
                    class="ml-2 bg-amber-600 text-white px-4 py-2 rounded-lg hover:bg-amber-700 transition text-sm font-semibold whitespace-nowrap">
                    Continue
                </a>
            </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- Progress Steps -->
<div class="bg-white shadow-md rounded-xl p-6 mb-8">
    <div class="flex items-center justify-between">
        <?php
        $steps = ['Client & Job', 'Materials', 'Paper', 'Ink', 'Binding', 'Labour', 'Consumables', 'Totals'];
        foreach ($steps as $index => $label):
            $stepNum = $index + 1;
            ?>
            <div class="flex items-center flex-1 step-indicator">
                <div class="flex flex-col items-center">
                    <div id="step-circle-<?php echo $stepNum; ?>"
                        class="w-10 h-10 rounded-full flex items-center justify-center bg-gray-300 text-gray-600 font-bold transition-colors">
                        <?php echo $stepNum; ?>
                    </div>
                    <span id="step-label-<?php echo $stepNum; ?>"
                        class="text-xs mt-2 text-gray-500 font-semibold transition-colors">
                        <?php echo $label; ?>
                    </span>
                </div>
                <?php if ($stepNum < count($steps)): ?>
                    <div id="step-line-<?php echo $stepNum; ?>" class="flex-1 h-1 mx-2 bg-gray-300 transition-colors"></div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- Form -->
<div class="bg-white shadow-md rounded-xl p-8">
    <form id="estimationForm" method="POST" action="save" novalidate>

        <!-- ===== STEP 1: Client & Job ===== -->
        <div id="step-1" class="step-content">
            <h2 class="text-2xl font-bold text-gray-800 mb-6">Client Information &amp; Job Description</h2>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                <div>
                    <label class="block text-gray-700 font-semibold mb-2">Customer Name *</label>
                    <input type="text" name="customer_name" id="customer_name" list="customer_list" required
                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-green-500"
                        autocomplete="off">
                    <datalist id="customer_list">
                        <?php
                        $stmt = $pdo->query("SELECT * FROM customers ORDER BY name");
                        $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);
                        foreach ($customers as $cust): ?>
                            <option value="<?php echo htmlspecialchars($cust['name']); ?>"
                                data-id="<?php echo $cust['id']; ?>"
                                data-email="<?php echo htmlspecialchars($cust['email']); ?>"
                                data-phone="<?php echo htmlspecialchars($cust['phone']); ?>">
                            <?php endforeach; ?>
                    </datalist>
                    <input type="hidden" name="customer_id" id="customer_id">
                </div>
                <div>
                    <label class="block text-gray-700 font-semibold mb-2">Email</label>
                    <input type="email" name="customer_email" id="customer_email"
                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-green-500">
                </div>
                <div>
                    <label class="block text-gray-700 font-semibold mb-2">Phone</label>
                    <input type="text" name="customer_phone" id="customer_phone"
                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-green-500">
                </div>
                <div>
                    <label class="block text-gray-700 font-semibold mb-2">Company/Dept</label>
                    <input type="text" name="customer_company"
                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-green-500">
                </div>
            </div>

            <script>
                document.getElementById('customer_name').addEventListener('input', function (e) {
                    var input = e.target;
                    var options = document.getElementById('customer_list').options;
                    for (var i = 0; i < options.length; i++) {
                        if (options[i].value === input.value) {
                            document.getElementById('customer_id').value = options[i].getAttribute('data-id');
                            document.getElementById('customer_email').value = options[i].getAttribute('data-email');
                            document.getElementById('customer_phone').value = options[i].getAttribute('data-phone');
                            break;
                        }
                    }
                });
            </script>

            <h3 class="text-xl font-bold text-gray-800 mb-4 mt-8">Job Description</h3>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                    <label class="block text-gray-700 font-semibold mb-2">Job Name / Title *</label>
                    <input type="text" name="job_title" required
                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-green-500">
                </div>
                <div>
                    <label class="block text-gray-700 font-semibold mb-2">Job Type</label>
                    <select name="job_type"
                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-green-500">
                        <option value="Booklet">Booklet</option>
                        <option value="Brochure">Brochure</option>
                        <option value="Poster">Poster</option>
                        <option value="Banner">Banner</option>
                        <option value="Business Cards">Business Cards</option>
                        <option value="Other">Other</option>
                    </select>
                </div>
                <div class="col-span-1 md:col-span-2">
                    <label class="block text-gray-700 font-semibold mb-2">Job Description / Notes</label>
                    <textarea name="job_description" rows="5"
                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-green-500 resize-y"
                        placeholder="Enter full job description, special instructions, or notes..."></textarea>
                </div>
            </div>
        </div>

        <!-- ===== STEP 2: Materials ===== -->
        <?php
        $stds = [];
        foreach ($all_materials as $m)
            $stds[$m['name']] = $m;
        $getRate = fn($name) => $stds[$name]['rate'] ?? 0;
        $getId = fn($name) => $stds[$name]['id'] ?? '';
        ?>
        <div id="step-2" class="step-content hidden">
            <h2 class="text-2xl font-bold text-gray-800 mb-6">Standard Materials</h2>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-10">
                <?php
                $stdCards = [
                    'Proofing Paper' => 'No. of Sheets',
                    'Film' => 'No. of Pieces',
                    'Plate' => 'No. of Plates',
                    'Colour Separation' => 'No. of Sets',
                ];
                foreach ($stdCards as $matName => $qtyLabel): ?>
                    <div class="bg-white border border-gray-100 shadow-sm rounded-xl p-6 transition-all hover:shadow-md">
                        <h3 class="font-bold text-gray-800 mb-4 flex justify-between">
                            <span><?php echo $matName; ?></span>
                            <input type="hidden" name="material_id[]" value="<?php echo $getId($matName); ?>">
                        </h3>
                        <div class="grid grid-cols-3 gap-4">
                            <div>
                                <label
                                    class="block text-xs font-semibold text-gray-500 uppercase mb-1"><?php echo $qtyLabel; ?></label>
                                <input type="number" name="material_qty[]"
                                    class="w-full px-3 py-2 bg-gray-50 border-none rounded-lg focus:ring-2 focus:ring-green-500 std-calc-qty"
                                    placeholder="0">
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">Rate / Unit</label>
                                <input type="number" step="0.01" name="material_rate[]"
                                    value="<?php echo $getRate($matName); ?>"
                                    class="w-full px-3 py-2 bg-gray-50 border-none rounded-lg focus:ring-2 focus:ring-green-500 std-calc-rate">
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">Total (MKW)</label>
                                <input type="text" name="material_total[]" readonly
                                    class="w-full px-3 py-2 bg-gray-100 border-none rounded-lg font-bold text-gray-700 std-calc-total"
                                    value="0.00">
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- Additional Materials -->
            <div class="flex justify-between items-center mb-6">
                <h2 class="text-2xl font-bold text-gray-800">Additional Materials</h2>
                <div class="flex gap-2">
                    <button type="button" id="add-material-row"
                        class="bg-blue-600 text-white px-4 py-2 rounded-lg hover:bg-blue-700 transition flex items-center text-sm shadow-sm">
                        <i data-lucide="plus" class="mr-1 inline-block h-5 w-5 flex-shrink-0" aria-hidden="true"></i> Add Row
                    </button>
                    <button type="button" onclick="openQuickAddModal()"
                        class="bg-green-600 text-white px-4 py-2 rounded-lg hover:bg-green-700 transition flex items-center text-sm shadow-sm">
                        <i data-lucide="package" class="mr-1 inline-block h-5 w-5 flex-shrink-0" aria-hidden="true"></i> New Material
                    </button>
                </div>
            </div>
            <div class="overflow-x-auto bg-gray-50 p-4 rounded-xl">
                <table class="min-w-full" id="materials-table">
                    <thead>
                        <tr class="text-left text-xs font-bold text-gray-400 uppercase tracking-wider">
                            <th class="px-3 py-3">Material</th>
                            <th class="px-3 py-3">Quantity</th>
                            <th class="px-3 py-3">Rate (MK)</th>
                            <th class="px-3 py-3">Total</th>
                            <th class="px-3 py-3"></th>
                        </tr>
                    </thead>
                    <tbody id="material-rows"></tbody>
                </table>
            </div>
            <template id="material-row-template">
                <tr class="material-row">
                    <td class="px-3 py-4">
                        <select name="material_id[]" class="w-full border-gray-300 rounded-lg material-select">
                            <option value="">Select Material</option>
                            <?php foreach ($all_materials as $mat): ?>
                                <option value="<?php echo $mat['id']; ?>" data-rate="<?php echo $mat['rate']; ?>">
                                    <?php echo htmlspecialchars($mat['name']); ?>
                                    (<?php echo htmlspecialchars($mat['unit']); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                    <td class="px-3 py-4">
                        <input type="number" step="0.01" name="material_qty[]"
                            class="w-full border-gray-300 rounded-lg material-qty" placeholder="0.00">
                    </td>
                    <td class="px-3 py-4">
                        <input type="number" step="0.01" name="material_rate[]"
                            class="w-full border-gray-300 rounded-lg material-rate" placeholder="0.00">
                    </td>
                    <td class="px-3 py-4">
                        <input type="text" name="material_total[]" readonly
                            class="w-full border-none bg-transparent material-total font-bold text-gray-700"
                            value="0.00">
                    </td>
                    <td class="px-3 py-4 text-right">
                        <button type="button" class="text-red-500 hover:text-red-700 remove-row">
                            <i data-lucide="trash-2" class="h-5 w-5" aria-hidden="true"></i>
                        </button>
                    </td>
                </tr>
            </template>
        </div>

        <!-- ===== STEP 3: Paper (Multi-entry) ===== -->
        <div id="step-3" class="step-content hidden">
            <div class="flex justify-between items-center mb-6">
                <h2 class="text-2xl font-bold text-gray-800">Paper</h2>
                <button type="button" id="add-paper-btn"
                    class="bg-blue-600 text-white px-4 py-2 rounded-lg hover:bg-blue-700 transition flex items-center text-sm shadow-sm">
                    <i data-lucide="plus" class="mr-1 inline-block h-5 w-5 flex-shrink-0" aria-hidden="true"></i> Add Paper
                </button>
            </div>
            <div id="paper-entries" class="space-y-4">
                <!-- Default 4 paper entries rendered by JS -->
            </div>
            <div class="mt-4 bg-gray-50 p-4 rounded-lg flex justify-between items-center">
                <span class="font-bold text-gray-700">Total Paper Cost (MK)</span>
                <input type="text" name="cost_paper" id="cost_paper" readonly
                    class="bg-transparent text-right text-2xl font-bold text-gray-800 border-none focus:ring-0 w-48"
                    value="0">
            </div>
        </div>

        <!-- ===== STEP 4: Ink ===== -->
        <div id="step-4" class="step-content hidden">
            <h2 class="text-2xl font-bold text-gray-800 mb-6">Ink Calculation</h2>
            <div class="bg-green-50 border border-green-200 rounded-lg p-4 mb-6">
                <p class="text-sm text-green-800">
                    <strong>Formula:</strong> Ink kgs = (base_mm/1000 × height_mm/1000) × pages × quantity × 0.5 / 0.886
                    / 1000
                </p>
            </div>
            <div class="border border-gray-200 rounded-lg p-6 mb-6">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label class="block text-gray-700 font-semibold mb-2">Measure Base (mm)</label>
                        <input type="number" step="0.01" name="ink_measure_base"
                            class="w-full px-4 py-2 border border-gray-300 rounded-lg calc-ink-listen">
                    </div>
                    <div>
                        <label class="block text-gray-700 font-semibold mb-2">Height (mm)</label>
                        <input type="number" step="0.01" name="ink_height"
                            class="w-full px-4 py-2 border border-gray-300 rounded-lg calc-ink-listen">
                    </div>
                    <div>
                        <label class="block text-gray-700 font-semibold mb-2">No. of Pages</label>
                        <input type="number" name="ink_pages"
                            class="w-full px-4 py-2 border border-gray-300 rounded-lg calc-ink-listen">
                    </div>
                    <div>
                        <label class="block text-gray-700 font-semibold mb-2">Quantity (Copies)</label>
                        <input type="number" name="ink_quantity_copies"
                            class="w-full px-4 py-2 border border-gray-300 rounded-lg calc-ink-listen">
                    </div>
                    <div class="col-span-1 md:col-span-2 bg-gray-50 p-4 rounded-lg">
                        <label class="block text-gray-700 font-semibold mb-2">Calculated Total Ink (kgs)</label>
                        <input type="text" name="ink_kgs" id="ink_kgs" readonly
                            class="w-full bg-transparent text-3xl font-bold text-green-600 border-none focus:ring-0"
                            value="0.0000">
                    </div>
                </div>
            </div>

            <!-- Ink Colour Breakdown -->
            <div class="border border-gray-200 rounded-lg p-6">
                <div class="flex justify-between items-center mb-4">
                    <h3 class="text-lg font-bold text-gray-800">Ink Colour Breakdown</h3>
                    <button type="button" id="add-ink-colour-btn"
                        class="bg-blue-600 text-white px-3 py-1 rounded-lg hover:bg-blue-700 text-sm flex items-center">
                        <i data-lucide="plus" class="mr-1 inline-block h-4 w-4 flex-shrink-0" aria-hidden="true"></i> Add Colour
                    </button>
                </div>
                <div id="ink-colour-warning"
                    class="hidden bg-red-50 border border-red-300 text-red-700 text-sm p-2 rounded mb-3">
                    ⚠ Total colour kgs exceeds calculated ink amount!
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full">
                        <thead>
                            <tr class="text-xs font-bold text-gray-400 uppercase">
                                <th class="px-3 py-2 text-left">Colour</th>
                                <th class="px-3 py-2 text-left">Kgs</th>
                                <th class="px-3 py-2 text-left">Rate / kg (MK)</th>
                                <th class="px-3 py-2 text-left">Total (MK)</th>
                                <th class="px-3 py-2"></th>
                            </tr>
                        </thead>
                        <tbody id="ink-colour-rows">
                            <!-- Rows injected by JS -->
                        </tbody>
                    </table>
                </div>
                <div class="mt-4 flex justify-between items-center bg-gray-50 p-3 rounded-lg">
                    <span class="font-bold text-gray-700">Total Ink Cost (MK)</span>
                    <input type="text" name="cost_ink" id="cost_ink" readonly
                        class="bg-transparent text-right text-2xl font-bold text-gray-800 border-none focus:ring-0 w-48"
                        value="0">
                </div>
            </div>
        </div>

        <!-- ===== STEP 5: Binding Materials ===== -->
        <div id="step-5" class="step-content hidden">
            <div class="flex justify-between items-center mb-6">
                <h2 class="text-2xl font-bold text-gray-800">Binding Materials</h2>
                <div class="flex gap-2">
                    <button type="button" id="add-binding-row"
                        class="bg-blue-600 text-white px-4 py-2 rounded-lg hover:bg-blue-700 transition flex items-center text-sm shadow-sm">
                        <i data-lucide="plus" class="mr-1 inline-block h-5 w-5 flex-shrink-0" aria-hidden="true"></i> Add Material
                    </button>
                    <button type="button" onclick="openBindingAddModal()"
                        class="bg-green-600 text-white px-4 py-2 rounded-lg hover:bg-green-700 transition flex items-center text-sm shadow-sm">
                        <i data-lucide="package" class="mr-1 inline-block h-5 w-5 flex-shrink-0" aria-hidden="true"></i> New Material
                    </button>
                </div>
            </div>
            <div class="overflow-x-auto bg-gray-50 p-4 rounded-xl">
                <table class="min-w-full" id="binding-table">
                    <thead>
                        <tr class="text-left text-xs font-bold text-gray-400 uppercase tracking-wider">
                            <th class="px-3 py-3">Material</th>
                            <th class="px-3 py-3">Unit</th>
                            <th class="px-3 py-3">Quantity</th>
                            <th class="px-3 py-3">Rate (MK)</th>
                            <th class="px-3 py-3">Total (MK)</th>
                            <th class="px-3 py-3"></th>
                        </tr>
                    </thead>
                    <tbody id="binding-rows"></tbody>
                </table>
            </div>
            <div class="mt-4 bg-gray-50 p-4 rounded-lg flex justify-between items-center">
                <span class="font-bold text-gray-700">Total Binding Materials (MK)</span>
                <input type="text" name="cost_binding" id="cost_binding" readonly
                    class="bg-transparent text-right text-2xl font-bold text-gray-800 border-none focus:ring-0 w-48"
                    value="0">
            </div>

            <template id="binding-row-template">
                <tr class="binding-row">
                    <td class="px-3 py-3">
                        <select name="binding_mat_id[]" class="w-full border-gray-300 rounded-lg binding-mat-select">
                            <option value="">-- Select or type below --</option>
                            <?php foreach ($binding_materials as $bm): ?>
                                <option value="<?php echo $bm['id']; ?>" data-rate="<?php echo $bm['rate']; ?>"
                                    data-unit="<?php echo htmlspecialchars($bm['unit']); ?>">
                                    <?php echo htmlspecialchars($bm['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <input type="text" name="binding_mat_name[]"
                            class="w-full mt-1 border-gray-300 rounded-lg text-sm binding-mat-name"
                            placeholder="Or enter name manually">
                    </td>
                    <td class="px-3 py-3">
                        <input type="text" name="binding_mat_unit[]"
                            class="w-full border-gray-300 rounded-lg binding-mat-unit" placeholder="unit">
                    </td>
                    <td class="px-3 py-3">
                        <input type="number" step="0.01" name="binding_mat_qty[]"
                            class="w-full border-gray-300 rounded-lg binding-mat-qty" placeholder="0">
                    </td>
                    <td class="px-3 py-3">
                        <input type="number" step="0.01" name="binding_mat_rate[]"
                            class="w-full border-gray-300 rounded-lg binding-mat-rate" placeholder="0.00">
                    </td>
                    <td class="px-3 py-3">
                        <input type="text" name="binding_mat_total[]" readonly
                            class="w-full border-none bg-transparent binding-mat-total font-bold text-gray-700"
                            value="0.00">
                    </td>
                    <td class="px-3 py-3 text-right">
                        <button type="button" class="text-red-500 hover:text-red-700 remove-binding-row">
                            <i data-lucide="trash-2" class="h-5 w-5" aria-hidden="true"></i>
                        </button>
                    </td>
                </tr>
            </template>
        </div>

        <!-- ===== STEP 6: Labour ===== -->
        <div id="step-6" class="step-content hidden">
            <h2 class="text-2xl font-bold text-gray-800 mb-6">Labour</h2>

            <!-- Pre-press -->
            <div class="border border-gray-200 rounded-xl p-6 mb-6">
                <div class="flex justify-between items-center mb-4">
                    <h3 class="text-lg font-bold text-gray-700">Pre-press</h3>
                    <button type="button" id="add-prepress-row"
                        class="bg-blue-600 text-white px-3 py-1 rounded-lg hover:bg-blue-700 text-sm flex items-center">
                        <i data-lucide="plus" class="mr-1 inline-block h-4 w-4 flex-shrink-0" aria-hidden="true"></i> Add Pre-press Labour
                    </button>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full">
                        <thead>
                            <tr class="text-xs font-bold text-gray-400 uppercase">
                                <th class="px-3 py-2 text-left">Labour</th>
                                <th class="px-3 py-2 text-left">Unit</th>
                                <th class="px-3 py-2 text-left">Hrs</th>
                                <th class="px-3 py-2 text-left">Rate / hr (MK)</th>
                                <th class="px-3 py-2 text-left">Total (MK)</th>
                                <th class="px-3 py-2"></th>
                            </tr>
                        </thead>
                        <tbody id="prepress-rows">
                            <?php
                            $prepressItems = ['Design', 'Keying', 'Laying Out', 'Reading', 'Proof Making', 'Film Assembly', 'Platemaking'];
                            foreach ($prepressItems as $pp): ?>
                                <tr class="prepress-row">
                                    <td class="px-3 py-2">
                                        <input type="text" name="prepress_name[]" value="<?php echo $pp; ?>"
                                            class="w-full border-gray-300 rounded-lg prepress-name">
                                    </td>
                                    <td class="px-3 py-2">
                                        <span class="text-gray-600 font-semibold">hrs</span>
                                        <input type="hidden" name="prepress_unit[]" value="hrs">
                                    </td>
                                    <td class="px-3 py-2">
                                        <input type="number" step="0.01" name="prepress_hrs[]"
                                            class="w-full border-gray-300 rounded-lg prepress-hrs" placeholder="0">
                                    </td>
                                    <td class="px-3 py-2">
                                        <input type="number" step="0.01" name="prepress_rate[]"
                                            class="w-full border-gray-300 rounded-lg prepress-rate" placeholder="0.00">
                                    </td>
                                    <td class="px-3 py-2">
                                        <input type="text" name="prepress_total[]" readonly
                                            class="w-full border-none bg-transparent prepress-total font-bold text-gray-700"
                                            value="0.00">
                                    </td>
                                    <td class="px-3 py-2"></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="mt-3 flex justify-between items-center">
                    <span class="text-sm font-semibold text-gray-600">Pre-press Subtotal (MK)</span>
                    <input type="text" name="cost_prepress" id="cost_prepress" readonly
                        class="bg-transparent text-right text-xl font-bold text-gray-700 border-none focus:ring-0 w-40"
                        value="0">
                </div>
            </div>

            <!-- Press -->
            <div class="border border-gray-200 rounded-xl p-6 mb-6">
                <div class="flex justify-between items-center mb-4">
                    <h3 class="text-lg font-bold text-gray-700">Press</h3>
                    <button type="button" id="add-machine-btn"
                        class="bg-blue-600 text-white px-3 py-1 rounded-lg hover:bg-blue-700 text-sm flex items-center">
                        <i data-lucide="plus" class="mr-1 inline-block h-4 w-4 flex-shrink-0" aria-hidden="true"></i> Add Machine
                    </button>
                </div>
                <div id="press-machines" class="space-y-4">
                    <!-- Machine blocks injected by JS -->
                </div>
                <div class="mt-3 flex justify-between items-center">
                    <span class="text-sm font-semibold text-gray-600">Press Subtotal (MK)</span>
                    <input type="text" name="cost_press" id="cost_press" readonly
                        class="bg-transparent text-right text-xl font-bold text-gray-700 border-none focus:ring-0 w-40"
                        value="0">
                </div>
            </div>

            <!-- Finishing -->
            <div class="border border-gray-200 rounded-xl p-6 mb-6">
                <div class="flex justify-between items-center mb-4">
                    <h3 class="text-lg font-bold text-gray-700">Finishing</h3>
                    <button type="button" id="add-finishing-row"
                        class="bg-blue-600 text-white px-3 py-1 rounded-lg hover:bg-blue-700 text-sm flex items-center">
                        <i data-lucide="plus" class="mr-1 inline-block h-4 w-4 flex-shrink-0" aria-hidden="true"></i> Add Finishing Labour
                    </button>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full">
                        <thead>
                            <tr class="text-xs font-bold text-gray-400 uppercase">
                                <th class="px-3 py-2 text-left">Labour</th>
                                <th class="px-3 py-2 text-left">Measure</th>
                                <th class="px-3 py-2 text-left">Impressions</th>
                                <th class="px-3 py-2 text-left">IPH</th>
                                <th class="px-3 py-2 text-left">Hrs</th>
                                <th class="px-3 py-2 text-left">Rate / hr (MK)</th>
                                <th class="px-3 py-2 text-left">Total (MK)</th>
                                <th class="px-3 py-2"></th>
                            </tr>
                        </thead>
                        <tbody id="finishing-rows">
                            <?php
                            $finishingItems = [
                                ['Numbering', 'numbers'],
                                ['Perforating', 'perfs'],
                                ['Saddle Stitching', 'books'],
                                ['Perfect Binding', 'books'],
                                ['Paper Cutting', 'reams'],
                                ['Trimming', 'items'],
                                ['Case Making', 'items'],
                                ['Gold Blocking', 'items'],
                            ];
                            foreach ($finishingItems as [$fname, $fmeasure]): ?>
                                <tr class="finishing-row">
                                    <td class="px-3 py-2">
                                        <input type="text" name="finishing_name[]" value="<?php echo $fname; ?>"
                                            class="w-full border-gray-300 rounded-lg finishing-name">
                                    </td>
                                    <td class="px-3 py-2">
                                        <select name="finishing_measure[]"
                                            class="w-full border-gray-300 rounded-lg finishing-measure">
                                            <option value="items" <?php echo $fmeasure === 'items' ? 'selected' : ''; ?>>Items
                                            </option>
                                            <option value="books" <?php echo $fmeasure === 'books' ? 'selected' : ''; ?>>Books
                                            </option>
                                            <option value="reams" <?php echo $fmeasure === 'reams' ? 'selected' : ''; ?>>Reams
                                            </option>
                                            <option value="numbers" <?php echo $fmeasure === 'numbers' ? 'selected' : ''; ?>>
                                                Numbers</option>
                                            <option value="perfs" <?php echo $fmeasure === 'perfs' ? 'selected' : ''; ?>>Perfs
                                            </option>
                                            <option value="others">Others</option>
                                        </select>
                                    </td>
                                    <td class="px-3 py-2">
                                        <input type="number" name="finishing_impressions[]"
                                            class="w-full border-gray-300 rounded-lg finishing-impressions" placeholder="0">
                                    </td>
                                    <td class="px-3 py-2">
                                        <input type="number" name="finishing_iph[]"
                                            class="w-full border-gray-300 rounded-lg finishing-iph" placeholder="0">
                                    </td>
                                    <td class="px-3 py-2">
                                        <input type="number" step="0.01" name="finishing_hrs[]"
                                            class="w-full border-gray-300 rounded-lg finishing-hrs" placeholder="0">
                                    </td>
                                    <td class="px-3 py-2">
                                        <input type="number" step="0.01" name="finishing_rate[]"
                                            class="w-full border-gray-300 rounded-lg finishing-rate" placeholder="0.00">
                                    </td>
                                    <td class="px-3 py-2">
                                        <input type="text" name="finishing_total[]" readonly
                                            class="w-full border-none bg-transparent finishing-total font-bold text-gray-700"
                                            value="0.00">
                                    </td>
                                    <td class="px-3 py-2"></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="mt-3 flex justify-between items-center">
                    <span class="text-sm font-semibold text-gray-600">Finishing Subtotal (MK)</span>
                    <input type="text" name="cost_finishing" id="cost_finishing" readonly
                        class="bg-transparent text-right text-xl font-bold text-gray-700 border-none focus:ring-0 w-40"
                        value="0">
                </div>
            </div>

            <!-- Labour Grand Total -->
            <div class="bg-gray-50 p-4 rounded-lg flex justify-between items-center">
                <span class="font-bold text-gray-700">Total Labour (MK)</span>
                <input type="text" name="cost_labour_total" id="cost_labour_total" readonly
                    class="bg-transparent text-right text-2xl font-bold text-gray-800 border-none focus:ring-0 w-48"
                    value="0">
            </div>
        </div>

        <!-- ===== STEP 7: Consumables ===== -->
        <div id="step-7" class="step-content hidden">
            <h2 class="text-2xl font-bold text-gray-800 mb-6">Consumables</h2>
            <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-6 text-center">
                <i data-lucide="hammer" class="mb-4 inline-block h-16 w-16 text-yellow-600" aria-hidden="true"></i>
                <p class="text-gray-700">Consumables section — enter a flat rate if applicable.</p>
                <div class="mt-4 max-w-xs mx-auto">
                    <label class="block text-gray-700 font-semibold mb-2">Consumables Cost (MK)</label>
                    <input type="number" step="0.01" name="cost_consumables"
                        class="w-full px-4 py-2 border border-gray-300 rounded-lg">
                </div>
            </div>
        </div>

        <!-- ===== STEP 8: Totals ===== -->
        <div id="step-8" class="step-content hidden">
            <h2 class="text-2xl font-bold text-gray-800 mb-6">Final Totals</h2>
            <div class="space-y-4">
                <div class="bg-gray-50 p-4 rounded-lg">
                    <div class="flex justify-between items-center">
                        <span class="text-gray-700 font-semibold">Subtotal (All Costs)</span>
                        <input type="text" name="subtotal" readonly
                            class="bg-transparent text-right text-2xl font-bold text-gray-800 border-none focus:ring-0"
                            value="0">
                    </div>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-gray-700 font-semibold mb-2">Profit Percentage (%)</label>
                        <input type="number" step="0.01" name="profit_margin" value="20"
                            class="w-full px-4 py-2 border border-gray-300 rounded-lg calc-final">
                    </div>
                    <div>
                        <label class="block text-gray-700 font-semibold mb-2">Overtime + Supervision (MK)</label>
                        <input type="number" step="0.01" name="cost_labour" value="0"
                            class="w-full px-4 py-2 border border-gray-300 rounded-lg calc-final">
                    </div>
                </div>
                <div>
                    <label class="block text-gray-700 font-semibold mb-2">VAT Percentage (%)</label>
                    <input type="number" step="0.01" name="vat_percent" value="17.5"
                        class="w-full px-4 py-2 border border-gray-300 rounded-lg calc-final">
                </div>
                <div class="bg-green-600 text-white p-6 rounded-lg mt-6">
                    <div class="flex justify-between items-center">
                        <span class="text-xl font-semibold">Grand Total</span>
                        <input type="text" name="grand_total" readonly
                            class="bg-transparent text-right text-4xl font-bold text-white border-none focus:ring-0 w-full"
                            value="0">
                    </div>
                </div>
            </div>
        </div>

        <!-- Navigation Buttons -->
        <div class="flex justify-between mt-8 pt-6 border-t border-gray-200">
            <div>
                <button type="button"
                    class="prev-step hidden bg-gray-300 hover:bg-gray-400 text-gray-800 font-bold py-3 px-6 rounded-lg transition">
                    Previous
                </button>
            </div>
            <div class="flex gap-4">
                <button type="submit" name="save_draft"
                    class="bg-yellow-500 text-white font-bold py-3 px-6 rounded-lg hover:bg-yellow-600 transition">
                    Save as Draft
                </button>
                <button type="button"
                    class="next-step bg-green-600 hover:bg-green-700 text-white font-bold py-3 px-6 rounded-lg transition">
                    Next Step
                </button>
                <button type="submit"
                    class="submit-btn hidden bg-green-600 hover:bg-green-700 text-white font-bold py-3 px-6 rounded-lg transition">
                    Complete Estimation
                </button>
            </div>
        </div>
    </form>
</div>

<!-- Quick Add Material Modal (General) -->
<div id="quickAddModal" class="fixed inset-0 bg-black bg-opacity-50 hidden flex items-center justify-center z-50">
    <div class="bg-white rounded-xl shadow-2xl p-8 w-full max-w-md">
        <div class="flex justify-between items-center mb-6">
            <h3 class="text-2xl font-bold text-gray-800">New Material</h3>
            <button onclick="closeQuickAddModal()" class="text-gray-400 hover:text-gray-600">
                <i data-lucide="x" class="h-6 w-6" aria-hidden="true"></i>
            </button>
        </div>
        <form id="quickAddForm">
            <input type="hidden" name="action" value="quick_add">
            <div class="space-y-4">
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">Material Name *</label>
                    <input type="text" name="name" required
                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 outline-none">
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">Unit *</label>
                    <input type="text" name="unit" required placeholder="e.g., sheet, kg"
                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 outline-none">
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">Initial Rate (MK) *</label>
                    <input type="number" step="0.01" name="rate" required
                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 outline-none">
                </div>
                <div class="pt-4">
                    <button type="submit"
                        class="w-full bg-green-600 text-white font-bold py-3 rounded-lg hover:bg-green-700 transition shadow-lg">
                        Save &amp; Add to List
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Quick Add Binding Material Modal -->
<div id="bindingAddModal" class="fixed inset-0 bg-black bg-opacity-50 hidden flex items-center justify-center z-50">
    <div class="bg-white rounded-xl shadow-2xl p-8 w-full max-w-md">
        <div class="flex justify-between items-center mb-6">
            <h3 class="text-2xl font-bold text-gray-800">New Binding Material</h3>
            <button onclick="closeBindingAddModal()" class="text-gray-400 hover:text-gray-600">
                <i data-lucide="x" class="h-6 w-6" aria-hidden="true"></i>
            </button>
        </div>
        <form id="bindingAddForm">
            <input type="hidden" name="action" value="quick_add">
            <input type="hidden" name="category_id" value="<?php echo $binding_cat_id; ?>">
            <div class="space-y-4">
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">Material Name *</label>
                    <input type="text" name="name" required
                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 outline-none">
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">Unit *</label>
                    <input type="text" name="unit" required placeholder="e.g., roll, piece"
                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 outline-none">
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">Rate (MK) *</label>
                    <input type="number" step="0.01" name="rate" required
                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 outline-none">
                </div>
                <div class="pt-4">
                    <button type="submit"
                        class="w-full bg-green-600 text-white font-bold py-3 rounded-lg hover:bg-green-700 transition shadow-lg">
                        Save &amp; Add to Binding List
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Pre-press custom row template -->
<template id="prepress-row-template">
    <tr class="prepress-row">
        <td class="px-3 py-2">
            <input type="text" name="prepress_name[]" class="w-full border-gray-300 rounded-lg prepress-name"
                placeholder="Labour name">
        </td>
        <td class="px-3 py-2">
            <span class="text-gray-600 font-semibold">hrs</span>
            <input type="hidden" name="prepress_unit[]" value="hrs">
        </td>
        <td class="px-3 py-2">
            <input type="number" step="0.01" name="prepress_hrs[]"
                class="w-full border-gray-300 rounded-lg prepress-hrs" placeholder="0">
        </td>
        <td class="px-3 py-2">
            <input type="number" step="0.01" name="prepress_rate[]"
                class="w-full border-gray-300 rounded-lg prepress-rate" placeholder="0.00">
        </td>
        <td class="px-3 py-2">
            <input type="text" name="prepress_total[]" readonly
                class="w-full border-none bg-transparent prepress-total font-bold text-gray-700" value="0.00">
        </td>
        <td class="px-3 py-2">
            <button type="button" class="text-red-500 hover:text-red-700 remove-prepress-row">
                <i data-lucide="trash-2" class="h-5 w-5" aria-hidden="true"></i>
            </button>
        </td>
    </tr>
</template>

<!-- Finishing custom row template -->
<template id="finishing-row-template">
    <tr class="finishing-row">
        <td class="px-3 py-2">
            <input type="text" name="finishing_name[]" class="w-full border-gray-300 rounded-lg finishing-name"
                placeholder="Labour name">
        </td>
        <td class="px-3 py-2">
            <select name="finishing_measure[]" class="w-full border-gray-300 rounded-lg finishing-measure">
                <option value="items">Items</option>
                <option value="books">Books</option>
                <option value="reams">Reams</option>
                <option value="numbers">Numbers</option>
                <option value="perfs">Perfs</option>
                <option value="others">Others</option>
            </select>
        </td>
        <td class="px-3 py-2">
            <input type="number" name="finishing_impressions[]"
                class="w-full border-gray-300 rounded-lg finishing-impressions" placeholder="0">
        </td>
        <td class="px-3 py-2">
            <input type="number" name="finishing_iph[]" class="w-full border-gray-300 rounded-lg finishing-iph"
                placeholder="0">
        </td>
        <td class="px-3 py-2">
            <input type="number" step="0.01" name="finishing_hrs[]"
                class="w-full border-gray-300 rounded-lg finishing-hrs" placeholder="0">
        </td>
        <td class="px-3 py-2">
            <input type="number" step="0.01" name="finishing_rate[]"
                class="w-full border-gray-300 rounded-lg finishing-rate" placeholder="0.00">
        </td>
        <td class="px-3 py-2">
            <input type="text" name="finishing_total[]" readonly
                class="w-full border-none bg-transparent finishing-total font-bold text-gray-700" value="0.00">
        </td>
        <td class="px-3 py-2">
            <button type="button" class="text-red-500 hover:text-red-700 remove-finishing-row">
                <i data-lucide="trash-2" class="h-5 w-5" aria-hidden="true"></i>
            </button>
        </td>
    </tr>
</template>

<script src="../../assets/js/estimation_wizard.js?v=<?php echo time(); ?>"></script>
<script>if (typeof window.refreshAppShellIcons === 'function') { window.refreshAppShellIcons(); }</script>
<?php include '../../includes/footer.php'; ?>