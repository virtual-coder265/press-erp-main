<?php
/**
 * @var string $reportKey
 * @var array $filters
 */
$exportBase = reports_build_query_string(array_merge($filters, ['report' => $reportKey]));
?>
<div class="relative inline-block">
    <button type="button" id="reportExportDropdown" class="list-action-btn bg-white border border-gray-300 text-gray-700" aria-label="Export report" aria-expanded="false" aria-haspopup="true">
        <i data-lucide="download" class="sm:mr-1 inline-block h-4 w-4" aria-hidden="true"></i>
        <span class="hidden sm:inline">Export</span>
        <i data-lucide="chevron-down" class="ml-1 inline-block h-4 w-4 hidden sm:inline" aria-hidden="true"></i>
    </button>
    <div id="reportExportMenu" class="hidden absolute right-0 mt-2 w-52 bg-white rounded-md shadow-lg z-20 border border-gray-200">
        <a href="export<?php echo htmlspecialchars($exportBase . (strpos($exportBase, '?') !== false ? '&' : '?') . 'format=pdf'); ?>"
           class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 flex items-center gap-2">
            <i data-lucide="file-text" class="h-4 w-4 text-red-500" aria-hidden="true"></i>
            Export as PDF
        </a>
        <a href="export<?php echo htmlspecialchars($exportBase . (strpos($exportBase, '?') !== false ? '&' : '?') . 'format=excel'); ?>"
           class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 flex items-center gap-2">
            <i data-lucide="table" class="h-4 w-4 text-green-600" aria-hidden="true"></i>
            Export as Excel
        </a>
        <a href="export<?php echo htmlspecialchars($exportBase . (strpos($exportBase, '?') !== false ? '&' : '?') . 'format=csv'); ?>"
           class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 flex items-center gap-2">
            <i data-lucide="file-spreadsheet" class="h-4 w-4 text-blue-600" aria-hidden="true"></i>
            Export as CSV
        </a>
    </div>
</div>
<script>
(function () {
    var btn = document.getElementById('reportExportDropdown');
    var menu = document.getElementById('reportExportMenu');
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
