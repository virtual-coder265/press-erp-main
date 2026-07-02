<?php
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/branding_helper.php';

$error = $_SESSION['error_message'] ?? '';
$success = $_SESSION['success_message'] ?? '';
unset($_SESSION['error_message'], $_SESSION['success_message']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password - Gov Press ERP</title>
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
            <p class="text-gray-500 mt-2">Reset your password</p>
        </div>
        
        <?php if($error): ?>
            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative mb-4" role="alert">
                <strong class="font-bold">Error!</strong>
                <span class="block sm:inline"><?php echo htmlspecialchars($error); ?></span>
            </div>
        <?php endif; ?>

        <?php if($success): ?>
            <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded relative mb-4" role="alert">
                <strong class="font-bold">Success!</strong>
                <span class="block sm:inline"><?php echo htmlspecialchars($success); ?></span>
            </div>
        <?php endif; ?>

        <form method="POST" action="send_reset_link">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token('forgot_password')); ?>">
            <div class="mb-4">
                <label class="block text-gray-700 text-sm font-bold mb-2" for="email">
                    Email Address
                </label>
                <input class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:shadow-outline" id="email" name="email" type="email" placeholder="Enter your email address" required>
            </div>
            
            <div class="flex items-center justify-between">
                <button class="bg-green-600 hover:bg-green-700 text-white font-bold py-2 px-4 rounded focus:outline-none focus:shadow-outline w-full transition duration-300" type="submit">
                    Send Reset Link
                </button>
            </div>
            <div class="text-center mt-4">
                 <a class="text-sm text-gray-500 hover:text-green-600" href="login">Back to Login</a>
            </div>
        </form>
    </div>
</body>
</html>
