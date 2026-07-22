<!-- Draft version restore confirmation -->
<div id="draftVersionRestoreModal" class="fixed inset-0 bg-black bg-opacity-50 hidden flex items-center justify-center z-[60]" role="dialog" aria-modal="true" aria-labelledby="draftVersionRestoreTitle">
    <div class="bg-white rounded-xl shadow-2xl p-6 w-full max-w-md mx-4">
        <h3 id="draftVersionRestoreTitle" class="text-xl font-bold text-gray-800 mb-2">Restore this step checkpoint?</h3>
        <p id="draftVersionRestoreMessage" class="text-sm text-gray-600 mb-6">
            Replace the current form with the saved checkpoint for this wizard step. Unsaved changes will be lost.
        </p>
        <div class="flex gap-3">
            <button type="button" id="draftVersionRestoreCancel"
                class="flex-1 bg-gray-300 text-gray-800 font-bold py-2 rounded-lg hover:bg-gray-400">
                Cancel
            </button>
            <button type="button" id="draftVersionRestoreConfirm"
                class="flex-1 bg-amber-600 text-white font-bold py-2 rounded-lg hover:bg-amber-700">
                Restore checkpoint
            </button>
        </div>
    </div>
</div>

<!-- Draft sync conflict modal -->
<div id="draftConflictModal" class="fixed inset-0 bg-black bg-opacity-50 hidden flex items-center justify-center z-[60]" role="dialog" aria-modal="true" aria-labelledby="draftConflictTitle">
    <div class="bg-white rounded-xl shadow-2xl p-6 w-full max-w-lg mx-4">
        <div class="flex items-start gap-3 mb-4">
            <div class="flex-shrink-0 mt-0.5 text-amber-600">
                <i data-lucide="alert-triangle" class="h-6 w-6" aria-hidden="true"></i>
            </div>
            <div>
                <h3 id="draftConflictTitle" class="text-xl font-bold text-gray-800">Draft conflict</h3>
                <p class="text-sm text-gray-600 mt-1">
                    This draft was updated on another device or browser. Choose which version to keep — your unsaved local changes will not be discarded until you pick an option.
                </p>
            </div>
        </div>
        <div class="space-y-2">
            <button type="button" id="draftConflictUseServer"
                class="w-full text-left px-4 py-3 rounded-lg border border-gray-200 hover:border-blue-400 hover:bg-blue-50 transition">
                <span class="block font-semibold text-gray-800">Use server version</span>
                <span class="block text-xs text-gray-500 mt-0.5">Load the version from the server and discard local changes on this device.</span>
            </button>
            <button type="button" id="draftConflictKeepLocal"
                class="w-full text-left px-4 py-3 rounded-lg border border-gray-200 hover:border-green-400 hover:bg-green-50 transition">
                <span class="block font-semibold text-gray-800">Keep this device</span>
                <span class="block text-xs text-gray-500 mt-0.5">Overwrite the server with what you have open here.</span>
            </button>
            <button type="button" id="draftConflictKeepBoth"
                class="w-full text-left px-4 py-3 rounded-lg border border-gray-200 hover:border-amber-400 hover:bg-amber-50 transition">
                <span class="block font-semibold text-gray-800">Keep both</span>
                <span class="block text-xs text-gray-500 mt-0.5">Save this device’s version as a new draft, then open the server version here.</span>
            </button>
        </div>
    </div>
</div>

<!-- Quick Add Paper Material Modal -->
<div id="paperAddModal" class="fixed inset-0 bg-black bg-opacity-50 hidden flex items-center justify-center z-50">
    <div class="bg-white rounded-xl shadow-2xl p-8 w-full max-w-lg mx-4 max-h-[90vh] overflow-y-auto">
        <div class="flex justify-between items-center mb-6">
            <div>
                <h3 class="text-2xl font-bold text-gray-800">New Catalog Paper</h3>
                <p class="text-sm text-gray-500 mt-1">Saved to Printing Papers and applied to the current row.</p>
            </div>
            <button type="button" onclick="closePaperQuickAddModal()" class="text-gray-400 hover:text-gray-600">
                <i data-lucide="x" class="h-6 w-6" aria-hidden="true"></i>
            </button>
        </div>
        <form id="paperAddForm">
            <input type="hidden" name="action" value="quick_add">
            <input type="hidden" name="category_id" value="<?php echo (int) ($paper_cat_id ?? 0); ?>">
            <input type="hidden" name="material_kind" value="paper">
            <input type="hidden" name="unit" value="Sheets">
            <input type="hidden" name="name" id="paper_add_generated_name" value="">
            <div class="space-y-4">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">Stock Type *</label>
                        <input type="text" name="stock_type" id="paper_add_stock_type" required
                            placeholder="e.g. Manilla, Bond Paper"
                            class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 outline-none">
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">Colour</label>
                        <input type="text" name="color" id="paper_add_color"
                            placeholder="e.g. Pink, Blue, White"
                            class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 outline-none">
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">Grammage (gsm) *</label>
                        <input type="number" step="0.01" name="grammage" id="paper_add_grammage" required
                            placeholder="e.g. 160"
                            class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 outline-none">
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">Size (optional)</label>
                        <input type="text" name="dimensions" id="paper_add_dimensions"
                            placeholder="e.g. A4, 210x297"
                            class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 outline-none">
                    </div>
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">Rate per Sheet (MK) *</label>
                    <input type="number" step="0.01" name="rate" id="paper_add_rate" required
                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 outline-none">
                </div>
                <div class="rounded-lg bg-gray-50 border border-gray-200 px-4 py-3">
                    <p class="text-xs font-semibold text-gray-500 uppercase mb-1">Catalog name preview</p>
                    <p id="paper_add_name_preview" class="text-sm text-gray-800 font-medium">—</p>
                </div>
                <div class="pt-2">
                    <button type="submit"
                        class="w-full bg-green-600 text-white font-bold py-3 rounded-lg hover:bg-green-700 transition shadow-lg flex items-center justify-center gap-2">
                        <i data-lucide="plus" class="h-5 w-5" aria-hidden="true"></i>
                        Save &amp; Use in Estimation
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Quick Add Binding Material Modal -->
<div id="bindingAddModal" class="fixed inset-0 bg-black bg-opacity-50 hidden flex items-center justify-center z-50">
    <div class="bg-white rounded-xl shadow-2xl p-8 w-full max-w-md mx-4">
        <div class="flex justify-between items-center mb-6">
            <div>
                <h3 class="text-2xl font-bold text-gray-800">Add to Catalog</h3>
                <p class="text-sm text-gray-500 mt-1">New binding material — saved and applied to the current row.</p>
            </div>
            <button type="button" onclick="closeBindingAddModal()" class="text-gray-400 hover:text-gray-600">
                <i data-lucide="x" class="h-6 w-6" aria-hidden="true"></i>
            </button>
        </div>
        <form id="bindingAddForm">
            <input type="hidden" name="action" value="quick_add">
            <input type="hidden" name="category_id" value="<?php echo $binding_cat_id; ?>">
            <input type="hidden" name="material_kind" value="binding">
            <div class="space-y-4">
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">Material Name *</label>
                    <input type="text" name="name" required placeholder="e.g. Green Book Cloth"
                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 outline-none">
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">Unit *</label>
                    <input type="text" name="unit" required placeholder="e.g. roll, metre, kg"
                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 outline-none">
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">Rate (MK) *</label>
                    <input type="number" step="0.01" name="rate" required
                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 outline-none">
                </div>
                <div class="pt-2">
                    <button type="submit"
                        class="w-full bg-green-600 text-white font-bold py-3 rounded-lg hover:bg-green-700 transition shadow-lg flex items-center justify-center gap-2">
                        <i data-lucide="plus" class="h-5 w-5" aria-hidden="true"></i>
                        Save &amp; Use in Estimation
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Quick Add Production Labour Modal -->
<?php
$prepress_labour_tasks = $prepress_labour_tasks ?? [];
$finishing_labour_tasks = $finishing_labour_tasks ?? [];
$press_labour_tasks = $press_labour_tasks ?? [];
?>
<div id="labourAddModal" class="fixed inset-0 bg-black bg-opacity-50 hidden flex items-center justify-center z-50">
    <div class="bg-white rounded-xl shadow-2xl p-8 w-full max-w-md">
        <div class="flex justify-between items-center mb-6">
            <h3 id="labourAddModalTitle" class="text-2xl font-bold text-gray-800">New Labour Task</h3>
            <button type="button" onclick="closeLabourAddModal()" class="text-gray-400 hover:text-gray-600">
                <i data-lucide="x" class="h-6 w-6" aria-hidden="true"></i>
            </button>
        </div>
        <form id="labourAddForm">
            <input type="hidden" name="action" value="quick_add">
            <input type="hidden" name="section" id="labour_add_section" value="prepress">
            <div class="space-y-4">
                <div>
                    <label id="labour_add_name_label" class="block text-sm font-semibold text-gray-700 mb-1">Task Name *</label>
                    <input type="text" name="name" required
                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 outline-none">
                </div>
                <div id="labour_add_measure_wrap" class="hidden">
                    <label class="block text-sm font-semibold text-gray-700 mb-1">Measure</label>
                    <select name="measure_type"
                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 outline-none">
                        <option value="items">Items</option>
                        <option value="books">Books</option>
                        <option value="reams">Reams</option>
                        <option value="numbers">Numbers</option>
                        <option value="perfs">Perfs</option>
                        <option value="others">Others</option>
                    </select>
                </div>
                <div id="labour_add_iph_wrap" class="hidden">
                    <label class="block text-sm font-semibold text-gray-700 mb-1">Default IPH</label>
                    <input type="number" name="default_iph" min="0"
                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 outline-none"
                        placeholder="Optional">
                </div>
                <div id="labour_add_rate_wrap">
                    <label id="labour_add_rate_label" class="block text-sm font-semibold text-gray-700 mb-1">Rate / hr (MK) *</label>
                    <input type="number" step="0.01" name="rate" required
                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 outline-none">
                </div>
                <div id="labour_add_press_rates_wrap" class="hidden space-y-4">
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">Make Ready Rate / hr (MK)</label>
                        <input type="number" step="0.01" name="make_ready_rate"
                            class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 outline-none">
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">Running Rate / hr (MK)</label>
                        <input type="number" step="0.01" name="running_rate"
                            class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 outline-none">
                    </div>
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

<!-- Pre-press custom row template -->
<template id="prepress-row-template">
    <tr class="prepress-row">
        <td class="px-3 py-2">
            <select name="prepress_task_id[]" class="w-full border-gray-300 rounded-lg prepress-task-select">
                <option value="">Select Task</option>
                <?php foreach ($prepress_labour_tasks as $task): ?>
                    <option value="<?php echo (int) $task['id']; ?>"
                        data-name="<?php echo htmlspecialchars($task['name']); ?>"
                        data-rate="<?php echo htmlspecialchars((string) ($task['rate'] ?? '')); ?>"
                        data-unit="<?php echo htmlspecialchars((string) ($task['unit'] ?? 'hrs')); ?>">
                        <?php echo htmlspecialchars($task['name']); ?>
                        (<?php echo htmlspecialchars((string) ($task['unit'] ?? 'hrs')); ?>)
                    </option>
                <?php endforeach; ?>
            </select>
            <input type="hidden" name="prepress_name[]" class="prepress-name" value="">
        </td>
        <td class="px-3 py-2">
            <input type="text" name="prepress_unit[]" readonly
                class="w-full border-gray-300 rounded-lg prepress-unit bg-gray-50" value="hrs">
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
            <select name="finishing_task_id[]" class="w-full border-gray-300 rounded-lg finishing-task-select">
                <option value="">Select Task</option>
                <?php foreach ($finishing_labour_tasks as $task): ?>
                    <option value="<?php echo (int) $task['id']; ?>"
                        data-name="<?php echo htmlspecialchars($task['name']); ?>"
                        data-rate="<?php echo htmlspecialchars((string) ($task['rate'] ?? '')); ?>"
                        data-measure="<?php echo htmlspecialchars((string) ($task['measure_type'] ?? 'items')); ?>"
                        data-iph="<?php echo htmlspecialchars((string) ($task['default_iph'] ?? '')); ?>">
                        <?php echo htmlspecialchars($task['name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <input type="hidden" name="finishing_name[]" class="finishing-name" value="">
        </td>
        <td class="px-3 py-2">
            <select name="finishing_measure[]" class="w-full border-gray-300 rounded-lg finishing-measure bg-gray-50">
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

<template id="press-task-options">
    <option value="">Select Machine</option>
    <?php foreach ($press_labour_tasks as $task): ?>
        <option value="<?php echo (int) $task['id']; ?>"
            data-name="<?php echo htmlspecialchars($task['name']); ?>"
            data-mr-rate="<?php echo htmlspecialchars((string) ($task['make_ready_rate'] ?? '')); ?>"
            data-run-rate="<?php echo htmlspecialchars((string) ($task['running_rate'] ?? '')); ?>">
            <?php echo htmlspecialchars($task['name']); ?>
        </option>
    <?php endforeach; ?>
</template>
