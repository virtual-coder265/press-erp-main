<?php

require_once __DIR__ . '/../../config/app.php';

checkAuth();

require_once __DIR__ . '/../../config/database.php';

require_once __DIR__ . '/../../includes/permissions_helper.php';

require_once __DIR__ . '/../../libs/MaterialSpecMigrator.php';

require_once __DIR__ . '/../../includes/material_match_helper.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $action = $_POST['action'] ?? '';



    if ($action === 'quick_add') {

        permissions_require_one_of(['manage_materials', 'manage_estimations']);

    } elseif ($action === 'create' || $action === 'update') {

        permissions_require_one_of(['manage_materials']);

    }



    MaterialSpecMigrator::ensure($pdo);



    if ($action === 'create' || $action === 'update' || $action === 'quick_add') {

        $name = $_POST['name'] ?? '';

        $unit = $_POST['unit'] ?? '';

        $category_id = $_POST['category_id'] ?? null;

        $rate = $_POST['rate'] ?? 0;

        $description = $_POST['description'] ?? '';

        $user_id = $_SESSION['user_id'];

        $specs = material_specs_from_post($_POST);



        if (trim($name) === '' && !empty($specs['stock_type'])) {

            $nameParts = [];

            if ($specs['grammage'] !== null && $specs['grammage'] !== '') {

                $nameParts[] = rtrim(rtrim(number_format((float) $specs['grammage'], 2, '.', ''), '0'), '.') . 'gsm';

            }

            if (!empty($specs['color'])) {

                $nameParts[] = $specs['color'];

            }

            if (!empty($specs['dimensions'])) {

                $nameParts[] = $specs['dimensions'];

            }

            $nameParts[] = $specs['stock_type'];

            $name = trim(implode(' ', $nameParts));

        }



        if (empty($name) || empty($unit)) {

            die("Name and Unit are required.");

        }



        try {

            $pdo->beginTransaction();



            if ($action === 'create' || $action === 'quick_add') {

                // If quick_add and no category, use a default if available or null

                if ($action === 'quick_add' && empty($category_id)) {

                    $default_cat = $pdo->query("SELECT id FROM material_categories LIMIT 1")->fetchColumn();

                    $category_id = $default_cat ?: null;

                }



                $stmt = $pdo->prepare("INSERT INTO materials (category_id, name, unit, description) VALUES (?, ?, ?, ?)");

                $stmt->execute([$category_id, $name, $unit, $description]);

                $material_id = (int) $pdo->lastInsertId();

            } else {

                $material_id = (int) $_POST['id'];

                $stmt = $pdo->prepare("UPDATE materials SET category_id = ?, name = ?, unit = ?, description = ? WHERE id = ?");

                $stmt->execute([$category_id, $name, $unit, $description, $material_id]);

            }



            if ($specs['material_kind'] === null && !empty($category_id)) {

                $catStmt = $pdo->prepare('SELECT name FROM material_categories WHERE id = ? LIMIT 1');

                $catStmt->execute([$category_id]);

                $catName = (string) ($catStmt->fetchColumn() ?: '');

                if ($catName !== '') {

                    $parsed = material_parse_name_specs($name, $catName);

                    foreach ($parsed as $key => $value) {

                        if (($specs[$key] ?? null) === null && $value !== null && $value !== '') {

                            $specs[$key] = $value;

                        }

                    }

                }

            }



            material_save_specs($pdo, $material_id, $specs);



            // Check if rate changed or is new

            $stmt = $pdo->prepare("SELECT rate FROM material_rates WHERE material_id = ? ORDER BY effective_date DESC LIMIT 1");

            $stmt->execute([$material_id]);

            $last_rate = $stmt->fetchColumn();



            if ($last_rate !== $rate) {

                $stmt = $pdo->prepare("INSERT INTO material_rates (material_id, rate, effective_date, created_by) VALUES (?, ?, CURDATE(), ?)");

                $stmt->execute([$material_id, $rate, $user_id]);

            }



            $pdo->commit();



            if ($action === 'quick_add') {

                echo json_encode([

                    'status' => 'success',

                    'material_id' => $material_id,

                    'name' => $name,

                    'unit' => $unit,

                    'rate' => $rate,

                    'material_kind' => $specs['material_kind'],

                    'stock_type' => $specs['stock_type'],

                    'color' => $specs['color'],

                    'grammage' => $specs['grammage'],

                    'dimensions' => $specs['dimensions'],

                ]);

                exit;

            }



            header("Location: list?msg=Material saved successfully");

            exit;

        } catch (Exception $e) {

            if ($pdo->inTransaction()) {

                $pdo->rollBack();

            }

            if ($action === 'quick_add') {

                echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);

                exit;

            }

            die("Error: " . $e->getMessage());

        }

    }

}

?>


