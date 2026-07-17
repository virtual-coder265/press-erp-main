<?php
/**
 * Renders the paper-form traveler sections for a work order detail view.
 *
 * Expects: $workOrder, $spec, $productionForm (array), $formeDressing (array), $trimMargins (array)
 */
if (!function_exists('wo_traveler_value')) {
    function wo_traveler_value($value): string
    {
        $value = trim((string) $value);
        return $value !== '' ? htmlspecialchars($value) : '<span class="text-gray-400">—</span>';
    }
}

if (!function_exists('wo_traveler_section_exports')) {
    function wo_traveler_section_exports(int $workOrderId, string $section): void
    {
        ?>
        <span class="inline-flex items-center gap-2 text-xs">
            <a href="<?php echo htmlspecialchars(work_order_print_href($workOrderId, $section)); ?>" target="_blank" rel="noopener"
                class="text-indigo-600 hover:underline inline-flex items-center gap-1">
                <i data-lucide="printer" class="h-3 w-3" aria-hidden="true"></i> Print
            </a>
            <a href="<?php echo htmlspecialchars(work_order_pdf_href($workOrderId, $section, true)); ?>"
                class="text-red-600 hover:underline inline-flex items-center gap-1">
                <i data-lucide="file-text" class="h-3 w-3" aria-hidden="true"></i> PDF
            </a>
        </span>
        <?php
    }
}

$bindingLabel = $workOrder['binding_type_name'] ?? $workOrder['binding_catalog_name'] ?? '';
$composing = $productionForm['composing'] ?? [];
$letterpress = $productionForm['letterpress'] ?? [];
$bookbinding = $productionForm['bookbinding'] ?? [];
$paperMaterials = $productionForm['paper_materials'] ?? [];
$dispatchReceived = $productionForm['dispatch_received'] ?? [];
$costingTracking = $productionForm['costing_tracking'] ?? [];
?>

<div class="bg-white shadow rounded-xl p-6 mb-6">
    <div class="flex flex-wrap items-center justify-between gap-3 mb-6 border-b pb-4">
        <h3 class="text-lg font-bold text-gray-700">Work Order Traveler</h3>
        <div class="flex flex-wrap items-center gap-2">
            <?php
            $workOrderId = (int) $workOrder['id'];
            $buttonClass = 'inline-flex items-center gap-1 text-sm px-3 py-1.5 rounded-lg bg-red-700 text-white hover:bg-red-800';
            include __DIR__ . '/partials/work_order_export_menu.php';
            ?>
            <?php if (hasPermission('manage_work_orders')): ?>
                <a href="edit?id=<?php echo (int) $workOrder['id']; ?>" class="text-sm text-indigo-600 hover:underline">Edit traveler</a>
            <?php endif; ?>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
        <div class="space-y-3 text-sm relative">
            <div class="flex items-center justify-between gap-2">
                <h4 class="font-semibold text-gray-800">Job Header</h4>
                <?php wo_traveler_section_exports((int) $workOrder['id'], 'job'); ?>
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div><span class="text-gray-500">Ministry / Dept</span><div><?php echo wo_traveler_value($workOrder['ministry_department'] ?? ''); ?></div></div>
                <div><span class="text-gray-500">Order Ref / LPO</span><div><?php echo wo_traveler_value($workOrder['order_ref_lpo'] ?? ''); ?></div></div>
                <div><span class="text-gray-500">Quantity</span><div><?php echo wo_traveler_value($workOrder['quantity'] ?? ''); ?></div></div>
                <div><span class="text-gray-500">Pages</span><div><?php echo wo_traveler_value($workOrder['pages_count'] ?? ''); ?></div></div>
                <div><span class="text-gray-500">Size deep</span><div><?php echo wo_traveler_value($workOrder['size_deep'] ?? ''); ?></div></div>
                <div><span class="text-gray-500">Size wide</span><div><?php echo wo_traveler_value($workOrder['size_wide'] ?? ''); ?></div></div>
                <div><span class="text-gray-500">Numbering</span><div><?php echo wo_traveler_value($workOrder['numbering_start'] ?? ''); ?></div></div>
                <div><span class="text-gray-500">Binding</span><div><?php echo wo_traveler_value($bindingLabel); ?></div></div>
                <div><span class="text-gray-500">Previous WO</span><div><?php echo wo_traveler_value($workOrder['previous_work_order_number'] ?? ''); ?></div></div>
                <div><span class="text-gray-500">Charge vote</span><div><?php echo wo_traveler_value($workOrder['charge_vote'] ?? ''); ?></div></div>
            </div>
            <?php if (!empty($workOrder['special_instructions'])): ?>
                <div><span class="text-gray-500">Special instructions</span><p class="mt-1 whitespace-pre-wrap"><?php echo htmlspecialchars($workOrder['special_instructions']); ?></p></div>
            <?php endif; ?>
        </div>

        <div class="space-y-3 text-sm relative">
            <div class="flex items-center justify-between gap-2">
                <h4 class="font-semibold text-gray-800">Costing &amp; Balance</h4>
                <?php wo_traveler_section_exports((int) $workOrder['id'], 'job'); ?>
            </div>
            <div class="bg-slate-50 rounded-lg p-4 space-y-2">
                <?php
                $travelerTotalCost = (float) ($workOrder['invoice_total'] ?? $workOrder['total_cost_snapshot'] ?? 0);
                $travelerAmountPaid = (float) ($workOrder['paid_amount'] ?? $workOrder['amount_paid_snapshot'] ?? 0);
                $travelerBalance = (float) ($workOrder['balance'] ?? $workOrder['balance_snapshot'] ?? 0);
                ?>
                <div class="flex justify-between"><span class="text-gray-500">Costed by</span><span class="font-semibold"><?php echo wo_traveler_value($workOrder['costed_by_name'] ?? ''); ?></span></div>
                <div class="flex justify-between"><span class="text-gray-500">Issued by</span><span class="font-semibold"><?php echo wo_traveler_value($workOrder['issued_by_name'] ?? ''); ?></span></div>
                <div class="flex justify-between"><span class="text-gray-500">Total cost</span><span class="font-semibold">MK <?php echo number_format($travelerTotalCost, 2); ?></span></div>
                <div class="flex justify-between"><span class="text-gray-500">Amount paid</span><span class="font-semibold">MK <?php echo number_format($travelerAmountPaid, 2); ?></span></div>
                <div class="flex justify-between border-t pt-2"><span class="text-gray-500">Balance</span><span class="font-bold">MK <?php echo number_format($travelerBalance, 2); ?></span></div>
            </div>
            <?php if (!empty($workOrder['delivery_instructions'])): ?>
                <div><span class="text-gray-500">Delivery instructions</span><p class="mt-1 whitespace-pre-wrap"><?php echo htmlspecialchars($workOrder['delivery_instructions']); ?></p></div>
            <?php endif; ?>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
        <div class="border border-gray-100 rounded-lg p-4">
            <div class="flex items-center justify-between gap-2 mb-3">
                <h4 class="font-semibold text-gray-800">Forme Dressing</h4>
                <?php wo_traveler_section_exports((int) $workOrder['id'], 'forme'); ?>
            </div>
            <div class="grid grid-cols-2 gap-2 text-sm">
                <?php foreach (['backs' => 'Backs', 'heads' => 'Heads', 'gutters' => 'Gutters', 'tails' => 'Tails'] as $key => $label): ?>
                    <div><span class="text-gray-500"><?php echo $label; ?></span><div><?php echo wo_traveler_value($formeDressing[$key] ?? ''); ?></div></div>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="border border-gray-100 rounded-lg p-4">
            <div class="flex items-center justify-between gap-2 mb-3">
                <h4 class="font-semibold text-gray-800">Trimmed Size Margins</h4>
                <?php wo_traveler_section_exports((int) $workOrder['id'], 'forme'); ?>
            </div>
            <div class="grid grid-cols-2 gap-2 text-sm">
                <?php foreach (['backs' => 'Backs', 'heads' => 'Heads', 'fore_edge' => 'Fore-edge', 'tails' => 'Tails'] as $key => $label): ?>
                    <div><span class="text-gray-500"><?php echo $label; ?></span><div><?php echo wo_traveler_value($trimMargins[$key] ?? ''); ?></div></div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <div class="space-y-6">
        <?php
        $sections = [
            'Origination Record' => [
                'print_section' => 'composing',
                'fields' => [
                    'Compositor' => $composing['compositor_name'] ?? '',
                    'Date received' => $composing['date_received'] ?? '',
                    'Type' => $composing['type'] ?? '',
                    'Type area wide (ems)' => $composing['type_area_wide_ems'] ?? '',
                    'Type area deep (ems)' => $composing['type_area_deep_ems'] ?? '',
                    'Proof to / date' => $composing['proof_to_date'] ?? '',
                    'Special instructions' => $composing['special_instructions'] ?? '',
                ],
            ],
            'Letterpress / Machine' => [
                'print_section' => 'letterpress',
                'fields' => [
                    'Machine minder' => $letterpress['machine_minder_name'] ?? '',
                    'Date received' => $letterpress['date_received'] ?? '',
                    'Machine type' => $letterpress['machine_type'] ?? '',
                    'Ink colour' => $letterpress['ink_colour'] ?? '',
                    'Overs allowed' => $letterpress['overs_allowed'] ?? '',
                    'Plate type' => $letterpress['plate_type'] ?? '',
                    'Camera %' => $letterpress['camera_percent'] ?? '',
                    'Process' => $letterpress['process'] ?? '',
                    'Size' => $letterpress['size'] ?? '',
                    'Special instructions' => $letterpress['special_instructions'] ?? '',
                ],
            ],
            'Bookbinding / Finishing' => [
                'print_section' => 'bookbinding',
                'fields' => [
                    'Machine minder' => $bookbinding['machine_minder_name'] ?? '',
                    'Date received' => $bookbinding['date_received'] ?? '',
                    'Ruling' => $bookbinding['ruling'] ?? '',
                    'Perforating' => $bookbinding['perforating'] ?? '',
                    'Trim fore-edge' => $bookbinding['trim_fore_edge'] ?? '',
                    'Trim back' => $bookbinding['trim_back'] ?? '',
                    'Trim head' => $bookbinding['trim_head'] ?? '',
                    'Trim tail' => $bookbinding['trim_tail'] ?? '',
                    'Special instructions' => $bookbinding['special_instructions'] ?? '',
                ],
            ],
        ];
        foreach ($sections as $title => $section):
            $fields = $section['fields'] ?? [];
            $printSection = $section['print_section'] ?? 'full';
            $hasData = false;
            foreach ($fields as $value) {
                if (trim((string) $value) !== '') {
                    $hasData = true;
                    break;
                }
            }
        ?>
            <div class="border border-gray-100 rounded-lg p-4">
                <div class="flex items-center justify-between gap-2 mb-3">
                    <h4 class="font-semibold text-gray-800"><?php echo htmlspecialchars($title); ?></h4>
                    <?php wo_traveler_section_exports((int) $workOrder['id'], $printSection); ?>
                </div>
                <?php if (!$hasData): ?>
                    <p class="text-sm text-gray-400 italic">Not yet filled in.</p>
                <?php else: ?>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3 text-sm">
                        <?php foreach ($fields as $label => $value): ?>
                            <?php if (trim((string) $value) === '') continue; ?>
                            <div>
                                <span class="text-gray-500"><?php echo htmlspecialchars($label); ?></span>
                                <div class="<?php echo $label === 'Special instructions' ? 'whitespace-pre-wrap' : ''; ?>"><?php echo htmlspecialchars((string) $value); ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>

        <div class="border border-gray-100 rounded-lg p-4 overflow-x-auto">
            <div class="flex items-center justify-between gap-2 mb-3">
                <h4 class="font-semibold text-gray-800">Paper &amp; Materials</h4>
                <?php wo_traveler_section_exports((int) $workOrder['id'], 'materials'); ?>
            </div>
            <?php if (empty($paperMaterials)): ?>
                <p class="text-sm text-gray-400 italic">Not yet filled in.</p>
            <?php else: ?>
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="text-left text-xs uppercase text-gray-500">
                            <th class="px-2 py-2">Ledger</th>
                            <th class="px-2 py-2">Qty</th>
                            <th class="px-2 py-2">Cut to</th>
                            <th class="px-2 py-2">R.I.V.</th>
                            <th class="px-2 py-2">Date</th>
                            <th class="px-2 py-2">Notes</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($paperMaterials as $row): ?>
                            <tr class="border-t">
                                <td class="px-2 py-2"><?php echo htmlspecialchars($row['ledger_no'] ?? ''); ?></td>
                                <td class="px-2 py-2"><?php echo htmlspecialchars($row['quantity'] ?? ''); ?></td>
                                <td class="px-2 py-2"><?php echo htmlspecialchars($row['cut_to'] ?? ''); ?></td>
                                <td class="px-2 py-2"><?php echo htmlspecialchars($row['riv_no'] ?? ''); ?></td>
                                <td class="px-2 py-2"><?php echo htmlspecialchars($row['date'] ?? ''); ?></td>
                                <td class="px-2 py-2"><?php echo htmlspecialchars($row['notes'] ?? ''); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
</div>
