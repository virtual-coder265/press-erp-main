        </main>
    </div>

    <!-- GLOBAL CONFIRMATION MODAL -->
    <div id="confirmModal" class="hidden fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center p-4" aria-hidden="true">
        <div class="bg-white rounded-xl shadow-2xl max-w-sm w-full transform transition-all scale-95 opacity-0 duration-300 overflow-hidden" id="modalContent">
            <div class="p-6 text-center">
                <div class="w-16 h-16 bg-red-100 rounded-full flex items-center justify-center mx-auto mb-4">
                    <i class="material-icons text-red-600 text-3xl">warning</i>
                </div>
                <h3 class="text-xl font-bold text-gray-800 mb-2" id="modalTitle">Confirm Deletion</h3>
                <p class="text-gray-600 mb-6" id="modalMessage">Are you sure you want to delete this item? This action cannot be undone.</p>
                <div class="flex gap-3">
                    <button onclick="closeConfirmModal()" class="surface-button flex-1 justify-center text-gray-600">Cancel</button>
                    <a id="modalConfirmBtn" href="#" class="surface-button flex-1 justify-center bg-red-600 text-white border-red-600 hover:bg-red-700 transition font-bold">Delete</a>
                </div>
            </div>
        </div>
    </div>

    <!-- GLOBAL TOAST NOTIFICATION -->
    <div id="toastContainer" class="space-y-3"></div>

    <!-- GLOBAL REQUEST LOADER -->
    <div id="globalRequestLoaderBar" class="global-request-loader__bar" aria-hidden="true"></div>
    <div id="globalRequestLoader" class="global-request-loader" aria-hidden="true" aria-live="polite" role="status">
        <div class="global-request-loader__backdrop"></div>
        <div class="global-request-loader__panel">
            <div class="global-request-loader__rings" aria-hidden="true">
                <span class="global-request-loader__ring"></span>
                <span class="global-request-loader__ring"></span>
                <span class="global-request-loader__ring"></span>
                <span class="global-request-loader__dot"></span>
            </div>
            <p id="globalRequestLoaderLabel" class="global-request-loader__label">Please wait…</p>
        </div>
    </div>

    <!-- GLOBAL ACTION MODAL -->
    <div class="todo-modal-overlay action-modal-overlay" id="globalActionModal" role="dialog" aria-modal="true" aria-labelledby="globalActionModalTitle" aria-hidden="true">
        <div class="todo-modal todo-modal--lg action-modal">
            <div class="todo-modal-header action-modal-header">
                <div class="action-modal-heading">
                    <span class="action-modal-icon" id="globalActionModalIcon">
                        <i class="material-icons">bolt</i>
                    </span>
                    <div class="min-w-0">
                        <p class="action-modal-kicker" id="globalActionModalKicker">Quick action</p>
                        <h3 class="todo-modal-title action-modal-title" id="globalActionModalTitle">Create item</h3>
                        <p class="action-modal-subtitle" id="globalActionModalSubtitle">Complete this action without leaving your current workspace.</p>
                    </div>
                </div>
                <button type="button" class="todo-modal-close action-modal-close" data-ws-close aria-label="Close">&times;</button>
            </div>
            <div class="action-modal-alert hidden" id="globalActionModalAlert" role="alert"></div>
            <div class="todo-modal-body action-modal-body" id="globalActionModalBody">
                <div class="action-modal-loading">
                    <span class="action-modal-spinner" aria-hidden="true"></span>
                    <span>Preparing action...</span>
                </div>
            </div>
        </div>
    </div>

    <?php
        $assistantEnabled = !empty($_SESSION['user_id']) && function_exists('setting_truthy') && setting_truthy('ai_enabled', false);
        $assistantConfig = function_exists('get_ai_settings') ? get_ai_settings() : ['features' => []];
        $assistantFeatureConfig = is_array($assistantConfig['features'] ?? null) ? $assistantConfig['features'] : [];
        $assistantHasFeature = !empty(array_filter($assistantFeatureConfig));
        $assistantEnabled = $assistantEnabled && $assistantHasFeature;
    ?>
    <?php include __DIR__ . '/smart_calculator.php'; ?>
    <?php if ($assistantEnabled): ?>
    <div id="aiAssistantRoot" class="ai-assistant-root" data-enabled="1">
        <button
            type="button"
            id="aiAssistantToggle"
            class="ai-assistant-fab"
            aria-controls="aiAssistantPanel"
            aria-expanded="false"
            aria-label="Open AI assistant"
        >
            <i class="material-icons">auto_awesome</i>
        </button>

        <section id="aiAssistantPanel" class="ai-assistant-panel hidden" aria-hidden="true">
            <header class="ai-assistant-header">
                <div>
                    <h3 class="ai-assistant-title">AI Assistant</h3>
                    <p class="ai-assistant-subtitle">Ask a question and the assistant will choose the right ERP context automatically.</p>
                </div>
                <button type="button" id="aiAssistantClose" class="ai-assistant-close" aria-label="Close assistant">
                    <i class="material-icons">close</i>
                </button>
            </header>

            <div id="aiAssistantMessages" class="ai-assistant-messages" role="log" aria-live="polite"></div>

            <form id="aiAssistantForm" class="ai-assistant-form">
                <textarea id="aiAssistantInput" rows="3" maxlength="2500" placeholder="Ask for insight, priority suggestions, or projections..." required></textarea>
                <div class="ai-assistant-actions">
                    <button type="submit" id="aiAssistantSend" class="ai-assistant-send">
                        <i class="material-icons">send</i>
                        <span>Send</span>
                    </button>
                </div>
            </form>
        </section>
    </div>
    <?php endif; ?>

    <audio id="app-sound-message" preload="none" aria-hidden="true">
        <source src="<?php echo asset('sounds/soundreality-notification-center-443093.mp3'); ?>" type="audio/mpeg">
    </audio>
    <audio id="app-sound-confirmation" preload="none" aria-hidden="true">
        <source src="<?php echo asset('sounds/deleted-comfimation.wav'); ?>" type="audio/wav">
    </audio>
    <audio id="app-sound-success" preload="none" aria-hidden="true">
        <source src="<?php echo asset('sounds/task-created.wav'); ?>" type="audio/wav">
    </audio>
    <audio id="app-sound-error" preload="none" aria-hidden="true">
        <source src="<?php echo asset('sounds/mixkit-magic-notification-ring-2344.wav'); ?>" type="audio/wav">
    </audio>
    <audio id="app-sound-reminder" preload="none" aria-hidden="true">
        <source src="<?php echo asset('sounds/reminder.wav'); ?>" type="audio/wav">
    </audio>

    <script src="<?php echo asset('js/app-shell.js'); ?>"></script>
    <?php
    $formGuardJsPath = ROOT_PATH . 'assets/js/form-unsaved-guard.js';
    $formGuardJsV = file_exists($formGuardJsPath) ? (string) filemtime($formGuardJsPath) : (string) time();
    ?>
    <script src="<?php echo asset('js/form-unsaved-guard.js') . '?v=' . rawurlencode($formGuardJsV); ?>"></script>
    <?php
    $pressErpSkipGlobalDateTimePicker = !empty($pressErpSkipGlobalDateTimePicker);
    ?>
    <?php $nativeDtJsPath = ROOT_PATH . 'assets/js/native-date-range.js'; ?>
    <?php $nativeDtJsV = file_exists($nativeDtJsPath) ? (string) filemtime($nativeDtJsPath) : (string) time(); ?>
    <script src="<?php echo asset('js/native-date-range.js') . '?v=' . rawurlencode($nativeDtJsV); ?>"></script>
    <?php if (!$pressErpSkipGlobalDateTimePicker): ?>
    <?php $pressDtJsPath = ROOT_PATH . 'assets/js/datetime-picker.js'; ?>
    <?php $pressDtJsV = file_exists($pressDtJsPath) ? (string) filemtime($pressDtJsPath) : (string) time(); ?>
    <script src="<?php echo asset('js/datetime-picker.js') . '?v=' . rawurlencode($pressDtJsV); ?>"></script>
    <?php endif; ?>
    <?php if (!empty($pressErpProjectCreateBudget)): ?>
    <?php $pcBudgetJsPath = ROOT_PATH . 'assets/js/project-create-budget-toggle.js'; ?>
    <?php $pcBudgetJsV = file_exists($pcBudgetJsPath) ? (string) filemtime($pcBudgetJsPath) : (string) time(); ?>
    <script src="<?php echo asset('js/project-create-budget-toggle.js') . '?v=' . rawurlencode($pcBudgetJsV); ?>"></script>
    <?php endif; ?>
    <script>
        window.PRESS_ERP_ACTION_MODALS = {
            baseUrl: '<?php echo BASE_URL; ?>',
            endpoints: {
                'reminder.create': '<?php echo BASE_URL; ?>modules/action-modals/reminder-form',
                'task.create': '<?php echo BASE_URL; ?>modules/action-modals/task-form',
                'project.create': '<?php echo BASE_URL; ?>modules/action-modals/project-form'
            }
        };
    </script>
    <?php $actionModalsJsVersion = file_exists(ROOT_PATH . 'assets/js/action-modals.js') ? (string) filemtime(ROOT_PATH . 'assets/js/action-modals.js') : (string) time(); ?>
    <script src="<?php echo asset('js/action-modals.js') . '?v=' . rawurlencode($actionModalsJsVersion); ?>"></script>
    <?php $ajaxComponentsJsVersion = file_exists(ROOT_PATH . 'assets/js/ajax-components.js') ? (string) filemtime(ROOT_PATH . 'assets/js/ajax-components.js') : (string) time(); ?>
    <script src="<?php echo asset('js/ajax-components.js') . '?v=' . rawurlencode($ajaxComponentsJsVersion); ?>"></script>
    <?php if (!empty($_SESSION['user_id'])): ?>
    <script>
        window.PRESS_ERP_SMART_CALCULATOR = {
            currencyPrefix: 'MK'
        };
    </script>
    <?php $pressCalcJsVersion = file_exists(ROOT_PATH . 'assets/js/press-calculations.js') ? (string) filemtime(ROOT_PATH . 'assets/js/press-calculations.js') : (string) time(); ?>
    <script src="<?php echo asset('js/press-calculations.js') . '?v=' . rawurlencode($pressCalcJsVersion); ?>"></script>
    <?php $smartCalcJsVersion = file_exists(ROOT_PATH . 'assets/js/smart-calculator.js') ? (string) filemtime(ROOT_PATH . 'assets/js/smart-calculator.js') : (string) time(); ?>
    <script src="<?php echo asset('js/smart-calculator.js') . '?v=' . rawurlencode($smartCalcJsVersion); ?>"></script>
    <?php endif; ?>
    <?php if ($assistantEnabled): ?>
    <script>
        window.PRESS_ERP_AI_ASSISTANT = {
            endpoint: '<?php echo BASE_URL; ?>modules/ai/chat',
            csrfToken: '<?php echo htmlspecialchars(csrf_token('ai_assistant_chat'), ENT_QUOTES, 'UTF-8'); ?>'
        };
    </script>
    <?php $aiAssistantJsVersion = file_exists(ROOT_PATH . 'assets/js/ai-assistant.js') ? (string) filemtime(ROOT_PATH . 'assets/js/ai-assistant.js') : (string) time(); ?>
    <script src="<?php echo asset('js/ai-assistant.js') . '?v=' . rawurlencode($aiAssistantJsVersion); ?>"></script>
    <?php endif; ?>
    <script>
        const APP_SOUND_KEY = 'govpress_notif_sound_enabled';
        const APP_SOUND_IDS = {
            message: 'app-sound-message',
            confirmation: 'app-sound-confirmation',
            success: 'app-sound-success',
            error: 'app-sound-error',
            reminder: 'app-sound-reminder'
        };

        function escapeToastHtml(value) {
            return String(value)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        function isAppSoundEnabled() {
            return localStorage.getItem(APP_SOUND_KEY) !== 'false';
        }

        function playAppSound(type, options = {}) {
            const soundType = APP_SOUND_IDS[type] ? type : 'message';
            if (options.force !== true && !isAppSoundEnabled()) {
                return Promise.resolve(false);
            }

            const audio = document.getElementById(APP_SOUND_IDS[soundType]);
            if (!audio) {
                return Promise.resolve(false);
            }

            audio.currentTime = 0;
            return audio.play().then(function () {
                return true;
            }).catch(function () {
                return false;
            });
        }

        window.isAppSoundEnabled = isAppSoundEnabled;
        window.playAppSound = playAppSound;

        // MODAL LOGIC
        function openConfirmModal(title, message, confirmUrl, isDelete = true) {
            const modalElement = document.getElementById('confirmModal');
            const confirmBtn = document.getElementById('modalConfirmBtn');

            $('#modalTitle').text(title);
            $('#modalMessage').text(message);
            $('#modalConfirmBtn').attr('href', confirmUrl);
            confirmBtn.removeAttribute('data-form-id');

            if (typeof confirmUrl === 'string' && confirmUrl.indexOf('form:') === 0) {
                confirmBtn.setAttribute('href', '#');
                confirmBtn.setAttribute('data-form-id', confirmUrl.slice(5));
            }
            
            if (!isDelete) {
                $('#modalConfirmBtn').removeClass('bg-red-600 hover:bg-red-700 border-red-600').addClass('bg-blue-600 hover:bg-blue-700 border-blue-600').text('Confirm');
                $('#confirmModal .bg-red-100').removeClass('bg-red-100').addClass('bg-blue-100');
                $('#confirmModal .text-red-600').removeClass('text-red-600').addClass('text-blue-600').text('info');
            } else {
                $('#modalConfirmBtn').removeClass('bg-blue-600 hover:bg-blue-700 border-blue-600').addClass('bg-red-600 hover:bg-red-700 border-red-600').text('Delete');
                $('#confirmModal .bg-blue-100').removeClass('bg-blue-100').addClass('bg-red-100');
                $('#confirmModal .text-blue-600').removeClass('text-blue-600').addClass('text-red-600').text('warning');
            }

            if (modalElement && modalElement.parentElement !== document.body) {
                document.body.appendChild(modalElement);
            }

            playAppSound('confirmation');
            $('#confirmModal').attr('aria-hidden', 'false').removeClass('hidden');
            setTimeout(() => {
                $('#modalContent').removeClass('scale-95 opacity-0').addClass('scale-100 opacity-100');
            }, 10);
        }

        function closeConfirmModal() {
            $('#modalContent').removeClass('scale-100 opacity-100').addClass('scale-95 opacity-0');
            setTimeout(() => {
                $('#confirmModal').attr('aria-hidden', 'true').addClass('hidden');
            }, 300);
        }

        document.addEventListener('DOMContentLoaded', function() {
            const modal = document.getElementById('confirmModal');

            if (!modal) {
                return;
            }

            if (modal.parentElement !== document.body) {
                document.body.appendChild(modal);
            }

            modal.addEventListener('click', function(event) {
                if (event.target === modal) {
                    closeConfirmModal();
                }
            });

            const confirmBtn = document.getElementById('modalConfirmBtn');
            if (confirmBtn) {
                confirmBtn.addEventListener('click', function(event) {
                    const formId = this.getAttribute('data-form-id');
                    if (!formId) {
                        return;
                    }

                    event.preventDefault();
                    const form = document.getElementById(formId);
                    if (!form) {
                        return;
                    }

                    closeConfirmModal();
                    form.submit();
                });
            }

            document.addEventListener('keydown', function(event) {
                if (event.key === 'Escape' && !modal.classList.contains('hidden')) {
                    closeConfirmModal();
                }
            });
        });

        // TOAST LOGIC
        function showToast(message, type = 'success', options = {}) {
            const id = 'toast-' + Date.now();
            const colors = {
                'success': 'bg-green-600',
                'error': 'bg-red-600',
                'info': 'bg-blue-600',
                'warning': 'bg-yellow-600'
            };
            const icons = {
                'success': 'check_circle',
                'error': 'error',
                'info': 'info',
                'warning': 'priority_high'
            };
            const soundByType = {
                'success': 'success',
                'error': 'error',
                'info': 'message',
                'warning': 'message'
            };
            const soundType = Object.prototype.hasOwnProperty.call(options, 'sound')
                ? options.sound
                : (soundByType[type] || 'message');
            const parsedDuration = parseInt(options.duration || 5000, 10);
            const duration = Number.isFinite(parsedDuration) ? Math.max(2500, parsedDuration) : 5000;

            const html = `
                <div id="${id}" class="${colors[type]} text-white px-6 py-4 rounded-lg shadow-xl flex items-center gap-3 transform translate-x-full transition-transform duration-300 opacity-0">
                    <i class="material-icons">${icons[type]}</i>
                    <span class="font-medium">${escapeToastHtml(message)}</span>
                </div>
            `;

            $('#toastContainer').append(html);
            if (soundType) {
                playAppSound(soundType);
            }
            
            setTimeout(() => {
                $(`#${id}`).removeClass('translate-x-full opacity-0');
            }, 100);

            setTimeout(() => {
                $(`#${id}`).addClass('translate-x-full opacity-0');
                setTimeout(() => { $(`#${id}`).remove(); }, 300);
            }, duration);
        }

        window.showToast = showToast;

        function pollReminderAlarms() {
            fetch('<?php echo BASE_URL; ?>modules/reminders/alarm_feed', {
                method: 'GET',
                credentials: 'same-origin',
                skipGlobalLoader: true,
                headers: {
                    'Accept': 'application/json'
                }
            })
            .then(function (response) {
                if (!response.ok) {
                    throw new Error('Alarm feed unavailable');
                }
                return response.json();
            })
            .then(function (payload) {
                if (!payload || !payload.success || !Array.isArray(payload.alarms) || payload.alarms.length === 0) {
                    return;
                }

                playAppSound('reminder');
                payload.alarms.forEach(function (alarm, index) {
                    const parts = ['Reminder: ' + (alarm.title || 'Upcoming reminder')];
                    if (alarm.project_name) {
                        parts.push('Project: ' + alarm.project_name);
                    }
                    if (alarm.due_label) {
                        parts.push(alarm.due_label);
                    }

                    showToast(parts.join(' - '), 'warning', {
                        sound: false,
                        duration: 8000 + (index * 500)
                    });
                });
            })
            .catch(function () {
            });
        }

        // AUTO-DETECT URL PARAMS FOR FEEDBACK
        $(document).ready(function() {
            const urlParams = new URLSearchParams(window.location.search);
            if (urlParams.has('success')) {
                const msg = urlParams.get('success').replace(/_/g, ' ');
                showToast(msg.charAt(0).toUpperCase() + msg.slice(1), 'success');
            }
            if (urlParams.has('error')) {
                const msg = urlParams.get('error').replace(/_/g, ' ');
                showToast(msg.charAt(0).toUpperCase() + msg.slice(1), 'error');
            }

            <?php if (!empty($_SESSION['user_id']) && !empty($reminderModuleAvailable)): ?>
            setTimeout(pollReminderAlarms, 12000);
            setInterval(pollReminderAlarms, 60000);
            <?php endif; ?>
        });
    </script>
    <?php if (!empty($_SESSION['user_id'])): ?>
    <?php
        require_once __DIR__ . '/../libs/BrowserPushManager.php';
        $footerBrowserPushManager = new BrowserPushManager($pdo);
        $browserPushConfig = $footerBrowserPushManager->getPublicConfig();
    ?>
    <script>
        window.PRESS_ERP_PUSH_CONFIG = <?php echo json_encode($browserPushConfig, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
    </script>
    <script src="<?php echo asset('js/push-notifications.js'); ?>"></script>
    <?php endif; ?>
</body>
</html>
