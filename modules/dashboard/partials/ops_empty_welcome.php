<?php
/** @var array $dashboardPermittedModules */
/** @var bool $dashboardShowEmptyWelcome */
if (empty($dashboardShowEmptyWelcome) || empty($dashboardPermittedModules)) {
    return;
}
?>
<section class="dashboard-ops-panel dashboard-ops-welcome"
         data-ajax-component="dashboard.ops.empty_welcome"
         aria-label="Welcome to your workspace">
    <div class="dashboard-ops-panel-head">
        <div>
            <h2>Welcome to your workspace</h2>
            <p>Your role has limited dashboard metrics, but these modules are available to you.</p>
        </div>
    </div>
    <div class="dashboard-ops-welcome-grid">
        <?php foreach ($dashboardPermittedModules as $module): ?>
            <a href="<?php echo htmlspecialchars($module['href']); ?>"
               class="dashboard-ops-welcome-card"
               data-tone="<?php echo htmlspecialchars($module['tone']); ?>">
                <span class="dashboard-ops-welcome-icon">
                    <i data-lucide="<?php echo htmlspecialchars($module['icon']); ?>" aria-hidden="true"></i>
                </span>
                <span class="dashboard-ops-welcome-copy">
                    <strong><?php echo htmlspecialchars($module['label']); ?></strong>
                    <span><?php echo htmlspecialchars($module['description']); ?></span>
                </span>
            </a>
        <?php endforeach; ?>
    </div>
</section>
