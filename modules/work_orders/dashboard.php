<?php
require_once __DIR__ . '/../../config/app.php';
checkAuth();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/work_order_helper.php';
require_once __DIR__ . '/../../includes/work_order_dashboard_helper.php';
require_once __DIR__ . '/../../libs/WorkOrderStatusManager.php';

if (!hasPermission('view_work_orders') && !hasPermission('view_work_order_reports') && !hasPermission('manage_work_orders')) {
    http_response_code(403);
    die('Access Denied.');
}

work_order_bootstrap($pdo);

$kpis = work_order_dashboard_kpis($pdo);
$pipeline = work_order_dashboard_pipeline($pdo);
$activeQueue = work_order_dashboard_active_queue($pdo, 8);
$trend = work_order_dashboard_trend($pdo, 7);
$recentWorkOrders = work_order_dashboard_recent($pdo, 10);
$quickActions = work_order_dashboard_quick_actions();

$canCreateWorkOrder = hasPermission('manage_work_orders') || hasPermission('manage_invoices');
$canOpenQueue = hasPermission('manage_production_queues') || hasPermission('manage_work_orders');

$todayLabel = date('l, j F Y');

include '../../includes/header.php';
?>

<div class="list-toolbar mb-6">
    <div class="min-w-0">
        <h1 class="text-3xl font-bold text-gray-800 break-words">Production Dashboard</h1>
        <p class="text-sm text-gray-500 mt-1">Monitor job flow from accepted invoice through routing, completion, and dispatch.</p>
        <p class="wo-dashboard-date">
            <i data-lucide="calendar" class="h-4 w-4" aria-hidden="true"></i>
            <?php echo htmlspecialchars($todayLabel); ?>
        </p>
    </div>
    <div class="list-toolbar-actions">
        <?php if ($canCreateWorkOrder): ?>
            <a href="create" class="list-action-btn bg-emerald-600 text-white">
                <i data-lucide="plus" class="sm:mr-1 inline-block h-5 w-5" aria-hidden="true"></i>
                <span class="hidden sm:inline">New Work Order</span>
            </a>
        <?php endif; ?>
        <a href="list" class="list-action-btn bg-indigo-600 text-white">
            <i data-lucide="clipboard-list" class="sm:mr-1 inline-block h-5 w-5" aria-hidden="true"></i>
            <span class="hidden sm:inline">All Work Orders</span>
        </a>
    </div>
</div>

<div class="wo-dashboard-kpi-grid">
    <?php foreach ($kpis as $kpi): ?>
        <a href="<?php echo htmlspecialchars($kpi['href']); ?>"
           class="wo-dashboard-kpi-card"
           data-tone="<?php echo htmlspecialchars($kpi['tone']); ?>">
            <div class="wo-dashboard-kpi-head">
                <div>
                    <p class="wo-dashboard-kpi-label"><?php echo htmlspecialchars($kpi['label']); ?></p>
                    <p class="wo-dashboard-kpi-value"><?php echo htmlspecialchars($kpi['value']); ?></p>
                </div>
                <span class="wo-dashboard-kpi-icon">
                    <i data-lucide="<?php echo htmlspecialchars($kpi['icon']); ?>" aria-hidden="true"></i>
                </span>
            </div>
            <p class="wo-dashboard-kpi-meta"><?php echo htmlspecialchars($kpi['meta']); ?></p>
            <div class="wo-dashboard-kpi-progress" aria-hidden="true">
                <span style="width: <?php echo max(0, min(100, (int) $kpi['progress'])); ?>%;"></span>
            </div>
        </a>
    <?php endforeach; ?>
</div>

<?php if (!empty($quickActions)): ?>
    <div class="wo-dashboard-quick-actions" aria-label="Quick actions">
        <?php foreach ($quickActions as $action): ?>
            <a href="<?php echo htmlspecialchars($action['href']); ?>"
               class="wo-dashboard-quick-action"
               data-tone="<?php echo htmlspecialchars($action['tone']); ?>">
                <span class="wo-dashboard-quick-action-icon">
                    <i data-lucide="<?php echo htmlspecialchars($action['icon']); ?>" aria-hidden="true"></i>
                </span>
                <span><?php echo htmlspecialchars($action['label']); ?></span>
            </a>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<div class="wo-dashboard-pipeline-card">
    <div class="wo-dashboard-section-head">
        <h2 class="wo-dashboard-section-title">Production Pipeline</h2>
        <a href="timeline" class="wo-dashboard-section-link">View timeline</a>
    </div>
    <div class="wo-dashboard-pipeline-scroll" role="list" aria-label="Production pipeline stages">
        <?php foreach ($pipeline as $index => $stage): ?>
            <?php if ($index > 0): ?>
                <span class="wo-dashboard-pipeline-arrow" aria-hidden="true">
                    <i data-lucide="chevron-right"></i>
                </span>
            <?php endif; ?>
            <a href="<?php echo htmlspecialchars($stage['href']); ?>"
               class="wo-dashboard-pipeline-step"
               role="listitem">
                <span class="wo-dashboard-pipeline-step-icon">
                    <i data-lucide="<?php echo htmlspecialchars($stage['icon']); ?>" aria-hidden="true"></i>
                </span>
                <span class="wo-dashboard-pipeline-step-label"><?php echo htmlspecialchars($stage['label']); ?></span>
                <span class="wo-dashboard-pipeline-step-count"><?php echo (int) $stage['count']; ?></span>
                <span class="wo-dashboard-pipeline-step-pct"><?php echo (int) $stage['pct']; ?>%</span>
            </a>
        <?php endforeach; ?>
    </div>
</div>

<div class="grid grid-cols-1 xl:grid-cols-3 gap-6 mb-6">
    <div class="xl:col-span-2 wo-dashboard-section-card">
        <div class="wo-dashboard-section-head">
            <h2 class="wo-dashboard-section-title">Active Work Queue</h2>
            <a href="list?status=In+Production" class="wo-dashboard-section-link">View all in production</a>
        </div>
        <div class="wo-dashboard-queue-list">
            <?php foreach ($activeQueue as $job): ?>
                <a href="view?id=<?php echo (int) $job['id']; ?>" class="wo-dashboard-queue-item">
                    <div class="wo-dashboard-queue-main">
                        <p class="wo-dashboard-queue-title"><?php echo htmlspecialchars($job['work_order_number']); ?></p>
                        <p class="wo-dashboard-queue-subtitle">
                            <?php echo htmlspecialchars($job['customer_name'] ?: '—'); ?>
                            <?php if (!empty($job['department_name'])): ?>
                                · <?php echo htmlspecialchars($job['department_name']); ?>
                            <?php endif; ?>
                        </p>
                    </div>
                    <div class="wo-dashboard-queue-meta">
                        <?php if (!empty($job['department_name'])): ?>
                            <span class="px-2 py-1 text-xs rounded-full font-semibold bg-indigo-100 text-indigo-800">
                                <?php echo htmlspecialchars($job['department_name']); ?>
                            </span>
                        <?php endif; ?>
                        <span class="wo-dashboard-due-label" data-tone="<?php echo htmlspecialchars($job['due_tone']); ?>">
                            <?php echo htmlspecialchars($job['due_label']); ?>
                        </span>
                    </div>
                </a>
            <?php endforeach; ?>
            <?php if (empty($activeQueue)): ?>
                <p class="text-sm text-gray-500 italic py-4 text-center">No active jobs in production right now.</p>
            <?php endif; ?>
        </div>
    </div>

    <div class="wo-dashboard-section-card">
        <div class="wo-dashboard-section-head">
            <h2 class="wo-dashboard-section-title">Daily Production Trend</h2>
            <span class="text-xs text-gray-500">Last 7 days</span>
        </div>
        <div class="relative w-full" style="height: 280px;">
            <canvas id="woProductionTrendChart" aria-label="Daily production trend line chart" role="img"></canvas>
        </div>
    </div>
</div>

<div class="wo-dashboard-section-card mb-6">
    <div class="wo-dashboard-section-head">
        <h2 class="wo-dashboard-section-title">Recent Work Orders</h2>
        <a href="list" class="wo-dashboard-section-link">View all</a>
    </div>
    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Work Order</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Customer</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Department</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Priority</th>
                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Due Date</th>
                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                <?php foreach ($recentWorkOrders as $wo): ?>
                    <?php
                    $deptSlug = (string) ($wo['current_department_slug'] ?? '');
                    $canQueue = $deptSlug !== '' && $canOpenQueue;
                    ?>
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-3">
                            <a href="view?id=<?php echo (int) $wo['id']; ?>" class="font-semibold text-indigo-600 hover:underline">
                                <?php echo htmlspecialchars($wo['work_order_number']); ?>
                            </a>
                            <div class="text-xs text-gray-500"><?php echo htmlspecialchars(date('M j, Y', strtotime($wo['created_at']))); ?></div>
                        </td>
                        <td class="px-4 py-3 text-sm text-gray-700"><?php echo htmlspecialchars($wo['customer_name'] ?: '—'); ?></td>
                        <td class="px-4 py-3 text-sm text-gray-700">
                            <?php if ($canQueue): ?>
                                <a href="workspace?department=<?php echo urlencode($deptSlug); ?>" class="text-indigo-600 hover:underline">
                                    <?php echo htmlspecialchars($wo['current_department_name']); ?>
                                </a>
                            <?php else: ?>
                                <?php echo htmlspecialchars($wo['current_department_name'] ?: ($wo['status'] === 'Draft' ? 'Costing (awaiting send)' : 'Not routed yet')); ?>
                            <?php endif; ?>
                        </td>
                        <td class="px-4 py-3">
                            <span class="px-2 py-1 inline-flex text-xs rounded-full font-semibold <?php echo work_order_status_badge_class((string) $wo['status']); ?>">
                                <?php echo htmlspecialchars($wo['status']); ?>
                            </span>
                        </td>
                        <td class="px-4 py-3 text-sm text-gray-700"><?php echo htmlspecialchars($wo['priority']); ?></td>
                        <td class="px-4 py-3 text-right text-sm text-gray-700"><?php echo htmlspecialchars($wo['due_date'] ?: '—'); ?></td>
                        <td class="px-4 py-3">
                            <div class="flex justify-end gap-2">
                                <a href="view?id=<?php echo (int) $wo['id']; ?>" class="list-icon-action bg-indigo-600 text-white" title="Open work order" aria-label="Open work order">
                                    <i data-lucide="eye" class="h-4 w-4" aria-hidden="true"></i>
                                </a>
                                <?php if ($canQueue): ?>
                                    <a href="workspace?department=<?php echo urlencode($deptSlug); ?>" class="list-icon-action bg-emerald-600 text-white" title="Open department queue" aria-label="Open department queue">
                                        <i data-lucide="layout-grid" class="h-4 w-4" aria-hidden="true"></i>
                                    </a>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($recentWorkOrders)): ?>
                    <tr>
                        <td colspan="7" class="px-4 py-10 text-center text-gray-500">No work orders yet.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var canvas = document.getElementById('woProductionTrendChart');
    if (!canvas || typeof Chart === 'undefined') {
        return;
    }

    Chart.defaults.color = '#5f6f82';
    Chart.defaults.font.family = '"Plus Jakarta Sans", "Segoe UI", sans-serif';
    Chart.defaults.plugins.legend.labels.usePointStyle = true;
    Chart.defaults.plugins.legend.labels.boxWidth = 10;
    Chart.defaults.plugins.legend.labels.boxHeight = 10;

    var trendData = {
        labels: <?php echo json_encode($trend['labels'], JSON_UNESCAPED_UNICODE); ?>,
        completed: <?php echo json_encode($trend['completed']); ?>,
        started: <?php echo json_encode($trend['started']); ?>
    };

    if (typeof Chart.getChart === 'function') {
        var existing = Chart.getChart(canvas);
        if (existing) {
            existing.destroy();
        }
    }

    new Chart(canvas, {
        type: 'line',
        data: {
            labels: trendData.labels,
            datasets: [
                {
                    label: 'Completed',
                    data: trendData.completed,
                    borderColor: '#059669',
                    backgroundColor: 'rgba(5, 150, 105, 0.12)',
                    fill: true,
                    tension: 0.35,
                    pointRadius: 3,
                    pointHoverRadius: 5
                },
                {
                    label: 'Started production',
                    data: trendData.started,
                    borderColor: '#4f46e5',
                    backgroundColor: 'rgba(79, 70, 229, 0.08)',
                    fill: true,
                    tension: 0.35,
                    pointRadius: 3,
                    pointHoverRadius: 5
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
            scales: {
                x: {
                    grid: {
                        display: false
                    }
                },
                y: {
                    beginAtZero: true,
                    ticks: {
                        precision: 0
                    },
                    grid: {
                        color: 'rgba(148, 163, 184, 0.2)'
                    }
                }
            },
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: {
                        padding: 14,
                        font: {
                            size: 12,
                            weight: '600'
                        }
                    }
                },
                tooltip: {
                    backgroundColor: 'rgba(15, 23, 42, 0.92)',
                    padding: 12
                }
            }
        }
    });
});
</script>

<?php include '../../includes/footer.php'; ?>
