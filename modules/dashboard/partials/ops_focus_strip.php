<?php
/** @var array $dashboardFocusItems */
if (empty($dashboardFocusItems)) {
    return;
}
?>
<section class="dashboard-ops-focus"
         data-ajax-component="dashboard.ops.focus"
         data-ajax-poll="60000"
         data-ajax-refresh-on="focus,action:task.create,action:reminder.create"
         aria-label="Today's focus">
    <div class="dashboard-ops-focus-head">
        <div>
            <span class="dashboard-ops-focus-kicker">Today's Focus</span>
            <h2>Open the next priority fast</h2>
        </div>
    </div>
    <div class="dashboard-ops-focus-list">
        <?php foreach ($dashboardFocusItems as $item): ?>
            <?php
            $target = (string) ($item['target'] ?? '');
            $href = (string) ($item['href'] ?? '#');
            $isModal = $target !== '';
            ?>
            <?php if ($isModal): ?>
                <button type="button"
                        class="dashboard-ops-focus-item"
                        data-tone="<?php echo htmlspecialchars($item['tone']); ?>"
                        data-ws-open="<?php echo htmlspecialchars($target); ?>">
            <?php else: ?>
                <a href="<?php echo htmlspecialchars($href); ?>"
                   class="dashboard-ops-focus-item"
                   data-tone="<?php echo htmlspecialchars($item['tone']); ?>">
            <?php endif; ?>
                <span class="dashboard-ops-focus-icon">
                    <i data-lucide="<?php echo htmlspecialchars($item['icon']); ?>" aria-hidden="true"></i>
                </span>
                <span class="dashboard-ops-focus-copy">
                    <strong><?php echo htmlspecialchars($item['label']); ?></strong>
                    <span><?php echo htmlspecialchars($item['note']); ?></span>
                </span>
                <span class="dashboard-ops-focus-value"><?php echo htmlspecialchars($item['value']); ?></span>
            <?php if ($isModal): ?>
                </button>
            <?php else: ?>
                </a>
            <?php endif; ?>
        <?php endforeach; ?>
    </div>
</section>
