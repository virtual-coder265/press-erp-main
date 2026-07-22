<?php
/**
 * Load estimation detail rows and section subtotals for view / print / PDF export.
 */

/**
 * @return array<int, array<string, mixed>>
 */
function estimation_safe_fetch(PDO $pdo, string $sql, array $params = []): array
{
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (PDOException $e) {
        return [];
    }
}

/**
 * @return array<string, mixed>
 */
function estimation_decode_item_details(?string $json): array
{
    if ($json === null || $json === '') {
        return [];
    }
    $decoded = json_decode($json, true);
    return is_array($decoded) ? $decoded : [];
}

/**
 * Split estimation_items into standard materials, section roll-ups, and other lines.
 *
 * @param array<int, array<string, mixed>> $items
 * @return array{
 *     standard_materials: array<int, array<string, mixed>>,
 *     consumables: array<int, array<string, mixed>>,
 *     rollup_items: array<int, array<string, mixed>>,
 *     other_items: array<int, array<string, mixed>>
 * }
 */
function estimation_partition_line_items(array $items): array
{
    $standardNames = array_map('strtolower', [
        'Proofing Paper',
        'Film',
        'Plate',
        'Colour Separation',
    ]);
    $rollupDescriptions = array_map('strtolower', [
        'Paper Stock',
        'Ink',
        'Binding Materials',
        'Pre-press Labour',
        'Press Labour',
        'Finishing Labour',
        'Overtime & Supervision',
    ]);
    $consumableDescriptions = array_map('strtolower', [
        'Consumables',
        'Miscellaneous consumables',
    ]);

    $standardMaterials = [];
    $consumables = [];
    $rollupItems = [];
    $otherItems = [];

    foreach ($items as $row) {
        $details = estimation_decode_item_details($row['details_json'] ?? null);
        $description = trim((string) ($row['description'] ?? ''));
        $descKey = strtolower($description);

        if (!empty($details['consumable'])
            || !empty($details['consumable_misc'])
            || !empty($details['consumable_rollup'])
            || in_array($descKey, $consumableDescriptions, true)) {
            $consumables[] = $row;
            continue;
        }

        if (!empty($details['multi_paper']) || !empty($details['binding']) || isset($details['mode'])) {
            $rollupItems[] = $row;
            continue;
        }

        if (in_array($descKey, $rollupDescriptions, true)) {
            $rollupItems[] = $row;
            continue;
        }

        if (!empty($details['material_id']) || in_array($descKey, $standardNames, true)) {
            $standardMaterials[] = $row;
            continue;
        }

        $otherItems[] = $row;
    }

    return [
        'standard_materials' => $standardMaterials,
        'consumables' => $consumables,
        'rollup_items' => $rollupItems,
        'other_items' => $otherItems,
    ];
}

/**
 * @return array{
 *     items: array<int, array<string, mixed>>,
 *     standard_materials: array<int, array<string, mixed>>,
 *     consumables: array<int, array<string, mixed>>,
 *     rollup_items: array<int, array<string, mixed>>,
 *     other_items: array<int, array<string, mixed>>,
 *     papers: array<int, array<string, mixed>>,
 *     inkRows: array<int, array<string, mixed>>,
 *     binding: array<int, array<string, mixed>>,
 *     prepress: array<int, array<string, mixed>>,
 *     press: array<int, array<string, mixed>>,
 *     finishing: array<int, array<string, mixed>>,
 *     subtotals: array<string, float>
 * }
 */
function estimation_load_detail_bundle(PDO $pdo, int $estimationId): array
{
    $params = ['id' => $estimationId];

    $items = estimation_safe_fetch(
        $pdo,
        'SELECT * FROM estimation_items WHERE estimation_id = :id ORDER BY id',
        $params
    );
    $papers = estimation_safe_fetch(
        $pdo,
        'SELECT ep.*, m.name AS material_name
         FROM estimation_papers ep
         LEFT JOIN materials m ON m.id = ep.material_id
         WHERE ep.estimation_id = :id
         ORDER BY ep.sort_order, ep.id',
        $params
    );
    $inkRows = estimation_safe_fetch(
        $pdo,
        'SELECT * FROM estimation_ink_colours WHERE estimation_id = :id ORDER BY sort_order, id',
        $params
    );
    $binding = estimation_safe_fetch(
        $pdo,
        'SELECT * FROM estimation_binding_materials WHERE estimation_id = :id ORDER BY sort_order, id',
        $params
    );
    $prepress = estimation_safe_fetch(
        $pdo,
        'SELECT * FROM estimation_prepress_labour WHERE estimation_id = :id ORDER BY sort_order, id',
        $params
    );
    $press = estimation_safe_fetch(
        $pdo,
        'SELECT * FROM estimation_press_labour WHERE estimation_id = :id ORDER BY sort_order, id',
        $params
    );
    $finishing = estimation_safe_fetch(
        $pdo,
        'SELECT * FROM estimation_finishing_labour WHERE estimation_id = :id ORDER BY sort_order, id',
        $params
    );

    $subtotals = estimation_compute_section_subtotals(
        $items,
        $papers,
        $inkRows,
        $binding,
        $prepress,
        $press,
        $finishing
    );

    $partition = estimation_partition_line_items($items);
    $subtotals['standard_materials'] = array_sum(array_map(
        static fn(array $row): float => (float) ($row['total_price'] ?? 0),
        $partition['standard_materials']
    ));
    $subtotals['other_items'] = array_sum(array_map(
        static fn(array $row): float => (float) ($row['total_price'] ?? 0),
        $partition['other_items']
    ));
    $subtotals['consumables'] = array_sum(array_map(
        static fn(array $row): float => (float) ($row['total_price'] ?? 0),
        $partition['consumables']
    ));

    return [
        'items' => $items,
        'standard_materials' => $partition['standard_materials'],
        'consumables' => $partition['consumables'],
        'rollup_items' => $partition['rollup_items'],
        'other_items' => $partition['other_items'],
        'papers' => $papers,
        'inkRows' => $inkRows,
        'binding' => $binding,
        'prepress' => $prepress,
        'press' => $press,
        'finishing' => $finishing,
        'subtotals' => $subtotals,
    ];
}

/**
 * @param array<int, array<string, mixed>> $items
 * @param array<int, array<string, mixed>> $papers
 * @param array<int, array<string, mixed>> $inkRows
 * @param array<int, array<string, mixed>> $binding
 * @param array<int, array<string, mixed>> $prepress
 * @param array<int, array<string, mixed>> $press
 * @param array<int, array<string, mixed>> $finishing
 *
 * @return array<string, float>
 */
function estimation_compute_section_subtotals(
    array $items,
    array $papers,
    array $inkRows,
    array $binding,
    array $prepress,
    array $press,
    array $finishing
): array {
    return [
        'items' => array_sum(array_map(static fn(array $row): float => (float) ($row['total_price'] ?? 0), $items)),
        'papers' => array_sum(array_map(static fn(array $row): float => (float) ($row['paper_total'] ?? 0), $papers)),
        'ink' => array_sum(array_map(static fn(array $row): float => (float) ($row['total'] ?? 0), $inkRows)),
        'binding' => array_sum(array_map(static fn(array $row): float => (float) ($row['total'] ?? 0), $binding)),
        'prepress' => array_sum(array_map(static fn(array $row): float => (float) ($row['total'] ?? 0), $prepress)),
        'press' => array_sum(array_map(static fn(array $row): float => (float) ($row['machine_total'] ?? 0), $press)),
        'finishing' => array_sum(array_map(static fn(array $row): float => (float) ($row['total'] ?? 0), $finishing)),
    ];
}
