<?php
/**
 * @var array $filters
 * @var string $reportKey
 * @var array $filterConfig optional keys: status_options, category_options, department_options, show_sale_type, show_work_order
 */
require_once __DIR__ . '/../../../includes/datetime_picker_helper.php';

$filterConfig = $filterConfig ?? [];
$hasActiveFilters = false;
foreach ($filters as $key => $value) {
    if ($key === 'preset' && ($value === 'all_time' || $value === '')) {
        continue;
    }
    if ($value !== '') {
        $hasActiveFilters = true;
        break;
    }
}
?>
<div class="bg-white shadow rounded-lg p-6 mb-6">
    <form method="get" class="list-filters-grid md:grid-cols-12 gap-4 items-end">
        <div class="md:col-span-3">
            <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">Period</label>
            <select name="preset" id="reportPreset" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-500">
                <?php foreach (reports_preset_options() as $value => $label): ?>
                    <option value="<?php echo htmlspecialchars($value); ?>" <?php echo ($filters['preset'] ?? '') === $value ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($label); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="md:col-span-2 report-custom-dates <?php echo ($filters['preset'] ?? '') === 'custom' ? '' : 'hidden'; ?>">
            <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">From</label>
            <?php echo press_datetime_picker_field([
                'name' => 'date_from',
                'value' => $filters['date_from'] ?? '',
                'mode' => 'date',
                'class' => 'w-full border border-gray-300 rounded-lg px-3 py-2 text-sm',
            ]); ?>
        </div>

        <div class="md:col-span-2 report-custom-dates <?php echo ($filters['preset'] ?? '') === 'custom' ? '' : 'hidden'; ?>">
            <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">To</label>
            <?php echo press_datetime_picker_field([
                'name' => 'date_to',
                'value' => $filters['date_to'] ?? '',
                'mode' => 'date',
                'class' => 'w-full border border-gray-300 rounded-lg px-3 py-2 text-sm',
            ]); ?>
        </div>

        <?php if (!empty($filterConfig['show_search'])): ?>
        <div class="md:col-span-3">
            <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">Search</label>
            <input type="text" name="search" value="<?php echo htmlspecialchars($filters['search'] ?? ''); ?>"
                   placeholder="Search records..."
                   class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-500">
        </div>
        <?php endif; ?>

        <?php if (!empty($filterConfig['status_options'])): ?>
        <div class="md:col-span-2">
            <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">Status</label>
            <select name="status" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
                <option value="">All statuses</option>
                <?php foreach ($filterConfig['status_options'] as $status): ?>
                    <option value="<?php echo htmlspecialchars($status); ?>" <?php echo ($filters['status'] ?? '') === $status ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($status); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>

        <?php if (!empty($filterConfig['show_sale_type'])): ?>
        <div class="md:col-span-2">
            <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">Sale Type</label>
            <select name="sale_type" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
                <option value="">All types</option>
                <option value="direct" <?php echo ($filters['sale_type'] ?? '') === 'direct' ? 'selected' : ''; ?>>Direct sales</option>
                <option value="invoiced" <?php echo ($filters['sale_type'] ?? '') === 'invoiced' ? 'selected' : ''; ?>>Invoiced sales</option>
            </select>
        </div>
        <?php endif; ?>

        <?php if (!empty($filterConfig['category_options'])): ?>
        <div class="md:col-span-2">
            <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">Category</label>
            <select name="category" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
                <option value="">All categories</option>
                <?php foreach ($filterConfig['category_options'] as $cat): ?>
                    <option value="<?php echo (int) $cat['id']; ?>" <?php echo (string) ($filters['category'] ?? '') === (string) $cat['id'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($cat['name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>

        <?php if (!empty($filterConfig['department_options'])): ?>
        <div class="md:col-span-2">
            <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">Department</label>
            <select name="department" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
                <option value="">All departments</option>
                <?php foreach ($filterConfig['department_options'] as $dept): ?>
                    <option value="<?php echo htmlspecialchars($dept['slug']); ?>" <?php echo ($filters['department'] ?? '') === $dept['slug'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($dept['name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>

        <?php if (!empty($filterConfig['show_priority'])): ?>
        <div class="md:col-span-2">
            <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">Priority</label>
            <select name="priority" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
                <option value="">All priorities</option>
                <?php foreach (['Low', 'Normal', 'High', 'Urgent'] as $priority): ?>
                    <option value="<?php echo htmlspecialchars($priority); ?>" <?php echo ($filters['priority'] ?? '') === $priority ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($priority); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>

        <?php if (!empty($filterConfig['show_work_order'])): ?>
        <div class="md:col-span-2">
            <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">Work Order</label>
            <input type="text" name="work_order" value="<?php echo htmlspecialchars($filters['work_order'] ?? ''); ?>"
                   placeholder="WO number..."
                   class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
        </div>
        <?php endif; ?>

        <div class="md:col-span-12 flex flex-wrap gap-2 pt-1">
            <button type="submit" class="list-action-btn bg-indigo-600 text-white">
                <i data-lucide="filter" class="sm:mr-1 inline-block h-4 w-4" aria-hidden="true"></i>
                Apply Filters
            </button>
            <?php if ($hasActiveFilters): ?>
                <a href="<?php echo htmlspecialchars($reportKey); ?>" class="list-action-btn bg-gray-100 text-gray-700">Clear</a>
            <?php endif; ?>
            <span class="text-sm text-gray-500 self-center ml-2">
                Showing: <strong><?php echo htmlspecialchars(reports_filter_period_label($filters)); ?></strong>
            </span>
        </div>
    </form>
</div>
<script>
(function () {
    var preset = document.getElementById('reportPreset');
    if (!preset) return;
    var customBlocks = document.querySelectorAll('.report-custom-dates');
    function toggleCustomDates() {
        var show = preset.value === 'custom';
        customBlocks.forEach(function (el) {
            el.classList.toggle('hidden', !show);
        });
    }
    preset.addEventListener('change', toggleCustomDates);
    toggleCustomDates();
})();
</script>
