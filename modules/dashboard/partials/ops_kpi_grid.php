<?php
/** @var array $dashboardPrimaryCards */
if (empty($dashboardPrimaryCards)) {
    return;
}
?>
<section class="wo-dashboard-kpi-grid dashboard-ops-kpi-section" aria-label="Critical ERP metrics">
    <?php foreach ($dashboardPrimaryCards as $card): ?>
        <?php
        $growth = (string) ($card['growth'] ?? '');
        $growthMeta = dashboard_kpi_growth_meta($growth);
        ?>
        <?php if (!empty($card['href'])): ?>
            <a href="<?php echo htmlspecialchars($card['href']); ?>"
               class="wo-dashboard-kpi-card"
               data-tone="<?php echo htmlspecialchars($card['tone']); ?>">
        <?php else: ?>
            <div class="wo-dashboard-kpi-card" data-tone="<?php echo htmlspecialchars($card['tone']); ?>">
        <?php endif; ?>
            <div class="wo-dashboard-kpi-head">
                <div>
                    <p class="wo-dashboard-kpi-label"><?php echo htmlspecialchars($card['label']); ?></p>
                    <p class="wo-dashboard-kpi-value"><?php echo htmlspecialchars($card['value']); ?></p>
                </div>
                <span class="wo-dashboard-kpi-icon">
                    <i data-lucide="<?php echo htmlspecialchars($card['icon']); ?>" aria-hidden="true"></i>
                </span>
            </div>
            <div class="wo-dashboard-kpi-meta-row">
                <p class="wo-dashboard-kpi-meta"><?php echo htmlspecialchars($card['note']); ?></p>
                <?php if ($growth !== ''): ?>
                    <span class="dashboard-ops-kpi-trend <?php echo htmlspecialchars($growthMeta['class']); ?>">
                        <i data-lucide="<?php echo htmlspecialchars($growthMeta['icon']); ?>" aria-hidden="true"></i>
                        <?php echo htmlspecialchars($growth); ?>
                    </span>
                <?php endif; ?>
            </div>
        <?php if (!empty($card['href'])): ?>
            </a>
        <?php else: ?>
            </div>
        <?php endif; ?>
    <?php endforeach; ?>
</section>
