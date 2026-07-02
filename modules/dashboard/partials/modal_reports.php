<?php
/**
 * Dashboard partial - Reports modal body (chart canvases + meta).
 *
 * Component id: dashboard.modal.reports
 * Required context:
 *   - $latestTrendLabel, $latestEstimationsTrend, $latestInvoicesTrend
 *   - $collectionRate, $latestRevenueTrend, $latestCollectedTrend
 *   - $invoiceStatusTotal, $stats, $projectStatusTotal, $totalTasksTracked
 *   - $chartData (also needed by the JS so it can re-init the charts)
 *
 * The chart bootstrap script (in modules/dashboard/index.php) listens for
 * `ajax:component:rendered` with this id and re-initialises every chart from
 * the embedded JSON below.
 */
$reportsChartData = $chartData ?? [];
?>
<div class="todo-modal-body"
     data-ajax-component="dashboard.modal.reports"
     data-ajax-refresh-on="modal-open:wsModalReports"
     data-ajax-stale="60000">
    <p style="font-size:12px;color:#605e5c;margin:-4px 0 0;">
        Charts for activity, revenue, invoice status and project status.
    </p>
    <script type="application/json" data-dashboard-chart-data><?php
        echo json_encode($reportsChartData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    ?></script>
    <div class="dashboard-chart-grid">
<?php if (hasPermission('view_dashboard_revenue') || hasPermission('view_estimations')): ?>
        <div class="dashboard-chart-card">
            <div class="dashboard-chart-inner">
            <div class="flex flex-col gap-2 sm:flex-row sm:justify-between sm:items-center mb-4">
                <div>
                    <h2 class="text-lg font-bold text-gray-800">Activity Trend</h2>
                </div>
                <a href="<?php echo BASE_URL; ?>modules/estimations/list"
                    class="text-green-600 text-xs font-semibold hover:underline inline-flex items-center gap-1.5 self-start">
                    <span>Open</span>
                    <i data-lucide="arrow-right" class="text-xs w-3 h-3" aria-hidden="true"></i>
                </a>
            </div>
            <div class="dashboard-chart-meta">
                <div class="dashboard-chart-meta-item">
                    <span>Latest Month</span>
                    <strong><?php echo htmlspecialchars($latestTrendLabel); ?></strong>
                </div>
                <div class="dashboard-chart-meta-item">
                    <span>Estimations</span>
                    <strong><?php echo number_format($latestEstimationsTrend); ?></strong>
                </div>
                <div class="dashboard-chart-meta-item">
                    <span>Invoices</span>
                    <strong><?php echo number_format($latestInvoicesTrend); ?></strong>
                </div>
            </div>
            <div class="dashboard-chart-frame">
                <div class="dashboard-chart-canvas">
                    <canvas id="trendChart"></canvas>
                </div>
            </div>
            </div>
        </div>
<?php endif; ?>

<?php if (hasPermission('view_dashboard_revenue')): ?>
        <div class="dashboard-chart-card">
            <div class="dashboard-chart-inner">
            <div class="flex flex-col gap-2 sm:flex-row sm:justify-between sm:items-center mb-4">
                <div>
                    <h2 class="text-lg font-bold text-gray-800">Revenue & Collections</h2>
                </div>
                <a href="<?php echo BASE_URL; ?>modules/sales/index"
                    class="text-emerald-600 text-xs font-semibold hover:underline inline-flex items-center gap-1.5 self-start" style="color:#10b981">
                    <span>Open</span>
                    <i data-lucide="arrow-right" class="text-xs w-3 h-3" aria-hidden="true"></i>
                </a>
            </div>
            <div class="dashboard-chart-meta">
                <div class="dashboard-chart-meta-item">
                    <span>Collection Rate</span>
                    <strong><?php echo $collectionRate; ?>%</strong>
                </div>
                <div class="dashboard-chart-meta-item">
                    <span>Latest Billed</span>
                    <strong>MK <?php echo dashboardCurrency($latestRevenueTrend); ?></strong>
                </div>
                <div class="dashboard-chart-meta-item">
                    <span>Latest Collected</span>
                    <strong>MK <?php echo dashboardCurrency($latestCollectedTrend); ?></strong>
                </div>
            </div>
            <div class="dashboard-chart-frame">
                <div class="dashboard-chart-canvas">
                    <canvas id="revenueChart"></canvas>
                </div>
            </div>
            </div>
        </div>
<?php endif; ?>

<?php if (hasPermission('view_invoices')): ?>
        <div class="dashboard-chart-card">
            <div class="dashboard-chart-inner">
            <div class="flex flex-col gap-2 sm:flex-row sm:justify-between sm:items-center mb-4">
                <div>
                    <h2 class="text-lg font-bold text-gray-800">Invoice Status</h2>
                </div>
                <a href="<?php echo BASE_URL; ?>modules/invoices/list"
                    class="text-emerald-700 text-xs font-semibold hover:underline inline-flex items-center gap-1.5 self-start">
                    <span>Open</span>
                    <i data-lucide="arrow-right" class="text-xs w-3 h-3" aria-hidden="true"></i>
                </a>
            </div>
            <div class="dashboard-chart-meta">
                <div class="dashboard-chart-meta-item">
                    <span>Total Invoices</span>
                    <strong><?php echo number_format($invoiceStatusTotal); ?></strong>
                </div>
                <div class="dashboard-chart-meta-item">
                    <span>Unpaid</span>
                    <strong><?php echo number_format((int) ($stats['unpaid_invoices']['val'] ?? 0)); ?></strong>
                </div>
                <div class="dashboard-chart-meta-item">
                    <span>Partial</span>
                    <strong><?php echo number_format((int) ($stats['partially_paid']['val'] ?? 0)); ?></strong>
                </div>
            </div>
            <div class="dashboard-chart-frame">
                <div class="dashboard-chart-canvas is-compact">
                    <canvas id="invoiceChart"></canvas>
                </div>
            </div>
            </div>
        </div>
<?php endif; ?>

<?php if (hasPermission('view_projects')): ?>
        <div class="dashboard-chart-card">
            <div class="dashboard-chart-inner">
            <div class="flex flex-col gap-2 sm:flex-row sm:justify-between sm:items-center mb-4">
                <div>
                    <h2 class="text-lg font-bold text-gray-800">Project Status</h2>
                </div>
                <a href="<?php echo BASE_URL; ?>modules/projects/list"
                    class="text-emerald-700 text-xs font-semibold hover:underline inline-flex items-center gap-1.5 self-start">
                    <span>Open</span>
                    <i data-lucide="arrow-right" class="text-xs w-3 h-3" aria-hidden="true"></i>
                </a>
            </div>
            <div class="dashboard-chart-meta">
                <div class="dashboard-chart-meta-item">
                    <span>Total Projects</span>
                    <strong><?php echo number_format($projectStatusTotal); ?></strong>
                </div>
                <div class="dashboard-chart-meta-item">
                    <span>Active</span>
                    <strong><?php echo number_format((int) ($stats['active_projects']['val'] ?? 0)); ?></strong>
                </div>
                <div class="dashboard-chart-meta-item">
                    <span>Tracked Tasks</span>
                    <strong><?php echo number_format($totalTasksTracked); ?></strong>
                </div>
            </div>
            <div class="dashboard-chart-frame">
                <div class="dashboard-chart-canvas is-compact">
                    <canvas id="projectChart"></canvas>
                </div>
            </div>
            </div>
        </div>
<?php endif; ?>
    </div>
</div>
