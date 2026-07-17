<?php
/** @var array $kpis */
if (empty($kpis)) {
    return;
}
?>
<div class="wo-dashboard-kpi-grid mb-6">
    <?php foreach ($kpis as $kpi): ?>
        <div class="wo-dashboard-kpi-card" data-tone="<?php echo htmlspecialchars($kpi['tone'] ?? 'indigo'); ?>">
            <div class="wo-dashboard-kpi-head">
                <div>
                    <p class="wo-dashboard-kpi-label"><?php echo htmlspecialchars($kpi['label']); ?></p>
                    <p class="wo-dashboard-kpi-value"><?php echo htmlspecialchars((string) $kpi['value']); ?></p>
                </div>
                <span class="wo-dashboard-kpi-icon">
                    <i data-lucide="<?php echo htmlspecialchars($kpi['icon'] ?? 'bar-chart-2'); ?>" aria-hidden="true"></i>
                </span>
            </div>
        </div>
    <?php endforeach; ?>
</div>
