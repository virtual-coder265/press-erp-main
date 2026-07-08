<?php
require_once __DIR__ . '/../../config/app.php';
checkAuth();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/work_order_helper.php';

$departmentSlug = trim((string) ($_GET['department'] ?? ''));
$tab = trim((string) ($_GET['tab'] ?? 'incoming'));
$query = 'workspace?department=' . urlencode($departmentSlug !== '' ? $departmentSlug : 'origination');
if ($tab !== '') {
    $query .= '&tab=' . urlencode($tab);
}
redirect('modules/work_orders/' . $query);
