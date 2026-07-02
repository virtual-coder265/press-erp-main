<?php
/**
 * Estimation Status Dashboard
 * 
 * Provides overview of all estimations grouped by status,
 * with statistics and quick access to manage them.
 */
require_once __DIR__ . '/../../config/app.php';
checkAuth();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../libs/EstimationStatusManager.php';

$manager = new EstimationStatusManager($pdo);
$statistics = $manager->getStatisticsByStatus();

// Build statistics array indexed by status
$stats_by_status = [];
$total_count = 0;
$total_amount = 0;

foreach (EstimationStatusManager::getAllStatuses() as $status) {
    $stats_by_status[$status] = [
        'count' => 0,
        'total_amount' => 0
    ];
}

foreach ($statistics as $stat) {
    if (isset($stats_by_status[$stat['status']])) {
        $stats_by_status[$stat['status']]['count'] = $stat['count'];
        $stats_by_status[$stat['status']]['total_amount'] = $stat['total_amount'];
        $total_count += $stat['count'];
        $total_amount += $stat['total_amount'];
    }
}

include '../../includes/header.php';
?>

<style>
    .estimation-stat-icon svg.lucide {
        width: 1.5rem;
        height: 1.5rem;
        flex-shrink: 0;
    }
</style>

<div class="mb-8">
    <div class="flex items-center gap-2 mb-4">
        <a href="list" class="text-green-600 hover:underline inline-flex items-center gap-1">
            <i data-lucide="arrow-left" class="h-4 w-4 flex-shrink-0" aria-hidden="true"></i> Back to Estimations
        </a>
    </div>
    <h1 class="text-3xl font-bold text-gray-800">Estimation Status Dashboard</h1>
    <p class="text-gray-600">Overview of all estimations organized by status</p>
</div>

<!-- Overall Statistics -->
<div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
    <div class="bg-white shadow rounded-lg p-6">
        <p class="text-gray-600 text-sm font-semibold uppercase">Total Estimations</p>
        <p class="text-4xl font-bold text-gray-800 mt-2"><?php echo $total_count; ?></p>
    </div>
    <div class="bg-white shadow rounded-lg p-6">
        <p class="text-gray-600 text-sm font-semibold uppercase">Total Amount</p>
        <p class="text-3xl font-bold text-green-600 mt-2">MTW <?php echo number_format($total_amount, 2); ?></p>
    </div>
    <div class="bg-white shadow rounded-lg p-6">
        <p class="text-gray-600 text-sm font-semibold uppercase">Average Estimation</p>
        <p class="text-3xl font-bold text-blue-600 mt-2">MTW <?php echo number_format($total_count > 0 ? $total_amount / $total_count : 0, 2); ?></p>
    </div>
</div>

<!-- Status Breakdown Cards -->
<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
    <?php
    $colors = [
        'Draft' => ['bg' => 'bg-yellow-50', 'border' => 'border-yellow-200', 'icon' => 'bg-yellow-100 text-yellow-700'],
        'Performer Invoiced' => ['bg' => 'bg-blue-50', 'border' => 'border-blue-200', 'icon' => 'bg-blue-100 text-blue-700'],
        'Approved' => ['bg' => 'bg-green-50', 'border' => 'border-green-200', 'icon' => 'bg-green-100 text-green-700'],
        'Invoiced' => ['bg' => 'bg-purple-50', 'border' => 'border-purple-200', 'icon' => 'bg-purple-100 text-purple-700']
    ];

    foreach (EstimationStatusManager::getAllStatuses() as $status):
        $details = EstimationStatusManager::getStatusDetails($status);
        $color = $colors[$status] ?? [];
        $count = $stats_by_status[$status]['count'];
        $amount = $stats_by_status[$status]['total_amount'];
        ?>
        <a href="list?status=<?php echo urlencode($status); ?>" class="block">
            <div class="<?php echo $color['bg']; ?> border-l-4 <?php echo $color['border']; ?> rounded-lg p-6 hover:shadow-lg transition cursor-pointer">
                <div class="flex justify-between items-start mb-3">
                    <h3 class="font-bold text-gray-800"><?php echo htmlspecialchars($details['label']); ?></h3>
                    <span class="<?php echo htmlspecialchars($color['icon'], ENT_QUOTES, 'UTF-8'); ?> rounded-full p-2 inline-flex items-center justify-center estimation-stat-icon" aria-hidden="true">
                        <i data-lucide="<?php echo htmlspecialchars($details['icon'], ENT_QUOTES, 'UTF-8'); ?>"></i>
                    </span>
                </div>
                <p class="text-2xl font-bold text-gray-700 mb-1"><?php echo $count; ?></p>
                <p class="text-sm text-gray-600 mb-3"><?php echo htmlspecialchars($details['description']); ?></p>
                <p class="text-sm font-semibold text-gray-700">
                    MTW <?php echo number_format($amount, 2); ?>
                </p>
            </div>
        </a>
    <?php endforeach; ?>
</div>

<!-- Status Ageing Report -->
<div class="bg-white shadow rounded-lg p-6">
    <h2 class="text-xl font-bold text-gray-800 mb-6">Estimations by Status (Detailed)</h2>
    
    <?php foreach (EstimationStatusManager::getAllStatuses() as $status): ?>
        <?php
        $estList = $manager->getEstimationsByStatus($status, limit: 5);
        if (empty($estList)) continue;
        ?>
        <div class="mb-8">
            <div class="flex items-center gap-2 mb-4">
                <?php echo EstimationStatusManager::getStatusBadgeHtml($status); ?>
                <span class="text-gray-600 text-sm">(<?php echo $stats_by_status[$status]['count']; ?> total)</span>
            </div>

            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-100">
                        <tr>
                            <th class="px-4 py-2 text-left">Est #</th>
                            <th class="px-4 py-2 text-left">Customer</th>
                            <th class="px-4 py-2 text-left">Amount</th>
                            <th class="px-4 py-2 text-left">Age (Days)</th>
                            <th class="px-4 py-2 text-right">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
                        <?php foreach ($estList as $est): ?>
                            <?php
                            $created = new DateTime($est['created_at']);
                            $now = new DateTime();
                            $age = $now->diff($created)->days;
                            ?>
                            <tr class="hover:bg-gray-50">
                                <td class="px-4 py-2 font-medium text-gray-900">
                                    <a href="view?id=<?php echo $est['id']; ?>" class="text-blue-600 hover:underline">
                                        <?php echo htmlspecialchars($est['estimation_number']); ?>
                                    </a>
                                </td>
                                <td class="px-4 py-2 text-gray-700"><?php echo htmlspecialchars($est['customer_name']); ?></td>
                                <td class="px-4 py-2 text-gray-700">MTW <?php echo number_format($est['total_amount'], 2); ?></td>
                                <td class="px-4 py-2">
                                    <span class="px-2 py-1 rounded text-xs font-semibold
                                        <?php if ($age > 30): ?>bg-red-100 text-red-800<?php elseif ($age > 14): ?>bg-yellow-100 text-yellow-800<?php else: ?>bg-green-100 text-green-800<?php endif; ?>">
                                        <?php echo $age; ?> days
                                    </span>
                                </td>
                                <td class="px-4 py-2 text-right">
                                    <a href="view?id=<?php echo $est['id']; ?>" class="text-blue-600 hover:underline text-sm">View</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($stats_by_status[$status]['count'] > 5): ?>
                <div class="text-center mt-2">
                    <a href="list?status=<?php echo urlencode($status); ?>" class="text-blue-600 hover:underline text-sm">
                        View all <?php echo $stats_by_status[$status]['count']; ?> estimations →
                    </a>
                </div>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
</div>

<script>if (typeof window.refreshAppShellIcons === 'function') { window.refreshAppShellIcons(); }</script>
<?php include '../../includes/footer.php'; ?>
