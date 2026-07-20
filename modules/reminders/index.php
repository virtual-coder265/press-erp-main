<?php
require_once __DIR__ . '/../../config/app.php';
checkAuth();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/reminder_helper.php';

function reminder_dashboard_excerpt(?string $text, int $limit = 120): string
{
    $text = trim(preg_replace('/\s+/', ' ', (string) $text));
    if ($text === '') {
        return 'No additional note added yet.';
    }

    if (strlen($text) <= $limit) {
        return $text;
    }

    return rtrim(substr($text, 0, $limit - 3)) . '...';
}

function reminder_dashboard_priority_rank(?string $priority): int
{
    $map = [
        'Urgent' => 4,
        'High' => 3,
        'Medium' => 2,
        'Low' => 1,
    ];

    return $map[(string) $priority] ?? 0;
}

function reminder_dashboard_matches_scope(array $reminder, string $scope): bool
{
    $status = (string) ($reminder['status'] ?? 'active');
    $dueTone = (string) ($reminder['due_meta']['tone'] ?? 'neutral');
    $priority = (string) ($reminder['priority'] ?? 'Medium');
    $isTaskLinked = !empty($reminder['is_task_linked']);
    $isPinned = !empty($reminder['pinned']) && !$isTaskLinked;
    $hasSchedule = !empty($reminder['due_on']) || !empty($reminder['remind_at']);

    switch ($scope) {
        case 'my_day':
            return $status === 'active' && in_array($dueTone, ['warning', 'danger'], true);
        case 'important':
            return $status === 'active' && in_array($priority, ['High', 'Urgent'], true);
        case 'planned':
            return $status === 'active' && $hasSchedule;
        case 'assigned':
            return $status === 'active' && $isTaskLinked;
        case 'all':
            return $status === 'active';
        case 'completed':
            return $status === 'completed';
        case 'overdue':
            return $status === 'active' && $dueTone === 'danger';
        case 'personal':
            return $status === 'active' && !$isTaskLinked;
        case 'task_linked':
            return $isTaskLinked;
        case 'pinned':
            return $status === 'active' && $isPinned;
        case 'archived':
            return $status === 'dismissed';
        default:
            return $status === 'active';
    }
}

function reminder_dashboard_matches_source(array $reminder, string $sourceFilter): bool
{
    if ($sourceFilter === 'all') {
        return true;
    }

    return ($reminder['source'] ?? 'self') === $sourceFilter;
}

function reminder_dashboard_compare(array $left, array $right, string $sort): int
{
    if ($sort === 'priority') {
        $priorityCompare = reminder_dashboard_priority_rank($right['priority'] ?? null)
            <=> reminder_dashboard_priority_rank($left['priority'] ?? null);
        if ($priorityCompare !== 0) {
            return $priorityCompare;
        }
    } elseif ($sort === 'updated') {
        $leftUpdated = strtotime((string) ($left['updated_at'] ?? '')) ?: 0;
        $rightUpdated = strtotime((string) ($right['updated_at'] ?? '')) ?: 0;
        if ($leftUpdated !== $rightUpdated) {
            return $rightUpdated <=> $leftUpdated;
        }
    } elseif ($sort === 'due') {
        $leftDue = reminder_effective_due_datetime($left['due_on'] ?? null, $left['remind_at'] ?? null);
        $rightDue = reminder_effective_due_datetime($right['due_on'] ?? null, $right['remind_at'] ?? null);
        $leftDueTs = $leftDue ? (strtotime($leftDue) ?: PHP_INT_MAX) : PHP_INT_MAX;
        $rightDueTs = $rightDue ? (strtotime($rightDue) ?: PHP_INT_MAX) : PHP_INT_MAX;
        if ($leftDueTs !== $rightDueTs) {
            return $leftDueTs <=> $rightDueTs;
        }
    } else {
        $leftWeight = (int) ($left['due_meta']['sort_weight'] ?? 99);
        $rightWeight = (int) ($right['due_meta']['sort_weight'] ?? 99);
        if ($leftWeight !== $rightWeight) {
            return $leftWeight <=> $rightWeight;
        }

        $pinCompare = (int) !empty($right['pinned']) <=> (int) !empty($left['pinned']);
        if ($pinCompare !== 0) {
            return $pinCompare;
        }
    }

    $leftDue = reminder_effective_due_datetime($left['due_on'] ?? null, $left['remind_at'] ?? null);
    $rightDue = reminder_effective_due_datetime($right['due_on'] ?? null, $right['remind_at'] ?? null);
    $leftDueTs = $leftDue ? (strtotime($leftDue) ?: PHP_INT_MAX) : PHP_INT_MAX;
    $rightDueTs = $rightDue ? (strtotime($rightDue) ?: PHP_INT_MAX) : PHP_INT_MAX;
    if ($leftDueTs !== $rightDueTs) {
        return $leftDueTs <=> $rightDueTs;
    }

    $leftUpdated = strtotime((string) ($left['updated_at'] ?? '')) ?: 0;
    $rightUpdated = strtotime((string) ($right['updated_at'] ?? '')) ?: 0;
    if ($leftUpdated !== $rightUpdated) {
        return $rightUpdated <=> $leftUpdated;
    }

    return strcmp((string) ($left['title'] ?? ''), (string) ($right['title'] ?? ''));
}

function reminder_dashboard_board_bucket(array $reminder): string
{
    $status = (string) ($reminder['status'] ?? 'active');
    $dueTone = (string) ($reminder['due_meta']['tone'] ?? 'neutral');

    if ($status !== 'active') {
        return 'resolved';
    }

    if (in_array($dueTone, ['warning', 'danger'], true)) {
        return 'attention';
    }

    if (!empty($reminder['is_task_linked'])) {
        return 'assigned';
    }

    return 'planned';
}

function reminder_dashboard_timeline(array $reminder): array
{
    $timeline = [];

    if (!empty($reminder['created_at'])) {
        $timeline[] = ['title' => 'Reminder created', 'stamp' => $reminder['created_at']];
    }

    if (!empty($reminder['last_synced_at']) && !empty($reminder['is_task_linked'])) {
        $timeline[] = ['title' => 'Task reminder synced', 'stamp' => $reminder['last_synced_at']];
    }

    if (!empty($reminder['updated_at']) && $reminder['updated_at'] !== ($reminder['created_at'] ?? null)) {
        $timeline[] = ['title' => 'Reminder updated', 'stamp' => $reminder['updated_at']];
    }

    if (!empty($reminder['completed_at'])) {
        $timeline[] = ['title' => 'Marked complete', 'stamp' => $reminder['completed_at']];
    }

    if (!empty($reminder['dismissed_at'])) {
        $timeline[] = ['title' => 'Archived from active board', 'stamp' => $reminder['dismissed_at']];
    }

    if (!empty($reminder['alarm_last_triggered_at'])) {
        $timeline[] = ['title' => 'Alarm last triggered', 'stamp' => $reminder['alarm_last_triggered_at']];
    }

    usort($timeline, static function (array $left, array $right): int {
        return (strtotime((string) ($right['stamp'] ?? '')) ?: 0)
            <=> (strtotime((string) ($left['stamp'] ?? '')) ?: 0);
    });

    return $timeline;
}

$userId = (int) ($_SESSION['user_id'] ?? 0);
$legacyView = trim((string) ($_GET['view'] ?? 'active'));
$scope = trim((string) ($_GET['scope'] ?? ''));
$sourceFilter = trim((string) ($_GET['source'] ?? 'all'));
$search = trim((string) ($_GET['search'] ?? ''));
$sort = trim((string) ($_GET['sort'] ?? 'smart'));
$layout = trim((string) ($_GET['layout'] ?? 'list'));
$detailReminderId = (int) ($_GET['detail'] ?? ($_GET['edit'] ?? 0));

$hub = trim((string) ($_GET['hub'] ?? 'dashboard'));
if ($hub === 'board') {
    $hub = 'dashboard';
}
if (!in_array($hub, ['dashboard', 'calendar'], true)) {
    $hub = 'dashboard';
}

if (!in_array($sourceFilter, ['all', 'self', 'task_assignment'], true)) {
    $sourceFilter = 'all';
}
if (!in_array($sort, ['smart', 'due', 'priority', 'updated'], true)) {
    $sort = 'smart';
}
if (!in_array($layout, ['list', 'grid', 'board'], true)) {
    $layout = 'list';
}

$scopeKeys = ['my_day', 'important', 'planned', 'assigned', 'all', 'completed', 'overdue', 'personal', 'task_linked', 'pinned', 'archived'];
if ($scope === '') {
    if ($legacyView === 'completed') {
        $scope = 'completed';
    } elseif ($legacyView === 'dismissed') {
        $scope = 'archived';
    } elseif ($legacyView === 'all') {
        $scope = 'all';
    } elseif ($sourceFilter === 'task_assignment') {
        $scope = 'assigned';
    } elseif ($sourceFilter === 'self') {
        $scope = 'personal';
    } else {
        $scope = 'my_day';
    }
}
if (!in_array($scope, $scopeKeys, true)) {
    $scope = 'my_day';
}

$calMonth = trim((string) ($_GET['cal_month'] ?? ''));
if (!preg_match('/^\d{4}-\d{2}$/', $calMonth)) {
    $calMonth = date('Y-m');
}
$calendarStart = $calMonth . '-01';
$calendarEnd = date('Y-m-t', strtotime($calendarStart));
$calDayRaw = trim((string) ($_GET['cal_day'] ?? ''));
$todayYmd = date('Y-m-d');
$selectedDay = $calDayRaw;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $selectedDay)) {
    $selectedDay = ($todayYmd >= $calendarStart && $todayYmd <= $calendarEnd) ? $todayYmd : $calendarStart;
} elseif ($selectedDay < $calendarStart || $selectedDay > $calendarEnd) {
    $selectedDay = max($calendarStart, min($selectedDay, $calendarEnd));
}

$calendarView = 'active';
$calendarRows = [];
$calendarByDay = [];
$weekByDay = [];
$weekStart = date('Y-m-d', strtotime('monday this week'));
$weekEnd = date('Y-m-d', strtotime('sunday this week'));

$stats = [
    'active' => 0,
    'due_today' => 0,
    'overdue' => 0,
    'task_linked' => 0,
    'personal' => 0,
    'completed' => 0,
    'dismissed' => 0,
];
$scopeCounts = array_fill_keys($scopeKeys, 0);
$allReminders = [];
$filteredReminders = [];
$selectedReminder = null;
$upcomingAlarms = [];
$alarmActiveCount = 0;
$alarmOffsetOptions = reminder_alarm_offset_options();

$moduleReady = reminder_module_ready($pdo, true);
if ($moduleReady) {
    backfill_task_assignment_reminders_for_user($pdo, $userId);
    $stats = fetch_reminder_counts_for_user($pdo, $userId);
    $allReminders = fetch_reminders_for_user($pdo, $userId, [
        'status' => 'all',
        'source' => 'all',
        'search' => $search,
        'limit' => 200,
    ]);

    foreach ($allReminders as $reminder) {
        foreach ($scopeKeys as $scopeKey) {
            if (reminder_dashboard_matches_scope($reminder, $scopeKey)) {
                $scopeCounts[$scopeKey]++;
            }
        }
    }

    $filteredReminders = array_values(array_filter($allReminders, static function (array $reminder) use ($scope, $sourceFilter): bool {
        return reminder_dashboard_matches_scope($reminder, $scope)
            && reminder_dashboard_matches_source($reminder, $sourceFilter);
    }));

    usort($filteredReminders, static function (array $left, array $right) use ($sort): int {
        return reminder_dashboard_compare($left, $right, $sort);
    });

    if ($detailReminderId > 0) {
        foreach ($allReminders as $candidateReminder) {
            if ((int) ($candidateReminder['id'] ?? 0) === $detailReminderId) {
                $selectedReminder = $candidateReminder;
                break;
            }
        }

        if (!$selectedReminder) {
            $selectedReminder = fetch_user_reminder($pdo, $userId, $detailReminderId);
        }
    }

    $upcomingAlarms = fetch_upcoming_alarm_schedule($pdo, $userId, 5);
    $alarmActiveCount = count_scheduled_active_alarms($pdo, $userId);
}

$selectedReminderEditValue = '';
$selectedReminderAlarmEnabled = 1;
$selectedReminderAlarmOffset = 30;
if ($selectedReminder) {
    $selectedReminderFallbackDatetime = !empty($selectedReminder['remind_at'])
        ? $selectedReminder['remind_at']
        : (!empty($selectedReminder['due_on']) ? ($selectedReminder['due_on'] . ' 09:00:00') : null);
    $selectedReminderEditValue = format_reminder_datetime_local($selectedReminderFallbackDatetime);
    $selectedReminderAlarmEnabled = isset($selectedReminder['alarm_enabled']) ? (int) $selectedReminder['alarm_enabled'] : 1;
    $selectedReminderAlarmOffset = isset($selectedReminder['alarm_offset_minutes'])
        ? (int) $selectedReminder['alarm_offset_minutes']
        : 30;
}

$quickAddDefaultDatetime = date('Y-m-d\TH:i', strtotime('+1 hour'));
$quickAddReminderAt = $scope === 'planned' ? $quickAddDefaultDatetime : '';
$quickAddAlarmEnabled = $quickAddReminderAt !== '' ? 1 : 0;

$scopeMeta = [
    'my_day' => ['icon' => 'wb_sunny', 'title' => 'My Day', 'subtitle' => date('l, F j') . ' - Focus on reminders that need attention now.'],
    'important' => ['icon' => 'priority_high', 'title' => 'Important', 'subtitle' => 'High-priority follow-ups and urgent cards stay at the top here.'],
    'planned' => ['icon' => 'event', 'title' => 'Planned', 'subtitle' => 'Everything with a schedule or target date across your reminder board.'],
    'assigned' => ['icon' => 'assignment_ind', 'title' => 'Assigned to Me', 'subtitle' => 'Task-linked reminders synced from project assignments and workflow deadlines.'],
    'all' => ['icon' => 'inbox', 'title' => 'All Items', 'subtitle' => 'Your full active queue of personal reminders and task-linked follow-ups.'],
    'completed' => ['icon' => 'done_all', 'title' => 'Completed', 'subtitle' => 'Completed reminders stay visible here for quick review and reactivation.'],
    'overdue' => ['icon' => 'warning', 'title' => 'Overdue', 'subtitle' => 'Items that slipped past their target time and should be resolved first.'],
    'personal' => ['icon' => 'sticky_note_2', 'title' => 'Personal', 'subtitle' => 'Private notes, follow-ups, and lightweight reminders that stay off the task register.'],
    'task_linked' => ['icon' => 'assignment', 'title' => 'Task-linked', 'subtitle' => 'Assigned work reminders that mirror task ownership, due dates, and progress.'],
    'pinned' => ['icon' => 'push_pin', 'title' => 'Pinned', 'subtitle' => 'Personal reminders you pinned for fast repeat access during the day.'],
    'archived' => ['icon' => 'inventory_2', 'title' => 'Archived', 'subtitle' => 'Dismissed reminders live here until you need to reopen them.'],
];
$currentScopeMeta = $scopeMeta[$scope] ?? $scopeMeta['my_day'];

$buildReminderPath = static function (array $overrides = []) use ($hub, $scope, $layout, $sort, $sourceFilter, $search, $detailReminderId, $calMonth, $selectedDay): string {
    $params = [
        'hub' => $hub !== 'dashboard' ? $hub : null,
        'scope' => $scope !== 'my_day' ? $scope : null,
        'layout' => $layout !== 'list' ? $layout : null,
        'sort' => $sort !== 'smart' ? $sort : null,
        'source' => $sourceFilter !== 'all' ? $sourceFilter : null,
        'search' => $search !== '' ? $search : null,
        'detail' => $detailReminderId > 0 ? $detailReminderId : null,
        'cal_month' => $hub === 'calendar' ? $calMonth : null,
        'cal_day' => $hub === 'calendar' ? $selectedDay : null,
    ];

    foreach ($overrides as $key => $value) {
        if ($value === false) {
            unset($params[$key]);
            continue;
        }

        $params[$key] = $value;
    }

    $params = array_filter($params, static function ($value): bool {
        return $value !== null && $value !== '';
    });

    return 'modules/reminders/index' . (!empty($params) ? '?' . http_build_query($params) : '');
};
$buildReminderUrl = static function (array $overrides = []) use ($buildReminderPath): string {
    return BASE_URL . $buildReminderPath($overrides);
};

$dashboardPath = $buildReminderPath(['hub' => false, 'detail' => false, 'cal_month' => false, 'cal_day' => false]);
$calendarUrl = $buildReminderUrl(['hub' => 'calendar', 'detail' => false, 'cal_month' => $calMonth, 'cal_day' => $selectedDay]);
$detailRedirectPath = $selectedReminder
    ? $buildReminderPath(['hub' => false, 'detail' => (int) $selectedReminder['id'], 'cal_month' => false, 'cal_day' => false])
    : $dashboardPath;
$baseDashboardPath = $buildReminderPath(['hub' => false, 'detail' => false, 'cal_month' => false, 'cal_day' => false]);

if ($moduleReady && $hub === 'calendar') {
    $calendarRows = fetch_reminders_for_calendar_range($pdo, $userId, $calendarStart, $calendarEnd, [
        'status' => $calendarView,
        'source' => $sourceFilter,
        'search' => $search,
    ]);
    $calendarByDay = group_reminders_by_calendar_day($calendarRows);

    $weekRows = fetch_reminders_for_calendar_range($pdo, $userId, $weekStart, $weekEnd, [
        'status' => $calendarView,
        'source' => $sourceFilter,
        'search' => $search,
    ]);
    $weekByDay = group_reminders_by_calendar_day($weekRows);
}

$summaryCounts = ['due_today' => 0, 'overdue' => 0, 'completed' => 0, 'upcoming' => 0, 'high_priority' => 0, 'task_linked' => 0];
foreach ($filteredReminders as $reminder) {
    $status = (string) ($reminder['status'] ?? 'active');
    $tone = (string) ($reminder['due_meta']['tone'] ?? 'neutral');
    if ($status === 'completed') {
        $summaryCounts['completed']++;
    }
    if ($status === 'active' && $tone === 'warning') {
        $summaryCounts['due_today']++;
    }
    if ($status === 'active' && $tone === 'danger') {
        $summaryCounts['overdue']++;
    }
    if ($status === 'active' && $tone === 'info') {
        $summaryCounts['upcoming']++;
    }
    if ($status === 'active' && in_array((string) ($reminder['priority'] ?? 'Medium'), ['High', 'Urgent'], true)) {
        $summaryCounts['high_priority']++;
    }
    if (!empty($reminder['is_task_linked']) && $status === 'active') {
        $summaryCounts['task_linked']++;
    }
}

$summaryCards = [
    ['icon' => 'today', 'label' => 'Due Today', 'value' => $summaryCounts['due_today'], 'tone' => 'warning'],
    ['icon' => 'warning', 'label' => 'Overdue', 'value' => $summaryCounts['overdue'], 'tone' => 'danger'],
    ['icon' => 'done_all', 'label' => 'Completed', 'value' => $summaryCounts['completed'], 'tone' => 'success'],
    ['icon' => 'upcoming', 'label' => 'Upcoming', 'value' => $summaryCounts['upcoming'], 'tone' => 'info'],
    ['icon' => 'flag', 'label' => 'High Priority', 'value' => $summaryCounts['high_priority'], 'tone' => 'neutral'],
];

$boardColumns = [
    'attention' => ['title' => 'Attention now', 'subtitle' => 'Overdue or due today', 'items' => []],
    'planned' => ['title' => 'Scheduled', 'subtitle' => 'Upcoming personal reminders', 'items' => []],
    'assigned' => ['title' => 'Assigned work', 'subtitle' => 'Task-linked follow-through', 'items' => []],
    'resolved' => ['title' => 'Resolved', 'subtitle' => 'Completed or archived', 'items' => []],
];
foreach ($filteredReminders as $reminder) {
    $bucket = reminder_dashboard_board_bucket($reminder);
    if (!isset($boardColumns[$bucket])) {
        $bucket = 'planned';
    }
    $boardColumns[$bucket]['items'][] = $reminder;
}

$emptyStateMap = [
    'my_day' => ['No reminders for today', 'Add a reminder or schedule follow-up work to start building your day.'],
    'important' => ['No important reminders', 'High and urgent items will surface here as soon as they are added.'],
    'planned' => ['Nothing planned yet', 'Add a due date or reminder time to see items in this scheduled view.'],
    'assigned' => ['No assigned reminders', 'Task-linked reminder cards appear automatically when tasks are assigned to you.'],
    'all' => ['No active items', 'Create a personal reminder or assign work to begin filling this workspace.'],
    'completed' => ['Nothing completed yet', 'Completed reminders stay here once you close them out.'],
    'overdue' => ['No overdue items', 'You are caught up. Overdue reminders will surface here if anything slips past schedule.'],
    'personal' => ['No personal reminders', 'Use the quick add row to capture private follow-ups and notes to self.'],
    'task_linked' => ['No task-linked cards', 'Assigned work reminders will show here as soon as project tasks sync in.'],
    'pinned' => ['Nothing pinned yet', 'Pin important personal reminders to keep them close during the day.'],
    'archived' => ['Archive is empty', 'Dismissed reminders will collect here until you reopen them.'],
];
$emptyState = $emptyStateMap[$scope] ?? $emptyStateMap['all'];

$success = trim((string) ($_GET['success'] ?? ''));
$error = trim((string) ($_GET['error'] ?? ''));
$successMessages = [
    'created' => 'Reminder created successfully.',
    'updated' => 'Reminder updated successfully.',
    'alarm_updated' => 'Reminder alarm settings updated successfully.',
    'completed' => 'Reminder marked as completed.',
    'reopened' => 'Reminder moved back to active.',
    'dismissed' => 'Reminder archived from the active board.',
    'deleted' => 'Reminder deleted successfully.',
    'snoozed' => 'Reminder snoozed and moved forward on your timeline.',
];

include '../../includes/header.php';
?>
<style>
/*
 * Reminders Hub specific overrides.
 * Core .todo-* chrome now lives in assets/css/workspace-shell.css (loaded globally).
 * Only keep rules here that differ from or extend the shared shell.
 */
.todo-modal #modalTitleInput {
    font-size: 16px;
    font-weight: 600;
    padding: 10px 14px;
}

.todo-modal #modalNote {
    min-height: 120px;
    padding: 10px 12px;
    line-height: 1.4;
}

.todo-modal #modalPriority {
    min-width: 150px;
    font-size: 14px;
}
</style>

<div class="todo-shell">
    
    <!-- Mobile toggle button (if global header doesn't cover) -->
    <div class="md:hidden fixed bottom-4 right-4 z-50">
        <button onclick="document.getElementById('todo-sidebar').classList.toggle('is-open')" class="bg-emerald-700 text-white p-3 rounded-full shadow-lg">
            <i class="material-icons">menu</i>
        </button>
    </div>

    <!-- Sidebar -->
    <aside class="todo-sidebar" id="todo-sidebar">
        <!-- Close button mobile -->
        <div class="md:hidden flex justify-end p-2 mb-2">
            <button onclick="document.getElementById('todo-sidebar').classList.remove('is-open')"><i class="material-icons">close</i></button>
        </div>

        <?php
        $navLinks = [
            'my_day' => ['icon' => 'wb_sunny', 'label' => 'My Day'],
            'important' => ['icon' => 'star_border', 'label' => 'Important'],
            'planned' => ['icon' => 'event', 'label' => 'Planned'],
            'personal' => ['icon' => 'sticky_note_2', 'label' => 'Personal'],
            'assigned' => ['icon' => 'person_outline', 'label' => 'Assigned to me'],
            'all' => ['icon' => 'inbox', 'label' => 'All Items'],
        ];
        ?>
        <?php foreach ($navLinks as $navScope => $config): ?>
            <a href="<?php echo htmlspecialchars($buildReminderUrl(['scope' => $navScope, 'detail' => false])); ?>" class="todo-nav-link <?php echo $scope === $navScope ? 'is-active' : ''; ?>">
                <div class="todo-nav-link-left">
                    <i class="material-icons"><?php echo htmlspecialchars($config['icon']); ?></i>
                    <span><?php echo htmlspecialchars($config['label']); ?></span>
                </div>
                <?php if (!empty($scopeCounts[$navScope])): ?>
                <span class="todo-nav-badge"><?php echo number_format((int) $scopeCounts[$navScope]); ?></span>
                <?php endif; ?>
            </a>
        <?php endforeach; ?>

        <div class="todo-sidebar-divider"></div>

        <a href="<?php echo htmlspecialchars($buildReminderUrl(['scope' => 'completed', 'detail' => false])); ?>" class="todo-nav-link <?php echo $scope === 'completed' ? 'is-active' : ''; ?>">
            <div class="todo-nav-link-left">
                <i class="material-icons">check_circle_outline</i>
                <span>Completed</span>
            </div>
            <span class="todo-nav-badge"><?php echo number_format((int) ($scopeCounts['completed'] ?? 0)); ?></span>
        </a>
    </aside>

    <!-- Main Workspace -->
    <main class="todo-main">
        <header class="todo-header">
            <h1 class="todo-header-title"><?php echo htmlspecialchars($currentScopeMeta['title']); ?></h1>
            <p class="todo-header-subtitle">
                <?php echo ($scope === 'my_day') ? htmlspecialchars(date('l, F j')) : htmlspecialchars($currentScopeMeta['subtitle']); ?>
            </p>
        </header>

        <div class="wo-dashboard-kpi-grid mb-6" aria-label="Reminder summary">
            <?php foreach ($summaryCards as $card): ?>
                <div class="wo-dashboard-kpi-card" data-tone="<?php echo htmlspecialchars($card['tone']); ?>">
                    <div class="wo-dashboard-kpi-head">
                        <div>
                            <p class="wo-dashboard-kpi-label"><?php echo htmlspecialchars($card['label']); ?></p>
                            <p class="wo-dashboard-kpi-value"><?php echo number_format((int) $card['value']); ?></p>
                        </div>
                        <span class="wo-dashboard-kpi-icon">
                            <i data-lucide="calendar-clock" aria-hidden="true"></i>
                        </span>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <?php if ($layout === 'board'): ?>
            <div class="grid grid-cols-1 xl:grid-cols-2 gap-4 mb-6">
                <?php foreach ($boardColumns as $column): ?>
                    <section class="workspace-panel">
                        <header class="mb-3">
                            <h2 class="text-lg font-semibold text-gray-900"><?php echo htmlspecialchars($column['title']); ?></h2>
                            <p class="text-sm text-gray-500"><?php echo htmlspecialchars($column['subtitle']); ?></p>
                        </header>
                        <div class="space-y-3">
                            <?php foreach ($column['items'] as $reminder): ?>
                                <article class="list-mobile-card">
                                    <h3 class="list-card-title"><?php echo htmlspecialchars($reminder['title'] ?? 'Reminder'); ?></h3>
                                    <p class="text-sm text-gray-500 mt-1"><?php echo htmlspecialchars(reminder_dashboard_excerpt($reminder['note'] ?? '')); ?></p>
                                    <p class="text-xs text-gray-400 mt-2"><?php echo htmlspecialchars($reminder['due_meta']['label'] ?? 'No due date'); ?></p>
                                </article>
                            <?php endforeach; ?>
                            <?php if (empty($column['items'])): ?>
                                <p class="text-sm text-gray-500 italic">Nothing in this column yet.</p>
                            <?php endif; ?>
                        </div>
                    </section>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <!-- Quick Add (Inline) -->
        <form method="POST" action="save" id="quick-add-form" class="todo-add-task">
            <input type="hidden" name="_csrf" value="<?php echo htmlspecialchars(csrf_token('reminder_save')); ?>">
            <input type="hidden" name="redirect_to" value="<?php echo htmlspecialchars($baseDashboardPath); ?>">
            <input type="hidden" name="alarm_enabled" value="<?php echo $quickAddAlarmEnabled; ?>">
            <input type="hidden" name="pinned" value="0">
            <input type="hidden" name="priority" value="<?php echo $scope === 'important' ? 'High' : 'Medium'; ?>">
            <input type="hidden" name="remind_at" value="<?php echo htmlspecialchars($quickAddReminderAt); ?>">

            <div style="display:flex; align-items:center; flex:1;">
                <i class="material-icons add-icon">add</i>
                <input type="text" name="title" class="add-input" placeholder="Add a reminder" required>
            </div>
            <div style="display:flex; gap: 8px; align-items:center; padding-left:12px;">
                <button type="button" title="Add details" class="todo-btn-ghost flex items-center justify-center" style="border:none; padding:4px 8px; color:#605e5c;" onclick="openNewReminderModal()">
                    <i class="material-icons" style="font-size: 20px;">open_in_full</i>
                </button>
                <button type="submit" class="todo-btn-primary" style="padding: 6px 12px;">Add</button>
            </div>
        </form>

        <!-- List -->
        <div class="todo-list">
            <?php if (empty($filteredReminders)): ?>
                <div style="text-align:center; padding: 40px; color:#605e5c; font-size:14px;">
                    <i class="material-icons" style="font-size:48px; color:#edebe9;">check_circle</i>
                    <p style="margin-top:10px;">Nothing to show here. Add a reminder to get started.</p>
                </div>
            <?php else: ?>
                <?php foreach ($filteredReminders as $reminder): ?>
                    <?php $isComplete = in_array((string) ($reminder['status'] ?? 'active'), ['completed', 'dismissed'], true); ?>
                    <div class="todo-row <?php echo $isComplete ? 'is-complete' : ''; ?>" onclick="openReminderModal(<?php echo htmlspecialchars(json_encode([
                        'id' => $reminder['id'],
                        'task_id' => $reminder['task_id'] ?? null,
                        'title' => $reminder['title'],
                        'note' => $reminder['note'] ?? '',
                        'priority' => $reminder['priority'],
                        'status' => $reminder['status'],
                        'remind_at' => $reminder['remind_at_local'] ?? '',
                        'alarm_enabled' => $reminder['alarm_enabled'] ?? 1,
                        'alarm_offset_minutes' => $reminder['alarm_offset_minutes'] ?? 30,
                        'is_readonly' => !empty($reminder['is_task_linked']),
                        'project_name' => $reminder['project_name'] ?? ''
                    ])); ?>)">
                        <form method="POST" action="action" class="todo-check-form" style="margin:0;" onclick="event.stopPropagation();">
                            <input type="hidden" name="_csrf" value="<?php echo htmlspecialchars(csrf_token('reminder_action')); ?>">
                            <input type="hidden" name="id" value="<?php echo (int) $reminder['id']; ?>">
                            <input type="hidden" name="action" value="<?php echo $isComplete ? 'reopen' : 'complete'; ?>">
                            <input type="hidden" name="redirect_to" value="<?php echo htmlspecialchars($baseDashboardPath); ?>">
                            <button type="submit" style="background:none; border:none; padding:0; cursor:pointer;" aria-label="Toggle Complete">
                                <div class="todo-check"></div>
                            </button>
                        </form>

                        <div class="todo-row-content">
                            <div class="todo-row-title"><?php echo htmlspecialchars($reminder['title']); ?></div>
                            <div class="todo-row-meta">
                                <?php if (!empty($reminder['source_label']) && $reminder['source_label'] === 'Task-linked'): ?>
                                    <span class="meta-item"><i class="material-icons">assignment</i> Task</span>
                                <?php else: ?>
                                    <span class="meta-item"><i class="material-icons">sticky_note_2</i> Personal</span>
                                <?php endif; ?>

                                <?php if (!empty($reminder['due_meta']['compact_label'])): ?>
                                    <span class="meta-item <?php echo $reminder['due_meta']['tone'] === 'danger' ? 'is-overdue' : ($reminder['due_meta']['tone'] === 'info' ? 'is-planned' : ''); ?>">
                                        <i class="material-icons">event</i> <?php echo htmlspecialchars($reminder['due_meta']['compact_label']); ?>
                                    </span>
                                <?php endif; ?>

                                <?php if (in_array((string)$reminder['priority'], ['High', 'Urgent'])): ?>
                                    <span class="meta-item is-overdue"><i class="material-icons">star</i> Important</span>
                                <?php endif; ?>

                                <?php if (!empty($reminder['alarm_enabled'])): ?>
                                    <span class="meta-item is-planned"><i class="material-icons">notifications_active</i></span>
                                <?php endif; ?>

                                <?php if (!empty($reminder['project_name'])): ?>
                                    <span class="meta-item"><i class="material-icons">folder</i> <?php echo htmlspecialchars($reminder['project_name']); ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </main>
</div>

<!-- Modal -->
<div class="todo-modal-overlay" id="todoModal">
    <div class="todo-modal">
        <div class="todo-modal-header">
            <h3 class="todo-modal-title" id="modalTitle">Reminder Details</h3>
            <button class="todo-modal-close" onclick="closeReminderModal()">&times;</button>
        </div>
        <form method="POST" action="save" id="modalForm">
            <input type="hidden" name="_csrf" value="<?php echo htmlspecialchars(csrf_token('reminder_save')); ?>">
            <input type="hidden" name="redirect_to" value="<?php echo htmlspecialchars($baseDashboardPath); ?>">
            <input type="hidden" name="id" id="modalId" value="">
            
            <div class="todo-modal-body" style="padding-bottom: 8px;">
                <div class="todo-field">
                    <input type="text" name="title" id="modalTitleInput" class="todo-input" placeholder="Reminder title" required>
                </div>

                <div class="todo-field" style="flex-direction:row; gap:12px; margin-top:12px; flex-wrap:wrap; align-items:center;">
                    <div style="position:relative;">
                        <button type="button" class="todo-btn-ghost flex items-center gap-2" onclick="document.getElementById('modalRemindAtContainer').classList.toggle('hidden')">
                            <i class="material-icons" style="font-size:16px;">event</i>
                            <span id="dueDateLabel">Add due date</span>
                        </button>
                        <div id="modalRemindAtContainer" class="hidden absolute left-0 top-full mt-2 bg-white border border-gray-200 shadow-lg rounded-lg p-3 z-50" style="min-width:260px;">
                            <label class="block text-xs font-semibold text-gray-600 mb-1">Due Date & Time</label>
                            <?php echo press_datetime_picker_field([
                                'name' => 'remind_at',
                                'id' => 'modalRemindAt',
                                'value' => '',
                                'mode' => 'datetime',
                                'class' => 'todo-input',
                                'disable_past' => true,
                            ]); ?>
                            <div class="mt-2 text-right">
                                <button type="button" class="text-emerald-700 text-xs font-semibold hover:underline" onclick="clearModalRemindAtPicker();">Clear</button>
                            </div>
                        </div>
                    </div>

                    <div style="position:relative;">
                        <button type="button" class="todo-btn-ghost flex items-center gap-2" id="remindMeBtn" onclick="document.getElementById('modalAlarmContainer').classList.toggle('hidden')">
                            <i class="material-icons" style="font-size:16px;">notifications</i>
                            <span>Remind me</span>
                        </button>
                        <div id="modalAlarmContainer" class="hidden absolute left-0 top-full mt-2 bg-white border border-gray-200 shadow-lg rounded-lg p-3 z-50" style="min-width:240px;">
                            <label class="flex items-center gap-2 mb-3 cursor-pointer">
                                <input type="hidden" name="alarm_enabled" value="0">
                                <input type="checkbox" name="alarm_enabled" id="modalAlarmEnabled" value="1" class="h-4 w-4 text-emerald-700 rounded">
                                <span class="text-sm font-semibold text-gray-800">Enable Alarm</span>
                            </label>
                            <label class="block text-xs font-semibold text-gray-600 mb-1">Remind me before due</label>
                            <select name="alarm_offset_minutes" id="modalAlarmOffset" class="todo-select">
                                <?php foreach ($alarmOffsetOptions as $value => $label): ?>
                                    <option value="<?php echo $value; ?>"><?php echo htmlspecialchars($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div style="position:relative;">
                        <button type="button" class="todo-btn-ghost flex items-center gap-2" onclick="document.getElementById('modalPriorityContainer').classList.toggle('hidden')">
                            <i class="material-icons" style="font-size:16px;" id="priorityIcon">star_border</i>
                            <span id="priorityLabel">Priority</span>
                        </button>
                        <div id="modalPriorityContainer" class="hidden absolute left-0 top-full mt-2 bg-white border border-gray-200 shadow-lg rounded-lg p-2 z-50">
                            <select name="priority" id="modalPriority" class="todo-select" onchange="updatePriorityLabel()">
                                <option value="Low">Low</option>
                                <option value="Medium">Medium</option>
                                <option value="High">Important (High)</option>
                                <option value="Urgent">Urgent</option>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="todo-field" style="margin-top: 16px;">
                    <textarea name="note" id="modalNote" class="todo-textarea" placeholder="Add note"></textarea>
                </div>
            </div>
            
            <div class="todo-modal-header" style="background:#f8f8f8; padding: 12px 20px; justify-content:flex-end; gap:8px;">
                <a href="#" id="modalOpenTaskBtn" class="todo-btn-ghost flex items-center gap-1" style="display:none; color:#187B74; border-color:#187B74; margin-right:auto; text-decoration:none;"><i class="material-icons" style="font-size:16px;">open_in_new</i> Open Task</a>
                <button type="button" class="todo-btn-ghost" onclick="closeReminderModal()">Cancel</button>
                <button type="submit" class="todo-btn-primary" id="modalSaveBtn">Save</button>
            </div>
        </form>
    </div>
</div>

<script>
function clearModalRemindAtPicker() {
    var el = document.getElementById('modalRemindAt');
    if (window.PressErpDateTimePicker && typeof window.PressErpDateTimePicker.setValue === 'function') {
        window.PressErpDateTimePicker.setValue(el, '');
    } else if (el) {
        el.value = '';
    }
    document.getElementById('modalRemindAtContainer').classList.add('hidden');
    updateDueDateLabel();
}

function formatRemindAtSummary(iso) {
    if (!iso || typeof iso !== 'string') {
        return '';
    }
    const m = iso.match(/^(\d{4})-(\d{2})-(\d{2})(?:T(\d{2}):(\d{2}))?/);
    if (!m) {
        return iso.replace('T', ' ');
    }
    const y = parseInt(m[1], 10);
    const mo = parseInt(m[2], 10) - 1;
    const d = parseInt(m[3], 10);
    const h = m[4] !== undefined ? parseInt(m[4], 10) : 0;
    const mi = m[5] !== undefined ? parseInt(m[5], 10) : 0;
    const dt = new Date(y, mo, d, h, mi);
    if (isNaN(dt.getTime())) {
        return iso.replace('T', ' ');
    }
    try {
        return dt.toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' });
    } catch (e) {
        return iso.replace('T', ' ');
    }
}

function updateDueDateLabel() {
    const val = document.getElementById('modalRemindAt').value;
    const label = document.getElementById('dueDateLabel');
    if (val) {
        label.textContent = formatRemindAtSummary(val);
    } else {
        label.textContent = "Add due date";
    }
}

function updatePriorityLabel() {
    const val = document.getElementById('modalPriority').value;
    const label = document.getElementById('priorityLabel');
    const icon = document.getElementById('priorityIcon');
    label.textContent = val;
    if(val === 'High' || val === 'Urgent') {
        icon.textContent = 'star';
        icon.style.color = '#d13438';
    } else {
        icon.textContent = 'star_border';
        icon.style.color = 'inherit';
    }
}

document.addEventListener('DOMContentLoaded', function() {
    var remindAt = document.getElementById('modalRemindAt');
    if (remindAt) {
        remindAt.addEventListener('change', updateDueDateLabel);
        remindAt.addEventListener('input', updateDueDateLabel);
    }

    // Hide popovers when clicking outside
    document.addEventListener('click', function(event) {
        if (event.target && event.target.closest && event.target.closest('.flatpickr-calendar')) {
            return;
        }
        const containers = ['modalRemindAtContainer', 'modalAlarmContainer', 'modalPriorityContainer'];
        containers.forEach(id => {
            const el = document.getElementById(id);
            if (!el || el.classList.contains('hidden')) {
                return;
            }
            const btn = el.previousElementSibling;
            if (btn && !el.contains(event.target) && !btn.contains(event.target)) {
                el.classList.add('hidden');
            }
        });
    });

    document.getElementById('modalForm').addEventListener('submit', function(event) {
        const alarmEnabled = document.getElementById('modalAlarmEnabled').checked;
        const remindAt = document.getElementById('modalRemindAt').value.trim();
        if (alarmEnabled && remindAt === '') {
            event.preventDefault();
            document.getElementById('modalRemindAtContainer').classList.remove('hidden');
            document.getElementById('modalRemindAt').focus();
            alert('Choose a due date and time before enabling reminder alarms.');
        }
    });
});

function openNewReminderModal() {
    if (typeof window.openActionModal === 'function') {
        window.openActionModal('reminder.create', {
            title: document.querySelector('.add-input').value,
            remind_at: '<?php echo htmlspecialchars($quickAddReminderAt); ?>'
        });
        return;
    }

    openReminderModal({
        id: '',
        title: document.querySelector('.add-input').value, // carry over typed text
        priority: '<?php echo $scope === "important" ? "High" : "Medium"; ?>',
        remind_at: '<?php echo htmlspecialchars($quickAddReminderAt); ?>',
        alarm_enabled: <?php echo $quickAddAlarmEnabled; ?>,
        alarm_offset_minutes: 30,
        note: '',
        is_readonly: false
    });
}

function openReminderModal(data) {
    document.getElementById('todoModal').classList.add('is-active');
    document.getElementById('modalId').value = data.id;
    document.getElementById('modalTitleInput').value = data.title || '';
    document.getElementById('modalPriority').value = data.priority || 'Medium';
    
    var remindAtEl = document.getElementById('modalRemindAt');
    if (remindAtEl && window.PressErpDateTimePicker && typeof window.PressErpDateTimePicker.rebind === 'function') {
        if (data.id || data.is_readonly) {
            remindAtEl.removeAttribute('data-press-disable-past');
        } else {
            remindAtEl.setAttribute('data-press-disable-past', '1');
        }
        window.PressErpDateTimePicker.rebind(remindAtEl);
        window.PressErpDateTimePicker.setValue(remindAtEl, data.remind_at || '');
    } else if (remindAtEl) {
        remindAtEl.value = data.remind_at || '';
    }
    updateDueDateLabel();
    updatePriorityLabel();

    document.getElementById('modalAlarmEnabled').checked = data.alarm_enabled == 1;
    document.getElementById('modalAlarmOffset').value = data.alarm_offset_minutes || 30;

    // Reset popover states
    document.getElementById('modalRemindAtContainer').classList.add('hidden');
    document.getElementById('modalAlarmContainer').classList.add('hidden');
    document.getElementById('modalPriorityContainer').classList.add('hidden');

    document.getElementById('modalNote').value = data.note || '';

    // If readonly (task-linked), disable ALL inputs but LEAVE ALARM SETTINGS WRITABLE
    const isReadonly = data.is_readonly;
    document.getElementById('modalTitleInput').readOnly = isReadonly;
    document.getElementById('modalPriority').disabled = isReadonly;
    
    // Disable Flatpickr if readonly
    const remindBtn = document.getElementById('modalRemindAtContainer').previousElementSibling;
    remindBtn.disabled = isReadonly;
    if (isReadonly) {
        remindBtn.style.opacity = '0.5';
        remindBtn.style.cursor = 'not-allowed';
    } else {
        remindBtn.style.opacity = '1';
        remindBtn.style.cursor = 'pointer';
    }

    const priorityBtn = document.getElementById('modalPriorityContainer').previousElementSibling;
    priorityBtn.disabled = isReadonly;
    if (isReadonly) {
        priorityBtn.style.opacity = '0.5';
        priorityBtn.style.cursor = 'not-allowed';
    } else {
        priorityBtn.style.opacity = '1';
        priorityBtn.style.cursor = 'pointer';
    }

    document.getElementById('modalNote').readOnly = isReadonly;
    
    // We explicitly leave save active for task-linked so they can save ALARM settings
    document.getElementById('modalSaveBtn').style.display = 'block';

    const openTaskBtn = document.getElementById('modalOpenTaskBtn');
    if (data.task_id) {
        openTaskBtn.style.display = 'inline-flex';
        openTaskBtn.href = "<?php echo BASE_URL; ?>modules/tasks/view?id=" + data.task_id;
    } else {
        openTaskBtn.style.display = 'none';
        openTaskBtn.href = "#";
    }

    if(isReadonly && data.project_name && !document.getElementById('modalNote').value.includes('Linked to project')) {
        document.getElementById('modalNote').value += "\n\n(Linked to project: " + data.project_name + "\nDue dates and priorities are synced from the project task.)";
    }
}

function closeReminderModal() {
    document.getElementById('todoModal').classList.remove('is-active');
}

// Close on background click
document.getElementById('todoModal').addEventListener('click', function(e) {
    if(e.target === this) closeReminderModal();
});
</script>

<?php include '../../includes/footer.php'; ?>
