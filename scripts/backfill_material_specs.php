<?php
/**
 * Backfill structured material specs from catalog names.
 *
 * Usage: php scripts/backfill_material_specs.php [--dry-run]
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../libs/MaterialSpecMigrator.php';
require_once __DIR__ . '/../includes/material_match_helper.php';

MaterialSpecMigrator::ensure($pdo);

$dryRun = in_array('--dry-run', $argv ?? [], true);

$stmt = $pdo->query("
    SELECT m.id, m.name, mc.name AS category_name
    FROM materials m
    LEFT JOIN material_categories mc ON mc.id = m.category_id
    ORDER BY m.id
");
$materials = $stmt->fetchAll(PDO::FETCH_ASSOC);

$updated = 0;
$skipped = 0;

foreach ($materials as $material) {
    $specs = material_parse_name_specs((string) $material['name'], (string) ($material['category_name'] ?? ''));

    if ($specs['material_kind'] === null && !empty($material['category_name'])) {
        $specs['material_kind'] = material_kind_from_category((string) $material['category_name']);
    }

    $hasSpec = false;
    foreach (['material_kind', 'stock_type', 'grammage', 'color', 'dimensions', 'thickness_mm', 'brand'] as $key) {
        if ($specs[$key] !== null && $specs[$key] !== '') {
            $hasSpec = true;
            break;
        }
    }

    if (!$hasSpec) {
        $skipped++;
        continue;
    }

    if ($dryRun) {
        echo sprintf(
            "#%d %s => kind=%s stock=%s gsm=%s color=%s dim=%s thick=%s brand=%s\n",
            $material['id'],
            $material['name'],
            $specs['material_kind'] ?? '-',
            $specs['stock_type'] ?? '-',
            $specs['grammage'] ?? '-',
            $specs['color'] ?? '-',
            $specs['dimensions'] ?? '-',
            $specs['thickness_mm'] ?? '-',
            $specs['brand'] ?? '-'
        );
    } else {
        material_save_specs($pdo, (int) $material['id'], $specs);
    }
    $updated++;
}

echo ($dryRun ? '[DRY RUN] ' : '') . "Updated {$updated} materials, skipped {$skipped}.\n";
