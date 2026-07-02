<?php
/**
 * Dashboard partial - Performance modal body.
 *
 * Component id: dashboard.modal.performance
 * Required context: $currentMonthLabel, $dashboardFeatureCards, $dashboardMetricTiles
 */
?>
<div class="todo-modal-body"
     data-ajax-component="dashboard.modal.performance"
     data-ajax-refresh-on="modal-open:wsModalPerformance,action:reminder.create,action:task.create,action:project.create"
     data-ajax-stale="20000">
    <p style="font-size:12px;color:#605e5c;margin:-4px 0 0;">
        Key figures across finance, delivery and workload &middot; <?php echo htmlspecialchars($currentMonthLabel); ?>.
    </p>
    <?php if (!empty($dashboardFeatureCards) || !empty($dashboardMetricTiles)): ?>
        <?php if (!empty($dashboardFeatureCards)): ?>
        <div class="dashboard-showcase-grid">
            <?php foreach ($dashboardFeatureCards as $card): ?>
            <a href="<?php echo $card['href']; ?>" class="dashboard-showcase-card <?php echo $card['tone'] === 'primary' ? 'is-primary' : 'is-soft'; ?>">
                <div class="dashboard-showcase-top">
                    <div class="min-w-0">
                        <p class="dashboard-showcase-label"><?php echo htmlspecialchars($card['label']); ?></p>
                        <h3 class="dashboard-showcase-value break-words"><?php echo htmlspecialchars($card['value']); ?></h3>
                        <div class="dashboard-showcase-meta">
                            <?php foreach ($card['meta'] as $meta): ?>
                            <div class="dashboard-showcase-meta-item">
                                <span><?php echo htmlspecialchars($meta['label']); ?></span>
                                <strong><?php echo htmlspecialchars($meta['value']); ?></strong>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <span class="dashboard-showcase-icon">
                        <i data-lucide="<?php echo htmlspecialchars($card['icon']); ?>" aria-hidden="true"></i>
                    </span>
                </div>
                <div class="dashboard-showcase-bottom">
                    <div class="dashboard-showcase-footer">
                        <span><?php echo htmlspecialchars($card['footer_label']); ?></span>
                        <span class="dashboard-showcase-action">
                            <?php echo htmlspecialchars($card['footer_value']); ?>
                            <i data-lucide="arrow-right" class="text-sm w-4 h-4" aria-hidden="true"></i>
                        </span>
                    </div>
                </div>
            </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php if (!empty($dashboardMetricTiles)): ?>
        <div class="dashboard-metric-grid" style="margin-top:16px;">
            <?php foreach ($dashboardMetricTiles as $tile): ?>
            <a href="<?php echo $tile['href']; ?>" class="dashboard-metric-tile">
                <span><?php echo htmlspecialchars($tile['label']); ?></span>
                <strong><?php echo htmlspecialchars($tile['value']); ?></strong>
            </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    <?php else: ?>
        <div class="todo-empty"><i data-lucide="chart-line" class="dashboard-lucide-lg" aria-hidden="true"></i><p>No performance data available yet.</p></div>
    <?php endif; ?>
</div>
