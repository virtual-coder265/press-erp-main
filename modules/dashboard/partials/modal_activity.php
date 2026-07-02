<?php
/**
 * Dashboard partial - Activity modal body.
 *
 * Component id: dashboard.modal.activity
 * Required context: $dashboardActivityItems
 */
?>
<div class="todo-modal-body"
     data-ajax-component="dashboard.modal.activity"
     data-ajax-refresh-on="modal-open:wsModalActivity,action:task.create,action:project.create"
     data-ajax-stale="20000">
    <p style="font-size:12px;color:#605e5c;margin:-4px 0 0;">
        The latest task and project movement requiring attention.
    </p>
    <?php if (!empty($dashboardActivityItems)): ?>
        <div class="dashboard-activity-list">
            <?php foreach ($dashboardActivityItems as $item): ?>
            <a href="<?php echo $item['href']; ?>" class="dashboard-activity-item">
                <div class="dashboard-activity-main">
                    <span class="dashboard-activity-mark is-<?php echo htmlspecialchars($item['tone']); ?>">
                        <i data-lucide="<?php echo htmlspecialchars($item['icon']); ?>" aria-hidden="true"></i>
                    </span>
                    <div class="dashboard-activity-copy">
                        <p class="dashboard-activity-title"><?php echo htmlspecialchars($item['title']); ?></p>
                        <p class="dashboard-activity-subtitle"><?php echo htmlspecialchars($item['subtitle']); ?></p>
                    </div>
                </div>
                <div class="dashboard-activity-value">
                    <?php echo htmlspecialchars($item['value']); ?>
                    <span class="dashboard-activity-meta"><?php echo htmlspecialchars($item['meta']); ?></span>
                </div>
            </a>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <div class="todo-empty">
            <i data-lucide="history" class="dashboard-lucide-lg" aria-hidden="true"></i>
            <p>No recent activity</p>
            <p style="font-size:12px;margin-top:4px;">New tasks and project updates will appear here automatically.</p>
        </div>
    <?php endif; ?>
</div>
