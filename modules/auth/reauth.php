<?php
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../libs/AuditLogger.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$email = trim((string) ($_POST['email'] ?? ''));
$password = $_POST['password'] ?? '';

if ($email === '' || $password === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Please fill in all fields.']);
    exit;
}

$auditLogger = new AuditLogger($pdo);
$activeBlock = $auditLogger->getActiveIpBlock();

if ($activeBlock) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access from this IP address is temporarily blocked.']);
    exit;
}

$stmt = $pdo->prepare("SELECT u.*, r.name as role_name, d.name as department_name
                       FROM users u
                       LEFT JOIN roles r ON u.role_id = r.id
                       LEFT JOIN departments d ON u.department_id = d.id
                       WHERE u.email = :email");
$stmt->execute(['email' => $email]);
$user = $stmt->fetch();

if (!$user || !password_verify($password, $user['password'])) {
    $failure = $auditLogger->registerFailedLogin(
        $email,
        isset($user['id']) ? (int) $user['id'] : null,
        $user ? 'invalid_password' : 'unknown_email',
        ['source' => 'modules/auth/reauth']
    );

    if (!empty($failure['blocked'])) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Too many failed login attempts. This IP address has been blocked.']);
        exit;
    }

    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Invalid email or password.']);
    exit;
}

session_regenerate_id(true);
$_SESSION['user_id'] = $user['id'];
$_SESSION['user_name'] = $user['name'];
$_SESSION['role'] = $user['role_name'];
$_SESSION['department'] = $user['department_name'];
$_SESSION['department_id'] = isset($user['department_id']) ? (int) $user['department_id'] : null;
if (isset($_SESSION['department_id']) && $_SESSION['department_id'] < 1) {
    $_SESSION['department_id'] = null;
}
$_SESSION['is_section_head'] = !empty($user['is_section_head'] ?? false) ? 1 : 0;
$_SESSION['user_photo'] = $user['photo'] ?? null;

try {
    $permStmt = $pdo->prepare(
        "SELECT p.slug FROM permissions p
         INNER JOIN role_permissions rp ON rp.permission_id = p.id
         WHERE rp.role_id = :role_id"
    );
    $permStmt->execute(['role_id' => $user['role_id']]);
    $_SESSION['permissions'] = $permStmt->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $exception) {
    $_SESSION['permissions'] = [];
}

$auditLogger->registerSuccessfulLogin($user, $email, [
    'source' => 'modules/auth/reauth',
]);

echo json_encode([
    'success' => true,
    'message' => 'Signed in successfully.',
    'user_id' => (int) $user['id'],
]);
