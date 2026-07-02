<?php
require_once __DIR__ . '/../../config/app.php';
checkAuth();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/settings_helper.php';
require_once __DIR__ . '/../../config/mail.php';
require_once __DIR__ . '/../../libs/MailManager.php';

if (($_SESSION['role'] ?? '') !== 'System Admin' && !hasPermission('manage_settings')) {
    die('Access Denied.');
}

$success = '';
$error = '';
$testResult = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'save_settings';

    if ($action === 'save_settings') {
        try {
            save_application_settings([
                'mail_driver' => $_POST['mail_driver'] ?? 'smtp',
                'mail_host' => $_POST['mail_host'] ?? '',
                'mail_port' => $_POST['mail_port'] ?? 465,
                'mail_username' => $_POST['mail_username'] ?? '',
                'mail_password' => $_POST['mail_password'] ?? '',
                'mail_encryption' => $_POST['mail_encryption'] ?? '',
                'mail_from_address' => $_POST['mail_from_address'] ?? '',
                'mail_from_name' => $_POST['mail_from_name'] ?? '',
                'mail_queue_enabled' => isset($_POST['mail_queue_enabled']),
                'mail_log_enabled' => isset($_POST['mail_log_enabled']),
                'mail_queue_batch_size' => $_POST['mail_queue_batch_size'] ?? 50,
            ]);

            $success = 'Mail settings saved successfully.';
        } catch (Exception $e) {
            $error = 'Failed to save settings: ' . $e->getMessage();
        }
    }

    if ($action === 'test_connection') {
        $mailSettings = getMailSettings();

        if (!empty($_POST['mail_password'])) {
            $mailSettings['password'] = $_POST['mail_password'];
        }

        $mailManager = new MailManager($pdo, $mailSettings);
        $testResult = $mailManager->testConnection();
    }
}

$currentSettings = get_settings_by_group('mail');
$mailPasswordStorage = get_setting_storage_status('mail_password');

include '../../includes/header.php';
?>

<div class="flex-1 overflow-auto">
    <div class="p-6 max-w-5xl mx-auto">
        <div class="flex flex-col gap-2 mb-6">
            <h1 class="text-3xl font-bold text-gray-800">Mail Configuration</h1>
            <p class="text-sm text-gray-500">SMTP credentials are synchronised to <code>.env</code>, while queue and operational flags stay available to the application through the unified settings layer.</p>
        </div>

        <?php if ($success): ?>
            <div class="bg-green-100 border border-green-300 text-green-800 px-4 py-3 rounded-lg mb-4">
                <?php echo htmlspecialchars($success); ?>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="bg-red-100 border border-red-300 text-red-800 px-4 py-3 rounded-lg mb-4">
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($mailPasswordStorage['legacy_db_fallback'])): ?>
            <div class="bg-amber-100 border border-amber-300 text-amber-900 px-4 py-3 rounded-lg mb-4 text-sm">
                The current SMTP password is being served from a legacy database fallback because <code>MAIL_PASSWORD</code> was not present in <code>.env</code>. The system will now backfill that secret into <code>.env</code> automatically, and saving the password here will refresh the env copy explicitly.
            </div>
        <?php elseif (empty($mailPasswordStorage['env_present']) && empty($mailPasswordStorage['db_present'])): ?>
            <div class="bg-amber-100 border border-amber-300 text-amber-900 px-4 py-3 rounded-lg mb-4 text-sm">
                No stored SMTP password was detected. Add the mailbox password below and save before testing the SMTP connection.
            </div>
        <?php endif; ?>

        <?php if (is_array($testResult)): ?>
            <div class="<?php echo !empty($testResult['success']) ? 'bg-green-100 border-green-300 text-green-800' : 'bg-red-100 border-red-300 text-red-800'; ?> border px-4 py-3 rounded-lg mb-4">
                <?php echo htmlspecialchars((string) ($testResult['message'] ?? 'Mail connection test completed.')); ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="mail" class="space-y-6">
            <input type="hidden" name="action" value="save_settings">

            <div class="bg-white rounded-xl shadow-md p-6">
                <h2 class="text-lg font-semibold text-gray-800 border-b pb-2 mb-4">SMTP Credentials</h2>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Mail Driver</label>
                        <select name="mail_driver" class="mt-1 block w-full border border-gray-300 rounded-lg px-3 py-2">
                            <option value="smtp" <?php echo (($currentSettings['mail_driver'] ?? 'smtp') === 'smtp') ? 'selected' : ''; ?>>SMTP</option>
                            <option value="php" <?php echo (($currentSettings['mail_driver'] ?? 'smtp') === 'php') ? 'selected' : ''; ?>>PHP mail()</option>
                        </select>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700">SMTP Host</label>
                        <input type="text" name="mail_host" value="<?php echo htmlspecialchars((string) ($currentSettings['mail_host'] ?? '')); ?>" class="mt-1 block w-full border border-gray-300 rounded-lg px-3 py-2" placeholder="smtp.example.com">
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700">SMTP Port</label>
                        <input type="number" name="mail_port" value="<?php echo htmlspecialchars((string) ($currentSettings['mail_port'] ?? 465)); ?>" class="mt-1 block w-full border border-gray-300 rounded-lg px-3 py-2">
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700">Encryption</label>
                        <select name="mail_encryption" class="mt-1 block w-full border border-gray-300 rounded-lg px-3 py-2">
                            <option value="ssl" <?php echo (($currentSettings['mail_encryption'] ?? 'ssl') === 'ssl') ? 'selected' : ''; ?>>SSL</option>
                            <option value="tls" <?php echo (($currentSettings['mail_encryption'] ?? 'ssl') === 'tls') ? 'selected' : ''; ?>>TLS</option>
                            <option value="" <?php echo (($currentSettings['mail_encryption'] ?? 'ssl') === '') ? 'selected' : ''; ?>>None</option>
                        </select>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700">Username / Mailbox</label>
                        <input type="email" name="mail_username" value="<?php echo htmlspecialchars((string) ($currentSettings['mail_username'] ?? '')); ?>" class="mt-1 block w-full border border-gray-300 rounded-lg px-3 py-2">
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700">Password</label>
                        <input type="password" name="mail_password" value="" class="mt-1 block w-full border border-gray-300 rounded-lg px-3 py-2" placeholder="<?php echo setting_has_stored_value('mail_password') ? 'Stored in .env' : 'Enter password'; ?>">
                        <p class="mt-1 text-xs text-gray-500">
                            Leave empty to keep the current secret.
                            Current source:
                            <?php echo htmlspecialchars($mailPasswordStorage['source'] === 'env' ? '.env' : ($mailPasswordStorage['source'] === 'database' ? 'legacy database fallback' : 'not stored')); ?>.
                        </p>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700">From Address</label>
                        <input type="email" name="mail_from_address" value="<?php echo htmlspecialchars((string) ($currentSettings['mail_from_address'] ?? '')); ?>" class="mt-1 block w-full border border-gray-300 rounded-lg px-3 py-2">
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700">From Name</label>
                        <input type="text" name="mail_from_name" value="<?php echo htmlspecialchars((string) ($currentSettings['mail_from_name'] ?? APP_NAME)); ?>" class="mt-1 block w-full border border-gray-300 rounded-lg px-3 py-2">
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-xl shadow-md p-6">
                <h2 class="text-lg font-semibold text-gray-800 border-b pb-2 mb-4">Delivery Controls</h2>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <label class="flex items-start gap-3 p-4 border border-gray-200 rounded-xl">
                        <input type="checkbox" name="mail_queue_enabled" class="mt-1 h-4 w-4 rounded border-gray-300 text-blue-600" <?php echo setting_truthy('mail_queue_enabled', true) ? 'checked' : ''; ?>>
                        <span>
                            <span class="block text-sm font-medium text-gray-800">Enable mail queue</span>
                            <span class="block text-xs text-gray-500">Send emails asynchronously where supported.</span>
                        </span>
                    </label>

                    <label class="flex items-start gap-3 p-4 border border-gray-200 rounded-xl">
                        <input type="checkbox" name="mail_log_enabled" class="mt-1 h-4 w-4 rounded border-gray-300 text-blue-600" <?php echo setting_truthy('mail_log_enabled', true) ? 'checked' : ''; ?>>
                        <span>
                            <span class="block text-sm font-medium text-gray-800">Enable mail logging</span>
                            <span class="block text-xs text-gray-500">Track successful and failed mail deliveries.</span>
                        </span>
                    </label>

                    <div class="p-4 border border-gray-200 rounded-xl">
                        <label class="block text-sm font-medium text-gray-700">Queue Batch Size</label>
                        <input type="number" min="1" max="500" name="mail_queue_batch_size" value="<?php echo htmlspecialchars((string) ($currentSettings['mail_queue_batch_size'] ?? 50)); ?>" class="mt-2 block w-full border border-gray-300 rounded-lg px-3 py-2">
                    </div>
                </div>
            </div>

            <div class="flex flex-wrap gap-3">
                <button type="submit" class="bg-pink-600 text-white px-5 py-2.5 rounded-lg hover:bg-pink-700">
                    Save Mail Settings
                </button>
            </div>
        </form>

        <form method="POST" action="mail" class="bg-white rounded-xl shadow-md p-6 mt-6">
            <input type="hidden" name="action" value="test_connection">
            <h2 class="text-lg font-semibold text-gray-800 border-b pb-2 mb-4">Test Connection</h2>
            <p class="text-sm text-gray-500 mb-4">Run a connectivity check against the currently saved mail configuration.</p>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700">Optional Password Override</label>
                    <input type="password" name="mail_password" value="" class="mt-1 block w-full border border-gray-300 rounded-lg px-3 py-2" placeholder="Use current saved secret if left blank">
                </div>
            </div>

            <div class="mt-4">
                <button type="submit" class="bg-blue-600 text-white px-5 py-2.5 rounded-lg hover:bg-blue-700">
                    Test SMTP Connection
                </button>
            </div>
        </form>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>
