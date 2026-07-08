<?php
$role = $_SESSION['role'] ?? 'Account';
$departmentName = $_SESSION['department'] ?? 'Government Press';
$appDisplayName = function_exists('get_setting') ? (string) get_setting('system_app_name', APP_NAME) : APP_NAME;
$routePath = str_replace('\\', '/', $_SERVER['PHP_SELF'] ?? '');
$routeSegments = array_values(array_filter(explode('/', trim($routePath, '/'))));
$moduleIndex = array_search('modules', $routeSegments, true);
$moduleSlug = $moduleIndex !== false ? ($routeSegments[$moduleIndex + 1] ?? '') : '';
$resourceSlug = $moduleIndex !== false ? ($routeSegments[$moduleIndex + 2] ?? '') : '';
$viewSlug = basename($routePath, '.php');

$formatTopbarLabel = static function (string $value): string {
    $value = trim(str_replace(['_', '-'], ' ', $value));
    if ($value === '') {
        return 'Workspace';
    }

    $label = ucwords($value);
    $replacements = [
        'Hr' => 'HR',
        'Sms' => 'SMS',
        'Api' => 'API',
        'Erp' => 'ERP',
    ];

    return $replacements[$label] ?? $label;
};

$moduleLabel = $formatTopbarLabel($moduleSlug);
$resourceLabel = '';
if ($resourceSlug !== '' && pathinfo($resourceSlug, PATHINFO_EXTENSION) === '') {
    $resourceLabel = $formatTopbarLabel($resourceSlug);
}

$actionViews = ['create', 'edit', 'view', 'comment', 'review'];
$pageTitle = $resourceLabel !== ''
    ? $resourceLabel
    : ((in_array($viewSlug, array_merge(['index', 'list'], $actionViews), true)) ? $moduleLabel : $formatTopbarLabel($viewSlug));

if ($moduleSlug === 'collaboration') {
    if ($viewSlug === 'invitation_respond') {
        $pageTitle = 'Respond to invitation';
    } elseif ($viewSlug === 'invitations') {
        $pageTitle = 'Team invitations';
    }
}

$showDepartmentChip = $departmentName !== '' && strcasecmp($departmentName, 'Government Press') !== 0;
?>

<header class="app-topbar hidden md:grid">
    <div class="topbar-context">
        <button id="sidebar-toggle" type="button" class="topbar-ghost-button text-gray-500" aria-controls="sidebar" aria-expanded="true" aria-label="Collapse navigation">
            <i data-lucide="panel-left-close" aria-hidden="true"></i>
        </button>

        <div class="topbar-heading min-w-0">
            <div class="topbar-heading-row">
                <h2 class="topbar-title"><?php echo htmlspecialchars($pageTitle); ?></h2>
                <?php if ($showDepartmentChip): ?>
                    <span class="topbar-chip topbar-context-chip truncate"><?php echo htmlspecialchars($departmentName); ?></span>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <form method="POST" action="<?php echo BASE_URL; ?>modules/dashboard/index" class="topbar-search">
        <i data-lucide="search" class="topbar-search-icon" aria-hidden="true"></i>
        <input
            type="search"
            name="search_query"
            class="topbar-search-input"
            placeholder="Search invoices, estimations, users, or tasks"
            autocomplete="off"
        >
    </form>

    <div class="topbar-actions">
        <div class="relative flex items-center gap-2" id="notifications-wrapper">
            <button
                id="notif-sound-toggle"
                type="button"
                title="Mute notification sounds"
                class="topbar-ghost-button text-blue-600"
                aria-label="Toggle notification sound"
            >
                <i data-lucide="volume-2" aria-hidden="true"></i>
            </button>

            <button id="notif-toggle" type="button" class="topbar-ghost-button relative" aria-label="Open notifications">
                <i data-lucide="bell" aria-hidden="true"></i>
                <?php
                $notifManager = isset($mobile_notifManager) ? $mobile_notifManager : null;
                $unreadCount = isset($mobile_unreadCount) ? (int) $mobile_unreadCount : 0;
                if ($unreadCount > 0):
                ?>
                    <span class="notif-badge absolute -top-1 -right-1 h-5 px-1.5 bg-red-500 text-white text-[0.65rem] flex items-center justify-center rounded-full font-bold shadow-md ring-2 ring-white" style="min-width: 1.25rem;">
                        <?php echo $unreadCount > 99 ? '99+' : $unreadCount; ?>
                    </span>
                <?php endif; ?>
            </button>

            <div id="notif-dropdown" class="dropdown-panel dropdown-wide hidden absolute right-0 top-full mt-3 z-50 overflow-hidden">
                <div class="p-4 border-b border-gray-100 flex justify-between items-center bg-gray-50">
                    <h3 class="font-bold text-gray-700">Notifications</h3>
                    <a href="<?php echo BASE_URL; ?>modules/user-account/notification_history" class="text-xs text-blue-600 hover:underline">View all</a>
                </div>
                <div class="max-h-96 overflow-y-auto">
                    <?php
                    $recentNotifs = isset($mobile_recentNotifs) ? $mobile_recentNotifs : [];
                    if (empty($recentNotifs)):
                    ?>
                        <div class="p-8 text-center text-gray-400 notif-dropdown-empty">
                            <i data-lucide="bell-off" aria-hidden="true"></i>
                            <p>No new notifications</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($recentNotifs as $notif): ?>
                            <a href="<?php echo BASE_URL . ($notif['link'] ?? '#'); ?>" class="block p-4 border-b border-gray-50 hover:bg-blue-50 transition notif-item" data-id="<?php echo $notif['id']; ?>">
                                <div class="flex items-start">
                                    <div class="flex-shrink-0 mr-3">
                                        <div class="w-9 h-9 rounded-xl bg-blue-100 flex items-center justify-center text-blue-600">
                                            <i class="notif-row-icon" data-lucide="<?php
                                                switch ($notif['type']) {
                                                    case 'message':
                                                        echo 'mail';
                                                        break;
                                                    case 'task':
                                                        echo 'clipboard-list';
                                                        break;
                                                    case 'reminder':
                                                        echo 'calendar-clock';
                                                        break;
                                                    case 'security':
                                                        echo 'shield';
                                                        break;
                                                    default:
                                                        echo 'bell';
                                                        break;
                                                }
                                                ?>" aria-hidden="true"></i>
                                        </div>
                                    </div>
                                    <div class="min-w-0">
                                        <p class="text-sm font-semibold text-gray-800"><?php echo htmlspecialchars($notif['title']); ?></p>
                                        <p class="text-xs text-gray-600 mt-1"><?php echo htmlspecialchars($notif['description']); ?></p>
                                        <p class="text-xs text-gray-400 mt-1"><?php echo date('M d, H:i', strtotime($notif['created_at'])); ?></p>
                                    </div>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
                <?php if ($unreadCount > 0): ?>
                    <div class="p-3 bg-gray-50 text-center border-t border-gray-100">
                        <button class="text-sm text-gray-600 hover:text-blue-600 transition font-semibold" id="mark-all-read" type="button">
                            Mark all as read
                        </button>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <a id="msg-link" href="<?php echo BASE_URL; ?>modules/messaging/inbox" class="topbar-ghost-button relative" aria-label="Open messages">
            <i data-lucide="mail" aria-hidden="true"></i>
            <?php if (!empty($unreadMessages)): ?>
                <span id="msg-badge" class="absolute -top-1 -right-1 h-5 px-1.5 bg-red-500 text-white text-[0.65rem] flex items-center justify-center rounded-full font-bold shadow-md ring-2 ring-white" style="min-width: 1.25rem;"><?php echo $unreadMessages > 99 ? '99+' : $unreadMessages; ?></span>
            <?php endif; ?>
        </a>

        <?php if (!empty($reminderModuleAvailable)): ?>
        <a href="<?php echo htmlspecialchars($remindersHubUrl ?? (BASE_URL . 'modules/reminders/index')); ?>" class="topbar-ghost-button relative" aria-label="Open reminders">
            <i data-lucide="calendar-clock" aria-hidden="true"></i>
            <?php if (!empty($activeReminderCount)): ?>
                <span class="absolute -top-1 -right-1 h-5 px-1.5 bg-brand text-white text-[0.65rem] flex items-center justify-center rounded-full font-bold shadow-md ring-2 ring-white" style="min-width: 1.25rem; background-color: #0f766e;">
                    <?php echo $activeReminderCount > 99 ? '99+' : $activeReminderCount; ?>
                </span>
            <?php endif; ?>
        </a>
        <?php endif; ?>

        <div class="relative">
            <button id="user-menu-toggle" type="button" class="surface-button topbar-user-button">
                <span class="topbar-avatar" style="overflow:hidden;">
                    <?php
                    $topbarPhoto = $_SESSION['user_photo'] ?? null;
                    if (!empty($topbarPhoto) && $topbarPhoto !== 'default.png'):
                    ?>
                        <img src="<?php echo htmlspecialchars(BASE_URL . ltrim($topbarPhoto, '/')); ?>" alt="" style="width:100%;height:100%;object-fit:cover;">
                    <?php else: ?>
                        <?php echo isset($_SESSION['user_name']) ? strtoupper(substr($_SESSION['user_name'], 0, 1)) : 'U'; ?>
                    <?php endif; ?>
                </span>
                <span class="topbar-user-name"><?php echo isset($_SESSION['user_name']) ? htmlspecialchars($_SESSION['user_name']) : 'User'; ?></span>
                <i data-lucide="chevron-down" class="text-gray-500" aria-hidden="true"></i>
            </button>

            <div id="user-menu-dropdown" class="dropdown-panel hidden absolute right-0 top-full mt-3 w-56 z-50">
                <a href="<?php echo BASE_URL; ?>modules/user-account/profile" class="flex items-center space-x-3 px-4 py-3 text-gray-700 hover:bg-blue-50 rounded-t-2xl transition">
                    <i data-lucide="user" class="text-sm" aria-hidden="true"></i>
                    <span>Profile</span>
                </a>
                <a href="<?php echo BASE_URL; ?>modules/user-account/security" class="flex items-center space-x-3 px-4 py-3 text-gray-700 hover:bg-blue-50 transition">
                    <i data-lucide="shield" class="text-sm" aria-hidden="true"></i>
                    <span>Security</span>
                </a>
                <a href="<?php echo BASE_URL; ?>modules/user-account/notifications" class="flex items-center space-x-3 px-4 py-3 text-gray-700 hover:bg-blue-50 transition">
                    <i data-lucide="bell" class="text-sm" aria-hidden="true"></i>
                    <span>Notifications</span>
                </a>
                <a href="<?php echo BASE_URL; ?>modules/user-account/tasks" class="flex items-center space-x-3 px-4 py-3 text-gray-700 hover:bg-blue-50 transition">
                    <i data-lucide="clipboard-list" class="text-sm" aria-hidden="true"></i>
                    <span>My tasks</span>
                </a>
                <?php if (!empty($reminderModuleAvailable)): ?>
                <a href="<?php echo htmlspecialchars($remindersHubUrl ?? (BASE_URL . 'modules/reminders/index')); ?>" class="flex items-center space-x-3 px-4 py-3 text-gray-700 hover:bg-blue-50 transition">
                    <i data-lucide="calendar-clock" class="text-sm" aria-hidden="true"></i>
                    <span>Reminders</span>
                </a>
                <?php endif; ?>
                <a href="<?php echo BASE_URL; ?>modules/auth/logout" class="flex items-center space-x-3 px-4 py-3 text-red-600 hover:bg-red-50 rounded-b-2xl transition">
                    <i data-lucide="log-out" class="text-sm" aria-hidden="true"></i>
                    <span>Log out</span>
                </a>
            </div>
        </div>
    </div>
</header>

<audio id="notif-sound" preload="auto" aria-hidden="true">
    <source src="<?php echo asset('sounds/soundreality-notification-center-443093.mp3'); ?>" type="audio/mpeg">
</audio>

<script>
(function () {
    const SOUND_KEY = 'govpress_notif_sound_enabled';
    let soundEnabled = localStorage.getItem(SOUND_KEY) !== 'false';
    let lastUnreadCount = <?php echo (int) $unreadCount; ?>;

    const notifSound = document.getElementById('notif-sound');
    const soundToggle = document.getElementById('notif-sound-toggle');
    const notifToggle = document.getElementById('notif-toggle');
    const notifDropdown = document.getElementById('notif-dropdown');
    const userMenuToggle = document.getElementById('user-menu-toggle');
    const userMenuDropdown = document.getElementById('user-menu-dropdown');
    const notificationsWrapper = document.getElementById('notifications-wrapper');

    function updateSoundToggleUi() {
        if (!soundToggle) {
            return;
        }

        soundToggle.innerHTML = '<i data-lucide="' + (soundEnabled ? 'volume-2' : 'volume-x') + '" aria-hidden="true"></i>';
        soundToggle.title = soundEnabled ? 'Mute notification sounds' : 'Enable notification sounds';
        soundToggle.classList.toggle('text-blue-600', soundEnabled);
        soundToggle.classList.toggle('text-gray-400', !soundEnabled);
        if (typeof window.refreshAppShellIcons === 'function') {
            window.refreshAppShellIcons();
        }
    }

    function playNotificationSound() {
        if (window.PressErpPush && typeof window.PressErpPush.wasRecentDelivery === 'function' && window.PressErpPush.wasRecentDelivery()) {
            return;
        }

        if (typeof window.playAppSound === 'function') {
            window.playAppSound('message');
            return;
        }

        if (!soundEnabled || !notifSound) {
            return;
        }

        notifSound.currentTime = 0;
        notifSound.play().catch(function () {
        });
    }

    function updateBadge(count) {
        if (!notifToggle) {
            return;
        }

        let badge = notifToggle.querySelector('.notif-badge');
        if (count > 0) {
            const label = count > 99 ? '99+' : count;
            if (!badge) {
                badge = document.createElement('span');
                badge.className = 'notif-badge absolute -top-1 -right-1 h-5 px-1.5 bg-red-500 text-white text-[0.65rem] flex items-center justify-center rounded-full font-bold shadow-md ring-2 ring-white';
                badge.style.minWidth = '1.25rem';
                notifToggle.appendChild(badge);
            }
            badge.textContent = label;
        } else if (badge) {
            badge.remove();
        }
    }

    function updateMsgBadge(count) {
        const msgLink = document.getElementById('msg-link');
        if (!msgLink) {
            return;
        }

        let badge = document.getElementById('msg-badge');
        if (count > 0) {
            const label = count > 99 ? '99+' : count;
            if (!badge) {
                badge = document.createElement('span');
                badge.id = 'msg-badge';
                badge.className = 'absolute -top-1 -right-1 h-5 px-1.5 bg-red-500 text-white text-[0.65rem] flex items-center justify-center rounded-full font-bold shadow-md ring-2 ring-white';
                badge.style.minWidth = '1.25rem';
                msgLink.appendChild(badge);
            }
            badge.textContent = label;
        } else if (badge) {
            badge.remove();
        }
    }

    function buildNotifItemHtml(notif) {
        var iconMap = { message: 'mail', task: 'clipboard-list', task_assignment: 'clipboard-list', security: 'shield', reminder: 'calendar-clock' };
        var icon = iconMap[notif.type] || 'bell';
        var timeAgo = notif.created_at || '';
        return '<a href="<?php echo BASE_URL; ?>' + (notif.link || '#') + '" class="block p-4 border-b border-gray-50 hover:bg-blue-50 transition notif-item" data-id="' + notif.id + '">'
            + '<div class="flex items-start">'
            + '<div class="flex-shrink-0 mr-3"><div class="w-9 h-9 rounded-xl bg-blue-100 flex items-center justify-center text-blue-600"><i class="notif-row-icon" data-lucide="' + icon + '" aria-hidden="true"></i></div></div>'
            + '<div class="flex-1 min-w-0"><p class="text-sm font-semibold text-gray-800 truncate">' + notif.title + '</p>'
            + '<p class="text-xs text-gray-500 mt-0.5 line-clamp-2">' + (notif.description || '') + '</p>'
            + '<p class="text-xs text-gray-400 mt-1">' + timeAgo + '</p></div></div></a>';
    }

    function refreshNotifDropdown(notifications, count) {
        var list = notifDropdown ? notifDropdown.querySelector('.max-h-96') : null;
        if (!list) {
            return;
        }

        if (!notifications || notifications.length === 0) {
            list.innerHTML = '<div class="p-8 text-center text-gray-400 notif-dropdown-empty"><i data-lucide="bell-off" aria-hidden="true"></i><p>No new notifications</p></div>';
        } else {
            var html = '';
            notifications.forEach(function (n) { html += buildNotifItemHtml(n); });
            list.innerHTML = html;

            // Re-attach mark-as-read click handlers on the new items
            list.querySelectorAll('.notif-item').forEach(function (item) {
                item.addEventListener('click', function (event) {
                    event.preventDefault();
                    var notifId = this.dataset.id;
                    var target = this.getAttribute('href');
                    $.post('<?php echo BASE_URL; ?>modules/user-account/notification_action', {
                        action: 'mark_as_read', id: notifId
                    }, function () { window.location.href = target; }, 'json')
                    .fail(function () { window.location.href = target; });
                });
            });
        }

        if (typeof window.refreshAppShellIcons === 'function') {
            window.refreshAppShellIcons();
        }
    }

    // ── SSE connection ──────────────────────────────────────────────────────
    function startSSE() {
        if (!window.EventSource) {
            // Fallback for browsers without SSE support (extremely rare)
            setTimeout(function () {
                pollNotificationsFallback();
                setInterval(pollNotificationsFallback, 5000);
            }, 2000);
            return;
        }

        var es = new EventSource('<?php echo BASE_URL; ?>modules/user-account/sse_stream');

        es.addEventListener('notification', function (e) {
            var data = JSON.parse(e.data);
            var newCount = Number(data.count || 0);
            var skipRefresh = window.PressErpPush
                && typeof window.PressErpPush.wasRecentDelivery === 'function'
                && window.PressErpPush.wasRecentDelivery();

            if (newCount > lastUnreadCount && !skipRefresh) {
                playNotificationSound();
                $.get('<?php echo BASE_URL; ?>modules/user-account/notification_action',
                    { action: 'get_latest' },
                    function (r) {
                        if (r && r.success) {
                            refreshNotifDropdown(r.notifications, r.count);
                        }
                    }, 'json');
            } else if (newCount > lastUnreadCount && notifDropdown && !notifDropdown.classList.contains('hidden')) {
                $.get('<?php echo BASE_URL; ?>modules/user-account/notification_action',
                    { action: 'get_latest' },
                    function (r) {
                        if (r && r.success) {
                            refreshNotifDropdown(r.notifications, r.count);
                        }
                    }, 'json');
            }
            if (newCount !== lastUnreadCount) {
                updateBadge(newCount);
                lastUnreadCount = newCount;
            }
        });

        es.addEventListener('message', function (e) {
            var data = JSON.parse(e.data);
            updateMsgBadge(Number(data.count || 0));
        });

        es.onerror = function () {
            es.close();
            // EventSource auto-reconnects, but we add a manual back-off too
            setTimeout(startSSE, 5000);
        };
    }

    // Fallback AJAX poll used only when SSE is unavailable
    function pollNotificationsFallback() {
        $.get('<?php echo BASE_URL; ?>modules/user-account/notification_action', { action: 'get_unread_count' }, function (response) {
            if (!response || !response.success) {
                return;
            }

            var newCount = Number(response.count || 0);
            if (newCount > lastUnreadCount) {
                playNotificationSound();
            }
            if (newCount !== lastUnreadCount) {
                updateBadge(newCount);
                lastUnreadCount = newCount;
            }
        }, 'json');
    }

    if (soundToggle) {
        soundToggle.addEventListener('click', function (event) {
            event.stopPropagation();
            soundEnabled = !soundEnabled;
            localStorage.setItem(SOUND_KEY, soundEnabled ? 'true' : 'false');
            updateSoundToggleUi();
        });
    }
    updateSoundToggleUi();
    startSSE();

    if (notifToggle && notifDropdown) {
        notifToggle.addEventListener('click', function (event) {
            event.stopPropagation();
            notifDropdown.classList.toggle('hidden');
            if (userMenuDropdown) {
                userMenuDropdown.classList.add('hidden');
            }
        });
    }

    if (userMenuToggle && userMenuDropdown) {
        userMenuToggle.addEventListener('click', function (event) {
            event.stopPropagation();
            userMenuDropdown.classList.toggle('hidden');
            if (notifDropdown) {
                notifDropdown.classList.add('hidden');
            }
        });
    }

    document.querySelectorAll('.notif-item').forEach(function (item) {
        item.addEventListener('click', function (event) {
            event.preventDefault();
            const notifId = this.dataset.id;
            const target = this.getAttribute('href');

            $.post('<?php echo BASE_URL; ?>modules/user-account/notification_action', {
                action: 'mark_as_read',
                id: notifId
            }, function () {
                window.location.href = target;
            }, 'json').fail(function () {
                window.location.href = target;
            });
        });
    });

    const markAllBtn = document.getElementById('mark-all-read');
    if (markAllBtn) {
        markAllBtn.addEventListener('click', function () {
            $.post('<?php echo BASE_URL; ?>modules/user-account/notification_action', {
                action: 'mark_all_read'
            }, function (response) {
                if (response && response.success) {
                    updateBadge(0);
                    lastUnreadCount = 0;
                    const list = document.querySelector('#notif-dropdown .max-h-96');
                    if (list) {
                        list.innerHTML = '<div class="p-8 text-center text-gray-400 notif-dropdown-empty"><i data-lucide="bell-off" aria-hidden="true"></i><p>No new notifications</p></div>';
                        if (typeof window.refreshAppShellIcons === 'function') {
                            window.refreshAppShellIcons();
                        }
                    }
                    markAllBtn.parentElement.remove();
                } else if (typeof showToast === 'function') {
                    showToast((response && response.message) ? response.message : 'Unable to update notifications.', 'error');
                }
            }, 'json');
        });
    }

    document.addEventListener('click', function (event) {
        if (notificationsWrapper && !notificationsWrapper.contains(event.target) && notifDropdown) {
            notifDropdown.classList.add('hidden');
        }

        if (userMenuToggle && !userMenuToggle.parentElement.contains(event.target) && userMenuDropdown) {
            userMenuDropdown.classList.add('hidden');
        }
    });
})();
</script>
