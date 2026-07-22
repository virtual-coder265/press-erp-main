<?php
/**
 * Create a sample estimation with consumables and verify view bundle fields.
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/estimation_view_data_helper.php';

function parseCurrency($value): float
{
    if ($value === null || $value === '') {
        return 0.0;
    }
    $cleaned = str_replace(['MK', ','], '', (string) $value);
    return (float) $cleaned;
}

$matStmt = $pdo->query(
    "SELECT m.id, m.name, m.unit, COALESCE(
        (SELECT mr.rate FROM material_rates mr WHERE mr.material_id = m.id ORDER BY mr.effective_date DESC LIMIT 1),
        100
    ) AS rate
     FROM materials m
     INNER JOIN material_categories c ON c.id = m.category_id
     WHERE LOWER(c.name) = 'printing consumables'
     ORDER BY m.id ASC
     LIMIT 1"
);
$material = $matStmt->fetch(PDO::FETCH_ASSOC);
if (!$material) {
    fwrite(STDERR, "No printing consumables material found in catalog.\n");
    exit(1);
}

$matId = (int) $material['id'];
$qty = 5;
$rate = (float) $material['rate'];
$lineTotal = round($qty * $rate, 2);
$misc = 1500.50;
$costConsumables = parseCurrency('MK' . number_format($lineTotal + $misc, 2));

$userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id ASC LIMIT 1')->fetchColumn() ?: 1);
$estNumber = 'TEST-CONS-' . date('YmdHis');

$pdo->beginTransaction();
try {
    $pdo->prepare(
        'INSERT INTO estimations (
            estimation_number, customer_name, job_description, total_amount, created_by, status,
            last_edited_at, last_edited_by, subtotal_amount, profit_margin_percent, profit_amount,
            cost_supervision_amount, cost_consumables_amount, vat_percent, vat_amount, pre_vat_total
        ) VALUES (
            :num, :name, :job, :total, :user, :status,
            NOW(), :editor, :subtotal, 20, 0,
            0, :consumables, 17.5, 0, :pre_vat
        )'
    )->execute([
        'num' => $estNumber,
        'name' => 'Consumables Test Customer',
        'job' => 'Consumables verification job (Test)',
        'total' => $costConsumables,
        'user' => $userId,
        'editor' => $userId,
        'status' => 'Draft',
        'subtotal' => $costConsumables,
        'pre_vat' => $costConsumables,
        'consumables' => $costConsumables,
    ]);
    $estId = (int) $pdo->lastInsertId();

    $itemStmt = $pdo->prepare(
        'INSERT INTO estimation_items (estimation_id, item_type, description, quantity, unit_price, total_price, details_json)
         VALUES (:eid, :type, :desc, :qty, :price, :total, :json)'
    );

    $itemStmt->execute([
        'eid' => $estId,
        'type' => 'Material',
        'desc' => $material['name'],
        'qty' => $qty,
        'price' => $rate,
        'total' => $lineTotal,
        'json' => json_encode([
            'material_id' => $matId,
            'consumable' => true,
            'unit' => (string) ($material['unit'] ?? ''),
        ]),
    ]);

    $itemStmt->execute([
        'eid' => $estId,
        'type' => 'Material',
        'desc' => 'Miscellaneous consumables',
        'qty' => 1,
        'price' => $misc,
        'total' => $misc,
        'json' => json_encode(['consumable_misc' => true]),
    ]);

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $e;
}

$estRow = $pdo->prepare('SELECT * FROM estimations WHERE id = ?');
$estRow->execute([$estId]);
$est = $estRow->fetch(PDO::FETCH_ASSOC);

$bundle = estimation_load_detail_bundle($pdo, $estId);
$errors = [];

if ((float) ($est['cost_consumables_amount'] ?? 0) !== $costConsumables) {
    $errors[] = 'cost_consumables_amount mismatch';
}
if (count($bundle['consumables'] ?? []) !== 2) {
    $errors[] = 'expected 2 consumable rows, got ' . count($bundle['consumables'] ?? []);
}
if (abs((float) ($bundle['subtotals']['consumables'] ?? 0) - $costConsumables) > 0.01) {
    $errors[] = 'consumables subtotal mismatch';
}

$catalogRow = null;
$miscRow = null;
foreach ($bundle['consumables'] as $row) {
    $details = estimation_decode_item_details($row['details_json'] ?? null);
    if (!empty($details['consumable'])) {
        $catalogRow = $row;
    }
    if (!empty($details['consumable_misc'])) {
        $miscRow = $row;
    }
}

if (!$catalogRow) {
    $errors[] = 'catalog consumable row missing from view bundle';
} else {
    if ((int) estimation_decode_item_details($catalogRow['details_json'])['material_id'] !== $matId) {
        $errors[] = 'catalog material_id mismatch';
    }
    if ((float) $catalogRow['quantity'] !== (float) $qty) {
        $errors[] = 'catalog qty mismatch';
    }
    if (abs((float) $catalogRow['unit_price'] - $rate) > 0.01) {
        $errors[] = 'catalog rate mismatch';
    }
}

if (!$miscRow) {
    $errors[] = 'misc consumable row missing from view bundle';
} elseif (abs((float) $miscRow['total_price'] - $misc) > 0.01) {
    $errors[] = 'misc total mismatch';
}

echo "Created estimation #{$estId} ({$estNumber})\n";
echo "Material: {$material['name']} qty={$qty} rate={$rate} line={$lineTotal}\n";
echo "Misc: {$misc}\n";
echo "Stored cost_consumables_amount: {$est['cost_consumables_amount']}\n";
echo "View consumables rows: " . count($bundle['consumables']) . "\n";
echo "View consumables subtotal: " . ($bundle['subtotals']['consumables'] ?? 0) . "\n";

if ($errors) {
    echo "FAILED:\n";
    foreach ($errors as $error) {
        echo " - {$error}\n";
    }
    exit(1);
}

echo "PASS: all consumable fields available for view.\n";
echo "Open: modules/estimations/view?id={$estId}\n";
