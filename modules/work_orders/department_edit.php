<?php
require_once __DIR__ . '/../../config/app.php';
checkAuth();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/work_order_helper.php';

if (!hasPermission('manage_production_queues') && !hasPermission('manage_work_orders')) {
    http_response_code(403);
    die('Access Denied.');
}

work_order_bootstrap($pdo);

$workOrderId = (int) ($_GET['id'] ?? 0);
$departmentSlug = trim((string) ($_GET['department'] ?? ''));
if ($workOrderId <= 0 || $departmentSlug === '') {
    redirect('modules/work_orders/workspace');
}

$workOrder = work_order_fetch_one($pdo, $workOrderId);
if (!$workOrder) {
    http_response_code(404);
    die('Work order not found.');
}

$section = work_order_department_form_section($departmentSlug);
if ($section === null) {
    $_SESSION['error'] = 'This department does not have an editable traveler section.';
    redirect('modules/work_orders/workspace?department=' . urlencode($departmentSlug));
}

$deptStmt = $pdo->prepare("SELECT * FROM production_departments WHERE slug = ? LIMIT 1");
$deptStmt->execute([$departmentSlug]);
$department = $deptStmt->fetch(PDO::FETCH_ASSOC);

$spec = work_order_fetch_specifications($pdo, $workOrderId);
$productionForm = work_order_decode_json_field($spec['production_form_json'] ?? null);
$composing = $productionForm['composing'] ?? [];
$letterpress = $productionForm['letterpress'] ?? [];
$bookbinding = $productionForm['bookbinding'] ?? [];
$dispatchReceived = $productionForm['dispatch_received'] ?? [];
$costingTracking = $productionForm['costing_tracking'] ?? [];
$paperMaterials = $productionForm['paper_materials'] ?? [];

function wo_dept_field(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

$sectionTitles = [
    'composing' => $departmentSlug === 'origination' ? 'Origination Record' : 'Composing / Photosetters',
    'letterpress' => 'Letterpress / Offset',
    'bookbinding' => 'Bookbinding',
    'dispatch' => 'Dispatch Office',
];

include '../../includes/header.php';
?>

<div class="mb-6">
    <a href="workspace?department=<?php echo urlencode($departmentSlug); ?>" class="wo-page-back">
        <i data-lucide="arrow-left" class="h-4 w-4" aria-hidden="true"></i>
        Back to <?php echo wo_dept_field($department['name'] ?? 'department'); ?> workspace
    </a>
</div>

<div class="mb-6">
    <h1 class="text-2xl font-bold text-gray-800"><?php echo wo_dept_field($sectionTitles[$section] ?? 'Department Section'); ?></h1>
    <p class="text-sm text-gray-500 mt-1">
        Work order <strong><?php echo wo_dept_field($workOrder['work_order_number']); ?></strong>
        for <?php echo wo_dept_field($workOrder['customer_name'] ?: 'customer'); ?>
    </p>
</div>

<?php if (!empty($_SESSION['success'])): ?>
    <div class="bg-green-50 border border-green-200 text-green-800 rounded-lg p-4 mb-4"><?php echo wo_dept_field($_SESSION['success']); unset($_SESSION['success']); ?></div>
<?php endif; ?>
<?php if (!empty($_SESSION['error'])): ?>
    <div class="bg-red-50 border border-red-200 text-red-800 rounded-lg p-4 mb-4"><?php echo wo_dept_field($_SESSION['error']); unset($_SESSION['error']); ?></div>
<?php endif; ?>

<form method="POST" action="department_save" class="bg-white shadow rounded-xl p-6 space-y-6">
    <input type="hidden" name="csrf_token" value="<?php echo wo_dept_field(csrf_token('work_order_department')); ?>">
    <input type="hidden" name="work_order_id" value="<?php echo $workOrderId; ?>">
    <input type="hidden" name="department" value="<?php echo wo_dept_field($departmentSlug); ?>">

    <?php if ($section === 'composing'): ?>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div><label class="block text-sm font-semibold mb-1">Compositor's name</label><input type="text" name="compositor_name" value="<?php echo wo_dept_field($composing['compositor_name'] ?? ''); ?>" class="w-full px-3 py-2 border rounded-lg"></div>
            <div><label class="block text-sm font-semibold mb-1">Date received</label><input type="date" name="composing_date_received" value="<?php echo wo_dept_field($composing['date_received'] ?? ''); ?>" class="w-full px-3 py-2 border rounded-lg"></div>
            <div><label class="block text-sm font-semibold mb-1">Type</label><input type="text" name="composing_type" value="<?php echo wo_dept_field($composing['type'] ?? ''); ?>" class="w-full px-3 py-2 border rounded-lg"></div>
            <div><label class="block text-sm font-semibold mb-1">Proof to / and date</label><input type="text" name="proof_to_date" value="<?php echo wo_dept_field($composing['proof_to_date'] ?? ''); ?>" class="w-full px-3 py-2 border rounded-lg"></div>
            <div><label class="block text-sm font-semibold mb-1">Type area (ems wide)</label><input type="text" name="type_area_wide_ems" value="<?php echo wo_dept_field($composing['type_area_wide_ems'] ?? ''); ?>" class="w-full px-3 py-2 border rounded-lg"></div>
            <div><label class="block text-sm font-semibold mb-1">Type area (ems deep)</label><input type="text" name="type_area_deep_ems" value="<?php echo wo_dept_field($composing['type_area_deep_ems'] ?? ''); ?>" class="w-full px-3 py-2 border rounded-lg"></div>
            <div class="md:col-span-2"><label class="block text-sm font-semibold mb-1">Special instructions</label><textarea name="composing_special_instructions" rows="3" class="w-full px-3 py-2 border rounded-lg resize-y"><?php echo wo_dept_field($composing['special_instructions'] ?? ''); ?></textarea></div>
        </div>
    <?php elseif ($section === 'letterpress'): ?>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div><label class="block text-sm font-semibold mb-1">Machine minder's name</label><input type="text" name="press_minder_name" value="<?php echo wo_dept_field($letterpress['machine_minder_name'] ?? ''); ?>" class="w-full px-3 py-2 border rounded-lg"></div>
            <div><label class="block text-sm font-semibold mb-1">Date received</label><input type="date" name="press_date_received" value="<?php echo wo_dept_field($letterpress['date_received'] ?? ''); ?>" class="w-full px-3 py-2 border rounded-lg"></div>
            <div><label class="block text-sm font-semibold mb-1">Type of machine</label><input type="text" name="press_machine_type" value="<?php echo wo_dept_field($letterpress['machine_type'] ?? ''); ?>" class="w-full px-3 py-2 border rounded-lg"></div>
            <div><label class="block text-sm font-semibold mb-1">Colour of ink</label><input type="text" name="press_ink_colour" value="<?php echo wo_dept_field($letterpress['ink_colour'] ?? ''); ?>" class="w-full px-3 py-2 border rounded-lg"></div>
            <div><label class="block text-sm font-semibold mb-1">No. of overs allowed</label><input type="text" name="press_overs_allowed" value="<?php echo wo_dept_field($letterpress['overs_allowed'] ?? ''); ?>" class="w-full px-3 py-2 border rounded-lg"></div>
            <div><label class="block text-sm font-semibold mb-1">Type of plates</label><input type="text" name="press_plate_type" value="<?php echo wo_dept_field($letterpress['plate_type'] ?? ''); ?>" class="w-full px-3 py-2 border rounded-lg"></div>
            <div><label class="block text-sm font-semibold mb-1">Camera %</label><input type="text" name="press_camera_percent" value="<?php echo wo_dept_field($letterpress['camera_percent'] ?? ''); ?>" class="w-full px-3 py-2 border rounded-lg"></div>
            <div><label class="block text-sm font-semibold mb-1">Process</label><input type="text" name="press_process" value="<?php echo wo_dept_field($letterpress['process'] ?? ''); ?>" class="w-full px-3 py-2 border rounded-lg"></div>
            <div><label class="block text-sm font-semibold mb-1">Size</label><input type="text" name="press_size" value="<?php echo wo_dept_field($letterpress['size'] ?? ''); ?>" class="w-full px-3 py-2 border rounded-lg"></div>
            <div class="md:col-span-2"><label class="block text-sm font-semibold mb-1">Special instructions</label><textarea name="press_special_instructions" rows="3" class="w-full px-3 py-2 border rounded-lg resize-y"><?php echo wo_dept_field($letterpress['special_instructions'] ?? ''); ?></textarea></div>
        </div>
    <?php elseif ($section === 'bookbinding'): ?>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div><label class="block text-sm font-semibold mb-1">Machine minder's name</label><input type="text" name="binding_minder_name" value="<?php echo wo_dept_field($bookbinding['machine_minder_name'] ?? ''); ?>" class="w-full px-3 py-2 border rounded-lg"></div>
            <div><label class="block text-sm font-semibold mb-1">Date received</label><input type="date" name="binding_date_received" value="<?php echo wo_dept_field($bookbinding['date_received'] ?? ''); ?>" class="w-full px-3 py-2 border rounded-lg"></div>
            <div><label class="block text-sm font-semibold mb-1">Ruling</label><input type="text" name="binding_ruling" value="<?php echo wo_dept_field($bookbinding['ruling'] ?? ''); ?>" class="w-full px-3 py-2 border rounded-lg"></div>
            <div><label class="block text-sm font-semibold mb-1">Perforating</label><input type="text" name="binding_perforating" value="<?php echo wo_dept_field($bookbinding['perforating'] ?? ''); ?>" class="w-full px-3 py-2 border rounded-lg"></div>
            <div><label class="block text-sm font-semibold mb-1">Trim — fore-edge</label><input type="text" name="bind_trim_fore_edge" value="<?php echo wo_dept_field($bookbinding['trim_fore_edge'] ?? ''); ?>" class="w-full px-3 py-2 border rounded-lg"></div>
            <div><label class="block text-sm font-semibold mb-1">Trim — back</label><input type="text" name="bind_trim_back" value="<?php echo wo_dept_field($bookbinding['trim_back'] ?? ''); ?>" class="w-full px-3 py-2 border rounded-lg"></div>
            <div><label class="block text-sm font-semibold mb-1">Trim — head</label><input type="text" name="bind_trim_head" value="<?php echo wo_dept_field($bookbinding['trim_head'] ?? ''); ?>" class="w-full px-3 py-2 border rounded-lg"></div>
            <div><label class="block text-sm font-semibold mb-1">Trim — tail</label><input type="text" name="bind_trim_tail" value="<?php echo wo_dept_field($bookbinding['trim_tail'] ?? ''); ?>" class="w-full px-3 py-2 border rounded-lg"></div>
            <div class="md:col-span-2"><label class="block text-sm font-semibold mb-1">Special instructions</label><textarea name="binding_special_instructions" rows="3" class="w-full px-3 py-2 border rounded-lg resize-y"><?php echo wo_dept_field($bookbinding['special_instructions'] ?? ''); ?></textarea></div>
        </div>
    <?php elseif ($section === 'dispatch'): ?>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div><label class="block text-sm font-semibold mb-1">Received qty</label><input type="text" name="dispatch_received_qty" value="<?php echo wo_dept_field($dispatchReceived['quantity'] ?? ''); ?>" class="w-full px-3 py-2 border rounded-lg"></div>
            <div><label class="block text-sm font-semibold mb-1">Received by (initials)</label><input type="text" name="dispatch_received_initials" value="<?php echo wo_dept_field($dispatchReceived['initials'] ?? ''); ?>" class="w-full px-3 py-2 border rounded-lg"></div>
            <div><label class="block text-sm font-semibold mb-1">Received date</label><input type="date" name="dispatch_received_date" value="<?php echo wo_dept_field($dispatchReceived['date'] ?? ''); ?>" class="w-full px-3 py-2 border rounded-lg"></div>
            <div><label class="block text-sm font-semibold mb-1">Passed to costing initials</label><input type="text" name="passed_to_costing_initials" value="<?php echo wo_dept_field($costingTracking['passed_to_costing_initials'] ?? ''); ?>" class="w-full px-3 py-2 border rounded-lg"></div>
            <div><label class="block text-sm font-semibold mb-1">Passed to costing date</label><input type="date" name="passed_to_costing_date" value="<?php echo wo_dept_field($costingTracking['passed_to_costing_date'] ?? ''); ?>" class="w-full px-3 py-2 border rounded-lg"></div>
            <div><label class="block text-sm font-semibold mb-1">Final dispatch initials</label><input type="text" name="final_dispatch_initials" value="<?php echo wo_dept_field($costingTracking['final_dispatch_initials'] ?? ''); ?>" class="w-full px-3 py-2 border rounded-lg"></div>
            <div><label class="block text-sm font-semibold mb-1">Final dispatch date</label><input type="date" name="final_dispatch_date" value="<?php echo wo_dept_field($costingTracking['final_dispatch_date'] ?? ''); ?>" class="w-full px-3 py-2 border rounded-lg"></div>
        </div>
    <?php endif; ?>

    <div class="border-t pt-6">
        <h3 class="font-semibold text-gray-800 mb-3">Paper &amp; Materials Used</h3>
        <div id="paper-rows" class="space-y-3">
            <?php if (empty($paperMaterials)): ?>
                <div class="paper-row grid grid-cols-1 md:grid-cols-6 gap-2">
                    <input type="text" name="paper_ledger_no[]" placeholder="Ledger no." class="px-2 py-2 border rounded">
                    <input type="text" name="paper_qty[]" placeholder="Qty" class="px-2 py-2 border rounded">
                    <input type="text" name="paper_cut_to[]" placeholder="Cut to" class="px-2 py-2 border rounded">
                    <input type="text" name="paper_riv_no[]" placeholder="R.I.V." class="px-2 py-2 border rounded">
                    <input type="date" name="paper_date[]" class="px-2 py-2 border rounded">
                    <input type="text" name="paper_notes[]" placeholder="Notes" class="px-2 py-2 border rounded">
                </div>
            <?php else: ?>
                <?php foreach ($paperMaterials as $row): ?>
                    <div class="paper-row grid grid-cols-1 md:grid-cols-6 gap-2">
                        <input type="text" name="paper_ledger_no[]" value="<?php echo wo_dept_field($row['ledger_no'] ?? ''); ?>" placeholder="Ledger no." class="px-2 py-2 border rounded">
                        <input type="text" name="paper_qty[]" value="<?php echo wo_dept_field($row['quantity'] ?? ''); ?>" placeholder="Qty" class="px-2 py-2 border rounded">
                        <input type="text" name="paper_cut_to[]" value="<?php echo wo_dept_field($row['cut_to'] ?? ''); ?>" placeholder="Cut to" class="px-2 py-2 border rounded">
                        <input type="text" name="paper_riv_no[]" value="<?php echo wo_dept_field($row['riv_no'] ?? ''); ?>" placeholder="R.I.V." class="px-2 py-2 border rounded">
                        <input type="date" name="paper_date[]" value="<?php echo wo_dept_field($row['date'] ?? ''); ?>" class="px-2 py-2 border rounded">
                        <input type="text" name="paper_notes[]" value="<?php echo wo_dept_field($row['notes'] ?? ''); ?>" placeholder="Notes" class="px-2 py-2 border rounded">
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        <button type="button" id="add-paper-row" class="mt-3 text-sm text-indigo-600 hover:underline">+ Add material row</button>
    </div>

    <div class="flex justify-end gap-3 pt-4 border-t">
        <a href="workspace?department=<?php echo urlencode($departmentSlug); ?>" class="px-5 py-2 rounded-lg border border-gray-300 text-gray-700 hover:bg-gray-50">Cancel</a>
        <button type="submit" class="px-5 py-2 rounded-lg bg-indigo-600 text-white hover:bg-indigo-700">Save section</button>
    </div>
</form>

<script>
document.getElementById('add-paper-row').addEventListener('click', function () {
    const container = document.getElementById('paper-rows');
    const row = document.createElement('div');
    row.className = 'paper-row grid grid-cols-1 md:grid-cols-6 gap-2';
    row.innerHTML = `
        <input type="text" name="paper_ledger_no[]" placeholder="Ledger no." class="px-2 py-2 border rounded">
        <input type="text" name="paper_qty[]" placeholder="Qty" class="px-2 py-2 border rounded">
        <input type="text" name="paper_cut_to[]" placeholder="Cut to" class="px-2 py-2 border rounded">
        <input type="text" name="paper_riv_no[]" placeholder="R.I.V." class="px-2 py-2 border rounded">
        <input type="date" name="paper_date[]" class="px-2 py-2 border rounded">
        <input type="text" name="paper_notes[]" placeholder="Notes" class="px-2 py-2 border rounded">
    `;
    container.appendChild(row);
});
</script>

<?php include '../../includes/footer.php'; ?>
