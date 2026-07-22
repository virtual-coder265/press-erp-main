<?php
/**
 * Full end-to-end estimation test: all sections filled, verify view subtotals
 * match calculated costs and grand total arithmetic.
 */
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/estimation_view_data_helper.php';
require_once __DIR__ . '/../libs/ProductionLabourMigrator.php';
require_once __DIR__ . '/../libs/EstimationAuditMigrator.php';

EstimationAuditMigrator::ensure($pdo);
ProductionLabourMigrator::ensure($pdo);

function fmtCurrency(float $value): string
{
    return 'MK' . number_format($value, 2, '.', ',');
}

function parseCurrency($value): float
{
    if ($value === null || $value === '') {
        return 0.0;
    }
    return (float) str_replace(['MK', ','], '', (string) $value);
}

function fetchMaterial(PDO $pdo, string $categoryLike, ?string $nameLike = null): ?array
{
    $rateSql = 'COALESCE((SELECT mr.rate FROM material_rates mr WHERE mr.material_id = m.id ORDER BY mr.effective_date DESC LIMIT 1), 100) AS rate';
    $sql = "SELECT m.id, m.name, m.unit, {$rateSql}
            FROM materials m
            INNER JOIN material_categories c ON c.id = m.category_id
            WHERE LOWER(c.name) LIKE :cat";
    $params = ['cat' => '%' . strtolower($categoryLike) . '%'];
    if ($nameLike !== null) {
        $sql .= ' AND LOWER(m.name) LIKE :name';
        $params['name'] = '%' . strtolower($nameLike) . '%';
    }
    $sql .= ' ORDER BY m.id ASC LIMIT 1';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function lineTotal(float $qty, float $rate): float
{
    return round($qty * $rate, 2);
}

// --- Fetch catalog rows ---------------------------------------------------
$proofing = fetchMaterial($pdo, 'paper', 'proofing') ?: fetchMaterial($pdo, 'paper');
$film = fetchMaterial($pdo, 'general', 'film') ?: fetchMaterial($pdo, 'paper', 'film');
$plate = fetchMaterial($pdo, 'plate') ?: fetchMaterial($pdo, 'paper', 'plate');
$separation = fetchMaterial($pdo, 'separation');
$paperMat = fetchMaterial($pdo, 'printing papers') ?: fetchMaterial($pdo, 'paper');
$inkCyan = fetchMaterial($pdo, 'printing inks', 'cyan') ?: fetchMaterial($pdo, 'printing inks');
$inkMagenta = fetchMaterial($pdo, 'printing inks', 'magenta') ?: $inkCyan;
$inkYellow = fetchMaterial($pdo, 'printing inks', 'yellow') ?: $inkCyan;
$inkBlack = fetchMaterial($pdo, 'printing inks', 'black') ?: $inkCyan;
$bindingMat = fetchMaterial($pdo, 'binding');
$consumableMat = fetchMaterial($pdo, 'printing consumables');

$missing = [];
foreach ([
    'proofing' => $proofing,
    'film' => $film,
    'plate' => $plate,
    'separation' => $separation,
    'paper' => $paperMat,
    'ink cyan' => $inkCyan,
    'binding' => $bindingMat,
    'consumable' => $consumableMat,
] as $label => $row) {
    if (!$row) {
        $missing[] = $label;
    }
}
if ($missing) {
    fwrite(STDERR, 'Missing catalog materials: ' . implode(', ', $missing) . "\n");
    exit(1);
}

$userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id ASC LIMIT 1')->fetchColumn() ?: 1);
$estNumber = 'TEST-FULL-' . date('YmdHis');

// --- Section costs (known values) -----------------------------------------
$stdMaterials = [
    ['id' => (int) $proofing['id'], 'name' => $proofing['name'], 'qty' => 50, 'rate' => (float) $proofing['rate']],
    ['id' => (int) $film['id'], 'name' => $film['name'], 'qty' => 10, 'rate' => (float) $film['rate']],
    ['id' => (int) $plate['id'], 'name' => $plate['name'], 'qty' => 4, 'rate' => (float) $plate['rate']],
    ['id' => (int) $separation['id'], 'name' => $separation['name'], 'qty' => 2, 'rate' => (float) $separation['rate']],
];
foreach ($stdMaterials as &$sm) {
    $sm['total'] = lineTotal($sm['qty'], $sm['rate']);
}
unset($sm);
$costStandardMaterials = round(array_sum(array_column($stdMaterials, 'total')), 2);

$papers = [
    [
        'type' => 'Cover',
        'size' => 'A4',
        'grammage' => 300,
        'color' => 'White',
        'material_id' => (int) $paperMat['id'],
        'sheets' => 500,
        'rate' => (float) $paperMat['rate'],
    ],
    [
        'type' => 'Original',
        'size' => 'A4',
        'grammage' => 80,
        'color' => 'White',
        'material_id' => (int) $paperMat['id'],
        'sheets' => 2000,
        'rate' => (float) $paperMat['rate'],
    ],
    [
        'type' => 'Duplicate',
        'size' => 'A4',
        'grammage' => 80,
        'color' => 'White',
        'material_id' => (int) $paperMat['id'],
        'sheets' => 2000,
        'rate' => (float) $paperMat['rate'],
    ],
    [
        'type' => 'Extra',
        'size' => 'A3',
        'grammage' => 120,
        'color' => 'Cream',
        'material_id' => (int) $paperMat['id'],
        'sheets' => 300,
        'rate' => (float) $paperMat['rate'] + 50,
    ],
];
foreach ($papers as &$p) {
    $p['total'] = lineTotal($p['sheets'], $p['rate']);
}
unset($p);
$costPaper = round(array_sum(array_column($papers, 'total')), 2);

// Ink formula_breakdown: formula kgs = (base/1000 * height/1000) * pages * copies * 0.5 / 0.886 / 1000
$inkBase = 210;
$inkHeight = 297;
$inkPages = 16;
$inkCopies = 5000;
$formulaKgs = round(($inkBase / 1000 * $inkHeight / 1000) * $inkPages * $inkCopies * 0.5 / 0.886 / 1000, 4);

$inkColours = [
    ['name' => 'C', 'pct' => 25, 'rate' => (float) $inkCyan['rate'], 'material_id' => (int) $inkCyan['id']],
    ['name' => 'M', 'pct' => 25, 'rate' => (float) $inkMagenta['rate'], 'material_id' => (int) $inkMagenta['id']],
    ['name' => 'Y', 'pct' => 25, 'rate' => (float) $inkYellow['rate'], 'material_id' => (int) $inkYellow['id']],
    ['name' => 'K', 'pct' => 25, 'rate' => (float) $inkBlack['rate'], 'material_id' => (int) $inkBlack['id']],
];
foreach ($inkColours as &$ic) {
    $ic['kgs'] = round($formulaKgs * ($ic['pct'] / 100), 4);
    $ic['total'] = lineTotal($ic['kgs'], $ic['rate']);
}
unset($ic);
$costInk = round(array_sum(array_column($inkColours, 'total')), 2);

$bindingRows = [
    [
        'material_id' => (int) $bindingMat['id'],
        'name' => $bindingMat['name'],
        'unit' => $bindingMat['unit'] ?? 'kg',
        'qty' => 3,
        'rate' => (float) $bindingMat['rate'],
    ],
];
$bindingRows[0]['total'] = lineTotal($bindingRows[0]['qty'], $bindingRows[0]['rate']);
$costBinding = $bindingRows[0]['total'];

$prepressRows = [
    ['name' => 'Design', 'hrs' => 4, 'rate' => 8500],
    ['name' => 'Platemaking', 'hrs' => 2, 'rate' => 12000],
];
foreach ($prepressRows as &$pp) {
    $pp['total'] = lineTotal($pp['hrs'], $pp['rate']);
}
unset($pp);
$costPrepress = round(array_sum(array_column($prepressRows, 'total')), 2);

$pressRows = [
    [
        'machine_name' => 'Heidelberg SM 74',
        'colours' => 4,
        'mr_hrs' => 1.5,
        'mr_rate' => 15000,
        'impressions' => 10000,
        'iph' => 5000,
        'run_rate' => 12000,
    ],
];
$pressRows[0]['mr_total'] = lineTotal($pressRows[0]['mr_hrs'], $pressRows[0]['mr_rate']);
$pressRows[0]['run_hrs'] = round($pressRows[0]['impressions'] / $pressRows[0]['iph'], 4);
$pressRows[0]['run_total'] = lineTotal($pressRows[0]['run_hrs'], $pressRows[0]['run_rate']);
$pressRows[0]['machine_total'] = round($pressRows[0]['mr_total'] + $pressRows[0]['run_total'], 2);
$costPress = $pressRows[0]['machine_total'];

$finishingRows = [
    [
        'name' => 'Saddle Stitching',
        'measure' => 'books',
        'impressions' => 5000,
        'iph' => 2500,
        'rate' => 9500,
    ],
    [
        'name' => 'Trimming',
        'measure' => 'items',
        'impressions' => 5000,
        'iph' => 3000,
        'rate' => 8000,
    ],
];
foreach ($finishingRows as &$fr) {
    $fr['hrs'] = round($fr['impressions'] / $fr['iph'], 4);
    $fr['total'] = lineTotal($fr['hrs'], $fr['rate']);
}
unset($fr);
$costFinishing = round(array_sum(array_column($finishingRows, 'total')), 2);

$costLabourTotal = round($costPrepress + $costPress + $costFinishing, 2);

$consumableQty = 2;
$consumableRate = (float) $consumableMat['rate'];
$consumableLineTotal = lineTotal($consumableQty, $consumableRate);
$consumableMisc = 2500.00;
$costConsumables = round($consumableLineTotal + $consumableMisc, 2);

$costSupervision = 5000.00;
$profitMargin = 20.0;
$vatPercent = 17.5;

$subtotal = round(
    $costStandardMaterials
    + $costPaper
    + $costInk
    + $costBinding
    + $costLabourTotal
    + $costConsumables,
    2
);

$baseCost = round($subtotal + $costSupervision, 2);
$profitAmount = round($baseCost * ($profitMargin / 100), 2);
$preVatTotal = round($baseCost + $profitAmount, 2);
$vatAmount = round($preVatTotal * ($vatPercent / 100), 2);
$grandTotal = round($preVatTotal + $vatAmount, 2);

$expectedSections = [
    'standard_materials' => $costStandardMaterials,
    'papers' => $costPaper,
    'ink' => $costInk,
    'binding' => $costBinding,
    'prepress' => $costPrepress,
    'press' => $costPress,
    'finishing' => $costFinishing,
    'consumables' => $costConsumables,
];

// --- Insert into DB (mirrors save.php structure) --------------------------
$pdo->beginTransaction();
try {
    $pdo->prepare(
        'INSERT INTO estimations (
            estimation_number, customer_name, customer_email, customer_phone, job_description,
            total_amount, created_by, status, last_edited_at, last_edited_by,
            subtotal_amount, profit_margin_percent, profit_amount,
            cost_supervision_amount, cost_consumables_amount,
            vat_percent, vat_amount, pre_vat_total
        ) VALUES (
            :num, :name, :email, :phone, :job,
            :total, :user, :status, NOW(), :editor,
            :subtotal, :profit_pct, :profit_amt,
            :supervision, :consumables,
            :vat_pct, :vat_amt, :pre_vat
        )'
    )->execute([
        'num' => $estNumber,
        'name' => 'Full Test Customer Ltd',
        'email' => 'test@example.com',
        'phone' => '+265 999 123 456',
        'job' => 'Annual Report 2026 (Booklet)' . "\n" . 'Full section E2E test job with all fields populated.',
        'total' => $grandTotal,
        'user' => $userId,
        'editor' => $userId,
        'status' => 'Draft',
        'subtotal' => $subtotal,
        'profit_pct' => $profitMargin,
        'profit_amt' => $profitAmount,
        'supervision' => $costSupervision,
        'consumables' => $costConsumables,
        'vat_pct' => $vatPercent,
        'vat_amt' => $vatAmount,
        'pre_vat' => $preVatTotal,
    ]);
    $estId = (int) $pdo->lastInsertId();

    $itemStmt = $pdo->prepare(
        'INSERT INTO estimation_items (estimation_id, item_type, description, quantity, unit_price, total_price, details_json)
         VALUES (:eid, :type, :desc, :qty, :price, :total, :json)'
    );

    foreach ($stdMaterials as $sm) {
        $itemStmt->execute([
            'eid' => $estId,
            'type' => 'Material',
            'desc' => $sm['name'],
            'qty' => $sm['qty'],
            'price' => $sm['rate'],
            'total' => $sm['total'],
            'json' => json_encode(['material_id' => $sm['id']]),
        ]);
    }

    $paperStmt = $pdo->prepare(
        'INSERT INTO estimation_papers
         (estimation_id, material_id, paper_type, paper_size, paper_grammage, paper_color, paper_sheets, paper_rate, paper_total, sort_order)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    foreach ($papers as $i => $p) {
        $paperStmt->execute([
            $estId, $p['material_id'], $p['type'], $p['size'], $p['grammage'], $p['color'],
            $p['sheets'], $p['rate'], $p['total'], $i,
        ]);
    }
    $itemStmt->execute([
        'eid' => $estId, 'type' => 'Material', 'desc' => 'Paper Stock',
        'qty' => 1, 'price' => $costPaper, 'total' => $costPaper,
        'json' => json_encode(['multi_paper' => true]),
    ]);

    $inkStmt = $pdo->prepare(
        'INSERT INTO estimation_ink_colours (estimation_id, material_id, colour_name, kgs, rate, total, sort_order)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    foreach ($inkColours as $i => $ic) {
        $inkStmt->execute([$estId, $ic['material_id'], $ic['name'], $ic['kgs'], $ic['rate'], $ic['total'], $i]);
    }
    $itemStmt->execute([
        'eid' => $estId, 'type' => 'Material', 'desc' => 'Ink',
        'qty' => 1, 'price' => $costInk, 'total' => $costInk,
        'json' => json_encode([
            'mode' => 'formula_breakdown',
            'base' => $inkBase,
            'height' => $inkHeight,
            'pages' => $inkPages,
            'copies' => $inkCopies,
            'kgs' => $formulaKgs,
        ]),
    ]);

    $bindStmt = $pdo->prepare(
        'INSERT INTO estimation_binding_materials (estimation_id, material_id, material_name, unit, quantity, rate, total, sort_order)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );
    foreach ($bindingRows as $i => $br) {
        $bindStmt->execute([
            $estId, $br['material_id'], $br['name'], $br['unit'], $br['qty'], $br['rate'], $br['total'], $i,
        ]);
    }
    $itemStmt->execute([
        'eid' => $estId, 'type' => 'Material', 'desc' => 'Binding Materials',
        'qty' => 1, 'price' => $costBinding, 'total' => $costBinding,
        'json' => json_encode(['binding' => true]),
    ]);

    $ppStmt = $pdo->prepare(
        'INSERT INTO estimation_prepress_labour (estimation_id, labour_name, unit, hrs, rate, total, sort_order)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    foreach ($prepressRows as $i => $pp) {
        $ppStmt->execute([$estId, $pp['name'], 'hrs', $pp['hrs'], $pp['rate'], $pp['total'], $i]);
    }
    $itemStmt->execute([
        'eid' => $estId, 'type' => 'Labor', 'desc' => 'Pre-press Labour',
        'qty' => 1, 'price' => $costPrepress, 'total' => $costPrepress, 'json' => null,
    ]);

    $pressStmt = $pdo->prepare(
        'INSERT INTO estimation_press_labour
         (estimation_id, machine_name, colours, make_ready_hrs, make_ready_rate, make_ready_total,
          impressions, iph, running_hrs, running_rate, running_total, machine_total, sort_order)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    foreach ($pressRows as $i => $pr) {
        $pressStmt->execute([
            $estId, $pr['machine_name'], $pr['colours'],
            $pr['mr_hrs'], $pr['mr_rate'], $pr['mr_total'],
            $pr['impressions'], $pr['iph'], $pr['run_hrs'], $pr['run_rate'], $pr['run_total'],
            $pr['machine_total'], $i,
        ]);
    }
    $itemStmt->execute([
        'eid' => $estId, 'type' => 'Labor', 'desc' => 'Press Labour',
        'qty' => 1, 'price' => $costPress, 'total' => $costPress, 'json' => null,
    ]);

    $finStmt = $pdo->prepare(
        'INSERT INTO estimation_finishing_labour
         (estimation_id, labour_name, measure_type, impressions, iph, hrs, quantity, rate, total, sort_order)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    foreach ($finishingRows as $i => $fr) {
        $finStmt->execute([
            $estId, $fr['name'], $fr['measure'], $fr['impressions'], $fr['iph'],
            $fr['hrs'], $fr['hrs'], $fr['rate'], $fr['total'], $i,
        ]);
    }
    $itemStmt->execute([
        'eid' => $estId, 'type' => 'Labor', 'desc' => 'Finishing Labour',
        'qty' => 1, 'price' => $costFinishing, 'total' => $costFinishing, 'json' => null,
    ]);

    $itemStmt->execute([
        'eid' => $estId, 'type' => 'Material', 'desc' => $consumableMat['name'],
        'qty' => $consumableQty, 'price' => $consumableRate, 'total' => $consumableLineTotal,
        'json' => json_encode([
            'material_id' => (int) $consumableMat['id'],
            'consumable' => true,
            'unit' => $consumableMat['unit'] ?? '',
        ]),
    ]);
    $itemStmt->execute([
        'eid' => $estId, 'type' => 'Material', 'desc' => 'Miscellaneous consumables',
        'qty' => 1, 'price' => $consumableMisc, 'total' => $consumableMisc,
        'json' => json_encode(['consumable_misc' => true]),
    ]);

    $itemStmt->execute([
        'eid' => $estId, 'type' => 'Labor', 'desc' => 'Overtime & Supervision',
        'qty' => 1, 'price' => $costSupervision, 'total' => $costSupervision, 'json' => null,
    ]);

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $e;
}

// --- Verify view bundle ---------------------------------------------------
$estRow = $pdo->prepare('SELECT * FROM estimations WHERE id = ?');
$estRow->execute([$estId]);
$est = $estRow->fetch(PDO::FETCH_ASSOC);

$bundle = estimation_load_detail_bundle($pdo, $estId);
$subtotals = $bundle['subtotals'];
$errors = [];
$passes = [];

foreach ($expectedSections as $key => $expected) {
    $actual = round((float) ($subtotals[$key] ?? 0), 2);
    if (abs($actual - $expected) > 0.02) {
        $errors[] = sprintf('Section "%s": expected MK %.2f, view subtotal MK %.2f', $key, $expected, $actual);
    } else {
        $passes[] = sprintf('Section "%s": MK %.2f', $key, $actual);
    }
}

$sectionsSum = round(array_sum($expectedSections), 2);
if (abs($sectionsSum - $subtotal) > 0.02) {
    $errors[] = sprintf('Section sum mismatch: sections MK %.2f vs subtotal MK %.2f', $sectionsSum, $subtotal);
} else {
    $passes[] = sprintf('All sections sum to subtotal: MK %.2f', $subtotal);
}

if (abs((float) $est['subtotal_amount'] - $subtotal) > 0.02) {
    $errors[] = 'Stored subtotal_amount mismatch';
} else {
    $passes[] = 'Stored subtotal_amount matches';
}

$derivedGrand = round(
    round($subtotal + $costSupervision, 2)
    + round(round($subtotal + $costSupervision, 2) * ($profitMargin / 100), 2),
    2
);
$derivedGrand = round($derivedGrand + round($derivedGrand * ($vatPercent / 100), 2), 2);

if (abs((float) $est['total_amount'] - $grandTotal) > 0.02) {
    $errors[] = sprintf('Grand total mismatch: expected MK %.2f, stored MK %.2f', $grandTotal, (float) $est['total_amount']);
} else {
    $passes[] = sprintf('Grand total: MK %.2f', $grandTotal);
}

if (abs((float) $est['pre_vat_total'] - $preVatTotal) > 0.02) {
    $errors[] = 'Pre-VAT total mismatch';
}
if (abs((float) $est['vat_amount'] - $vatAmount) > 0.02) {
    $errors[] = 'VAT amount mismatch';
}
if (abs((float) $est['profit_amount'] - $profitAmount) > 0.02) {
    $errors[] = 'Profit amount mismatch';
}

// Row counts
$rowChecks = [
    'standard_materials' => [count($bundle['standard_materials']), 4],
    'papers' => [count($bundle['papers']), 4],
    'inkRows' => [count($bundle['inkRows']), 4],
    'binding' => [count($bundle['binding']), 1],
    'prepress' => [count($bundle['prepress']), 2],
    'press' => [count($bundle['press']), 1],
    'finishing' => [count($bundle['finishing']), 2],
    'consumables' => [count($bundle['consumables']), 2],
];
foreach ($rowChecks as $key => [$actual, $expectedCount]) {
    if ($actual !== $expectedCount) {
        $errors[] = sprintf('Row count %s: expected %d, got %d', $key, $expectedCount, $actual);
    } else {
        $passes[] = sprintf('Row count %s: %d rows', $key, $actual);
    }
}

// --- Report ----------------------------------------------------------------
echo "=== FULL ESTIMATION E2E TEST ===\n";
echo "Estimation #{$estId} ({$estNumber})\n\n";

echo "--- Expected section costs ---\n";
foreach ($expectedSections as $label => $value) {
    echo sprintf("  %-22s MK %s\n", ucfirst(str_replace('_', ' ', $label)) . ':', number_format($value, 2));
}
echo sprintf("  %-22s MK %s\n", 'Subtotal:', number_format($subtotal, 2));
echo sprintf("  %-22s MK %s\n", 'Supervision:', number_format($costSupervision, 2));
echo sprintf("  %-22s MK %s\n", 'Profit (20%):', number_format($profitAmount, 2));
echo sprintf("  %-22s MK %s\n", 'Pre-VAT:', number_format($preVatTotal, 2));
echo sprintf("  %-22s MK %s\n", 'VAT (17.5%):', number_format($vatAmount, 2));
echo sprintf("  %-22s MK %s\n", 'Grand Total:', number_format($grandTotal, 2));

echo "\n--- Verification ---\n";
foreach ($passes as $p) {
    echo "  PASS: {$p}\n";
}

if ($errors) {
    echo "\nFAILED (" . count($errors) . " errors):\n";
    foreach ($errors as $error) {
        echo "  FAIL: {$error}\n";
    }
    exit(1);
}

echo "\nALL CHECKS PASSED.\n";
echo "View in browser: http://localhost/press-erp-main/modules/estimations/view?id={$estId}\n";
exit(0);
