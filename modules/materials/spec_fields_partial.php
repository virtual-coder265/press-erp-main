<?php
/**
 * Shared spec fields for material create/edit forms.
 *
 * @var array<string, mixed> $material
 */
$material = $material ?? [];
?>
<div class="border-t border-gray-200 pt-6 mt-2 mb-6">
    <h3 class="text-lg font-bold text-gray-800 mb-4">Specification (for estimation matching)</h3>
    <p class="text-xs text-gray-500 mb-4">These fields help the estimation wizard find the correct catalog item by type, size, and colour.</p>
    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
        <div>
            <label class="block text-gray-700 font-semibold mb-2">Material Kind</label>
            <select name="material_kind" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500">
                <option value="">Auto / none</option>
                <?php foreach (MATERIAL_KINDS as $kind): ?>
                    <option value="<?php echo htmlspecialchars($kind); ?>" <?php echo (($material['material_kind'] ?? '') === $kind) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars(ucfirst($kind)); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="block text-gray-700 font-semibold mb-2">Stock Type</label>
            <input type="text" name="stock_type" value="<?php echo htmlspecialchars($material['stock_type'] ?? ''); ?>"
                placeholder="e.g. Manilla, Book Cloth, Process Inks"
                class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500">
        </div>
        <div>
            <label class="block text-gray-700 font-semibold mb-2">Grammage (gsm)</label>
            <input type="number" step="0.01" name="grammage" value="<?php echo htmlspecialchars($material['grammage'] ?? ''); ?>"
                class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500">
        </div>
        <div>
            <label class="block text-gray-700 font-semibold mb-2">Colour</label>
            <input type="text" name="color" value="<?php echo htmlspecialchars($material['color'] ?? ''); ?>"
                class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500">
        </div>
        <div>
            <label class="block text-gray-700 font-semibold mb-2">Dimensions</label>
            <input type="text" name="dimensions" value="<?php echo htmlspecialchars($material['dimensions'] ?? ''); ?>"
                placeholder="e.g. A4, 605x745mm"
                class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500">
        </div>
        <div>
            <label class="block text-gray-700 font-semibold mb-2">Thickness (mm)</label>
            <input type="number" step="0.01" name="thickness_mm" value="<?php echo htmlspecialchars($material['thickness_mm'] ?? ''); ?>"
                class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500">
        </div>
        <div>
            <label class="block text-gray-700 font-semibold mb-2">Brand</label>
            <input type="text" name="brand" value="<?php echo htmlspecialchars($material['brand'] ?? ''); ?>"
                placeholder="e.g. Xerox, Kyocera"
                class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500">
        </div>
    </div>
</div>
