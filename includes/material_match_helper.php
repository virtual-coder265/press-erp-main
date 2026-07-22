<?php
/**
 * Material specification parsing, normalization, and catalog search for estimations.
 */

const MATERIAL_KINDS = ['paper', 'plate', 'film', 'separation', 'ink', 'binding', 'consumable', 'general'];

const MATERIAL_DIMENSION_ALIASES = [
    '210x297' => 'A4',
    '210 x 297' => 'A4',
    '297x420' => 'A3',
    '420x594' => 'A2',
    '594x841' => 'A1',
    '841x1189' => 'A0',
];

const MATERIAL_INK_COLOR_ALIASES = [
    'c' => 'Cyan',
    'cyan' => 'Cyan',
    'm' => 'Magenta',
    'magenta' => 'Magenta',
    'y' => 'Yellow',
    'yellow' => 'Yellow',
    'k' => 'Black',
    'black' => 'Black',
    'varnish' => 'Varnish',
];

const ESTIMATION_STD_MATERIAL_SLOTS = [
    ['key' => 'proofing', 'label' => 'Proofing Paper', 'qty_label' => 'No. of Sheets', 'material_kind' => 'paper', 'stock_type' => 'Proofing Paper'],
    ['key' => 'film', 'label' => 'Film', 'qty_label' => 'No. of Pieces', 'material_kind' => 'film'],
    ['key' => 'plate', 'label' => 'Plate', 'qty_label' => 'No. of Plates', 'material_kind' => 'plate'],
    ['key' => 'separation', 'label' => 'Colour Separation', 'qty_label' => 'No. of Sets', 'material_kind' => 'separation'],
];

/**
 * @return array<string, string[]>
 */
function material_category_kind_map(): array
{
    return [
        'printing papers' => ['paper'],
        'printing plates' => ['plate'],
        'general materials' => ['film', 'general'],
        'colour separation' => ['separation'],
        'printing inks' => ['ink'],
        'binding materials' => ['binding'],
        'printing consumables' => ['consumable'],
        'printing materials' => ['general'],
    ];
}

/**
 * @return string[]
 */
function material_spec_filters_for_category(string $categoryName): array
{
    $key = strtolower(trim($categoryName));
    return match ($key) {
        'printing papers' => ['stock_type', 'color', 'grammage', 'dimensions'],
        'printing plates' => ['dimensions'],
        'general materials' => ['material_kind', 'dimensions', 'stock_type'],
        'colour separation' => ['dimensions'],
        'printing inks' => ['color', 'brand', 'stock_type'],
        'binding materials' => ['stock_type', 'color', 'thickness_mm'],
        'printing consumables' => ['stock_type'],
        default => ['q'],
    };
}

function material_normalize_dimensions(string $input): string
{
    $value = trim($input);
    if ($value === '') {
        return '';
    }

    $upper = strtoupper($value);
    if (preg_match('/^A[0-9]+$/', $upper)) {
        return $upper;
    }

    $compact = strtolower(preg_replace('/\s+/', '', $value));
    $compact = str_replace('mm', '', $compact);
    if (isset(MATERIAL_DIMENSION_ALIASES[$compact])) {
        return MATERIAL_DIMENSION_ALIASES[$compact];
    }

    if (preg_match('/^(\d+)x(\d+)$/', $compact, $m)) {
        $normalized = $m[1] . 'x' . $m[2];
        return MATERIAL_DIMENSION_ALIASES[$normalized] ?? ($m[1] . 'x' . $m[2] . 'mm');
    }

    if (preg_match('/^(\d+)x(\d+)mm$/', $compact, $m)) {
        $normalized = $m[1] . 'x' . $m[2];
        return MATERIAL_DIMENSION_ALIASES[$normalized] ?? ($m[1] . 'x' . $m[2] . 'mm');
    }

    return $value;
}

function material_normalize_color(string $input): string
{
    $value = trim($input);
    if ($value === '') {
        return '';
    }
    $key = strtolower($value);
    return MATERIAL_INK_COLOR_ALIASES[$key] ?? ucwords(strtolower($value));
}

function material_kind_from_category(string $categoryName): ?string
{
    $key = strtolower(trim($categoryName));
    $map = material_category_kind_map();
    if (!isset($map[$key])) {
        return null;
    }
    return $map[$key][0] ?? null;
}

/**
 * Parse a material display name into structured specification fields.
 *
 * @return array<string, mixed>
 */
function material_parse_name_specs(string $name, ?string $categoryName = null): array
{
    $specs = [
        'material_kind' => material_kind_from_category((string) $categoryName),
        'stock_type' => null,
        'grammage' => null,
        'color' => null,
        'dimensions' => null,
        'thickness_mm' => null,
        'brand' => null,
    ];

    $n = trim($name);
    $cat = strtolower(trim((string) $categoryName));

    if ($cat === 'printing papers') {
        $specs['material_kind'] = 'paper';
        if (preg_match('/^(\d+(?:\.\d+)?)\s*gsm\b/i', $n, $m)) {
            $specs['grammage'] = (float) $m[1];
        }
        if (preg_match('/\b(A[0-9])\b/i', $n, $m)) {
            $specs['dimensions'] = strtoupper($m[1]);
        }
        $colors = ['Blue', 'Cream', 'Green', 'White', 'Pink', 'Yellow', 'Brown', 'Grey', 'Gray', 'Black', 'Red'];
        foreach ($colors as $color) {
            if (preg_match('/\b' . preg_quote($color, '/') . '\b/i', $n)) {
                $specs['color'] = ucfirst(strtolower($color));
                if ($specs['color'] === 'Gray') {
                    $specs['color'] = 'Grey';
                }
                break;
            }
        }
        if (preg_match('/\bstrawboard\b/i', $n)) {
            $specs['stock_type'] = 'Strawboard';
            if (preg_match('/^(\d+(?:\.\d+)?)\s*mm\b/i', $n, $m)) {
                $specs['thickness_mm'] = (float) $m[1];
            }
        } elseif (preg_match('/\bproofing\b/i', $n)) {
            $specs['stock_type'] = 'Proofing Paper';
        } else {
            $types = [
                'NCR Paper', 'Bank Paper', 'Bond Paper', 'Manilla', 'Glossy Art Paper', 'Board Paper',
                'Laid Paper', 'Kraft Paper', 'Valcoat Board', 'Art Paper',
            ];
            foreach ($types as $type) {
                if (stripos($n, $type) !== false) {
                    $specs['stock_type'] = $type;
                    break;
                }
            }
            if ($specs['stock_type'] === null && preg_match('/\bpaper\b/i', $n)) {
                $specs['stock_type'] = trim(preg_replace('/^\d+(?:\.\d+)?\s*gsm\s*/i', '', $n));
            }
        }
        return $specs;
    }

    if ($cat === 'printing plates' || preg_match('/\bplate\b/i', $n)) {
        $specs['material_kind'] = 'plate';
        $specs['stock_type'] = 'Positive Plate';
        if (preg_match('/(\d+\s*x\s*\d+)\s*mm/i', $n, $m)) {
            $specs['dimensions'] = material_normalize_dimensions(str_replace(' ', '', $m[1]));
        }
        return $specs;
    }

    if ($cat === 'colour separation' || preg_match('/colour\s*separation/i', $n)) {
        $specs['material_kind'] = 'separation';
        $specs['stock_type'] = 'Colour Separation';
        if (preg_match('/\b(A[0-9])\b/i', $n, $m)) {
            $specs['dimensions'] = strtoupper($m[1]);
        }
        return $specs;
    }

    if ($cat === 'general materials' || preg_match('/\bfilm\b/i', $n)) {
        if (preg_match('/\bfilm\b/i', $n)) {
            $specs['material_kind'] = 'film';
            $specs['stock_type'] = 'Laser Film';
        }
        if (preg_match('/\b(A[0-9])\b/i', $n, $m)) {
            $specs['dimensions'] = strtoupper($m[1]);
        }
        if (preg_match('/\bgold foil\b/i', $n)) {
            $specs['material_kind'] = 'general';
            $specs['stock_type'] = 'Gold Foil';
        }
        return $specs;
    }

    if ($cat === 'printing inks') {
        $specs['material_kind'] = 'ink';
        if (preg_match('/^(.+?)\s*[-–]\s*(.+)$/u', $n, $m)) {
            $left = trim($m[1]);
            $right = trim($m[2]);
            if (preg_match('/\btoner\b/i', $left) || preg_match('/\bink\b/i', $left)) {
                $specs['stock_type'] = preg_match('/\btoner\b/i', $left) ? 'Toner' : 'Process Inks';
                $specs['brand'] = trim(preg_replace('/\s*(toner|ink)s?\s*$/i', '', $left));
                $specs['color'] = material_normalize_color($right);
            }
        } elseif (preg_match('/\briso\s*ink\b/i', $n)) {
            $specs['stock_type'] = 'Riso Ink';
            $specs['brand'] = 'Riso';
        }
        return $specs;
    }

    if ($cat === 'binding materials') {
        $specs['material_kind'] = 'binding';
        if (preg_match('/^(\d+(?:\.\d+)?)\s*mm\b/i', $n, $m)) {
            $specs['thickness_mm'] = (float) $m[1];
        }
        $colors = ['Green', 'Blue', 'Red', 'Black', 'Brown', 'Maroon', 'Grey', 'Gray', 'White'];
        foreach ($colors as $color) {
            if (preg_match('/\b' . preg_quote($color, '/') . '\b/i', $n)) {
                $specs['color'] = ucfirst(strtolower($color));
                break;
            }
        }
        $types = ['Book Cloth', 'Stitching Wire', 'PVA Glue', 'Hotmelt Glue', 'Binding Tape'];
        foreach ($types as $type) {
            if (stripos($n, $type) !== false) {
                $specs['stock_type'] = $type;
                break;
            }
        }
        return $specs;
    }

    if ($cat === 'printing consumables') {
        $specs['material_kind'] = 'consumable';
        $specs['stock_type'] = $n;
        return $specs;
    }

    if ($cat === 'printing materials') {
        $specs['material_kind'] = 'general';
        $specs['stock_type'] = $n;
        return $specs;
    }

    return $specs;
}

/**
 * @param array<string, mixed> $filters
 * @return array<int, array<string, mixed>>
 */
function material_search(PDO $pdo, array $filters = []): array
{
    $sql = "
        SELECT m.id, m.name, m.unit, m.description, m.material_kind, m.stock_type,
               m.grammage, m.color, m.dimensions, m.thickness_mm, m.brand,
               mc.name AS category_name, mc.id AS category_id,
               COALESCE(r.rate, 0) AS rate
        FROM materials m
        LEFT JOIN material_categories mc ON mc.id = m.category_id
        LEFT JOIN (
            SELECT material_id, rate
            FROM material_rates
            WHERE id IN (SELECT MAX(id) FROM material_rates GROUP BY material_id)
        ) r ON r.material_id = m.id
        WHERE 1=1
    ";
    $params = [];

    if (!empty($filters['category'])) {
        $sql .= ' AND LOWER(mc.name) = LOWER(:category)';
        $params['category'] = (string) $filters['category'];
    }
    if (!empty($filters['category_id'])) {
        $sql .= ' AND m.category_id = :category_id';
        $params['category_id'] = (int) $filters['category_id'];
    }
    if (!empty($filters['material_kind'])) {
        $sql .= ' AND m.material_kind = :material_kind';
        $params['material_kind'] = (string) $filters['material_kind'];
    }
    if (!empty($filters['stock_type'])) {
        $sql .= ' AND LOWER(m.stock_type) = LOWER(:stock_type)';
        $params['stock_type'] = (string) $filters['stock_type'];
    }
    if (isset($filters['grammage']) && $filters['grammage'] !== '' && $filters['grammage'] !== null) {
        $sql .= ' AND m.grammage = :grammage';
        $params['grammage'] = (float) $filters['grammage'];
    }
    if (!empty($filters['color'])) {
        $sql .= ' AND LOWER(m.color) = LOWER(:color)';
        $params['color'] = material_normalize_color((string) $filters['color']);
    }
    if (!empty($filters['dimensions'])) {
        $dim = material_normalize_dimensions((string) $filters['dimensions']);
        $sql .= ' AND (m.dimensions = :dimensions OR m.dimensions = :dimensions_alt)';
        $params['dimensions'] = $dim;
        $params['dimensions_alt'] = material_normalize_dimensions(str_replace('mm', '', $dim));
    }
    if (isset($filters['thickness_mm']) && $filters['thickness_mm'] !== '' && $filters['thickness_mm'] !== null) {
        $sql .= ' AND m.thickness_mm = :thickness_mm';
        $params['thickness_mm'] = (float) $filters['thickness_mm'];
    }
    if (!empty($filters['brand'])) {
        $sql .= ' AND LOWER(m.brand) LIKE LOWER(:brand)';
        $params['brand'] = '%' . trim((string) $filters['brand']) . '%';
    }
    if (!empty($filters['q'])) {
        $sql .= ' AND (m.name LIKE :q OR m.stock_type LIKE :q OR m.description LIKE :q)';
        $params['q'] = '%' . trim((string) $filters['q']) . '%';
    }

    $sql .= ' ORDER BY m.name ASC LIMIT 200';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * @param array<string, mixed> $filters
 */
function material_find_best_match(PDO $pdo, array $filters): ?array
{
    $results = material_search($pdo, $filters);
    if (count($results) === 1) {
        return $results[0];
    }
    return null;
}

/**
 * @return array<int, mixed>
 */
function material_distinct_specs(PDO $pdo, array $filters = [], string $field = 'stock_type'): array
{
    $allowed = ['stock_type', 'color', 'grammage', 'dimensions', 'thickness_mm', 'brand', 'material_kind'];
    if (!in_array($field, $allowed, true)) {
        return [];
    }

    $rows = material_search($pdo, $filters);
    $values = [];
    foreach ($rows as $row) {
        $val = $row[$field] ?? null;
        if ($val === null || $val === '') {
            continue;
        }
        $key = is_numeric($val) ? (string) (float) $val : (string) $val;
        $values[$key] = $val;
    }
    ksort($values, SORT_NATURAL | SORT_FLAG_CASE);
    return array_values($values);
}

/**
 * @return array<string, mixed>
 */
function material_row_to_api(array $row): array
{
    return [
        'id' => (int) ($row['id'] ?? 0),
        'name' => (string) ($row['name'] ?? ''),
        'unit' => (string) ($row['unit'] ?? ''),
        'rate' => (float) ($row['rate'] ?? 0),
        'category_name' => (string) ($row['category_name'] ?? ''),
        'material_kind' => (string) ($row['material_kind'] ?? ''),
        'stock_type' => (string) ($row['stock_type'] ?? ''),
        'grammage' => $row['grammage'] !== null ? (float) $row['grammage'] : null,
        'color' => (string) ($row['color'] ?? ''),
        'dimensions' => (string) ($row['dimensions'] ?? ''),
        'thickness_mm' => $row['thickness_mm'] !== null ? (float) $row['thickness_mm'] : null,
        'brand' => (string) ($row['brand'] ?? ''),
    ];
}

/**
 * @param array<string, mixed> $specs
 */
function material_save_specs(PDO $pdo, int $materialId, array $specs): void
{
    $stmt = $pdo->prepare(
        'UPDATE materials SET material_kind = :material_kind, stock_type = :stock_type,
         grammage = :grammage, color = :color, dimensions = :dimensions,
         thickness_mm = :thickness_mm, brand = :brand WHERE id = :id'
    );
    $stmt->execute([
        'material_kind' => $specs['material_kind'] ?? null,
        'stock_type' => $specs['stock_type'] ?? null,
        'grammage' => $specs['grammage'] ?? null,
        'color' => $specs['color'] ?? null,
        'dimensions' => $specs['dimensions'] ?? null,
        'thickness_mm' => $specs['thickness_mm'] ?? null,
        'brand' => $specs['brand'] ?? null,
        'id' => $materialId,
    ]);
}

/**
 * @return array<string, mixed>
 */
function material_specs_from_post(array $post): array
{
    $grammage = trim((string) ($post['grammage'] ?? ''));
    $thickness = trim((string) ($post['thickness_mm'] ?? ''));

    return [
        'material_kind' => trim((string) ($post['material_kind'] ?? '')) ?: null,
        'stock_type' => trim((string) ($post['stock_type'] ?? '')) ?: null,
        'grammage' => $grammage !== '' ? (float) $grammage : null,
        'color' => trim((string) ($post['color'] ?? '')) ?: null,
        'dimensions' => trim((string) ($post['dimensions'] ?? '')) ?: null,
        'thickness_mm' => $thickness !== '' ? (float) $thickness : null,
        'brand' => trim((string) ($post['brand'] ?? '')) ?: null,
    ];
}
