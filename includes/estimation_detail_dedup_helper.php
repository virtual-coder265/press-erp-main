<?php
/**
 * Remove duplicate estimation detail rows caused by pre-fix double saves.
 * Keeps the lowest id per identical content group within each estimation.
 */

/**
 * @return int Number of rows deleted
 */
function estimation_deduplicate_detail_rows(PDO $pdo, ?int $estimationId = null): int
{
    $specs = estimation_detail_dedup_specs();
    $deleted = 0;

    foreach ($specs as $spec) {
        $deleted += estimation_deduplicate_table_rows(
            $pdo,
            $spec['table'],
            $spec['match'],
            $estimationId
        );
    }

    return $deleted;
}

/**
 * @return list<array{table: string, match: string[]}>
 */
function estimation_detail_dedup_specs(): array
{
    return [
        [
            'table' => 'estimation_prepress_labour',
            'match' => ['labour_name', 'unit', 'hrs', 'rate', 'total'],
        ],
        [
            'table' => 'estimation_press_labour',
            'match' => [
                'machine_name', 'colours', 'make_ready_hrs', 'make_ready_rate', 'make_ready_total',
                'impressions', 'iph', 'running_hrs', 'running_rate', 'running_total', 'machine_total',
            ],
        ],
        [
            'table' => 'estimation_finishing_labour',
            'match' => ['labour_name', 'measure_type', 'impressions', 'iph', 'hrs', 'quantity', 'rate', 'total'],
        ],
        [
            'table' => 'estimation_papers',
            'match' => ['material_id', 'paper_type', 'paper_size', 'paper_grammage', 'paper_color', 'paper_sheets', 'paper_rate', 'paper_total'],
        ],
        [
            'table' => 'estimation_ink_colours',
            'match' => ['material_id', 'colour_name', 'kgs', 'rate', 'total'],
        ],
        [
            'table' => 'estimation_binding_materials',
            'match' => ['material_id', 'material_name', 'unit', 'quantity', 'rate', 'total'],
        ],
        [
            'table' => 'estimation_items',
            'match' => ['item_type', 'description', 'quantity', 'unit_price', 'total_price'],
        ],
    ];
}

/**
 * @param string[] $matchColumns
 */
function estimation_deduplicate_table_rows(
    PDO $pdo,
    string $table,
    array $matchColumns,
    ?int $estimationId
): int {
    if (!preg_match('/^[a-z_]+$/', $table)) {
        return 0;
    }

    try {
        $tableCheck = $pdo->query(
            "SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = " . $pdo->quote($table)
        );
        if ((int) $tableCheck->fetchColumn() === 0) {
            return 0;
        }
    } catch (Throwable $e) {
        return 0;
    }

    $joinParts = [];
    foreach ($matchColumns as $col) {
        if (!preg_match('/^[a-z_]+$/', $col)) {
            continue;
        }
        $joinParts[] = "t1.`{$col}` <=> t2.`{$col}`";
    }
    if ($joinParts === []) {
        return 0;
    }

    $sql = "DELETE t1 FROM `{$table}` t1
            INNER JOIN `{$table}` t2
              ON t1.estimation_id = t2.estimation_id
             AND t1.id > t2.id
             AND " . implode(' AND ', $joinParts);

    $params = [];
    if ($estimationId !== null) {
        $sql .= ' WHERE t1.estimation_id = :est_id';
        $params['est_id'] = $estimationId;
    }

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    } catch (Throwable $e) {
        error_log('estimation_deduplicate_table_rows failed for ' . $table . ': ' . $e->getMessage());
        return 0;
    }
}
