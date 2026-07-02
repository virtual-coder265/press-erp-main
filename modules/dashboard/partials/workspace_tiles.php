<?php
/**
 * Dashboard partial - workspace shortcut tiles (Performance, Activity,
 * Reports, Projects, Tasks, Reminders, Quick Actions).
 *
 * Component id: dashboard.workspace.tiles
 * Required context: $wsDashboardTiles
 *
 * Each tile is rendered via partials/_tile.php which already exists.
 */
?>
<div class="dashboard-workspace-shortcuts"
     data-ajax-component="dashboard.workspace.tiles"
     data-ajax-poll="60000"
     data-ajax-refresh-on="focus,action:task.create,action:reminder.create,action:project.create"
     data-ajax-stale="20000">
    <div class="dashboard-focus-head">
        <span>Workspace</span>
        <h2>Quick access</h2>
    </div>
    <div class="todo-tile-grid todo-tile-grid--wide" role="list" aria-label="Workspace tiles">
        <?php foreach (array_slice($wsDashboardTiles, 0, 4) as $tile): include __DIR__ . '/_tile.php'; endforeach; ?>
    </div>
</div>
