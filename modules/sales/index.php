<?php
require_once __DIR__ . '/../../config/app.php';
checkAuth();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/permissions_helper.php';
permissions_require_one_of(['view_sales', 'view_invoices', 'view_dashboard_revenue']);
require_once __DIR__ . '/../../libs/InvoiceAuditMigrator.php';
InvoiceAuditMigrator::ensure($pdo);

// Fetch all invoices / sales
$query = "
    SELECT i.*, 
           e.estimation_number 
    FROM invoices i
    LEFT JOIN estimations e ON i.estimation_id = e.id
    ORDER BY i.created_date DESC, i.id DESC
";
// Wait, 'created_date' is 'generated_date' in invoices table. Let's fix that.
$query = "
    SELECT i.*, 
           e.estimation_number 
    FROM invoices i
    LEFT JOIN estimations e ON i.estimation_id = e.id
    ORDER BY i.generated_date DESC, i.id DESC
";
$invoices = $pdo->query($query)->fetchAll();

include '../../includes/header.php';
?>

<div class="list-toolbar mb-6">
    <div class="min-w-0">
        <h1 class="text-3xl font-bold text-gray-800 break-words">Sales and Invoices</h1>
        <p class="text-sm text-gray-500 mt-1">Review direct sales, invoiced work, balances, and payment status in one place.</p>
    </div>
    <div class="list-toolbar-actions">
        <a href="lookup_gr.php" class="list-action-btn bg-indigo-600 text-white" aria-label="Lookup GR number">
            <i class="material-icons sm:mr-1 text-sm">search</i>
            <span class="hidden sm:inline">GR Lookup</span>
        </a>
        <a href="record_sale.php" class="list-action-btn bg-green-600 text-white" aria-label="Record sale">
            <i class="material-icons sm:mr-1 text-sm">add_shopping_cart</i>
            <span class="hidden sm:inline">Record Sale</span>
        </a>
    </div>
</div>

<div class="list-view-shell">
    <div class="list-mobile-stack">
        <?php foreach ($invoices as $inv): ?>
        <div class="list-mobile-card">
            <div class="flex items-start justify-between gap-3">
                <div class="min-w-0">
                    <p class="list-card-title"><?php echo htmlspecialchars($inv['invoice_number']); ?></p>
                    <p class="text-sm text-gray-500 mt-1 break-words"><?php echo htmlspecialchars($inv['customer_name'] ?: 'Estimation #' . $inv['estimation_number']); ?></p>
                </div>
                <?php
                $statusColor = 'bg-gray-100 text-gray-800';
                if ($inv['status'] == 'Paid')
                    $statusColor = 'bg-green-100 text-green-800';
                elseif ($inv['status'] == 'Partially Paid')
                    $statusColor = 'bg-yellow-100 text-yellow-800';
                elseif ($inv['status'] == 'Overdue')
                    $statusColor = 'bg-red-100 text-red-800';
                elseif ($inv['status'] == 'Unpaid')
                    $statusColor = 'bg-orange-100 text-orange-800';
                ?>
                <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full <?php echo $statusColor; ?>">
                    <?php echo htmlspecialchars($inv['status']); ?>
                </span>
            </div>
            <div class="grid grid-cols-2 gap-3 mt-4 text-sm">
                <div>
                    <p class="list-card-meta">Total</p>
                    <p class="list-card-value font-semibold text-gray-900"><?php echo number_format($inv['total_amount'], 2); ?></p>
                </div>
                <div>
                    <p class="list-card-meta">Balance</p>
                    <p class="list-card-value font-semibold <?php echo $inv['balance'] > 0 ? 'text-red-600' : 'text-green-600'; ?>"><?php echo number_format($inv['balance'], 2); ?></p>
                </div>
            </div>
            <div class="grid grid-cols-2 gap-3 mt-3 text-sm">
                <div>
                    <p class="list-card-meta">Date</p>
                    <p class="list-card-value"><?php echo date('M d, Y', strtotime($inv['generated_date'])); ?></p>
                </div>
                <div>
                    <p class="list-card-meta">Type</p>
                    <p class="list-card-value"><?php echo $inv['estimation_id'] ? 'Invoiced Sale' : 'Direct Sale'; ?></p>
                </div>
            </div>
            <div class="list-row-actions mt-4">
                <a href="view_invoice.php?id=<?php echo $inv['id']; ?>" class="list-icon-action bg-indigo-600 text-white" aria-label="View sale details">
                    <i class="material-icons text-sm">visibility</i>
                </a>
            </div>
        </div>
        <?php endforeach; ?>
        <?php if (empty($invoices)): ?>
        <div class="list-mobile-card text-center text-gray-500">
            <i class="material-icons text-gray-400 text-4xl mb-2 block">receipt</i>
            No sales or invoices found.
        </div>
        <?php endif; ?>
    </div>

    <div class="list-desktop-table overflow-x-auto">
    <table class="divide-y divide-gray-200" id="salesTable">
        <thead class="bg-gray-50">
            <tr>
                <th class="text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Invoice #
                </th>
                <th class="text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date</th>
                <th class="text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Customer</th>
                <th class="text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Type</th>
                <th class="text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Total (MK)
                </th>
                <th class="text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Balance (MK)
                </th>
                <th class="text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                <th class="text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
            </tr>
        </thead>
        <tbody class="bg-white divide-y divide-gray-200">
            <?php foreach ($invoices as $inv): ?>
                <tr>
                    <td class="font-medium text-gray-900 cell-wrap">
                        <?php echo htmlspecialchars($inv['invoice_number']); ?>
                    </td>
                    <td class="text-sm text-gray-500">
                        <?php echo date('M d, Y', strtotime($inv['generated_date'])); ?>
                        <br>
                        <span class="text-xs text-gray-400">Due:
                            <?php echo $inv['due_date'] ? date('M d, Y', strtotime($inv['due_date'])) : 'N/A'; ?>
                        </span>
                    </td>
                    <td class="text-sm text-gray-900 cell-wrap">
                        <?php
                        if ($inv['customer_name']) {
                            echo htmlspecialchars($inv['customer_name']);
                            if ($inv['customer_phone'])
                                echo "<br><span class='text-xs text-gray-500'>{$inv['customer_phone']}</span>";
                        } else {
                            // Fallback if joined from estimation
                            echo "<i>Estimation #" . $inv['estimation_number'] . "</i>";
                        }
                        ?>
                    </td>
                    <td class="text-sm text-gray-500">
                        <?php if ($inv['estimation_id']): ?>
                            <span
                                class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-blue-100 text-blue-800">Invoiced
                                Sale</span>
                        <?php else: ?>
                            <span
                                class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-purple-100 text-purple-800">Direct
                                Sale</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-sm text-gray-900 font-semibold">
                        <?php echo number_format($inv['total_amount'], 2); ?>
                    </td>
                    <td class="text-sm font-semibold <?php echo $inv['balance'] > 0 ? 'text-red-600' : 'text-green-600'; ?>">
                        <?php echo number_format($inv['balance'], 2); ?>
                    </td>
                    <td>
                        <?php
                        $statusColor = 'bg-gray-100 text-gray-800';
                        if ($inv['status'] == 'Paid')
                            $statusColor = 'bg-green-100 text-green-800';
                        elseif ($inv['status'] == 'Partially Paid')
                            $statusColor = 'bg-yellow-100 text-yellow-800';
                        elseif ($inv['status'] == 'Overdue')
                            $statusColor = 'bg-red-100 text-red-800';
                        elseif ($inv['status'] == 'Unpaid')
                            $statusColor = 'bg-orange-100 text-orange-800';
                        ?>
                        <span
                            class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full <?php echo $statusColor; ?>">
                            <?php echo htmlspecialchars($inv['status']); ?>
                        </span>
                    </td>
                    <td class="text-right text-sm font-medium">
                        <a href="view_invoice.php?id=<?php echo $inv['id']; ?>"
                            class="text-indigo-600 hover:text-indigo-900" title="View Details">
                            <i class="material-icons">visibility</i>
                        </a>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($invoices)): ?>
                <tr>
                    <td colspan="8" class="text-center text-gray-500">No sales or invoices found.</td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        if (typeof $ !== 'undefined' && $.fn.DataTable) {
            $('#salesTable').DataTable({
                "order": [[1, "desc"]],
                "pageLength": 10
            });
        }
    });
</script>

<?php include '../../includes/footer.php'; ?>
