<?php
/**
 * Dashboard partials helper.
 *
 * Owns:
 *   - Shared formatting helpers used by the dashboard partials
 *     (dashboardTaskStateMeta, dashboardTaskDueCopy, dashboardCurrency, ...)
 *   - The data-collection function `dashboard_collect_context($pdo, $params)`
 *     that both modules/dashboard/index.php and modules/dashboard/fragments.php
 *     call before including a partial. This guarantees that whatever the
 *     fragment endpoint sends back is byte-identical to the matching block
 *     rendered during a full page load.
 *   - The fragment registry that maps component ids to partial views and
 *     (optionally) the permission required to refresh them.
 */

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/dashboard_metrics_helper.php';
require_once __DIR__ . '/task_management_helper.php';
require_once __DIR__ . '/reminder_helper.php';
require_once __DIR__ . '/dashboard_landing_helper.php';

// ---------------------------------------------------------------------------
// Formatting helpers (extracted from modules/dashboard/index.php)
// ---------------------------------------------------------------------------

if (!function_exists('dashboardTaskStateMeta')) {
    function dashboardTaskStateMeta(?string $status, bool $isOverdue = false): array
    {
        $states = [
            'Pending' => [
                'label' => 'Pending',
                'accent' => '#94a3b8',
                'soft' => 'rgba(148, 163, 184, 0.20)',
                'bg' => 'rgba(148, 163, 184, 0.10)',
                'badge' => 'bg-slate-100 text-slate-700',
                'icon' => 'clock',
                'pulse' => false,
            ],
            'Not Started' => [
                'label' => 'Pending',
                'accent' => '#94a3b8',
                'soft' => 'rgba(148, 163, 184, 0.20)',
                'bg' => 'rgba(148, 163, 184, 0.10)',
                'badge' => 'bg-slate-100 text-slate-700',
                'icon' => 'clock',
                'pulse' => false,
            ],
            'Started' => [
                'label' => 'Started',
                'accent' => '#f59e0b',
                'soft' => 'rgba(245, 158, 11, 0.22)',
                'bg' => 'rgba(245, 158, 11, 0.11)',
                'badge' => 'bg-amber-100 text-amber-700',
                'icon' => 'circle-play',
                'pulse' => true,
            ],
            'In Progress' => [
                'label' => 'In Progress',
                'accent' => '#0f766e',
                'soft' => 'rgba(15, 118, 110, 0.22)',
                'bg' => 'rgba(15, 118, 110, 0.11)',
                'badge' => 'bg-emerald-50 text-emerald-700',
                'icon' => 'refresh-cw',
                'pulse' => true,
            ],
            'In Review' => [
                'label' => 'In Review',
                'accent' => '#d97706',
                'soft' => 'rgba(217, 119, 6, 0.22)',
                'bg' => 'rgba(217, 119, 6, 0.10)',
                'badge' => 'bg-amber-100 text-amber-700',
                'icon' => 'clipboard-check',
                'pulse' => true,
            ],
            'Review' => [
                'label' => 'In Review',
                'accent' => '#d97706',
                'soft' => 'rgba(217, 119, 6, 0.22)',
                'bg' => 'rgba(217, 119, 6, 0.10)',
                'badge' => 'bg-amber-100 text-amber-700',
                'icon' => 'clipboard-check',
                'pulse' => true,
            ],
            'Completed' => [
                'label' => 'Completed',
                'accent' => '#10b981',
                'soft' => 'rgba(16, 185, 129, 0.22)',
                'bg' => 'rgba(16, 185, 129, 0.11)',
                'badge' => 'bg-emerald-100 text-emerald-700',
                'icon' => 'circle-check',
                'pulse' => false,
            ],
            'Cancelled' => [
                'label' => 'Cancelled',
                'accent' => '#f43f5e',
                'soft' => 'rgba(244, 63, 94, 0.22)',
                'bg' => 'rgba(244, 63, 94, 0.10)',
                'badge' => 'bg-rose-100 text-rose-700',
                'icon' => 'circle-x',
                'pulse' => false,
            ],
            'Overdue' => [
                'label' => 'Overdue',
                'accent' => '#ef4444',
                'soft' => 'rgba(239, 68, 68, 0.24)',
                'bg' => 'rgba(239, 68, 68, 0.10)',
                'badge' => 'bg-red-100 text-red-700',
                'icon' => 'triangle-alert',
                'pulse' => true,
            ],
        ];

        if ($isOverdue) {
            return $states['Overdue'];
        }

        return $states[$status ?? ''] ?? $states['Pending'];
    }
}

if (!function_exists('dashboardTaskDueCopy')) {
    function dashboardTaskDueCopy(?string $dueDate, bool $isOverdue): string
    {
        if (empty($dueDate)) {
            return 'No due date';
        }

        $dueTimestamp = strtotime($dueDate);
        if ($dueTimestamp === false) {
            return 'Due date unavailable';
        }

        $today = strtotime(date('Y-m-d'));
        $dueDay = strtotime(date('Y-m-d', $dueTimestamp));
        $dayDiff = (int) round(($dueDay - $today) / 86400);

        if ($isOverdue) {
            $daysLate = max(1, abs($dayDiff));
            return 'Overdue by ' . $daysLate . ' day' . ($daysLate === 1 ? '' : 's');
        }

        if ($dayDiff === 0) {
            return 'Due today';
        }

        if ($dayDiff === 1) {
            return 'Due tomorrow';
        }

        return 'Due ' . date('M j', $dueTimestamp);
    }
}

if (!function_exists('dashboardTaskExcerpt')) {
    function dashboardTaskExcerpt(?string $text, int $limit = 110): string
    {
        $text = trim((string) $text);
        if ($text === '') {
            return '';
        }
        if (strlen($text) <= $limit) {
            return $text;
        }
        return rtrim(substr($text, 0, $limit - 1)) . '…';
    }
}

if (!function_exists('dashboardTaskExcerptPlain')) {
    function dashboardTaskExcerptPlain(?string $text, int $limit = 110): string
    {
        $text = trim((string) $text);
        if ($text === '') {
            return '';
        }
        if (strlen($text) <= $limit) {
            return $text;
        }
        return rtrim(substr($text, 0, max(1, $limit - 3))) . '...';
    }
}

if (!function_exists('dashboardCurrency')) {
    function dashboardCurrency($value): string
    {
        return number_format((float) $value, 2);
    }
}

if (!function_exists('dashboardDebtAgeMeta')) {
    function dashboardDebtAgeMeta(int $days): array
    {
        if ($days <= 0) {
            return ['label' => 'Current', 'tone' => 'success'];
        }
        if ($days <= 30) {
            return ['label' => '1-30d', 'tone' => 'info'];
        }
        if ($days <= 60) {
            return ['label' => '31-60d', 'tone' => 'warning'];
        }
        if ($days <= 90) {
            return ['label' => '61-90d', 'tone' => 'danger'];
        }
        return ['label' => '90+d', 'tone' => 'critical'];
    }
}

if (!function_exists('dashboardDebtorInitials')) {
    function dashboardDebtorInitials(string $name): string
    {
        $name = trim($name);
        if ($name === '') {
            return '?';
        }

        $parts = preg_split('/\s+/', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (count($parts) === 1) {
            return strtoupper(substr($parts[0], 0, 2));
        }

        return strtoupper(substr($parts[0], 0, 1) . substr($parts[1], 0, 1));
    }
}

if (!function_exists('dashboardMoMGrowth')) {
    function dashboardMoMGrowth($current, $previous): string
    {
        if ($previous == 0) {
            return $current > 0 ? '+100%' : '0%';
        }
        $growth = (($current - $previous) / $previous) * 100;
        $sign = $growth > 0 ? '+' : '';
        return $sign . number_format($growth, 1) . '%';
    }
}

if (!function_exists('dashboardGrowthColor')) {
    function dashboardGrowthColor($growthStr): string
    {
        if (strpos($growthStr, '+') !== false) {
            return 'text-emerald-700 bg-emerald-50';
        }
        if ($growthStr === '0%') {
            return 'text-slate-600 bg-slate-100';
        }
        return 'text-rose-700 bg-rose-50';
    }
}

if (!function_exists('dashboardGrowthIcon')) {
    function dashboardGrowthIcon($growthStr): string
    {
        if (strpos($growthStr, '+') !== false) {
            return 'trending-up';
        }
        if ($growthStr === '0%') {
            return 'minus';
        }
        return 'trending-down';
    }
}

if (!function_exists('dashboardModuleMeta')) {
    function dashboardModuleMeta(string $moduleKey): array
    {
        $catalog = [
            'workorders' => ['workorders', 'work_orders', 'work-order', 'workorder'],
        ];
        $candidates = $catalog[$moduleKey] ?? [$moduleKey];

        foreach ($candidates as $slug) {
            $basePath = ROOT_PATH . 'modules' . DIRECTORY_SEPARATOR . $slug;
            if (!is_dir($basePath)) {
                continue;
            }

            $route = '';
            if (is_file($basePath . DIRECTORY_SEPARATOR . 'index.php')) {
                $route = 'index';
            } elseif (is_file($basePath . DIRECTORY_SEPARATOR . 'list.php')) {
                $route = 'list';
            }

            return [
                'available' => true,
                'slug' => $slug,
                'path' => $basePath,
                'href' => BASE_URL . 'modules/' . $slug . ($route !== '' ? '/' . $route : ''),
            ];
        }

        return [
            'available' => false,
            'slug' => '',
            'path' => '',
            'href' => '',
        ];
    }
}

if (!function_exists('dashboardRelativeAgeLabel')) {
    function dashboardRelativeAgeLabel(?string $dateValue): string
    {
        if (empty($dateValue)) {
            return 'New';
        }

        $timestamp = strtotime($dateValue);
        if ($timestamp === false) {
            return 'New';
        }

        $today = strtotime(date('Y-m-d'));
        $targetDay = strtotime(date('Y-m-d', $timestamp));
        $dayDiff = (int) floor(($today - $targetDay) / 86400);

        if ($dayDiff <= 0) {
            return $dayDiff === 0 ? 'Today' : date('M j', $timestamp);
        }

        return $dayDiff === 1 ? '1 day' : $dayDiff . ' days';
    }
}

if (!function_exists('dashboardMaterialRateMeta')) {
    function dashboardMaterialRateMeta($rate, ?string $effectiveDate): array
    {
        if ((float) $rate <= 0) {
            return ['label' => 'Missing', 'tone' => 'danger'];
        }

        if (empty($effectiveDate)) {
            return ['label' => 'Review', 'tone' => 'warning'];
        }

        $effectiveTs = strtotime($effectiveDate);
        if ($effectiveTs === false) {
            return ['label' => 'Review', 'tone' => 'warning'];
        }

        $ageDays = max(0, (int) floor((strtotime(date('Y-m-d')) - strtotime(date('Y-m-d', $effectiveTs))) / 86400));
        if ($ageDays <= 30) {
            return ['label' => 'Current', 'tone' => 'success'];
        }
        if ($ageDays <= 90) {
            return ['label' => 'Review', 'tone' => 'warning'];
        }

        return ['label' => 'Stale', 'tone' => 'danger'];
    }
}

// ---------------------------------------------------------------------------
// Context collector
// ---------------------------------------------------------------------------

if (!function_exists('dashboard_build_attention_inbox')) {
    /**
     * Merge focus, approvals, debtors, work orders, and reminders into one feed.
     *
     * @param array<string, mixed> $context
     * @return array<int, array<string, mixed>>
     */
    function dashboard_build_attention_inbox(array $context): array
    {
        $items = [];

        foreach ($context['dashboardFocusItems'] ?? [] as $focus) {
            $tone = (string) ($focus['tone'] ?? 'neutral');
            $severity = $tone === 'danger' ? 90 : ($tone === 'warning' ? 70 : 40);
            $target = (string) ($focus['target'] ?? '');
            $items[] = [
                'severity' => $severity >= 80 ? 'critical' : ($severity >= 60 ? 'high' : 'medium'),
                'severity_score' => $severity,
                'type' => 'focus',
                'icon' => (string) ($focus['icon'] ?? 'alert-circle'),
                'title' => (string) ($focus['label'] ?? 'Focus item'),
                'subtitle' => (string) ($focus['note'] ?? ''),
                'value' => (string) ($focus['value'] ?? ''),
                'age_label' => 'Now',
                'href' => (string) ($focus['href'] ?? '#'),
                'modal' => $target,
            ];
        }

        foreach ($context['dashboardPendingApprovals'] ?? [] as $approval) {
            $items[] = [
                'severity' => 'high',
                'severity_score' => 75,
                'type' => 'approval',
                'icon' => (string) ($approval['icon'] ?? 'clipboard-check'),
                'title' => (string) ($approval['title'] ?? 'Approval'),
                'subtitle' => (string) ($approval['subtitle'] ?? ''),
                'value' => (string) ($approval['value'] ?? ''),
                'age_label' => (string) ($approval['age_label'] ?? ''),
                'href' => (string) ($approval['href'] ?? '#'),
                'modal' => '',
            ];
        }

        foreach ($context['dashboardDebtors'] ?? [] as $debtor) {
            $ageDays = (int) ($debtor['max_age_days'] ?? 0);
            if ($ageDays <= 30) {
                continue;
            }
            $severity = $ageDays > 60 ? 95 : 80;
            $items[] = [
                'severity' => $severity >= 90 ? 'critical' : 'high',
                'severity_score' => $severity,
                'type' => 'debtor',
                'icon' => 'wallet',
                'title' => (string) ($debtor['debtor_name'] ?? 'Debtor'),
                'subtitle' => number_format((int) ($debtor['invoice_count'] ?? 0)) . ' open invoice(s)',
                'value' => 'MK ' . dashboardCurrency($debtor['balance'] ?? 0),
                'age_label' => $ageDays . 'd overdue',
                'href' => BASE_URL . 'modules/invoices/list?status=unpaid',
                'modal' => '',
            ];
        }

        foreach ($context['dashboardWorkOrdersPanel']['active_queue'] ?? [] as $job) {
            $dueTone = (string) ($job['due_tone'] ?? 'neutral');
            if ($dueTone !== 'danger' && $dueTone !== 'warning') {
                continue;
            }
            $items[] = [
                'severity' => $dueTone === 'danger' ? 'critical' : 'high',
                'severity_score' => $dueTone === 'danger' ? 92 : 78,
                'type' => 'work_order',
                'icon' => 'briefcase',
                'title' => (string) ($job['work_order_number'] ?? 'Work order'),
                'subtitle' => (string) ($job['customer_name'] ?: 'Production job'),
                'value' => (string) ($job['due_label'] ?? ''),
                'age_label' => (string) ($job['status'] ?? ''),
                'href' => BASE_URL . 'modules/work_orders/view?id=' . (int) ($job['id'] ?? 0),
                'modal' => '',
            ];
        }

        if (($context['dashboardReminderAttentionCount'] ?? 0) > 0) {
            $items[] = [
                'severity' => 'high',
                'severity_score' => 72,
                'type' => 'reminder',
                'icon' => 'bell-dot',
                'title' => 'Reminder attention',
                'subtitle' => 'Due-today and overdue reminders need follow-up.',
                'value' => number_format((int) $context['dashboardReminderAttentionCount']),
                'age_label' => 'Today',
                'href' => BASE_URL . 'modules/reminders/index?scope=my_day',
                'modal' => 'wsModalReminders',
            ];
        }

        usort($items, static function (array $left, array $right): int {
            $scoreCompare = ((int) ($right['severity_score'] ?? 0)) <=> ((int) ($left['severity_score'] ?? 0));
            if ($scoreCompare !== 0) {
                return $scoreCompare;
            }

            return strcmp((string) ($left['title'] ?? ''), (string) ($right['title'] ?? ''));
        });

        return array_slice($items, 0, 8);
    }
}

/**
 * Build the full dashboard context bundle (data + computed UI helpers).
 *
 * Mirrors the inline data-collection block that used to live at the top of
 * modules/dashboard/index.php. Both index.php and fragments.php call this so
 * that the fragment HTML matches what a fresh page render would emit.
 *
 * @param PDO $pdo
 * @param array $params Optional inputs (cal_month, cal_day, search_query, ...).
 * @return array<string, mixed>
 */
function dashboard_collect_context(PDO $pdo, array $params = []): array
{
    $userId = (int) ($_SESSION['user_id'] ?? 0);
    $role = (string) ($_SESSION['role'] ?? 'User');

    $dashboardDateRange = dashboard_resolve_date_range($params);
    $stats = dashboard_fetch_summary_stats($pdo, $userId, $dashboardDateRange);
    $dashboardCanViewRevenueChart = hasPermission('view_dashboard_revenue') || hasPermission('view_invoices');
    $dashboardPersona = dashboard_resolve_persona();
    $dashboardPersonaLabel = dashboard_persona_label($dashboardPersona);
    $dashboardPanelOrder = dashboard_panel_order($dashboardPersona);
    $dashboardMainColumnOrder = dashboard_main_column_order($dashboardPersona);
    $chartData = $dashboardCanViewRevenueChart
        ? dashboard_fetch_chart_data($pdo, 6, $userId, $dashboardDateRange)
        : dashboard_empty_chart_data(dashboard_date_range_month_series($dashboardDateRange, 6));
    $dashboardHeroTrend = dashboard_fetch_hero_trend($pdo, $dashboardDateRange);

    // Recent projects ---------------------------------------------------------
    $recentProjects = [];
    try {
        require_once __DIR__ . '/project_visibility_helper.php';
        $recentVis = project_visibility_sql_where_for_projects('p', $userId, $pdo);
        $recentSql =
            "SELECT p.id, p.name, p.status, p.priority, p.end_date AS deadline,
                    (SELECT COUNT(*) FROM tasks t WHERE t.project_id = p.id) as task_count,
                    (SELECT COUNT(*) FROM tasks t WHERE t.project_id = p.id AND t.status = 'Completed') as completed_tasks
             FROM projects p
             WHERE 1=1 {$recentVis['clause']}
             ORDER BY p.created_at DESC
             LIMIT 5";
        $recentStmt = $pdo->prepare($recentSql);
        foreach ($recentVis['binds'] as $bk => $bv) {
            $recentStmt->bindValue(':' . $bk, $bv, PDO::PARAM_INT);
        }
        $recentStmt->execute();
        $recentProjects = $recentStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $recentProjects = [];
    }

    // Recent tasks + summary ---------------------------------------------------
    $recentTasks = [];
    $taskSummary = [
        'Not Started' => 0,
        'Started' => 0,
        'In Progress' => 0,
        'In Review' => 0,
        'Completed' => 0,
        'Cancelled' => 0,
    ];
    $dashboardTaskSummary = [
        'Pending' => 0,
        'Started' => 0,
        'In Progress' => 0,
        'In Review' => 0,
        'Completed' => 0,
        'Cancelled' => 0,
        'Overdue' => 0,
    ];
    $myOverdueTaskCount = 0;
    $dashboardReminderStats = [
        'active' => 0,
        'due_today' => 0,
        'overdue' => 0,
        'task_linked' => 0,
        'personal' => 0,
        'completed' => 0,
    ];
    $dashboardReminderItems = [];
    $dashboardReminderAttentionCount = 0;

    try {
        $stmt = $pdo->prepare(
            "SELECT t.id, t.name AS title, t.description, t.status, t.priority, t.due_date, t.created_at,
                    p.name AS project_name, u.name AS assigned_to_name
             FROM tasks t
             LEFT JOIN projects p ON t.project_id = p.id
             LEFT JOIN users u ON t.assigned_to = u.id
             WHERE t.assigned_to = :uid
                OR EXISTS (SELECT 1 FROM task_assignees ta WHERE ta.task_id = t.id AND ta.user_id = :uid2)
             ORDER BY CASE WHEN t.due_date IS NULL THEN 1 ELSE 0 END, t.due_date ASC, t.created_at DESC
             LIMIT 6"
        );
        $stmt->execute(['uid' => $userId, 'uid2' => $userId]);
        $recentTasks = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (!empty($recentTasks)) {
            $taskAssigneeMap = fetch_task_assignees_for_tasks($pdo, array_column($recentTasks, 'id'));
            foreach ($recentTasks as &$task) {
                $task['assignee_summary'] = format_task_assignee_summary(
                    $taskAssigneeMap[$task['id']] ?? [],
                    $task['assigned_to_name'] ?? null
                );
                $task['is_overdue'] = !empty($task['due_date'])
                    && !in_array($task['status'], ['Completed', 'Cancelled'], true)
                    && strtotime($task['due_date']) < strtotime(date('Y-m-d'));
                $task['state_meta'] = dashboardTaskStateMeta($task['status'], $task['is_overdue']);
                $task['due_copy'] = dashboardTaskDueCopy($task['due_date'] ?? null, $task['is_overdue']);
                $task['description_excerpt'] = dashboardTaskExcerptPlain($task['description'] ?? '');
            }
            unset($task);
        }

        $stmt2 = $pdo->query("SELECT status, COUNT(*) as cnt FROM tasks GROUP BY status");
        while ($row = $stmt2->fetch(PDO::FETCH_ASSOC)) {
            $taskSummary[$row['status']] = (int) $row['cnt'];
        }

        $dashboardTaskSummary['Pending'] = ($taskSummary['Not Started'] ?? 0) + ($taskSummary['Pending'] ?? 0);
        $dashboardTaskSummary['Started'] = $taskSummary['Started'] ?? 0;
        $dashboardTaskSummary['In Progress'] = $taskSummary['In Progress'] ?? 0;
        $dashboardTaskSummary['In Review'] = ($taskSummary['In Review'] ?? 0) + ($taskSummary['Review'] ?? 0);
        $dashboardTaskSummary['Completed'] = $taskSummary['Completed'] ?? 0;
        $dashboardTaskSummary['Cancelled'] = $taskSummary['Cancelled'] ?? 0;
        $dashboardTaskSummary['Overdue'] = (int) $pdo->query(
            "SELECT COUNT(*) FROM tasks
             WHERE due_date IS NOT NULL
               AND due_date < CURDATE()
               AND status NOT IN ('Completed', 'Cancelled')"
        )->fetchColumn();

        $myOverdueStmt = $pdo->prepare(
            "SELECT COUNT(*)
             FROM tasks t
             WHERE t.due_date < CURDATE()
               AND t.status NOT IN ('Completed', 'Cancelled')
               AND (
                   t.assigned_to = :uid
                   OR EXISTS (
                       SELECT 1
                       FROM task_assignees ta
                       WHERE ta.task_id = t.id AND ta.user_id = :uid2
                   )
               )"
        );
        $myOverdueStmt->execute(['uid' => $userId, 'uid2' => $userId]);
        $myOverdueTaskCount = (int) $myOverdueStmt->fetchColumn();

        if ($userId > 0 && reminder_module_ready($pdo, true)) {
            $reminderBootstrapAt = (int) ($_SESSION['reminder_backfill_bootstrapped_at'] ?? 0);
            if ($reminderBootstrapAt < (time() - 900)) {
                backfill_task_assignment_reminders_for_user($pdo, (int) $userId);
                $_SESSION['reminder_backfill_bootstrapped_at'] = time();
            }
            $dashboardReminderStats = fetch_reminder_counts_for_user($pdo, (int) $userId);
            $dashboardReminderAttentionCount = (int) $dashboardReminderStats['due_today'] + (int) $dashboardReminderStats['overdue'];

            foreach (fetch_reminders_for_user($pdo, (int) $userId, ['status' => 'active', 'limit' => 4]) as $reminder) {
                $dashboardReminderItems[] = [
                    'id' => (int) ($reminder['id'] ?? 0),
                    'href' => !empty($reminder['is_task_linked']) && !empty($reminder['task_id'])
                        ? BASE_URL . 'modules/tasks/view?id=' . (int) $reminder['task_id']
                        : BASE_URL . 'modules/reminders/index' . (!empty($reminder['id']) ? '?detail=' . (int) $reminder['id'] : ''),
                    'icon' => !empty($reminder['is_task_linked']) ? 'clipboard-list' : 'calendar-clock',
                    'tone' => ($reminder['due_meta']['tone'] ?? 'info') === 'danger' ? 'danger' : 'info',
                    'title' => $reminder['title'],
                    'subtitle' => !empty($reminder['is_task_linked'])
                        ? ($reminder['project_name'] ?: 'Task-linked reminder')
                        : 'Personal reminder card',
                    'meta' => $reminder['priority'] ?? 'Medium',
                    'value' => $reminder['due_meta']['compact_label'] ?? ($reminder['due_meta']['label'] ?? 'No target date'),
                ];
            }
        }
    } catch (Exception $e) {
        // best-effort context collection; partials handle missing data gracefully.
    }

    // Global search (used by the search-results panel) -------------------------
    $search_results = [];
    $search_query = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['search_query'])) {
        $search_query = trim((string) $_POST['search_query']);
        $search_pattern = '%' . $search_query . '%';
        try {
            if (hasPermission('view_estimations')) {
            $stmt = $pdo->prepare("SELECT 'estimation' as type, id, estimation_number as title, customer_name as subtitle, created_at
                                  FROM estimations
                                  WHERE estimation_number LIKE :query OR customer_name LIKE :query OR job_description LIKE :query
                                  LIMIT 5");
            $stmt->execute(['query' => $search_pattern]);
            $search_results = array_merge($search_results, $stmt->fetchAll());
            }

            if (hasPermission('view_invoices')) {
            $stmt = $pdo->prepare("SELECT 'invoice' as type, id, invoice_number as title, customer_name as subtitle, generated_date as created_at
                                  FROM invoices
                                  WHERE invoice_number LIKE :query OR customer_name LIKE :query
                                  LIMIT 5");
            $stmt->execute(['query' => $search_pattern]);
            $search_results = array_merge($search_results, $stmt->fetchAll());
            }

            if (hasPermission('view_users')) {
                $stmt = $pdo->prepare("SELECT 'user' as type, id, name as title, email as subtitle, created_at
                                      FROM users
                                      WHERE name LIKE :query OR email LIKE :query
                                      LIMIT 5");
                $stmt->execute(['query' => $search_pattern]);
                $search_results = array_merge($search_results, $stmt->fetchAll());
            }
        } catch (Exception $e) {
            $search_results = [];
        }
    }

    // Hero / metrics derived ---------------------------------------------------
    $currentMonthLabel = (string) ($dashboardDateRange['period_label'] ?? date('F Y'));
    $currentHour = (int) date('G');
    $dashboardGreeting = $currentHour < 12
        ? 'Good morning'
        : ($currentHour < 18 ? 'Good afternoon' : 'Good evening');
    $totalTasksTracked = array_sum($taskSummary);
    $collectionRate = ($stats['total_revenue']['val'] ?? 0) > 0
        ? (int) round((($stats['collected']['val'] ?? 0) / $stats['total_revenue']['val']) * 100)
        : 0;
    $invoiceStatusTotal = array_sum(array_map('intval', $chartData['invoice_status'] ?? []));
    $projectStatusTotal = array_sum(array_map('intval', $chartData['project_status'] ?? []));
    $latestMonthIndex = max(0, count($chartData['months']) - 1);
    $latestTrendLabel = $chartData['months'][$latestMonthIndex] ?? $currentMonthLabel;
    $latestEstimationsTrend = (int) ($chartData['estimations_trend'][$latestMonthIndex] ?? 0);
    $latestInvoicesTrend = (int) ($chartData['invoices_trend'][$latestMonthIndex] ?? 0);
    $latestRevenueTrend = (float) ($chartData['revenue_trend'][$latestMonthIndex] ?? 0);
    $latestCollectedTrend = (float) ($chartData['collected_trend'][$latestMonthIndex] ?? 0);

    // Feature + metric tiles (used inside Performance modal) -------------------
    $dashboardFeatureCards = [];
    if (hasPermission('view_dashboard_revenue')) {
        $dashboardFeatureCards[] = [
            'tone' => 'primary',
            'label' => 'Collected',
            'value' => 'MK ' . dashboardCurrency($stats['collected']['val'] ?? 0),
            'icon' => 'wallet',
            'meta' => [
                ['label' => 'Rate', 'value' => $collectionRate . '%'],
                ['label' => 'Growth', 'value' => $stats['collected']['growth'] ?? '0%'],
            ],
            'footer_label' => 'Revenue',
            'footer_value' => 'MK ' . dashboardCurrency($stats['total_revenue']['val'] ?? 0),
            'href' => BASE_URL . 'modules/sales/index',
        ];
        $dashboardFeatureCards[] = [
            'tone' => 'soft',
            'label' => 'Outstanding',
            'value' => 'MK ' . dashboardCurrency($stats['outstanding']['val'] ?? 0),
            'icon' => 'receipt',
            'meta' => [
                ['label' => 'Unpaid', 'value' => number_format((int) ($stats['unpaid_invoices']['val'] ?? 0))],
                ['label' => 'Partial', 'value' => number_format((int) ($stats['partially_paid']['val'] ?? 0))],
            ],
            'footer_label' => 'Invoices',
            'footer_value' => number_format($invoiceStatusTotal),
            'href' => BASE_URL . 'modules/sales/index',
        ];
    } elseif (hasPermission('view_invoices')) {
        $dashboardFeatureCards[] = [
            'tone' => 'primary',
            'label' => 'Invoices',
            'value' => number_format((int) ($stats['invoices']['val'] ?? 0)),
            'icon' => 'receipt',
            'meta' => [
                ['label' => 'Latest', 'value' => number_format($latestInvoicesTrend)],
                ['label' => 'Growth', 'value' => $stats['invoices']['growth'] ?? '0%'],
            ],
            'footer_label' => 'Unpaid',
            'footer_value' => number_format((int) ($stats['unpaid_invoices']['val'] ?? 0)),
            'href' => BASE_URL . 'modules/invoices/list',
        ];
        $dashboardFeatureCards[] = [
            'tone' => 'soft',
            'label' => 'Projects',
            'value' => number_format((int) ($stats['active_projects']['val'] ?? 0)),
            'icon' => 'folder-open',
            'meta' => [
                ['label' => 'Tracked', 'value' => number_format($projectStatusTotal)],
                ['label' => 'Tasks', 'value' => number_format($totalTasksTracked)],
            ],
            'footer_label' => 'Open',
            'footer_value' => 'Workspace',
            'href' => BASE_URL . 'modules/projects/list',
        ];
    } else {
        $dashboardFeatureCards[] = [
            'tone' => 'primary',
            'label' => 'Projects',
            'value' => number_format((int) ($stats['active_projects']['val'] ?? 0)),
            'icon' => 'clipboard-list',
            'meta' => [
                ['label' => 'Tracked', 'value' => number_format($projectStatusTotal)],
                ['label' => 'Tasks', 'value' => number_format($totalTasksTracked)],
            ],
            'footer_label' => 'Period',
            'footer_value' => $currentMonthLabel,
            'href' => BASE_URL . 'modules/projects/list',
        ];
    }

    $dashboardMetricTiles = [];
    if (hasPermission('view_estimations')) {
        $dashboardMetricTiles[] = [
            'label' => 'Estimations',
            'value' => number_format((int) ($stats['estimations']['val'] ?? 0)),
            'href' => BASE_URL . 'modules/estimations/list',
        ];
    }
    if (hasPermission('view_invoices')) {
        $dashboardMetricTiles[] = [
            'label' => 'Invoices',
            'value' => number_format((int) ($stats['invoices']['val'] ?? 0)),
            'href' => BASE_URL . 'modules/invoices/list',
        ];
    }
    if (hasPermission('view_projects')) {
        $dashboardMetricTiles[] = [
            'label' => 'Active Projects',
            'value' => number_format((int) ($stats['active_projects']['val'] ?? 0)),
            'href' => BASE_URL . 'modules/projects/list',
        ];
    }
    if (hasPermission('view_dispatch')) {
        $dashboardMetricTiles[] = [
            'label' => 'Dispatch',
            'value' => number_format((int) ($stats['dispatched']['val'] ?? 0)),
            'href' => BASE_URL . 'modules/dispatch/list',
        ];
    }
    if (hasPermission('view_dashboard_revenue')) {
        $dashboardMetricTiles[] = [
            'label' => 'Collection Rate',
            'value' => $collectionRate . '%',
            'href' => BASE_URL . 'modules/sales/index',
        ];
    }
    if (hasPermission('view_tasks')) {
        $dashboardMetricTiles[] = [
            'label' => 'Tracked Tasks',
            'value' => number_format($totalTasksTracked),
            'href' => BASE_URL . 'modules/tasks/list',
        ];
    }
    if (hasPermission('view_users')) {
        $dashboardMetricTiles[] = [
            'label' => 'Users',
            'value' => number_format((int) ($stats['users']['val'] ?? 0)),
            'href' => BASE_URL . 'modules/hr/users/list',
        ];
    }
    $dashboardMetricTiles = array_slice($dashboardMetricTiles, 0, 6);

    // Recent activity feed -----------------------------------------------------
    $dashboardActivityItems = [];
    foreach (array_slice($recentTasks, 0, 4) as $task) {
        $dashboardActivityItems[] = [
            'href' => BASE_URL . 'modules/tasks/view?id=' . $task['id'],
            'icon' => $task['state_meta']['icon'] ?? 'clipboard-list',
            'tone' => !empty($task['is_overdue']) ? 'danger' : ($task['status'] === 'Completed' ? 'success' : 'info'),
            'title' => $task['title'],
            'subtitle' => $task['project_name'] ?: 'Task',
            'meta' => !empty($task['due_date']) ? date('M j, Y', strtotime($task['due_date'])) : 'No due date',
            'value' => $task['due_copy'],
        ];
    }
    if (count($dashboardActivityItems) < 4) {
        foreach ($recentProjects as $proj) {
            if (count($dashboardActivityItems) >= 4) {
                break;
            }
            $projectProgress = $proj['task_count'] > 0
                ? round(($proj['completed_tasks'] / $proj['task_count']) * 100)
                : 0;
            $dashboardActivityItems[] = [
                'href' => BASE_URL . 'modules/projects/view?id=' . $proj['id'],
                'icon' => 'folder-open',
                'tone' => $proj['status'] === 'Completed' ? 'success' : ($proj['status'] === 'Cancelled' ? 'danger' : 'neutral'),
                'title' => $proj['name'],
                'subtitle' => $proj['status'] ?: 'Project',
                'meta' => !empty($proj['deadline']) ? date('M j, Y', strtotime($proj['deadline'])) : 'No deadline',
                'value' => $projectProgress . '%',
            ];
        }
    }

    $dashboardOpenTaskCount =
        (int) ($dashboardTaskSummary['Pending'] ?? 0)
        + (int) ($dashboardTaskSummary['Started'] ?? 0)
        + (int) ($dashboardTaskSummary['In Progress'] ?? 0)
        + (int) ($dashboardTaskSummary['In Review'] ?? 0);

    // Today's Focus ------------------------------------------------------------
    $dashboardFocusItems = [];
    if (hasPermission('view_tasks')) {
        $dashboardFocusItems[] = [
            'label' => 'Overdue tasks',
            'value' => number_format((int) ($dashboardTaskSummary['Overdue'] ?? 0)),
            'note' => ((int) ($dashboardTaskSummary['Overdue'] ?? 0)) > 0
                ? 'Review tasks that slipped their due date and clear bottlenecks.'
                : 'No overdue tasks are currently flagged.',
            'tone' => ((int) ($dashboardTaskSummary['Overdue'] ?? 0)) > 0 ? 'danger' : 'success',
            'icon' => ((int) ($dashboardTaskSummary['Overdue'] ?? 0)) > 0 ? 'triangle-alert' : 'circle-check',
            'target' => 'wsModalTasks',
            'href' => BASE_URL . 'modules/tasks/list?my_tasks=1',
        ];
        $dashboardFocusItems[] = [
            'label' => 'Reminder attention',
            'value' => number_format((int) $dashboardReminderAttentionCount),
            'note' => $dashboardReminderAttentionCount > 0
                ? 'Due-today and overdue reminder cards waiting for action.'
                : 'Reminder pressure is under control at the moment.',
            'tone' => $dashboardReminderAttentionCount > 0 ? 'warning' : 'accent',
            'icon' => $dashboardReminderAttentionCount > 0 ? 'calendar-clock' : 'bell-dot',
            'target' => 'wsModalReminders',
            'href' => BASE_URL . 'modules/reminders/index?scope=my_day',
        ];
    }
    if (hasPermission('view_projects')) {
        $dashboardFocusItems[] = [
            'label' => 'Portfolio load',
            'value' => number_format((int) ($stats['active_projects']['val'] ?? 0)),
            'note' => 'Open the projects dashboard to review execution health and deadlines.',
            'tone' => 'accent',
            'icon' => 'folder-open',
            'target' => 'wsModalProjects',
            'href' => BASE_URL . 'modules/projects/list',
        ];
    }
    if (hasPermission('view_dashboard_revenue')) {
        $dashboardFocusItems[] = [
            'label' => 'Outstanding revenue',
            'value' => 'MK ' . dashboardCurrency($stats['outstanding']['val'] ?? 0),
            'note' => 'Use the performance and revenue views to keep collections moving.',
            'tone' => 'neutral',
            'icon' => 'credit-card',
            'target' => 'wsModalPerformance',
            'href' => BASE_URL . 'modules/sales/index',
        ];
    }
    $dashboardFocusItems = array_slice($dashboardFocusItems, 0, 4);

    // Debtors panel ------------------------------------------------------------
    $dashboardCanViewDebtorsPanel = hasPermission('view_dashboard_revenue') || hasPermission('view_invoices');
    $dashboardDebtors = [];
    $dashboardDebtorsTotalBalance = 0.0;
    $dashboardDebtorsCriticalCount = 0;
    $dashboardDebtorsReminderAt = date('Y-m-d\T09:00', strtotime('+1 day'));

    if ($dashboardCanViewDebtorsPanel) {
        try {
            $stmt = $pdo->query(
                "SELECT
                    COALESCE(NULLIF(TRIM(i.customer_name), ''), NULLIF(TRIM(e.customer_name), ''), 'Unknown debtor') AS debtor_name,
                    COUNT(*) AS invoice_count,
                    COALESCE(SUM(i.balance), 0) AS balance,
                    MAX(GREATEST(DATEDIFF(CURDATE(), COALESCE(i.due_date, i.generated_date)), 0)) AS max_age_days,
                    MIN(COALESCE(i.due_date, i.generated_date)) AS oldest_due_date,
                    MAX(i.id) AS latest_invoice_id,
                    COALESCE(MAX(NULLIF(TRIM(i.customer_email), '')), MAX(NULLIF(TRIM(e.customer_email), ''))) AS customer_email,
                    COALESCE(MAX(NULLIF(TRIM(i.customer_phone), '')), MAX(NULLIF(TRIM(e.customer_phone), ''))) AS customer_phone
                 FROM invoices i
                 LEFT JOIN estimations e ON e.id = i.estimation_id
                 WHERE i.status IN ('Unpaid', 'Partially Paid', 'Overdue')
                   AND i.balance > 0
                 GROUP BY debtor_name
                 ORDER BY balance DESC, max_age_days DESC
                 LIMIT 8"
            );
            $dashboardDebtors = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($dashboardDebtors as $debtor) {
                $dashboardDebtorsTotalBalance += (float) ($debtor['balance'] ?? 0);
                if ((int) ($debtor['max_age_days'] ?? 0) > 60) {
                    $dashboardDebtorsCriticalCount++;
                }
            }
        } catch (Exception $e) {
            $dashboardDebtors = [];
        }
    }

    // Operational dashboard sections ------------------------------------------
    $dashboardWorkOrderModule = dashboardModuleMeta('workorders');
    $dashboardWorkOrdersPanel = [
        'available' => permissions_can_view_work_orders() && !empty($dashboardWorkOrderModule['available']),
        'href' => (string) ($dashboardWorkOrderModule['href'] ?? ''),
        'slug' => (string) ($dashboardWorkOrderModule['slug'] ?? ''),
        'summary' => 'Track and manage production job workflows.',
        'active' => 0,
        'in_production' => 0,
        'awaiting_dispatch' => 0,
        'completed' => 0,
        'overdue' => 0,
        'urgent' => 0,
        'total' => 0,
    ];
    if (!empty($dashboardWorkOrdersPanel['available'])) {
        try {
            $workOrderKpiStmt = $pdo->query(
                "SELECT
                    COALESCE(SUM(CASE WHEN status NOT IN ('Completed', 'Cancelled') THEN 1 ELSE 0 END), 0) AS active_count,
                    COALESCE(SUM(CASE WHEN status = 'In Production' THEN 1 ELSE 0 END), 0) AS in_production,
                    COALESCE(SUM(CASE WHEN status = 'Awaiting Dispatch' THEN 1 ELSE 0 END), 0) AS awaiting_dispatch,
                    COALESCE(SUM(CASE WHEN status = 'Completed' THEN 1 ELSE 0 END), 0) AS completed,
                    COALESCE(SUM(CASE
                        WHEN due_date IS NOT NULL
                             AND due_date < CURDATE()
                             AND status NOT IN ('Completed', 'Cancelled')
                        THEN 1 ELSE 0 END), 0) AS overdue_count,
                    COALESCE(SUM(CASE
                        WHEN priority IN ('Urgent', 'Critical')
                             AND status NOT IN ('Completed', 'Cancelled')
                        THEN 1 ELSE 0 END), 0) AS urgent_count,
                    COUNT(*) AS total
                 FROM work_orders"
            );
            $workOrderKpiRow = $workOrderKpiStmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $dashboardWorkOrdersPanel['active'] = (int) ($workOrderKpiRow['active_count'] ?? 0);
            $dashboardWorkOrdersPanel['in_production'] = (int) ($workOrderKpiRow['in_production'] ?? 0);
            $dashboardWorkOrdersPanel['awaiting_dispatch'] = (int) ($workOrderKpiRow['awaiting_dispatch'] ?? 0);
            $dashboardWorkOrdersPanel['completed'] = (int) ($workOrderKpiRow['completed'] ?? 0);
            $dashboardWorkOrdersPanel['overdue'] = (int) ($workOrderKpiRow['overdue_count'] ?? 0);
            $dashboardWorkOrdersPanel['urgent'] = (int) ($workOrderKpiRow['urgent_count'] ?? 0);
            $dashboardWorkOrdersPanel['total'] = (int) ($workOrderKpiRow['total'] ?? 0);

            $summaryParts = [];
            if ($dashboardWorkOrdersPanel['in_production'] > 0) {
                $summaryParts[] = number_format($dashboardWorkOrdersPanel['in_production']) . ' in production';
            }
            if ($dashboardWorkOrdersPanel['awaiting_dispatch'] > 0) {
                $summaryParts[] = number_format($dashboardWorkOrdersPanel['awaiting_dispatch']) . ' awaiting dispatch';
            }
            if ($dashboardWorkOrdersPanel['overdue'] > 0) {
                $summaryParts[] = number_format($dashboardWorkOrdersPanel['overdue']) . ' overdue';
            }
            $dashboardWorkOrdersPanel['summary'] = !empty($summaryParts)
                ? implode(' · ', $summaryParts)
                : (
                    $dashboardWorkOrdersPanel['active'] > 0
                        ? number_format($dashboardWorkOrdersPanel['active']) . ' open work orders'
                        : 'No open production jobs right now.'
                );

            require_once __DIR__ . '/work_order_helper.php';
            require_once __DIR__ . '/work_order_dashboard_helper.php';
            work_order_bootstrap($pdo);
            $dashboardWorkOrdersPanel['active_queue'] = work_order_dashboard_active_queue($pdo, 5);
        } catch (Throwable $exception) {
            // Keep defaults when the work-order schema is unavailable.
        }
    }
    if (!isset($dashboardWorkOrdersPanel['active_queue'])) {
        $dashboardWorkOrdersPanel['active_queue'] = [];
    }
    $dashboardOpenInvoiceCount = 0;
    $dashboardOverdueInvoiceCount = 0;
    $dashboardDueTodayCount = 0;
    $dashboardHighPriorityTodayCount = 0;
    $dashboardPrimaryCards = [];
    $dashboardPendingApprovals = [];
    $dashboardMaterialsSnapshot = [];
    $dashboardFinanceRows = [];
    $dashboardReceivablesSummary = [
        'debtors' => 0,
        'overdue' => 0,
        'age_0_30' => 0,
        'age_31_60' => 0,
        'age_61_plus' => 0,
        'total_balance' => 0.0,
        'overdue_balance' => 0.0,
        'balance_0_30' => 0.0,
        'balance_31_60' => 0.0,
        'balance_61_plus' => 0.0,
        'avg_age_days' => 0,
        'oldest_age_days' => 0,
    ];
    $dashboardTodayDateLabel = date('d M Y');
    $dashboardTodayWeekday = date('l');

    try {
        $invoiceLoadStmt = $pdo->query(
            "SELECT
                COALESCE(SUM(CASE WHEN status IN ('Unpaid', 'Partially Paid', 'Overdue') THEN 1 ELSE 0 END), 0) AS open_count,
                COALESCE(SUM(CASE
                    WHEN status = 'Overdue'
                         OR (status IN ('Unpaid', 'Partially Paid') AND due_date IS NOT NULL AND due_date < CURDATE())
                    THEN 1 ELSE 0 END), 0) AS overdue_count
             FROM invoices"
        );
        $invoiceLoadRow = $invoiceLoadStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $dashboardOpenInvoiceCount = (int) ($invoiceLoadRow['open_count'] ?? 0);
        $dashboardOverdueInvoiceCount = (int) ($invoiceLoadRow['overdue_count'] ?? 0);
    } catch (Throwable $exception) {
        $dashboardOpenInvoiceCount = 0;
        $dashboardOverdueInvoiceCount = 0;
    }

    if (hasPermission('view_tasks') && $userId > 0) {
        try {
            $dueTodayStmt = $pdo->prepare(
                "SELECT
                    COUNT(*) AS due_today_count,
                    COALESCE(SUM(CASE WHEN t.priority IN ('High', 'Urgent') THEN 1 ELSE 0 END), 0) AS high_priority_count
                 FROM tasks t
                 WHERE t.due_date = CURDATE()
                   AND t.status NOT IN ('Completed', 'Cancelled')
                   AND (
                       t.assigned_to = :uid
                       OR EXISTS (
                           SELECT 1
                           FROM task_assignees ta
                           WHERE ta.task_id = t.id AND ta.user_id = :uid2
                       )
                   )"
            );
            $dueTodayStmt->execute(['uid' => $userId, 'uid2' => $userId]);
            $dueTodayRow = $dueTodayStmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $dashboardDueTodayCount = (int) ($dueTodayRow['due_today_count'] ?? 0);
            $dashboardHighPriorityTodayCount = (int) ($dueTodayRow['high_priority_count'] ?? 0);
        } catch (Throwable $exception) {
            $dashboardDueTodayCount = 0;
            $dashboardHighPriorityTodayCount = 0;
        }
    }

    $approvalQueue = [];
    if (hasPermission('view_projects')) {
        try {
            $approvalVis = project_visibility_sql_where_for_projects('p', $userId, $pdo);
            $projectApprovalSql =
                "SELECT p.id, p.name, p.end_date, p.created_at, u.name AS owner_name
                 FROM projects p
                 LEFT JOIN users u ON u.id = p.created_by
                 WHERE p.approved_status = 'Pending'
                   AND p.status NOT IN ('Completed', 'Cancelled')
                   {$approvalVis['clause']}
                 ORDER BY COALESCE(p.end_date, DATE_ADD(CURDATE(), INTERVAL 365 DAY)) ASC, p.created_at ASC
                 LIMIT 4";
            $projectApprovalStmt = $pdo->prepare($projectApprovalSql);
            foreach ($approvalVis['binds'] as $bindKey => $bindValue) {
                $projectApprovalStmt->bindValue(':' . $bindKey, $bindValue, PDO::PARAM_INT);
            }
            $projectApprovalStmt->execute();
            foreach ($projectApprovalStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $sortAt = !empty($row['end_date']) ? (string) $row['end_date'] : date('Y-m-d', strtotime((string) $row['created_at']));
                $approvalQueue[] = [
                    'type' => 'Project',
                    'icon' => 'folder-open',
                    'title' => (string) ($row['name'] ?? 'Untitled project'),
                    'subtitle' => !empty($row['owner_name'])
                        ? 'Project approval for ' . $row['owner_name']
                        : 'Project approval pending',
                    'value' => !empty($row['end_date'])
                        ? 'Due ' . date('d M Y', strtotime((string) $row['end_date']))
                        : 'Awaiting sign-off',
                    'age_label' => dashboardRelativeAgeLabel($row['created_at'] ?? null),
                    'href' => BASE_URL . 'modules/projects/view?id=' . (int) ($row['id'] ?? 0),
                    'sort_key' => $sortAt,
                ];
            }
        } catch (Throwable $exception) {
        }
    }

    if (hasPermission('view_tasks') && $userId > 0) {
        try {
            $taskApprovalStmt = $pdo->prepare(
                "SELECT t.id, t.name, t.status, t.priority, t.due_date, t.created_at, p.name AS project_name
                 FROM tasks t
                 LEFT JOIN projects p ON p.id = t.project_id
                 WHERE (t.status = 'In Review' OR (t.status = 'Completed' AND t.approved_by IS NULL))
                   AND (
                       t.assigned_to = :uid
                       OR EXISTS (
                           SELECT 1
                           FROM task_assignees ta
                           WHERE ta.task_id = t.id AND ta.user_id = :uid2
                       )
                   )
                 ORDER BY CASE WHEN t.due_date IS NULL THEN 1 ELSE 0 END, t.due_date ASC, t.created_at ASC
                 LIMIT 4"
            );
            $taskApprovalStmt->execute(['uid' => $userId, 'uid2' => $userId]);
            foreach ($taskApprovalStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $dueCopy = !empty($row['due_date'])
                    ? dashboardTaskDueCopy((string) $row['due_date'], false)
                    : ucfirst(strtolower((string) ($row['priority'] ?? 'Medium'))) . ' priority';
                $approvalQueue[] = [
                    'type' => 'Task',
                    'icon' => 'clipboard-check',
                    'title' => (string) ($row['name'] ?? 'Untitled task'),
                    'subtitle' => !empty($row['project_name'])
                        ? 'Task review in ' . $row['project_name']
                        : 'Task review pending',
                    'value' => $dueCopy,
                    'age_label' => dashboardRelativeAgeLabel($row['created_at'] ?? null),
                    'href' => BASE_URL . 'modules/tasks/view?id=' . (int) ($row['id'] ?? 0),
                    'sort_key' => !empty($row['due_date'])
                        ? (string) $row['due_date']
                        : date('Y-m-d', strtotime((string) $row['created_at'])),
                ];
            }
        } catch (Throwable $exception) {
        }
    }

    if (hasPermission('view_estimations')) {
        try {
            $estimationApprovalStmt = $pdo->prepare(
                "SELECT id, estimation_number, customer_name, total_amount, created_at
                 FROM estimations
                 WHERE status = 'Performer Invoiced'
                 ORDER BY created_at ASC
                 LIMIT 4"
            );
            $estimationApprovalStmt->execute();
            foreach ($estimationApprovalStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $approvalQueue[] = [
                    'type' => 'Estimation',
                    'icon' => 'file-text',
                    'title' => (string) ($row['estimation_number'] ?? 'Estimation'),
                    'subtitle' => !empty($row['customer_name'])
                        ? (string) $row['customer_name']
                        : 'Awaiting customer approval',
                    'value' => 'MK ' . dashboardCurrency($row['total_amount'] ?? 0),
                    'age_label' => dashboardRelativeAgeLabel($row['created_at'] ?? null),
                    'href' => BASE_URL . 'modules/estimations/view?id=' . (int) ($row['id'] ?? 0),
                    'sort_key' => date('Y-m-d', strtotime((string) $row['created_at'])),
                ];
            }
        } catch (Throwable $exception) {
        }
    }

    usort($approvalQueue, static function (array $left, array $right): int {
        $dateCompare = strcmp((string) ($left['sort_key'] ?? ''), (string) ($right['sort_key'] ?? ''));
        if ($dateCompare !== 0) {
            return $dateCompare;
        }

        return strcmp((string) ($left['title'] ?? ''), (string) ($right['title'] ?? ''));
    });
    $dashboardPendingApprovals = array_slice($approvalQueue, 0, 4);

    if (hasPermission('view_materials')) {
        try {
            $materialsStmt = $pdo->query(
                "SELECT
                    m.id,
                    m.name,
                    m.unit,
                    m.description,
                    latest.rate,
                    latest.effective_date
                 FROM materials m
                 LEFT JOIN (
                     SELECT mr.material_id, mr.rate, mr.effective_date
                     FROM material_rates mr
                     INNER JOIN (
                         SELECT material_id, MAX(id) AS latest_id
                         FROM material_rates
                         GROUP BY material_id
                     ) latest_rate ON latest_rate.latest_id = mr.id
                 ) latest ON latest.material_id = m.id
                 ORDER BY
                    CASE
                        WHEN m.name IN ('Proofing Paper', 'Film', 'Plate', 'Colour Separation') THEN 0
                        ELSE 1
                    END,
                    FIELD(m.name, 'Proofing Paper', 'Film', 'Plate', 'Colour Separation'),
                    m.name ASC
                 LIMIT 4"
            );
            foreach ($materialsStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $rateMeta = dashboardMaterialRateMeta($row['rate'] ?? 0, $row['effective_date'] ?? null);
                $dashboardMaterialsSnapshot[] = [
                    'id' => (int) ($row['id'] ?? 0),
                    'name' => (string) ($row['name'] ?? 'Material'),
                    'unit' => (string) ($row['unit'] ?? 'Unit'),
                    'rate' => (float) ($row['rate'] ?? 0),
                    'rate_label' => !empty($row['rate']) ? 'MK ' . dashboardCurrency($row['rate']) : 'No rate',
                    'effective_copy' => !empty($row['effective_date'])
                        ? date('d M Y', strtotime((string) $row['effective_date']))
                        : 'Awaiting rate',
                    'description_excerpt' => dashboardTaskExcerptPlain($row['description'] ?? '', 44),
                    'status_label' => $rateMeta['label'],
                    'status_tone' => $rateMeta['tone'],
                    'href' => BASE_URL . 'modules/materials/edit?id=' . (int) ($row['id'] ?? 0),
                ];
            }
        } catch (Throwable $exception) {
            $dashboardMaterialsSnapshot = [];
        }
    }

    if ($dashboardCanViewDebtorsPanel) {
        try {
            $receivablesSummaryStmt = $pdo->query(
                "SELECT
                    COUNT(DISTINCT COALESCE(NULLIF(TRIM(i.customer_name), ''), NULLIF(TRIM(e.customer_name), ''), CONCAT('invoice-', i.id))) AS debtor_count,
                    COALESCE(SUM(i.balance), 0) AS total_balance,
                    COALESCE(SUM(CASE
                        WHEN i.status = 'Overdue'
                             OR (i.due_date IS NOT NULL AND i.due_date < CURDATE() AND i.status IN ('Unpaid', 'Partially Paid'))
                        THEN 1 ELSE 0 END), 0) AS overdue_count,
                    COALESCE(SUM(CASE
                        WHEN i.status = 'Overdue'
                             OR (i.due_date IS NOT NULL AND i.due_date < CURDATE() AND i.status IN ('Unpaid', 'Partially Paid'))
                        THEN i.balance ELSE 0 END), 0) AS overdue_balance,
                    COALESCE(SUM(CASE
                        WHEN GREATEST(DATEDIFF(CURDATE(), COALESCE(i.due_date, i.generated_date)), 0) BETWEEN 0 AND 30
                        THEN 1 ELSE 0 END), 0) AS age_0_30,
                    COALESCE(SUM(CASE
                        WHEN GREATEST(DATEDIFF(CURDATE(), COALESCE(i.due_date, i.generated_date)), 0) BETWEEN 31 AND 60
                        THEN 1 ELSE 0 END), 0) AS age_31_60,
                    COALESCE(SUM(CASE
                        WHEN GREATEST(DATEDIFF(CURDATE(), COALESCE(i.due_date, i.generated_date)), 0) > 60
                        THEN 1 ELSE 0 END), 0) AS age_61_plus,
                    COALESCE(SUM(CASE
                        WHEN GREATEST(DATEDIFF(CURDATE(), COALESCE(i.due_date, i.generated_date)), 0) BETWEEN 0 AND 30
                        THEN i.balance ELSE 0 END), 0) AS balance_0_30,
                    COALESCE(SUM(CASE
                        WHEN GREATEST(DATEDIFF(CURDATE(), COALESCE(i.due_date, i.generated_date)), 0) BETWEEN 31 AND 60
                        THEN i.balance ELSE 0 END), 0) AS balance_31_60,
                    COALESCE(SUM(CASE
                        WHEN GREATEST(DATEDIFF(CURDATE(), COALESCE(i.due_date, i.generated_date)), 0) > 60
                        THEN i.balance ELSE 0 END), 0) AS balance_61_plus,
                    COALESCE(AVG(GREATEST(DATEDIFF(CURDATE(), COALESCE(i.due_date, i.generated_date)), 0)), 0) AS avg_age_days,
                    COALESCE(MAX(GREATEST(DATEDIFF(CURDATE(), COALESCE(i.due_date, i.generated_date)), 0)), 0) AS oldest_age_days
                 FROM invoices i
                 LEFT JOIN estimations e ON e.id = i.estimation_id
                 WHERE i.status IN ('Unpaid', 'Partially Paid', 'Overdue')
                   AND i.balance > 0"
            );
            $receivablesRow = $receivablesSummaryStmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $dashboardReceivablesSummary = [
                'debtors' => (int) ($receivablesRow['debtor_count'] ?? 0),
                'overdue' => (int) ($receivablesRow['overdue_count'] ?? 0),
                'age_0_30' => (int) ($receivablesRow['age_0_30'] ?? 0),
                'age_31_60' => (int) ($receivablesRow['age_31_60'] ?? 0),
                'age_61_plus' => (int) ($receivablesRow['age_61_plus'] ?? 0),
                'total_balance' => (float) ($receivablesRow['total_balance'] ?? 0),
                'overdue_balance' => (float) ($receivablesRow['overdue_balance'] ?? 0),
                'balance_0_30' => (float) ($receivablesRow['balance_0_30'] ?? 0),
                'balance_31_60' => (float) ($receivablesRow['balance_31_60'] ?? 0),
                'balance_61_plus' => (float) ($receivablesRow['balance_61_plus'] ?? 0),
                'avg_age_days' => (int) round((float) ($receivablesRow['avg_age_days'] ?? 0)),
                'oldest_age_days' => (int) ($receivablesRow['oldest_age_days'] ?? 0),
            ];

            $criticalDebtorsStmt = $pdo->query(
                "SELECT COUNT(*) AS critical_count
                 FROM (
                     SELECT COALESCE(NULLIF(TRIM(i.customer_name), ''), NULLIF(TRIM(e.customer_name), ''), 'Unknown debtor') AS debtor_name
                     FROM invoices i
                     LEFT JOIN estimations e ON e.id = i.estimation_id
                     WHERE i.status IN ('Unpaid', 'Partially Paid', 'Overdue')
                       AND i.balance > 0
                     GROUP BY debtor_name
                     HAVING MAX(GREATEST(DATEDIFF(CURDATE(), COALESCE(i.due_date, i.generated_date)), 0)) > 60
                 ) critical_debtors"
            );
            $criticalDebtorsRow = $criticalDebtorsStmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $dashboardDebtorsCriticalCount = (int) ($criticalDebtorsRow['critical_count'] ?? 0);
        } catch (Throwable $exception) {
            $dashboardReceivablesSummary = [
                'debtors' => 0,
                'overdue' => 0,
                'age_0_30' => 0,
                'age_31_60' => 0,
                'age_61_plus' => 0,
                'total_balance' => 0.0,
                'overdue_balance' => 0.0,
                'balance_0_30' => 0.0,
                'balance_31_60' => 0.0,
                'balance_61_plus' => 0.0,
                'avg_age_days' => 0,
                'oldest_age_days' => 0,
            ];
        }
    }

    if (hasPermission('view_dashboard_revenue')) {
        $dashboardFinanceRows[] = [
            'label' => 'Revenue (' . $currentMonthLabel . ')',
            'value' => 'MK ' . dashboardCurrency($stats['total_revenue']['current'] ?? 0),
            'change' => $stats['total_revenue']['growth'] ?? '0%',
        ];
        $dashboardFinanceRows[] = [
            'label' => 'Collected (' . $currentMonthLabel . ')',
            'value' => 'MK ' . dashboardCurrency($stats['collected']['current'] ?? 0),
            'change' => $stats['collected']['growth'] ?? '0%',
        ];
        $dashboardFinanceRows[] = [
            'label' => 'Outstanding',
            'value' => 'MK ' . dashboardCurrency($stats['outstanding']['val'] ?? 0),
            'change' => $stats['outstanding']['growth'] ?? '0%',
        ];
    }
    if (hasPermission('view_invoices') || hasPermission('view_dashboard_revenue')) {
        $dashboardFinanceRows[] = [
            'label' => 'Open invoices',
            'value' => number_format($dashboardOpenInvoiceCount),
            'change' => $dashboardOverdueInvoiceCount > 0
                ? $dashboardOverdueInvoiceCount . ' overdue'
                : 'On track',
        ];
        $dashboardFinanceRows[] = [
            'label' => 'Collection rate',
            'value' => $collectionRate . '%',
            'change' => $latestTrendLabel,
        ];
    }
    $dashboardFinanceRows = array_slice($dashboardFinanceRows, 0, 5);

    if (hasPermission('view_dashboard_revenue')) {
        $revenueGrowth = (string) ($stats['total_revenue']['growth'] ?? '0%');
        $dashboardPrimaryCards[] = [
            'label' => 'Total Revenue',
            'value' => 'MK ' . dashboardCurrency($stats['total_revenue']['current'] ?? 0),
            'note' => $revenueGrowth . ' vs prior period',
            'growth' => $revenueGrowth,
            'tone' => strpos($revenueGrowth, '-') !== false ? 'danger' : 'success',
            'icon' => 'wallet',
            'href' => BASE_URL . 'modules/sales/index',
            'target' => '',
        ];
    }

    if (hasPermission('view_invoices') || hasPermission('view_dashboard_revenue')) {
        $outstandingGrowth = (string) ($stats['outstanding']['growth'] ?? '0%');
        $dashboardPrimaryCards[] = [
            'label' => 'Open Invoices',
            'value' => 'MK ' . dashboardCurrency($stats['outstanding']['val'] ?? 0),
            'note' => $dashboardOverdueInvoiceCount > 0
                ? $dashboardOverdueInvoiceCount . ' overdue'
                : number_format($dashboardOpenInvoiceCount) . ' open invoices',
            'growth' => $outstandingGrowth,
            'tone' => $dashboardOverdueInvoiceCount > 0 ? 'danger' : 'warning',
            'icon' => 'receipt',
            'href' => BASE_URL . 'modules/invoices/list?status=unpaid',
            'target' => '',
        ];
    }

    if (!empty($dashboardWorkOrdersPanel['available'])) {
        $workOrderOverdue = (int) ($dashboardWorkOrdersPanel['overdue'] ?? 0);
        $workOrderUrgent = (int) ($dashboardWorkOrdersPanel['urgent'] ?? 0);
        $workOrderInProduction = (int) ($dashboardWorkOrdersPanel['in_production'] ?? 0);
        $workOrderAwaitingDispatch = (int) ($dashboardWorkOrdersPanel['awaiting_dispatch'] ?? 0);
        $workOrderActive = (int) ($dashboardWorkOrdersPanel['active'] ?? 0);
        if ($workOrderOverdue > 0) {
            $workOrderNote = number_format($workOrderOverdue) . ' overdue';
            $workOrderTone = 'danger';
        } elseif ($workOrderUrgent > 0) {
            $workOrderNote = number_format($workOrderUrgent) . ' urgent';
            $workOrderTone = 'warning';
        } elseif ($workOrderInProduction > 0) {
            $workOrderNote = number_format($workOrderInProduction) . ' in production';
            $workOrderTone = 'warning';
        } elseif ($workOrderAwaitingDispatch > 0) {
            $workOrderNote = number_format($workOrderAwaitingDispatch) . ' awaiting dispatch';
            $workOrderTone = 'neutral';
        } else {
            $workOrderNote = $workOrderActive > 0
                ? number_format($workOrderActive) . ' open jobs'
                : 'No open production jobs';
            $workOrderTone = 'success';
        }

        $dashboardPrimaryCards[] = [
            'label' => 'Active Work Orders',
            'value' => number_format($workOrderActive),
            'note' => $workOrderNote,
            'growth' => $workOrderOverdue > 0 ? '+' . number_format($workOrderOverdue) . ' overdue' : '0%',
            'tone' => $workOrderTone,
            'icon' => 'briefcase',
            'href' => BASE_URL . 'modules/work_orders/list?status=In+Production',
            'target' => '',
        ];
    }

    if (hasPermission('view_tasks')) {
        $dashboardPrimaryCards[] = [
            'label' => 'Tasks Due Today',
            'value' => number_format($dashboardDueTodayCount),
            'note' => $dashboardHighPriorityTodayCount > 0
                ? $dashboardHighPriorityTodayCount . ' high priority'
                : 'No high priority due today',
            'growth' => $dashboardHighPriorityTodayCount > 0 ? '+' . number_format($dashboardHighPriorityTodayCount) : '0%',
            'tone' => $dashboardHighPriorityTodayCount > 0 ? 'danger' : 'success',
            'icon' => 'calendar-clock',
            'href' => BASE_URL . 'modules/tasks/list?my_tasks=1',
            'target' => '',
        ];
    }

    if (hasPermission('view_projects')) {
        $projectGrowth = (string) ($stats['active_projects']['growth'] ?? '0%');
        $dashboardPrimaryCards[] = [
            'label' => 'Active Projects',
            'value' => number_format((int) ($stats['active_projects']['val'] ?? 0)),
            'note' => $projectGrowth . ' vs prior period',
            'growth' => $projectGrowth,
            'tone' => 'neutral',
            'icon' => 'folder-open',
            'href' => BASE_URL . 'modules/projects/list?status=In+Progress',
            'target' => '',
        ];
    }

    if (hasPermission('view_dispatch')) {
        $dispatchGrowth = (string) ($stats['dispatched']['growth'] ?? '0%');
        $dashboardPrimaryCards[] = [
            'label' => 'Dispatch Today',
            'value' => number_format((int) ($stats['dispatched']['val'] ?? 0)),
            'note' => ($stats['dispatched']['current'] ?? 0) . ' in selected period',
            'growth' => $dispatchGrowth,
            'tone' => 'neutral',
            'icon' => 'truck',
            'href' => BASE_URL . 'modules/dispatch/list',
            'target' => '',
        ];
    }

    if (hasPermission('view_estimations')) {
        $estimationGrowth = (string) ($stats['estimations']['growth'] ?? '0%');
        $dashboardPrimaryCards[] = [
            'label' => 'Estimations',
            'value' => number_format((int) ($stats['estimations']['current'] ?? 0)),
            'note' => $currentMonthLabel,
            'growth' => $estimationGrowth,
            'tone' => 'success',
            'icon' => 'file-text',
            'href' => BASE_URL . 'modules/estimations/list',
            'target' => '',
        ];
    }
    $dashboardPrimaryCards = dashboard_prioritize_primary_cards($dashboardPrimaryCards, $dashboardPersona);

    // Hero metric cards --------------------------------------------------------
    $dashboardProjectCount = (int) ($stats['active_projects']['val'] ?? 0);
    $dashboardProjectBase = max(1, $projectStatusTotal > 0 ? $projectStatusTotal : $dashboardProjectCount);
    $dashboardAvgProjectEarnings = ($stats['total_revenue']['val'] ?? 0) > 0
        ? ((float) ($stats['total_revenue']['val'] ?? 0) / $dashboardProjectBase)
        : 0;
    $dashboardProductivity = $collectionRate;
    $dashboardHeroCards = [];
    if (hasPermission('view_projects')) {
        $dashboardHeroCards[] = [
            'label' => 'Total Projects',
            'value' => number_format($dashboardProjectCount),
            'icon' => 'briefcase',
            'growth' => $stats['active_projects']['growth'] ?? '0%',
            'meta' => 'Since last month',
            'tone' => 'violet',
            'target' => 'wsModalProjects',
        ];
    }
    if (hasPermission('view_tasks')) {
        $dashboardHeroCards[] = [
            'label' => 'Total Tasks',
            'value' => number_format($totalTasksTracked),
            'icon' => 'clipboard-list',
            'growth' => $dashboardOpenTaskCount > 0 ? '+' . number_format($dashboardOpenTaskCount) : '0%',
            'meta' => 'Open work in motion',
            'tone' => 'pink',
            'target' => 'wsModalTasks',
        ];
    }
    if (hasPermission('view_dashboard_revenue')) {
        $dashboardHeroCards[] = [
            'label' => 'Avg. Project Earnings',
            'value' => 'MK ' . dashboardCurrency($dashboardAvgProjectEarnings),
            'icon' => 'wallet',
            'growth' => $stats['total_revenue']['growth'] ?? '0%',
            'meta' => 'Since last month',
            'tone' => 'amber',
            'target' => 'wsModalPerformance',
        ];
        $dashboardHeroCards[] = [
            'label' => 'Productivity',
            'value' => $dashboardProductivity . '%',
            'icon' => 'trending-up',
            'growth' => $stats['collected']['growth'] ?? '0%',
            'meta' => 'Collection health',
            'tone' => 'green',
            'target' => 'wsModalReports',
        ];
    }

    // Calendar / schedule ------------------------------------------------------
    $dashboardCalMonth = trim((string) ($params['cal_month'] ?? $_GET['cal_month'] ?? ''));
    if (!preg_match('/^\d{4}-\d{2}$/', $dashboardCalMonth)) {
        $dashboardCalMonth = date('Y-m');
    }
    $dashboardCalendarStart = $dashboardCalMonth . '-01';
    $dashboardCalendarEnd = date('Y-m-t', strtotime($dashboardCalendarStart));

    $dashboardSelectedDayRaw = trim((string) ($params['cal_day'] ?? $_GET['cal_day'] ?? ''));
    $dashboardTodayYmd = date('Y-m-d');
    $dashboardSelectedDay = preg_match('/^\d{4}-\d{2}-\d{2}$/', $dashboardSelectedDayRaw)
        ? $dashboardSelectedDayRaw
        : $dashboardTodayYmd;
    if (date('Y-m', strtotime($dashboardSelectedDay)) !== $dashboardCalMonth) {
        $dashboardSelectedDay = $dashboardCalMonth === date('Y-m')
            ? $dashboardTodayYmd
            : $dashboardCalendarStart;
    }

    $dashboardCalendarRows = [];
    $dashboardCalendarByDay = [];
    if ($userId > 0 && reminder_module_ready($pdo, true)) {
        $dashboardCalendarRows = fetch_reminders_for_calendar_range(
            $pdo,
            $userId,
            $dashboardCalendarStart,
            $dashboardCalendarEnd,
            ['status' => 'active', 'source' => 'all']
        );
        $dashboardCalendarByDay = group_reminders_by_calendar_day($dashboardCalendarRows);
    }

    $dashboardPrevMonth = date('Y-m', strtotime($dashboardCalendarStart . ' -1 month'));
    $dashboardNextMonth = date('Y-m', strtotime($dashboardCalendarStart . ' +1 month'));
    $dashboardCalendarLabel = date('F', strtotime($dashboardCalendarStart));
    $dashboardCalendarYear = date('Y', strtotime($dashboardCalendarStart));
    $dashboardCalendarGridStart = strtotime(
        '-' . ((int) date('w', strtotime($dashboardCalendarStart))) . ' days',
        strtotime($dashboardCalendarStart)
    );
    $dashboardSelectedItems = array_slice($dashboardCalendarByDay[$dashboardSelectedDay] ?? [], 0, 4);
    $dashboardSelectedReminderAt = date('Y-m-d\T09:00', strtotime($dashboardSelectedDay));

    $dashboardBuildCalendarUrl = static function (array $extra = []): string {
        $params = array_filter(array_merge([
            'cal_month' => null,
            'cal_day' => null,
        ], $extra), static function ($value): bool {
            return $value !== null && $value !== '';
        });
        return BASE_URL . 'modules/dashboard/index' . (!empty($params) ? '?' . http_build_query($params) : '');
    };

    // Workspace tile previews + tile list -------------------------------------
    $wsPerformancePreview = [
        'title' => 'Performance snapshot',
        'lines' => [
            'Collected MK ' . dashboardCurrency($stats['collected']['val'] ?? 0),
            'Outstanding MK ' . dashboardCurrency($stats['outstanding']['val'] ?? 0),
            'Collection rate: ' . $collectionRate . '%',
        ],
    ];
    $wsActivityPreview = [
        'title' => 'Recent activity',
        'lines' => array_map(
            static function (array $item): string {
                return (string) ($item['title'] ?? '');
            },
            array_slice($dashboardActivityItems, 0, 3)
        ),
    ];
    if (empty($wsActivityPreview['lines'])) {
        $wsActivityPreview['lines'] = ['No recent activity yet.'];
    }
    $wsReportsPreview = [
        'title' => 'Reports',
        'lines' => [
            'Activity Trend - latest ' . $latestTrendLabel,
            'Revenue MK ' . dashboardCurrency($latestRevenueTrend),
            number_format($invoiceStatusTotal) . ' invoices tracked',
        ],
    ];
    $wsProjectsPreview = [
        'title' => 'Projects',
        'lines' => array_map(
            static function (array $proj): string {
                return (string) ($proj['name'] ?? 'Untitled project');
            },
            array_slice($recentProjects, 0, 3)
        ),
    ];
    if (empty($wsProjectsPreview['lines'])) {
        $wsProjectsPreview['lines'] = ['No projects yet.'];
    }
    $wsTasksPreview = [
        'title' => 'Tasks',
        'lines' => [
            (int) ($dashboardTaskSummary['In Progress'] ?? 0) . ' in progress',
            (int) ($dashboardTaskSummary['Pending'] ?? 0) . ' pending',
            (int) ($dashboardTaskSummary['Overdue'] ?? 0) . ' overdue',
        ],
    ];
    $wsRemindersPreview = [
        'title' => 'Reminders',
        'lines' => [
            (int) ($dashboardReminderStats['active'] ?? 0) . ' active',
            (int) ($dashboardReminderStats['due_today'] ?? 0) . ' due today',
            (int) ($dashboardReminderStats['overdue'] ?? 0) . ' overdue',
        ],
    ];
    $wsQuickActionsPreview = [
        'title' => 'Quick actions',
        'lines' => [
            'Create estimation, invoice, task or project in one click.',
            'Jump straight to your workspace.',
        ],
    ];

    $wsDashboardTiles = [];
    if (!empty($dashboardFeatureCards) || !empty($dashboardMetricTiles)) {
        $wsDashboardTiles[] = [
            'id' => 'ws-tile-performance',
            'modal' => 'wsModalPerformance',
            'icon' => 'chart-line',
            'label' => 'Performance',
            'value' => hasPermission('view_dashboard_revenue')
                ? 'MK ' . dashboardCurrency($stats['collected']['val'] ?? 0)
                : number_format((int) ($stats['active_projects']['val'] ?? 0)),
            'hint' => hasPermission('view_dashboard_revenue') ? 'Collected this month' : 'Active projects',
            'tone' => 'primary',
            'preview' => $wsPerformancePreview,
        ];
    }
    $wsDashboardTiles[] = [
        'id' => 'ws-tile-activity',
        'modal' => 'wsModalActivity',
        'icon' => 'history',
        'label' => 'Activity',
        'value' => (string) count($dashboardActivityItems),
        'hint' => count($dashboardActivityItems) === 1 ? 'Recent update' : 'Recent updates',
        'tone' => 'neutral',
        'preview' => $wsActivityPreview,
    ];
    if (hasPermission('view_dashboard_revenue') || hasPermission('view_estimations') || hasPermission('view_invoices') || hasPermission('view_projects')) {
        $wsDashboardTiles[] = [
            'id' => 'ws-tile-reports',
            'modal' => 'wsModalReports',
            'icon' => 'bar-chart-3',
            'label' => 'Reports',
            'value' => $collectionRate . '%',
            'hint' => 'Collection rate',
            'tone' => 'success',
            'preview' => $wsReportsPreview,
        ];
    }
    if (hasPermission('view_projects')) {
        $wsDashboardTiles[] = [
            'id' => 'ws-tile-projects',
            'modal' => 'wsModalProjects',
            'icon' => 'folder-open',
            'label' => 'Projects',
            'value' => number_format((int) ($stats['active_projects']['val'] ?? 0)),
            'hint' => number_format($projectStatusTotal) . ' tracked',
            'tone' => 'success',
            'preview' => $wsProjectsPreview,
        ];
    }
    if (hasPermission('view_tasks')) {
        $wsTasksOverdue = (int) ($dashboardTaskSummary['Overdue'] ?? 0);
        $wsDashboardTiles[] = [
            'id' => 'ws-tile-tasks',
            'modal' => 'wsModalTasks',
            'icon' => 'circle-check',
            'label' => 'Tasks',
            'value' => number_format($totalTasksTracked),
            'hint' => $wsTasksOverdue > 0 ? $wsTasksOverdue . ' overdue' : 'On track',
            'tone' => $wsTasksOverdue > 0 ? 'danger' : 'neutral',
            'preview' => $wsTasksPreview,
        ];
        $wsDashboardTiles[] = [
            'id' => 'ws-tile-reminders',
            'modal' => 'wsModalReminders',
            'icon' => 'calendar-clock',
            'label' => 'Reminders',
            'value' => number_format((int) ($dashboardReminderStats['active'] ?? 0)),
            'hint' => ((int) ($dashboardReminderStats['due_today'] ?? 0)) . ' due today',
            'tone' => 'warning',
            'preview' => $wsRemindersPreview,
        ];
    }
    $wsDashboardTiles[] = [
        'id' => 'ws-tile-actions',
        'modal' => 'wsModalQuickActions',
        'icon' => 'zap',
        'label' => 'Quick Actions',
        'value' => 'Go',
        'hint' => 'Create & jump',
        'tone' => 'neutral',
        'preview' => $wsQuickActionsPreview,
    ];
    $dashboardWorkspaceTiles = dashboard_filter_workspace_tiles($wsDashboardTiles, $dashboardPersona, 4);

    // Mini panels --------------------------------------------------------------
    $dashboardEstimationFunnel = [];
    $dashboardEstimationFunnelBottleneck = '';
    if (hasPermission('view_estimations')) {
        try {
            require_once __DIR__ . '/../libs/EstimationStatusManager.php';
            $estimationManager = new EstimationStatusManager($pdo);
            $estimationStats = $estimationManager->getStatisticsByStatus();
            $statusTotals = [];
            foreach (EstimationStatusManager::getAllStatuses() as $status) {
                $statusTotals[$status] = ['count' => 0, 'total_amount' => 0.0];
            }
            foreach ($estimationStats as $row) {
                $status = (string) ($row['status'] ?? '');
                if (!isset($statusTotals[$status])) {
                    continue;
                }
                $statusTotals[$status] = [
                    'count' => (int) ($row['count'] ?? 0),
                    'total_amount' => (float) ($row['total_amount'] ?? 0),
                ];
            }
            foreach ($statusTotals as $status => $meta) {
                $details = EstimationStatusManager::getStatusDetails($status);
                $dashboardEstimationFunnel[] = [
                    'status' => $status,
                    'label' => (string) ($details['label'] ?? $status),
                    'icon' => (string) ($details['icon'] ?? 'file-text'),
                    'count' => (int) ($meta['count'] ?? 0),
                    'amount' => (float) ($meta['total_amount'] ?? 0),
                    'href' => BASE_URL . 'modules/estimations/list?status=' . rawurlencode($status),
                ];
            }

            $stuckStmt = $pdo->query(
                "SELECT COUNT(*) FROM estimations
                 WHERE status = 'Performer Invoiced'
                   AND created_at < DATE_SUB(CURDATE(), INTERVAL 7 DAY)"
            );
            $stuckCount = (int) ($stuckStmt->fetchColumn() ?: 0);
            if ($stuckCount > 0) {
                $dashboardEstimationFunnelBottleneck = number_format($stuckCount) . ' estimation(s) stuck in Performer Invoiced for 7+ days';
            }
        } catch (Throwable $exception) {
            $dashboardEstimationFunnel = [];
        }
    }

    $dashboardProjectHealth = [
        'available' => false,
        'overdue_tasks' => 0,
        'projects_at_risk' => 0,
        'top_projects' => [],
        'assignee_workload' => [],
    ];
    if (hasPermission('view_projects')) {
        try {
            require_once __DIR__ . '/project_visibility_helper.php';
            $healthVis = project_visibility_sql_where_for_projects('p', $userId, $pdo);
            $healthClause = $healthVis['clause'];

            $overdueStmt = $pdo->prepare(
                "SELECT COUNT(*) FROM tasks t
                 INNER JOIN projects p ON p.id = t.project_id
                 WHERE t.due_date < CURDATE()
                   AND t.status NOT IN ('Completed', 'Cancelled')
                   $healthClause"
            );
            foreach ($healthVis['binds'] as $bk => $bv) {
                $overdueStmt->bindValue(':' . $bk, $bv, PDO::PARAM_INT);
            }
            $overdueStmt->execute();
            $dashboardProjectHealth['overdue_tasks'] = (int) ($overdueStmt->fetchColumn() ?: 0);

            $riskStmt = $pdo->prepare(
                "SELECT COUNT(*) FROM (
                    SELECT p.id
                    FROM projects p
                    LEFT JOIN tasks t ON t.project_id = p.id
                        AND t.due_date < CURDATE()
                        AND t.status NOT IN ('Completed', 'Cancelled')
                    WHERE p.status = 'In Progress'
                      $healthClause
                    GROUP BY p.id
                    HAVING COUNT(t.id) > 0
                 ) risky_projects"
            );
            foreach ($healthVis['binds'] as $bk => $bv) {
                $riskStmt->bindValue(':' . $bk, $bv, PDO::PARAM_INT);
            }
            $riskStmt->execute();
            $dashboardProjectHealth['projects_at_risk'] = (int) ($riskStmt->fetchColumn() ?: 0);

            $topProjectsStmt = $pdo->prepare(
                "SELECT p.id, p.name,
                        COUNT(t.id) AS overdue_tasks
                 FROM projects p
                 LEFT JOIN tasks t ON t.project_id = p.id
                     AND t.due_date < CURDATE()
                     AND t.status NOT IN ('Completed', 'Cancelled')
                 WHERE p.status = 'In Progress'
                   $healthClause
                 GROUP BY p.id
                 HAVING overdue_tasks > 0
                 ORDER BY overdue_tasks DESC, p.name ASC
                 LIMIT 3"
            );
            foreach ($healthVis['binds'] as $bk => $bv) {
                $topProjectsStmt->bindValue(':' . $bk, $bv, PDO::PARAM_INT);
            }
            $topProjectsStmt->execute();
            foreach ($topProjectsStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $dashboardProjectHealth['top_projects'][] = [
                    'id' => (int) ($row['id'] ?? 0),
                    'name' => (string) ($row['name'] ?? 'Project'),
                    'overdue_tasks' => (int) ($row['overdue_tasks'] ?? 0),
                    'href' => BASE_URL . 'modules/projects/view?id=' . (int) ($row['id'] ?? 0),
                ];
            }

            $workloadStmt = $pdo->prepare(
                "SELECT u.name, COUNT(DISTINCT ta.task_id) AS task_count
                 FROM task_assignees ta
                 INNER JOIN tasks t ON t.id = ta.task_id
                 INNER JOIN projects p ON p.id = t.project_id
                 INNER JOIN users u ON u.id = ta.user_id
                 WHERE t.status NOT IN ('Completed', 'Cancelled')
                   $healthClause
                 GROUP BY u.id, u.name
                 ORDER BY task_count DESC, u.name ASC
                 LIMIT 4"
            );
            foreach ($healthVis['binds'] as $bk => $bv) {
                $workloadStmt->bindValue(':' . $bk, $bv, PDO::PARAM_INT);
            }
            $workloadStmt->execute();
            $maxTasks = 0;
            foreach ($workloadStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $taskCount = (int) ($row['task_count'] ?? 0);
                $maxTasks = max($maxTasks, $taskCount);
                $dashboardProjectHealth['assignee_workload'][] = [
                    'name' => (string) ($row['name'] ?? 'Assignee'),
                    'task_count' => $taskCount,
                ];
            }
            foreach ($dashboardProjectHealth['assignee_workload'] as &$assignee) {
                $assignee['pct'] = $maxTasks > 0
                    ? (int) round(($assignee['task_count'] / $maxTasks) * 100)
                    : 0;
            }
            unset($assignee);

            $dashboardProjectHealth['available'] = true;
        } catch (Throwable $exception) {
        }
    }

    $dashboardProductionPipeline = [];
    $dashboardProductionPipelineBottleneck = null;
    if (!empty($dashboardWorkOrdersPanel['available'])) {
        try {
            require_once __DIR__ . '/work_order_dashboard_helper.php';
            work_order_bootstrap($pdo);
            $pipelineStages = work_order_dashboard_pipeline($pdo);
            $maxCount = 0;
            foreach ($pipelineStages as $stage) {
                $count = (int) ($stage['count'] ?? 0);
                $maxCount = max($maxCount, $count);
                $dashboardProductionPipeline[] = [
                    'label' => (string) ($stage['label'] ?? ''),
                    'count' => $count,
                    'pct' => (int) ($stage['pct'] ?? 0),
                    'icon' => (string) ($stage['icon'] ?? 'briefcase'),
                    'href' => BASE_URL . 'modules/work_orders/' . ltrim((string) ($stage['href'] ?? 'list'), '/'),
                ];
            }
            foreach ($dashboardProductionPipeline as $stage) {
                if ($dashboardProductionPipelineBottleneck === null && (int) ($stage['count'] ?? 0) === $maxCount && $maxCount > 0) {
                    $dashboardProductionPipelineBottleneck = $stage;
                }
            }
        } catch (Throwable $exception) {
            $dashboardProductionPipeline = [];
        }
    }

    $dashboardPermittedModules = dashboard_permitted_module_tiles();
    $dashboardShowEmptyWelcome = empty($dashboardPrimaryCards) && empty($dashboardFinanceRows);
    $dashboardAttentionInbox = dashboard_build_attention_inbox([
        'dashboardFocusItems' => $dashboardFocusItems,
        'dashboardPendingApprovals' => $dashboardPendingApprovals,
        'dashboardDebtors' => $dashboardDebtors,
        'dashboardWorkOrdersPanel' => $dashboardWorkOrdersPanel,
        'dashboardReminderAttentionCount' => $dashboardReminderAttentionCount,
    ]);

    // Workspace sidebar (currently unused outside legacy templates) ------------
    $wsScope = trim((string) ($params['scope'] ?? $_GET['scope'] ?? 'home'));
    $wsSidebar = [
        ['scope' => 'home', 'icon' => 'home', 'label' => 'Home', 'target' => ''],
        ['scope' => 'performance', 'icon' => 'chart-line', 'label' => 'Performance', 'target' => 'wsModalPerformance'],
        ['scope' => 'activity', 'icon' => 'history', 'label' => 'Activity', 'target' => 'wsModalActivity'],
        ['scope' => 'reports', 'icon' => 'bar-chart-3', 'label' => 'Reports', 'target' => 'wsModalReports'],
    ];
    if (hasPermission('view_projects')) {
        $wsSidebar[] = ['scope' => 'projects', 'icon' => 'folder-open', 'label' => 'Projects', 'target' => 'wsModalProjects'];
    }
    if (hasPermission('view_tasks')) {
        $wsSidebar[] = ['scope' => 'tasks', 'icon' => 'circle-check', 'label' => 'Tasks', 'target' => 'wsModalTasks'];
        $wsSidebar[] = ['scope' => 'reminders', 'icon' => 'calendar-clock', 'label' => 'Reminders', 'target' => 'wsModalReminders'];
    }
    $wsSidebar[] = ['scope' => 'actions', 'icon' => 'zap', 'label' => 'Quick Actions', 'target' => 'wsModalQuickActions'];

    return [
        // raw data
        'stats' => $stats,
        'chartData' => $chartData,
        'dashboardHeroTrend' => $dashboardHeroTrend,
        'dashboardCanViewRevenueChart' => $dashboardCanViewRevenueChart,
        'dashboardPersona' => $dashboardPersona,
        'dashboardPersonaLabel' => $dashboardPersonaLabel,
        'dashboardPanelOrder' => $dashboardPanelOrder,
        'dashboardMainColumnOrder' => $dashboardMainColumnOrder,
        'dashboardDateRange' => $dashboardDateRange,
        'recentProjects' => $recentProjects,
        'recentTasks' => $recentTasks,
        'taskSummary' => $taskSummary,
        'dashboardTaskSummary' => $dashboardTaskSummary,
        'myOverdueTaskCount' => $myOverdueTaskCount,
        'dashboardReminderStats' => $dashboardReminderStats,
        'dashboardReminderItems' => $dashboardReminderItems,
        'dashboardReminderAttentionCount' => $dashboardReminderAttentionCount,
        'search_results' => $search_results,
        'search_query' => $search_query,
        // hero/metric derived
        'currentMonthLabel' => $currentMonthLabel,
        'currentHour' => $currentHour,
        'dashboardGreeting' => $dashboardGreeting,
        'totalTasksTracked' => $totalTasksTracked,
        'collectionRate' => $collectionRate,
        'invoiceStatusTotal' => $invoiceStatusTotal,
        'projectStatusTotal' => $projectStatusTotal,
        'latestMonthIndex' => $latestMonthIndex,
        'latestTrendLabel' => $latestTrendLabel,
        'latestEstimationsTrend' => $latestEstimationsTrend,
        'latestInvoicesTrend' => $latestInvoicesTrend,
        'latestRevenueTrend' => $latestRevenueTrend,
        'latestCollectedTrend' => $latestCollectedTrend,
        'dashboardFeatureCards' => $dashboardFeatureCards,
        'dashboardMetricTiles' => $dashboardMetricTiles,
        'dashboardActivityItems' => $dashboardActivityItems,
        'dashboardOpenTaskCount' => $dashboardOpenTaskCount,
        'dashboardFocusItems' => $dashboardFocusItems,
        // debtors
        'dashboardCanViewDebtorsPanel' => $dashboardCanViewDebtorsPanel,
        'dashboardDebtors' => $dashboardDebtors,
        'dashboardDebtorsTotalBalance' => $dashboardDebtorsTotalBalance,
        'dashboardDebtorsCriticalCount' => $dashboardDebtorsCriticalCount,
        'dashboardDebtorsReminderAt' => $dashboardDebtorsReminderAt,
        // operational dashboard
        'dashboardWorkOrderModule' => $dashboardWorkOrderModule,
        'dashboardWorkOrdersPanel' => $dashboardWorkOrdersPanel,
        'dashboardOpenInvoiceCount' => $dashboardOpenInvoiceCount,
        'dashboardOverdueInvoiceCount' => $dashboardOverdueInvoiceCount,
        'dashboardDueTodayCount' => $dashboardDueTodayCount,
        'dashboardHighPriorityTodayCount' => $dashboardHighPriorityTodayCount,
        'dashboardPrimaryCards' => $dashboardPrimaryCards,
        'dashboardPendingApprovals' => $dashboardPendingApprovals,
        'dashboardMaterialsSnapshot' => $dashboardMaterialsSnapshot,
        'dashboardFinanceRows' => $dashboardFinanceRows,
        'dashboardWorkspaceTiles' => $dashboardWorkspaceTiles,
        'dashboardEstimationFunnel' => $dashboardEstimationFunnel,
        'dashboardEstimationFunnelBottleneck' => $dashboardEstimationFunnelBottleneck,
        'dashboardProjectHealth' => $dashboardProjectHealth,
        'dashboardProductionPipeline' => $dashboardProductionPipeline,
        'dashboardProductionPipelineBottleneck' => $dashboardProductionPipelineBottleneck,
        'dashboardAttentionInbox' => $dashboardAttentionInbox,
        'dashboardPermittedModules' => $dashboardPermittedModules,
        'dashboardShowEmptyWelcome' => $dashboardShowEmptyWelcome,
        'dashboardReceivablesSummary' => $dashboardReceivablesSummary,
        'dashboardTodayDateLabel' => $dashboardTodayDateLabel,
        'dashboardTodayWeekday' => $dashboardTodayWeekday,
        // hero metric cards
        'dashboardProjectCount' => $dashboardProjectCount,
        'dashboardAvgProjectEarnings' => $dashboardAvgProjectEarnings,
        'dashboardProductivity' => $dashboardProductivity,
        'dashboardHeroCards' => $dashboardHeroCards,
        // calendar / schedule
        'dashboardCalendarUserId' => $userId,
        'dashboardCalMonth' => $dashboardCalMonth,
        'dashboardCalendarStart' => $dashboardCalendarStart,
        'dashboardCalendarEnd' => $dashboardCalendarEnd,
        'dashboardTodayYmd' => $dashboardTodayYmd,
        'dashboardSelectedDay' => $dashboardSelectedDay,
        'dashboardCalendarRows' => $dashboardCalendarRows,
        'dashboardCalendarByDay' => $dashboardCalendarByDay,
        'dashboardPrevMonth' => $dashboardPrevMonth,
        'dashboardNextMonth' => $dashboardNextMonth,
        'dashboardCalendarLabel' => $dashboardCalendarLabel,
        'dashboardCalendarYear' => $dashboardCalendarYear,
        'dashboardCalendarGridStart' => $dashboardCalendarGridStart,
        'dashboardSelectedItems' => $dashboardSelectedItems,
        'dashboardSelectedReminderAt' => $dashboardSelectedReminderAt,
        'dashboardBuildCalendarUrl' => $dashboardBuildCalendarUrl,
        // workspace shell
        'wsScope' => $wsScope,
        'wsSidebar' => $wsSidebar,
        'wsDashboardTiles' => $wsDashboardTiles,
        'wsPerformancePreview' => $wsPerformancePreview,
        'wsActivityPreview' => $wsActivityPreview,
        'wsReportsPreview' => $wsReportsPreview,
        'wsProjectsPreview' => $wsProjectsPreview,
        'wsTasksPreview' => $wsTasksPreview,
        'wsRemindersPreview' => $wsRemindersPreview,
        'wsQuickActionsPreview' => $wsQuickActionsPreview,
        // session shorthand for partials
        'role' => $role,
        'userId' => $userId,
    ];
}

// ---------------------------------------------------------------------------
// Fragment registry
// ---------------------------------------------------------------------------

/**
 * Registry of refreshable dashboard components.
 *
 * Each entry maps a component id (matching `data-ajax-component` on the front
 * end) to:
 *   - 'view'       Path (relative to modules/dashboard/) of the partial to
 *                  include after the context is built.
 *   - 'permission' Optional permission slug; clients without it get a 403.
 *
 * fragments.php walks the same array; the front-end framework just hits
 *   <BASE_URL>modules/dashboard/fragments?id=<componentId>
 *
 * @return array<string, array{view: string, permission?: string}>
 */
function dashboard_fragment_registry(): array
{
    return [
        'dashboard.hero.greeting' => [
            'view' => 'partials/hero_greeting.php',
        ],
        'dashboard.hero.metrics' => [
            'view' => 'partials/hero_metrics.php',
            'permission_any' => ['view_projects', 'view_tasks', 'view_dashboard_revenue'],
        ],
        'dashboard.ops.kpi' => [
            'view' => 'partials/ops_kpi_grid.php',
        ],
        'dashboard.ops.focus' => [
            'view' => 'partials/ops_focus_strip.php',
        ],
        'dashboard.ops.workspace' => [
            'view' => 'partials/ops_workspace_shortcuts.php',
        ],
        'dashboard.ops.hero_trend' => [
            'view' => 'partials/ops_hero_trend.php',
        ],
        'dashboard.ops.attention' => [
            'view' => 'partials/ops_attention_inbox.php',
        ],
        'dashboard.ops.estimation_funnel' => [
            'view' => 'partials/ops_estimation_funnel.php',
            'permission' => 'view_estimations',
        ],
        'dashboard.ops.project_health' => [
            'view' => 'partials/ops_project_health.php',
            'permission' => 'view_projects',
        ],
        'dashboard.ops.production_pipeline' => [
            'view' => 'partials/ops_production_pipeline.php',
            'permission_any' => ['view_work_orders', 'view_work_order_reports', 'manage_work_orders'],
        ],
        'dashboard.ops.empty_welcome' => [
            'view' => 'partials/ops_empty_welcome.php',
        ],
        'dashboard.ops.sidebar' => [
            'view' => 'partials/ops_sidebar_rail.php',
        ],
        'dashboard.focus.list' => [
            'view' => 'partials/focus_list.php',
        ],
        'dashboard.workspace.tiles' => [
            'view' => 'partials/workspace_tiles.php',
        ],
        'dashboard.debtors.panel' => [
            'view' => 'partials/debtors_panel.php',
            'permission_any' => ['view_dashboard_revenue', 'view_invoices'],
        ],
        'dashboard.calendar' => [
            'view' => 'partials/calendar.php',
        ],
        'dashboard.schedule' => [
            'view' => 'partials/schedule.php',
        ],
        'dashboard.modal.performance' => [
            'view' => 'partials/modal_performance.php',
            'permission_any' => ['view_dashboard_revenue', 'view_invoices', 'view_projects', 'view_tasks'],
        ],
        'dashboard.modal.activity' => [
            'view' => 'partials/modal_activity.php',
            'permission_any' => ['view_projects', 'view_tasks'],
        ],
        'dashboard.modal.reports' => [
            'view' => 'partials/modal_reports.php',
            'permission_any' => ['view_dashboard_revenue', 'view_estimations', 'view_invoices', 'view_projects', 'view_dispatch'],
        ],
        'dashboard.modal.projects' => [
            'view' => 'partials/modal_projects.php',
            'permission' => 'view_projects',
        ],
        'dashboard.modal.tasks' => [
            'view' => 'partials/modal_tasks.php',
            'permission' => 'view_tasks',
        ],
        'dashboard.modal.reminders' => [
            'view' => 'partials/modal_reminders.php',
        ],
        'dashboard.modal.quick_actions' => [
            'view' => 'partials/modal_quick_actions.php',
        ],
    ];
}
