<?php
/** @var array $dashboardProductionPipeline */
/** @var array|null $dashboardProductionPipelineBottleneck */
if (empty($dashboardProductionPipeline)) {
    return;
}
?>
<section class="dashboard-ops-panel" aria-label="Production pipeline">
    <div class="dashboard-ops-panel-head">
        <div>
            <h2>Production Pipeline</h2>
            <p>Where open jobs are concentrated across production stages.</p>
        </div>
        <a href="<?php echo BASE_URL; ?>modules/work_orders/dashboard" class="dashboard-ops-link">
            Production dashboard
            <i data-lucide="arrow-up-right" aria-hidden="true"></i>
        </a>
    </div>

    <?php if (!empty($dashboardProductionPipelineBottleneck)): ?>
        <p class="dashboard-ops-mini-alert">
            <i data-lucide="activity" aria-hidden="true"></i>
            Bottleneck: <?php echo htmlspecialchars($dashboardProductionPipelineBottleneck['label']); ?>
            (<?php echo number_format((int) ($dashboardProductionPipelineBottleneck['count'] ?? 0)); ?> jobs)
        </p>
    <?php endif; ?>

    <div class="dashboard-ops-pipeline-strip">
        <?php foreach ($dashboardProductionPipeline as $stage): ?>
            <a href="<?php echo htmlspecialchars($stage['href']); ?>"
               class="dashboard-ops-pipeline-stage"
               title="<?php echo htmlspecialchars($stage['label'] . ': ' . $stage['count']); ?>">
                <span class="dashboard-ops-pipeline-bar" style="height: <?php echo max(12, min(100, (int) ($stage['pct'] ?? 0))); ?>%;"></span>
                <span class="dashboard-ops-pipeline-label"><?php echo htmlspecialchars($stage['label']); ?></span>
                <span class="dashboard-ops-pipeline-count"><?php echo number_format((int) $stage['count']); ?></span>
            </a>
        <?php endforeach; ?>
    </div>
</section>
