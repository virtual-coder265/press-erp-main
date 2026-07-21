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
 * @return array{
 *     items: array<int, array<string, mixed>>,
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
        'SELECT * FROM estimation_papers WHERE estimation_id = :id ORDER BY sort_order, id',
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

    return [
        'items' => $items,
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
