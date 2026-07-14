<?php
require_once __DIR__ . '/../../config/app.php';
checkAuth();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/work_order_helper.php';

if (!hasPermission('manage_work_orders')) {
    http_response_code(403);
    die('Access Denied.');
}

work_order_bootstrap($pdo);

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    redirect('modules/work_orders/list');
}

$workOrder = work_order_fetch_one($pdo, $id);
if (!$workOrder) {
    http_response_code(404);
    die('Work order not found.');
}

$spec = work_order_fetch_specifications($pdo, $id);
$productionForm = work_order_decode_json_field($spec['production_form_json'] ?? null);
$formeDressing = work_order_decode_json_field($workOrder['forme_dressing_json'] ?? null);
$trimMargins = work_order_decode_json_field($workOrder['trim_margins_json'] ?? null);
$bindingTypes = work_order_fetch_binding_types($pdo);
$previousOrders = work_order_safe_fetch($pdo, "SELECT work_order_number FROM work_orders WHERE id != ? ORDER BY created_at DESC LIMIT 100", [$id]);

$composing = $productionForm['composing'] ?? [];
$letterpress = $productionForm['letterpress'] ?? [];
$bookbinding = $productionForm['bookbinding'] ?? [];
$paperMaterials = $productionForm['paper_materials'] ?? [];

function wo_field(string $name, $value = ''): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

include '../../includes/header.php';
?>

<div class="mb-6">
    <a href="view?id=<?php echo $id; ?>" class="wo-page-back">
        <i data-lucide="arrow-left" class="h-4 w-4" aria-hidden="true"></i>
        Back to work order
    </a>
</div>

<div class="mb-6">
    <h1 class="text-3xl font-bold text-gray-800">Edit Traveler — <?php echo wo_field($workOrder['work_order_number']); ?></h1>
    <p class="text-sm text-gray-500 mt-1">Update costing specs and department handoff notes.</p>
</div>

<?php if (!empty($_SESSION['error'])): ?>
    <div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg mb-6"><?php echo wo_field($_SESSION['error']); unset($_SESSION['error']); ?></div>
<?php endif; ?>

<form method="POST" action="save" class="space-y-6">
    <input type="hidden" name="csrf_token" value="<?php echo wo_field(csrf_token('work_order_costing')); ?>">
    <input type="hidden" name="work_order_id" value="<?php echo $id; ?>">
    <input type="hidden" name="binding_type_name" id="binding_type_name" value="<?php echo wo_field($workOrder['binding_type_name'] ?? ''); ?>">

    <div class="bg-white shadow rounded-xl p-6">
        <h2 class="text-xl font-bold text-gray-800 mb-4">Job &amp; Production Specs</h2>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div><label class="block text-sm font-semibold mb-1">Ministry / Department</label><input type="text" name="ministry_department" value="<?php echo wo_field($workOrder['ministry_department'] ?? ''); ?>" class="w-full px-3 py-2 border rounded-lg"></div>
            <div><label class="block text-sm font-semibold mb-1">Order Ref / LPO</label><input type="text" name="order_ref_lpo" value="<?php echo wo_field($workOrder['order_ref_lpo'] ?? ''); ?>" class="w-full px-3 py-2 border rounded-lg"></div>
            <div><label class="block text-sm font-semibold mb-1">Quantity</label><input type="number" name="quantity" value="<?php echo wo_field($workOrder['quantity'] ?? ''); ?>" class="w-full px-3 py-2 border rounded-lg"></div>
            <div><label class="block text-sm font-semibold mb-1">Pages</label><input type="number" name="pages_count" value="<?php echo wo_field($workOrder['pages_count'] ?? ''); ?>" class="w-full px-3 py-2 border rounded-lg"></div>
            <div><label class="block text-sm font-semibold mb-1">Size deep</label><input type="text" name="size_deep" value="<?php echo wo_field($workOrder['size_deep'] ?? ''); ?>" class="w-full px-3 py-2 border rounded-lg"></div>
            <div><label class="block text-sm font-semibold mb-1">Size wide</label><input type="text" name="size_wide" value="<?php echo wo_field($workOrder['size_wide'] ?? ''); ?>" class="w-full px-3 py-2 border rounded-lg"></div>
            <div><label class="block text-sm font-semibold mb-1">Numbering (optional)</label><input type="text" name="numbering_start" value="<?php echo wo_field($workOrder['numbering_start'] ?? ''); ?>" class="w-full px-3 py-2 border rounded-lg"></div>
            <div>
                <label class="block text-sm font-semibold mb-1">Type of Binding *</label>
                <select name="binding_type_id" id="binding_type_id" required class="w-full px-3 py-2 border rounded-lg">
                    <option value="">Select binding type</option>
                    <?php foreach ($bindingTypes as $type): ?>
                        <option value="<?php echo (int) $type['id']; ?>" <?php echo (int) ($workOrder['binding_type_id'] ?? 0) === (int) $type['id'] ? 'selected' : ''; ?>><?php echo wo_field($type['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div><label class="block text-sm font-semibold mb-1">Previous WO No.</label><input type="text" name="previous_work_order_number" list="previous_work_orders" value="<?php echo wo_field($workOrder['previous_work_order_number'] ?? ''); ?>" class="w-full px-3 py-2 border rounded-lg"><datalist id="previous_work_orders"><?php foreach ($previousOrders as $o): ?><option value="<?php echo wo_field($o['work_order_number']); ?>"><?php endforeach; ?></datalist></div>
            <div><label class="block text-sm font-semibold mb-1">Charge vote</label><input type="text" name="charge_vote" value="<?php echo wo_field($workOrder['charge_vote'] ?? ''); ?>" class="w-full px-3 py-2 border rounded-lg"></div>
            <div class="md:col-span-2"><label class="block text-sm font-semibold mb-1">Description of work</label><textarea name="job_description" rows="3" class="w-full px-3 py-2 border rounded-lg resize-y"><?php echo wo_field($workOrder['job_description'] ?? ''); ?></textarea></div>
            <div class="md:col-span-2"><label class="block text-sm font-semibold mb-1">Special instructions</label><textarea name="special_instructions" rows="2" class="w-full px-3 py-2 border rounded-lg resize-y"><?php echo wo_field($workOrder['special_instructions'] ?? ''); ?></textarea></div>
            <div class="md:col-span-2"><label class="block text-sm font-semibold mb-1">Delivery instructions</label><textarea name="delivery_instructions" rows="2" class="w-full px-3 py-2 border rounded-lg resize-y"><?php echo wo_field($workOrder['delivery_instructions'] ?? ''); ?></textarea></div>
        </div>
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mt-6">
            <div class="border rounded-lg p-4"><h3 class="font-semibold mb-3">Forme Dressing</h3><div class="grid grid-cols-2 gap-3"><?php foreach (['backs','heads','gutters','tails'] as $k): ?><div><label class="text-xs text-gray-500"><?php echo ucfirst($k); ?></label><input type="text" name="forme_<?php echo $k; ?>" value="<?php echo wo_field($formeDressing[$k] ?? ''); ?>" class="w-full px-2 py-1 border rounded"></div><?php endforeach; ?></div></div>
            <div class="border rounded-lg p-4"><h3 class="font-semibold mb-3">Trim Margins</h3><div class="grid grid-cols-2 gap-3"><?php foreach (['backs'=>'Backs','heads'=>'Heads','fore_edge'=>'Fore-edge','tails'=>'Tails'] as $k=>$l): ?><div><label class="text-xs text-gray-500"><?php echo $l; ?></label><input type="text" name="trim_<?php echo $k; ?>" value="<?php echo wo_field($trimMargins[$k] ?? ''); ?>" class="w-full px-2 py-1 border rounded"></div><?php endforeach; ?></div></div>
        </div>
    </div>

    <div class="bg-white shadow rounded-xl p-6">
        <h2 class="text-xl font-bold text-gray-800 mb-4">Composing / Photosetters</h2>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div><label class="text-sm text-gray-600">Compositor</label><input type="text" name="compositor_name" value="<?php echo wo_field($composing['compositor_name'] ?? ''); ?>" class="w-full px-3 py-2 border rounded-lg"></div>
            <div><label class="text-sm text-gray-600">Date received</label><input type="date" name="composing_date_received" value="<?php echo wo_field($composing['date_received'] ?? ''); ?>" class="w-full px-3 py-2 border rounded-lg"></div>
            <div><label class="text-sm text-gray-600">Type</label><input type="text" name="composing_type" value="<?php echo wo_field($composing['type'] ?? ''); ?>" class="w-full px-3 py-2 border rounded-lg"></div>
            <div><label class="text-sm text-gray-600">Proof to / date</label><input type="text" name="proof_to_date" value="<?php echo wo_field($composing['proof_to_date'] ?? ''); ?>" class="w-full px-3 py-2 border rounded-lg"></div>
            <div><label class="text-sm text-gray-600">Ems wide</label><input type="text" name="type_area_wide_ems" value="<?php echo wo_field($composing['type_area_wide_ems'] ?? ''); ?>" class="w-full px-3 py-2 border rounded-lg"></div>
            <div><label class="text-sm text-gray-600">Ems deep</label><input type="text" name="type_area_deep_ems" value="<?php echo wo_field($composing['type_area_deep_ems'] ?? ''); ?>" class="w-full px-3 py-2 border rounded-lg"></div>
            <div class="md:col-span-2"><label class="text-sm text-gray-600">Special instructions</label><textarea name="composing_special_instructions" rows="2" class="w-full px-3 py-2 border rounded-lg resize-y"><?php echo wo_field($composing['special_instructions'] ?? ''); ?></textarea></div>
        </div>
    </div>

    <div class="bg-white shadow rounded-xl p-6">
        <h2 class="text-xl font-bold text-gray-800 mb-4">Letterpress / Offset</h2>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <?php
            $pressFields = [
                'press_minder_name' => ['machine_minder_name', 'Machine minder'],
                'press_date_received' => ['date_received', 'Date received', 'date'],
                'press_machine_type' => ['machine_type', 'Machine type'],
                'press_ink_colour' => ['ink_colour', 'Ink colour'],
                'press_overs_allowed' => ['overs_allowed', 'Overs allowed'],
                'press_plate_type' => ['plate_type', 'Plate type'],
                'press_camera_percent' => ['camera_percent', 'Camera %'],
                'press_process' => ['process', 'Process'],
                'press_size' => ['size', 'Size'],
            ];
            foreach ($pressFields as $inputName => $meta):
                $key = $meta[0]; $label = $meta[1]; $type = $meta[2] ?? 'text';
            ?>
                <div><label class="text-sm text-gray-600"><?php echo $label; ?></label><input type="<?php echo $type; ?>" name="<?php echo $inputName; ?>" value="<?php echo wo_field($letterpress[$key] ?? ''); ?>" class="w-full px-3 py-2 border rounded-lg"></div>
            <?php endforeach; ?>
            <div class="md:col-span-2"><label class="text-sm text-gray-600">Special instructions</label><textarea name="press_special_instructions" rows="2" class="w-full px-3 py-2 border rounded-lg resize-y"><?php echo wo_field($letterpress['special_instructions'] ?? ''); ?></textarea></div>
        </div>
    </div>

    <div class="bg-white shadow rounded-xl p-6">
        <h2 class="text-xl font-bold text-gray-800 mb-4">Bookbinding</h2>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div><label class="text-sm text-gray-600">Machine minder</label><input type="text" name="binding_minder_name" value="<?php echo wo_field($bookbinding['machine_minder_name'] ?? ''); ?>" class="w-full px-3 py-2 border rounded-lg"></div>
            <div><label class="text-sm text-gray-600">Date received</label><input type="date" name="binding_date_received" value="<?php echo wo_field($bookbinding['date_received'] ?? ''); ?>" class="w-full px-3 py-2 border rounded-lg"></div>
            <div><label class="text-sm text-gray-600">Ruling</label><input type="text" name="binding_ruling" value="<?php echo wo_field($bookbinding['ruling'] ?? ''); ?>" class="w-full px-3 py-2 border rounded-lg"></div>
            <div><label class="text-sm text-gray-600">Perforating</label><input type="text" name="binding_perforating" value="<?php echo wo_field($bookbinding['perforating'] ?? ''); ?>" class="w-full px-3 py-2 border rounded-lg"></div>
            <div><label class="text-sm text-gray-600">Trim fore-edge</label><input type="text" name="bind_trim_fore_edge" value="<?php echo wo_field($bookbinding['trim_fore_edge'] ?? ''); ?>" class="w-full px-3 py-2 border rounded-lg"></div>
            <div><label class="text-sm text-gray-600">Trim back</label><input type="text" name="bind_trim_back" value="<?php echo wo_field($bookbinding['trim_back'] ?? ''); ?>" class="w-full px-3 py-2 border rounded-lg"></div>
            <div><label class="text-sm text-gray-600">Trim head</label><input type="text" name="bind_trim_head" value="<?php echo wo_field($bookbinding['trim_head'] ?? ''); ?>" class="w-full px-3 py-2 border rounded-lg"></div>
            <div><label class="text-sm text-gray-600">Trim tail</label><input type="text" name="bind_trim_tail" value="<?php echo wo_field($bookbinding['trim_tail'] ?? ''); ?>" class="w-full px-3 py-2 border rounded-lg"></div>
            <div class="md:col-span-2"><label class="text-sm text-gray-600">Special instructions</label><textarea name="binding_special_instructions" rows="2" class="w-full px-3 py-2 border rounded-lg resize-y"><?php echo wo_field($bookbinding['special_instructions'] ?? ''); ?></textarea></div>
        </div>
    </div>

    <div class="flex justify-end gap-3">
        <a href="view?id=<?php echo $id; ?>" class="px-5 py-2 rounded-lg border border-gray-300 text-gray-700 hover:bg-gray-50">Cancel</a>
        <button type="submit" class="px-5 py-2 rounded-lg bg-indigo-600 text-white hover:bg-indigo-700">Save Changes</button>
    </div>
</form>

<script>
document.getElementById('binding_type_id').addEventListener('change', function () {
    const selected = this.options[this.selectedIndex];
    document.getElementById('binding_type_name').value = selected.value ? selected.text.trim() : '';
});
</script>
<?php include '../../includes/footer.php'; ?>
