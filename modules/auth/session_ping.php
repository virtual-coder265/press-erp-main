<?php
require_once __DIR__ . '/../../config/app.php';
checkAuthApi();

header('Content-Type: application/json');
echo json_encode([
    'success' => true,
    'user_id' => (int) $_SESSION['user_id'],
]);
