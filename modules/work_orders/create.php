<?php
require_once __DIR__ . '/../../config/app.php';
checkAuth();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/work_order_helper.php';

if (!hasPermission('manage_work_orders') && !hasPermission('manage_invoices')) {
    http_response_code(403);
    die('Access Denied.');
}

work_order_bootstrap($pdo);

$invoiceId = (int) ($_GET['invoice_id'] ?? 0);
if ($invoiceId <= 0) {
    $_SESSION['error'] = 'Select an invoice to issue a work order from costing.';
    redirect('modules/invoices/list');
}

$existingStmt = $pdo->prepare("SELECT id, work_order_number FROM work_orders WHERE invoice_id = ? LIMIT 1");
$existingStmt->execute([$invoiceId]);
$existing = $existingStmt->fetch(PDO::FETCH_ASSOC);
if ($existing) {
    $_SESSION['error'] = 'A work order already exists for this invoice: ' . $existing['work_order_number'];
    redirect('modules/work_orders/view?id=' . (int) $existing['id']);
}

try {
    $prefill = work_order_prefill_from_invoice($pdo, $invoiceId);
} catch (Throwable $exception) {
    $_SESSION['error'] = $exception->getMessage();
    redirect('modules/invoices/view?id=' . $invoiceId);
}

$invoice = $prefill['invoice'];
$estimation = $prefill['estimation'];
$bindingTypes = work_order_fetch_binding_types($pdo);
$previousOrders = work_order_safe_fetch(
    $pdo,
    "SELECT work_order_number, customer_name, job_description
     FROM work_orders
     ORDER BY created_at DESC
     LIMIT 100"
);
$currentUser = $_SESSION['user_name'] ?? 'Current user';

include '../../includes/header.php';
?>

<style>
    input[type="number"]::-webkit-outer-spin-button,
    input[type="number"]::-webkit-inner-spin-button { -webkit-appearance: none; margin: 0; }
    input[type="number"] { -moz-appearance: textfield; }
</style>

<div class="mb-6">
    <a href="<?php echo BASE_URL; ?>modules/invoices/view?id=<?php echo $invoiceId; ?>" class="text-indigo-600 hover:underline inline-flex items-center text-sm">
        <i data-lucide="arrow-left" class="mr-1 inline-block h-4 w-4" aria-hidden="true"></i>
        Back to invoice
    </a>
</div>

<div class="mb-6">
    <h1 class="text-3xl font-bold text-gray-800">Create Work Order</h1>
    <p class="text-sm text-gray-500 mt-1">
        Complete the costing traveler for invoice <strong><?php echo htmlspecialchars($invoice['invoice_number']); ?></strong>
        <?php if (!empty($estimation['estimation_number'])): ?>
            linked to estimation <strong><?php echo htmlspecialchars($estimation['estimation_number']); ?></strong>
        <?php endif; ?>
    </p>
</div>

<?php if (!empty($_SESSION['error'])): ?>
    <div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg mb-6"><?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?></div>
<?php endif; ?>

<div class="bg-white shadow-md rounded-xl p-6 mb-8">
    <div class="flex items-center justify-between">
        <?php
        $steps = ['Job Details', 'Production Specs', 'Optional Notes', 'Save'];
        foreach ($steps as $index => $label):
            $stepNum = $index + 1;
        ?>
            <div class="flex items-center flex-1">
                <div class="flex flex-col items-center">
                    <div id="step-circle-<?php echo $stepNum; ?>" class="w-10 h-10 rounded-full flex items-center justify-center bg-gray-300 text-gray-600 font-bold transition-colors"><?php echo $stepNum; ?></div>
                    <span id="step-label-<?php echo $stepNum; ?>" class="text-xs mt-2 text-gray-500 font-semibold text-center"><?php echo htmlspecialchars($label); ?></span>
                </div>
                <?php if ($stepNum < count($steps)): ?>
                    <div id="step-line-<?php echo $stepNum; ?>" class="flex-1 h-1 mx-2 bg-gray-300 transition-colors"></div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<div class="bg-white shadow-md rounded-xl p-8">
    <form id="workOrderForm" method="POST" action="save" novalidate>
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token('work_order_costing')); ?>">
        <input type="hidden" name="invoice_id" value="<?php echo $invoiceId; ?>">
        <input type="hidden" name="binding_type_name" id="binding_type_name" value="">

        <!-- Step 1 -->
        <div id="step-1" class="step-content">
            <h2 class="text-2xl font-bold text-gray-800 mb-2">Job &amp; Invoice Context</h2>
            <p class="text-sm text-gray-500 mb-6">Confirm job details pulled from the estimation and invoice. Edit anything that changed during costing.</p>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
                <div class="bg-slate-50 rounded-lg p-4">
                    <div class="text-xs uppercase text-gray-500">Customer</div>
                    <div class="font-semibold text-gray-900"><?php echo htmlspecialchars($invoice['customer_name'] ?: '—'); ?></div>
                </div>
                <div class="bg-slate-50 rounded-lg p-4">
                    <div class="text-xs uppercase text-gray-500">Invoice total</div>
                    <div class="font-semibold text-gray-900">MK <?php echo number_format($prefill['total_cost'], 2); ?></div>
                </div>
                <div class="bg-slate-50 rounded-lg p-4">
                    <div class="text-xs uppercase text-gray-500">Outstanding balance</div>
                    <div class="font-semibold <?php echo $prefill['balance'] > 0 ? 'text-amber-700' : 'text-green-700'; ?>">
                        MK <?php echo number_format($prefill['balance'], 2); ?>
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                    <label class="block text-gray-700 font-semibold mb-2">Ministry / Department</label>
                    <input type="text" name="ministry_department" value="<?php echo htmlspecialchars($prefill['ministry_department']); ?>"
                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-indigo-500">
                </div>
                <div>
                    <label class="block text-gray-700 font-semibold mb-2">Order Ref / L.P.O No.</label>
                    <input type="text" name="order_ref_lpo" value="<?php echo htmlspecialchars($prefill['order_ref_lpo']); ?>"
                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-indigo-500">
                </div>
                <div>
                    <label class="block text-gray-700 font-semibold mb-2">Quantity</label>
                    <input type="number" name="quantity" min="0" value="<?php echo htmlspecialchars((string) ($prefill['quantity'] ?? '')); ?>"
                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-indigo-500">
                </div>
                <div>
                    <label class="block text-gray-700 font-semibold mb-2">No. of Pages</label>
                    <input type="number" name="pages_count" min="0" value="<?php echo htmlspecialchars((string) ($prefill['pages_count'] ?? '')); ?>"
                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-indigo-500">
                </div>
                <div>
                    <label class="block text-gray-700 font-semibold mb-2">Size deep (mm/in)</label>
                    <input type="text" name="size_deep" value="<?php echo htmlspecialchars((string) ($prefill['size_deep'] ?? '')); ?>"
                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-indigo-500">
                </div>
                <div>
                    <label class="block text-gray-700 font-semibold mb-2">Size wide (mm/in)</label>
                    <input type="text" name="size_wide" value="<?php echo htmlspecialchars((string) ($prefill['size_wide'] ?? '')); ?>"
                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-indigo-500">
                </div>
                <div class="md:col-span-2">
                    <label class="block text-gray-700 font-semibold mb-2">Description of Work</label>
                    <textarea name="job_description" rows="4"
                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-indigo-500 resize-y"><?php echo htmlspecialchars($prefill['job_description']); ?></textarea>
                </div>
            </div>
        </div>

        <!-- Step 2 -->
        <div id="step-2" class="step-content hidden">
            <h2 class="text-2xl font-bold text-gray-800 mb-2">Production Specifications</h2>
            <p class="text-sm text-gray-500 mb-6">Required costing fields for production. Numbering and margins are optional unless the job needs them.</p>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-8">
                <div>
                    <label class="block text-gray-700 font-semibold mb-2">Numbering start sequence <span class="text-gray-400 font-normal">(optional)</span></label>
                    <input type="text" name="numbering_start" placeholder="e.g. 1001"
                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-indigo-500">
                </div>
                <div>
                    <label class="block text-gray-700 font-semibold mb-2">Type of Binding *</label>
                    <div class="flex gap-2">
                        <select name="binding_type_id" id="binding_type_id" required
                            class="flex-1 px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-indigo-500">
                            <option value="">Select binding type</option>
                            <?php foreach ($bindingTypes as $type): ?>
                                <option value="<?php echo (int) $type['id']; ?>"><?php echo htmlspecialchars($type['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="button" id="btn-add-binding-type" class="px-3 py-2 bg-slate-100 text-slate-700 rounded-lg hover:bg-slate-200 text-sm whitespace-nowrap">Add new</button>
                    </div>
                </div>
                <div>
                    <label class="block text-gray-700 font-semibold mb-2">Previous Work Order No. <span class="text-gray-400 font-normal">(continuing jobs)</span></label>
                    <input type="text" name="previous_work_order_number" list="previous_work_orders"
                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-indigo-500">
                    <datalist id="previous_work_orders">
                        <?php foreach ($previousOrders as $order): ?>
                            <option value="<?php echo htmlspecialchars($order['work_order_number']); ?>">
                                <?php echo htmlspecialchars($order['customer_name'] . ' — ' . substr((string) $order['job_description'], 0, 40)); ?>
                            </option>
                        <?php endforeach; ?>
                    </datalist>
                </div>
                <div>
                    <label class="block text-gray-700 font-semibold mb-2">Charge Vote</label>
                    <input type="text" name="charge_vote"
                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-indigo-500">
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
                <div class="border border-gray-200 rounded-xl p-5">
                    <h3 class="font-bold text-gray-800 mb-4">Forme Dressing</h3>
                    <div class="grid grid-cols-2 gap-4">
                        <?php foreach (['backs' => 'Backs', 'heads' => 'Heads', 'gutters' => 'Gutters', 'tails' => 'Tails'] as $key => $label): ?>
                            <div>
                                <label class="block text-sm text-gray-600 mb-1"><?php echo $label; ?></label>
                                <input type="text" name="forme_<?php echo $key; ?>"
                                    class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-indigo-500">
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="border border-gray-200 rounded-xl p-5">
                    <h3 class="font-bold text-gray-800 mb-4">Trimmed Size Margins</h3>
                    <div class="grid grid-cols-2 gap-4">
                        <?php foreach (['backs' => 'Backs', 'heads' => 'Heads', 'fore_edge' => 'Fore-edge', 'tails' => 'Tails'] as $key => $label): ?>
                            <div>
                                <label class="block text-sm text-gray-600 mb-1"><?php echo $label; ?></label>
                                <input type="text" name="trim_<?php echo $key; ?>"
                                    class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-indigo-500">
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <div>
                <label class="block text-gray-700 font-semibold mb-2">Special Instructions (General)</label>
                <textarea name="special_instructions" rows="3" placeholder="e.g. NOTE: Specimen enclosed"
                    class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-indigo-500 resize-y"></textarea>
            </div>
        </div>

        <!-- Step 3 -->
        <div id="step-3" class="step-content hidden">
            <h2 class="text-2xl font-bold text-gray-800 mb-2">Department Sections</h2>
            <p class="text-sm text-gray-500 mb-6">Optional production notes for departments. Sections can complete these later during production.</p>

            <?php
            $deptSections = [
                'composing' => ['title' => 'Composing / Photosetters', 'target' => '#dept-composing'],
                'letterpress' => ['title' => 'Letterpress / Offset', 'target' => '#dept-letterpress'],
                'bookbinding' => ['title' => 'Bookbinding', 'target' => '#dept-bookbinding'],
                'materials' => ['title' => 'Paper & Materials', 'target' => '#dept-materials'],
            ];
            foreach ($deptSections as $key => $section):
            ?>
                <div class="border border-gray-200 rounded-xl mb-4 overflow-hidden">
                    <button type="button" class="dept-toggle w-full flex items-center justify-between px-5 py-4 bg-gray-50 hover:bg-gray-100 text-left" data-target="<?php echo $section['target']; ?>" aria-expanded="false">
                        <span class="font-semibold text-gray-800"><?php echo htmlspecialchars($section['title']); ?></span>
                        <i data-lucide="chevron-down" class="h-5 w-5 text-gray-500"></i>
                    </button>
                </div>
            <?php endforeach; ?>

            <div id="dept-composing" class="hidden border border-gray-200 rounded-xl p-5 mb-4 -mt-4">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div><label class="block text-sm text-gray-600 mb-1">Compositor's Name</label><input type="text" name="compositor_name" class="w-full px-3 py-2 border border-gray-300 rounded-lg"></div>
                    <div><label class="block text-sm text-gray-600 mb-1">Date Received</label><input type="date" name="composing_date_received" class="w-full px-3 py-2 border border-gray-300 rounded-lg"></div>
                    <div><label class="block text-sm text-gray-600 mb-1">Type</label><input type="text" name="composing_type" class="w-full px-3 py-2 border border-gray-300 rounded-lg"></div>
                    <div><label class="block text-sm text-gray-600 mb-1">Proof to / and date</label><input type="text" name="proof_to_date" class="w-full px-3 py-2 border border-gray-300 rounded-lg"></div>
                    <div><label class="block text-sm text-gray-600 mb-1">Type area (ems wide)</label><input type="text" name="type_area_wide_ems" class="w-full px-3 py-2 border border-gray-300 rounded-lg"></div>
                    <div><label class="block text-sm text-gray-600 mb-1">Type area (ems deep)</label><input type="text" name="type_area_deep_ems" class="w-full px-3 py-2 border border-gray-300 rounded-lg"></div>
                    <div class="md:col-span-2"><label class="block text-sm text-gray-600 mb-1">Special Instructions</label><textarea name="composing_special_instructions" rows="2" class="w-full px-3 py-2 border border-gray-300 rounded-lg resize-y"></textarea></div>
                </div>
            </div>

            <div id="dept-letterpress" class="hidden border border-gray-200 rounded-xl p-5 mb-4">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div><label class="block text-sm text-gray-600 mb-1">Machine Minder's Name</label><input type="text" name="press_minder_name" class="w-full px-3 py-2 border border-gray-300 rounded-lg"></div>
                    <div><label class="block text-sm text-gray-600 mb-1">Date Received</label><input type="date" name="press_date_received" class="w-full px-3 py-2 border border-gray-300 rounded-lg"></div>
                    <div><label class="block text-sm text-gray-600 mb-1">Type of Machine</label><input type="text" name="press_machine_type" class="w-full px-3 py-2 border border-gray-300 rounded-lg"></div>
                    <div><label class="block text-sm text-gray-600 mb-1">Colour of Ink</label><input type="text" name="press_ink_colour" class="w-full px-3 py-2 border border-gray-300 rounded-lg"></div>
                    <div><label class="block text-sm text-gray-600 mb-1">No. of Overs Allowed</label><input type="text" name="press_overs_allowed" class="w-full px-3 py-2 border border-gray-300 rounded-lg"></div>
                    <div><label class="block text-sm text-gray-600 mb-1">Type of Plates</label><input type="text" name="press_plate_type" class="w-full px-3 py-2 border border-gray-300 rounded-lg"></div>
                    <div><label class="block text-sm text-gray-600 mb-1">Camera %</label><input type="text" name="press_camera_percent" class="w-full px-3 py-2 border border-gray-300 rounded-lg"></div>
                    <div><label class="block text-sm text-gray-600 mb-1">Process</label><input type="text" name="press_process" class="w-full px-3 py-2 border border-gray-300 rounded-lg"></div>
                    <div><label class="block text-sm text-gray-600 mb-1">Size</label><input type="text" name="press_size" class="w-full px-3 py-2 border border-gray-300 rounded-lg"></div>
                    <div class="md:col-span-2"><label class="block text-sm text-gray-600 mb-1">Special Instructions</label><textarea name="press_special_instructions" rows="2" class="w-full px-3 py-2 border border-gray-300 rounded-lg resize-y"></textarea></div>
                </div>
            </div>

            <div id="dept-bookbinding" class="hidden border border-gray-200 rounded-xl p-5 mb-4">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div><label class="block text-sm text-gray-600 mb-1">Machine Minder's Name</label><input type="text" name="binding_minder_name" class="w-full px-3 py-2 border border-gray-300 rounded-lg"></div>
                    <div><label class="block text-sm text-gray-600 mb-1">Date Received</label><input type="date" name="binding_date_received" class="w-full px-3 py-2 border border-gray-300 rounded-lg"></div>
                    <div><label class="block text-sm text-gray-600 mb-1">Ruling</label><input type="text" name="binding_ruling" class="w-full px-3 py-2 border border-gray-300 rounded-lg"></div>
                    <div><label class="block text-sm text-gray-600 mb-1">Perforating</label><input type="text" name="binding_perforating" class="w-full px-3 py-2 border border-gray-300 rounded-lg"></div>
                    <div><label class="block text-sm text-gray-600 mb-1">Trim — Fore-edge</label><input type="text" name="bind_trim_fore_edge" class="w-full px-3 py-2 border border-gray-300 rounded-lg"></div>
                    <div><label class="block text-sm text-gray-600 mb-1">Trim — Back</label><input type="text" name="bind_trim_back" class="w-full px-3 py-2 border border-gray-300 rounded-lg"></div>
                    <div><label class="block text-sm text-gray-600 mb-1">Trim — Head</label><input type="text" name="bind_trim_head" class="w-full px-3 py-2 border border-gray-300 rounded-lg"></div>
                    <div><label class="block text-sm text-gray-600 mb-1">Trim — Tail</label><input type="text" name="bind_trim_tail" class="w-full px-3 py-2 border border-gray-300 rounded-lg"></div>
                    <div class="md:col-span-2"><label class="block text-sm text-gray-600 mb-1">Special Instructions</label><textarea name="binding_special_instructions" rows="2" class="w-full px-3 py-2 border border-gray-300 rounded-lg resize-y"></textarea></div>
                </div>
            </div>

            <div id="dept-materials" class="hidden border border-gray-200 rounded-xl p-5 mb-4">
                <div class="flex justify-between items-center mb-4">
                    <p class="text-sm text-gray-500">Log paper and materials used during production.</p>
                    <button type="button" id="add-paper-row" class="text-sm bg-indigo-600 text-white px-3 py-2 rounded-lg hover:bg-indigo-700">Add row</button>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="text-left text-xs uppercase text-gray-500">
                                <th class="px-2 py-2">Ledger No.</th>
                                <th class="px-2 py-2">Qty Sheets/Reels</th>
                                <th class="px-2 py-2">Cut to</th>
                                <th class="px-2 py-2">R.I.V. No.</th>
                                <th class="px-2 py-2">Date</th>
                                <th class="px-2 py-2">Notes</th>
                                <th class="px-2 py-2"></th>
                            </tr>
                        </thead>
                        <tbody id="paper-rows"></tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Step 4 -->
        <div id="step-4" class="step-content hidden">
            <h2 class="text-2xl font-bold text-gray-800 mb-2">Save &amp; Financial Summary</h2>
            <p class="text-sm text-gray-500 mb-6">Review costing accountability before saving the work order as a draft.</p>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-8">
                <div class="bg-slate-50 rounded-xl p-5 space-y-3">
                    <h3 class="font-bold text-gray-800">Costing Accountability</h3>
                    <div class="flex justify-between text-sm"><span class="text-gray-500">Costed by</span><span class="font-semibold"><?php echo htmlspecialchars($currentUser); ?></span></div>
                    <div class="flex justify-between text-sm"><span class="text-gray-500">Issued by</span><span class="font-semibold"><?php echo htmlspecialchars($currentUser); ?></span></div>
                    <div class="flex justify-between text-sm"><span class="text-gray-500">Date</span><span class="font-semibold"><?php echo date('d/m/Y'); ?></span></div>
                </div>
                <div class="bg-slate-50 rounded-xl p-5 space-y-3">
                    <h3 class="font-bold text-gray-800">Customer Balance</h3>
                    <div class="flex justify-between text-sm"><span class="text-gray-500">Total cost of job</span><span class="font-semibold">MK <?php echo number_format($prefill['total_cost'], 2); ?></span></div>
                    <div class="flex justify-between text-sm"><span class="text-gray-500">Amount paid</span><span class="font-semibold">MK <?php echo number_format($prefill['amount_paid'], 2); ?></span></div>
                    <div class="flex justify-between text-sm border-t pt-2"><span class="text-gray-500">Balance</span><span class="font-bold <?php echo $prefill['balance'] > 0 ? 'text-amber-700' : 'text-green-700'; ?>">MK <?php echo number_format($prefill['balance'], 2); ?></span></div>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                <div>
                    <label class="block text-gray-700 font-semibold mb-2">Delivery Instructions</label>
                    <textarea name="delivery_instructions" rows="3" placeholder="e.g. Box 30801 LL 3"
                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-indigo-500 resize-y"></textarea>
                </div>
                <div>
                    <label class="block text-gray-700 font-semibold mb-2">Priority</label>
                    <select name="priority" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-indigo-500">
                        <option value="Normal">Normal</option>
                        <option value="Urgent">Urgent</option>
                        <option value="Critical">Critical</option>
                    </select>
                    <label class="block text-gray-700 font-semibold mb-2 mt-4">Internal Remarks</label>
                    <textarea name="remarks" rows="2" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-indigo-500 resize-y"></textarea>
                </div>
            </div>

            <div class="bg-indigo-50 border border-indigo-100 rounded-lg p-4 text-sm text-indigo-900">
                Saving creates a draft work order in costing. Use <strong>Send to Origination</strong> on the work order when it is ready to enter production routing.
            </div>
        </div>

        <div class="flex justify-between items-center mt-8 pt-6 border-t">
            <button type="button" id="btn-prev" class="px-5 py-2 rounded-lg border border-gray-300 text-gray-700 hover:bg-gray-50" disabled>Previous</button>
            <div class="flex gap-3">
                <button type="button" id="btn-next" class="px-5 py-2 rounded-lg bg-indigo-600 text-white hover:bg-indigo-700">Next</button>
                <button type="submit" id="btn-submit" class="hidden px-5 py-2 rounded-lg bg-green-600 text-white hover:bg-green-700">Save Work Order</button>
            </div>
        </div>
    </form>
</div>

<template id="paper-row-template">
    <tr>
        <td class="px-2 py-2"><input type="text" name="paper_ledger_no[]" class="w-full px-2 py-1 border border-gray-300 rounded"></td>
        <td class="px-2 py-2"><input type="text" name="paper_qty[]" class="w-full px-2 py-1 border border-gray-300 rounded"></td>
        <td class="px-2 py-2"><input type="text" name="paper_cut_to[]" class="w-full px-2 py-1 border border-gray-300 rounded"></td>
        <td class="px-2 py-2"><input type="text" name="paper_riv_no[]" class="w-full px-2 py-1 border border-gray-300 rounded"></td>
        <td class="px-2 py-2"><input type="date" name="paper_date[]" class="w-full px-2 py-1 border border-gray-300 rounded"></td>
        <td class="px-2 py-2"><input type="text" name="paper_notes[]" class="w-full px-2 py-1 border border-gray-300 rounded"></td>
        <td class="px-2 py-2"><button type="button" class="remove-paper-row text-red-500 hover:text-red-700 text-sm">Remove</button></td>
    </tr>
</template>

<div id="bindingTypeModal" class="fixed inset-0 bg-black bg-opacity-50 hidden flex items-center justify-center z-50">
    <div class="bg-white rounded-xl shadow-xl p-6 w-full max-w-md mx-4">
        <h3 class="text-lg font-bold text-gray-800 mb-4">Add Binding Type</h3>
        <form id="bindingTypeForm">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token('work_order_binding')); ?>">
            <input type="text" name="name" id="new_binding_type_name" required placeholder="e.g. Comb Binding"
                class="w-full px-4 py-2 border border-gray-300 rounded-lg mb-4 focus:outline-none focus:border-indigo-500">
            <div class="flex justify-end gap-2">
                <button type="button" id="btn-cancel-binding-type" class="px-4 py-2 rounded-lg border border-gray-300 text-gray-700">Cancel</button>
                <button type="submit" class="px-4 py-2 rounded-lg bg-indigo-600 text-white hover:bg-indigo-700">Save</button>
            </div>
        </form>
    </div>
</div>

<script src="../../assets/js/work_order_wizard.js?v=<?php echo time(); ?>"></script>
<?php include '../../includes/footer.php'; ?>
