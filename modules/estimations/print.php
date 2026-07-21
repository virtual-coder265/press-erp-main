<?php
require_once __DIR__ . '/../../config/app.php';
checkAuth();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/permissions_helper.php';
permissions_require_one_of(['view_estimations']);
require_once __DIR__ . '/../../includes/estimation_detail_dedup_helper.php';

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    redirect('modules/estimations/list');
}

// Fetch Estimation
$stmt = $pdo->prepare("SELECT * FROM estimations WHERE id = :id");
$stmt->execute(['id' => $id]);
$est = $stmt->fetch();

if (!$est) {
    die('Estimation not found');
}

estimation_deduplicate_detail_rows($pdo, $id);

// Fetch Items
$stmtItems = $pdo->prepare("SELECT * FROM estimation_items WHERE estimation_id = :id");
$stmtItems->execute(['id' => $id]);
$items = $stmtItems->fetchAll();

// Include the print template
require_once __DIR__ . '/../../templates/estimation_print_template.php';
?>