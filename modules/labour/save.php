<?php
require_once __DIR__ . '/../../config/app.php';
checkAuth();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/permissions_helper.php';
require_once __DIR__ . '/../../libs/ProductionLabourMigrator.php';

ProductionLabourMigrator::ensure($pdo);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

$action = $_POST['action'] ?? '';

if ($action === 'quick_add') {
    permissions_require_one_of(['manage_estimations']);
} else {
    permissions_require_one_of(['manage_estimations']);
}

header('Content-Type: application/json; charset=utf-8');

if ($action !== 'quick_add') {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Unsupported action.']);
    exit;
}

$section = strtolower(trim((string) ($_POST['section'] ?? '')));
$name = trim((string) ($_POST['name'] ?? ''));
$unit = trim((string) ($_POST['unit'] ?? 'hrs'));
$measureType = trim((string) ($_POST['measure_type'] ?? ''));
$defaultIph = $_POST['default_iph'] ?? null;
$rate = (float) ($_POST['rate'] ?? 0);
$makeReadyRate = isset($_POST['make_ready_rate']) && $_POST['make_ready_rate'] !== ''
    ? (float) $_POST['make_ready_rate']
    : null;
$runningRate = isset($_POST['running_rate']) && $_POST['running_rate'] !== ''
    ? (float) $_POST['running_rate']
    : null;
$userId = (int) $_SESSION['user_id'];

$allowedSections = ['prepress', 'press', 'finishing'];
if (!in_array($section, $allowedSections, true)) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid labour section.']);
    exit;
}

if ($name === '') {
    echo json_encode(['status' => 'error', 'message' => 'Task name is required.']);
    exit;
}

if ($section === 'prepress') {
    $unit = 'hrs';
    $measureType = null;
    $defaultIph = null;
} elseif ($section === 'finishing' && $measureType === '') {
    $measureType = 'items';
} elseif ($section === 'press') {
    $unit = 'hrs';
    $measureType = null;
    $defaultIph = null;
    if ($rate <= 0 && $runningRate !== null) {
        $rate = $runningRate;
    } elseif ($rate <= 0 && $makeReadyRate !== null) {
        $rate = $makeReadyRate;
    }
}

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare("
        INSERT INTO production_labour_tasks (section, name, unit, measure_type, default_iph)
        VALUES (?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            unit = VALUES(unit),
            measure_type = VALUES(measure_type),
            default_iph = VALUES(default_iph)
    ");
    $stmt->execute([
        $section,
        $name,
        $unit !== '' ? $unit : 'hrs',
        $measureType !== '' ? $measureType : null,
        $defaultIph !== null && $defaultIph !== '' ? (int) $defaultIph : null,
    ]);

    $taskId = (int) $pdo->lastInsertId();
    if ($taskId === 0) {
        $lookup = $pdo->prepare('SELECT id FROM production_labour_tasks WHERE section = ? AND name = ? LIMIT 1');
        $lookup->execute([$section, $name]);
        $taskId = (int) $lookup->fetchColumn();
    }

    $rateStmt = $pdo->prepare("
        INSERT INTO production_labour_rates
            (task_id, rate, make_ready_rate, running_rate, effective_date, created_by)
        VALUES (?, ?, ?, ?, CURDATE(), ?)
    ");
    $rateStmt->execute([
        $taskId,
        $rate,
        $makeReadyRate,
        $runningRate,
        $userId,
    ]);

    $pdo->commit();

    echo json_encode([
        'status' => 'success',
        'task_id' => $taskId,
        'section' => $section,
        'name' => $name,
        'unit' => $unit !== '' ? $unit : 'hrs',
        'measure_type' => $measureType !== '' ? $measureType : null,
        'default_iph' => $defaultIph !== null && $defaultIph !== '' ? (int) $defaultIph : null,
        'rate' => $rate,
        'make_ready_rate' => $makeReadyRate,
        'running_rate' => $runningRate,
    ]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
