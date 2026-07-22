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
        require_once __DIR__ . '/../../includes/material_match_helper.php';
        ?>
        <div id="step-2" class="step-content hidden">
            <h2 class="text-2xl font-bold text-gray-800 mb-6">Standard Materials</h2>
            <p class="text-sm text-gray-500 mb-6">Select the size or dimensions for each item; rates are pulled from the materials catalog.</p>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-10">
                <?php foreach (ESTIMATION_STD_MATERIAL_SLOTS as $slot): ?>
                    <div class="bg-white border border-gray-100 shadow-sm rounded-xl p-6 transition-all hover:shadow-md std-material-card"
                        data-std-key="<?php echo htmlspecialchars($slot['key']); ?>"
                        data-material-kind="<?php echo htmlspecialchars($slot['material_kind']); ?>"
                        data-stock-type="<?php echo htmlspecialchars($slot['stock_type'] ?? ''); ?>">
                        <h3 class="font-bold text-gray-800 mb-4"><?php echo htmlspecialchars($slot['label']); ?></h3>
                        <div class="mb-4">
                            <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">Size / Dimensions</label>
                            <select name="std_mat_dimensions[]" class="std-mat-dimensions w-full px-3 py-2 bg-gray-50 border border-gray-200 rounded-lg focus:ring-2 focus:ring-green-500">
                                <option value="">Select size…</option>
                            </select>
                            <p class="text-xs text-gray-400 mt-1 std-mat-selected-name"></p>
                        </div>
                        <input type="hidden" name="material_id[]" class="std-mat-id" value="">
                        <div class="grid grid-cols-3 gap-4">
                            <div>
                                <label class="block text-xs font-semibold text-gray-500 uppercase mb-1"><?php echo htmlspecialchars($slot['qty_label']); ?></label>
                                <input type="number" name="material_qty[]"
                                    class="w-full px-3 py-2 bg-gray-50 border-none rounded-lg focus:ring-2 focus:ring-green-500 std-calc-qty"
                                    placeholder="0">
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">Rate / Unit</label>
                                <input type="number" step="0.01" name="material_rate[]"
                                    class="w-full px-3 py-2 bg-gray-50 border-none rounded-lg focus:ring-2 focus:ring-green-500 std-calc-rate"
                                    value="0">
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">Total (MK)</label>
                                <input type="text" name="material_total[]" readonly
                                    class="w-full px-3 py-2 bg-gray-100 border-none rounded-lg font-bold text-gray-700 std-calc-total"
                                    value="0.00">
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- ===== STEP 3: Paper (Multi-entry) ===== -->
        <div id="step-3" class="step-content hidden">
            <div class="flex flex-col sm:flex-row sm:justify-between sm:items-center gap-3 mb-6">
                <div>
                    <h2 class="text-2xl font-bold text-gray-800">Paper</h2>
                    <p class="text-sm text-gray-500 mt-1">Pick stock specs from the catalog — rates fill in automatically when matched.</p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <button type="button" onclick="openPaperQuickAddModal()"
                        class="bg-green-600 text-white px-4 py-2 rounded-lg hover:bg-green-700 transition flex items-center text-sm shadow-sm">
                        <i data-lucide="plus" class="mr-1 inline-block h-5 w-5 flex-shrink-0" aria-hidden="true"></i> New Catalog Paper
                    </button>
                    <button type="button" id="add-paper-btn"
                        class="bg-blue-600 text-white px-4 py-2 rounded-lg hover:bg-blue-700 transition flex items-center text-sm shadow-sm">
                        <i data-lucide="layers" class="mr-1 inline-block h-5 w-5 flex-shrink-0" aria-hidden="true"></i> Add Paper Row
                    </button>
                </div>
            </div>
            <div id="paper-entries" class="space-y-5">
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

            <div class="border border-gray-200 rounded-lg p-4 mb-6">
                <label class="block text-gray-700 font-semibold mb-2">Calculation method</label>
                <input type="hidden" name="ink_calc_mode" id="ink_calc_mode" value="formula_breakdown">
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-2" id="ink-calc-mode-options" role="radiogroup" aria-label="Ink calculation method">
                    <button type="button" data-ink-mode="formula"
                        class="ink-mode-btn text-left px-3 py-3 rounded-lg border border-gray-200 hover:border-green-400 transition">
                        <span class="block font-semibold text-gray-800 text-sm">Formula only</span>
                        <span class="block text-xs text-gray-500 mt-1">Formula kgs × overall rate</span>
                    </button>
                    <button type="button" data-ink-mode="formula_breakdown"
                        class="ink-mode-btn text-left px-3 py-3 rounded-lg border border-green-500 bg-green-50 transition">
                        <span class="block font-semibold text-gray-800 text-sm">Formula + breakdown</span>
                        <span class="block text-xs text-gray-500 mt-1">% of formula kgs + colour rates</span>
                    </button>
                    <button type="button" data-ink-mode="breakdown"
                        class="ink-mode-btn text-left px-3 py-3 rounded-lg border border-gray-200 hover:border-green-400 transition">
                        <span class="block font-semibold text-gray-800 text-sm">Breakdown only</span>
                        <span class="block text-xs text-gray-500 mt-1">Manual colour kgs + rates (no formula)</span>
                    </button>
                </div>
            </div>

            <div id="ink-formula-panel" class="bg-green-50 border border-green-200 rounded-lg p-4 mb-6">
                <p class="text-sm text-green-800">
                    <strong>Formula:</strong> Ink kgs = (base_mm/1000 × height_mm/1000) × pages × quantity × 0.5 / 0.886 / 1000
                </p>
            </div>
            <div id="ink-formula-fields" class="border border-gray-200 rounded-lg p-6 mb-6">
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
                    <div id="ink-formula-rate-wrap" class="col-span-1 md:col-span-2 hidden">
                        <label class="block text-gray-700 font-semibold mb-2">Overall Ink Rate / kg (MK)</label>
                        <input type="number" step="0.01" name="ink_overall_rate" id="ink_overall_rate" value="15000"
                            class="w-full px-4 py-2 border border-gray-300 rounded-lg calc-ink-listen">
                    </div>
                </div>
            </div>

            <!-- Ink Colour Breakdown -->
            <div id="ink-breakdown-panel" class="border border-gray-200 rounded-lg p-6">
                <div class="mb-4">
                    <label class="block text-gray-700 font-semibold mb-2">Ink Brand / Type (optional filter)</label>
                    <select id="ink-brand-filter" class="w-full max-w-md px-4 py-2 border border-gray-300 rounded-lg">
                        <option value="">All brands / types</option>
                    </select>
                </div>
                <div class="flex justify-between items-center mb-4">
                    <div>
                        <h3 class="text-lg font-bold text-gray-800">Ink Colour Breakdown</h3>
                        <p id="ink-breakdown-hint" class="text-xs text-gray-500 mt-1">
                            Enter colour percentages and rates; kgs are taken from the formula total.
                        </p>
                    </div>
                    <button type="button" id="add-ink-colour-btn"
                        class="bg-blue-600 text-white px-3 py-1 rounded-lg hover:bg-blue-700 text-sm flex items-center">
                        <i data-lucide="plus" class="mr-1 inline-block h-4 w-4 flex-shrink-0" aria-hidden="true"></i> Add Colour
                    </button>
                </div>
                <div id="ink-colour-warning"
                    class="hidden bg-red-50 border border-red-300 text-red-700 text-sm p-2 rounded mb-3">
                    Total colour allocation exceeds the calculated ink amount!
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full">
                        <thead>
                            <tr class="text-xs font-bold text-gray-400 uppercase">
                                <th class="px-3 py-2 text-left">Colour</th>
                                <th class="px-3 py-2 text-left ink-col-pct">%</th>
                                <th class="px-3 py-2 text-left ink-col-kgs">Kgs</th>
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
            </div>

            <div class="mt-4 flex justify-between items-center bg-gray-50 p-3 rounded-lg border border-gray-200">
                <span class="font-bold text-gray-700">Total Ink Cost (MK)</span>
                <input type="text" name="cost_ink" id="cost_ink" readonly
                    class="bg-transparent text-right text-2xl font-bold text-gray-800 border-none focus:ring-0 w-48"
                    value="0">
            </div>
        </div>

        <!-- ===== STEP 5: Binding Materials ===== -->
        <div id="step-5" class="step-content hidden">
            <h2 class="text-2xl font-bold text-gray-800 mb-2">Binding Materials</h2>
            <p class="text-sm text-gray-500 mb-4">Add a row for each binding item on this job, or add new items to the catalog.</p>
            <div class="flex items-center gap-2 mb-6">
                <button type="button" id="add-binding-row"
                    class="bg-blue-600 text-white px-4 py-2 rounded-lg hover:bg-blue-700 transition inline-flex items-center text-sm shadow-sm whitespace-nowrap"
                    title="Add another binding line to this estimation">
                    <i data-lucide="list-plus" class="mr-1.5 inline-block h-4 w-4 flex-shrink-0" aria-hidden="true"></i> Add Row
                </button>
                <button type="button" onclick="openBindingAddModal(true)"
                    class="bg-green-600 text-white px-4 py-2 rounded-lg hover:bg-green-700 transition inline-flex items-center text-sm shadow-sm whitespace-nowrap"
                    title="Create a new binding material in the catalog">
                    <i data-lucide="plus" class="mr-1.5 inline-block h-4 w-4 flex-shrink-0" aria-hidden="true"></i> Add to Catalog
                </button>
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
                    <td class="px-3 py-3 align-top">
                        <div class="grid grid-cols-2 gap-2 mb-2 binding-filter-row">
                            <select class="binding-filter-stock w-full border-gray-300 rounded-lg text-xs px-2 py-1.5 bg-white">
                                <option value="">All types</option>
                            </select>
                            <select class="binding-filter-color w-full border-gray-300 rounded-lg text-xs px-2 py-1.5 bg-white">
                                <option value="">All colours</option>
                            </select>
                        </div>
                        <div class="flex items-stretch gap-1.5">
                            <select name="binding_mat_id[]" class="binding-mat-select flex-1 min-w-0 border-gray-300 rounded-lg px-2 py-2 text-sm bg-white">
                                <option value="">Select material…</option>
                                <?php foreach ($binding_materials as $bm): ?>
                                    <option value="<?php echo $bm['id']; ?>" data-rate="<?php echo $bm['rate']; ?>"
                                        data-unit="<?php echo htmlspecialchars($bm['unit']); ?>"
                                        data-stock-type="<?php echo htmlspecialchars($bm['stock_type'] ?? ''); ?>"
                                        data-color="<?php echo htmlspecialchars($bm['color'] ?? ''); ?>"
                                        data-thickness="<?php echo htmlspecialchars($bm['thickness_mm'] ?? ''); ?>">
                                        <?php echo htmlspecialchars($bm['name']); ?>
                                        (<?php echo htmlspecialchars($bm['unit']); ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <button type="button"
                                class="binding-quick-add inline-flex items-center justify-center w-9 shrink-0 rounded-lg border border-green-200 bg-green-50 text-green-700 hover:bg-green-100 transition"
                                title="Add to catalog">
                                <i data-lucide="plus" class="h-4 w-4" aria-hidden="true"></i>
                            </button>
                        </div>
                    </td>
                    <td class="px-3 py-3 align-top">
                        <input type="text" name="binding_mat_unit[]" readonly
                            class="w-full border-gray-300 rounded-lg binding-mat-unit bg-gray-50 px-2 py-2 text-sm" placeholder="unit">
                    </td>
                    <td class="px-3 py-3 align-top">
                        <input type="number" step="0.01" name="binding_mat_qty[]"
                            class="w-full border-gray-300 rounded-lg binding-mat-qty px-2 py-2 text-sm" placeholder="0">
                    </td>
                    <td class="px-3 py-3 align-top">
                        <input type="number" step="0.01" name="binding_mat_rate[]"
                            class="w-full border-gray-300 rounded-lg binding-mat-rate px-2 py-2 text-sm" placeholder="0.00">
                    </td>
                    <td class="px-3 py-3 align-top">
                        <input type="text" name="binding_mat_total[]" readonly
                            class="w-full border-none bg-transparent binding-mat-total font-bold text-gray-700 text-sm"
                            value="0.00">
                    </td>
                    <td class="px-3 py-3 align-top text-right">
                        <button type="button" class="text-red-500 hover:text-red-700 remove-binding-row" title="Remove row">
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
                    <div class="flex gap-2">
                        <button type="button" id="add-prepress-row"
                            class="bg-blue-600 text-white px-3 py-1 rounded-lg hover:bg-blue-700 text-sm flex items-center">
                            <i data-lucide="plus" class="mr-1 inline-block h-4 w-4 flex-shrink-0" aria-hidden="true"></i> Add Task
                        </button>
                        <button type="button" onclick="openLabourAddModal('prepress')"
                            class="bg-green-600 text-white px-3 py-1 rounded-lg hover:bg-green-700 text-sm flex items-center">
                            <i data-lucide="user-plus" class="mr-1 inline-block h-4 w-4 flex-shrink-0" aria-hidden="true"></i> New Task
                        </button>
                    </div>
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
                        <tbody id="prepress-rows"></tbody>
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
                    <div class="flex gap-2">
                        <button type="button" id="add-machine-btn"
                            class="bg-blue-600 text-white px-3 py-1 rounded-lg hover:bg-blue-700 text-sm flex items-center">
                            <i data-lucide="plus" class="mr-1 inline-block h-4 w-4 flex-shrink-0" aria-hidden="true"></i> Add Machine
                        </button>
                        <button type="button" onclick="openLabourAddModal('press')"
                            class="bg-green-600 text-white px-3 py-1 rounded-lg hover:bg-green-700 text-sm flex items-center">
                            <i data-lucide="user-plus" class="mr-1 inline-block h-4 w-4 flex-shrink-0" aria-hidden="true"></i> New Machine
                        </button>
                    </div>
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
                    <div class="flex gap-2">
                        <button type="button" id="add-finishing-row"
                            class="bg-blue-600 text-white px-3 py-1 rounded-lg hover:bg-blue-700 text-sm flex items-center">
                            <i data-lucide="plus" class="mr-1 inline-block h-4 w-4 flex-shrink-0" aria-hidden="true"></i> Add Task
                        </button>
                        <button type="button" onclick="openLabourAddModal('finishing')"
                            class="bg-green-600 text-white px-3 py-1 rounded-lg hover:bg-green-700 text-sm flex items-center">
                            <i data-lucide="user-plus" class="mr-1 inline-block h-4 w-4 flex-shrink-0" aria-hidden="true"></i> New Task
                        </button>
                    </div>
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
                        <tbody id="finishing-rows"></tbody>
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
            <div class="flex justify-between items-center mb-6">
                <div>
                    <h2 class="text-2xl font-bold text-gray-800">Printing Consumables</h2>
                    <p class="text-sm text-gray-500 mt-1">Select consumables from the catalog; totals roll into miscellaneous costs.</p>
                </div>
                <button type="button" id="add-consumable-row"
                    class="bg-blue-600 text-white px-4 py-2 rounded-lg hover:bg-blue-700 transition flex items-center text-sm shadow-sm">
                    <i data-lucide="plus" class="mr-1 inline-block h-5 w-5 flex-shrink-0" aria-hidden="true"></i> Add Consumable
                </button>
            </div>
            <div class="overflow-x-auto bg-gray-50 p-4 rounded-xl mb-4">
                <table class="min-w-full" id="consumable-table">
                    <thead>
                        <tr class="text-left text-xs font-bold text-gray-400 uppercase tracking-wider">
                            <th class="px-3 py-3">Product Type</th>
                            <th class="px-3 py-3">Material</th>
                            <th class="px-3 py-3">Unit</th>
                            <th class="px-3 py-3">Quantity</th>
                            <th class="px-3 py-3">Rate (MK)</th>
                            <th class="px-3 py-3">Total (MK)</th>
                            <th class="px-3 py-3"></th>
                        </tr>
                    </thead>
                    <tbody id="consumable-rows"></tbody>
                </table>
            </div>
            <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-4">
                <label class="block text-gray-700 font-semibold mb-2">Additional Miscellaneous Costs (MK)</label>
                <input type="number" step="0.01" name="cost_consumables_misc" id="cost_consumables_misc"
                    class="w-full max-w-xs px-4 py-2 border border-gray-300 rounded-lg calc-final consumables-misc-input"
                    placeholder="0.00" value="0">
                <p class="text-xs text-gray-500 mt-2">Manual amount added on top of catalog consumable lines.</p>
            </div>
            <input type="hidden" name="cost_consumables" id="cost_consumables" value="0">
        </div>

        <!-- ===== STEP 8: Final Totals ===== -->
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
                            class="bg-transparent text-right text-4xl font-bold text-gray-800 border-none focus:ring-0 w-full"
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
