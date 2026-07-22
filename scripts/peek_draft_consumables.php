<?php
require_once __DIR__ . '/../config/database.php';

$id = (int) ($argv[1] ?? 42);
$stmt = $pdo->prepare('SELECT draft_data, cost_consumables_amount FROM estimations WHERE id = ?');
$stmt->execute([$id]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
$data = json_decode($row['draft_data'] ?? '', true) ?: [];
$keys = ['consumable_mat_id', 'consumable_mat_qty', 'consumable_mat_rate', 'consumable_mat_total', 'consumable_mat_unit', 'cost_consumables', 'cost_consumables_misc'];
echo "Est #$id cost_consumables_amount: " . ($row['cost_consumables_amount'] ?? 'null') . "\n";
foreach ($keys as $k) {
    if (isset($data[$k])) {
        echo "$k: " . json_encode($data[$k]) . "\n";
    }
}
