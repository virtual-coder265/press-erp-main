<?php
/** @var array $dashboardDateRange */
$activeRange = dashboard_normalize_date_range_key((string) ($dashboardDateRange['key'] ?? 'month'));
$ranges = [
    'day' => 'Day',
    'week' => 'Week',
    'month' => 'Month',
    'quarter' => 'Quarter',
];
$queryParams = $_GET;
unset($queryParams['range']);
$baseQuery = http_build_query($queryParams);
$buildRangeHref = static function (string $rangeKey) use ($baseQuery): string {
    $query = $baseQuery !== '' ? $baseQuery . '&range=' . rawurlencode($rangeKey) : 'range=' . rawurlencode($rangeKey);

    return BASE_URL . 'modules/dashboard/index?' . $query;
};
?>
<div class="dashboard-ops-date-range" aria-label="Dashboard trend period">
    <span class="dashboard-ops-date-range-label"><?php echo htmlspecialchars($dashboardDateRange['label'] ?? 'Month to date'); ?></span>
    <div class="dashboard-ops-date-range-tabs" role="tablist">
        <?php foreach ($ranges as $rangeKey => $rangeLabel): ?>
            <a href="<?php echo htmlspecialchars($buildRangeHref($rangeKey)); ?>"
               class="dashboard-ops-date-range-tab<?php echo $activeRange === $rangeKey ? ' is-active' : ''; ?>"
               role="tab"
               aria-selected="<?php echo $activeRange === $rangeKey ? 'true' : 'false'; ?>">
                <?php echo htmlspecialchars($rangeLabel); ?>
            </a>
        <?php endforeach; ?>
    </div>
</div>
