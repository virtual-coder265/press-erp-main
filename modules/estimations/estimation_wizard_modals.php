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
