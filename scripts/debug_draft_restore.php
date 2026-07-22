<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/estimation_draft_restore_helper.php';

$estId = (int) ($argv[1] ?? 44);
$est = $pdo->query("SELECT * FROM estimations WHERE id = {$estId}")->fetch(PDO::FETCH_ASSOC);
if (!$est) {
    fwrite(STDERR, "Estimation {$estId} not found\n");
    exit(1);
}

$res = estimation_resolve_draft_fields($pdo, $est);
echo "source={$res['source']} repaired=" . ($res['repaired'] ? 'yes' : 'no') . "\n";
echo "completeness score=" . estimation_draft_completeness_score($res['fields']) . "\n";
echo "draft_data null? " . (empty($est['draft_data']) ? 'yes' : 'no') . "\n\n";

$f = $res['fields'];
$keys = [
    'material_qty', 'paper_sheets', 'paper_type', 'ink_colour', 'binding_mat_qty',
    'prepress_hrs', 'press_impressions', 'finishing_hrs', 'consumable_mat_qty',
    'cost_prepress', 'cost_press', 'cost_finishing', 'cost_binding', 'cost_paper', 'cost_ink',
    'cost_consumables_misc', 'subtotal', 'grand_total', 'profit_margin', 'cost_labour',
];
foreach ($keys as $k) {
    $v = $f[$k] ?? 'MISSING';
    if (is_array($v)) {
        $v = count($v) . ' items: ' . json_encode($v);
    }
    echo "{$k}={$v}\n";
}

$dbOnly = estimation_draft_fields_from_database($pdo, $est);
echo "\nDB has values: " . (estimation_draft_has_wizard_values($dbOnly) ? 'yes' : 'no') . "\n";
if (!empty($est['draft_data'])) {
    $json = json_decode($est['draft_data'], true) ?: [];
    echo "JSON has values: " . (estimation_draft_has_wizard_values($json) ? 'yes' : 'no') . "\n";
    echo "\n--- JSON vs DB ink ---\n";
    echo 'JSON cost_ink: ' . ($json['cost_ink'] ?? 'MISSING') . "\n";
    echo 'DB cost_ink: ' . ($dbOnly['cost_ink'] ?? 'MISSING') . "\n";
    echo 'JSON ink_colour_kgs: ' . json_encode($json['ink_colour_kgs'] ?? []) . "\n";
    echo 'DB ink_colour_kgs: ' . json_encode($dbOnly['ink_colour_kgs'] ?? []) . "\n";
    echo "\n--- JSON vs DB materials ---\n";
    echo 'JSON material_qty: ' . json_encode($json['material_qty'] ?? []) . "\n";
    echo 'ink fields: ' . json_encode([
        'mode' => $f['ink_calc_mode'] ?? null,
        'base' => $f['ink_measure_base'] ?? null,
        'pages' => $f['ink_pages'] ?? null,
        'copies' => $f['ink_quantity_copies'] ?? null,
        'pct' => $f['ink_colour_pct'] ?? null,
        'kgs' => $f['ink_colour_kgs'] ?? null,
        'rate' => $f['ink_colour_rate'] ?? null,
        'total' => $f['ink_colour_total'] ?? null,
        'cost_ink' => $f['cost_ink'] ?? null,
    ], JSON_PRETTY_PRINT) . "\n";
}
