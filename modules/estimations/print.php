<?php
require_once __DIR__ . '/../../config/app.php';
checkAuth();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/permissions_helper.php';
permissions_require_one_of(['view_estimations']);
require_once __DIR__ . '/../../includes/estimation_detail_dedup_helper.php';
require_once __DIR__ . '/../../includes/estimation_view_data_helper.php';

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

$detailBundle = estimation_load_detail_bundle($pdo, $id);
extract($detailBundle, EXTR_SKIP);

// Include the print template
require_once __DIR__ . '/../../templates/estimation_print_template.php';
?>