<?php
/** @var array $dashboardHeroTrend */
/** @var array $dashboardDateRange */
/** @var bool $dashboardCanViewRevenueChart */
if (empty($dashboardHeroTrend['labels'])) {
    return;
}
$trendMetricLabel = (string) ($dashboardHeroTrend['metric_label'] ?? 'Collections');
$trendGranularity = (string) ($dashboardHeroTrend['granularity'] ?? 'day');
?>
<div class="dashboard-ops-hero-trend"
     data-ajax-component="dashboard.ops.hero_trend"
     aria-label="<?php echo htmlspecialchars($trendMetricLabel); ?> trend">
    <div class="dashboard-ops-hero-trend-head">
        <span class="dashboard-ops-hero-trend-kicker"><?php echo htmlspecialchars($trendMetricLabel); ?></span>
        <span class="dashboard-ops-hero-trend-meta">
            <?php echo htmlspecialchars($dashboardDateRange['label'] ?? 'Month to date'); ?>
        </span>
    </div>
    <div class="dashboard-ops-hero-trend-frame">
        <span class="dashboard-ops-hero-trend-grid" aria-hidden="true"></span>
        <canvas id="dashboardOpsHeroTrend"
                aria-label="<?php echo htmlspecialchars($trendMetricLabel); ?> trend chart"
                role="img"></canvas>
    </div>
    <script type="application/json" data-dashboard-hero-trend><?php
        echo json_encode($dashboardHeroTrend, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    ?></script>
</div>
