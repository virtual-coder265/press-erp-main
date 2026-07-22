<?php
require __DIR__ . '/../config/database.php';

echo "=== Completed estimation recovery detail ===\n\n";

$completed = $pdo->query("
    SELECT e.id, e.estimation_number, e.status, e.total_amount, e.subtotal_amount,
           e.cost_consumables_amount, e.draft_data IS NOT NULL AND TRIM(e.draft_data) != '' AS has_draft,
           (SELECT COUNT(*) FROM estimation_draft_versions v WHERE v.estimation_id = e.id) AS version_count
    FROM estimations e
    WHERE e.status != 'Draft'
       OR (e.status = 'Draft' AND (e.draft_data IS NULL OR e.draft_data = ''))
    ORDER BY e.id
")->fetchAll(PDO::FETCH_ASSOC);

foreach ($completed as $est) {
    $id = (int) $est['id'];
    $versions = $pdo->prepare('SELECT revision, draft_step, saved_at, draft_data FROM estimation_draft_versions WHERE estimation_id = ? ORDER BY revision DESC');
    $versions->execute([$id]);
    $rows = $versions->fetchAll(PDO::FETCH_ASSOC);

    $bestPlate = null;
    $bestMisc = null;
    $bestRevision = null;

    foreach ($rows as $v) {
        $data = json_decode($v['draft_data'], true);
        if (!is_array($data)) continue;

        $plateIdx = 2; // Plate is 3rd std material (0=Proofing, 1=Film, 2=Plate)
        $plateQty = isset($data['material_qty'][$plateIdx]) ? (float) $data['material_qty'][$plateIdx] : 0;
        $plateRate = isset($data['material_rate'][$plateIdx]) ? (float) $data['material_rate'][$plateIdx] : 0;
        $plateTotal = isset($data['material_total'][$plateIdx]) ? $data['material_total'][$plateIdx] : 0;
        $misc = (float) ($data['cost_miscellaneous'] ?? $data['cost_consumables'] ?? 0);

        if ($plateQty > 0 && $bestPlate === null) {
            $bestPlate = ['qty' => $plateQty, 'rate' => $plateRate, 'total' => $plateTotal, 'rev' => $v['revision'], 'at' => $v['saved_at']];
        }
        if ($misc > 0 && $bestMisc === null) {
            $bestMisc = ['amount' => $misc, 'rev' => $v['revision'], 'at' => $v['saved_at']];
        }
        if ($bestRevision === null) {
            $bestRevision = $v['revision'];
        }
    }

    $recover = ($bestPlate || $bestMisc) ? 'RECOVERABLE' : 'NO HISTORY';
    echo "{$est['estimation_number']} (id {$id}) status={$est['status']} versions={$est['version_count']} => {$recover}\n";
    if ($bestPlate) {
        echo "  Plate: qty={$bestPlate['qty']} rate={$bestPlate['rate']} total={$bestPlate['total']} (rev {$bestPlate['rev']})\n";
    }
    if ($bestMisc) {
        echo "  Misc: MK {$bestMisc['amount']} (rev {$bestMisc['rev']})\n";
    }
    if (!$bestPlate && !$bestMisc && (int) $est['version_count'] === 0) {
        echo "  (no draft_versions rows — data likely lost unless manual notes elsewhere)\n";
    }
    echo "\n";
}
