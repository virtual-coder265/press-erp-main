<?php
require_once dirname(dirname(__DIR__)) . '/config/app.php';
checkAuth();
require_once ROOT_PATH . 'config/database.php';

$user_id = intval($_SESSION['user_id']);
$success_message = '';
$error_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $current_password = $_POST['current_password'] ?? '';
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    try {
        $stmt = $pdo->prepare("SELECT password FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!password_verify($current_password, $user['password'])) {
            $error_message = "Current password is incorrect.";
        } elseif (strlen($new_password) < 8) {
            $error_message = "New password must be at least 8 characters long.";
        } elseif ($new_password !== $confirm_password) {
            $error_message = "New passwords do not match.";
        } else {
            $hashed_password = password_hash($new_password, PASSWORD_BCRYPT);
            $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
            $stmt->execute([$hashed_password, $user_id]);
            $success_message = "Password changed successfully!";

            // Notify user of password change
            require_once ROOT_PATH . 'libs/NotificationManager.php';
            $notifManager = new NotificationManager($pdo);
            $notifManager->notify(
                $user_id,
                'security',
                'Password Changed',
                'Your account password was recently changed. If this wasn\'t you, please contact support immediately.',
                'modules/user-account/security'
            );
        }
    } catch (Exception $e) {
        $error_message = "Error updating password. Please try again.";
    }
}

include ROOT_PATH . 'includes/header.php';
?>

<div class="max-w-4xl mx-auto">
    <div class="mb-8">
        <h1 class="text-3xl font-extrabold text-gray-900 tracking-tight">Login & Security</h1>
        <p class="text-gray-500 mt-1">Manage your password and account security settings.</p>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-3 gap-8">
        <!-- Sidebar Navigation -->
        <div class="space-y-2">
            <a href="profile" class="flex items-center space-x-3 px-4 py-3 text-gray-600 hover:bg-white hover:shadow-sm rounded-xl transition-all">
                <i class="material-icons">person</i>
                <span class="font-medium">Public Profile</span>
            </a>
            <a href="security" class="flex items-center space-x-3 px-4 py-3 bg-blue-600 text-white rounded-xl shadow-md transition-all">
                <i class="material-icons">security</i>
                <span class="font-medium">Security</span>
            </a>
            <a href="notifications" class="flex items-center space-x-3 px-4 py-3 text-gray-600 hover:bg-white hover:shadow-sm rounded-xl transition-all">
                <i class="material-icons">notifications</i>
                <span class="font-medium">Notifications</span>
            </a>
            <a href="tasks" class="flex items-center space-x-3 px-4 py-3 text-gray-600 hover:bg-white hover:shadow-sm rounded-xl transition-all">
                <i class="material-icons">assignment</i>
                <span class="font-medium">My Tasks</span>
            </a>
            <a href="../reminders/index" class="flex items-center space-x-3 px-4 py-3 text-gray-600 hover:bg-white hover:shadow-sm rounded-xl transition-all">
                <i class="material-icons">alarm</i>
                <span class="font-medium">Reminders</span>
            </a>
        </div>

        <!-- Main Form -->
        <div class="md:col-span-2 space-y-6">
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-8">
                <h2 class="text-xl font-bold text-gray-800 mb-6">Change Password</h2>
                
                <?php if ($success_message): ?>
                    <div class="mb-6 p-4 bg-green-50 border-l-4 border-green-500 text-green-700 flex items-center">
                        <i class="material-icons mr-2">check_circle</i>
                        <span class="text-sm font-medium"><?php echo htmlspecialchars($success_message); ?></span>
                    </div>
                <?php endif; ?>

                <?php if ($error_message): ?>
                    <div class="mb-6 p-4 bg-red-50 border-l-4 border-red-500 text-red-700 flex items-center">
                        <i class="material-icons mr-2">error</i>
                        <span class="text-sm font-medium"><?php echo htmlspecialchars($error_message); ?></span>
                    </div>
                <?php endif; ?>

                <form method="POST" action="security" class="space-y-6">
                    <div>
                        <label class="block text-sm font-bold text-gray-700 mb-2 uppercase tracking-wide">Current Password</label>
                        <div class="relative">
                            <span class="absolute inset-y-0 left-0 pl-3 flex items-center text-gray-400">
                                <i class="material-icons text-sm">lock_open</i>
                            </span>
                            <input type="password" name="current_password" required 
                                   class="w-full pl-10 pr-4 py-3 bg-gray-50 border border-gray-200 rounded-xl focus:ring-2 focus:ring-blue-500 focus:bg-white transition-all outline-none">
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <label class="block text-sm font-bold text-gray-700 mb-2 uppercase tracking-wide">New Password</label>
                            <div class="relative">
                                <span class="absolute inset-y-0 left-0 pl-3 flex items-center text-gray-400">
                                    <i class="material-icons text-sm">vpn_key</i>
                                </span>
                                <input type="password" name="new_password" required minlength="8"
                                       class="w-full pl-10 pr-4 py-3 bg-gray-50 border border-gray-200 rounded-xl focus:ring-2 focus:ring-blue-500 focus:bg-white transition-all outline-none">
                            </div>
                            <p class="text-xs text-gray-400 mt-2 italic">Minimum 8 characters</p>
                        </div>

                        <div>
                            <label class="block text-sm font-bold text-gray-700 mb-2 uppercase tracking-wide">Confirm New Password</label>
                            <div class="relative">
                                <span class="absolute inset-y-0 left-0 pl-3 flex items-center text-gray-400">
                                    <i class="material-icons text-sm">verified</i>
                                </span>
                                <input type="password" name="confirm_password" required 
                                       class="w-full pl-10 pr-4 py-3 bg-gray-50 border border-gray-200 rounded-xl focus:ring-2 focus:ring-blue-500 focus:bg-white transition-all outline-none">
                            </div>
                        </div>
                    </div>

                    <div class="pt-4 flex items-center justify-end">
                        <button type="submit" class="bg-blue-600 text-white font-bold px-10 py-3 rounded-xl hover:bg-blue-700 transition shadow-lg shadow-blue-200">
                            Update Password
                        </button>
                    </div>
                </form>
            </div>

            <div class="bg-blue-50 rounded-2xl p-6 border border-blue-100">
                <div class="flex items-start">
                    <i class="material-icons text-blue-500 mr-4">info</i>
                    <div>
                        <h3 class="font-bold text-blue-800">Security Recommendation</h3>
                        <p class="text-sm text-blue-700 mt-1">Use a combination of uppercase, lowercase letters, numbers, and special characters to create a strong password that is hard to guess.</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include ROOT_PATH . 'includes/footer.php'; ?>
