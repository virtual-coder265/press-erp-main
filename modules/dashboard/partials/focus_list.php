<?php
/**
 * Dashboard partial - "Today's Focus" prioritized list.
 *
 * Component id: dashboard.focus.list
 * Required context: $dashboardFocusItems
 */
?>
<div class="dashboard-focus-card"
     data-ajax-component="dashboard.focus.list"
     data-ajax-poll="60000"
     data-ajax-refresh-on="focus,action:task.create,action:reminder.create"
     data-ajax-stale="20000">
    <div class="dashboard-focus-head">
        <span>Today’s Focus</span>
        <h2>Open the next priority fast</h2>
    </div>
    <div class="dashboard-focus-list">
        <?php foreach ($dashboardFocusItems as $item): ?>
        <a href="#"
           class="dashboard-focus-item"
           data-tone="<?php echo htmlspecialchars($item['tone']); ?>"
           data-ws-open="<?php echo htmlspecialchars($item['target']); ?>"
           onclick="event.preventDefault(); openWorkspaceModal('<?php echo htmlspecialchars($item['target']); ?>');">
            <span class="dashboard-focus-icon">
                <i data-lucide="<?php echo htmlspecialchars($item['icon']); ?>" aria-hidden="true"></i>
            </span>
            <div class="dashboard-focus-copy">
                <strong><?php echo htmlspecialchars($item['label']); ?></strong>
                <span><?php echo htmlspecialchars($item['note']); ?></span>
            </div>
            <span class="dashboard-focus-value"><?php echo htmlspecialchars($item['value']); ?></span>
        </a>
        <?php endforeach; ?>
    </div>
</div>
