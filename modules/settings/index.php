<?php
require_once __DIR__ . '/../../config/app.php';
checkAuth();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/settings_helper.php';
require_once __DIR__ . '/../../includes/upload_helper.php';
require_once __DIR__ . '/../../includes/branding_helper.php';

if (($_SESSION['role'] ?? '') !== 'System Admin' && !hasPermission('manage_settings')) {
    die('Access Denied.');
}

$success = '';
$error = '';
$installerNotice = trim((string) ($_GET['installer_notice'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? 'save_settings') === 'unlock_reinstall') {
    if (!verify_csrf_token($_POST['_csrf'] ?? null, 'installer_unlock_action')) {
        $error = 'Failed to unlock the installer because the security token was invalid.';
    } else {
        try {
            $token = installer_unlock_reinstallation();
            redirect(installer_public_route() . '?token=' . urlencode($token));
        } catch (Exception $e) {
            $error = 'Failed to unlock reinstall mode: ' . $e->getMessage();
        }
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        system_branding_handle_post();

        save_application_settings([
            'system_app_name' => $_POST['system_app_name'] ?? APP_NAME,
            'system_tagline' => $_POST['system_tagline'] ?? '',
            'system_operation_mode' => $_POST['system_operation_mode'] ?? 'standard',
            'system_timezone' => $_POST['system_timezone'] ?? APP_TIMEZONE,
            'default_currency' => $_POST['default_currency'] ?? 'MWK',
            'default_country_code' => $_POST['default_country_code'] ?? '+265',
            'date_format' => $_POST['date_format'] ?? 'Y-m-d',
            'time_format' => $_POST['time_format'] ?? 'H:i',
            'records_per_page' => $_POST['records_per_page'] ?? 25,
            'maintenance_mode' => isset($_POST['maintenance_mode']),
            'profile_enforcement_enabled' => isset($_POST['profile_enforcement_enabled']),
            'allow_self_registration' => isset($_POST['allow_self_registration']),
            'enforce_strong_passwords' => isset($_POST['enforce_strong_passwords']),
            'enable_audit_logs' => isset($_POST['enable_audit_logs']),
            'enable_multi_branch' => isset($_POST['enable_multi_branch']),
            'enable_approval_workflows' => isset($_POST['enable_approval_workflows']),
            'enable_project_billing' => isset($_POST['enable_project_billing']),
            'enable_priority_dispatch' => isset($_POST['enable_priority_dispatch']),
            'enable_advanced_estimation_rules' => isset($_POST['enable_advanced_estimation_rules']),
            'security_login_monitoring_enabled' => isset($_POST['security_login_monitoring_enabled']),
            'security_failed_login_threshold' => $_POST['security_failed_login_threshold'] ?? 5,
            'security_failed_login_window_minutes' => $_POST['security_failed_login_window_minutes'] ?? 15,
            'security_block_duration_minutes' => $_POST['security_block_duration_minutes'] ?? 60,
            'security_notify_admin_on_block' => isset($_POST['security_notify_admin_on_block']),
            'public_api_base_url' => $_POST['public_api_base_url'] ?? '',
            'api_client_id' => $_POST['api_client_id'] ?? '',
            'api_client_secret' => $_POST['api_client_secret'] ?? '',
            'webhook_endpoint' => $_POST['webhook_endpoint'] ?? '',
            'webhook_signing_secret' => $_POST['webhook_signing_secret'] ?? '',
        ]);

        $success = 'System and operational settings updated successfully.';
    } catch (Exception $e) {
        $error = 'Failed to update settings: ' . $e->getMessage();
    }
}

$systemSettings = get_settings_by_group('system');
$securitySettings = get_security_monitoring_settings();
$apiSettings = get_settings_by_group('api');
$installerMeta = installer_metadata();
$systemLogoUrl = system_branding_resolved_url('logo');
$systemFaviconUrl = system_branding_resolved_url('favicon');
$hasCustomSystemLogo = system_branding_stored_path('logo') !== '';
$hasCustomSystemFavicon = system_branding_stored_path('favicon') !== '';

$cards = [
    [
        'label' => 'Operation mode',
        'value' => ucfirst((string) ($systemSettings['system_operation_mode'] ?? 'standard')),
        'tone' => strtolower((string) ($systemSettings['system_operation_mode'] ?? 'standard')) === 'pro' ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-700',
    ],
    [
        'label' => 'Maintenance',
        'value' => setting_truthy('maintenance_mode', false) ? 'Enabled' : 'Live',
        'tone' => setting_truthy('maintenance_mode', false) ? 'bg-amber-50 text-amber-700' : 'bg-blue-50 text-blue-700',
    ],
    [
        'label' => 'Profile enforcement',
        'value' => setting_truthy('profile_enforcement_enabled', true) ? 'Required' : 'Optional',
        'tone' => 'bg-violet-50 text-violet-700',
    ],
    [
        'label' => 'Audit logs',
        'value' => setting_truthy('enable_audit_logs', true) ? 'Active' : 'Off',
        'tone' => 'bg-rose-50 text-rose-700',
    ],
];

include '../../includes/header.php';
?>

<div class="container mx-auto px-4 py-8 max-w-6xl">
    <div class="flex flex-col gap-2 mb-6">
        <h1 class="text-2xl font-bold text-gray-800">System and Operations</h1>
        <p class="text-sm text-gray-500">Manage platform-wide behaviour, feature toggles, environment-backed credentials, and the standard or pro operating mode for the ERP.</p>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
        <?php foreach ($cards as $card): ?>
            <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4">
                <p class="text-xs uppercase tracking-wide text-gray-400"><?php echo htmlspecialchars($card['label']); ?></p>
                <span class="inline-flex mt-3 px-3 py-1 rounded-full text-sm font-semibold <?php echo htmlspecialchars($card['tone']); ?>">
                    <?php echo htmlspecialchars($card['value']); ?>
                </span>
            </div>
        <?php endforeach; ?>
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

    <?php if ($installerNotice === 'reinstall_cancelled'): ?>
        <div class="bg-blue-100 border border-blue-300 text-blue-800 px-4 py-3 rounded-lg mb-4">
            Reinstall mode was cancelled. The live system has been restored.
        </div>
    <?php endif; ?>

    <form method="POST" action="index" enctype="multipart/form-data" class="space-y-8">
        <div class="bg-white shadow-md rounded-xl p-6">
            <h2 class="text-lg font-semibold mb-4 text-gray-800 border-b pb-2">Platform Identity</h2>

            <div class="mb-6 pb-6 border-b border-gray-100">
                <h3 class="text-sm font-semibold text-gray-700 mb-1">Application branding</h3>
                <p class="text-xs text-gray-500 mb-4">Upload logos used in the sidebar, mobile header, login screen, and browser tab. Drag an image onto a zone or click to browse. Preview updates immediately; click <strong>Save System Settings</strong> to persist changes.</p>

                <div class="grid grid-cols-1 xl:grid-cols-2 gap-6">
                    <?php
                    $zoneId = 'system_logo_zone';
                    $inputName = 'system_logo';
                    $removeFlagName = 'remove_system_logo';
                    $label = 'Application logo';
                    $hint = 'Wide logo for sidebar and sign-in pages. Recommended transparent PNG, at least 200px wide.';
                    $currentUrl = $systemLogoUrl;
                    $hasCustom = $hasCustomSystemLogo;
                    $previewVariant = 'logo';
                    include __DIR__ . '/../../includes/partials/branding_upload_zone.php';

                    $zoneId = 'system_favicon_zone';
                    $inputName = 'system_favicon';
                    $removeFlagName = 'remove_system_favicon';
                    $label = 'Compact icon / favicon';
                    $hint = 'Square icon for the login badge, mobile bar, and browser tab. Recommended 64×64 or larger PNG.';
                    $currentUrl = $systemFaviconUrl;
                    $hasCustom = $hasCustomSystemFavicon;
                    $previewVariant = 'favicon';
                    include __DIR__ . '/../../includes/partials/branding_upload_zone.php';
                    ?>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label for="system_app_name" class="block text-sm font-medium text-gray-700">Application Name</label>
                    <input type="text" id="system_app_name" name="system_app_name" value="<?php echo htmlspecialchars((string) ($systemSettings['system_app_name'] ?? APP_NAME)); ?>" class="mt-1 block w-full border border-gray-300 rounded-lg px-3 py-2">
                </div>

                <div>
                    <label for="system_tagline" class="block text-sm font-medium text-gray-700">Tagline</label>
                    <input type="text" id="system_tagline" name="system_tagline" value="<?php echo htmlspecialchars((string) ($systemSettings['system_tagline'] ?? 'Government Press Operations Suite')); ?>" class="mt-1 block w-full border border-gray-300 rounded-lg px-3 py-2">
                </div>

                <div>
                    <label for="system_operation_mode" class="block text-sm font-medium text-gray-700">Operation Mode</label>
                    <select id="system_operation_mode" name="system_operation_mode" class="mt-1 block w-full border border-gray-300 rounded-lg px-3 py-2">
                        <option value="standard" <?php echo (($systemSettings['system_operation_mode'] ?? 'standard') === 'standard') ? 'selected' : ''; ?>>Standard</option>
                        <option value="pro" <?php echo (($systemSettings['system_operation_mode'] ?? 'standard') === 'pro') ? 'selected' : ''; ?>>Pro</option>
                    </select>
                </div>

                <div>
                    <label for="system_timezone" class="block text-sm font-medium text-gray-700">Timezone</label>
                    <input type="text" id="system_timezone" name="system_timezone" value="<?php echo htmlspecialchars((string) ($systemSettings['system_timezone'] ?? APP_TIMEZONE)); ?>" class="mt-1 block w-full border border-gray-300 rounded-lg px-3 py-2" placeholder="Africa/Blantyre">
                </div>

                <div>
                    <label for="default_currency" class="block text-sm font-medium text-gray-700">Default Currency</label>
                    <input type="text" id="default_currency" name="default_currency" value="<?php echo htmlspecialchars((string) ($systemSettings['default_currency'] ?? 'MWK')); ?>" class="mt-1 block w-full border border-gray-300 rounded-lg px-3 py-2">
                </div>

                <div>
                    <label for="default_country_code" class="block text-sm font-medium text-gray-700">Default Country Code</label>
                    <input type="text" id="default_country_code" name="default_country_code" value="<?php echo htmlspecialchars((string) ($systemSettings['default_country_code'] ?? '+265')); ?>" class="mt-1 block w-full border border-gray-300 rounded-lg px-3 py-2">
                </div>

                <div>
                    <label for="date_format" class="block text-sm font-medium text-gray-700">Date Format</label>
                    <input type="text" id="date_format" name="date_format" value="<?php echo htmlspecialchars((string) ($systemSettings['date_format'] ?? 'Y-m-d')); ?>" class="mt-1 block w-full border border-gray-300 rounded-lg px-3 py-2">
                </div>

                <div>
                    <label for="time_format" class="block text-sm font-medium text-gray-700">Time Format</label>
                    <input type="text" id="time_format" name="time_format" value="<?php echo htmlspecialchars((string) ($systemSettings['time_format'] ?? 'H:i')); ?>" class="mt-1 block w-full border border-gray-300 rounded-lg px-3 py-2">
                </div>

                <div>
                    <label for="records_per_page" class="block text-sm font-medium text-gray-700">Records Per Page</label>
                    <input type="number" min="5" max="200" id="records_per_page" name="records_per_page" value="<?php echo htmlspecialchars((string) ($systemSettings['records_per_page'] ?? 25)); ?>" class="mt-1 block w-full border border-gray-300 rounded-lg px-3 py-2">
                </div>
            </div>
        </div>

        <div class="bg-white shadow-md rounded-xl p-6">
            <h2 class="text-lg font-semibold mb-4 text-gray-800 border-b pb-2">Operational Toggles</h2>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <?php
                $toggles = [
                    'maintenance_mode' => 'Maintenance mode',
                    'profile_enforcement_enabled' => 'Require valid user contact profiles',
                    'allow_self_registration' => 'Allow self registration',
                    'enforce_strong_passwords' => 'Enforce strong passwords',
                    'enable_audit_logs' => 'Enable audit logs',
                    'enable_multi_branch' => 'Enable multi-branch operations',
                    'enable_approval_workflows' => 'Enable approval workflows',
                    'enable_project_billing' => 'Enable project billing controls',
                    'enable_priority_dispatch' => 'Enable priority dispatch rules',
                    'enable_advanced_estimation_rules' => 'Enable advanced estimation rules',
                ];
                ?>
                <?php foreach ($toggles as $key => $label): ?>
                    <?php $toggleDefault = get_setting_definition($key)['default'] ?? false; ?>
                    <label class="flex items-start gap-3 p-4 border border-gray-200 rounded-xl">
                        <input type="checkbox" name="<?php echo htmlspecialchars($key); ?>" class="mt-1 h-4 w-4 rounded border-gray-300 text-blue-600" <?php echo setting_truthy($key, $toggleDefault) ? 'checked' : ''; ?>>
                        <span>
                            <span class="block text-sm font-medium text-gray-800"><?php echo htmlspecialchars($label); ?></span>
                            <span class="block text-xs text-gray-500">Saved centrally for standard and pro operational control.</span>
                        </span>
                    </label>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="bg-white shadow-md rounded-xl p-6">
            <h2 class="text-lg font-semibold mb-4 text-gray-800 border-b pb-2">Security Monitoring</h2>
            <p class="text-sm text-gray-500 mb-4">Configure failed-login detection, automatic IP blocking, and whether system administrators receive security notifications when the block threshold is reached.</p>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <label class="flex items-start gap-3 p-4 border border-gray-200 rounded-xl md:col-span-2">
                    <input type="checkbox" name="security_login_monitoring_enabled" class="mt-1 h-4 w-4 rounded border-gray-300 text-blue-600" <?php echo !empty($securitySettings['login_monitoring_enabled']) ? 'checked' : ''; ?>>
                    <span>
                        <span class="block text-sm font-medium text-gray-800">Enable failed-login monitoring</span>
                        <span class="block text-xs text-gray-500">Tracks login attempts by user identifier and IP address, then escalates repeated failures into automatic IP blocks.</span>
                    </span>
                </label>

                <div>
                    <label for="security_failed_login_threshold" class="block text-sm font-medium text-gray-700">Failed Login Threshold</label>
                    <input type="number" min="1" max="50" id="security_failed_login_threshold" name="security_failed_login_threshold" value="<?php echo htmlspecialchars((string) $securitySettings['failed_login_threshold']); ?>" class="mt-1 block w-full border border-gray-300 rounded-lg px-3 py-2">
                    <p class="mt-1 text-xs text-gray-500">Number of failed attempts before the IP is automatically blocked.</p>
                </div>

                <div>
                    <label for="security_failed_login_window_minutes" class="block text-sm font-medium text-gray-700">Detection Window (Minutes)</label>
                    <input type="number" min="1" max="1440" id="security_failed_login_window_minutes" name="security_failed_login_window_minutes" value="<?php echo htmlspecialchars((string) $securitySettings['failed_login_window_minutes']); ?>" class="mt-1 block w-full border border-gray-300 rounded-lg px-3 py-2">
                    <p class="mt-1 text-xs text-gray-500">Only failures inside this rolling time window count toward the threshold.</p>
                </div>

                <div>
                    <label for="security_block_duration_minutes" class="block text-sm font-medium text-gray-700">Automatic Block Duration (Minutes)</label>
                    <input type="number" min="0" max="10080" id="security_block_duration_minutes" name="security_block_duration_minutes" value="<?php echo htmlspecialchars((string) $securitySettings['block_duration_minutes']); ?>" class="mt-1 block w-full border border-gray-300 rounded-lg px-3 py-2">
                    <p class="mt-1 text-xs text-gray-500">Set to <code>0</code> to keep the block active until an administrator manually clears it.</p>
                </div>

                <label class="flex items-start gap-3 p-4 border border-gray-200 rounded-xl">
                    <input type="checkbox" name="security_notify_admin_on_block" class="mt-1 h-4 w-4 rounded border-gray-300 text-blue-600" <?php echo !empty($securitySettings['notify_admin_on_block']) ? 'checked' : ''; ?>>
                    <span>
                        <span class="block text-sm font-medium text-gray-800">Notify administrators when an IP is auto-blocked</span>
                        <span class="block text-xs text-gray-500">Uses the in-app notification system so system administrators can react quickly to suspicious activity.</span>
                    </span>
                </label>
            </div>
        </div>

        <div class="bg-white shadow-md rounded-xl p-6">
            <h2 class="text-lg font-semibold mb-4 text-gray-800 border-b pb-2">API and Webhook Credentials</h2>
            <p class="text-sm text-gray-500 mb-4">Sensitive fields are stored in <code>.env</code>. Leave secret fields blank to keep the current value.</p>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label for="public_api_base_url" class="block text-sm font-medium text-gray-700">Public API Base URL</label>
                    <input type="url" id="public_api_base_url" name="public_api_base_url" value="<?php echo htmlspecialchars((string) ($apiSettings['public_api_base_url'] ?? '')); ?>" class="mt-1 block w-full border border-gray-300 rounded-lg px-3 py-2">
                </div>

                <div>
                    <label for="api_client_id" class="block text-sm font-medium text-gray-700">API Client ID</label>
                    <input type="text" id="api_client_id" name="api_client_id" value="<?php echo htmlspecialchars((string) ($apiSettings['api_client_id'] ?? '')); ?>" class="mt-1 block w-full border border-gray-300 rounded-lg px-3 py-2">
                </div>

                <div>
                    <label for="api_client_secret" class="block text-sm font-medium text-gray-700">API Client Secret</label>
                    <input type="password" id="api_client_secret" name="api_client_secret" value="" class="mt-1 block w-full border border-gray-300 rounded-lg px-3 py-2" placeholder="<?php echo setting_has_stored_value('api_client_secret') ? 'Stored in .env' : 'Enter secret'; ?>">
                </div>

                <div>
                    <label for="webhook_endpoint" class="block text-sm font-medium text-gray-700">Webhook Endpoint</label>
                    <input type="url" id="webhook_endpoint" name="webhook_endpoint" value="<?php echo htmlspecialchars((string) ($apiSettings['webhook_endpoint'] ?? '')); ?>" class="mt-1 block w-full border border-gray-300 rounded-lg px-3 py-2">
                </div>

                <div class="md:col-span-2">
                    <label for="webhook_signing_secret" class="block text-sm font-medium text-gray-700">Webhook Signing Secret</label>
                    <input type="password" id="webhook_signing_secret" name="webhook_signing_secret" value="" class="mt-1 block w-full border border-gray-300 rounded-lg px-3 py-2" placeholder="<?php echo setting_has_stored_value('webhook_signing_secret') ? 'Stored in .env' : 'Enter secret'; ?>">
                </div>
            </div>
        </div>

        <div class="flex justify-end">
            <button type="submit" class="bg-blue-600 text-white px-6 py-2.5 rounded-lg shadow-sm hover:bg-blue-700">
                Save System Settings
            </button>
        </div>
    </form>

    <div class="bg-amber-50 border border-amber-200 rounded-xl p-6 mt-8">
        <div class="flex flex-col gap-2 mb-4">
            <h2 class="text-lg font-semibold text-amber-900">Installer Utility</h2>
            <p class="text-sm text-amber-800">Unlock a token-protected reinstall session when you need to redeploy the platform from a new SQL release. This puts the application into maintenance mode first, then sends you straight into the installer.</p>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-5">
            <div class="bg-white rounded-lg border border-amber-200 px-4 py-3">
                <p class="text-xs uppercase tracking-wide text-amber-500">State</p>
                <p class="mt-2 font-semibold text-gray-800"><?php echo $installerMeta['installed'] ? 'Installed' : 'Pending setup'; ?></p>
            </div>
            <div class="bg-white rounded-lg border border-amber-200 px-4 py-3">
                <p class="text-xs uppercase tracking-wide text-amber-500">Last SQL Package</p>
                <p class="mt-2 font-semibold text-gray-800"><?php echo htmlspecialchars($installerMeta['last_sql_file'] !== '' ? $installerMeta['last_sql_file'] : 'Not recorded yet'); ?></p>
            </div>
            <div class="bg-white rounded-lg border border-amber-200 px-4 py-3">
                <p class="text-xs uppercase tracking-wide text-amber-500">Last Installed</p>
                <p class="mt-2 font-semibold text-gray-800"><?php echo htmlspecialchars($installerMeta['last_installed_at'] !== '' ? $installerMeta['last_installed_at'] : 'Not recorded yet'); ?></p>
            </div>
        </div>

        <div class="rounded-lg bg-white border border-amber-200 px-4 py-3 mb-4 text-sm text-gray-700">
            Use this only when the incoming SQL package is intended to replace the current database state. Always take a backup first.
        </div>

        <form method="POST" action="index" class="flex flex-wrap items-center gap-3">
            <input type="hidden" name="action" value="unlock_reinstall">
            <input type="hidden" name="_csrf" value="<?php echo htmlspecialchars(csrf_token('installer_unlock_action')); ?>">
            <button type="submit" class="bg-amber-600 text-white px-5 py-2.5 rounded-lg shadow-sm hover:bg-amber-700">
                Unlock Reinstall Flow
            </button>
            <span class="text-sm text-amber-800">After unlock, only the generated installer token can reopen setup until you finish or cancel the reinstall.</span>
        </form>
    </div>
</div>

<style>
    .branding-upload-preview {
        flex-shrink: 0;
        display: flex;
        align-items: center;
        justify-content: center;
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        border-radius: 0.75rem;
        overflow: hidden;
        position: relative;
    }

    .branding-upload-preview--logo {
        width: 10rem;
        height: 6rem;
    }

    .branding-upload-preview--icon {
        width: 5.5rem;
        height: 5.5rem;
    }

    .branding-upload-preview-img {
        max-width: 100%;
        max-height: 100%;
        object-fit: contain;
    }

    .branding-upload-preview-empty {
        font-size: 0.7rem;
        text-transform: uppercase;
        letter-spacing: 0.06em;
        color: #94a3b8;
    }
</style>
<script src="<?php echo asset('js/logo-upload-zone.js'); ?>"></script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
