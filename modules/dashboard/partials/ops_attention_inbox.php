<?php
/** @var array $dashboardAttentionInbox */
?>
<section class="dashboard-ops-panel dashboard-ops-attention"
         data-ajax-component="dashboard.ops.attention"
         data-ajax-poll="120000"
         aria-label="Needs attention">
    <div class="dashboard-ops-panel-head">
        <div>
            <h2>Needs Attention</h2>
            <p>Prioritized items across approvals, receivables, production, and reminders.</p>
        </div>
    </div>

    <?php if (!empty($dashboardAttentionInbox)): ?>
        <div class="dashboard-ops-attention-list">
            <?php foreach ($dashboardAttentionInbox as $item): ?>
                <?php
                $modal = (string) ($item['modal'] ?? '');
                $href = (string) ($item['href'] ?? '#');
                ?>
                <?php if ($modal !== ''): ?>
                    <button type="button"
                            class="dashboard-ops-attention-item"
                            data-severity="<?php echo htmlspecialchars($item['severity']); ?>"
                            data-ws-open="<?php echo htmlspecialchars($modal); ?>">
                <?php else: ?>
                    <a href="<?php echo htmlspecialchars($href); ?>"
                       class="dashboard-ops-attention-item"
                       data-severity="<?php echo htmlspecialchars($item['severity']); ?>">
                <?php endif; ?>
                    <span class="dashboard-ops-attention-icon">
                        <i data-lucide="<?php echo htmlspecialchars($item['icon']); ?>" aria-hidden="true"></i>
                    </span>
                    <span class="dashboard-ops-attention-copy">
                        <span class="dashboard-ops-attention-top">
                            <span class="dashboard-ops-attention-type"><?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', (string) ($item['type'] ?? 'item')))); ?></span>
                            <span class="dashboard-ops-age"><?php echo htmlspecialchars($item['age_label']); ?></span>
                        </span>
                        <strong class="dashboard-ops-queue-title"><?php echo htmlspecialchars($item['title']); ?></strong>
                        <span class="dashboard-ops-queue-subtitle"><?php echo htmlspecialchars($item['subtitle']); ?></span>
                    </span>
                    <span class="dashboard-ops-queue-value"><?php echo htmlspecialchars($item['value']); ?></span>
                <?php if ($modal !== ''): ?>
                    </button>
                <?php else: ?>
                    </a>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <div class="dashboard-ops-empty">Nothing urgent is waiting right now. You're caught up.</div>
    <?php endif; ?>
</section>
