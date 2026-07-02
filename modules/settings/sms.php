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
            'sms_provider' => $_POST['sms_provider'] ?? 'twilio',
            'twilio_sid' => $_POST['twilio_sid'] ?? '',
            'twilio_token' => $_POST['twilio_token'] ?? '',
            'twilio_from_number' => $_POST['twilio_from_number'] ?? '',
            'twilio_whatsapp_number' => $_POST['twilio_whatsapp_number'] ?? '',
            'sms_default_country_code' => $_POST['sms_default_country_code'] ?? '+265',
            'sms_sender_name' => $_POST['sms_sender_name'] ?? 'Press ERP',
        ]);

        $success = 'SMS and WhatsApp settings saved successfully.';
    } catch (Exception $e) {
        $error = 'Failed to save settings: ' . $e->getMessage();
    }
}

$currentSettings = get_settings_by_group('sms');

include '../../includes/header.php';
?>

<div class="flex-1 overflow-auto">
    <div class="p-6 max-w-5xl mx-auto">
        <div class="flex flex-col gap-2 mb-6">
            <h1 class="text-3xl font-bold text-gray-800">SMS and WhatsApp Configuration</h1>
            <p class="text-sm text-gray-500">Twilio tokens are stored in <code>.env</code>, while operational sender metadata remains available for the notification engine and admin screens.</p>
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

        <form method="POST" action="sms" class="space-y-6">
            <div class="bg-white rounded-xl shadow-md p-6">
                <h2 class="text-lg font-semibold text-gray-800 border-b pb-2 mb-4">Provider Settings</h2>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700">SMS Provider</label>
                        <select name="sms_provider" class="mt-1 block w-full border border-gray-300 rounded-lg px-3 py-2">
                            <option value="twilio" <?php echo (($currentSettings['sms_provider'] ?? 'twilio') === 'twilio') ? 'selected' : ''; ?>>Twilio</option>
                            <option value="custom" <?php echo (($currentSettings['sms_provider'] ?? 'twilio') === 'custom') ? 'selected' : ''; ?>>Custom / future integration</option>
                        </select>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700">Default Country Code</label>
                        <input type="text" name="sms_default_country_code" value="<?php echo htmlspecialchars((string) ($currentSettings['sms_default_country_code'] ?? '+265')); ?>" class="mt-1 block w-full border border-gray-300 rounded-lg px-3 py-2">
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700">Sender Name</label>
                        <input type="text" name="sms_sender_name" value="<?php echo htmlspecialchars((string) ($currentSettings['sms_sender_name'] ?? 'Press ERP')); ?>" class="mt-1 block w-full border border-gray-300 rounded-lg px-3 py-2">
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-xl shadow-md p-6">
                <h2 class="text-lg font-semibold text-gray-800 border-b pb-2 mb-4">Twilio Credentials</h2>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Account SID</label>
                        <input type="text" name="twilio_sid" value="<?php echo htmlspecialchars((string) ($currentSettings['twilio_sid'] ?? '')); ?>" class="mt-1 block w-full border border-gray-300 rounded-lg px-3 py-2" placeholder="ACxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx">
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700">Auth Token</label>
                        <input type="password" name="twilio_token" value="" class="mt-1 block w-full border border-gray-300 rounded-lg px-3 py-2" placeholder="<?php echo setting_has_stored_value('twilio_token') ? 'Stored in .env' : 'Enter token'; ?>">
                        <p class="mt-1 text-xs text-gray-500">Leave empty to keep the current token.</p>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700">SMS From Number</label>
                        <input type="text" name="twilio_from_number" value="<?php echo htmlspecialchars((string) ($currentSettings['twilio_from_number'] ?? '')); ?>" class="mt-1 block w-full border border-gray-300 rounded-lg px-3 py-2" placeholder="+265XXXXXXXXX">
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700">WhatsApp Sender</label>
                        <input type="text" name="twilio_whatsapp_number" value="<?php echo htmlspecialchars((string) ($currentSettings['twilio_whatsapp_number'] ?? '')); ?>" class="mt-1 block w-full border border-gray-300 rounded-lg px-3 py-2" placeholder="whatsapp:+14155238886">
                    </div>
                </div>
            </div>

            <div class="bg-blue-50 border border-blue-100 rounded-xl p-4 text-sm text-blue-800">
                <p class="font-semibold mb-1">Operational notes</p>
                <ul class="list-disc pl-5 space-y-1">
                    <li>Use E.164 numbers such as <code>+265...</code> for SMS delivery.</li>
                    <li>WhatsApp senders must be provisioned in Twilio or configured for the sandbox.</li>
                    <li>Global channel enablement is controlled from Notification Configuration.</li>
                </ul>
            </div>

            <div class="flex justify-end">
                <button type="submit" class="bg-green-600 text-white px-6 py-2.5 rounded-lg hover:bg-green-700">
                    Save SMS Settings
                </button>
            </div>
        </form>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>
