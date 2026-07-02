<?php
require_once __DIR__ . '/../../../config/app.php';
checkAuth();
require_once __DIR__ . '/../../../config/database.php';

if ($_SESSION['role'] != 'System Admin' && $_SESSION['role'] != 'Costing' && $_SESSION['role'] != 'Procurement') {
    die("Access Denied.");
}

$action = $_REQUEST['action'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');

    if (empty($name)) {
        die("Name is required.");
    }

    if ($action === 'create') {
        $stmt = $pdo->prepare("INSERT INTO service_categories (name, description) VALUES (?, ?)");
        $stmt->execute([$name, $description]);
    } elseif ($action === 'update') {
        $id = $_POST['id'];
        $stmt = $pdo->prepare("UPDATE service_categories SET name = ?, description = ? WHERE id = ?");
        $stmt->execute([$name, $description, $id]);
    }
} elseif ($action === 'delete') {
    $id = $_GET['id'];
    $pdo->prepare("UPDATE services SET category_id = NULL WHERE category_id = ?")->execute([$id]);
    $stmt = $pdo->prepare("DELETE FROM service_categories WHERE id = ?");
    $stmt->execute([$id]);
}

header("Location: index?msg=Category updated");
exit;
?>