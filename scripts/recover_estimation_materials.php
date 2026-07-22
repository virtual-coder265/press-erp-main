<?php
/**
 * Recover plates, other standard materials, and miscellaneous costs from draft version history.
 *
 * Usage:
 *   php scripts/recover_estimation_materials.php                 # dry-run (default)
 *   php scripts/recover_estimation_materials.php --apply         # write changes
 *   php scripts/recover_estimation_materials.php --apply --id=36 # one estimation
 *   php scripts/recover_estimation_materials.php --apply --include-suspicious-misc
 */

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "Run this script from the command line.\n");
    exit(1);
}

require __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/estimation_recovery_helper.php';

$apply = in_array('--apply', $argv, true);
$includeSuspiciousMisc = in_array('--include-suspicious-misc', $argv, true);
$onlyId = null;
foreach ($argv as $arg) {
    if (preg_match('/^--id=(\d+)$/', $arg, $matches)) {
        $onlyId = (int) $matches[1];
    }
}

$editorUserId = null;
$adminStmt = $pdo->query("SELECT u.id FROM users u JOIN roles r ON r.id = u.role_id WHERE r.name = 'System Admin' ORDER BY u.id LIMIT 1");
$editorUserId = (int) ($adminStmt->fetchColumn() ?: 0);
if ($editorUserId <= 0) {
    $editorUserId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
}

echo '=== Estimation material / misc recovery ===' . PHP_EOL;
echo 'Mode: ' . ($apply ? 'APPLY (writes to database)' : 'DRY-RUN (preview only)') . PHP_EOL;
if ($onlyId) {
    echo 'Scope: estimation id ' . $onlyId . PHP_EOL;
}
if ($includeSuspiciousMisc) {
    echo 'Including suspicious miscellaneous amounts' . PHP_EOL;
}
echo PHP_EOL;

$result = estimation_recovery_build_plans($pdo, $onlyId, $includeSuspiciousMisc);
$plans = $result['plans'];
$skipped = $result['skipped'];

if ($plans === []) {
    echo "No recoverable estimations found.\n";
} else {
    echo count($plans) . " estimation(s) with recoverable data:\n\n";
}

$totalMaterials = 0;
$totalMisc = 0;
$appliedCount = 0;

foreach ($plans as $plan) {
    echo $plan['estimation_number'] . ' (id ' . $plan['estimation_id'] . ') status=' . $plan['status'];
    echo ' · history rev ' . ($plan['source_revision'] ?? '?') . ' · ' . $plan['version_count'] . " version(s)\n";

    foreach ($plan['materials'] as $material) {
        echo '  + Material: ' . $material['name']
            . ' qty=' . $material['qty']
            . ' rate=' . number_format((float) $material['rate'], 2)
            . ' total=MK' . number_format((float) $material['total'], 2)
            . ' (rev ' . (int) $material['revision'] . ")\n";
        $totalMaterials++;
    }

    $misc = $plan['misc'] ?? null;
    if ($misc && empty($misc['skipped'])) {
        echo '  + Misc: MK' . number_format((float) $misc['amount'], 2) . ' (rev ' . (int) $misc['revision'] . ")\n";
        $totalMisc++;
    } elseif ($misc && !empty($misc['skipped'])) {
        echo '  ! Misc skipped (suspicious): MK' . number_format((float) $misc['amount'], 2) . ' — ' . ($misc['reason'] ?? '') . "\n";
    }

    if ($apply) {
        try {
            $pdo->beginTransaction();
            $outcome = estimation_recovery_apply_plan($pdo, $plan, $editorUserId > 0 ? $editorUserId : null);
            $pdo->commit();
            if ($outcome['applied']) {
                $appliedCount++;
                foreach ($outcome['messages'] as $message) {
                    echo '  => ' . $message . "\n";
                }
            } else {
                echo "  => Nothing applied\n";
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            echo '  !! FAILED: ' . $e->getMessage() . "\n";
        }
    }

    echo "\n";
}

if ($skipped !== []) {
    echo "Skipped / no data (" . count($skipped) . "):\n";
    foreach ($skipped as $row) {
        echo '  ' . ($row['estimation_number'] ?: ('id ' . $row['estimation_id'])) . ' — ' . $row['reason'] . "\n";
    }
    echo "\n";
}

echo 'Summary: ' . count($plans) . ' plan(s), ' . $totalMaterials . ' material row(s), ' . $totalMisc . " misc update(s)\n";
if (!$apply) {
    echo "Re-run with --apply to write these changes.\n";
    if ($skipped !== [] && !$includeSuspiciousMisc) {
        echo "Use --include-suspicious-misc to force flagged miscellaneous amounts.\n";
    }
} else {
    echo 'Applied to ' . $appliedCount . " estimation(s).\n";
}
