<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/branding_helper.php';
$remindersHubUrl = BASE_URL . 'modules/reminders/index';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8"/>
    <meta content="width=device-width, initial-scale=1.0" name="viewport"/>
    <title><?php echo htmlspecialchars(defined('APP_NAME') ? APP_NAME : 'Gov Press ERP'); ?></title>
    <link rel="icon" type="image/png" href="<?php echo htmlspecialchars(system_branding_resolved_url('favicon')); ?>">
    <?php $includeAppShellCss = true; ?>
    <?php include __DIR__ . '/head_assets.php'; ?>
</head>
<body class="app-shell flex min-h-screen">
<?php include __DIR__ . '/sidebar.php'; ?>
    <?php
    $mobile_notifManager = null;
    $mobile_unreadCount = 0;
    $mobile_recentNotifs = [];
    $unreadMessages = 0;
    $reminderModuleAvailable = false;
    $activeReminderCount = 0;
    $appDisplayName = function_exists('get_setting') ? (string) get_setting('system_app_name', APP_NAME) : APP_NAME;

    if (isset($_SESSION['user_id'])) {
        require_once __DIR__ . '/../libs/NotificationManager.php';
        require_once __DIR__ . '/reminder_helper.php';
        try {
            $mobile_notifManager = new NotificationManager($pdo);
            $mobile_unreadCount = (int) $mobile_notifManager->getUnreadCount($_SESSION['user_id']);
            $mobile_recentNotifs = $mobile_notifManager->getUnread($_SESSION['user_id'], 5);
        } catch (Exception $e) {
            $mobile_notifManager = null;
            $mobile_unreadCount = 0;
            $mobile_recentNotifs = [];
        }

        try {
            $msgStmt = $pdo->prepare("SELECT COUNT(*) FROM messages WHERE recipient_id = :recipient_id AND is_read = FALSE");
            $msgStmt->execute(['recipient_id' => (int) $_SESSION['user_id']]);
            $unreadMessages = (int) $msgStmt->fetchColumn();
        } catch (Exception $e) {
            $unreadMessages = 0;
        }

        try {
            $reminderModuleAvailable = reminder_module_ready($pdo);
            $remindersHubUrl = reminders_hub_entry_url();
            if ($reminderModuleAvailable) {
                $reminderBootstrapAt = (int) ($_SESSION['reminder_backfill_bootstrapped_at'] ?? 0);
                if ($reminderBootstrapAt < (time() - 900)) {
                    backfill_task_assignment_reminders_for_user($pdo, (int) $_SESSION['user_id']);
                    $_SESSION['reminder_backfill_bootstrapped_at'] = time();
                }
                $reminderCounts = fetch_reminder_counts_for_user($pdo, (int) $_SESSION['user_id']);
                $activeReminderCount = (int) ($reminderCounts['active'] ?? 0);
            }
        } catch (Exception $e) {
            $reminderModuleAvailable = false;
            $activeReminderCount = 0;
        }
    }

    $mobile_department = isset($_SESSION['department']) ? htmlspecialchars($_SESSION['department']) : 'Government Press';
    ?>

    <div class="app-mobilebar md:hidden fixed inset-x-0 top-0">
        <div class="app-mobilebar-inner">
            <div class="flex items-center gap-3 min-w-0">
                <button
                    id="mobile-menu-toggle"
                    type="button"
                    class="surface-icon-button"
                    aria-controls="sidebar"
                    aria-expanded="false"
                    aria-label="Open navigation"
                >
                    <i data-lucide="menu" aria-hidden="true"></i>
                </button>
                <a href="<?php echo BASE_URL; ?>modules/dashboard/index" class="mobile-brand">
                    <span class="sidebar-brand-mark">
                        <img src="<?php echo htmlspecialchars(system_branding_resolved_url('favicon')); ?>" alt="<?php echo htmlspecialchars($appDisplayName); ?>" class="h-7 w-7 object-contain">
                    </span>
                    <span class="mobile-brand-copy truncate">
                        <span class="block text-sm font-bold text-gray-900 truncate"><?php echo htmlspecialchars($appDisplayName); ?></span>
                        <span class="block text-xs text-gray-500 truncate"><?php echo $mobile_department; ?></span>
                    </span>
                </a>
            </div>

            <div class="flex items-center gap-2.5 flex-shrink-0">
                <button id="mobile-notif-toggle" type="button" class="surface-icon-button relative" aria-label="Open notifications">
                    <i data-lucide="bell" aria-hidden="true"></i>
                    <?php if ($mobile_unreadCount > 0): ?>
                        <span class="absolute -top-1 -right-1 h-5 px-1 bg-red-500 text-white text-xs flex items-center justify-center rounded-full font-bold" style="min-width: 1.25rem;">
                            <?php echo $mobile_unreadCount > 99 ? '99+' : $mobile_unreadCount; ?>
                        </span>
                    <?php endif; ?>
                </button>

                <a href="<?php echo BASE_URL; ?>modules/messaging/inbox" class="surface-icon-button relative" aria-label="Open messages">
                    <i data-lucide="mail" aria-hidden="true"></i>
                    <?php if ($unreadMessages > 0): ?>
                                <span class="absolute -top-1 -right-1 h-5 px-1 bg-red-500 text-white text-xs flex items-center justify-center rounded-full font-bold" style="min-width: 1.25rem;">
                                    <?php echo $unreadMessages > 99 ? '99+' : $unreadMessages; ?>
                                </span>
                    <?php endif; ?>
                </a>

                <?php if ($reminderModuleAvailable): ?>
                <a href="<?php echo htmlspecialchars($remindersHubUrl); ?>" class="surface-icon-button relative" aria-label="Open reminders">
                    <i data-lucide="calendar-clock" aria-hidden="true"></i>
                    <?php if ($activeReminderCount > 0): ?>
                        <span class="absolute -top-1 -right-1 h-5 px-1 bg-emerald-500 text-white text-xs flex items-center justify-center rounded-full font-bold" style="min-width: 1.25rem;">
                            <?php echo $activeReminderCount > 99 ? '99+' : $activeReminderCount; ?>
                        </span>
                    <?php endif; ?>
                </a>
                <?php endif; ?>

                <a href="<?php echo BASE_URL; ?>modules/auth/logout" class="surface-icon-button text-red-600" aria-label="Log out">
                    <i data-lucide="log-out" aria-hidden="true"></i>
                </a>
            </div>
        </div>
        <form method="POST" action="<?php echo BASE_URL; ?>modules/dashboard/index" class="app-mobilebar-search">
            <i data-lucide="search" class="app-mobilebar-search-icon" aria-hidden="true"></i>
            <input
                type="search"
                name="search_query"
                class="app-mobilebar-search-input"
                placeholder="Search invoices, estimations, tasks..."
                autocomplete="off"
                value="<?php echo htmlspecialchars(trim((string) ($_POST['search_query'] ?? ''))); ?>"
            >
        </form>
    </div>

    <div id="mobile-notif-dropdown" class="dropdown-panel dropdown-wide md:hidden hidden fixed top-20 right-4 z-50 overflow-hidden">
        <div class="p-4 border-b border-gray-100 flex justify-between items-center bg-gray-50">
            <h3 class="font-bold text-gray-700">Notifications</h3>
            <a href="<?php echo BASE_URL; ?>modules/user-account/notification_history" class="text-xs text-blue-600 hover:underline">View all</a>
        </div>
        <div class="max-h-80 overflow-y-auto">
            <?php if (empty($mobile_recentNotifs)): ?>
                <div class="p-8 text-center text-gray-400 notif-dropdown-empty">
                    <i data-lucide="bell-off" aria-hidden="true"></i>
                    <p>No new notifications</p>
                </div>
            <?php else: ?>
                <?php foreach ($mobile_recentNotifs as $notif): ?>
                    <a href="<?php echo BASE_URL . ($notif['link'] ?? '#'); ?>" class="block p-4 border-b border-gray-50 hover:bg-blue-50 transition">
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
                                <p class="text-gray-400 mt-1 text-xs"><?php echo date('M d, H:i', strtotime($notif['created_at'])); ?></p>
                            </div>
                        </div>
                    </a>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <div id="sidebar-overlay" class="app-overlay fixed inset-0 z-30 hidden"></div>

    <div class="app-main-shell flex-1 flex flex-col md:ml-0 transition-all duration-300 min-w-0">
        <?php include __DIR__ . '/topbar.php'; ?>
        <main class="app-page flex-1 mt-28 md:mt-0 overflow-y-auto">
