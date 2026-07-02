<?php
require_once dirname(dirname(__DIR__)) . '/config/app.php';
checkAuth();
require_once ROOT_PATH . 'config/database.php';
require_once ROOT_PATH . 'libs/NotificationManager.php';
require_once ROOT_PATH . 'libs/BrowserPushManager.php';

$user_id = intval($_SESSION['user_id']);
$success_message = '';
$error_message = '';
$types = ['message', 'task', 'security', 'reminder'];
$notif_types = [
    'message' => ['title' => 'Direct Messages', 'desc' => 'Notifications when someone sends you a message.'],
    'task' => ['title' => 'Task Assignments', 'desc' => 'Notifications for new tasks or status updates.'],
    'security' => ['title' => 'Security Alerts', 'desc' => 'Important alerts about your account login and security.'],
    'reminder' => ['title' => 'System Reminders', 'desc' => 'Automated reminders for deadlines and upcoming events.']
];

$notifManager = new NotificationManager($pdo);
$browserPushManager = new BrowserPushManager($pdo);
$pushStatus = $browserPushManager->getStatus($user_id);

// Handle settings update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $pdo->beginTransaction();
        $existsStmt = $pdo->prepare("
            SELECT id
            FROM notification_settings
            WHERE user_id = :user_id AND notification_type = :type
            LIMIT 1
        ");
        $updateStmt = $pdo->prepare("
            UPDATE notification_settings
            SET email_enabled = :email,
                in_app_enabled = :in_app,
                push_enabled = :push_enabled,
                sms_enabled = :sms_enabled,
                whatsapp_enabled = :wa_enabled
            WHERE user_id = :user_id AND notification_type = :type
        ");
        $insertStmt = $pdo->prepare("
            INSERT INTO notification_settings (
                user_id,
                notification_type,
                email_enabled,
                in_app_enabled,
                push_enabled,
                sms_enabled,
                whatsapp_enabled
            )
            VALUES (:user_id, :type, :email, :in_app, :push_enabled, :sms_enabled, :wa_enabled)
        ");

        foreach ($types as $type) {
            $email_enabled = isset($_POST["email_$type"]) ? 1 : 0;
            $in_app_enabled = isset($_POST["in_app_$type"]) ? 1 : 0;
            $push_enabled = isset($_POST["push_$type"]) ? 1 : 0;
            $sms_enabled = isset($_POST["sms_$type"]) ? 1 : 0;
            $wa_enabled = isset($_POST["whatsapp_$type"]) ? 1 : 0;

            $params = [
                'user_id' => $user_id,
                'type' => $type,
                'email' => $email_enabled,
                'in_app' => $in_app_enabled,
                'push_enabled' => $push_enabled,
                'sms_enabled' => $sms_enabled,
                'wa_enabled' => $wa_enabled
            ];

            $existsStmt->execute([
                'user_id' => $user_id,
                'type' => $type
            ]);

            if ($existsStmt->fetchColumn()) {
                $updateStmt->execute($params);
            } else {
                $insertStmt->execute($params);
            }
        }
        $pdo->commit();
        $success_message = "Notification settings updated successfully!";
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error_message = "Error updating settings: " . $e->getMessage();
    }
}

// Fetch current settings
$stmt = $pdo->prepare("SELECT notification_type, email_enabled, in_app_enabled, push_enabled, sms_enabled, whatsapp_enabled FROM notification_settings WHERE user_id = ?");
$stmt->execute([$user_id]);
$current_settings = [];
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $setting) {
    $current_settings[$setting['notification_type']] = $setting;
}

function isChecked($type, $field, $current_settings) {
    if (!isset($current_settings[$type])) {
        return in_array($field, ['in_app_enabled', 'push_enabled'], true) ? 'checked' : '';
    }
    return $current_settings[$type][$field] ? 'checked' : '';
}

include ROOT_PATH . 'includes/header.php';
?>

<style>
.notification-toggle {
    display: inline-flex;
    align-items: center;
    cursor: pointer;
    position: relative;
}

.notification-toggle-input {
    position: absolute;
    width: 1px;
    height: 1px;
    padding: 0;
    margin: -1px;
    overflow: hidden;
    clip: rect(0, 0, 0, 0);
    white-space: nowrap;
    border: 0;
}

.notification-toggle-track {
    width: 2.75rem;
    height: 1.5rem;
    border-radius: 9999px;
    background-color: #dbe0e8;
    border: 1px solid #d4dae3;
    position: relative;
    transition: background-color 0.2s ease, border-color 0.2s ease, box-shadow 0.2s ease;
}

.notification-toggle-track::after {
    content: '';
    position: absolute;
    top: 1px;
    left: 1px;
    width: 1.25rem;
    height: 1.25rem;
    border-radius: 9999px;
    background-color: #ffffff;
    box-shadow: 0 1px 3px rgba(15, 23, 42, 0.18);
    transition: transform 0.2s ease;
}

.notification-toggle-input:focus + .notification-toggle-track {
    box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.18);
}

.notification-toggle-input:checked + .notification-toggle-track {
    background-color: #2563eb;
    border-color: #2563eb;
}

.notification-toggle-input:checked + .notification-toggle-track::after {
    transform: translateX(1.25rem);
}
</style>

<div class="max-w-4xl mx-auto">
    <div class="mb-8 flex justify-between items-center">
        <div>
            <h1 class="text-3xl font-extrabold text-gray-900 tracking-tight">Notification Settings</h1>
            <p class="text-gray-500 mt-1">Control how and when you receive notifications.</p>
        </div>
        <a href="profile" class="text-blue-600 hover:underline flex items-center">
            <i class="material-icons text-sm mr-1">arrow_back</i> Back to Profile
        </a>
    </div>

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

    <!-- Sound Notification Preference (client-side, persisted in localStorage) -->
    <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden mb-6">
        <div class="px-6 py-5 flex items-center justify-between">
            <div class="flex items-center gap-4">
                <div class="w-10 h-10 rounded-full bg-blue-100 flex items-center justify-center text-blue-600 flex-shrink-0">
                    <i class="material-icons">volume_up</i>
                </div>
                <div>
                    <h3 class="font-bold text-gray-800">Sound Notifications</h3>
                    <p class="text-xs text-gray-500 mt-0.5">Play an audio alert when a new notification arrives.</p>
                </div>
            </div>
            <label class="notification-toggle">
                <input type="checkbox" id="sound-notif-toggle" class="notification-toggle-input" checked aria-label="Enable sound notifications">
                <span class="notification-toggle-track" aria-hidden="true"></span>
            </label>
        </div>
        <div id="sound-test-bar" class="px-6 pb-4 flex items-center gap-3">
            <button type="button" id="test-sound-btn"
                class="inline-flex items-center gap-1 text-sm text-blue-600 hover:text-blue-800 border border-blue-200 rounded-lg px-3 py-1 transition hover:bg-blue-50">
                <i class="material-icons text-sm">play_circle</i> Test Sound
            </button>
            <span id="sound-test-feedback" class="text-xs text-gray-400 hidden">Sound played!</span>
        </div>
    </div>

    <audio id="settings-notif-sound" preload="auto" aria-hidden="true">
        <source src="<?php echo asset('sounds/soundreality-notification-center-443093.mp3'); ?>" type="audio/mpeg">
    </audio>

    <script>
    (function () {
        const SOUND_KEY   = 'govpress_notif_sound_enabled';
        const toggle      = document.getElementById('sound-notif-toggle');
        const testBtn     = document.getElementById('test-sound-btn');
        const feedback    = document.getElementById('sound-test-feedback');
        const audio       = document.getElementById('settings-notif-sound');

        // Sync toggle with stored preference
        const stored = localStorage.getItem(SOUND_KEY);
        toggle.checked = stored !== 'false'; // default ON

        toggle.addEventListener('change', function () {
            localStorage.setItem(SOUND_KEY, this.checked);
            // Sync topbar sound toggle icon if on same page load
            const topbarIcon = document.querySelector('#notif-sound-toggle i');
            if (topbarIcon) {
                topbarIcon.textContent = this.checked ? 'volume_up' : 'volume_off';
            }
        });

        testBtn.addEventListener('click', function () {
            if (!toggle.checked) {
                feedback.textContent = 'Enable sound first.';
                feedback.classList.remove('hidden');
                setTimeout(function () { feedback.classList.add('hidden'); }, 2000);
                return;
            }
            audio.currentTime = 0;
            audio.play().then(function () {
                feedback.textContent = 'Sound played!';
                feedback.classList.remove('hidden');
                setTimeout(function () { feedback.classList.add('hidden'); }, 2000);
            }).catch(function () {
                feedback.textContent = 'Could not play — click elsewhere first.';
                feedback.classList.remove('hidden');
                setTimeout(function () { feedback.classList.add('hidden'); }, 3000);
            });
        });
    })();
    </script>

    <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden mb-6">
        <div class="px-6 py-5 flex items-center justify-between gap-4 flex-wrap">
            <div class="flex items-center gap-4 min-w-0">
                <div class="w-10 h-10 rounded-full bg-teal-100 flex items-center justify-center text-teal-700 flex-shrink-0">
                    <i class="material-icons">notifications_active</i>
                </div>
                <div>
                    <h3 class="font-bold text-gray-800">Browser Push Notifications</h3>
                    <p class="text-xs text-gray-500 mt-0.5">Keep alerts running in the background with branded notifications, even when this browser tab is inactive.</p>
                </div>
            </div>
            <div class="flex items-center gap-3">
                <span id="browser-push-status" class="inline-flex items-center px-3 py-1 rounded-full bg-slate-100 text-slate-700 text-xs font-semibold">
                    <?php echo !empty($pushStatus['has_active_subscription']) ? 'Active' : 'Not Enabled'; ?>
                </span>
                <button
                    type="button"
                    id="browser-push-test"
                    class="inline-flex items-center gap-2 rounded-xl border border-teal-300 text-teal-700 px-4 py-2 text-sm font-semibold hover:bg-teal-50 transition"
                >
                    <i class="material-icons text-sm">send</i>
                    <span>Send Test</span>
                </button>
                <button
                    type="button"
                    id="browser-push-enable"
                    class="inline-flex items-center gap-2 rounded-xl bg-teal-600 text-white px-4 py-2 text-sm font-semibold hover:bg-teal-700 transition"
                >
                    <i class="material-icons text-sm">notifications</i>
                    <span>Enable</span>
                </button>
                <button
                    type="button"
                    id="browser-push-disable"
                    class="inline-flex items-center gap-2 rounded-xl border border-slate-300 text-slate-700 px-4 py-2 text-sm font-semibold hover:bg-slate-50 transition"
                    <?php echo empty($pushStatus['has_active_subscription']) ? 'disabled' : ''; ?>
                >
                    <i class="material-icons text-sm">notifications_off</i>
                    <span>Disable</span>
                </button>
            </div>
        </div>
        <div class="px-6 pb-5">
            <p id="browser-push-detail" class="text-sm text-gray-500">
                <?php if (!empty($pushStatus['has_active_subscription'])): ?>
                    Background notifications are active for this browser and will carry your business branding.
                <?php else: ?>
                    Enable this to receive branded browser alerts for messages, tasks, reminders, and security notices.
                <?php endif; ?>
            </p>
            <p class="text-xs text-gray-400 mt-2">
                The channel switches in the table below control which notification types may use browser push after this browser has been registered.
            </p>
        </div>
    </div>

    <script>
    (function () {
        const testButton = document.getElementById('browser-push-test');
        if (!testButton) {
            return;
        }

        const csrfToken = <?php echo json_encode(csrf_token('push_test')); ?>;
        const endpoint = <?php echo json_encode(BASE_URL . 'modules/user-account/push_test'); ?>;

        testButton.addEventListener('click', function () {
            testButton.disabled = true;

            Promise.resolve()
            .then(function () {
                if (!window.PressErpPush || typeof window.PressErpPush.enable !== 'function') {
                    throw new Error('Browser push helpers are not loaded on this page.');
                }

                return window.PressErpPush.enable();
            })
            .then(function () {
                return fetch(endpoint, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': csrfToken
                    },
                    body: JSON.stringify({})
                });
            })
            .then(function (response) {
                return response.json().then(function (payload) {
                    return {
                        ok: response.ok,
                        payload: payload
                    };
                });
            })
            .then(function (result) {
                if (!result.ok || !result.payload.success) {
                    throw new Error(result.payload.message || 'Test push failed.');
                }

                if (typeof showToast === 'function') {
                    showToast('Test push sent. If the page is active, a browser popup should still appear.', 'success', {
                        sound: 'success'
                    });
                }
            })
            .catch(function (error) {
                if (typeof showToast === 'function') {
                    showToast(error.message || 'Unable to send a test push notification.', 'error', {
                        sound: 'error'
                    });
                }
            })
            .finally(function () {
                testButton.disabled = false;
            });
        });
    })();
    </script>

    <form method="POST" action="notifications" class="space-y-6">
        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
            <table class="w-full text-left border-collapse">
                <thead>
                    <tr class="bg-gray-50 border-b border-gray-100">
                        <th class="px-6 py-4 text-sm font-bold text-gray-700 uppercase tracking-wide">Notification Type</th>
                        <th class="px-6 py-4 text-sm font-bold text-gray-700 uppercase tracking-wide text-center">In-App</th>
                        <th class="px-6 py-4 text-sm font-bold text-gray-700 uppercase tracking-wide text-center">Browser Push</th>
                        <th class="px-6 py-4 text-sm font-bold text-gray-700 uppercase tracking-wide text-center">Email</th>
                        <th class="px-6 py-4 text-sm font-bold text-gray-700 uppercase tracking-wide text-center">SMS</th>
                        <th class="px-6 py-4 text-sm font-bold text-gray-700 uppercase tracking-wide text-center">WhatsApp</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-50">
                    <?php foreach ($notif_types as $type => $info): ?>
                    <tr>
                        <td class="px-6 py-6">
                            <h3 class="font-bold text-gray-800"><?php echo $info['title']; ?></h3>
                            <p class="text-xs text-gray-500 mt-1"><?php echo $info['desc']; ?></p>
                        </td>
                        <td class="px-6 py-6 text-center">
                            <label class="notification-toggle">
                                <input type="checkbox" name="in_app_<?php echo $type; ?>" class="notification-toggle-input" <?php echo isChecked($type, 'in_app_enabled', $current_settings); ?> aria-label="Enable in-app notifications for <?php echo htmlspecialchars($info['title']); ?>">
                                <span class="notification-toggle-track" aria-hidden="true"></span>
                            </label>
                        </td>
                        <td class="px-6 py-6 text-center">
                            <label class="notification-toggle">
                                <input type="checkbox" name="push_<?php echo $type; ?>" class="notification-toggle-input" <?php echo isChecked($type, 'push_enabled', $current_settings); ?> aria-label="Enable browser push notifications for <?php echo htmlspecialchars($info['title']); ?>">
                                <span class="notification-toggle-track" aria-hidden="true"></span>
                            </label>
                        </td>
                        <td class="px-6 py-6 text-center">
                            <label class="notification-toggle">
                                <input type="checkbox" name="email_<?php echo $type; ?>" class="notification-toggle-input" <?php echo isChecked($type, 'email_enabled', $current_settings); ?> aria-label="Enable email notifications for <?php echo htmlspecialchars($info['title']); ?>">
                                <span class="notification-toggle-track" aria-hidden="true"></span>
                            </label>
                        </td>
                        <td class="px-6 py-6 text-center">
                            <label class="notification-toggle">
                                <input type="checkbox" name="sms_<?php echo $type; ?>" class="notification-toggle-input" <?php echo isChecked($type, 'sms_enabled', $current_settings); ?> aria-label="Enable SMS notifications for <?php echo htmlspecialchars($info['title']); ?>">
                                <span class="notification-toggle-track" aria-hidden="true"></span>
                            </label>
                        </td>
                        <td class="px-6 py-6 text-center">
                            <label class="notification-toggle">
                                <input type="checkbox" name="whatsapp_<?php echo $type; ?>" class="notification-toggle-input" <?php echo isChecked($type, 'whatsapp_enabled', $current_settings); ?> aria-label="Enable WhatsApp notifications for <?php echo htmlspecialchars($info['title']); ?>">
                                <span class="notification-toggle-track" aria-hidden="true"></span>
                            </label>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="flex items-center justify-end space-x-4">
            <button type="submit" class="bg-blue-600 text-white font-bold px-10 py-3 rounded-xl hover:bg-blue-700 transition shadow-lg shadow-blue-200">
                Save Preferences
            </button>
        </div>
    </form>
</div>

<?php include ROOT_PATH . 'includes/footer.php'; ?>
