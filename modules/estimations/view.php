<?php
/**
 * Estimation View Page
 *
 * Renders the full transformed summary of an estimation: every populated
 * field across the wizard sections (header, items, paper, ink, binding,
 * pre-press, press, finishing) plus the audit notice and the status
 * history.
 *
 * This page is a *view*. It never triggers a file download. To export the
 * PDF the user clicks the explicit "Download" action which routes to
 * `download?id=X` and streams the file with `Content-Disposition: attachment`.
 *
 * All in-page links go to extensionless URLs (e.g. `download?id=X`,
 * `delete`) so the .htaccess rewrite handles routing properly and POST
 * bodies are not lost to a 301 redirect.
 */

require_once __DIR__ . '/../../config/app.php';
checkAuth();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/permissions_helper.php';
permissions_require_one_of(['view_estimations']);
require_once __DIR__ . '/../../libs/EstimationStatusManager.php';
require_once __DIR__ . '/../../libs/EstimationAuditMigrator.php';

EstimationAuditMigrator::ensure($pdo);

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    redirect('modules/estimations/list');
}

// Main estimation row + creator/editor names so the audit notice can render
// "Last edited on {Date, Time} by {User}" without an extra round-trip.
$stmt = $pdo->prepare("
    SELECT e.*,
           uc.name AS created_by_name,
           ue.name AS last_edited_by_name
    FROM estimations e
    LEFT JOIN users uc ON e.created_by = uc.id
    LEFT JOIN users ue ON e.last_edited_by = ue.id
    WHERE e.id = :id
");
$stmt->execute(['id' => $id]);
$est = $stmt->fetch();

if (!$est) {
    http_response_code(404);
    die('Estimation not found.');
}

// Coalesce audit fields (older rows may not have last_edited_* populated yet).
$lastEditedAt   = $est['last_edited_at'] ?? $est['updated_at'] ?? $est['created_at'];
$lastEditedBy   = $est['last_edited_by_name'] ?? $est['created_by_name'] ?? 'System';

/**
 * Helper: load every related table once with prepared queries. The
 * estimations module stores child rows across several tables (papers, ink
 * colours, binding materials, pre-press, press, finishing). Each query is
 * defensive in case the underlying table has not been created on a fresh
 * install.
 */
function est_safe_fetch(PDO $pdo, string $sql, array $params = []): array
{
    try {
        $s = $pdo->prepare($sql);
        $s->execute($params);
        return $s->fetchAll();
    } catch (PDOException $e) {
        return [];
    }
}

$items     = est_safe_fetch($pdo, "SELECT * FROM estimation_items             WHERE estimation_id = :id ORDER BY id",                ['id' => $id]);
$papers    = est_safe_fetch($pdo, "SELECT * FROM estimation_papers            WHERE estimation_id = :id ORDER BY sort_order, id",   ['id' => $id]);
$inkRows   = est_safe_fetch($pdo, "SELECT * FROM estimation_ink_colours       WHERE estimation_id = :id ORDER BY sort_order, id",   ['id' => $id]);
$binding   = est_safe_fetch($pdo, "SELECT * FROM estimation_binding_materials WHERE estimation_id = :id ORDER BY sort_order, id",   ['id' => $id]);
$prepress  = est_safe_fetch($pdo, "SELECT * FROM estimation_prepress_labour   WHERE estimation_id = :id ORDER BY sort_order, id",   ['id' => $id]);
$press     = est_safe_fetch($pdo, "SELECT * FROM estimation_press_labour      WHERE estimation_id = :id ORDER BY sort_order, id",   ['id' => $id]);
$finishing = est_safe_fetch($pdo, "SELECT * FROM estimation_finishing_labour  WHERE estimation_id = :id ORDER BY sort_order, id",   ['id' => $id]);

// Linked invoices so we can hide the Convert and Delete buttons when one
// already exists (deletion would otherwise be blocked by the FK anyway).
$linkedInvoices = est_safe_fetch(
    $pdo,
    "SELECT id, invoice_number, status, generated_date FROM invoices WHERE estimation_id = :id ORDER BY id",
    ['id' => $id]
);

$statusManager = new EstimationStatusManager($pdo);
$statusHistory = $statusManager->getStatusHistory($id);

// Aggregated subtotals straight from the saved data so the summary always
// matches what was stored, even if the wizard math drifted.
$subtotals = [
    'items'     => array_sum(array_map(fn($r) => (float) $r['total_price'],   $items)),
    'papers'    => array_sum(array_map(fn($r) => (float) $r['paper_total'],   $papers)),
    'ink'       => array_sum(array_map(fn($r) => (float) $r['total'],         $inkRows)),
    'binding'   => array_sum(array_map(fn($r) => (float) $r['total'],         $binding)),
    'prepress'  => array_sum(array_map(fn($r) => (float) $r['total'],         $prepress)),
    'press'     => array_sum(array_map(fn($r) => (float) $r['machine_total'], $press)),
    'finishing' => array_sum(array_map(fn($r) => (float) $r['total'],         $finishing)),
];

include '../../includes/header.php';

$flashSuccess = $_SESSION['success'] ?? null;
$flashError   = $_SESSION['error']   ?? null;
unset($_SESSION['success'], $_SESSION['error']);
?>

<div class="mb-6">
    <a href="list" class="text-green-600 hover:underline inline-flex items-center text-sm">
        <i data-lucide="arrow-left" class="mr-1 inline-block h-4 w-4 flex-shrink-0" aria-hidden="true"></i>
        Back to estimations
    </a>
</div>

<?php if ($flashSuccess): ?>
    <div class="bg-green-50 border border-green-200 text-green-800 rounded-lg p-4 mb-4">
        <?php echo htmlspecialchars((string) $flashSuccess); ?>
    </div>
<?php endif; ?>
<?php if ($flashError): ?>
    <div class="bg-red-50 border border-red-200 text-red-800 rounded-lg p-4 mb-4">
        <?php echo htmlspecialchars((string) $flashError); ?>
    </div>
<?php endif; ?>

<div class="bg-white shadow rounded-xl p-6 mb-6">
    <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
        <div class="min-w-0">
            <h1 class="text-3xl font-bold text-gray-800 break-words">
                Estimation <?php echo htmlspecialchars($est['estimation_number']); ?>
            </h1>
            <div class="flex flex-wrap items-center gap-3 mt-3">
                <?php echo EstimationStatusManager::getStatusBadgeHtml($est['status']); ?>
                <span class="text-sm text-gray-500">
                    Total
                    <strong class="text-gray-800">MK <?php echo number_format((float) $est['total_amount'], 2); ?></strong>
                </span>
                <span class="text-sm text-gray-500">
                    Created <?php echo date('M j, Y', strtotime($est['created_at'])); ?>
                    by <strong class="text-gray-800"><?php echo htmlspecialchars($est['created_by_name'] ?? 'Unknown'); ?></strong>
                </span>
            </div>
            <p class="mt-3 text-sm text-gray-600">
                <i data-lucide="history" class="inline-block h-4 w-4 mr-1 align-text-bottom text-gray-400" aria-hidden="true"></i>
                Last edited on
                <strong><?php echo date('M j, Y \a\t g:i A', strtotime($lastEditedAt)); ?></strong>
                by <strong><?php echo htmlspecialchars($lastEditedBy); ?></strong>
            </p>
        </div>

        <div class="flex flex-wrap gap-2">
            <a href="edit?id=<?php echo (int) $est['id']; ?>"
                class="inline-flex items-center gap-1 bg-blue-600 text-white px-4 py-2 rounded-lg shadow hover:bg-blue-700 transition">
                <i data-lucide="pencil" class="h-4 w-4" aria-hidden="true"></i> Edit
            </a>
            <button type="button" onclick="openStatusModal()"
                class="inline-flex items-center gap-1 bg-gray-700 text-white px-4 py-2 rounded-lg shadow hover:bg-gray-800 transition">
                <i data-lucide="refresh-cw" class="h-4 w-4" aria-hidden="true"></i> Change Status
            </button>
            <a href="download?id=<?php echo (int) $est['id']; ?>"
                class="inline-flex items-center gap-1 bg-red-600 text-white px-4 py-2 rounded-lg shadow hover:bg-red-700 transition">
                <i data-lucide="download" class="h-4 w-4" aria-hidden="true"></i> Download PDF
            </a>
            <a href="print?id=<?php echo (int) $est['id']; ?>" target="_blank" rel="noopener"
                class="inline-flex items-center gap-1 bg-gray-200 text-gray-800 px-4 py-2 rounded-lg shadow hover:bg-gray-300 transition">
                <i data-lucide="printer" class="h-4 w-4" aria-hidden="true"></i> Print
            </a>
            <?php if (empty($linkedInvoices)): ?>
                <a href="<?php echo BASE_URL; ?>modules/invoices/create?estimation_id=<?php echo (int) $est['id']; ?>"
                    class="inline-flex items-center gap-1 bg-green-600 text-white px-4 py-2 rounded-lg shadow hover:bg-green-700 transition">
                    <i data-lucide="receipt" class="h-4 w-4" aria-hidden="true"></i> Convert to Invoice
                </a>
                <button type="button" onclick="openDeleteModal()"
                    class="inline-flex items-center gap-1 bg-red-100 text-red-700 px-4 py-2 rounded-lg shadow hover:bg-red-200 transition">
                    <i data-lucide="trash-2" class="h-4 w-4" aria-hidden="true"></i> Delete
                </button>
            <?php else: ?>
                <span class="inline-flex items-center gap-1 bg-amber-50 text-amber-700 px-4 py-2 rounded-lg text-sm" title="An invoice already references this estimation; convert / delete actions are disabled.">
                    <i data-lucide="lock" class="h-4 w-4" aria-hidden="true"></i>
                    Invoice exists (<?php echo htmlspecialchars($linkedInvoices[0]['invoice_number']); ?>)
                </span>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-6">
    <div class="bg-white shadow rounded-lg p-6">
        <h3 class="text-lg font-bold text-gray-700 mb-4 border-b pb-2">Customer</h3>
        <dl class="space-y-2 text-sm">
            <div><dt class="text-gray-500">Name</dt>
                <dd class="font-semibold text-gray-800"><?php echo htmlspecialchars($est['customer_name'] ?: '—'); ?></dd></div>
            <div><dt class="text-gray-500">Email</dt>
                <dd class="text-gray-800 break-all"><?php echo htmlspecialchars($est['customer_email'] ?: '—'); ?></dd></div>
            <div><dt class="text-gray-500">Phone</dt>
                <dd class="text-gray-800"><?php echo htmlspecialchars($est['customer_phone'] ?: '—'); ?></dd></div>
            <?php if (!empty($est['customer_id'])): ?>
                <div><dt class="text-gray-500">Customer ID</dt>
                    <dd class="text-gray-800">#<?php echo (int) $est['customer_id']; ?></dd></div>
            <?php endif; ?>
        </dl>
    </div>

    <div class="bg-white shadow rounded-lg p-6 md:col-span-2">
        <h3 class="text-lg font-bold text-gray-700 mb-4 border-b pb-2">Job Description</h3>
        <?php if (!empty($est['job_description'])): ?>
            <p class="text-sm text-gray-800 whitespace-pre-wrap"><?php echo htmlspecialchars($est['job_description']); ?></p>
        <?php else: ?>
            <p class="text-sm text-gray-500 italic">No job description recorded.</p>
        <?php endif; ?>
    </div>
</div>

<?php if (!empty($items)): ?>
    <div class="bg-white shadow rounded-lg p-6 mb-6">
        <div class="flex items-center justify-between mb-4">
            <h3 class="text-lg font-bold text-gray-700">Line items</h3>
            <span class="text-sm text-gray-500">
                Subtotal: <strong class="text-gray-800">MK <?php echo number_format($subtotals['items'], 2); ?></strong>
            </span>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Description</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Type</th>
                        <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Qty</th>
                        <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Unit price</th>
                        <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Total</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    <?php foreach ($items as $row): ?>
                        <tr>
                            <td class="px-4 py-2 text-sm text-gray-800"><?php echo htmlspecialchars($row['description'] ?: '—'); ?></td>
                            <td class="px-4 py-2 text-sm text-gray-500"><?php echo htmlspecialchars($row['item_type'] ?: '—'); ?></td>
                            <td class="px-4 py-2 text-sm text-gray-700 text-right"><?php echo $row['quantity'] !== null ? number_format((float) $row['quantity'], 2) : '—'; ?></td>
                            <td class="px-4 py-2 text-sm text-gray-700 text-right"><?php echo $row['unit_price'] !== null ? number_format((float) $row['unit_price'], 2) : '—'; ?></td>
                            <td class="px-4 py-2 text-sm font-semibold text-gray-900 text-right"><?php echo number_format((float) $row['total_price'], 2); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<?php if (!empty($papers)): ?>
    <div class="bg-white shadow rounded-lg p-6 mb-6">
        <div class="flex items-center justify-between mb-4">
            <h3 class="text-lg font-bold text-gray-700">Paper</h3>
            <span class="text-sm text-gray-500">
                Subtotal: <strong class="text-gray-800">MK <?php echo number_format($subtotals['papers'], 2); ?></strong>
            </span>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Type</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Size</th>
                        <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Grammage</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Colour</th>
                        <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Sheets</th>
                        <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Rate</th>
                        <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Total</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    <?php foreach ($papers as $row): ?>
                        <tr>
                            <td class="px-4 py-2 text-sm text-gray-800"><?php echo htmlspecialchars($row['paper_type'] ?: '—'); ?></td>
                            <td class="px-4 py-2 text-sm text-gray-700"><?php echo htmlspecialchars($row['paper_size'] ?: '—'); ?></td>
                            <td class="px-4 py-2 text-sm text-gray-700 text-right"><?php echo number_format((float) $row['paper_grammage'], 2); ?></td>
                            <td class="px-4 py-2 text-sm text-gray-700"><?php echo htmlspecialchars($row['paper_color'] ?: '—'); ?></td>
                            <td class="px-4 py-2 text-sm text-gray-700 text-right"><?php echo number_format((float) $row['paper_sheets'], 2); ?></td>
                            <td class="px-4 py-2 text-sm text-gray-700 text-right"><?php echo number_format((float) $row['paper_rate'], 2); ?></td>
                            <td class="px-4 py-2 text-sm font-semibold text-gray-900 text-right"><?php echo number_format((float) $row['paper_total'], 2); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<?php if (!empty($inkRows)): ?>
    <div class="bg-white shadow rounded-lg p-6 mb-6">
        <div class="flex items-center justify-between mb-4">
            <h3 class="text-lg font-bold text-gray-700">Ink colours</h3>
            <span class="text-sm text-gray-500">
                Subtotal: <strong class="text-gray-800">MK <?php echo number_format($subtotals['ink'], 2); ?></strong>
            </span>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Colour</th>
                        <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Kgs</th>
                        <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Rate / kg</th>
                        <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Total</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    <?php foreach ($inkRows as $row): ?>
                        <tr>
                            <td class="px-4 py-2 text-sm text-gray-800"><?php echo htmlspecialchars($row['colour_name'] ?: '—'); ?></td>
                            <td class="px-4 py-2 text-sm text-gray-700 text-right"><?php echo number_format((float) $row['kgs'], 4); ?></td>
                            <td class="px-4 py-2 text-sm text-gray-700 text-right"><?php echo number_format((float) $row['rate'], 2); ?></td>
                            <td class="px-4 py-2 text-sm font-semibold text-gray-900 text-right"><?php echo number_format((float) $row['total'], 2); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<?php if (!empty($binding)): ?>
    <div class="bg-white shadow rounded-lg p-6 mb-6">
        <div class="flex items-center justify-between mb-4">
            <h3 class="text-lg font-bold text-gray-700">Binding materials</h3>
            <span class="text-sm text-gray-500">
                Subtotal: <strong class="text-gray-800">MK <?php echo number_format($subtotals['binding'], 2); ?></strong>
            </span>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Material</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Unit</th>
                        <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Quantity</th>
                        <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Rate</th>
                        <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Total</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    <?php foreach ($binding as $row): ?>
                        <tr>
                            <td class="px-4 py-2 text-sm text-gray-800"><?php echo htmlspecialchars($row['material_name'] ?: '—'); ?></td>
                            <td class="px-4 py-2 text-sm text-gray-700"><?php echo htmlspecialchars($row['unit'] ?: '—'); ?></td>
                            <td class="px-4 py-2 text-sm text-gray-700 text-right"><?php echo number_format((float) $row['quantity'], 2); ?></td>
                            <td class="px-4 py-2 text-sm text-gray-700 text-right"><?php echo number_format((float) $row['rate'], 2); ?></td>
                            <td class="px-4 py-2 text-sm font-semibold text-gray-900 text-right"><?php echo number_format((float) $row['total'], 2); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<?php if (!empty($prepress)): ?>
    <div class="bg-white shadow rounded-lg p-6 mb-6">
        <div class="flex items-center justify-between mb-4">
            <h3 class="text-lg font-bold text-gray-700">Pre-press labour</h3>
            <span class="text-sm text-gray-500">
                Subtotal: <strong class="text-gray-800">MK <?php echo number_format($subtotals['prepress'], 2); ?></strong>
            </span>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Labour</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Unit</th>
                        <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Hours</th>
                        <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Rate / hr</th>
                        <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Total</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    <?php foreach ($prepress as $row): ?>
                        <tr>
                            <td class="px-4 py-2 text-sm text-gray-800"><?php echo htmlspecialchars($row['labour_name'] ?: '—'); ?></td>
                            <td class="px-4 py-2 text-sm text-gray-700"><?php echo htmlspecialchars($row['unit'] ?: 'hrs'); ?></td>
                            <td class="px-4 py-2 text-sm text-gray-700 text-right"><?php echo number_format((float) $row['hrs'], 2); ?></td>
                            <td class="px-4 py-2 text-sm text-gray-700 text-right"><?php echo number_format((float) $row['rate'], 2); ?></td>
                            <td class="px-4 py-2 text-sm font-semibold text-gray-900 text-right"><?php echo number_format((float) $row['total'], 2); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<?php if (!empty($press)): ?>
    <div class="bg-white shadow rounded-lg p-6 mb-6">
        <div class="flex items-center justify-between mb-4">
            <h3 class="text-lg font-bold text-gray-700">Press labour</h3>
            <span class="text-sm text-gray-500">
                Subtotal: <strong class="text-gray-800">MK <?php echo number_format($subtotals['press'], 2); ?></strong>
            </span>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Machine</th>
                        <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Colours</th>
                        <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Make-ready (hrs/rate/total)</th>
                        <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Run (hrs/rate/total)</th>
                        <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Impressions / IPH</th>
                        <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Machine total</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    <?php foreach ($press as $row): ?>
                        <tr>
                            <td class="px-4 py-2 text-sm text-gray-800"><?php echo htmlspecialchars($row['machine_name'] ?: '—'); ?></td>
                            <td class="px-4 py-2 text-sm text-gray-700 text-right"><?php echo (int) $row['colours']; ?></td>
                            <td class="px-4 py-2 text-sm text-gray-700 text-right">
                                <?php echo number_format((float) $row['make_ready_hrs'], 2); ?>
                                / <?php echo number_format((float) $row['make_ready_rate'], 2); ?>
                                / <?php echo number_format((float) $row['make_ready_total'], 2); ?>
                            </td>
                            <td class="px-4 py-2 text-sm text-gray-700 text-right">
                                <?php echo number_format((float) $row['running_hrs'], 2); ?>
                                / <?php echo number_format((float) $row['running_rate'], 2); ?>
                                / <?php echo number_format((float) $row['running_total'], 2); ?>
                            </td>
                            <td class="px-4 py-2 text-sm text-gray-700 text-right">
                                <?php echo (int) $row['impressions']; ?> / <?php echo (int) $row['iph']; ?>
                            </td>
                            <td class="px-4 py-2 text-sm font-semibold text-gray-900 text-right"><?php echo number_format((float) $row['machine_total'], 2); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<?php if (!empty($finishing)): ?>
    <div class="bg-white shadow rounded-lg p-6 mb-6">
        <div class="flex items-center justify-between mb-4">
            <h3 class="text-lg font-bold text-gray-700">Finishing labour</h3>
            <span class="text-sm text-gray-500">
                Subtotal: <strong class="text-gray-800">MK <?php echo number_format($subtotals['finishing'], 2); ?></strong>
            </span>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Labour</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Measure</th>
                        <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Quantity / Hours</th>
                        <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Rate</th>
                        <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Total</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    <?php foreach ($finishing as $row): ?>
                        <tr>
                            <td class="px-4 py-2 text-sm text-gray-800"><?php echo htmlspecialchars($row['labour_name'] ?: '—'); ?></td>
                            <td class="px-4 py-2 text-sm text-gray-700"><?php echo htmlspecialchars($row['measure_type'] ?: '—'); ?></td>
                            <td class="px-4 py-2 text-sm text-gray-700 text-right"><?php echo number_format((float) $row['quantity'], 2); ?></td>
                            <td class="px-4 py-2 text-sm text-gray-700 text-right"><?php echo number_format((float) $row['rate'], 2); ?></td>
                            <td class="px-4 py-2 text-sm font-semibold text-gray-900 text-right"><?php echo number_format((float) $row['total'], 2); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<div class="bg-white shadow rounded-lg p-6 mb-6">
    <h3 class="text-lg font-bold text-gray-700 mb-4">Totals</h3>
    <dl class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3 text-sm">
        <?php
        $totalsRows = [
            'Line items'         => $subtotals['items'],
            'Paper'              => $subtotals['papers'],
            'Ink'                => $subtotals['ink'],
            'Binding materials'  => $subtotals['binding'],
            'Pre-press labour'   => $subtotals['prepress'],
            'Press labour'       => $subtotals['press'],
            'Finishing labour'   => $subtotals['finishing'],
        ];
        foreach ($totalsRows as $label => $value):
            if ((float) $value <= 0) continue; ?>
            <div class="flex items-center justify-between bg-gray-50 rounded-lg p-3">
                <dt class="text-gray-500"><?php echo htmlspecialchars($label); ?></dt>
                <dd class="font-semibold text-gray-800">MK <?php echo number_format((float) $value, 2); ?></dd>
            </div>
        <?php endforeach; ?>
    </dl>

    <?php
    // Show the persisted breakdown (subtotal -> profit -> supervision ->
    // pre-VAT -> VAT -> grand total) so users can see exactly how the grand
    // total was built and what VAT % the invoice will inherit.
    $hasBreakdown = isset($est['subtotal_amount']) || isset($est['vat_percent']);
    if ($hasBreakdown):
        $row = function (string $label, $value, bool $isPercent = false, bool $emphasize = false) {
            $value = (float) $value;
            $cls = $emphasize ? 'font-semibold text-gray-900' : 'text-gray-700';
            return '<div class="flex items-center justify-between py-1.5 border-b border-gray-100 last:border-b-0">'
                . '<dt class="text-gray-500">' . htmlspecialchars($label) . '</dt>'
                . '<dd class="' . $cls . '">'
                . ($isPercent ? number_format($value, 2) . '%' : 'MK ' . number_format($value, 2))
                . '</dd></div>';
        };
        ?>
        <div class="mt-6 bg-gray-50 rounded-lg p-4">
            <h4 class="text-sm font-semibold text-gray-600 uppercase tracking-wide mb-3">Cost build-up</h4>
            <dl class="text-sm">
                <?php
                echo $row('Cost subtotal',                $est['subtotal_amount'] ?? 0);
                if ((float) ($est['cost_supervision_amount'] ?? 0) > 0) {
                    echo $row('Overtime / supervision',   $est['cost_supervision_amount']);
                }
                if ((float) ($est['profit_margin_percent'] ?? 0) > 0) {
                    echo $row('Profit margin',            $est['profit_margin_percent'], true);
                    echo $row('Profit amount',            $est['profit_amount'] ?? 0);
                }
                echo $row('Pre-VAT total',                $est['pre_vat_total'] ?? 0, false, true);
                echo $row('VAT rate',                     $est['vat_percent']   ?? 0, true);
                echo $row('VAT amount',                   $est['vat_amount']    ?? 0);
                ?>
            </dl>
            <p class="text-xs text-gray-500 mt-3">
                When this estimation is converted to an invoice the customer sees a single line at the
                <strong>Pre-VAT total</strong>, with VAT applied at the <strong>VAT rate</strong> shown above.
                Material rates stay internal.
            </p>
        </div>
    <?php endif; ?>

    <div class="flex items-center justify-between bg-green-600 text-white rounded-lg p-4 mt-4">
        <span class="text-lg font-semibold">Grand Total</span>
        <span class="text-3xl font-bold">MK <?php echo number_format((float) $est['total_amount'], 2); ?></span>
    </div>
</div>

<?php if (!empty($linkedInvoices)): ?>
    <div class="bg-white shadow rounded-lg p-6 mb-6">
        <h3 class="text-lg font-bold text-gray-700 mb-4">Linked invoices</h3>
        <ul class="divide-y divide-gray-100">
            <?php foreach ($linkedInvoices as $inv): ?>
                <li class="flex flex-wrap items-center justify-between gap-3 py-3">
                    <div>
                        <p class="font-semibold text-gray-800"><?php echo htmlspecialchars($inv['invoice_number']); ?></p>
                        <p class="text-xs text-gray-500">
                            <?php echo htmlspecialchars($inv['status']); ?> · generated
                            <?php echo htmlspecialchars($inv['generated_date']); ?>
                        </p>
                    </div>
                    <a href="<?php echo BASE_URL; ?>modules/invoices/view?id=<?php echo (int) $inv['id']; ?>"
                        class="text-sm text-blue-600 hover:underline">Open invoice</a>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<div class="bg-white shadow rounded-lg p-6 mb-6">
    <h3 class="text-lg font-bold text-gray-700 mb-4 flex items-center">
        <i data-lucide="history" class="mr-2 h-5 w-5 flex-shrink-0 text-blue-600" aria-hidden="true"></i>
        Status history
    </h3>
    <?php if (empty($statusHistory)): ?>
        <p class="text-sm text-gray-500 italic">No status changes recorded yet.</p>
    <?php else: ?>
        <div class="space-y-3">
            <?php foreach ($statusHistory as $entry): ?>
                <div class="border-l-4 border-blue-400 pl-4 py-2">
                    <div class="flex flex-wrap justify-between items-start gap-2">
                        <div>
                            <p class="font-semibold text-gray-800">
                                <?php if ($entry['old_status']): ?>
                                    <span class="text-gray-500"><?php echo htmlspecialchars($entry['old_status']); ?></span>
                                    <i data-lucide="arrow-right" class="mx-1 inline-block h-4 w-4 align-middle text-gray-500" aria-hidden="true"></i>
                                <?php endif; ?>
                                <span class="text-green-700"><?php echo htmlspecialchars($entry['new_status']); ?></span>
                            </p>
                            <p class="text-sm text-gray-600">
                                Changed by <strong><?php echo htmlspecialchars($entry['changed_by_name'] ?? 'System'); ?></strong>
                            </p>
                            <?php if (!empty($entry['change_reason'])): ?>
                                <p class="text-sm text-gray-700 mt-1 italic">
                                    Reason: <?php echo htmlspecialchars($entry['change_reason']); ?>
                                </p>
                            <?php endif; ?>
                        </div>
                        <div class="text-right">
                            <p class="text-xs text-gray-500"><?php echo date('M j, Y', strtotime($entry['changed_at'])); ?></p>
                            <p class="text-xs text-gray-500"><?php echo date('g:i A', strtotime($entry['changed_at'])); ?></p>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<!-- Status Change Modal -->
<div id="statusModal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
    <div class="relative top-20 mx-auto p-5 border w-96 shadow-lg rounded-md bg-white">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-lg font-bold text-gray-900">Change Estimation Status</h3>
            <button onclick="closeStatusModal()" class="text-gray-400 hover:text-gray-600">
                <i data-lucide="x" class="h-5 w-5" aria-hidden="true"></i>
            </button>
        </div>
        <form id="statusChangeForm" onsubmit="submitStatusChange(event)">
            <input type="hidden" id="modalEstimationId" name="estimation_id" value="<?php echo (int) $est['id']; ?>">

            <div class="mb-4">
                <p class="text-sm text-gray-600 mb-2">Current Status:</p>
                <p id="currentStatusDisplay" class="font-semibold text-gray-800 mb-4">
                    <?php echo EstimationStatusManager::getStatusBadgeHtml($est['status']); ?>
                </p>
            </div>

            <div class="mb-4">
                <label class="block text-gray-700 font-semibold mb-2">New Status</label>
                <select id="newStatusSelect" name="new_status"
                    class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500"
                    required>
                    <option value="">-- Select Status --</option>
                </select>
            </div>

            <div class="mb-4">
                <label class="block text-gray-700 font-semibold mb-2">Reason (Optional)</label>
                <textarea id="reasonInput" name="reason" rows="3"
                    class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500 resize-none"
                    placeholder="Enter reason for this status change..."></textarea>
            </div>

            <div class="flex gap-2 justify-end">
                <button type="button" onclick="closeStatusModal()"
                    class="px-4 py-2 border border-gray-300 rounded-lg hover:bg-gray-50 transition">Cancel</button>
                <button type="submit"
                    class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition flex items-center">
                    <i data-lucide="check" class="mr-1 inline-block h-4 w-4 flex-shrink-0" aria-hidden="true"></i> Update Status
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Delete confirmation modal -->
<div id="deleteModal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
    <div class="relative top-20 mx-auto p-6 border w-full max-w-md shadow-lg rounded-md bg-white">
        <div class="flex items-start justify-between mb-4">
            <div>
                <h3 class="text-lg font-bold text-gray-900">Delete estimation?</h3>
                <p class="text-sm text-gray-600 mt-1">
                    This permanently removes <strong><?php echo htmlspecialchars($est['estimation_number']); ?></strong>
                    and all of its related papers, ink, binding, labour and item rows.
                </p>
            </div>
            <button onclick="closeDeleteModal()" class="text-gray-400 hover:text-gray-600">
                <i data-lucide="x" class="h-5 w-5" aria-hidden="true"></i>
            </button>
        </div>
        <form method="POST" action="delete" class="flex justify-end gap-2 mt-6">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token('estimation_delete')); ?>">
            <input type="hidden" name="id" value="<?php echo (int) $est['id']; ?>">
            <button type="button" onclick="closeDeleteModal()"
                class="px-4 py-2 border border-gray-300 rounded-lg hover:bg-gray-50 transition">Cancel</button>
            <button type="submit"
                class="px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 transition flex items-center gap-1">
                <i data-lucide="trash-2" class="h-4 w-4" aria-hidden="true"></i> Delete permanently
            </button>
        </form>
    </div>
</div>

<script>
    const BASE_URL = '<?php echo BASE_URL; ?>';

    function openStatusModal() {
        const modal = document.getElementById('statusModal');
        const estimationId = document.getElementById('modalEstimationId').value;

        fetch(`${BASE_URL}modules/estimations/change_status?id=${estimationId}`)
            .then(r => { if (!r.ok) throw new Error(`HTTP ${r.status}`); return r.json(); })
            .then(data => {
                if (!data.success) throw new Error(data.message || 'Failed to load options');

                const select = document.getElementById('newStatusSelect');
                select.innerHTML = '<option value="">-- Select Status --</option>';
                if (data.allowed_transitions.length === 0) {
                    select.innerHTML += '<option disabled>No transitions available for this status</option>';
                } else {
                    data.allowed_transitions.forEach(t => {
                        const o = document.createElement('option');
                        o.value = t.value;
                        o.textContent = `${t.details.label} - ${t.details.description}`;
                        select.appendChild(o);
                    });
                }
                document.getElementById('reasonInput').value = '';
                modal.classList.remove('hidden');
                if (typeof window.refreshAppShellIcons === 'function') window.refreshAppShellIcons();
            })
            .catch(err => alert('Error loading status information: ' + err.message));
    }

    function closeStatusModal() {
        document.getElementById('statusModal').classList.add('hidden');
        document.getElementById('statusChangeForm').reset();
    }

    function submitStatusChange(e) {
        e.preventDefault();

        const estimationId = document.getElementById('modalEstimationId').value;
        const newStatus = document.getElementById('newStatusSelect').value;
        const reason = document.getElementById('reasonInput').value;

        if (!newStatus) {
            alert('Please select a new status');
            return;
        }

        const submitBtn = e.target.querySelector('button[type="submit"]');
        const originalText = submitBtn.innerHTML;
        submitBtn.innerHTML = '<i data-lucide="loader-circle" class="mr-1 inline-block h-4 w-4 animate-spin" aria-hidden="true"></i> Updating...';
        submitBtn.disabled = true;
        if (typeof window.refreshAppShellIcons === 'function') window.refreshAppShellIcons();

        const formData = new URLSearchParams();
        formData.append('estimation_id', estimationId);
        formData.append('new_status', newStatus);
        formData.append('reason', reason);

        fetch(`${BASE_URL}modules/estimations/change_status`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: formData.toString()
        })
            .then(async response => {
                const text = await response.text();
                let data;
                try { data = JSON.parse(text); } catch (e) { /* not JSON */ }
                if (!response.ok) throw new Error((data && data.message) || `HTTP Error: ${response.status}`);
                if (!data) throw new Error('Invalid server response (not JSON)');
                return data;
            })
            .then(data => {
                if (data.success) {
                    closeStatusModal();
                    location.reload();
                } else {
                    throw new Error(data.message || 'Unknown error');
                }
            })
            .catch(err => alert('Error updating status: ' + (err.message || 'Unknown error')))
            .finally(() => {
                submitBtn.innerHTML = originalText;
                submitBtn.disabled = false;
            });
    }

    function openDeleteModal() {
        document.getElementById('deleteModal').classList.remove('hidden');
        if (typeof window.refreshAppShellIcons === 'function') window.refreshAppShellIcons();
    }
    function closeDeleteModal() {
        document.getElementById('deleteModal').classList.add('hidden');
    }

    document.addEventListener('DOMContentLoaded', () => {
        const statusModal = document.getElementById('statusModal');
        statusModal.addEventListener('click', (e) => { if (e.target === statusModal) closeStatusModal(); });
        const deleteModal = document.getElementById('deleteModal');
        deleteModal.addEventListener('click', (e) => { if (e.target === deleteModal) closeDeleteModal(); });
        if (typeof window.refreshAppShellIcons === 'function') window.refreshAppShellIcons();
    });
</script>

<?php include '../../includes/footer.php'; ?>
