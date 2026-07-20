<?php
/** @var array $dashboardWorkspaceTiles */
if (empty($dashboardWorkspaceTiles)) {
    return;
}
?>
<div class="dashboard-ops-workspace-shortcuts"
     data-ajax-component="dashboard.ops.workspace"
     data-ajax-poll="60000"
     aria-label="Workspace shortcuts">
    <div class="dashboard-ops-workspace-grid" role="list" aria-label="Workspace tiles">
        <?php foreach ($dashboardWorkspaceTiles as $tile): ?>
            <?php include __DIR__ . '/_tile.php'; ?>
        <?php endforeach; ?>
    </div>
</div>
