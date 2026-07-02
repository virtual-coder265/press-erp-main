<?php
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/branding_helper.php';

$error = '';
$token = $_GET['token'] ?? $_POST['token'] ?? '';
$user = null;

if (!$token) {
    $error = "Invalid request. Token missing.";
} else {
    // Check if token valid
    $stmt = $pdo->prepare("SELECT id FROM users WHERE reset_token = :token AND reset_expires_at > NOW()");
    $stmt->execute(['token' => $token]);
    $user = $stmt->fetch();

    if (!$user) {
        $error = "Invalid or expired password reset link.";
    }
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && $user) {
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    if (empty($password) || strlen($password) < 6) {
        $error = "Password must be at least 6 characters.";
    } elseif ($password !== $confirm_password) {
        $error = "Passwords do not match.";
    } else {
        $hashed = password_hash($password, PASSWORD_DEFAULT);
        $update = $pdo->prepare("UPDATE users SET password = :password, reset_token = NULL, reset_expires_at = NULL WHERE id = :id");
        $update->execute([
            'password' => $hashed,
            'id' => $user['id']
        ]);
        
        // Redirect to login
        header('Location: login?message=password_reset_success');
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password - Gov Press ERP</title>
    <link rel="icon" type="image/png" href="<?php echo htmlspecialchars(system_branding_resolved_url('favicon')); ?>">
    <?php include __DIR__ . '/../../includes/head_assets.php'; ?>
    <style>
        body { font-family: var(--app-font-sans); }
        .bg-overlay {
            background-image: url('<?php echo asset('images/emblem.png'); ?>');
            background-repeat: no-repeat;
            background-position: left;
            background-size: contain;
            opacity: 0.3;
            position: absolute;
            top: 0;
            left: -30;
            width: 100%;
            height: 100%;
            z-index: -1;
        }
    </style>
</head>
<body class="bg-gray-100 flex items-center justify-center h-screen relative overflow-hidden">
    <div class="bg-overlay pointer-events-none"></div>
    <div class="bg-white bg-opacity-10 p-8 rounded-lg shadow-lg w-full max-w-md z-10 backdrop-filter backdrop-blur-sm">
        <div class="text-center mb-8">
            <img src="<?php echo htmlspecialchars(system_branding_resolved_url('logo')); ?>" alt="Gov Press ERP" class="h-20 mx-auto mb-4">
            <h1 class="text-2xl font-bold text-gray-800">Gov Press ERP</h1>
            <p class="text-gray-500 mt-2">Set New Password</p>
        </div>
        
        <?php if($error): ?>
            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative mb-4" role="alert">
                <strong class="font-bold">Error!</strong>
                <span class="block sm:inline"><?php echo htmlspecialchars($error); ?></span>
            </div>
        <?php endif; ?>

        <?php if($user): ?>
        <form method="POST" action="reset_password">
            <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">
            <div class="mb-4">
                <label class="block text-gray-700 text-sm font-bold mb-2" for="password">
                    New Password
                </label>
                <input class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:shadow-outline" id="password" name="password" type="password" placeholder="At least 6 characters" required>
            </div>
            <div class="mb-6">
                 <label class="block text-gray-700 text-sm font-bold mb-2" for="confirm_password">
                    Confirm Password
                </label>
                <input class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:shadow-outline" id="confirm_password" name="confirm_password" type="password" placeholder="Confirm new password" required>
            </div>
            
            <div class="flex items-center justify-between">
                <button class="bg-green-600 hover:bg-green-700 text-white font-bold py-2 px-4 rounded focus:outline-none focus:shadow-outline w-full transition duration-300" type="submit">
                    Update Password
                </button>
            </div>
        </form>
        <?php else: ?>
             <div class="text-center">
                 <a href="forgot_password" class="text-green-600 hover:underline">Request a new reset link</a>
             </div>
        <?php endif; ?>
    </div>
</body>
</html>
