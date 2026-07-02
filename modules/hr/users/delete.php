<?php
require_once __DIR__ . '/../../../config/app.php';
checkAuth();
require_once __DIR__ . '/../../../config/database.php';
if (!hasPermission('manage_users')) {
    die("Access Denied.");
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('modules/hr/users/list?error=invalid_request');
}

if (!verify_csrf_token($_POST['csrf_token'] ?? null, 'delete_user')) {
    $_SESSION['hr_users_error'] = 'The delete request could not be verified. Please try again.';
    redirect('modules/hr/users/list?error=invalid_csrf');
}

$id = $_POST['id'] ?? 0;

if (!$id) {
    redirect('modules/hr/users/list?error=not_found');
}

if ((int) $id === (int) $_SESSION['user_id']) {
    $_SESSION['hr_users_error'] = 'You cannot delete the account you are currently signed in with.';
    redirect('modules/hr/users/list?error=cannot_delete_self');
}

$stmt = $pdo->prepare("SELECT id, name FROM users WHERE id = ?");
$stmt->execute([$id]);
$user = $stmt->fetch();

if (!$user) {
    redirect('modules/hr/users/list?error=not_found');
}

try {
    $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
    $stmt->execute([$id]);
} catch (PDOException $e) {
    $_SESSION['hr_users_error'] = 'The user could not be deleted because an unexpected database rule still blocked the request.';
    redirect('modules/hr/users/list?error=user_delete_failed');
}

redirect('modules/hr/users/list?success=user_deleted');
