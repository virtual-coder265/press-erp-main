<?php
/**
 * Dashboard partial - hero metric cards (Total Projects, Total Tasks,
 * Avg. Project Earnings, Productivity).
 *
 * Component id: dashboard.hero.metrics
 * Required context: $dashboardHeroCards
 */
?>
<section class="dashboard-hero-metrics"
         data-ajax-component="dashboard.hero.metrics"
         data-ajax-poll="60000"
         data-ajax-refresh-on="focus,action:task.create,action:project.create,action:reminder.create"
         data-ajax-stale="20000"
         aria-label="Dashboard quick metrics">
    <?php foreach ($dashboardHeroCards as $card): ?>
        <?php $isPositiveGrowth = strpos((string) $card['growth'], '-') === false && (string) $card['growth'] !== '0%'; ?>
        <?php
            $growthStr = (string) $card['growth'];
            $growthIconLucide = strpos($growthStr, '-') !== false
                ? 'trending-down'
                : ($growthStr === '0%' ? 'minus' : 'trending-up');
        ?>
        <a href="#"
           class="dashboard-hero-metric-card"
           data-tone="<?php echo htmlspecialchars($card['tone']); ?>"
           data-ws-open="<?php echo htmlspecialchars($card['target']); ?>"
           onclick="event.preventDefault(); openWorkspaceModal('<?php echo htmlspecialchars($card['target']); ?>');">
            <span class="dashboard-hero-metric-icon">
                <i data-lucide="<?php echo htmlspecialchars($card['icon']); ?>" aria-hidden="true"></i>
            </span>
            <span class="dashboard-hero-metric-label"><?php echo htmlspecialchars($card['label']); ?></span>
            <strong class="dashboard-hero-metric-value"><?php echo htmlspecialchars($card['value']); ?></strong>
            <span class="dashboard-hero-metric-growth <?php echo $isPositiveGrowth ? 'is-up' : 'is-down'; ?>">
                <i data-lucide="<?php echo htmlspecialchars($growthIconLucide); ?>" aria-hidden="true"></i>
                <?php echo htmlspecialchars($card['growth']); ?>
            </span>
            <span class="dashboard-hero-metric-meta"><?php echo htmlspecialchars($card['meta']); ?></span>
        </a>
    <?php endforeach; ?>
</section>
