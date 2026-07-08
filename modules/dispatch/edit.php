<?php
require_once __DIR__ . '/../../config/app.php';
checkAuth();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/permissions_helper.php';
permissions_require_one_of(['manage_dispatch']);
require_once __DIR__ . '/../../includes/work_order_helper.php';

$id = $_GET['id'] ?? 0;
work_order_bootstrap($pdo);

$stmt = $pdo->prepare("SELECT * FROM dispatch_register WHERE id = ?");
$stmt->execute([$id]);
$dispatch = $stmt->fetch();

if (!$dispatch) {
    redirect('modules/dispatch/list?error=entry_not_found');
}

// Get all users for authorised dispatcher selection
$users = $pdo->query("SELECT id, name, email FROM users ORDER BY name")->fetchAll();
$availableWorkOrders = work_order_safe_fetch(
    $pdo,
    "SELECT wo.id, wo.work_order_number, wo.customer_name, wo.status
     FROM work_orders wo
     WHERE wo.status IN ('Awaiting Dispatch', 'Dispatched', 'Completed')
        OR wo.id = ?
     ORDER BY wo.work_order_number DESC",
    [(int) ($dispatch['work_order_id'] ?? 0)]
);
$remark_templates = [
    'Work Order complete' => 'Work Order complete',
    'Held' => 'Held',
    'Need attention' => 'Need attention'
];
$current_remarks = trim($dispatch['remarks'] ?? '');
$selected_template = '__custom';
foreach ($remark_templates as $template_label => $template_value) {
    if (strcasecmp($current_remarks, $template_value) === 0) {
        $selected_template = $template_value;
        break;
    }
}

include '../../includes/header.php';
?>

<div class="mb-6">
    <a href="list" class="text-green-600 hover:underline flex items-center mb-4">
        <i class="material-icons text-sm mr-1">arrow_back</i> Back to Dispatch Register
    </a>
    <h1 class="text-3xl font-bold text-gray-800">Edit Dispatch Entry</h1>
    <p class="text-gray-600">Update dispatch entry information.</p>
</div>

<div class="bg-white shadow rounded-lg p-8 max-w-4xl">
    <form method="POST" action="save">
        <input type="hidden" name="action" value="update">
        <input type="hidden" name="id" value="<?php echo $dispatch['id']; ?>">
        
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <div>
                <label class="block text-gray-700 font-bold mb-2">Linked Work Order</label>
                <select name="work_order_id" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500">
                    <option value="">Select completed work order</option>
                    <?php foreach ($availableWorkOrders as $workOrder): ?>
                        <option value="<?php echo (int) $workOrder['id']; ?>" <?php echo (int) ($dispatch['work_order_id'] ?? 0) === (int) $workOrder['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($workOrder['work_order_number'] . ' - ' . ($workOrder['customer_name'] ?: 'Customer')); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label class="block text-gray-700 font-bold mb-2">Work Order Number</label>
                <input type="text" name="work_order_number" 
                       value="<?php echo htmlspecialchars($dispatch['work_order_number'] ?? ''); ?>"
                       class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500"
                       placeholder="Enter work order number">
                <p class="text-xs text-gray-500 mt-1">Leave blank when linked to a work order. It will be filled automatically.</p>
            </div>
            
            <div>
                <label class="block text-gray-700 font-bold mb-2">Date In *</label>
                <?php echo press_datetime_picker_field([
                    'name' => 'date_in',
                    'value' => $dispatch['date_in'] ? date('Y-m-d', strtotime($dispatch['date_in'])) : date('Y-m-d'),
                    'mode' => 'date',
                    'required' => true,
                    'class' => 'w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500',
                ]); ?>
            </div>
            
            <div class="md:col-span-2">
                <label class="block text-gray-700 font-bold mb-2">Ministry or Department *</label>
                <input type="text" name="ministry_department"
                       value="<?php echo htmlspecialchars($dispatch['ministry_department']); ?>"
                       class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500"
                       placeholder="Enter ministry or department name">
            </div>
            
            <div class="md:col-span-2">
                <label class="block text-gray-700 font-bold mb-2">Description of Job</label>
                <textarea name="job_description" rows="3" 
                          class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500"
                          placeholder="Describe the job or work being dispatched"><?php echo htmlspecialchars($dispatch['job_description'] ?? ''); ?></textarea>
            </div>

            <div class="md:col-span-2">
                <label class="block text-gray-700 font-bold mb-2">Remarks Template</label>
                <select id="remarks_template" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500">
                    <?php foreach ($remark_templates as $template_label => $template_value): ?>
                        <option value="<?php echo htmlspecialchars($template_value); ?>" <?php echo $selected_template === $template_value ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($template_label); ?>
                        </option>
                    <?php endforeach; ?>
                    <option value="__custom" <?php echo $selected_template === '__custom' ? 'selected' : ''; ?>>Custom</option>
                </select>
            </div>

            <div class="md:col-span-2">
                <label class="block text-gray-700 font-bold mb-2">Remarks</label>
                <textarea name="remarks" id="remarks_input" rows="2" 
                          class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500"
                          placeholder="Add remarks..."><?php echo htmlspecialchars($current_remarks); ?></textarea>
                <p class="text-xs text-gray-500 mt-1">Select a template or choose Custom to enter your own remark.</p>
            </div>
            
            <div>
                <label class="block text-gray-700 font-bold mb-2">Quantity</label>
                <input type="number" name="quantity" min="0" 
                       value="<?php echo $dispatch['quantity']; ?>"
                       class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500"
                       placeholder="Enter quantity">
            </div>
            
            <div>
                <label class="block text-gray-700 font-bold mb-2">Date Out</label>
                <?php echo press_datetime_picker_field([
                    'name' => 'date_out',
                    'value' => $dispatch['date_out'] ? date('Y-m-d', strtotime($dispatch['date_out'])) : '',
                    'mode' => 'date',
                    'class' => 'w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500',
                ]); ?>
                <p class="text-xs text-gray-500 mt-1">Leave blank if not yet dispatched</p>
            </div>
            
            <div>
                <label class="block text-gray-700 font-bold mb-2">Delivery Note Number</label>
                <input type="text" name="delivery_note_number" 
                       value="<?php echo htmlspecialchars($dispatch['delivery_note_number'] ?? ''); ?>"
                       class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500"
                       placeholder="Enter delivery note number">
            </div>
            
            <div>
                <label class="block text-gray-700 font-bold mb-2">Authorised Dispatcher</label>
                <select name="authorised_dispatcher_id" 
                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500">
                    <option value="">Select Authorised Dispatcher</option>
                    <?php foreach($users as $user): ?>
                        <option value="<?php echo $user['id']; ?>" <?php echo $dispatch['authorised_dispatcher_id'] == $user['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($user['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        
        <div class="mt-8 flex gap-4">
            <button type="submit" class="bg-green-600 text-white px-6 py-2 rounded font-bold hover:bg-green-700 transition">
                <i class="material-icons align-middle mr-2">save</i> Update Entry
            </button>
            <a href="list" class="bg-gray-300 text-gray-700 px-6 py-2 rounded font-bold hover:bg-gray-400 transition">
                Cancel
            </a>
        </div>
    </form>
</div>

<?php include '../../includes/footer.php'; ?>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const templateSelect = document.getElementById('remarks_template');
    const remarksInput = document.getElementById('remarks_input');
    const templateValues = <?php echo json_encode($remark_templates); ?>;

    templateSelect.addEventListener('change', function() {
        const value = this.value;
        if (value === '__custom') {
            remarksInput.value = '';
            remarksInput.focus();
        } else if (templateValues[value] !== undefined) {
            remarksInput.value = templateValues[value];
        } else {
            remarksInput.value = value;
        }
    });
});
</script>

