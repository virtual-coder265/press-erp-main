<?php
/**
 * Work order export dropdown (PDF sections + HTML print preview).
 *
 * Expected:
 * - $workOrderId (int)
 * - $defaultSection (string) optional section key, default full
 * - $buttonClass (string) optional extra classes for the toggle button
 */
$workOrderId = (int) ($workOrderId ?? 0);
$defaultSection = trim((string) ($defaultSection ?? 'full'));
$buttonClass = trim((string) ($buttonClass ?? 'wo-action-btn bg-red-600 text-white hover:bg-red-700'));
$menuId = 'wo-export-menu-' . $workOrderId . '-' . substr(md5($defaultSection . $buttonClass), 0, 8);
$toggleId = $menuId . '-toggle';

if ($workOrderId <= 0) {
    return;
}

$sections = work_order_print_sections();
?>
<div class="relative inline-block">
    <button type="button"
        id="<?php echo htmlspecialchars($toggleId); ?>"
        class="<?php echo htmlspecialchars($buttonClass); ?>"
        aria-label="Export work order"
        aria-expanded="false"
        aria-haspopup="true">
        <i data-lucide="file-down" class="h-4 w-4" aria-hidden="true"></i>
        Export PDF
        <i data-lucide="chevron-down" class="h-4 w-4 ml-0.5" aria-hidden="true"></i>
    </button>
    <div id="<?php echo htmlspecialchars($menuId); ?>"
        class="hidden absolute right-0 mt-2 w-64 bg-white rounded-lg shadow-lg z-30 border border-gray-200 py-1">
        <?php foreach ($sections as $sectionKey => $sectionLabel): ?>
            <a href="<?php echo htmlspecialchars(work_order_pdf_href($workOrderId, $sectionKey, true)); ?>"
                class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-50 flex items-center gap-2">
                <i data-lucide="file-text" class="h-4 w-4 text-red-500 shrink-0" aria-hidden="true"></i>
                <span>PDF — <?php echo htmlspecialchars($sectionLabel); ?></span>
            </a>
        <?php endforeach; ?>
        <div class="border-t border-gray-100 my-1"></div>
        <a href="<?php echo htmlspecialchars(work_order_print_href($workOrderId, $defaultSection)); ?>"
            target="_blank"
            rel="noopener"
            class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-50 flex items-center gap-2">
            <i data-lucide="printer" class="h-4 w-4 text-slate-500 shrink-0" aria-hidden="true"></i>
            <span>Print preview (HTML)</span>
        </a>
    </div>
</div>
<script>
(function () {
    var btn = document.getElementById(<?php echo json_encode($toggleId); ?>);
    var menu = document.getElementById(<?php echo json_encode($menuId); ?>);
    if (!btn || !menu) return;
    btn.addEventListener('click', function (e) {
        e.stopPropagation();
        var open = !menu.classList.contains('hidden');
        menu.classList.toggle('hidden', open);
        btn.setAttribute('aria-expanded', open ? 'false' : 'true');
    });
    document.addEventListener('click', function () {
        menu.classList.add('hidden');
        btn.setAttribute('aria-expanded', 'false');
    });
})();
</script>
