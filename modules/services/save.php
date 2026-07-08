<?php
require_once __DIR__ . '/../../config/app.php';
checkAuth();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/permissions_helper.php';
permissions_require_one_of(['manage_services']);


$action = $_REQUEST['action'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $category_id = $_POST['category_id'] ?? null;
    $price = $_POST['price'] ?? 0;
    $description = trim($_POST['description'] ?? '');

    if (empty($name) || empty($category_id) || empty($price)) {
        die("Name, Category, and Price are required.");
    }

    if ($action === 'create') {
        $stmt = $pdo->prepare("INSERT INTO services (name, category_id, price, description) VALUES (?, ?, ?, ?)");
        $stmt->execute([$name, $category_id, $price, $description]);
    } elseif ($action === 'update') {
        $id = $_POST['id'];
        $stmt = $pdo->prepare("UPDATE services SET name = ?, category_id = ?, price = ?, description = ? WHERE id = ?");
        $stmt->execute([$name, $category_id, $price, $description, $id]);
    }
} elseif ($action === 'delete') {
    $id = $_GET['id'];
    $stmt = $pdo->prepare("DELETE FROM services WHERE id = ?");
    $stmt->execute([$id]);
}

header("Location: index?msg=Service saved");
exit;
?>