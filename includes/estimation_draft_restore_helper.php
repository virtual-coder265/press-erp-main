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
 * @param array<string, mixed> $fields
 */
function estimation_draft_has_wizard_values(array $fields): bool
{
    $scalarKeys = ['customer_name', 'job_title', 'job_description', 'grand_total', 'subtotal'];
    foreach ($scalarKeys as $key) {
        $value = trim((string) ($fields[$key] ?? ''));
        if ($value !== '' && $value !== 'MK0' && $value !== '0' && $value !== '0.00') {
            return true;
        }
    }

    $numericArrays = ['material_qty', 'paper_sheets', 'binding_mat_qty', 'press_impressions', 'finishing_hrs'];
    foreach ($numericArrays as $key) {
        if (!isset($fields[$key]) || !is_array($fields[$key])) {
            continue;
        }
        foreach ($fields[$key] as $item) {
            $value = trim((string) $item);
            if ($value !== '' && (float) $value != 0.0) {
                return true;
            }
        }
    }

    if (!empty($fields['ink_measure_base']) || !empty($fields['ink_pages']) || !empty($fields['ink_quantity_copies'])) {
        return true;
    }

    if (isset($fields['ink_colour_kgs']) && is_array($fields['ink_colour_kgs'])) {
        foreach ($fields['ink_colour_kgs'] as $kgs) {
            if ((float) $kgs > 0) {
                return true;
            }
        }
    }

    return false;
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
        'cost_miscellaneous' => (string) ($estimation['cost_consumables_amount'] ?? '0'),
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
        if ($materialId === '') {
            continue;
        }

        $stdIndex = array_search($materialId, $stdIds, true);
        if ($stdIndex !== false) {
            $materialQty[$stdIndex] = (string) ($item['quantity'] ?? '');
            $materialRate[$stdIndex] = number_format((float) ($item['unit_price'] ?? 0), 2, '.', '');
            $materialTotal[$stdIndex] = number_format((float) ($item['total_price'] ?? 0), 2, '.', '');
            continue;
        }

        $materialIds[] = $materialId;
        $materialQty[] = (string) ($item['quantity'] ?? '');
        $materialRate[] = number_format((float) ($item['unit_price'] ?? 0), 2, '.', '');
        $materialTotal[] = number_format((float) ($item['total_price'] ?? 0), 2, '.', '');
    }

    $fields['material_id'] = $materialIds;
    $fields['material_qty'] = $materialQty;
    $fields['material_rate'] = $materialRate;
    $fields['material_total'] = $materialTotal;

    $paperStmt = $pdo->prepare('SELECT * FROM estimation_papers WHERE estimation_id = :id ORDER BY sort_order, id');
    $paperStmt->execute(['id' => $estId]);
    $papers = $paperStmt->fetchAll(PDO::FETCH_ASSOC);

    $fields['paper_type'] = [];
    $fields['paper_size'] = [];
    $fields['paper_grammage'] = [];
    $fields['paper_color'] = [];
    $fields['paper_sheets'] = [];
    $fields['paper_rate'] = [];
    $fields['paper_total'] = [];
    $paperSubtotal = 0.0;

    foreach ($papers as $paper) {
        $fields['paper_type'][] = (string) ($paper['paper_type'] ?? '');
        $fields['paper_size'][] = (string) ($paper['paper_size'] ?? '');
        $fields['paper_grammage'][] = (string) ($paper['paper_grammage'] ?? '');
        $fields['paper_color'][] = (string) ($paper['paper_color'] ?? '');
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

    $fields['ink_colour'] = [];
    $fields['ink_colour_pct'] = [];
    $fields['ink_colour_kgs'] = [];
    $fields['ink_colour_rate'] = [];
    $fields['ink_colour_total'] = [];

    foreach ($inkRows as $index => $inkRow) {
        $fields['ink_colour'][] = (string) ($inkRow['colour_name'] ?? '');
        $pct = '';
        if (isset($inkMeta['percentages'][$index])) {
            $pct = (string) $inkMeta['percentages'][$index];
        }
        $fields['ink_colour_pct'][] = $pct;
        $fields['ink_colour_kgs'][] = number_format((float) ($inkRow['kgs'] ?? 0), 4, '.', '');
        $fields['ink_colour_rate'][] = number_format((float) ($inkRow['rate'] ?? 0), 2, '.', '');
        $fields['ink_colour_total'][] = estimation_wizard_money($inkRow['total'] ?? 0);
        $inkSubtotal += (float) ($inkRow['total'] ?? 0);
    }
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
    $fields['cost_binding'] = (string) $bindingSubtotal;

    $ppStmt = $pdo->prepare('SELECT * FROM estimation_prepress_labour WHERE estimation_id = :id ORDER BY sort_order, id');
    $ppStmt->execute(['id' => $estId]);
    $prepressByName = estimation_index_rows_by_name($ppStmt->fetchAll(PDO::FETCH_ASSOC), 'labour_name');
    $prepressSubtotal = 0.0;

    $fields['prepress_name'] = [];
    $fields['prepress_unit'] = [];
    $fields['prepress_hrs'] = [];
    $fields['prepress_rate'] = [];
    $fields['prepress_total'] = [];

    foreach (ESTIMATION_DEFAULT_PREPRESS_NAMES as $name) {
        $row = $prepressByName[$name] ?? null;
        $fields['prepress_name'][] = $name;
        $fields['prepress_unit'][] = 'hrs';
        $fields['prepress_hrs'][] = $row ? (string) ($row['hrs'] ?? '') : '';
        $fields['prepress_rate'][] = $row ? number_format((float) ($row['rate'] ?? 0), 2, '.', '') : '';
        $total = $row ? number_format((float) ($row['total'] ?? 0), 2, '.', '') : '0.00';
        $fields['prepress_total'][] = $total;
        $prepressSubtotal += (float) ($row['total'] ?? 0);
    }
    $fields['cost_prepress'] = (string) $prepressSubtotal;

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
    $fields['cost_press'] = (string) $pressSubtotal;

    $finStmt = $pdo->prepare('SELECT * FROM estimation_finishing_labour WHERE estimation_id = :id ORDER BY sort_order, id');
    $finStmt->execute(['id' => $estId]);
    $finishingByName = estimation_index_rows_by_name($finStmt->fetchAll(PDO::FETCH_ASSOC), 'labour_name');
    $finishingSubtotal = 0.0;

    $fields['finishing_name'] = [];
    $fields['finishing_measure'] = [];
    $fields['finishing_impressions'] = [];
    $fields['finishing_iph'] = [];
    $fields['finishing_hrs'] = [];
    $fields['finishing_rate'] = [];
    $fields['finishing_total'] = [];

    foreach (ESTIMATION_DEFAULT_FINISHING_ROWS as [$name, $measure]) {
        $row = $finishingByName[$name] ?? null;
        $fields['finishing_name'][] = $name;
        $fields['finishing_measure'][] = $row ? (string) ($row['measure_type'] ?? $measure) : $measure;
        $fields['finishing_impressions'][] = $row ? (string) ($row['impressions'] ?? '') : '';
        $fields['finishing_iph'][] = $row ? (string) ($row['iph'] ?? '') : '';
        $fields['finishing_hrs'][] = $row ? (string) ($row['hrs'] ?? '') : '';
        $fields['finishing_rate'][] = $row ? number_format((float) ($row['rate'] ?? 0), 2, '.', '') : '';
        $total = $row ? number_format((float) ($row['total'] ?? 0), 2, '.', '') : '0.00';
        $fields['finishing_total'][] = $total;
        $finishingSubtotal += (float) ($row['total'] ?? 0);
    }
    $fields['cost_finishing'] = (string) $finishingSubtotal;
    $fields['cost_labour_total'] = estimation_wizard_money($prepressSubtotal + $pressSubtotal + $finishingSubtotal);

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

    if ($jsonHasValues) {
        return [
            'fields' => $jsonFields,
            'source' => 'draft_data',
            'repaired' => false,
        ];
    }

    if ($dbHasValues) {
        return [
            'fields' => $dbFields,
            'source' => 'database',
            'repaired' => true,
        ];
    }

    return [
        'fields' => $jsonFields ?: $dbFields,
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
