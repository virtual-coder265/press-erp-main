<?php
require_once __DIR__ . '/../../config/app.php';
checkAuth();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/permissions_helper.php';
require_once __DIR__ . '/../../libs/MaterialSpecMigrator.php';
require_once __DIR__ . '/../../includes/material_match_helper.php';

header('Content-Type: application/json; charset=utf-8');

permissions_require_one_of(['view_materials', 'manage_materials', 'manage_estimations']);
MaterialSpecMigrator::ensure($pdo);

$action = $_GET['action'] ?? 'search';

try {
    if ($action === 'distinct') {
        $field = (string) ($_GET['field'] ?? 'stock_type');
        $filters = [
            'category' => $_GET['category'] ?? null,
            'category_id' => $_GET['category_id'] ?? null,
            'material_kind' => $_GET['material_kind'] ?? null,
            'stock_type' => $_GET['stock_type'] ?? null,
            'color' => $_GET['color'] ?? null,
            'grammage' => $_GET['grammage'] ?? null,
            'dimensions' => $_GET['dimensions'] ?? null,
            'thickness_mm' => $_GET['thickness_mm'] ?? null,
            'brand' => $_GET['brand'] ?? null,
            'q' => $_GET['q'] ?? null,
        ];
        $values = material_distinct_specs($pdo, array_filter($filters, fn($v) => $v !== null && $v !== ''), $field);
        echo json_encode(['status' => 'success', 'field' => $field, 'values' => $values]);
        exit;
    }

    if ($action === 'match') {
        $filters = [
            'category' => $_GET['category'] ?? null,
            'category_id' => $_GET['category_id'] ?? null,
            'material_kind' => $_GET['material_kind'] ?? null,
            'stock_type' => $_GET['stock_type'] ?? null,
            'color' => $_GET['color'] ?? null,
            'grammage' => $_GET['grammage'] ?? null,
            'dimensions' => $_GET['dimensions'] ?? null,
            'thickness_mm' => $_GET['thickness_mm'] ?? null,
            'brand' => $_GET['brand'] ?? null,
            'q' => $_GET['q'] ?? null,
        ];
        $match = material_find_best_match($pdo, array_filter($filters, fn($v) => $v !== null && $v !== ''));
        echo json_encode([
            'status' => 'success',
            'match' => $match ? material_row_to_api($match) : null,
        ]);
        exit;
    }

    $filters = [
        'category' => $_GET['category'] ?? null,
        'category_id' => $_GET['category_id'] ?? null,
        'material_kind' => $_GET['material_kind'] ?? null,
        'stock_type' => $_GET['stock_type'] ?? null,
        'color' => $_GET['color'] ?? null,
        'grammage' => $_GET['grammage'] ?? null,
        'dimensions' => $_GET['dimensions'] ?? null,
        'thickness_mm' => $_GET['thickness_mm'] ?? null,
        'brand' => $_GET['brand'] ?? null,
        'q' => $_GET['q'] ?? null,
    ];
    $rows = material_search($pdo, array_filter($filters, fn($v) => $v !== null && $v !== ''));
    echo json_encode([
        'status' => 'success',
        'count' => count($rows),
        'materials' => array_map('material_row_to_api', $rows),
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
