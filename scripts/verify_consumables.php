<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/estimation_view_data_helper.php';

echo "Recent estimations:\n";
$stmt = $pdo->query('SELECT id, estimation_number, cost_consumables_amount, status FROM estimations ORDER BY id DESC LIMIT 5');
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
    echo json_encode($r) . "\n";
}

echo "\nItems with consumable flag:\n";
$stmt = $pdo->query("SELECT ei.estimation_id, ei.description, ei.quantity, ei.unit_price, ei.total_price, ei.details_json
    FROM estimation_items ei WHERE ei.details_json LIKE '%consumable%' ORDER BY ei.id DESC LIMIT 10");
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
    echo json_encode($r) . "\n";
}

$estId = (int) ($argv[1] ?? 0);
if ($estId > 0) {
    $bundle = estimation_load_detail_bundle($pdo, $estId);
    echo "\nPartition for estimation #{$estId}:\n";
    echo 'standard_materials: ' . count($bundle['standard_materials']) . "\n";
    echo 'rollup_items: ' . count($bundle['rollup_items']) . "\n";
    echo 'other_items: ' . count($bundle['other_items']) . "\n";
    echo 'consumables key exists: ' . (isset($bundle['consumables']) ? 'yes (' . count($bundle['consumables']) . ' rows)' : 'no') . "\n";
    foreach ($bundle['standard_materials'] as $row) {
        if (str_contains((string) ($row['details_json'] ?? ''), 'consumable')) {
            echo '  std misclassified: ' . ($row['description'] ?? '') . "\n";
        }
    }
}
