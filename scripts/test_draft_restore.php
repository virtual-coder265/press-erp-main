<?php
/**
 * Verify draft restore merges DB detail with stale draft_data JSON.
 */
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/estimation_view_data_helper.php';
require_once __DIR__ . '/../includes/estimation_draft_restore_helper.php';

$estId = (int) ($argv[1] ?? 44);
$est = $pdo->prepare('SELECT * FROM estimations WHERE id = ?');
$est->execute([$estId]);
$estRow = $est->fetch(PDO::FETCH_ASSOC);
if (!$estRow) {
    fwrite(STDERR, "Estimation {$estId} not found\n");
    exit(1);
}

$bundle = estimation_load_detail_bundle($pdo, $estId);
$res = estimation_resolve_draft_fields($pdo, $estRow);
$fields = $res['fields'];
$errors = [];

$expectedMaterials = 4;
if (count($fields['material_qty'] ?? []) !== $expectedMaterials) {
    $errors[] = 'material_qty should have ' . $expectedMaterials . ' slots, got ' . count($fields['material_qty'] ?? []);
}
$nonZeroMaterials = array_filter($fields['material_qty'] ?? [], static fn($q) => (float) $q > 0);
if (count($nonZeroMaterials) < 4) {
    $errors[] = 'expected 4 non-zero standard material qty values, got ' . count($nonZeroMaterials);
}

$inkCost = estimation_draft_parse_amount($fields['cost_ink'] ?? 0);
$viewInk = (float) ($bundle['subtotals']['ink'] ?? 0);
if ($inkCost <= 0 && $viewInk > 0) {
    $errors[] = "cost_ink is zero but view ink subtotal is {$viewInk}";
}
if ($viewInk > 0 && abs($inkCost - $viewInk) > 0.02) {
    $errors[] = "cost_ink {$inkCost} != view ink {$viewInk}";
}

$pctValues = $fields['ink_colour_pct'] ?? [];
$kgsValues = $fields['ink_colour_kgs'] ?? [];
$filledPct = array_filter($pctValues, static fn($v) => trim((string) $v) !== '' && (float) $v > 0);
$filledKgs = array_filter($kgsValues, static fn($v) => (float) $v > 0);
if ($viewInk > 0 && count($filledKgs) >= 4 && count($filledPct) < 4) {
    $errors[] = 'ink_colour_pct not derived for all rows (step 4 would recalc to zero)';
}

$subtotal = estimation_draft_parse_amount($fields['subtotal'] ?? 0);
$sectionSum = round(
    (float) ($bundle['subtotals']['standard_materials'] ?? 0)
    + (float) ($bundle['subtotals']['papers'] ?? 0)
    + (float) ($bundle['subtotals']['ink'] ?? 0)
    + (float) ($bundle['subtotals']['binding'] ?? 0)
    + (float) ($bundle['subtotals']['prepress'] ?? 0)
    + (float) ($bundle['subtotals']['press'] ?? 0)
    + (float) ($bundle['subtotals']['finishing'] ?? 0)
    + (float) ($bundle['subtotals']['consumables'] ?? 0),
    2
);
if (abs($subtotal - $sectionSum) > 1.0) {
    $errors[] = "subtotal {$subtotal} != section sum {$sectionSum}";
}

if (!in_array($res['source'], ['database', 'database_merged', 'draft_data_merged'], true) && !empty($estRow['draft_data'])) {
    $dbScore = estimation_draft_completeness_score(estimation_draft_fields_from_database($pdo, $estRow));
    $jsonScore = estimation_draft_completeness_score(json_decode((string) $estRow['draft_data'], true) ?: []);
    if ($dbScore > $jsonScore && $res['source'] === 'draft_data') {
        $errors[] = 'stale draft_data preferred over richer database (source=draft_data)';
    }
}

echo "Estimation #{$estId}\n";
echo "Source: {$res['source']} (repaired=" . ($res['repaired'] ? 'yes' : 'no') . ")\n";
echo "Completeness: " . estimation_draft_completeness_score($fields) . "\n";
echo "cost_ink: " . ($fields['cost_ink'] ?? 'MISSING') . "\n";
echo "material_qty: " . json_encode($fields['material_qty'] ?? []) . "\n";
echo "subtotal: " . ($fields['subtotal'] ?? 'MISSING') . "\n";
echo "grand_total: " . ($fields['grand_total'] ?? 'MISSING') . "\n";

if ($errors) {
    echo "\nFAILED:\n";
    foreach ($errors as $error) {
        echo " - {$error}\n";
    }
    exit(1);
}

echo "\nPASS: draft restore fields align with view data.\n";
exit(0);
