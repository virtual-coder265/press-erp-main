<?php
require_once __DIR__ . '/../../config/app.php';
checkAuth();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/settings_helper.php';

if (($_SESSION['role'] ?? '') !== 'System Admin' && !hasPermission('manage_settings')) {
    die('Access Denied.');
}

$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        save_application_settings([
            'notifications_enabled' => isset($_POST['notifications_enabled']),
            'notification_in_app_enabled' => isset($_POST['notification_in_app_enabled']),
            'notification_push_enabled' => isset($_POST['notification_push_enabled']),
            'notification_email_enabled' => isset($_POST['notification_email_enabled']),
            'notification_sms_enabled' => isset($_POST['notification_sms_enabled']),
            'notification_whatsapp_enabled' => isset($_POST['notification_whatsapp_enabled']),
            'notification_queue_enabled' => isset($_POST['notification_queue_enabled']),
            'notification_digest_enabled' => isset($_POST['notification_digest_enabled']),
            'notification_batch_size' => $_POST['notification_batch_size'] ?? 25,
            'notification_retry_limit' => $_POST['notification_retry_limit'] ?? 3,
            'notification_admin_email' => $_POST['notification_admin_email'] ?? '',
        ]);

        $success = 'Notification settings updated successfully.';
    } catch (Exception $e) {
        $error = 'Failed to update settings: ' . $e->getMessage();
    }
}

$notificationSettings = get_settings_by_group('notification');

include '../../includes/header.php';
?>

<div class="flex-1 overflow-auto">
    <div class="p-6 max-w-5xl mx-auto">
        <div class="flex flex-col gap-2 mb-6">
            <h1 class="text-3xl font-bold text-gray-800">Notification Configuration</h1>
            <p class="text-sm text-gray-500">Define the system-wide rules that control in-app alerts, email, SMS, WhatsApp delivery, queue processing, and notification retries.</p>
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

        <form method="POST" action="notifications" class="space-y-6">
            <div class="bg-white rounded-xl shadow-md p-6">
                <h2 class="text-lg font-semibold text-gray-800 border-b pb-2 mb-4">Channel Controls</h2>

                <?php
                $channels = [
                    'notifications_enabled' => ['title' => 'Enable notifications', 'desc' => 'Master switch for the full notification subsystem.'],
                    'notification_in_app_enabled' => ['title' => 'In-app alerts', 'desc' => 'Show notifications inside the ERP interface.'],
                    'notification_push_enabled' => ['title' => 'Browser push alerts', 'desc' => 'Deliver branded browser notifications through service workers even when tabs are inactive.'],
                    'notification_email_enabled' => ['title' => 'Email notifications', 'desc' => 'Allow email-based alerts when user preferences permit it.'],
                    'notification_sms_enabled' => ['title' => 'SMS notifications', 'desc' => 'Allow SMS delivery through the configured provider.'],
                    'notification_whatsapp_enabled' => ['title' => 'WhatsApp notifications', 'desc' => 'Allow WhatsApp delivery through the configured provider.'],
                    'notification_queue_enabled' => ['title' => 'Queue external notifications', 'desc' => 'Use the queue worker for email, SMS, and WhatsApp messages.'],
                    'notification_digest_enabled' => ['title' => 'Digest-ready mode', 'desc' => 'Store the global preference for summary-style notifications.'],
                ];
                ?>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <?php foreach ($channels as $key => $meta): ?>
                        <?php $channelDefault = get_setting_definition($key)['default'] ?? false; ?>
                        <label class="flex items-start gap-3 p-4 border border-gray-200 rounded-xl">
                            <input type="checkbox" name="<?php echo htmlspecialchars($key); ?>" class="mt-1 h-4 w-4 rounded border-gray-300 text-blue-600" <?php echo setting_truthy($key, $channelDefault) ? 'checked' : ''; ?>>
                            <span>
                                <span class="block text-sm font-medium text-gray-800"><?php echo htmlspecialchars($meta['title']); ?></span>
                                <span class="block text-xs text-gray-500"><?php echo htmlspecialchars($meta['desc']); ?></span>
                            </span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="bg-white rounded-xl shadow-md p-6">
                <h2 class="text-lg font-semibold text-gray-800 border-b pb-2 mb-4">Queue and Escalation</h2>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Batch Size</label>
                        <input type="number" min="1" max="500" name="notification_batch_size" value="<?php echo htmlspecialchars((string) ($notificationSettings['notification_batch_size'] ?? 25)); ?>" class="mt-1 block w-full border border-gray-300 rounded-lg px-3 py-2">
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700">Retry Limit</label>
                        <input type="number" min="1" max="20" name="notification_retry_limit" value="<?php echo htmlspecialchars((string) ($notificationSettings['notification_retry_limit'] ?? 3)); ?>" class="mt-1 block w-full border border-gray-300 rounded-lg px-3 py-2">
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700">Admin Alert Email</label>
                        <input type="email" name="notification_admin_email" value="<?php echo htmlspecialchars((string) ($notificationSettings['notification_admin_email'] ?? '')); ?>" class="mt-1 block w-full border border-gray-300 rounded-lg px-3 py-2" placeholder="ops@example.com">
                    </div>
                </div>
            </div>

            <div class="flex justify-end">
                <button type="submit" class="bg-blue-600 text-white px-6 py-2.5 rounded-lg hover:bg-blue-700">
                    Save Notification Settings
                </button>
            </div>
        </form>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>
