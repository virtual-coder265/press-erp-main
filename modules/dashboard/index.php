<?php
require_once __DIR__ . '/../../config/app.php';
checkAuth();
require_once __DIR__ . '/../../includes/dashboard_partials_helper.php';

// Legacy aliases for callers outside the dashboard module that still expect
// the original camelCase names.
if (!function_exists('calculateMoM')) {
    function calculateMoM($current, $previous)
    {
        return dashboardMoMGrowth($current, $previous);
    }
}
if (!function_exists('getGrowthColor')) {
    function getGrowthColor($growthStr)
    {
        return dashboardGrowthColor($growthStr);
    }
}
if (!function_exists('getGrowthIcon')) {
    function getGrowthIcon($growthStr)
    {
        return dashboardGrowthIcon($growthStr);
    }
}

// Dashboard data + UI context (helpers + queries live in
// includes/dashboard_partials_helper.php so the same arrays power both this
// initial render and modules/dashboard/fragments.php AJAX refreshes).
$dashboardContext = dashboard_collect_context($pdo, $_GET);
extract($dashboardContext, EXTR_SKIP);

// Re-expose the original calculateMoM-style call paths for any inline code
// further down. (Most of these were previously computed locally; the helper
// now owns them.)
$stats = $dashboardContext['stats'];

include '../../includes/header.php';
?>

<!-- Chart.js CDN (lazy-loaded when charts are present) -->
<script>
    window.dashboardCanViewRevenueChart = <?php echo !empty($dashboardCanViewRevenueChart) ? 'true' : 'false'; ?>;
    window.dashboardChartData = <?php echo !empty($dashboardCanViewRevenueChart) ? json_encode($chartData) : 'null'; ?>;
</script>

<?php $dashboardCssVersion = file_exists(ROOT_PATH . 'assets/css/dashboard.css') ? (string) filemtime(ROOT_PATH . 'assets/css/dashboard.css') : (string) time(); ?>
<link href="<?php echo asset('css/dashboard.css') . '?v=' . rawurlencode($dashboardCssVersion); ?>" rel="stylesheet">

<?php
// Workspace tile / preview / hero card / calendar values come from
// dashboard_collect_context() above. The legacy locals below are no longer
// required because extract() already populated $wsDashboardTiles, $wsSidebar,
// $dashboardHeroCards, $dashboardCalendar*, $dashboardBuildCalendarUrl, etc.
$dashboardFinanceHref = hasPermission('view_dashboard_revenue')
    ? BASE_URL . 'modules/sales/index'
    : (hasPermission('view_invoices') ? BASE_URL . 'modules/invoices/list' : '#');
$dashboardApprovalsHref = hasPermission('view_projects')
    ? BASE_URL . 'modules/projects/list'
    : (hasPermission('view_estimations')
        ? BASE_URL . 'modules/estimations/list'
        : (hasPermission('view_tasks') ? BASE_URL . 'modules/tasks/list' : '#'));
$dashboardReceivablesHref = hasPermission('view_dashboard_revenue')
    ? BASE_URL . 'modules/sales/index'
    : (hasPermission('view_invoices') ? BASE_URL . 'modules/invoices/list' : '#');
$dashboardQuickActionHref = hasPermission('manage_estimations')
    ? BASE_URL . 'modules/estimations/create'
    : (hasPermission('manage_invoices')
        ? BASE_URL . 'modules/sales/record_sale.php'
        : (hasPermission('manage_tasks') ? BASE_URL . 'modules/tasks/create' : BASE_URL . 'modules/tasks/list'));
$useLegacyDashboardShell = false;
$dashboardPanelOrderStyle = static function (string $key) use ($dashboardPanelOrder): string {
    $order = (int) ($dashboardPanelOrder[$key] ?? 99);
    return ' style="order:' . $order . '"';
};
?>

<div class="todo-shell dashboard-home-shell">
    <main class="todo-main todo-main--wide dashboard-home-main">
        <div class="dashboard-ops-shell">
            <section class="dashboard-ops-hero" aria-label="Operational dashboard overview">
                <div class="dashboard-ops-hero-copy">
                    <span class="dashboard-ops-kicker">
                        <i data-lucide="layout-dashboard" aria-hidden="true"></i>
                        Main Dashboard
                    </span>
                    <h1 class="dashboard-ops-title">
                        <?php echo htmlspecialchars($dashboardGreeting); ?>, <?php echo htmlspecialchars($_SESSION['user_name'] ?? 'there'); ?>
                    </h1>
                    <p class="dashboard-ops-subtitle">
                        <?php echo htmlspecialchars($dashboardPersonaLabel ?? 'Operations'); ?> workspace · Here's what needs attention today.
                    </p>
                </div>

                <div class="dashboard-ops-hero-side">
                    <div class="dashboard-ops-date-card">
                        <span class="dashboard-ops-date-label">Today</span>
                        <strong class="dashboard-ops-date-value"><?php echo htmlspecialchars($dashboardTodayDateLabel); ?></strong>
                        <span class="dashboard-ops-date-meta"><?php echo htmlspecialchars($dashboardTodayWeekday); ?></span>
                    </div>

                    <div class="dashboard-ops-action-row">
                        <a href="<?php echo htmlspecialchars($dashboardQuickActionHref); ?>"
                           class="dashboard-ops-action is-primary">
                            <i data-lucide="zap" aria-hidden="true"></i>
                            Quick Actions
                        </a>
                        <a href="<?php echo BASE_URL; ?>modules/reports/index"
                           class="dashboard-ops-action">
                            <i data-lucide="bar-chart-3" aria-hidden="true"></i>
                            Reports
                        </a>
                        <a href="<?php echo BASE_URL; ?>modules/tasks/list"
                           class="dashboard-ops-action">
                            <i data-lucide="history" aria-hidden="true"></i>
                            Activity
                        </a>
                    </div>
                </div>
            </section>

            <div class="dashboard-ops-panels-stack">
            <?php if (!empty($dashboardPrimaryCards)): ?>
                <div data-ajax-component="dashboard.ops.kpi" data-ajax-refresh="120000"<?php echo $dashboardPanelOrderStyle('kpis'); ?>>
                    <?php include __DIR__ . '/partials/ops_kpi_grid.php'; ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($dashboardWorkOrdersPanel['available'])): ?>
            <section class="dashboard-ops-panel-grid" aria-label="Work order overview"<?php echo $dashboardPanelOrderStyle('work_orders'); ?>>
                    <div class="dashboard-ops-panel">
                        <div class="dashboard-ops-panel-head">
                            <div>
                                <h2>Work Orders</h2>
                                <p>View and manage production job workflows.</p>
                            </div>
                            <?php if (!empty($dashboardWorkOrdersPanel['href'])): ?>
                                <a href="<?php echo htmlspecialchars($dashboardWorkOrdersPanel['href']); ?>" class="dashboard-ops-link">
                                    Open Work Orders
                                    <i data-lucide="arrow-up-right" aria-hidden="true"></i>
                                </a>
                            <?php endif; ?>
                        </div>
                        <div class="dashboard-ops-panel-body">
                            <div class="dashboard-ops-wo-kpis">
                                <div class="dashboard-ops-wo-kpi">
                                    <span>In production</span>
                                    <strong><?php echo number_format((int) ($dashboardWorkOrdersPanel['in_production'] ?? 0)); ?></strong>
                                </div>
                                <div class="dashboard-ops-wo-kpi">
                                    <span>Awaiting dispatch</span>
                                    <strong><?php echo number_format((int) ($dashboardWorkOrdersPanel['awaiting_dispatch'] ?? 0)); ?></strong>
                                </div>
                                <div class="dashboard-ops-wo-kpi">
                                    <span>Overdue</span>
                                    <strong><?php echo number_format((int) ($dashboardWorkOrdersPanel['overdue'] ?? 0)); ?></strong>
                                </div>
                                <div class="dashboard-ops-wo-kpi">
                                    <span>Urgent</span>
                                    <strong><?php echo number_format((int) ($dashboardWorkOrdersPanel['urgent'] ?? 0)); ?></strong>
                                </div>
                            </div>
                            <?php if (!empty($dashboardWorkOrdersPanel['active_queue'])): ?>
                                <div class="dashboard-ops-queue">
                                    <?php foreach ($dashboardWorkOrdersPanel['active_queue'] as $job): ?>
                                        <a href="<?php echo BASE_URL; ?>modules/work_orders/view?id=<?php echo (int) $job['id']; ?>" class="dashboard-ops-queue-item">
                                            <span class="dashboard-ops-queue-icon">
                                                <i data-lucide="briefcase" aria-hidden="true"></i>
                                            </span>
                                            <span class="dashboard-ops-queue-copy">
                                                <span class="dashboard-ops-queue-top">
                                                    <span class="dashboard-ops-queue-type"><?php echo htmlspecialchars($job['work_order_number'] ?? ''); ?></span>
                                                    <span class="dashboard-ops-status is-<?php echo htmlspecialchars($job['due_tone'] ?? 'neutral'); ?>">
                                                        <?php echo htmlspecialchars($job['due_label'] ?? ''); ?>
                                                    </span>
                                                </span>
                                                <strong class="dashboard-ops-queue-title"><?php echo htmlspecialchars($job['customer_name'] ?: 'No customer'); ?></strong>
                                                <span class="dashboard-ops-queue-subtitle">
                                                    <?php echo htmlspecialchars($job['status'] ?? ''); ?>
                                                    <?php if (!empty($job['department_name'])): ?>
                                                        · <?php echo htmlspecialchars($job['department_name']); ?>
                                                    <?php endif; ?>
                                                </span>
                                            </span>
                                            <span class="dashboard-ops-queue-value"><?php echo htmlspecialchars($job['priority'] ?? ''); ?></span>
                                        </a>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <p class="dashboard-ops-empty-note">No active jobs in production right now.</p>
                            <?php endif; ?>
                        </div>
                    </div>
            </section>
            <?php endif; ?>

            <section class="dashboard-ops-panel-grid" aria-label="Financial and operational summaries"<?php echo $dashboardPanelOrderStyle('finance'); ?>>
                <?php if (!empty($dashboardFinanceRows)): ?>
                    <div class="dashboard-ops-panel">
                        <div class="dashboard-ops-panel-head">
                            <div>
                                <h2>Financial Summary (MTD)</h2>
                                <p>Financial summary for the current month.</p>
                            </div>
                            <?php if ($dashboardFinanceHref !== '#'): ?>
                                <a href="<?php echo htmlspecialchars($dashboardFinanceHref); ?>" class="dashboard-ops-link">
                                    View report
                                    <i data-lucide="arrow-up-right" aria-hidden="true"></i>
                                </a>
                            <?php endif; ?>
                        </div>
                        <div class="dashboard-ops-finance">
                            <?php foreach ($dashboardFinanceRows as $row): ?>
                                <?php
                                $changeValue = strtolower((string) $row['change']);
                                $changeClass = 'dashboard-ops-finance-change';
                                if (strpos($changeValue, '-') !== false || strpos($changeValue, 'overdue') !== false) {
                                    $changeClass .= ' is-negative';
                                } elseif (strpos($changeValue, '+') !== false || strpos($changeValue, 'on track') !== false) {
                                    $changeClass .= ' is-positive';
                                }
                                ?>
                                <div class="dashboard-ops-finance-row">
                                    <span class="dashboard-ops-finance-label"><?php echo htmlspecialchars($row['label']); ?></span>
                                    <strong class="dashboard-ops-finance-value"><?php echo htmlspecialchars($row['value']); ?></strong>
                                    <span class="<?php echo htmlspecialchars($changeClass); ?>"><?php echo htmlspecialchars($row['change']); ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if (!empty($dashboardCanViewRevenueChart)): ?>
                <div class="dashboard-ops-panel">
                    <div class="dashboard-ops-panel-head">
                        <div>
                            <h2>Revenue Trend</h2>
                            <p>Last 6 months of invoiced value.</p>
                        </div>
                        <a href="<?php echo BASE_URL; ?>modules/reports/sales"
                           class="dashboard-ops-link">
                            Open reports
                            <i data-lucide="arrow-up-right" aria-hidden="true"></i>
                        </a>
                    </div>
                    <div class="dashboard-ops-chart-meta">
                        <div class="dashboard-ops-chart-stat">
                            <span>Latest Month</span>
                            <strong><?php echo htmlspecialchars($latestTrendLabel); ?></strong>
                        </div>
                        <div class="dashboard-ops-chart-stat">
                            <span>Revenue</span>
                            <strong>MK <?php echo htmlspecialchars(dashboardCurrency($latestRevenueTrend)); ?></strong>
                        </div>
                        <div class="dashboard-ops-chart-stat">
                            <span>Collected</span>
                            <strong>MK <?php echo htmlspecialchars(dashboardCurrency($latestCollectedTrend)); ?></strong>
                        </div>
                    </div>
                    <div class="dashboard-ops-inline-chart">
                        <canvas id="dashboardInlineRevenueTrend" aria-label="Revenue trend chart"></canvas>
                    </div>
                </div>
                <?php endif; ?>

                <?php if (!empty($dashboardMaterialsSnapshot)): ?>
                    <div class="dashboard-ops-panel">
                        <div class="dashboard-ops-panel-head">
                            <div>
                                <h2>Materials Snapshot</h2>
                                <p>Latest costing rates for core production items.</p>
                            </div>
                            <a href="<?php echo BASE_URL; ?>modules/materials/list" class="dashboard-ops-link">
                                View all
                                <i data-lucide="arrow-up-right" aria-hidden="true"></i>
                            </a>
                        </div>
                        <div class="dashboard-ops-material-list">
                            <?php foreach ($dashboardMaterialsSnapshot as $item): ?>
                                <a href="<?php echo htmlspecialchars($item['href']); ?>" class="dashboard-ops-material-item">
                                    <span class="dashboard-ops-material-copy">
                                        <strong class="dashboard-ops-material-title"><?php echo htmlspecialchars($item['name']); ?></strong>
                                        <span class="dashboard-ops-material-meta">
                                            <?php echo htmlspecialchars($item['unit']); ?>
                                            <?php if (!empty($item['description_excerpt'])): ?>
                                                - <?php echo htmlspecialchars($item['description_excerpt']); ?>
                                            <?php else: ?>
                                                - <?php echo htmlspecialchars($item['effective_copy']); ?>
                                            <?php endif; ?>
                                        </span>
                                    </span>
                                    <span class="dashboard-ops-material-rate">
                                        <strong class="dashboard-ops-queue-title"><?php echo htmlspecialchars($item['rate_label']); ?></strong>
                                        <span class="dashboard-ops-status is-<?php echo htmlspecialchars($item['status_tone']); ?>">
                                            <?php echo htmlspecialchars($item['status_label']); ?>
                                        </span>
                                    </span>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </section>

            <?php if ($dashboardCanViewDebtorsPanel): ?>
            <?php
            $debtorsPanelTotalBalance = max(0, (float) ($dashboardReceivablesSummary['total_balance'] ?? 0));
            $debtorsAgingPercent = static function (float $bucketBalance) use ($debtorsPanelTotalBalance): int {
                if ($debtorsPanelTotalBalance <= 0) {
                    return 0;
                }

                return (int) min(100, round(($bucketBalance / $debtorsPanelTotalBalance) * 100));
            };
            $debtorsBalance030 = (float) ($dashboardReceivablesSummary['balance_0_30'] ?? 0);
            $debtorsBalance3160 = (float) ($dashboardReceivablesSummary['balance_31_60'] ?? 0);
            $debtorsBalance61Plus = (float) ($dashboardReceivablesSummary['balance_61_plus'] ?? 0);
            ?>
            <section class="dashboard-ops-debtors-section" aria-label="Debtors follow-up"<?php echo $dashboardPanelOrderStyle('debtors'); ?>>
                    <div class="dashboard-ops-panel dashboard-ops-panel-debtors">
                        <div class="dashboard-ops-panel-head dashboard-ops-debtors-head">
                            <div class="dashboard-ops-debtors-head-title">
                                <span class="dashboard-ops-debtors-head-icon" aria-hidden="true">
                                    <i data-lucide="file-text"></i>
                                </span>
                                <div>
                                    <h2>Quick Debtors Summary</h2>
                                    <p>Snapshot of outstanding receivables, aging breakdown, and key accounts needing follow-up.</p>
                                </div>
                            </div>
                            <?php if ($dashboardReceivablesHref !== '#'): ?>
                                <a href="<?php echo htmlspecialchars($dashboardReceivablesHref); ?>" class="dashboard-ops-link">
                                    View all debtors
                                    <i data-lucide="arrow-up-right" aria-hidden="true"></i>
                                </a>
                            <?php endif; ?>
                        </div>

                        <div class="dashboard-ops-debtors-kpis" aria-label="Debtors key metrics">
                            <div class="dashboard-ops-debtors-kpi is-total">
                                <span class="dashboard-ops-debtors-kpi-icon" aria-hidden="true">
                                    <i data-lucide="wallet"></i>
                                </span>
                                <div class="dashboard-ops-debtors-kpi-copy">
                                    <span>Total outstanding</span>
                                    <strong>MK <?php echo htmlspecialchars(dashboardCurrency($dashboardReceivablesSummary['total_balance'] ?? 0)); ?></strong>
                                </div>
                            </div>
                            <div class="dashboard-ops-debtors-kpi is-overdue<?php echo ((int) ($dashboardReceivablesSummary['overdue'] ?? 0)) > 0 ? ' has-count' : ''; ?>">
                                <span class="dashboard-ops-debtors-kpi-icon" aria-hidden="true">
                                    <i data-lucide="file-warning"></i>
                                </span>
                                <div class="dashboard-ops-debtors-kpi-copy">
                                    <span>Overdue invoices</span>
                                    <strong><?php echo number_format((int) ($dashboardReceivablesSummary['overdue'] ?? 0)); ?></strong>
                                    <span class="dashboard-ops-debtors-kpi-sub">MK <?php echo htmlspecialchars(dashboardCurrency($dashboardReceivablesSummary['overdue_balance'] ?? 0)); ?></span>
                                </div>
                            </div>
                            <div class="dashboard-ops-debtors-kpi is-critical<?php echo $dashboardDebtorsCriticalCount > 0 ? ' has-count' : ''; ?>">
                                <span class="dashboard-ops-debtors-kpi-icon" aria-hidden="true">
                                    <i data-lucide="alert-triangle"></i>
                                </span>
                                <div class="dashboard-ops-debtors-kpi-copy">
                                    <span>Critical (61+ days)</span>
                                    <strong><?php echo number_format($dashboardDebtorsCriticalCount); ?></strong>
                                    <span class="dashboard-ops-debtors-kpi-sub">61+ days outstanding</span>
                                </div>
                            </div>
                            <div class="dashboard-ops-debtors-kpi is-avg-age">
                                <span class="dashboard-ops-debtors-kpi-icon" aria-hidden="true">
                                    <i data-lucide="calendar-clock"></i>
                                </span>
                                <div class="dashboard-ops-debtors-kpi-copy">
                                    <span>Avg. debt age</span>
                                    <strong><?php echo number_format((int) ($dashboardReceivablesSummary['avg_age_days'] ?? 0)); ?> days</strong>
                                </div>
                            </div>
                        </div>

                        <div class="dashboard-ops-debtors-aging-bar" aria-label="Aging breakdown by balance">
                            <div class="dashboard-ops-debtors-aging-segment is-0-30">
                                <div class="dashboard-ops-debtors-aging-top">
                                    <span class="dashboard-ops-debtors-aging-icon" aria-hidden="true">
                                        <i data-lucide="clock"></i>
                                    </span>
                                    <span class="dashboard-ops-debtors-aging-label">0-30 days</span>
                                </div>
                                <strong>MK <?php echo htmlspecialchars(dashboardCurrency($debtorsBalance030)); ?></strong>
                                <em><?php echo number_format((int) ($dashboardReceivablesSummary['age_0_30'] ?? 0)); ?> invoice(s)</em>
                                <div class="dashboard-ops-debtors-aging-progress" aria-hidden="true">
                                    <div class="dashboard-ops-debtors-aging-fill" style="width: <?php echo $debtorsAgingPercent($debtorsBalance030); ?>%;"></div>
                                </div>
                            </div>
                            <div class="dashboard-ops-debtors-aging-segment is-31-60">
                                <div class="dashboard-ops-debtors-aging-top">
                                    <span class="dashboard-ops-debtors-aging-icon" aria-hidden="true">
                                        <i data-lucide="clock"></i>
                                    </span>
                                    <span class="dashboard-ops-debtors-aging-label">31-60 days</span>
                                </div>
                                <strong>MK <?php echo htmlspecialchars(dashboardCurrency($debtorsBalance3160)); ?></strong>
                                <em><?php echo number_format((int) ($dashboardReceivablesSummary['age_31_60'] ?? 0)); ?> invoice(s)</em>
                                <div class="dashboard-ops-debtors-aging-progress" aria-hidden="true">
                                    <div class="dashboard-ops-debtors-aging-fill" style="width: <?php echo $debtorsAgingPercent($debtorsBalance3160); ?>%;"></div>
                                </div>
                            </div>
                            <div class="dashboard-ops-debtors-aging-segment is-61-plus">
                                <div class="dashboard-ops-debtors-aging-top">
                                    <span class="dashboard-ops-debtors-aging-icon" aria-hidden="true">
                                        <i data-lucide="clock"></i>
                                    </span>
                                    <span class="dashboard-ops-debtors-aging-label">61+ days</span>
                                </div>
                                <strong>MK <?php echo htmlspecialchars(dashboardCurrency($debtorsBalance61Plus)); ?></strong>
                                <em><?php echo number_format((int) ($dashboardReceivablesSummary['age_61_plus'] ?? 0)); ?> invoice(s)</em>
                                <div class="dashboard-ops-debtors-aging-progress" aria-hidden="true">
                                    <div class="dashboard-ops-debtors-aging-fill" style="width: <?php echo $debtorsAgingPercent($debtorsBalance61Plus); ?>%;"></div>
                                </div>
                            </div>
                        </div>

                        <?php if (!empty($dashboardDebtors)): ?>
                            <div class="dashboard-ops-debtors-table-head" aria-hidden="true">
                                <span>Debtor</span>
                                <span>Aging</span>
                                <span>Balance</span>
                                <span>Actions</span>
                            </div>
                            <div class="dashboard-ops-debtor-list">
                                <?php foreach ($dashboardDebtors as $debtor): ?>
                                    <?php
                                    $debtorName = (string) ($debtor['debtor_name'] ?? 'Unknown debtor');
                                    $days = (int) ($debtor['max_age_days'] ?? 0);
                                    $balanceVal = (float) ($debtor['balance'] ?? 0);
                                    $ageMeta = dashboardDebtAgeMeta($days);
                                    $debtorInitials = dashboardDebtorInitials($debtorName);
                                    $balanceTone = in_array($ageMeta['tone'], ['danger', 'critical'], true)
                                        ? 'danger'
                                        : ($ageMeta['tone'] === 'warning' ? 'warning' : 'success');
                                    $invoiceLookupUrl = BASE_URL . 'modules/invoices/list?' . http_build_query(['search' => $debtorName]);
                                    $latestInvoiceUrl = !empty($debtor['latest_invoice_id'])
                                        ? BASE_URL . 'modules/invoices/view?id=' . (int) $debtor['latest_invoice_id']
                                        : $invoiceLookupUrl;
                                    $recordPaymentUrl = !empty($debtor['latest_invoice_id'])
                                        ? BASE_URL . 'modules/invoices/record_payment?id=' . (int) $debtor['latest_invoice_id']
                                        : BASE_URL . 'modules/invoices/list';

                                    $reminderTitle = 'Follow up debtor: ' . $debtorName;
                                    $reminderNote = 'Outstanding balance MK ' . dashboardCurrency($balanceVal)
                                        . ' across ' . $debtor['invoice_count'] . ' invoice(s). Debt age: ' . $ageMeta['label'] . '.';
                                    ?>
                                    <div class="dashboard-ops-debtor-item dashboard-ops-debtor-item--interactive">
                                        <div class="dashboard-ops-debtor-info">
                                            <span class="dashboard-ops-debtor-avatar is-<?php echo htmlspecialchars($ageMeta['tone']); ?>" aria-hidden="true">
                                                <?php echo htmlspecialchars($debtorInitials); ?>
                                            </span>
                                            <div class="dashboard-ops-debtor-copy">
                                                <strong class="dashboard-ops-debtor-name"><?php echo htmlspecialchars($debtorName); ?></strong>
                                                <span class="dashboard-ops-debtor-meta">
                                                    <?php echo htmlspecialchars((string) ($debtor['invoice_count'] ?? 0)); ?> invoice(s)
                                                </span>
                                            </div>
                                        </div>
                                        <div class="dashboard-ops-debtor-badge-wrap">
                                            <span class="dashboard-ops-debtor-badge is-<?php echo htmlspecialchars($ageMeta['tone']); ?>">
                                                <?php echo htmlspecialchars($ageMeta['label']); ?>
                                            </span>
                                        </div>
                                        <div class="dashboard-ops-debtor-value-wrap">
                                            <strong class="dashboard-ops-debtor-balance is-<?php echo htmlspecialchars($balanceTone); ?>">
                                                MK <?php echo htmlspecialchars(dashboardCurrency($balanceVal)); ?>
                                            </strong>
                                        </div>
                                        <div class="dashboard-ops-debtor-actions">
                                            <a href="<?php echo htmlspecialchars($latestInvoiceUrl); ?>" class="dashboard-ops-debtor-btn text-teal" title="Open latest invoice">
                                                <i data-lucide="eye" aria-hidden="true"></i>
                                            </a>
                                            <a href="<?php echo htmlspecialchars($invoiceLookupUrl); ?>" class="dashboard-ops-debtor-btn text-blue" title="View all invoices">
                                                <i data-lucide="list" aria-hidden="true"></i>
                                            </a>
                                            <button class="dashboard-ops-debtor-btn text-amber"
                                                    data-action-modal="reminder.create"
                                                    data-action-option-title="<?php echo htmlspecialchars($reminderTitle); ?>"
                                                    data-action-option-remind-at="<?php echo htmlspecialchars($dashboardDebtorsReminderAt); ?>"
                                                    data-action-option-note="<?php echo htmlspecialchars($reminderNote); ?>"
                                                    title="Create follow-up reminder">
                                                <i data-lucide="bell" aria-hidden="true"></i>
                                            </button>
                                            <a href="<?php echo htmlspecialchars($recordPaymentUrl); ?>" class="dashboard-ops-debtor-btn text-emerald" title="Record payment">
                                                <i data-lucide="wallet" aria-hidden="true"></i>
                                            </a>
                                            <?php if (!empty($debtor['customer_email'])): ?>
                                                <a href="mailto:<?php echo htmlspecialchars($debtor['customer_email']); ?>?subject=Outstanding%20Payment%20Follow-up&body=Dear%20Customer,%0D%0A%0D%0AThis%20is%20a%20friendly%20follow-up%20regarding%20outstanding%20invoices%20with%20a%20total%20outstanding%20balance%20of%20MK%20<?php echo urlencode(dashboardCurrency($balanceVal)); ?>.%20Please%20find%20details%20in%20the%20system%20or%20contact%20us%20for%20assistance.%0D%0A%0D%0ABest%20regards,"
                                                   class="dashboard-ops-debtor-btn text-indigo"
                                                   title="Email: <?php echo htmlspecialchars($debtor['customer_email']); ?>">
                                                    <i data-lucide="mail" aria-hidden="true"></i>
                                                </a>
                                            <?php endif; ?>
                                            <?php if (!empty($debtor['customer_phone'])): ?>
                                                <a href="tel:<?php echo htmlspecialchars($debtor['customer_phone']); ?>"
                                                   class="dashboard-ops-debtor-btn text-violet"
                                                   title="Call: <?php echo htmlspecialchars($debtor['customer_phone']); ?>">
                                                    <i data-lucide="phone" aria-hidden="true"></i>
                                                </a>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="dashboard-ops-empty">No outstanding debtors are currently on the board.</div>
                        <?php endif; ?>

                        <div class="dashboard-ops-debtors-footer-actions">
                            <a href="<?php echo BASE_URL; ?>modules/invoices/list?status=Overdue" class="dashboard-ops-debtors-footer-btn">
                                <i data-lucide="alert-circle" aria-hidden="true"></i>
                                Overdue invoices
                            </a>
                            <a href="<?php echo BASE_URL; ?>modules/invoices/list" class="dashboard-ops-debtors-footer-btn">
                                <i data-lucide="receipt" aria-hidden="true"></i>
                                All open invoices
                            </a>
                            <?php if ($dashboardReceivablesHref !== '#'): ?>
                                <a href="<?php echo htmlspecialchars($dashboardReceivablesHref); ?>" class="dashboard-ops-debtors-footer-btn is-primary">
                                    <i data-lucide="bar-chart-3" aria-hidden="true"></i>
                                    Receivables report
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
            </section>
            <?php endif; ?>

            <section class="dashboard-ops-panel" aria-label="Pending approvals"<?php echo $dashboardPanelOrderStyle('approvals'); ?>>
                <div class="dashboard-ops-panel-head">
                    <div>
                        <h2>Pending Approvals</h2>
                        <p>Queues that still need attention before work can move forward.</p>
                    </div>
                    <?php if ($dashboardApprovalsHref !== '#'): ?>
                        <a href="<?php echo htmlspecialchars($dashboardApprovalsHref); ?>" class="dashboard-ops-link">
                            View all
                            <i data-lucide="arrow-up-right" aria-hidden="true"></i>
                        </a>
                    <?php endif; ?>
                </div>

                <?php if (!empty($dashboardPendingApprovals)): ?>
                    <div class="dashboard-ops-queue">
                        <?php foreach ($dashboardPendingApprovals as $item): ?>
                            <a href="<?php echo htmlspecialchars($item['href']); ?>" class="dashboard-ops-queue-item">
                                <span class="dashboard-ops-queue-icon">
                                    <i data-lucide="<?php echo htmlspecialchars($item['icon']); ?>" aria-hidden="true"></i>
                                </span>
                                <span>
                                    <span class="dashboard-ops-queue-top">
                                        <span class="dashboard-ops-queue-type"><?php echo htmlspecialchars($item['type']); ?></span>
                                        <span class="dashboard-ops-age"><?php echo htmlspecialchars($item['age_label']); ?></span>
                                    </span>
                                    <strong class="dashboard-ops-queue-title"><?php echo htmlspecialchars($item['title']); ?></strong>
                                    <span class="dashboard-ops-queue-subtitle"><?php echo htmlspecialchars($item['subtitle']); ?></span>
                                </span>
                                <span class="dashboard-ops-queue-value"><?php echo htmlspecialchars($item['value']); ?></span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="dashboard-ops-empty">No approvals are waiting in the currently visible queues.</div>
                <?php endif; ?>
            </section>

            <section class="dashboard-ops-activity" aria-label="Recent activity"<?php echo $dashboardPanelOrderStyle('activity'); ?>>
                <div class="dashboard-ops-activity-head">
                    <div>
                        <h2>Recent Activity</h2>
                        <p>Recent work movement across your visible tasks and projects.</p>
                    </div>
                    <a href="<?php echo BASE_URL; ?>modules/tasks/list"
                       class="dashboard-ops-link">
                        View all
                        <i data-lucide="arrow-up-right" aria-hidden="true"></i>
                    </a>
                </div>

                <?php if (!empty($dashboardActivityItems)): ?>
                    <div class="dashboard-ops-activity-list">
                        <?php foreach ($dashboardActivityItems as $item): ?>
                            <a href="<?php echo htmlspecialchars($item['href']); ?>" class="dashboard-ops-activity-item">
                                <span class="dashboard-ops-activity-top">
                                    <span class="dashboard-ops-activity-icon">
                                        <i data-lucide="<?php echo htmlspecialchars($item['icon']); ?>" aria-hidden="true"></i>
                                    </span>
                                    <span class="dashboard-ops-age"><?php echo htmlspecialchars($item['value']); ?></span>
                                </span>
                                <strong class="dashboard-ops-activity-title"><?php echo htmlspecialchars($item['title']); ?></strong>
                                <span class="dashboard-ops-activity-subtitle"><?php echo htmlspecialchars($item['subtitle']); ?></span>
                                <span class="dashboard-ops-activity-subtitle"><?php echo htmlspecialchars($item['meta']); ?></span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="dashboard-ops-empty">Recent activity will appear here once new operational updates are logged.</div>
                <?php endif; ?>
            </section>
            </div>
        </div>

        <?php if ($useLegacyDashboardShell): ?>
        <section class="dashboard-hero-card" id="dashboardHeroCard" aria-label="Dashboard hero">
            <?php include __DIR__ . '/partials/hero_greeting.php'; ?>

            <aside class="dashboard-hero-aside" aria-label="Current weather and forecast">
                <div class="weather-card weather-card--current" id="weatherCurrentCard" data-state="loading">
                    <div class="weather-card__head">
                        <span class="weather-card__kicker">Current Weather</span>
                        <button type="button"
                                class="weather-card__edit"
                                id="weatherCityEditBtn"
                                aria-label="Change city"
                                aria-expanded="false">
                            <i data-lucide="map-pinned" aria-hidden="true"></i>
                        </button>
                    </div>
                    <div class="weather-card__city">
                        <i data-lucide="map-pin" aria-hidden="true"></i>
                        <span id="weatherCity">Locating…</span>
                    </div>
                    <div class="weather-card__body">
                        <div class="weather-card__readings">
                            <div class="weather-card__temp">
                                <strong id="weatherTemp">--</strong><sup>°C</sup>
                            </div>
                            <span class="weather-card__condition" id="weatherCondition">--</span>
                            <span class="weather-card__description" id="weatherDescription">Fetching live conditions…</span>
                        </div>
                        <span class="weather-card__icon" id="weatherIcon" aria-hidden="true">
                            <i data-lucide="cloud" aria-hidden="true"></i>
                        </span>
                    </div>
                    <div class="weather-card__chips">
                        <span class="weather-chip" title="Humidity">
                            <i data-lucide="droplet" aria-hidden="true"></i>
                            Humidity: <strong id="weatherHumidity">--</strong>
                        </span>
                        <span class="weather-chip" title="Wind">
                            <i data-lucide="wind" aria-hidden="true"></i>
                            Wind: <strong id="weatherWind">--</strong>
                        </span>
                        <span class="weather-chip" title="Rain chance">
                            <i data-lucide="umbrella" aria-hidden="true"></i>
                            Rain chance: <strong id="weatherRain">--</strong>
                        </span>
                    </div>
                    <form class="weather-card__editor" id="weatherCityForm" hidden autocomplete="off">
                        <label for="weatherCityInput" class="sr-only">Search city</label>
                        <div class="weather-editor-field">
                            <i data-lucide="search" aria-hidden="true"></i>
                            <input type="text"
                                   id="weatherCityInput"
                                   name="city"
                                   placeholder="Search city (e.g. Zomba)"
                                   maxlength="80"
                                   autocomplete="off">
                        </div>
                        <ul class="weather-editor-results" id="weatherCityResults" role="listbox"></ul>
                        <div class="weather-editor-actions">
                            <button type="button" class="weather-editor-btn weather-editor-btn--ghost" id="weatherUseLocation">
                                <i data-lucide="locate-fixed" aria-hidden="true"></i>
                                Use my location
                            </button>
                            <button type="button" class="weather-editor-btn" id="weatherCityCancel">Cancel</button>
                        </div>
                        <p class="weather-editor-hint" id="weatherEditorHint" aria-live="polite"></p>
                    </form>
                </div>

                <div class="weather-card weather-card--forecast" id="weatherForecastCard" data-state="loading">
                    <span class="weather-card__kicker">Next Hours Forecast</span>
                    <div class="weather-forecast-grid" id="weatherForecastGrid">
                        <?php for ($fi = 0; $fi < 4; $fi++): ?>
                            <div class="weather-forecast-slot" data-slot="<?php echo $fi; ?>">
                                <span class="weather-forecast-slot__time">--:--</span>
                                <span class="weather-forecast-slot__icon" aria-hidden="true">
                                    <i data-lucide="cloud" aria-hidden="true"></i>
                                </span>
                                <span class="weather-forecast-slot__temp">--°</span>
                            </div>
                        <?php endfor; ?>
                    </div>
                </div>
            </aside>
        </section>

        <?php include __DIR__ . '/partials/hero_metrics.php'; ?>

        <section class="dashboard-priority-grid" aria-label="Dashboard priorities">
            <?php include __DIR__ . '/partials/focus_list.php'; ?>
            <?php include __DIR__ . '/partials/workspace_tiles.php'; ?>
        </section>
        <?php endif; ?>

        <?php if ($search_query): ?>
            <div class="todo-modal" style="max-width:none;max-height:none;margin-bottom:16px;">
                <div class="todo-modal-header">
                    <h3 class="todo-modal-title">Search results for "<?php echo htmlspecialchars($search_query); ?>"</h3>
                    <a href="<?php echo BASE_URL; ?>modules/dashboard/index" class="todo-btn-ghost">
                        <i data-lucide="x" class="inline-icon" aria-hidden="true"></i> Clear
                    </a>
                </div>
                <div class="todo-modal-body">
                    <?php if (!empty($search_results)): ?>
                        <?php foreach ($search_results as $result): ?>
                            <?php
                            $typeMeta = [
                                'estimation' => ['label' => 'Estimation', 'href' => BASE_URL . 'modules/estimations/view?id=' . $result['id']],
                                'invoice' => ['label' => 'Invoice', 'href' => BASE_URL . 'modules/invoices/list'],
                                'user' => ['label' => 'User', 'href' => BASE_URL . 'modules/hr/users/edit?id=' . $result['id']],
                            ][$result['type']] ?? ['label' => 'Result', 'href' => '#'];
                            ?>
                            <a href="<?php echo $typeMeta['href']; ?>" class="todo-row">
                                <span class="todo-row-leading"><i data-lucide="search" aria-hidden="true"></i></span>
                                <div class="todo-row-content">
                                    <div class="todo-row-title"><?php echo htmlspecialchars($result['title']); ?></div>
                                    <div class="todo-row-meta">
                                        <span class="meta-item"><i data-lucide="tag" aria-hidden="true"></i> <?php echo $typeMeta['label']; ?></span>
                                        <span class="meta-item"><i data-lucide="calendar" aria-hidden="true"></i> <?php echo date('M j, Y', strtotime($result['created_at'])); ?></span>
                                        <span class="meta-item"><?php echo htmlspecialchars($result['subtitle']); ?></span>
                                    </div>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="todo-empty">
                            <i data-lucide="search-x" class="dashboard-lucide-lg" aria-hidden="true"></i>
                            <p>No matches found.</p>
                            <p style="font-size:12px;margin-top:4px;">Try a different invoice number, customer or job description.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </main>

    <?php if ($useLegacyDashboardShell): ?>
    <aside class="dashboard-calendar-sidebar" aria-label="Dashboard calendar utility">
        <?php include __DIR__ . '/partials/calendar.php'; ?>
        <?php include __DIR__ . '/partials/schedule.php'; ?>
        <?php include __DIR__ . '/partials/debtors_panel.php'; ?>
    </aside>
    <?php endif; ?>
</div>

<?php /* -----------------------------------------------------------------
   Workspace modals
   Each modal houses content that used to be rendered inline.
   ----------------------------------------------------------------- */ ?>

<!-- Performance modal -->
<div class="todo-modal-overlay" id="wsModalPerformance" role="dialog" aria-labelledby="wsModalPerformanceTitle" aria-hidden="true">
    <div class="todo-modal todo-modal--lg">
        <div class="todo-modal-header">
            <h3 class="todo-modal-title" id="wsModalPerformanceTitle">Performance snapshot</h3>
            <button class="todo-modal-close" data-ws-close aria-label="Close">&times;</button>
        </div>
        <?php include __DIR__ . '/partials/modal_performance.php'; ?>
    </div>
</div>

<!-- Activity modal -->
<div class="todo-modal-overlay" id="wsModalActivity" role="dialog" aria-labelledby="wsModalActivityTitle" aria-hidden="true">
    <div class="todo-modal todo-modal--lg">
        <div class="todo-modal-header">
            <h3 class="todo-modal-title" id="wsModalActivityTitle">Recent activity</h3>
            <button class="todo-modal-close" data-ws-close aria-label="Close">&times;</button>
        </div>
        <?php include __DIR__ . '/partials/modal_activity.php'; ?>
        <div class="todo-modal-footer">
            <?php if (hasPermission('view_tasks')): ?>
                <a href="<?php echo BASE_URL; ?>modules/tasks/list?my_tasks=1" class="todo-btn-ghost">Assigned Tasks</a>
            <?php endif; ?>
            <?php if (hasPermission('view_projects')): ?>
                <a href="<?php echo BASE_URL; ?>modules/projects/list" class="todo-btn-ghost">All Projects</a>
            <?php endif; ?>
            <button type="button" class="todo-btn-primary" data-ws-close>Close</button>
        </div>
    </div>
</div>

<!-- Reports modal -->
<div class="todo-modal-overlay" id="wsModalReports" role="dialog" aria-labelledby="wsModalReportsTitle" aria-hidden="true">
    <div class="todo-modal todo-modal--xl">
        <div class="todo-modal-header">
            <h3 class="todo-modal-title" id="wsModalReportsTitle">Reports</h3>
            <button class="todo-modal-close" data-ws-close aria-label="Close">&times;</button>
        </div>
        <?php include __DIR__ . '/partials/modal_reports.php'; ?>
        <div class="todo-modal-footer">
            <button type="button" class="todo-btn-primary" data-ws-close>Close</button>
        </div>
    </div>
</div>

<!-- Projects modal -->
<div class="todo-modal-overlay" id="wsModalProjects" role="dialog" aria-labelledby="wsModalProjectsTitle" aria-hidden="true">
    <div class="todo-modal todo-modal--lg">
        <div class="todo-modal-header">
            <h3 class="todo-modal-title" id="wsModalProjectsTitle">Projects</h3>
            <button class="todo-modal-close" data-ws-close aria-label="Close">&times;</button>
        </div>
        <?php include __DIR__ . '/partials/modal_projects.php'; ?>
        <div class="todo-modal-footer">
            <a href="<?php echo BASE_URL; ?>modules/projects/list" class="todo-btn-ghost">Open Projects workspace</a>
            <?php if (hasPermission('manage_projects')): ?>
                <a href="<?php echo BASE_URL; ?>modules/projects/create" class="todo-btn-primary">
                    <i data-lucide="plus" class="inline-icon" aria-hidden="true"></i> New Project
                </a>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Tasks modal -->
<div class="todo-modal-overlay" id="wsModalTasks" role="dialog" aria-labelledby="wsModalTasksTitle" aria-hidden="true">
    <div class="todo-modal todo-modal--xl">
        <div class="todo-modal-header">
            <h3 class="todo-modal-title" id="wsModalTasksTitle">Tasks</h3>
            <button class="todo-modal-close" data-ws-close aria-label="Close">&times;</button>
        </div>
        <?php include __DIR__ . '/partials/modal_tasks.php'; ?>
        <div class="todo-modal-footer">
            <a href="<?php echo BASE_URL; ?>modules/tasks/list" class="todo-btn-ghost">Assigned Tasks</a>
            <?php if (hasPermission('manage_tasks')): ?>
                <a href="<?php echo BASE_URL; ?>modules/tasks/create" class="todo-btn-primary">
                    <i data-lucide="plus" class="inline-icon" aria-hidden="true"></i> New Task
                </a>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Reminders modal -->
<div class="todo-modal-overlay" id="wsModalReminders" role="dialog" aria-labelledby="wsModalRemindersTitle" aria-hidden="true">
    <div class="todo-modal todo-modal--lg">
        <div class="todo-modal-header">
            <h3 class="todo-modal-title" id="wsModalRemindersTitle">Reminder board</h3>
            <button class="todo-modal-close" data-ws-close aria-label="Close">&times;</button>
        </div>
        <?php include __DIR__ . '/partials/modal_reminders.php'; ?>
        <div class="todo-modal-footer">
            <a href="<?php echo BASE_URL; ?>modules/reminders/index?scope=personal" class="todo-btn-primary">
                <i data-lucide="external-link" class="inline-icon" aria-hidden="true"></i> Open Reminder Hub
            </a>
        </div>
    </div>
</div>

<!-- Quick Actions modal -->
<div class="todo-modal-overlay" id="wsModalQuickActions" role="dialog" aria-labelledby="wsModalQuickActionsTitle" aria-hidden="true">
    <div class="todo-modal todo-modal--lg">
        <div class="todo-modal-header">
            <h3 class="todo-modal-title" id="wsModalQuickActionsTitle">Quick actions</h3>
            <button class="todo-modal-close" data-ws-close aria-label="Close">&times;</button>
        </div>
        <?php include __DIR__ . '/partials/modal_quick_actions.php'; ?>
        <div class="todo-modal-footer">
            <button type="button" class="todo-btn-primary" data-ws-close>Close</button>
        </div>
    </div>
</div>

<!-- Initialize Charts Script -->
<script>
    document.addEventListener("DOMContentLoaded", function () {
        if (typeof window.refreshAppShellIcons === 'function') {
            window.refreshAppShellIcons();
        }
        const chartData = window.dashboardChartData || {};
        const hasRevenueChart = !!document.getElementById('dashboardInlineRevenueTrend');

        function loadChartJs(callback) {
            if (typeof Chart !== 'undefined') {
                callback();
                return;
            }
            const script = document.createElement('script');
            script.src = 'https://cdn.jsdelivr.net/npm/chart.js';
            script.onload = callback;
            document.head.appendChild(script);
        }

        const currencyFormat = new Intl.NumberFormat('en-US', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });

        const compactCurrencyFormat = new Intl.NumberFormat('en-US', {
            notation: 'compact',
            maximumFractionDigits: 1
        });

        function updateDashboardClock() {
            const clockTime = document.getElementById('dashboardClockTime');
            const clockDate = document.getElementById('dashboardClockDate');
            if (!clockTime || !clockDate) {
                return;
            }

            const now = new Date();
            const liveTime = new Intl.DateTimeFormat(undefined, {
                hour: '2-digit',
                minute: '2-digit',
                second: '2-digit'
            }).format(now);
            clockTime.textContent = liveTime;

            clockDate.textContent = new Intl.DateTimeFormat(undefined, {
                weekday: 'long',
                day: 'numeric',
                month: 'long',
                year: 'numeric'
            }).format(now);
        }

        updateDashboardClock();
        setInterval(updateDashboardClock, 1000);

        function startDashboardCharts() {
        Chart.defaults.color = '#5f6f82';
        Chart.defaults.font.family = '"Plus Jakarta Sans", "Segoe UI", sans-serif';
        Chart.defaults.plugins.legend.labels.usePointStyle = true;
        Chart.defaults.plugins.legend.labels.boxWidth = 10;
        Chart.defaults.plugins.legend.labels.boxHeight = 10;

        const centerTextPlugin = {
            id: 'dashboardCenterText',
            afterDraw(chart, args, options) {
                const cfg = chart?.options?.plugins?.dashboardCenterText;
                if (!cfg || chart.config.type !== 'doughnut') {
                    return;
                }

                const meta = chart.getDatasetMeta(0);
                if (!meta?.data?.length) {
                    return;
                }

                const ctx = chart.ctx;
                const x = meta.data[0].x;
                const y = meta.data[0].y;

                ctx.save();
                ctx.textAlign = 'center';
                ctx.fillStyle = '#122033';
                ctx.font = '700 24px "Plus Jakarta Sans", "Segoe UI", sans-serif';
                ctx.fillText(cfg.text || '', x, y - 4);
                ctx.fillStyle = '#7a8ea2';
                ctx.font = '600 11px "Plus Jakarta Sans", "Segoe UI", sans-serif';
                ctx.fillText(cfg.subtext || '', x, y + 16);
                ctx.restore();
            }
        };

        Chart.register(centerTextPlugin);

        function withOpacity(hex, opacity) {
            const value = hex.replace('#', '');
            const bigint = parseInt(value, 16);
            const r = (bigint >> 16) & 255;
            const g = (bigint >> 8) & 255;
            const b = bigint & 255;
            return `rgba(${r}, ${g}, ${b}, ${opacity})`;
        }

        function makeVerticalGradient(canvas, color) {
            const ctx = canvas.getContext('2d');
            const gradient = ctx.createLinearGradient(0, 0, 0, canvas.height || 320);
            gradient.addColorStop(0, withOpacity(color, 0.28));
            gradient.addColorStop(1, withOpacity(color, 0.02));
            return gradient;
        }

        function chartGrid() {
            return {
                color: 'rgba(148, 163, 184, 0.14)',
                drawBorder: false
            };
        }

        function axisText() {
            return {
                color: '#7a8ea2',
                font: {
                    size: 11,
                    weight: '600'
                }
            };
        }

        function destroyDashboardCharts(scopeRoot) {
            if (typeof Chart === 'undefined' || typeof Chart.getChart !== 'function' || !scopeRoot) {
                return;
            }
            scopeRoot.querySelectorAll('canvas').forEach(function (canvas) {
                var ch = Chart.getChart(canvas);
                if (ch) {
                    ch.destroy();
                }
            });
        }

        function bootstrapDashboardCharts(scopeRoot) {
            scopeRoot = scopeRoot || document;
            destroyDashboardCharts(scopeRoot);

        const trendCanvas = scopeRoot.querySelector('#trendChart');
        if (trendCanvas) {
            const greenGradient = makeVerticalGradient(trendCanvas, '#22c55e');
            const accentGradient = makeVerticalGradient(trendCanvas, '#0f766e');

            new Chart(trendCanvas, {
                type: 'line',
                data: {
                    labels: chartData.months,
                    datasets: [
                        {
                            label: 'Estimations Created',
                            data: chartData.estimations_trend,
                            borderColor: '#22c55e',
                            backgroundColor: greenGradient,
                            pointBackgroundColor: '#22c55e',
                            pointBorderColor: '#ffffff',
                            pointBorderWidth: 2,
                            pointRadius: 4,
                            pointHoverRadius: 5,
                            borderWidth: 3,
                            tension: 0.36,
                            fill: true
                        },
                        {
                            label: 'Invoices Generated',
                            data: chartData.invoices_trend,
                            borderColor: '#0f766e',
                            backgroundColor: accentGradient,
                            pointBackgroundColor: '#0f766e',
                            pointBorderColor: '#ffffff',
                            pointBorderWidth: 2,
                            pointRadius: 4,
                            pointHoverRadius: 5,
                            borderWidth: 3,
                            tension: 0.36,
                            fill: true
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    interaction: {
                        mode: 'index',
                        intersect: false
                    },
                    plugins: {
                        legend: {
                            position: 'top',
                            align: 'start'
                        },
                        tooltip: {
                            backgroundColor: 'rgba(15, 23, 42, 0.92)',
                            padding: 12,
                            titleFont: { weight: '700' },
                            bodyFont: { weight: '600' }
                        }
                    },
                    scales: {
                        x: {
                            grid: { display: false },
                            ticks: axisText()
                        },
                        y: {
                            beginAtZero: true,
                            grid: chartGrid(),
                            ticks: {
                                ...axisText(),
                                precision: 0
                            }
                        }
                    }
                }
            });
        }

        const revenueCanvas = scopeRoot.querySelector('#revenueChart');
        if (revenueCanvas) {
            new Chart(revenueCanvas, {
                type: 'bar',
                data: {
                    labels: chartData.months,
                    datasets: [
                        {
                            label: 'Invoiced (MK)',
                            data: chartData.revenue_trend,
                            backgroundColor: 'rgba(16, 185, 129, 0.72)',
                            borderColor: '#10b981',
                            borderWidth: 1,
                            borderRadius: 12,
                            maxBarThickness: 24,
                        },
                        {
                            label: 'Collected (MK)',
                            data: chartData.collected_trend,
                            backgroundColor: 'rgba(13, 148, 136, 0.72)',
                            borderColor: '#0d9488',
                            borderWidth: 1,
                            borderRadius: 12,
                            maxBarThickness: 24,
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'top',
                            align: 'start'
                        },
                        tooltip: {
                            backgroundColor: 'rgba(15, 23, 42, 0.92)',
                            padding: 12,
                            callbacks: {
                                label: function (ctx) {
                                    return ctx.dataset.label + ': MK ' + currencyFormat.format(ctx.parsed.y || 0);
                                }
                            }
                        }
                    },
                    scales: {
                        x: {
                            grid: { display: false },
                            ticks: axisText()
                        },
                        y: {
                            beginAtZero: true,
                            grid: chartGrid(),
                            ticks: {
                                ...axisText(),
                                callback: function (val) {
                                    return 'MK ' + compactCurrencyFormat.format(val);
                                }
                            }
                        }
                    }
                }
            });
        }

        const invoiceCanvas = scopeRoot.querySelector('#invoiceChart');
        if (invoiceCanvas) {
            const invoiceValues = Object.values(chartData.invoice_status);
            new Chart(invoiceCanvas, {
                type: 'doughnut',
                data: {
                    labels: Object.keys(chartData.invoice_status),
                    datasets: [{
                        data: invoiceValues,
                        backgroundColor: [
                            '#22c55e',
                            '#ef4444',
                            '#eab308',
                            '#0f766e',
                            '#94a3b8'
                        ],
                        borderWidth: 0,
                        hoverOffset: 8
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    cutout: '72%',
                    plugins: {
                        legend: {
                            position: 'bottom'
                        },
                        tooltip: {
                            backgroundColor: 'rgba(15, 23, 42, 0.92)',
                            padding: 12
                        },
                        dashboardCenterText: {
                            text: String(invoiceValues.reduce((sum, value) => sum + value, 0)),
                            subtext: 'Invoices'
                        }
                    }
                }
            });
        }

        const projectCanvas = scopeRoot.querySelector('#projectChart');
        if (projectCanvas) {
            const projectValues = Object.values(chartData.project_status);
            new Chart(projectCanvas, {
                type: 'doughnut',
                data: {
                    labels: Object.keys(chartData.project_status),
                    datasets: [{
                        data: projectValues,
                        backgroundColor: [
                            '#0f766e',
                            '#22c55e',
                            '#6b7280',
                            '#f59e0b',
                            '#ef4444'
                        ],
                        borderWidth: 0,
                        hoverOffset: 8
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    cutout: '72%',
                    plugins: {
                        legend: {
                            position: 'bottom'
                        },
                        tooltip: {
                            backgroundColor: 'rgba(15, 23, 42, 0.92)',
                            padding: 12
                        },
                        dashboardCenterText: {
                            text: String(projectValues.reduce((sum, value) => sum + value, 0)),
                            subtext: 'Projects'
                        }
                    }
                }
            });
        }
        }

        function bootstrapInlineRevenueTrend() {
            const inlineCanvas = document.getElementById('dashboardInlineRevenueTrend');
            if (!inlineCanvas || typeof Chart === 'undefined') {
                return;
            }

            if (typeof Chart.getChart === 'function') {
                const existingChart = Chart.getChart(inlineCanvas);
                if (existingChart) {
                    existingChart.destroy();
                }
            }

            const revenueGradient = makeVerticalGradient(inlineCanvas, '#0f766e');
            const trendLabels = (chartData.months || []).map(function (label) {
                return String(label || '').split(' ')[0] || label;
            });

            new Chart(inlineCanvas, {
                type: 'line',
                data: {
                    labels: trendLabels,
                    datasets: [{
                        label: 'Revenue',
                        data: chartData.revenue_trend,
                        borderColor: '#0f766e',
                        backgroundColor: revenueGradient,
                        pointBackgroundColor: '#0f766e',
                        pointBorderColor: '#ffffff',
                        pointBorderWidth: 2,
                        pointRadius: 4,
                        pointHoverRadius: 5,
                        borderWidth: 3,
                        tension: 0.35,
                        fill: true
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            display: false
                        },
                        tooltip: {
                            backgroundColor: 'rgba(15, 23, 42, 0.92)',
                            padding: 12,
                            callbacks: {
                                label: function (ctx) {
                                    return 'MK ' + currencyFormat.format(ctx.parsed.y || 0);
                                }
                            }
                        }
                    },
                    scales: {
                        x: {
                            grid: { display: false },
                            ticks: axisText()
                        },
                        y: {
                            beginAtZero: true,
                            grid: chartGrid(),
                            ticks: {
                                ...axisText(),
                                callback: function (val) {
                                    return 'MK ' + compactCurrencyFormat.format(val);
                                }
                            }
                        }
                    }
                }
            });
        }

        document.addEventListener('ajax:component:rendered', function (ev) {
            var detail = ev && ev.detail;
            if (!detail || detail.id !== 'dashboard.modal.reports' || !detail.root) {
                return;
            }
            bootstrapDashboardCharts(detail.root);
        });

        if (typeof window.registerWorkspaceModal === 'function') {
            window.registerWorkspaceModal('wsModalReports', function (modalEl) {
                bootstrapDashboardCharts(modalEl || document);
            });
        } else {
            bootstrapDashboardCharts(document);
        }

        bootstrapInlineRevenueTrend();
        }

        const chartCanvases = document.querySelector('#trendChart, #invoiceStatusChart, #projectStatusChart, #dashboardInlineRevenueTrend, #estimationsTrendChart');
        if (chartCanvases) {
            loadChartJs(startDashboardCharts);
        }
    });
</script>

<script>
(function () {
    var statIds = {
        estimations:     'stat-estimations',
        invoices:        'stat-invoices',
        unpaid_invoices: 'stat-unpaid-invoices',
        active_projects: 'stat-active-projects',
        dispatched:      'stat-dispatched',
        users:           'stat-users',
        total_revenue:   'stat-total-revenue',
        collected:       'stat-collected',
        outstanding:     'stat-outstanding',
    };

    function fmt(val) {
        return parseFloat(val).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function refreshStats() {
        $.getJSON('<?php echo BASE_URL; ?>modules/dashboard/stats', function (d) {
            if (!d || !d.success) { return; }

            var currency = ['total_revenue', 'collected', 'outstanding'];

            Object.keys(statIds).forEach(function (key) {
                var el = document.getElementById(statIds[key]);
                if (!el) { return; }
                el.textContent = currency.indexOf(key) !== -1 ? fmt(d[key]) : d[key];
            });

            // Update the two badge spans inside the Outstanding card
            var ppEl = document.getElementById('stat-partially-paid');
            if (ppEl) { ppEl.textContent = d.partially_paid + ' Partially Paid'; }

            var ubEl = document.getElementById('stat-unpaid-badge');
            if (ubEl) { ubEl.textContent = d.unpaid_invoices + ' Unpaid'; }
        });
    }

    setInterval(function () {
        if (!document.hidden) {
            refreshStats();
        }
    }, 30000);
})();
</script>

<script>
    // ===== Dashboard hero weather widget =====
    (function () {
        const BASE_URL = '<?php echo BASE_URL; ?>';
        const WEATHER_ENDPOINT = BASE_URL + 'modules/dashboard/weather';
        const HERO_BG_BASE = BASE_URL + 'assets/images/weather-illustrations/';
        const STORAGE_KEY = 'dashboardWeatherCity';
        const REFRESH_MS = 15 * 60 * 1000;

        const HERO_DEFAULTS = {
            name: 'Lilongwe, Malawi',
            latitude: -13.9626,
            longitude: 33.7741,
            timezone: 'Africa/Blantyre',
        };

        // Open-Meteo WMO weather codes mapped to label, Lucide icon name, and a "group"
        // used to choose hero backgrounds. `nightIcon` (when defined) replaces `icon`
        // after sunset so we don't show a sun glyph in a 22:00 forecast slot.
        const WMO = {
            0: { label: 'Clear sky', icon: 'sun', nightIcon: 'moon', group: 'clear', desc: 'Bright skies and steady visibility.' },
            1: { label: 'Mainly Clear', icon: 'sun', nightIcon: 'moon', group: 'clear', desc: 'Mostly sunny with light cloud movement.' },
            2: { label: 'Partly Cloudy', icon: 'cloud-sun', nightIcon: 'cloud-moon', group: 'cloud-partial', desc: 'Calm conditions with soft cloud cover.' },
            3: { label: 'Overcast', icon: 'cloud', group: 'cloud-overcast', desc: 'Grey, even cloud cover throughout the day.' },
            45: { label: 'Foggy', icon: 'cloud-fog', group: 'fog', desc: 'Reduced visibility, gentle drift of fog.' },
            48: { label: 'Rime Fog', icon: 'cloud-fog', group: 'fog', desc: 'Dense, frosty fog hugging the area.' },
            51: { label: 'Light Drizzle', icon: 'cloud-drizzle', group: 'rain', desc: 'Light, intermittent drizzle.' },
            53: { label: 'Drizzle', icon: 'cloud-rain', group: 'rain', desc: 'Steady moderate drizzle.' },
            55: { label: 'Heavy Drizzle', icon: 'cloud-rain', group: 'rain', desc: 'Persistent heavy drizzle.' },
            56: { label: 'Freezing Drizzle', icon: 'thermometer-snowflake', group: 'rain', desc: 'Freezing drizzle, careful out there.' },
            57: { label: 'Freezing Drizzle', icon: 'thermometer-snowflake', group: 'rain', desc: 'Heavy freezing drizzle.' },
            61: { label: 'Light Rain', icon: 'cloud-drizzle', group: 'rain', desc: 'Soft, steady rainfall.' },
            63: { label: 'Rain', icon: 'cloud-rain', group: 'rain', desc: 'Moderate, consistent rainfall.' },
            65: { label: 'Heavy Rain', icon: 'cloud-rain', group: 'rain', desc: 'Heavy rain, expect surface water.' },
            66: { label: 'Freezing Rain', icon: 'cloud-hail', group: 'rain', desc: 'Freezing rain — watch for ice.' },
            67: { label: 'Freezing Rain', icon: 'cloud-hail', group: 'rain', desc: 'Heavy freezing rain — caution outdoors.' },
            71: { label: 'Light Snow', icon: 'snowflake', group: 'snow', desc: 'Gentle snowfall.' },
            73: { label: 'Snow', icon: 'snowflake', group: 'snow', desc: 'Steady snowfall.' },
            75: { label: 'Heavy Snow', icon: 'snowflake', group: 'snow', desc: 'Heavy snow accumulation likely.' },
            77: { label: 'Snow Grains', icon: 'snowflake', group: 'snow', desc: 'Fine snow grains.' },
            80: { label: 'Rain Showers', icon: 'cloud-rain', group: 'rain', desc: 'Passing rain showers.' },
            81: { label: 'Rain Showers', icon: 'cloud-rain', group: 'rain', desc: 'Heavier passing showers.' },
            82: { label: 'Violent Showers', icon: 'cloud-lightning', group: 'storm', desc: 'Intense, heavy showers.' },
            85: { label: 'Snow Showers', icon: 'snowflake', group: 'snow', desc: 'Brief bursts of snow.' },
            86: { label: 'Heavy Snow Showers', icon: 'snowflake', group: 'snow', desc: 'Heavy bursts of snow.' },
            95: { label: 'Thunderstorm', icon: 'cloud-lightning', group: 'storm', desc: 'Thunderstorm activity, take cover.' },
            96: { label: 'Storm with Hail', icon: 'cloud-lightning', group: 'storm', desc: 'Thunderstorm with hail.' },
            99: { label: 'Severe Storm', icon: 'cloud-lightning', group: 'storm', desc: 'Severe thunderstorm with hail.' },
        };

        function getWmo(code) {
            if (code === null || code === undefined) {
                return WMO[0];
            }
            return WMO[code] || { label: 'Weather', icon: 'cloud', group: 'cloud-partial', desc: 'Live weather snapshot.' };
        }

        function iconFor(wmo, isDay) {
            if (!wmo) return 'cloud';
            return isDay === false && wmo.nightIcon ? wmo.nightIcon : wmo.icon;
        }

        function pickBackgroundFile(hour, isDay, group) {
            const h = hour;
            if (!isDay) {
                if (group === 'storm') return 'lighting-and-thurnder.png';
                if (group === 'clear') return 'clear-night.png';
                return 'partially-cloud-night.png';
            }
            if (group === 'storm') {
                if (h >= 17 && h <= 20) return 'lighting-and-thurnder.png';
                return 'thundery.png';
            }
            if (group === 'snow') {
                if (h >= 17 && h <= 19) return 'sunset.png';
                return 'cloudy-day.png';
            }
            if (group === 'fog') {
                if (h <= 10) return 'morning-calm.png';
                if (h >= 17 && h <= 19) return 'sunset.png';
                return 'cloudy-day.png';
            }
            if (group === 'rain') {
                if (h >= 14 && h <= 18) return '3pm-cloudy-afternoon.jpg';
                if (h >= 17 && h <= 19) return 'sunset.png';
                return 'cloudy-day.png';
            }
            if (group === 'cloud-overcast') {
                if (h >= 14 && h <= 18) return '3pm-cloudy-afternoon.jpg';
                return 'cloudy-day.png';
            }
            if (group === 'cloud-partial') {
                if (h <= 10) return '10m-partially-cloudy.jpg';
                if (h >= 11 && h <= 13) return 'partialy-cloud-noon.png';
                if (h >= 14 && h <= 16) return 'partially-cloudy-afternoon.png';
                if (h >= 17 && h <= 19) return 'sunset.png';
                return 'partially-cloud-day.png';
            }
            if (group === 'clear') {
                if (h <= 11) return 'morning-calm.png';
                if (h >= 12 && h <= 13) return 'clyde-rs-4XbZCfU2Uoo-unsplash.jpg';
                if (h >= 14 && h <= 16) return 'yellow-late-afternoon.jpg';
                if (h >= 17 && h <= 19) return 'sunset.png';
                return 'clear-night.png';
            }
            return 'cloudy-day.png';
        }

        // Admin-curated hero backgrounds. `heroConfig.backgrounds` maps
        // "group:daypart" -> absolute URL, while the global toggle decides
        // whether any background swap happens at all. Defaults assume the
        // feature is on with no overrides, so the bundled illustrations show
        // until the live config arrives (or if the request fails).
        const HERO_DAYPARTS_ORDER = ['morning', 'noon', 'afternoon', 'sunset', 'night'];
        let heroConfig = { enabled: true, backgrounds: {} };
        let heroConfigLoaded = false;

        function computeDaypart(hour, isDay) {
            if (!isDay) return 'night';
            if (hour <= 10) return 'morning';
            if (hour <= 13) return 'noon';
            if (hour <= 16) return 'afternoon';
            if (hour <= 19) return 'sunset';
            return 'night';
        }

        async function fetchHeroConfig() {
            try {
                const res = await fetch(WEATHER_ENDPOINT + '?action=hero_config', { credentials: 'same-origin' });
                if (!res.ok) return;
                const payload = await res.json();
                heroConfig = {
                    enabled: payload && payload.enabled !== false,
                    backgrounds: payload && payload.backgrounds && typeof payload.backgrounds === 'object'
                        ? payload.backgrounds : {}
                };
                heroConfigLoaded = true;
            } catch (e) {
                // Fail open: keep using the bundled defaults.
            }
        }

        function applyHeroBackground(hero, hour, isDay, group) {
            if (!hero) return;

            if (heroConfigLoaded && heroConfig.enabled === false) {
                hero.style.setProperty('--hero-bg-img', 'none');
                return;
            }

            const daypart = computeDaypart(hour, isDay);
            const slotKey = group + ':' + daypart;
            const override = heroConfig.backgrounds ? heroConfig.backgrounds[slotKey] : null;

            let url;
            if (override) {
                url = override;
            } else {
                url = HERO_BG_BASE + encodeURIComponent(pickBackgroundFile(hour, isDay, group));
            }

            hero.style.setProperty(
                '--hero-bg-img',
                "linear-gradient(135deg, rgba(15, 23, 42, 0.45), rgba(31, 41, 55, 0.45)), url('" + url + "')"
            );
        }

        function readStoredCity() {
            try {
                const raw = localStorage.getItem(STORAGE_KEY);
                if (!raw) return null;
                const parsed = JSON.parse(raw);
                if (parsed && typeof parsed.latitude === 'number' && typeof parsed.longitude === 'number') {
                    return parsed;
                }
            } catch (e) { /* ignore */ }
            return null;
        }

        function writeStoredCity(city) {
            try {
                localStorage.setItem(STORAGE_KEY, JSON.stringify(city));
            } catch (e) { /* ignore quota */ }
        }

        function clearStoredCity() {
            try { localStorage.removeItem(STORAGE_KEY); } catch (e) { /* ignore */ }
        }

        function formatHour(date) {
            return date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', hour12: false });
        }

        function detectViaGeolocation(timeoutMs) {
            return new Promise(function (resolve) {
                if (!('geolocation' in navigator)) {
                    resolve(null);
                    return;
                }
                let settled = false;
                const timer = setTimeout(function () {
                    if (!settled) { settled = true; resolve(null); }
                }, timeoutMs);
                navigator.geolocation.getCurrentPosition(
                    function (pos) {
                        if (settled) return;
                        settled = true;
                        clearTimeout(timer);
                        resolve({
                            name: 'Your location',
                            latitude: pos.coords.latitude,
                            longitude: pos.coords.longitude,
                            timezone: 'auto',
                            isAuto: true,
                        });
                    },
                    function () {
                        if (settled) return;
                        settled = true;
                        clearTimeout(timer);
                        resolve(null);
                    },
                    { enableHighAccuracy: false, timeout: timeoutMs, maximumAge: 10 * 60 * 1000 }
                );
            });
        }

        async function fetchForecast(city) {
            const params = new URLSearchParams({
                action: 'forecast',
                lat: String(city.latitude),
                lon: String(city.longitude),
                tz: city.timezone || 'auto',
            });
            const res = await fetch(WEATHER_ENDPOINT + '?' + params.toString(), { credentials: 'same-origin' });
            if (!res.ok) {
                throw new Error('Weather request failed (' + res.status + ').');
            }
            return res.json();
        }

        async function fetchGeocode(query) {
            const params = new URLSearchParams({ action: 'geocode', q: query });
            const res = await fetch(WEATHER_ENDPOINT + '?' + params.toString(), { credentials: 'same-origin' });
            if (!res.ok) {
                throw new Error('Geocoding failed (' + res.status + ').');
            }
            return res.json();
        }

        async function fetchReverse(lat, lon) {
            const params = new URLSearchParams({
                action: 'reverse',
                lat: String(lat),
                lon: String(lon),
            });
            const res = await fetch(WEATHER_ENDPOINT + '?' + params.toString(), { credentials: 'same-origin' });
            if (!res.ok) {
                throw new Error('Reverse lookup failed (' + res.status + ').');
            }
            return res.json();
        }

        async function enrichWithReverse(city) {
            // Auto-detected positions only carry lat/lon. Fetch the actual locality
            // name so the UI can show "Lilongwe, Malawi" instead of "Your location".
            if (!city || !city.isAuto) return city;
            try {
                const result = await fetchReverse(city.latitude, city.longitude);
                if (result && result.name) {
                    return Object.assign({}, city, {
                        name: result.name,
                        admin1: result.admin1 || '',
                        country: result.country || '',
                        country_code: result.country_code || '',
                        isAuto: true,
                    });
                }
            } catch (err) {
                console.warn('[weather] reverse geocoding failed', err);
            }
            return city;
        }

        function setText(id, value) {
            const el = document.getElementById(id);
            if (el) el.textContent = value;
        }

        function setIcon(host, iconName) {
            if (!host) return;
            host.innerHTML = '<i data-lucide="' + iconName + '" aria-hidden="true"></i>';
            if (typeof window.refreshAppShellIcons === 'function') {
                window.refreshAppShellIcons();
            }
        }

        function describeCity(city) {
            const parts = [];
            if (city.name) parts.push(city.name);
            if (city.admin1 && city.admin1 !== city.name) parts.push(city.admin1);
            if (city.country) parts.push(city.country);
            return parts.join(', ');
        }

        function renderForecast(payload, hero) {
            const current = payload.current || {};
            const wmo = getWmo(current.weather_code);
            const currentIsDay = current.is_day === undefined ? true : !!current.is_day;

            // Current card
            const currentCard = document.getElementById('weatherCurrentCard');
            if (currentCard) currentCard.dataset.state = 'ready';

            setText('weatherTemp', current.temperature !== null && current.temperature !== undefined
                ? Math.round(current.temperature) : '--');
            setText('weatherCondition', wmo.label);
            setText('weatherDescription', wmo.desc);
            setText('weatherHumidity', current.humidity !== null && current.humidity !== undefined
                ? current.humidity + '%' : '--');
            setText('weatherWind', current.wind_speed !== null && current.wind_speed !== undefined
                ? Math.round(current.wind_speed) + ' km/h' : '--');

            // Rain chance: use the upcoming hour's precipitation_probability if available,
            // else fall back to the precipitation reading.
            let rainChance = null;
            const next = (payload.hourly_next4 || [])[0];
            if (next && next.precipitation_probability !== null && next.precipitation_probability !== undefined) {
                rainChance = next.precipitation_probability + '%';
            } else if (current.precipitation !== null && current.precipitation !== undefined) {
                rainChance = current.precipitation > 0 ? Math.round(current.precipitation) + ' mm' : '0%';
            }
            setText('weatherRain', rainChance || '--');

            setIcon(document.getElementById('weatherIcon'), iconFor(wmo, currentIsDay));

            // Forecast slots
            const slots = document.querySelectorAll('#weatherForecastGrid .weather-forecast-slot');
            (payload.hourly_next4 || []).slice(0, 4).forEach(function (slot, idx) {
                const node = slots[idx];
                if (!node) return;
                const slotWmo = getWmo(slot.weather_code);
                const slotIsDay = slot.is_day === undefined ? true : !!slot.is_day;
                const t = node.querySelector('.weather-forecast-slot__time');
                const ic = node.querySelector('.weather-forecast-slot__icon');
                const tp = node.querySelector('.weather-forecast-slot__temp');
                if (t) {
                    const stamp = String(slot.time || '');
                    t.textContent = stamp.length >= 16 ? stamp.slice(11, 16) : '--:--';
                }
                if (ic) setIcon(ic, iconFor(slotWmo, slotIsDay));
                if (tp) tp.textContent = slot.temperature !== null && slot.temperature !== undefined
                    ? Math.round(slot.temperature) + '°C' : '--°';
            });

            const forecastCard = document.getElementById('weatherForecastCard');
            if (forecastCard) forecastCard.dataset.state = 'ready';

            // Background mapping based on the timezone-aware current time.
            let hour = new Date().getHours();
            const stamp = String(current.time || '');
            if (stamp.length >= 13) {
                const parsed = parseInt(stamp.slice(11, 13), 10);
                if (!Number.isNaN(parsed)) hour = parsed;
            }
            applyHeroBackground(hero, hour, currentIsDay, wmo.group);
        }

        function renderError(message) {
            setText('weatherCondition', 'Weather unavailable');
            setText('weatherDescription', message || 'Unable to reach the live weather service.');
            const currentCard = document.getElementById('weatherCurrentCard');
            if (currentCard) currentCard.dataset.state = 'error';
        }

        function setCityLabel(city) {
            const label = describeCity(city) || city.name || 'Lilongwe, Malawi';
            setText('weatherCity', label);
        }

        async function loadAndRender(city, hero) {
            try {
                setCityLabel(city);
                const payload = await fetchForecast(city);
                renderForecast(payload, hero);
            } catch (err) {
                console.warn('[weather] forecast fetch failed', err);
                renderError(err && err.message ? err.message : '');
            }
        }

        function debounce(fn, wait) {
            let t = null;
            return function () {
                const args = arguments;
                clearTimeout(t);
                t = setTimeout(function () { fn.apply(null, args); }, wait);
            };
        }

        function initEditor(state) {
            const editBtn = document.getElementById('weatherCityEditBtn');
            const form = document.getElementById('weatherCityForm');
            const input = document.getElementById('weatherCityInput');
            const list = document.getElementById('weatherCityResults');
            const cancel = document.getElementById('weatherCityCancel');
            const useLoc = document.getElementById('weatherUseLocation');
            const hint = document.getElementById('weatherEditorHint');

            if (!editBtn || !form || !input || !list) return;

            function open() {
                form.hidden = false;
                editBtn.setAttribute('aria-expanded', 'true');
                if (hint) hint.textContent = '';
                list.innerHTML = '';
                input.value = '';
                setTimeout(function () { input.focus(); }, 30);
            }

            function close() {
                form.hidden = true;
                editBtn.setAttribute('aria-expanded', 'false');
                list.innerHTML = '';
            }

            editBtn.addEventListener('click', function () {
                if (form.hidden) open(); else close();
            });

            if (cancel) cancel.addEventListener('click', close);

            const runSearch = debounce(async function (query) {
                if (!query || query.length < 2) {
                    list.innerHTML = '';
                    if (hint) hint.textContent = '';
                    return;
                }
                if (hint) hint.textContent = 'Searching…';
                try {
                    const data = await fetchGeocode(query);
                    list.innerHTML = '';
                    const results = (data && data.results) || [];
                    if (!results.length) {
                        if (hint) hint.textContent = 'No matches found.';
                        return;
                    }
                    if (hint) hint.textContent = '';
                    results.forEach(function (row) {
                        const li = document.createElement('li');
                        li.className = 'weather-editor-result';
                        li.tabIndex = 0;
                        li.setAttribute('role', 'option');
                        const region = [row.admin1, row.country].filter(Boolean).join(', ');
                        li.innerHTML = '<span>' + escapeHtml(row.name) + '</span>'
                            + (region ? '<small>' + escapeHtml(region) + '</small>' : '');
                        li.addEventListener('click', function () {
                            const city = {
                                name: row.name,
                                admin1: row.admin1 || '',
                                country: row.country || '',
                                latitude: row.latitude,
                                longitude: row.longitude,
                                timezone: row.timezone || 'auto',
                            };
                            writeStoredCity(city);
                            state.city = city;
                            close();
                            loadAndRender(city, state.hero);
                        });
                        list.appendChild(li);
                    });
                } catch (err) {
                    if (hint) hint.textContent = 'Search failed. Try again.';
                }
            }, 280);

            input.addEventListener('input', function () {
                runSearch(input.value.trim());
            });

            if (useLoc) {
                useLoc.addEventListener('click', async function () {
                    if (hint) hint.textContent = 'Requesting your location…';
                    const detected = await detectViaGeolocation(8000);
                    if (!detected) {
                        if (hint) hint.textContent = 'Could not detect your location.';
                        return;
                    }
                    if (hint) hint.textContent = 'Resolving city name…';
                    const enriched = await enrichWithReverse(detected);
                    clearStoredCity();
                    writeStoredCity(enriched);
                    state.city = enriched;
                    close();
                    loadAndRender(enriched, state.hero);
                });
            }
        }

        function escapeHtml(value) {
            return String(value).replace(/[&<>"']/g, function (c) {
                return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
            });
        }

        async function resolveStartingCity() {
            const stored = readStoredCity();
            if (stored) {
                // Backfill the name lazily for older auto-detected entries that
                // were saved before reverse lookup was wired up.
                if (stored.isAuto && (!stored.name || stored.name === 'Your location')) {
                    const enriched = await enrichWithReverse(stored);
                    if (enriched !== stored) {
                        writeStoredCity(enriched);
                        return enriched;
                    }
                }
                return stored;
            }

            const detected = await detectViaGeolocation(6000);
            if (detected) {
                const enriched = await enrichWithReverse(detected);
                writeStoredCity(enriched);
                return enriched;
            }
            return Object.assign({}, HERO_DEFAULTS);
        }

        window.initDashboardWeatherWidget = async function () {
            const hero = document.getElementById('dashboardHeroCard');
            const state = { hero: hero, city: null };

            initEditor(state);

            // Hero config first so the very first paint already honours the
            // admin's curated images and the global enable/disable toggle.
            await fetchHeroConfig();

            const city = await resolveStartingCity();
            state.city = city;
            await loadAndRender(city, hero);

            setInterval(function () {
                if (document.hidden) return;
                if (!state.city) return;
                loadAndRender(state.city, hero);
            }, REFRESH_MS);
        };
    })();
</script>

<?php include '../../includes/footer.php'; ?>
