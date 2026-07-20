<?php
/** @var array $dashboardEstimationFunnel */
/** @var string $dashboardEstimationFunnelBottleneck */
if (empty($dashboardEstimationFunnel)) {
    return;
}
?>
<section class="dashboard-ops-panel" aria-label="Estimation funnel">
    <div class="dashboard-ops-panel-head">
        <div>
            <h2>Estimation Funnel</h2>
            <p>Status breakdown and bottlenecks in the quote pipeline.</p>
        </div>
        <a href="<?php echo BASE_URL; ?>modules/estimations/status_dashboard" class="dashboard-ops-link">
            Status dashboard
            <i data-lucide="arrow-up-right" aria-hidden="true"></i>
        </a>
    </div>

    <?php if (!empty($dashboardEstimationFunnelBottleneck)): ?>
        <p class="dashboard-ops-mini-alert">
            <i data-lucide="alert-triangle" aria-hidden="true"></i>
            <?php echo htmlspecialchars($dashboardEstimationFunnelBottleneck); ?>
        </p>
    <?php endif; ?>

    <div class="dashboard-ops-funnel-grid">
        <?php foreach ($dashboardEstimationFunnel as $stage): ?>
            <a href="<?php echo htmlspecialchars($stage['href']); ?>" class="dashboard-ops-funnel-item">
                <span class="dashboard-ops-funnel-icon">
                    <i data-lucide="<?php echo htmlspecialchars($stage['icon']); ?>" aria-hidden="true"></i>
                </span>
                <span class="dashboard-ops-funnel-copy">
                    <strong><?php echo htmlspecialchars($stage['label']); ?></strong>
                    <span><?php echo number_format((int) $stage['count']); ?> estimation(s)</span>
                </span>
                <span class="dashboard-ops-funnel-value">MK <?php echo htmlspecialchars(dashboardCurrency($stage['amount'])); ?></span>
            </a>
        <?php endforeach; ?>
    </div>
</section>
