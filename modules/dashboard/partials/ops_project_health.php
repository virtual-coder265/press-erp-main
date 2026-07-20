<?php
/** @var array $dashboardProjectHealth */
if (empty($dashboardProjectHealth['available'])) {
    return;
}
?>
<section class="dashboard-ops-panel" aria-label="Project health">
    <div class="dashboard-ops-panel-head">
        <div>
            <h2>Project Health</h2>
            <p>Overdue work and assignee load across your visible portfolio.</p>
        </div>
        <a href="<?php echo BASE_URL; ?>modules/projects/analytics" class="dashboard-ops-link">
            Full analytics
            <i data-lucide="arrow-up-right" aria-hidden="true"></i>
        </a>
    </div>

    <div class="dashboard-ops-health-kpis">
        <div class="dashboard-ops-health-kpi">
            <span>Overdue tasks</span>
            <strong><?php echo number_format((int) ($dashboardProjectHealth['overdue_tasks'] ?? 0)); ?></strong>
        </div>
        <div class="dashboard-ops-health-kpi">
            <span>Projects at risk</span>
            <strong><?php echo number_format((int) ($dashboardProjectHealth['projects_at_risk'] ?? 0)); ?></strong>
        </div>
    </div>

    <?php if (!empty($dashboardProjectHealth['top_projects'])): ?>
        <div class="dashboard-ops-queue">
            <?php foreach ($dashboardProjectHealth['top_projects'] as $project): ?>
                <a href="<?php echo htmlspecialchars($project['href']); ?>" class="dashboard-ops-queue-item">
                    <span class="dashboard-ops-queue-icon">
                        <i data-lucide="folder-open" aria-hidden="true"></i>
                    </span>
                    <span>
                        <strong class="dashboard-ops-queue-title"><?php echo htmlspecialchars($project['name']); ?></strong>
                        <span class="dashboard-ops-queue-subtitle">Needs overdue task cleanup</span>
                    </span>
                    <span class="dashboard-ops-queue-value"><?php echo number_format((int) $project['overdue_tasks']); ?> overdue</span>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($dashboardProjectHealth['assignee_workload'])): ?>
        <div class="dashboard-ops-workload-list">
            <?php foreach ($dashboardProjectHealth['assignee_workload'] as $assignee): ?>
                <div class="dashboard-ops-workload-item">
                    <span class="dashboard-ops-workload-name"><?php echo htmlspecialchars($assignee['name']); ?></span>
                    <span class="dashboard-ops-workload-bar" aria-hidden="true">
                        <span style="width: <?php echo max(0, min(100, (int) ($assignee['pct'] ?? 0))); ?>%;"></span>
                    </span>
                    <span class="dashboard-ops-workload-count"><?php echo number_format((int) ($assignee['task_count'] ?? 0)); ?></span>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>
