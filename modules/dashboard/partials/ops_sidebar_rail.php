<?php
/**
 * Right-rail calendar and schedule for the ops dashboard layout.
 */
?>
<aside class="dashboard-ops-sidebar-rail"
       data-ajax-component="dashboard.ops.sidebar"
       data-ajax-poll="120000"
       aria-label="Calendar and schedule">
    <?php include __DIR__ . '/calendar.php'; ?>
    <?php include __DIR__ . '/schedule.php'; ?>
</aside>
