<?php
require_once __DIR__ . '/../../config/app.php';
checkAuth();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/permissions_helper.php';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'quick_add') {
        permissions_require_one_of(['manage_materials', 'manage_estimations']);
    } elseif ($action === 'create' || $action === 'update') {
        permissions_require_one_of(['manage_materials']);
    }

    if ($action === 'create' || $action === 'update' || $action === 'quick_add') {
        $name = $_POST['name'] ?? '';
        $unit = $_POST['unit'] ?? '';
        $category_id = $_POST['category_id'] ?? null;
        $rate = $_POST['rate'] ?? 0;
        $description = $_POST['description'] ?? '';
        $user_id = $_SESSION['user_id'];

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
                $material_id = $pdo->lastInsertId();
            } else {
                $material_id = $_POST['id'];
                $stmt = $pdo->prepare("UPDATE materials SET category_id = ?, name = ?, unit = ?, description = ? WHERE id = ?");
                $stmt->execute([$category_id, $name, $unit, $description, $material_id]);
            }

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
