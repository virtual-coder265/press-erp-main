<?php
/** @var array $kpis */
if (empty($kpis)) {
    return;
}
?>
<div class="wo-dashboard-kpi-grid mb-6" aria-label="Module summary metrics">
    <?php foreach ($kpis as $kpi): ?>
        <?php if (!empty($kpi['href'])): ?>
            <a href="<?php echo htmlspecialchars($kpi['href']); ?>"
               class="wo-dashboard-kpi-card"
               data-tone="<?php echo htmlspecialchars($kpi['tone'] ?? 'indigo'); ?>">
        <?php else: ?>
            <div class="wo-dashboard-kpi-card" data-tone="<?php echo htmlspecialchars($kpi['tone'] ?? 'indigo'); ?>">
        <?php endif; ?>
            <div class="wo-dashboard-kpi-head">
                <div>
                    <p class="wo-dashboard-kpi-label"><?php echo htmlspecialchars($kpi['label']); ?></p>
                    <p class="wo-dashboard-kpi-value"><?php echo htmlspecialchars((string) $kpi['value']); ?></p>
                </div>
                <span class="wo-dashboard-kpi-icon">
                    <i data-lucide="<?php echo htmlspecialchars($kpi['icon'] ?? 'bar-chart-2'); ?>" aria-hidden="true"></i>
                </span>
            </div>
            <?php if (!empty($kpi['meta'])): ?>
                <p class="wo-dashboard-kpi-meta"><?php echo htmlspecialchars($kpi['meta']); ?></p>
            <?php endif; ?>
        <?php if (!empty($kpi['href'])): ?>
            </a>
        <?php else: ?>
            </div>
        <?php endif; ?>
    <?php endforeach; ?>
</div>
