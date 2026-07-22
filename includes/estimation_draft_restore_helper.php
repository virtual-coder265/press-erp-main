<?php
/**
 * Restore estimation wizard fields from draft_data JSON or normalized DB tables.
 */

const ESTIMATION_STD_MATERIAL_NAMES = [
    'Proofing Paper',
    'Film',
    'Plate',
    'Colour Separation',
];

const ESTIMATION_DEFAULT_PREPRESS_NAMES = [
    'Design',
    'Keying',
    'Laying Out',
    'Reading',
    'Proof Making',
    'Film Assembly',
    'Platemaking',
];

const ESTIMATION_DEFAULT_FINISHING_ROWS = [
    ['Numbering', 'numbers'],
    ['Perforating', 'perfs'],
    ['Saddle Stitching', 'books'],
    ['Perfect Binding', 'books'],
    ['Paper Cutting', 'reams'],
    ['Trimming', 'items'],
    ['Case Making', 'items'],
    ['Gold Blocking', 'items'],
];

/**
 * @param mixed $value
 */
function estimation_wizard_money($value): string
{
    $amount = (float) $value;
    $negative = $amount < 0;
    $formatted = number_format(abs($amount), 2, '.', ',');
    return ($negative ? '-' : '') . 'MK' . $formatted;
}

/**
 * Parse a wizard currency/scalar field to float (MK-prefixed or plain).
 */
function estimation_draft_parse_amount($value): float
{
    if ($value === null || $value === '') {
        return 0.0;
    }
    return (float) str_replace(['MK', ','], '', (string) $value);
}

/**
 * @param mixed $value
 */
function estimation_draft_field_has_value(string $key, $value): bool
{
    if ($value === null) {
        return false;
    }

    if (is_array($value)) {
        foreach ($value as $item) {
            if (estimation_draft_field_has_value($key, $item)) {
                return true;
            }
        }
        return false;
    }

    $moneyKeys = [
        'subtotal', 'grand_total', 'cost_paper', 'cost_ink', 'cost_binding',
        'cost_prepress', 'cost_press', 'cost_finishing', 'cost_labour_total',
        'cost_consumables', 'cost_consumables_misc', 'cost_miscellaneous', 'cost_labour',
    ];
    if (in_array($key, $moneyKeys, true) || str_starts_with($key, 'cost_')) {
        return estimation_draft_parse_amount($value) > 0;
    }

    $trimmed = trim((string) $value);
    return $trimmed !== '' && $trimmed !== '0' && $trimmed !== '0.00' && $trimmed !== 'MK0' && $trimmed !== 'MK0.00';
}

/**
 * Score how completely a field set covers wizard sections (higher = more complete).
 *
 * @param array<string, mixed> $fields
 */
function estimation_draft_completeness_score(array $fields): int
{
    $score = 0;

    $sectionChecks = [
        ['material_qty', 3],
        ['paper_sheets', 4],
        ['ink_colour_kgs', 5],
        ['cost_ink', 6],
        ['binding_mat_qty', 3],
        ['prepress_hrs', 4],
        ['press_impressions', 4],
        ['finishing_hrs', 4],
        ['consumable_mat_id', 3],
        ['cost_consumables_misc', 2],
        ['subtotal', 3],
        ['grand_total', 3],
    ];

    foreach ($sectionChecks as [$key, $weight]) {
        if (estimation_draft_field_has_value($key, $fields[$key] ?? null)) {
            $score += $weight;
        }
    }

    if (estimation_draft_field_has_value('ink_measure_base', $fields['ink_measure_base'] ?? null)
        && estimation_draft_field_has_value('ink_pages', $fields['ink_pages'] ?? null)) {
        $score += 2;
    }

    return $score;
}

/**
 * Merge two array fields index-by-index, preferring primary non-empty values.
 *
 * @return array<int, mixed>
 */
function estimation_draft_merge_array_values(array $primary, array $secondary): array
{
    $length = max(count($primary), count($secondary));
    $merged = [];
    for ($i = 0; $i < $length; $i++) {
        $primaryValue = $primary[$i] ?? '';
        $secondaryValue = $secondary[$i] ?? '';
        $merged[] = estimation_draft_field_has_value('', $primaryValue)
            ? $primaryValue
            : $secondaryValue;
    }
    return $merged;
}

/**
 * Merge draft JSON with DB-rebuilt fields. Primary wins when populated; secondary fills gaps.
 *
 * @param array<string, mixed> $primary
 * @param array<string, mixed> $secondary
 * @return array<string, mixed>
 */
function estimation_draft_merge_fields(array $primary, array $secondary): array
{
    if ($primary === []) {
        return $secondary;
    }
    if ($secondary === []) {
        return $primary;
    }

    $merged = $primary;
    $allKeys = array_unique(array_merge(array_keys($primary), array_keys($secondary)));

    foreach ($allKeys as $key) {
        $primaryValue = $primary[$key] ?? null;
        $secondaryValue = $secondary[$key] ?? null;

        if (!array_key_exists($key, $primary)) {
            $merged[$key] = $secondaryValue;
            continue;
        }

        if (is_array($primaryValue) && is_array($secondaryValue)) {
            $merged[$key] = estimation_draft_merge_array_values($primaryValue, $secondaryValue);
            continue;
        }

        if (!estimation_draft_field_has_value((string) $key, $primaryValue)
            && estimation_draft_field_has_value((string) $key, $secondaryValue)) {
            $merged[$key] = $secondaryValue;
        }
    }

    return $merged;
}

/**
 * @param array<string, mixed> $fields
 */
function estimation_draft_has_wizard_values(array $fields): bool
{
    return estimation_draft_completeness_score($fields) > 0;
}

/**
 * Resolve standard-material slot index from material id / description.
 *
 * @param array<int, string> $stdIds
 * @return int|false
 */
function estimation_std_material_index(string $description, string $materialId, array $stdIds)
{
    if ($materialId !== '') {
        $stdIndex = array_search($materialId, $stdIds, true);
        if ($stdIndex !== false) {
            return $stdIndex;
        }
    }

    $descKey = strtolower(trim($description));
    if ($descKey === '') {
        return false;
    }

    foreach (ESTIMATION_STD_MATERIAL_NAMES as $idx => $stdName) {
        $stdKey = strtolower($stdName);
        if ($descKey === $stdKey) {
            return $idx;
        }
        if (str_contains($descKey, $stdKey) || str_contains($stdKey, $descKey)) {
            return $idx;
        }
        $stdStem = rtrim($stdKey, 's');
        if ($stdStem !== '' && str_contains($descKey, $stdStem)) {
            return $idx;
        }
    }

    return false;
}

/**
 * @param array<string, mixed> $fields
 */
function estimation_compute_formula_ink_kgs_from_fields(array $fields): float
{
    $base = (float) ($fields['ink_measure_base'] ?? 0);
    $height = (float) ($fields['ink_height'] ?? 0);
    $pages = (float) ($fields['ink_pages'] ?? 0);
    $copies = (float) ($fields['ink_quantity_copies'] ?? 0);
    if ($base <= 0 || $height <= 0 || $pages <= 0 || $copies <= 0) {
        return estimation_draft_parse_amount($fields['ink_kgs'] ?? 0);
    }

    return ($base / 1000 * $height / 1000) * $pages * $copies * 0.5 / 0.886 / 1000;
}

/**
 * Drop trailing empty ink colour rows so the wizard does not rebuild extra blank lines.
 *
 * @param array<string, mixed> $fields
 */
function estimation_draft_trim_trailing_ink_rows(array &$fields): void
{
    $keys = [
        'ink_colour',
        'ink_material_id',
        'ink_colour_pct',
        'ink_colour_kgs',
        'ink_colour_rate',
        'ink_colour_total',
    ];

    $maxLen = 0;
    foreach ($keys as $key) {
        if (isset($fields[$key]) && is_array($fields[$key])) {
            $maxLen = max($maxLen, count($fields[$key]));
        }
    }
    if ($maxLen <= 0) {
        return;
    }

    $lastIndex = -1;
    for ($i = 0; $i < $maxLen; $i++) {
        $hasValue = false;
        if (estimation_draft_field_has_value('ink_colour', $fields['ink_colour'][$i] ?? null)) {
            $hasValue = true;
        }
        if (estimation_draft_field_has_value('ink_colour_kgs', $fields['ink_colour_kgs'][$i] ?? null)) {
            $hasValue = true;
        }
        if (estimation_draft_field_has_value('ink_colour_rate', $fields['ink_colour_rate'][$i] ?? null)) {
            $hasValue = true;
        }
        if (estimation_draft_field_has_value('ink_colour_total', $fields['ink_colour_total'][$i] ?? null)) {
            $hasValue = true;
        }
        if ($hasValue) {
            $lastIndex = $i;
        }
    }

    if ($lastIndex < 0) {
        return;
    }

    $keep = $lastIndex + 1;
    foreach ($keys as $key) {
        if (isset($fields[$key]) && is_array($fields[$key]) && count($fields[$key]) > $keep) {
            $fields[$key] = array_slice($fields[$key], 0, $keep);
        }
    }
}

/**
 * @return array{job_title: string, job_type: string, job_description: string}
 */
function estimation_parse_job_fields(?string $jobText): array
{
    $jobText = trim((string) $jobText);
    $title = '';
    $type = 'Booklet';
    $description = '';

    if ($jobText === '') {
        return [
            'job_title' => $title,
            'job_type' => $type,
            'job_description' => $description,
        ];
    }

    $lines = preg_split('/\r\n|\r|\n/', $jobText, 2);
    $firstLine = trim((string) ($lines[0] ?? ''));
    if (preg_match('/^(.+?)\s*\(([^)]+)\)\s*$/', $firstLine, $matches)) {
        $title = trim($matches[1]);
        $type = trim($matches[2]);
    } else {
        $title = $firstLine;
    }
    $description = trim((string) ($lines[1] ?? ''));

    return [
        'job_title' => $title,
        'job_type' => $type !== '' ? $type : 'Booklet',
        'job_description' => $description,
    ];
}

/**
 * @param array<int, array<string, mixed>> $rows
 * @return array<string, array<string, mixed>>
 */
function estimation_index_rows_by_name(array $rows, string $nameKey): array
{
    $indexed = [];
    foreach ($rows as $row) {
        $name = trim((string) ($row[$nameKey] ?? ''));
        if ($name !== '') {
            $indexed[$name] = $row;
        }
    }
    return $indexed;
}

/**
 * @param array<string, mixed> $estimation
 * @return array<string, mixed>
 */
function estimation_draft_fields_from_database(PDO $pdo, array $estimation): array
{
    $estId = (int) ($estimation['id'] ?? 0);
    if ($estId <= 0) {
        return [];
    }

    $job = estimation_parse_job_fields($estimation['job_description'] ?? '');

    $fields = [
        'is_draft_edit' => '1',
        'customer_name' => (string) ($estimation['customer_name'] ?? ''),
        'customer_id' => (string) ($estimation['customer_id'] ?? ''),
        'customer_email' => (string) ($estimation['customer_email'] ?? ''),
        'customer_phone' => (string) ($estimation['customer_phone'] ?? ''),
        'customer_company' => (string) ($estimation['customer_company'] ?? ''),
        'job_title' => $job['job_title'],
        'job_type' => $job['job_type'],
        'job_description' => $job['job_description'],
        'profit_margin' => (string) ($estimation['profit_margin_percent'] ?? '20'),
        'vat_percent' => (string) ($estimation['vat_percent'] ?? '17.5'),
        'cost_labour' => (string) ($estimation['cost_supervision_amount'] ?? '0'),
        'cost_consumables' => (string) ($estimation['cost_consumables_amount'] ?? '0'),
        'subtotal' => estimation_wizard_money($estimation['subtotal_amount'] ?? 0),
        'grand_total' => estimation_wizard_money($estimation['total_amount'] ?? 0),
    ];

    $nameToId = [];
    $stmt = $pdo->query('SELECT id, name FROM materials');
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $material) {
        $nameToId[strtolower(trim((string) $material['name']))] = (int) $material['id'];
    }

    $stdIds = [];
    foreach (ESTIMATION_STD_MATERIAL_NAMES as $stdName) {
        $key = strtolower($stdName);
        $stdIds[] = (string) ($nameToId[$key] ?? '');
    }

    $materialIds = $stdIds;
    $materialQty = array_fill(0, count($stdIds), '');
    $materialRate = array_fill(0, count($stdIds), '');
    $materialTotal = array_fill(0, count($stdIds), '0.00');

    $itemStmt = $pdo->prepare('SELECT * FROM estimation_items WHERE estimation_id = :id ORDER BY id');
    $itemStmt->execute(['id' => $estId]);
    $items = $itemStmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($items as $item) {
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

        $materialId = isset($details['material_id']) ? (string) $details['material_id'] : '';
        $stdIndex = estimation_std_material_index(
            (string) ($item['description'] ?? ''),
            $materialId,
            $stdIds
        );

        if ($stdIndex !== false) {
            if ($materialId !== '') {
                $materialIds[$stdIndex] = $materialId;
            }
            $materialQty[$stdIndex] = (string) ($item['quantity'] ?? '');
            $materialRate[$stdIndex] = number_format((float) ($item['unit_price'] ?? 0), 2, '.', '');
            $materialTotal[$stdIndex] = number_format((float) ($item['total_price'] ?? 0), 2, '.', '');
            continue;
        }

        if (!empty($details['consumable']) || !empty($details['consumable_misc']) || !empty($details['consumable_rollup'])) {
            continue;
        }
    }

    $fields['material_id'] = $materialIds;
    $fields['material_qty'] = $materialQty;
    $fields['material_rate'] = $materialRate;
    $fields['material_total'] = $materialTotal;
    $fields['std_mat_dimensions'] = array_fill(0, count(ESTIMATION_STD_MATERIAL_NAMES), '');
    foreach ($materialIds as $idx => $mid) {
        if ($mid === '' || $mid === null) {
            continue;
        }
        $dimStmt = $pdo->prepare('SELECT dimensions FROM materials WHERE id = ? LIMIT 1');
        $dimStmt->execute([(int) $mid]);
        $fields['std_mat_dimensions'][$idx] = (string) ($dimStmt->fetchColumn() ?: '');
    }

    $paperStmt = $pdo->prepare(
        'SELECT ep.*, m.stock_type, m.name AS material_name
         FROM estimation_papers ep
         LEFT JOIN materials m ON m.id = ep.material_id
         WHERE ep.estimation_id = :id
         ORDER BY ep.sort_order, ep.id'
    );
    $paperStmt->execute(['id' => $estId]);
    $papers = $paperStmt->fetchAll(PDO::FETCH_ASSOC);

    $fields['paper_type'] = [];
    $fields['paper_size'] = [];
    $fields['paper_grammage'] = [];
    $fields['paper_color'] = [];
    $fields['paper_stock_type'] = [];
    $fields['paper_material_id'] = [];
    $fields['paper_sheets'] = [];
    $fields['paper_rate'] = [];
    $fields['paper_total'] = [];
    $paperSubtotal = 0.0;

    foreach ($papers as $paper) {
        $fields['paper_type'][] = (string) ($paper['paper_type'] ?? '');
        $fields['paper_size'][] = (string) ($paper['paper_size'] ?? '');
        $fields['paper_grammage'][] = (string) ($paper['paper_grammage'] ?? '');
        $fields['paper_color'][] = (string) ($paper['paper_color'] ?? '');
        $fields['paper_stock_type'][] = (string) ($paper['stock_type'] ?? '');
        $fields['paper_material_id'][] = (string) ($paper['material_id'] ?? '');
        $fields['paper_sheets'][] = (string) ($paper['paper_sheets'] ?? '');
        $fields['paper_rate'][] = number_format((float) ($paper['paper_rate'] ?? 0), 2, '.', '');
        $fields['paper_total'][] = number_format((float) ($paper['paper_total'] ?? 0), 2, '.', '');
        $paperSubtotal += (float) ($paper['paper_total'] ?? 0);
    }
    $fields['cost_paper'] = estimation_wizard_money($paperSubtotal);

    $inkMeta = [
        'mode' => 'formula_breakdown',
        'base' => '',
        'height' => '',
        'pages' => '',
        'copies' => '',
        'kgs' => '0',
        'overall_rate' => '',
        'percentages' => [],
    ];
    foreach ($items as $item) {
        if (empty($item['details_json'])) {
            continue;
        }
        $details = json_decode((string) $item['details_json'], true);
        if (!is_array($details) || !isset($details['mode'])) {
            continue;
        }
        $inkMeta = array_merge($inkMeta, $details);
        break;
    }

    $fields['ink_calc_mode'] = (string) ($inkMeta['mode'] ?? 'formula_breakdown');
    $fields['ink_measure_base'] = (string) ($inkMeta['base'] ?? '');
    $fields['ink_height'] = (string) ($inkMeta['height'] ?? '');
    $fields['ink_pages'] = (string) ($inkMeta['pages'] ?? '');
    $fields['ink_quantity_copies'] = (string) ($inkMeta['copies'] ?? '');
    $fields['ink_kgs'] = number_format((float) ($inkMeta['kgs'] ?? 0), 4, '.', '');
    $fields['ink_overall_rate'] = (string) ($inkMeta['overall_rate'] ?? '');

    $inkStmt = $pdo->prepare('SELECT * FROM estimation_ink_colours WHERE estimation_id = :id ORDER BY sort_order, id');
    $inkStmt->execute(['id' => $estId]);
    $inkRows = $inkStmt->fetchAll(PDO::FETCH_ASSOC);
    $inkSubtotal = 0.0;
    $formulaKgsTotal = (float) ($inkMeta['kgs'] ?? 0);

    $fields['ink_colour'] = [];
    $fields['ink_material_id'] = [];
    $fields['ink_colour_pct'] = [];
    $fields['ink_colour_kgs'] = [];
    $fields['ink_colour_rate'] = [];
    $fields['ink_colour_total'] = [];

    foreach ($inkRows as $index => $inkRow) {
        $fields['ink_colour'][] = (string) ($inkRow['colour_name'] ?? '');
        $fields['ink_material_id'][] = (string) ($inkRow['material_id'] ?? '');
        $rowKgs = (float) ($inkRow['kgs'] ?? 0);
        $pct = '';
        if (isset($inkMeta['percentages'][$index]) && $inkMeta['percentages'][$index] !== '') {
            $pct = (string) $inkMeta['percentages'][$index];
        }
        $formulaKgs = $formulaKgsTotal;
        if ($formulaKgs <= 0) {
            $formulaKgs = estimation_compute_formula_ink_kgs_from_fields($fields);
            if ($formulaKgs > 0) {
                $formulaKgsTotal = $formulaKgs;
            }
        }
        if ($pct === '' && $formulaKgs > 0 && $rowKgs > 0) {
            $pct = number_format($rowKgs / $formulaKgs * 100, 2, '.', '');
        }
        $fields['ink_colour_pct'][] = $pct;
        $fields['ink_colour_kgs'][] = number_format($rowKgs, 4, '.', '');
        $fields['ink_colour_rate'][] = number_format((float) ($inkRow['rate'] ?? 0), 2, '.', '');
        $fields['ink_colour_total'][] = estimation_wizard_money($inkRow['total'] ?? 0);
        $inkSubtotal += (float) ($inkRow['total'] ?? 0);
    }
    if ($formulaKgsTotal > 0 && estimation_draft_parse_amount($fields['ink_kgs'] ?? 0) <= 0) {
        $fields['ink_kgs'] = number_format($formulaKgsTotal, 4, '.', '');
    }
    estimation_draft_trim_trailing_ink_rows($fields);
    $fields['cost_ink'] = estimation_wizard_money($inkSubtotal);

    $bindStmt = $pdo->prepare('SELECT * FROM estimation_binding_materials WHERE estimation_id = :id ORDER BY sort_order, id');
    $bindStmt->execute(['id' => $estId]);
    $bindingRows = $bindStmt->fetchAll(PDO::FETCH_ASSOC);
    $bindingSubtotal = 0.0;

    $fields['binding_mat_id'] = [];
    $fields['binding_mat_name'] = [];
    $fields['binding_mat_unit'] = [];
    $fields['binding_mat_qty'] = [];
    $fields['binding_mat_rate'] = [];
    $fields['binding_mat_total'] = [];

    if ($bindingRows) {
        foreach ($bindingRows as $bindingRow) {
            $fields['binding_mat_id'][] = (string) ($bindingRow['material_id'] ?? '');
            $fields['binding_mat_name'][] = (string) ($bindingRow['material_name'] ?? '');
            $fields['binding_mat_unit'][] = (string) ($bindingRow['unit'] ?? '');
            $fields['binding_mat_qty'][] = (string) ($bindingRow['quantity'] ?? '');
            $fields['binding_mat_rate'][] = number_format((float) ($bindingRow['rate'] ?? 0), 2, '.', '');
            $fields['binding_mat_total'][] = number_format((float) ($bindingRow['total'] ?? 0), 2, '.', '');
            $bindingSubtotal += (float) ($bindingRow['total'] ?? 0);
        }
    } else {
        $fields['binding_mat_id'][] = '';
        $fields['binding_mat_name'][] = '';
        $fields['binding_mat_unit'][] = '';
        $fields['binding_mat_qty'][] = '';
        $fields['binding_mat_rate'][] = '';
        $fields['binding_mat_total'][] = '0.00';
    }
    $fields['cost_binding'] = estimation_wizard_money($bindingSubtotal);

    $ppStmt = $pdo->prepare('SELECT * FROM estimation_prepress_labour WHERE estimation_id = :id ORDER BY sort_order, id');
    $ppStmt->execute(['id' => $estId]);
    $prepressRows = $ppStmt->fetchAll(PDO::FETCH_ASSOC);
    $prepressSubtotal = 0.0;

    $fields['prepress_name'] = [];
    $fields['prepress_unit'] = [];
    $fields['prepress_hrs'] = [];
    $fields['prepress_rate'] = [];
    $fields['prepress_total'] = [];

    if ($prepressRows) {
        foreach ($prepressRows as $row) {
            $fields['prepress_name'][] = (string) ($row['labour_name'] ?? '');
            $fields['prepress_unit'][] = (string) ($row['unit'] ?? 'hrs');
            $fields['prepress_hrs'][] = (string) ($row['hrs'] ?? '');
            $fields['prepress_rate'][] = number_format((float) ($row['rate'] ?? 0), 2, '.', '');
            $total = number_format((float) ($row['total'] ?? 0), 2, '.', '');
            $fields['prepress_total'][] = $total;
            $prepressSubtotal += (float) ($row['total'] ?? 0);
        }
    } else {
        $fields['prepress_name'][] = '';
        $fields['prepress_unit'][] = 'hrs';
        $fields['prepress_hrs'][] = '';
        $fields['prepress_rate'][] = '';
        $fields['prepress_total'][] = '0.00';
    }
    $fields['cost_prepress'] = estimation_wizard_money($prepressSubtotal);

    $pressStmt = $pdo->prepare('SELECT * FROM estimation_press_labour WHERE estimation_id = :id ORDER BY sort_order, id');
    $pressStmt->execute(['id' => $estId]);
    $pressRows = $pressStmt->fetchAll(PDO::FETCH_ASSOC);
    $pressSubtotal = 0.0;

    $fields['press_machine_name'] = [];
    $fields['press_colours'] = [];
    $fields['press_mr_hrs'] = [];
    $fields['press_mr_rate'] = [];
    $fields['press_mr_total'] = [];
    $fields['press_impressions'] = [];
    $fields['press_iph'] = [];
    $fields['press_run_hrs'] = [];
    $fields['press_run_rate'] = [];
    $fields['press_run_total'] = [];

    if ($pressRows) {
        foreach ($pressRows as $pressRow) {
            $fields['press_machine_name'][] = (string) ($pressRow['machine_name'] ?? '');
            $fields['press_colours'][] = (string) ($pressRow['colours'] ?? '');
            $fields['press_mr_hrs'][] = (string) ($pressRow['make_ready_hrs'] ?? '');
            $fields['press_mr_rate'][] = number_format((float) ($pressRow['make_ready_rate'] ?? 0), 2, '.', '');
            $fields['press_mr_total'][] = number_format((float) ($pressRow['make_ready_total'] ?? 0), 2, '.', '');
            $fields['press_impressions'][] = (string) ($pressRow['impressions'] ?? '');
            $fields['press_iph'][] = (string) ($pressRow['iph'] ?? '');
            $fields['press_run_hrs'][] = (string) ($pressRow['running_hrs'] ?? '');
            $fields['press_run_rate'][] = number_format((float) ($pressRow['running_rate'] ?? 0), 2, '.', '');
            $fields['press_run_total'][] = number_format((float) ($pressRow['running_total'] ?? 0), 2, '.', '');
            $pressSubtotal += (float) ($pressRow['machine_total'] ?? 0);
        }
    } else {
        $fields['press_machine_name'][] = '';
        $fields['press_colours'][] = '';
        $fields['press_mr_hrs'][] = '';
        $fields['press_mr_rate'][] = '';
        $fields['press_mr_total'][] = '0.00';
        $fields['press_impressions'][] = '';
        $fields['press_iph'][] = '';
        $fields['press_run_hrs'][] = '';
        $fields['press_run_rate'][] = '';
        $fields['press_run_total'][] = '0.00';
    }
    $fields['cost_press'] = estimation_wizard_money($pressSubtotal);

    $finStmt = $pdo->prepare('SELECT * FROM estimation_finishing_labour WHERE estimation_id = :id ORDER BY sort_order, id');
    $finStmt->execute(['id' => $estId]);
    $finishingRows = $finStmt->fetchAll(PDO::FETCH_ASSOC);
    $finishingSubtotal = 0.0;

    $fields['finishing_name'] = [];
    $fields['finishing_measure'] = [];
    $fields['finishing_impressions'] = [];
    $fields['finishing_iph'] = [];
    $fields['finishing_hrs'] = [];
    $fields['finishing_rate'] = [];
    $fields['finishing_total'] = [];

    if ($finishingRows) {
        foreach ($finishingRows as $row) {
            $fields['finishing_name'][] = (string) ($row['labour_name'] ?? '');
            $fields['finishing_measure'][] = (string) ($row['measure_type'] ?? 'items');
            $fields['finishing_impressions'][] = (string) ($row['impressions'] ?? '');
            $fields['finishing_iph'][] = (string) ($row['iph'] ?? '');
            $fields['finishing_hrs'][] = (string) ($row['hrs'] ?? '');
            $fields['finishing_rate'][] = number_format((float) ($row['rate'] ?? 0), 2, '.', '');
            $total = number_format((float) ($row['total'] ?? 0), 2, '.', '');
            $fields['finishing_total'][] = $total;
            $finishingSubtotal += (float) ($row['total'] ?? 0);
        }
    } else {
        $fields['finishing_name'][] = '';
        $fields['finishing_measure'][] = 'items';
        $fields['finishing_impressions'][] = '';
        $fields['finishing_iph'][] = '';
        $fields['finishing_hrs'][] = '';
        $fields['finishing_rate'][] = '';
        $fields['finishing_total'][] = '0.00';
    }
    $fields['cost_finishing'] = estimation_wizard_money($finishingSubtotal);
    $fields['cost_labour_total'] = estimation_wizard_money($prepressSubtotal + $pressSubtotal + $finishingSubtotal);

    $fields['consumable_mat_id'] = [];
    $fields['consumable_mat_unit'] = [];
    $fields['consumable_mat_qty'] = [];
    $fields['consumable_mat_rate'] = [];
    $fields['consumable_mat_total'] = [];
    $fields['cost_consumables_misc'] = '0';

    foreach ($items as $item) {
        $details = [];
        if (!empty($item['details_json'])) {
            $decoded = json_decode((string) $item['details_json'], true);
            if (is_array($decoded)) {
                $details = $decoded;
            }
        }

        if (!empty($details['consumable_misc'])) {
            $fields['cost_consumables_misc'] = number_format((float) ($item['total_price'] ?? 0), 2, '.', '');
            continue;
        }

        if (!empty($details['consumable_rollup'])
            || (strtolower(trim((string) ($item['description'] ?? ''))) === 'consumables' && empty($details['multi_paper']))) {
            $fields['cost_consumables_misc'] = number_format((float) ($item['total_price'] ?? 0), 2, '.', '');
            continue;
        }

        if (empty($details['consumable']) || empty($details['material_id'])) {
            continue;
        }

        $fields['consumable_mat_id'][] = (string) $details['material_id'];
        $fields['consumable_mat_unit'][] = (string) ($details['unit'] ?? '');
        $fields['consumable_mat_qty'][] = (string) ($item['quantity'] ?? '');
        $fields['consumable_mat_rate'][] = number_format((float) ($item['unit_price'] ?? 0), 2, '.', '');
        $fields['consumable_mat_total'][] = number_format((float) ($item['total_price'] ?? 0), 2, '.', '');
    }

    if ($fields['consumable_mat_id'] === [] && (float) ($fields['cost_consumables_misc'] ?? 0) <= 0) {
        $fields['cost_consumables_misc'] = (string) ($fields['cost_consumables'] ?? '0');
    }

    $catalogConsumableTotal = 0.0;
    foreach ($fields['consumable_mat_total'] as $lineTotal) {
        $catalogConsumableTotal += (float) $lineTotal;
    }
    $fields['cost_consumables'] = estimation_wizard_money(
        $catalogConsumableTotal + (float) ($fields['cost_consumables_misc'] ?? 0)
    );

    return $fields;
}

/**
 * Normalize ink arrays after merge / load so the wizard can recalculate step 4.
 *
 * @param array<string, mixed> $fields
 * @return array<string, mixed>
 */
function estimation_draft_finalize_fields(array $fields): array
{
    estimation_draft_trim_trailing_ink_rows($fields);

    $formulaKgs = estimation_compute_formula_ink_kgs_from_fields($fields);
    if ($formulaKgs > 0 && estimation_draft_parse_amount($fields['ink_kgs'] ?? 0) <= 0) {
        $fields['ink_kgs'] = number_format($formulaKgs, 4, '.', '');
    }

    if ($formulaKgs > 0 && isset($fields['ink_colour_kgs']) && is_array($fields['ink_colour_kgs'])) {
        if (!isset($fields['ink_colour_pct']) || !is_array($fields['ink_colour_pct'])) {
            $fields['ink_colour_pct'] = [];
        }
        foreach ($fields['ink_colour_kgs'] as $idx => $kgsVal) {
            $kgs = (float) $kgsVal;
            $pct = trim((string) ($fields['ink_colour_pct'][$idx] ?? ''));
            if ($pct === '' && $kgs > 0) {
                $fields['ink_colour_pct'][$idx] = number_format($kgs / $formulaKgs * 100, 2, '.', '');
            }
        }
    }

    return $fields;
}

/**
 * @param array<string, mixed> $estimation
 * @return array{fields: array<string, mixed>, source: string, repaired: bool}
 */
function estimation_resolve_draft_fields(PDO $pdo, array $estimation): array
{
    $jsonFields = [];
    if (!empty($estimation['draft_data'])) {
        $decoded = json_decode((string) $estimation['draft_data'], true);
        if (is_array($decoded)) {
            $jsonFields = $decoded;
        }
    }

    $dbFields = estimation_draft_fields_from_database($pdo, $estimation);
    $jsonHasValues = estimation_draft_has_wizard_values($jsonFields);
    $dbHasValues = estimation_draft_has_wizard_values($dbFields);

    if ($jsonHasValues && $dbHasValues) {
        $jsonScore = estimation_draft_completeness_score($jsonFields);
        $dbScore = estimation_draft_completeness_score($dbFields);

        if ($dbScore > $jsonScore) {
            $fields = estimation_draft_finalize_fields(estimation_draft_merge_fields($dbFields, $jsonFields));
            return [
                'fields' => $fields,
                'source' => 'database_merged',
                'repaired' => true,
            ];
        }

        $fields = estimation_draft_finalize_fields(estimation_draft_merge_fields($jsonFields, $dbFields));
        return [
            'fields' => $fields,
            'source' => 'draft_data_merged',
            'repaired' => $dbScore > 0 && $dbScore >= $jsonScore,
        ];
    }

    if ($jsonHasValues) {
        return [
            'fields' => estimation_draft_finalize_fields($jsonFields),
            'source' => 'draft_data',
            'repaired' => false,
        ];
    }

    if ($dbHasValues) {
        return [
            'fields' => estimation_draft_finalize_fields($dbFields),
            'source' => 'database',
            'repaired' => true,
        ];
    }

    return [
        'fields' => estimation_draft_finalize_fields($jsonFields ?: $dbFields),
        'source' => 'empty',
        'repaired' => false,
    ];
}

/**
 * @param array<string, mixed> $fields
 */
function estimation_draft_content_hash_from_fields(array $fields): string
{
    $canonical = estimation_draft_canonicalize_fields($fields);
    $json = json_encode($canonical, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    return hash('sha256', $json !== false ? $json : '');
}

/**
 * @param mixed $value
 * @return mixed
 */
function estimation_draft_canonicalize_fields($value)
{
    if (!is_array($value)) {
        return $value;
    }

    $isList = array_keys($value) === range(0, count($value) - 1);
    if (!$isList) {
        ksort($value);
    }

    foreach ($value as $key => $child) {
        $value[$key] = estimation_draft_canonicalize_fields($child);
    }

    return $value;
}

/**
 * @param array<string, mixed> $fields
 */
function estimation_repair_draft_data(PDO $pdo, int $estId, array $fields, int $draftStep = 1): void
{
    $json = json_encode($fields, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        return;
    }

    $hash = estimation_draft_content_hash_from_fields($fields);

    $stmt = $pdo->prepare('
        UPDATE estimations
        SET draft_data = :draft_data,
            draft_content_hash = :hash,
            draft_step = :step,
            last_auto_saved = NOW()
        WHERE id = :id
    ');
    $stmt->execute([
        'draft_data' => $json,
        'hash' => $hash,
        'step' => max(1, $draftStep),
        'id' => $estId,
    ]);
}
