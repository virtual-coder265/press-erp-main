<?php
/**
 * Recover missing standard materials and miscellaneous costs from draft version history.
 */

require_once __DIR__ . '/estimation_draft_restore_helper.php';

const ESTIMATION_RECOVERY_MISC_MAX_RATIO = 0.35;

/**
 * Parse wizard money strings (MK1,234.56) or plain numbers.
 */
function estimation_recovery_parse_money($value): float
{
    if ($value === null || $value === '') {
        return 0.0;
    }
    $cleaned = str_replace(['MK', ','], '', (string) $value);
    return (float) $cleaned;
}

/**
 * @return array<string, int>
 */
function estimation_recovery_material_name_map(PDO $pdo): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    $cache = [];
    $stmt = $pdo->query('SELECT id, name FROM materials');
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $cache[strtolower(trim((string) $row['name']))] = (int) $row['id'];
    }

    return $cache;
}

/**
 * @return array<int, array<string, mixed>>
 */
function estimation_recovery_fetch_versions(PDO $pdo, int $estimationId): array
{
    $stmt = $pdo->prepare(
        'SELECT revision, draft_step, saved_at, draft_data
         FROM estimation_draft_versions
         WHERE estimation_id = :id
         ORDER BY revision DESC, saved_at DESC'
    );
    $stmt->execute(['id' => $estimationId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * @param array<int, array<string, mixed>> $versions
 * @return array{
 *     materials: array<int, array{name: string, material_id: int|null, qty: float, rate: float, total: float, revision: int, saved_at: string|null}>,
 *     misc: array{amount: float, revision: int, saved_at: string|null, suspicious: bool, reason: string}|null,
 *     source_revision: int|null
 * }
 */
function estimation_recovery_extract_from_versions(PDO $pdo, array $versions): array
{
    $nameMap = estimation_recovery_material_name_map($pdo);
    $materials = [];
    $misc = null;

    foreach (ESTIMATION_STD_MATERIAL_NAMES as $index => $materialName) {
        $key = strtolower($materialName);
        $materialId = $nameMap[$key] ?? null;

        foreach ($versions as $version) {
            $data = json_decode((string) ($version['draft_data'] ?? ''), true);
            if (!is_array($data)) {
                continue;
            }

            $qty = isset($data['material_qty'][$index]) ? (float) $data['material_qty'][$index] : 0.0;
            if ($qty <= 0) {
                continue;
            }

            $rate = isset($data['material_rate'][$index]) ? (float) $data['material_rate'][$index] : 0.0;
            $totalRaw = $data['material_total'][$index] ?? 0;
            $total = estimation_recovery_parse_money($totalRaw);
            if ($total <= 0 && $qty > 0 && $rate > 0) {
                $total = round($qty * $rate, 2);
            }

            $materials[$index] = [
                'name' => $materialName,
                'material_id' => $materialId,
                'qty' => $qty,
                'rate' => $rate,
                'total' => $total,
                'revision' => (int) ($version['revision'] ?? 0),
                'saved_at' => $version['saved_at'] ?? null,
            ];
            break;
        }
    }

    foreach ($versions as $version) {
        $data = json_decode((string) ($version['draft_data'] ?? ''), true);
        if (!is_array($data)) {
            continue;
        }

        $amount = estimation_recovery_parse_money($data['cost_consumables'] ?? 0);
        if ($amount <= 0) {
            $amount = estimation_recovery_parse_money($data['cost_miscellaneous'] ?? 0);
        }
        if ($amount <= 0) {
            continue;
        }

        $subtotal = estimation_recovery_parse_money($data['subtotal'] ?? 0);
        $grandTotal = estimation_recovery_parse_money($data['grand_total'] ?? 0);
        $suspicious = false;
        $reason = '';

        if ($subtotal > 0 && $amount >= $subtotal * ESTIMATION_RECOVERY_MISC_MAX_RATIO) {
            $suspicious = true;
            $reason = 'Misc is ' . round(($amount / $subtotal) * 100, 1) . '% of subtotal (cap ' . (ESTIMATION_RECOVERY_MISC_MAX_RATIO * 100) . '%)';
        } elseif ($subtotal > 0 && abs($amount - $subtotal) < max(1.0, $subtotal * 0.01)) {
            $suspicious = true;
            $reason = 'Misc nearly equals subtotal — likely wrong field';
        } elseif ($grandTotal > 0 && abs($amount - $grandTotal) < max(1.0, $grandTotal * 0.01)) {
            $suspicious = true;
            $reason = 'Misc nearly equals grand total — likely wrong field';
        }

        $misc = [
            'amount' => round($amount, 2),
            'revision' => (int) ($version['revision'] ?? 0),
            'saved_at' => $version['saved_at'] ?? null,
            'suspicious' => $suspicious,
            'reason' => $reason,
        ];
        break;
    }

    $sourceRevision = null;
    if ($misc) {
        $sourceRevision = $misc['revision'];
    } elseif ($materials !== []) {
        $first = reset($materials);
        $sourceRevision = (int) ($first['revision'] ?? 0);
    }

    return [
        'materials' => $materials,
        'misc' => $misc,
        'source_revision' => $sourceRevision,
    ];
}

/**
 * @return array<int, array<string, mixed>>
 */
function estimation_recovery_existing_std_materials(PDO $pdo, int $estimationId): array
{
    $nameMap = estimation_recovery_material_name_map($pdo);
    $stdIds = [];
    foreach (ESTIMATION_STD_MATERIAL_NAMES as $stdName) {
        $stdIds[strtolower($stdName)] = (string) ($nameMap[strtolower($stdName)] ?? '');
    }

    $found = [];
    $stmt = $pdo->prepare('SELECT * FROM estimation_items WHERE estimation_id = :id ORDER BY id');
    $stmt->execute(['id' => $estimationId]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $item) {
        $details = [];
        if (!empty($item['details_json'])) {
            $decoded = json_decode((string) $item['details_json'], true);
            if (is_array($decoded)) {
                $details = $decoded;
            }
        }
        if (!empty($details['multi_paper']) || !empty($details['binding']) || isset($details['mode'])) {
            continue;
        }

        $descKey = strtolower(trim((string) ($item['description'] ?? '')));
        foreach (ESTIMATION_STD_MATERIAL_NAMES as $index => $stdName) {
            if (strtolower($stdName) === $descKey) {
                $found[$index] = $item;
                continue 2;
            }
        }

        $materialId = isset($details['material_id']) ? (string) $details['material_id'] : '';
        foreach (ESTIMATION_STD_MATERIAL_NAMES as $index => $stdName) {
            if ($materialId !== '' && $materialId === ($stdIds[strtolower($stdName)] ?? '')) {
                $found[$index] = $item;
                continue 2;
            }
        }
    }

    return $found;
}

function estimation_recovery_has_consumables(PDO $pdo, int $estimationId, ?array $estimationRow = null): bool
{
    if ($estimationRow === null) {
        $stmt = $pdo->prepare('SELECT cost_consumables_amount FROM estimations WHERE id = :id');
        $stmt->execute(['id' => $estimationId]);
        $estimationRow = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    if ((float) ($estimationRow['cost_consumables_amount'] ?? 0) > 0) {
        return true;
    }

    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM estimation_items
         WHERE estimation_id = :id AND LOWER(description) = 'consumables'"
    );
    $stmt->execute(['id' => $estimationId]);

    return (int) $stmt->fetchColumn() > 0;
}

/**
 * @return array<string, mixed>|null
 */
function estimation_recovery_plan_for_estimation(PDO $pdo, array $estimationRow, bool $includeSuspiciousMisc = false): ?array
{
    $estId = (int) ($estimationRow['id'] ?? 0);
    if ($estId <= 0) {
        return null;
    }

    $versions = estimation_recovery_fetch_versions($pdo, $estId);
    if ($versions === []) {
        return null;
    }

    $extracted = estimation_recovery_extract_from_versions($pdo, $versions);
    $existingMaterials = estimation_recovery_existing_std_materials($pdo, $estId);
    $hasConsumables = estimation_recovery_has_consumables($pdo, $estId, $estimationRow);

    $materialActions = [];
    foreach ($extracted['materials'] as $index => $material) {
        if (isset($existingMaterials[$index])) {
            continue;
        }
        if ($material['qty'] <= 0) {
            continue;
        }
        $materialActions[] = $material;
    }

    $miscAction = null;
    if ($extracted['misc'] && !$hasConsumables) {
        if (!$extracted['misc']['suspicious'] || $includeSuspiciousMisc) {
            $miscAction = $extracted['misc'];
        } else {
            $miscAction = array_merge($extracted['misc'], ['skipped' => true]);
        }
    }

    if ($materialActions === [] && ($miscAction === null || !empty($miscAction['skipped']))) {
        return null;
    }

    return [
        'estimation_id' => $estId,
        'estimation_number' => (string) ($estimationRow['estimation_number'] ?? ''),
        'status' => (string) ($estimationRow['status'] ?? ''),
        'source_revision' => $extracted['source_revision'],
        'materials' => $materialActions,
        'misc' => $miscAction,
        'version_count' => count($versions),
    ];
}

/**
 * @return array<int, array<string, mixed>>
 */
function estimation_recovery_target_estimations(PDO $pdo, ?int $onlyId = null): array
{
    $sql = "
        SELECT e.*
        FROM estimations e
        WHERE (
            e.status != 'Draft'
            OR (e.status = 'Draft' AND (e.draft_data IS NULL OR TRIM(e.draft_data) = ''))
        )
    ";
    $params = [];
    if ($onlyId !== null && $onlyId > 0) {
        $sql .= ' AND e.id = :id';
        $params['id'] = $onlyId;
    }
    $sql .= ' ORDER BY e.id';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * @return array{applied: bool, materials_added: int, misc_applied: bool, skipped_misc: bool, messages: string[]}
 */
function estimation_recovery_apply_plan(PDO $pdo, array $plan, ?int $editorUserId = null): array
{
    $estId = (int) $plan['estimation_id'];
    $messages = [];
    $materialsAdded = 0;
    $miscApplied = false;
    $skippedMisc = false;

    $itemStmt = $pdo->prepare(
        "INSERT INTO estimation_items
            (estimation_id, item_type, description, quantity, unit_price, total_price, details_json)
         VALUES (:eid, 'Material', :desc, :qty, :price, :total, :json)"
    );

    foreach ($plan['materials'] as $material) {
        $json = json_encode([
            'material_id' => $material['material_id'] ? (string) $material['material_id'] : null,
            'recovered_from_revision' => (int) ($material['revision'] ?? 0),
        ]);
        $itemStmt->execute([
            'eid' => $estId,
            'desc' => $material['name'],
            'qty' => $material['qty'],
            'price' => $material['rate'],
            'total' => $material['total'],
            'json' => $json,
        ]);
        $materialsAdded++;
        $messages[] = sprintf(
            'Added %s qty=%s rate=%s total=MK%s (rev %s)',
            $material['name'],
            $material['qty'],
            number_format((float) $material['rate'], 2),
            number_format((float) $material['total'], 2),
            (int) ($material['revision'] ?? 0)
        );
    }

    $misc = $plan['misc'] ?? null;
    if ($misc && !empty($misc['skipped'])) {
        $skippedMisc = true;
        $messages[] = 'Skipped suspicious misc MK ' . number_format((float) $misc['amount'], 2) . ': ' . ($misc['reason'] ?? 'flagged');
    } elseif ($misc && empty($misc['skipped'])) {
        $amount = round((float) $misc['amount'], 2);
        $itemStmt->execute([
            'eid' => $estId,
            'desc' => 'Consumables',
            'qty' => 1,
            'price' => $amount,
            'total' => $amount,
            'json' => json_encode(['recovered_from_revision' => (int) ($misc['revision'] ?? 0)]),
        ]);

        $update = $pdo->prepare(
            'UPDATE estimations
             SET cost_consumables_amount = :amount,
                 last_edited_at = NOW(),
                 last_edited_by = :editor
             WHERE id = :id'
        );
        $update->execute([
            'amount' => $amount,
            'editor' => $editorUserId,
            'id' => $estId,
        ]);
        $miscApplied = true;
        $messages[] = sprintf(
            'Set miscellaneous/consumables MK %s (rev %s)',
            number_format($amount, 2),
            (int) ($misc['revision'] ?? 0)
        );
    }

    if ($materialsAdded > 0 && $editorUserId) {
        $touch = $pdo->prepare(
            'UPDATE estimations SET last_edited_at = NOW(), last_edited_by = :editor WHERE id = :id'
        );
        $touch->execute(['editor' => $editorUserId, 'id' => $estId]);
    }

    return [
        'applied' => $materialsAdded > 0 || $miscApplied,
        'materials_added' => $materialsAdded,
        'misc_applied' => $miscApplied,
        'skipped_misc' => $skippedMisc,
        'messages' => $messages,
    ];
}

/**
 * @return array{plans: array<int, array<string, mixed>>, skipped: array<int, array<string, mixed>>}
 */
function estimation_recovery_build_plans(PDO $pdo, ?int $onlyId = null, bool $includeSuspiciousMisc = false): array
{
    $plans = [];
    $skipped = [];

    foreach (estimation_recovery_target_estimations($pdo, $onlyId) as $estimationRow) {
        $plan = estimation_recovery_plan_for_estimation($pdo, $estimationRow, $includeSuspiciousMisc);
        if ($plan === null) {
            $estId = (int) $estimationRow['id'];
            $versions = estimation_recovery_fetch_versions($pdo, $estId);
            if ($versions === []) {
                $skipped[] = [
                    'estimation_id' => $estId,
                    'estimation_number' => $estimationRow['estimation_number'] ?? '',
                    'reason' => 'No draft version history',
                ];
            }
            continue;
        }

        if (!empty($plan['misc']['skipped'])) {
            $skipped[] = [
                'estimation_id' => (int) $plan['estimation_id'],
                'estimation_number' => $plan['estimation_number'],
                'reason' => 'Suspicious misc MK ' . number_format((float) $plan['misc']['amount'], 2) . ': ' . ($plan['misc']['reason'] ?? ''),
            ];
        }

        $plans[] = $plan;
    }

    return ['plans' => $plans, 'skipped' => $skipped];
}
