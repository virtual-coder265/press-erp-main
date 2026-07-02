<?php
require_once __DIR__ . '/../../../config/app.php';
checkAuth();
require_once __DIR__ . '/../../../config/database.php';

// Role Check
if ($_SESSION['role'] != 'System Admin' && $_SESSION['role'] != 'Procurement' && $_SESSION['role'] != 'Costing') {
    die("Access Denied.");
}

$action = $_REQUEST['action'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = $_POST['name'] ?? '';
    
    if (empty($name)) {
        die("Name is required.");
    }

    if ($action === 'create') {
        $stmt = $pdo->prepare("INSERT INTO material_categories (name) VALUES (?)");
        $stmt->execute([$name]);
    } elseif ($action === 'update') {
        $id = $_POST['id'];
        $stmt = $pdo->prepare("UPDATE material_categories SET name = ? WHERE id = ?");
        $stmt->execute([$name, $id]);
    }
} elseif ($action === 'delete') {
    $id = $_GET['id'];
    // Update materials that use this category to null
    $pdo->prepare("UPDATE materials SET category_id = NULL WHERE category_id = ?")->execute([$id]);
    $stmt = $pdo->prepare("DELETE FROM material_categories WHERE id = ?");
    $stmt->execute([$id]);
}

header("Location: list?msg=Category updated");
exit;
?>
