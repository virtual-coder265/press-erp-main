<?php

require_once __DIR__ . '/project_visibility_helper.php';

function dashboard_calculate_mom($current, $previous): string
{
    $current = (float) $current;
    $previous = (float) $previous;

    if ($previous == 0.0) {
        return $current > 0 ? '+100%' : '0%';
    }

    $growth = (($current - $previous) / $previous) * 100;
    $sign = $growth > 0 ? '+' : '';

    return $sign . number_format($growth, 1) . '%';
}

function dashboard_month_window(int $offset = 0): array
{
    $base = new DateTimeImmutable('first day of this month 00:00:00');
    if ($offset !== 0) {
        $base = $base->modify(($offset > 0 ? '+' : '') . $offset . ' month');
    }

    $end = $base->modify('+1 month');

    return [
        'key' => $base->format('Y-m'),
        'label' => $base->format('M Y'),
        'start' => $base->format('Y-m-d'),
        'end' => $end->format('Y-m-d'),
    ];
}

function dashboard_day_window(int $offset = 0): array
{
    $base = new DateTimeImmutable('today 00:00:00');
    if ($offset !== 0) {
        $base = $base->modify(($offset > 0 ? '+' : '') . $offset . ' day');
    }

    $end = $base->modify('+1 day');

    return [
        'start' => $base->format('Y-m-d'),
        'end' => $end->format('Y-m-d'),
    ];
}

function dashboard_month_series(int $months = 6): array
{
    $months = max(1, $months);
    $series = [];

    for ($offset = $months - 1; $offset >= 0; $offset--) {
        $series[] = dashboard_month_window(-$offset);
    }

    return $series;
}

function dashboard_normalize_date_range_key(string $rawKey): string
{
    $rawKey = strtolower(trim($rawKey));
    $aliases = [
        'mtd' => 'month',
        '30d' => 'week',
    ];

    $key = $aliases[$rawKey] ?? $rawKey;
    if (!in_array($key, ['day', 'week', 'month', 'quarter'], true)) {
        $key = 'month';
    }

    return $key;
}

function dashboard_resolve_date_range(array $params = []): array
{
    $key = dashboard_normalize_date_range_key((string) ($params['range'] ?? $_GET['range'] ?? 'month'));
    $today = new DateTimeImmutable('today');

    if ($key === 'day') {
        $currentStart = $today;
        $currentEnd = $today->modify('+1 day');
        $previousStart = $today->modify('-1 day');
        $previousEnd = $today;

        return [
            'key' => 'day',
            'label' => 'Today',
            'period_label' => 'Today',
            'granularity' => 'hour',
            'current' => [
                'start' => $currentStart->format('Y-m-d'),
                'end' => $currentEnd->format('Y-m-d'),
            ],
            'previous' => [
                'start' => $previousStart->format('Y-m-d'),
                'end' => $previousEnd->format('Y-m-d'),
            ],
        ];
    }

    if ($key === 'week') {
        $currentStart = $today->modify('-6 days');
        $currentEnd = $today->modify('+1 day');
        $previousStart = $today->modify('-13 days');
        $previousEnd = $currentStart;

        return [
            'key' => 'week',
            'label' => 'Last 7 days',
            'period_label' => 'Last 7 days',
            'granularity' => 'day',
            'current' => [
                'start' => $currentStart->format('Y-m-d'),
                'end' => $currentEnd->format('Y-m-d'),
            ],
            'previous' => [
                'start' => $previousStart->format('Y-m-d'),
                'end' => $previousEnd->format('Y-m-d'),
            ],
        ];
    }

    if ($key === 'quarter') {
        $month = (int) $today->format('n');
        $quarterStartMonth = ((int) floor(($month - 1) / 3) * 3) + 1;
        $currentStart = new DateTimeImmutable($today->format('Y') . '-' . str_pad((string) $quarterStartMonth, 2, '0', STR_PAD_LEFT) . '-01');
        $currentEnd = $currentStart->modify('+3 months');
        $previousStart = $currentStart->modify('-3 months');
        $previousEnd = $currentStart;

        return [
            'key' => 'quarter',
            'label' => 'This quarter',
            'period_label' => 'Quarter to date',
            'granularity' => 'month',
            'current' => [
                'start' => $currentStart->format('Y-m-d'),
                'end' => $currentEnd->format('Y-m-d'),
            ],
            'previous' => [
                'start' => $previousStart->format('Y-m-d'),
                'end' => $previousEnd->format('Y-m-d'),
            ],
        ];
    }

    $currentMonth = dashboard_month_window(0);
    $prevMonth = dashboard_month_window(-1);

    return [
        'key' => 'month',
        'label' => 'Month to date',
        'period_label' => $currentMonth['label'],
        'granularity' => 'week',
        'current' => [
            'start' => $currentMonth['start'],
            'end' => $currentMonth['end'],
        ],
        'previous' => [
            'start' => $prevMonth['start'],
            'end' => $prevMonth['end'],
        ],
    ];
}

function dashboard_date_range_month_series(array $dateRange, int $months = 6): array
{
    $rangeKey = dashboard_normalize_date_range_key((string) ($dateRange['key'] ?? 'month'));
    if ($rangeKey === 'week') {
        $series = [];
        $end = new DateTimeImmutable((string) ($dateRange['current']['end'] ?? date('Y-m-d')));
        for ($offset = $months - 1; $offset >= 0; $offset--) {
            $bucketEnd = $end->modify('-' . ($offset * 7) . ' days');
            $bucketStart = $bucketEnd->modify('-7 days');
            $series[] = [
                'key' => $bucketStart->format('Y-m-d'),
                'label' => $bucketStart->format('M j'),
                'start' => $bucketStart->format('Y-m-d'),
                'end' => $bucketEnd->format('Y-m-d'),
            ];
        }

        return $series;
    }

    return dashboard_month_series($months);
}

function dashboard_empty_metric(): array
{
    return [
        'val' => 0,
        'current' => 0,
        'previous' => 0,
        'growth' => '0%',
    ];
}

function dashboard_empty_summary_stats(): array
{
    return [
        'estimations' => dashboard_empty_metric(),
        'invoices' => dashboard_empty_metric(),
        'unpaid_invoices' => dashboard_empty_metric(),
        'active_projects' => dashboard_empty_metric(),
        'dispatched' => dashboard_empty_metric(),
        'users' => dashboard_empty_metric(),
        'total_revenue' => dashboard_empty_metric(),
        'collected' => dashboard_empty_metric(),
        'outstanding' => dashboard_empty_metric(),
        'partially_paid' => dashboard_empty_metric(),
    ];
}

function dashboard_fetch_summary_stats(PDO $pdo, int $viewerUserId = 0, ?array $dateRange = null): array
{
    require_once __DIR__ . '/../libs/InvoiceAuditMigrator.php';
    InvoiceAuditMigrator::ensure($pdo);

    $stats = dashboard_empty_summary_stats();
    $dateRange = $dateRange ?? dashboard_resolve_date_range();
    $currentMonth = [
        'start' => $dateRange['current']['start'],
        'end' => $dateRange['current']['end'],
        'label' => $dateRange['period_label'] ?? ($dateRange['label'] ?? date('M Y')),
    ];
    $prevMonth = [
        'start' => $dateRange['previous']['start'],
        'end' => $dateRange['previous']['end'],
    ];
    $today = dashboard_day_window(0);

    try {
        if (hasPermission('view_estimations')) {
        $estimationStmt = $pdo->prepare("
            SELECT
                COALESCE(SUM(CASE WHEN status != 'Draft' THEN 1 ELSE 0 END), 0) AS total_count,
                COALESCE(SUM(CASE WHEN status != 'Draft' AND created_at >= ? AND created_at < ? THEN 1 ELSE 0 END), 0) AS current_count,
                COALESCE(SUM(CASE WHEN status != 'Draft' AND created_at >= ? AND created_at < ? THEN 1 ELSE 0 END), 0) AS prev_count
            FROM estimations
        ");
        $estimationStmt->execute([
            $currentMonth['start'],
            $currentMonth['end'],
            $prevMonth['start'],
            $prevMonth['end'],
        ]);
        $estimationRow = $estimationStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $stats['estimations'] = [
            'val' => (int) ($estimationRow['total_count'] ?? 0),
            'current' => (int) ($estimationRow['current_count'] ?? 0),
            'previous' => (int) ($estimationRow['prev_count'] ?? 0),
            'growth' => dashboard_calculate_mom($estimationRow['current_count'] ?? 0, $estimationRow['prev_count'] ?? 0),
        ];
        }

        if (hasPermission('view_invoices') || hasPermission('view_dashboard_revenue')) {
        $invoiceStmt = $pdo->prepare("
            SELECT
                COUNT(*) AS total_invoices,
                COALESCE(SUM(CASE WHEN generated_date >= ? AND generated_date < ? THEN 1 ELSE 0 END), 0) AS current_invoices,
                COALESCE(SUM(CASE WHEN generated_date >= ? AND generated_date < ? THEN 1 ELSE 0 END), 0) AS prev_invoices,
                COALESCE(SUM(CASE WHEN status = 'Unpaid' THEN 1 ELSE 0 END), 0) AS total_unpaid,
                COALESCE(SUM(CASE WHEN status = 'Unpaid' AND generated_date >= ? AND generated_date < ? THEN 1 ELSE 0 END), 0) AS current_unpaid,
                COALESCE(SUM(CASE WHEN status = 'Unpaid' AND generated_date >= ? AND generated_date < ? THEN 1 ELSE 0 END), 0) AS prev_unpaid,
                COALESCE(SUM(total_amount), 0) AS total_revenue,
                COALESCE(SUM(CASE WHEN generated_date >= ? AND generated_date < ? THEN total_amount ELSE 0 END), 0) AS current_revenue,
                COALESCE(SUM(CASE WHEN generated_date >= ? AND generated_date < ? THEN total_amount ELSE 0 END), 0) AS prev_revenue,
                COALESCE(SUM(paid_amount), 0) AS total_collected,
                COALESCE(SUM(CASE WHEN status IN ('Unpaid', 'Partially Paid', 'Overdue') THEN balance ELSE 0 END), 0) AS total_outstanding,
                COALESCE(SUM(CASE WHEN status IN ('Unpaid', 'Partially Paid', 'Overdue') AND generated_date >= ? AND generated_date < ? THEN balance ELSE 0 END), 0) AS current_outstanding,
                COALESCE(SUM(CASE WHEN status IN ('Unpaid', 'Partially Paid', 'Overdue') AND generated_date >= ? AND generated_date < ? THEN balance ELSE 0 END), 0) AS prev_outstanding,
                COALESCE(SUM(CASE WHEN status = 'Partially Paid' THEN 1 ELSE 0 END), 0) AS total_partially_paid,
                COALESCE(SUM(CASE WHEN status = 'Partially Paid' AND generated_date >= ? AND generated_date < ? THEN 1 ELSE 0 END), 0) AS current_partially_paid,
                COALESCE(SUM(CASE WHEN status = 'Partially Paid' AND generated_date >= ? AND generated_date < ? THEN 1 ELSE 0 END), 0) AS prev_partially_paid
            FROM invoices
        ");
        $invoiceStmt->execute([
            $currentMonth['start'], $currentMonth['end'],
            $prevMonth['start'], $prevMonth['end'],
            $currentMonth['start'], $currentMonth['end'],
            $prevMonth['start'], $prevMonth['end'],
            $currentMonth['start'], $currentMonth['end'],
            $prevMonth['start'], $prevMonth['end'],
            $currentMonth['start'], $currentMonth['end'],
            $prevMonth['start'], $prevMonth['end'],
            $currentMonth['start'], $currentMonth['end'],
            $prevMonth['start'], $prevMonth['end'],
        ]);
        $invoiceRow = $invoiceStmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $stats['invoices'] = [
            'val' => (int) ($invoiceRow['total_invoices'] ?? 0),
            'current' => (int) ($invoiceRow['current_invoices'] ?? 0),
            'previous' => (int) ($invoiceRow['prev_invoices'] ?? 0),
            'growth' => dashboard_calculate_mom($invoiceRow['current_invoices'] ?? 0, $invoiceRow['prev_invoices'] ?? 0),
        ];
        $stats['unpaid_invoices'] = [
            'val' => (int) ($invoiceRow['total_unpaid'] ?? 0),
            'current' => (int) ($invoiceRow['current_unpaid'] ?? 0),
            'previous' => (int) ($invoiceRow['prev_unpaid'] ?? 0),
            'growth' => dashboard_calculate_mom($invoiceRow['current_unpaid'] ?? 0, $invoiceRow['prev_unpaid'] ?? 0),
        ];
        $stats['total_revenue'] = [
            'val' => (float) ($invoiceRow['total_revenue'] ?? 0),
            'current' => (float) ($invoiceRow['current_revenue'] ?? 0),
            'previous' => (float) ($invoiceRow['prev_revenue'] ?? 0),
            'growth' => dashboard_calculate_mom($invoiceRow['current_revenue'] ?? 0, $invoiceRow['prev_revenue'] ?? 0),
        ];
        $stats['collected']['val'] = (float) ($invoiceRow['total_collected'] ?? 0);
        $stats['outstanding'] = [
            'val' => (float) ($invoiceRow['total_outstanding'] ?? 0),
            'current' => (float) ($invoiceRow['current_outstanding'] ?? 0),
            'previous' => (float) ($invoiceRow['prev_outstanding'] ?? 0),
            'growth' => dashboard_calculate_mom($invoiceRow['current_outstanding'] ?? 0, $invoiceRow['prev_outstanding'] ?? 0),
        ];
        $stats['partially_paid'] = [
            'val' => (int) ($invoiceRow['total_partially_paid'] ?? 0),
            'current' => (int) ($invoiceRow['current_partially_paid'] ?? 0),
            'previous' => (int) ($invoiceRow['prev_partially_paid'] ?? 0),
            'growth' => dashboard_calculate_mom($invoiceRow['current_partially_paid'] ?? 0, $invoiceRow['prev_partially_paid'] ?? 0),
        ];

        $paymentStmt = $pdo->prepare("
            SELECT
                COALESCE(SUM(CASE WHEN payment_date >= ? AND payment_date < ? THEN amount ELSE 0 END), 0) AS current_collected,
                COALESCE(SUM(CASE WHEN payment_date >= ? AND payment_date < ? THEN amount ELSE 0 END), 0) AS prev_collected
            FROM invoice_payments
        ");
        $paymentStmt->execute([
            $currentMonth['start'],
            $currentMonth['end'],
            $prevMonth['start'],
            $prevMonth['end'],
        ]);
        $paymentRow = $paymentStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $stats['collected']['current'] = (float) ($paymentRow['current_collected'] ?? 0);
        $stats['collected']['previous'] = (float) ($paymentRow['prev_collected'] ?? 0);
        $stats['collected']['growth'] = dashboard_calculate_mom($paymentRow['current_collected'] ?? 0, $paymentRow['prev_collected'] ?? 0);
        }

        if (hasPermission('view_projects')) {
        $visDash = project_visibility_sql_where_for_projects('p', $viewerUserId, $pdo);
        $visDashClause = $visDash['clause'];

        $projectSql = "
            SELECT
                COALESCE(SUM(CASE WHEN p.status = 'In Progress' THEN 1 ELSE 0 END), 0) AS total_active,
                COALESCE(SUM(CASE WHEN p.status = 'In Progress' AND p.created_at >= :cm_start AND p.created_at < :cm_end THEN 1 ELSE 0 END), 0) AS current_active,
                COALESCE(SUM(CASE WHEN p.status = 'In Progress' AND p.created_at >= :pm_start AND p.created_at < :pm_end THEN 1 ELSE 0 END), 0) AS prev_active
            FROM projects p
            WHERE 1=1 $visDashClause
        ";
        $projectStmt = $pdo->prepare($projectSql);
        $projectStmt->bindValue(':cm_start', $currentMonth['start']);
        $projectStmt->bindValue(':cm_end', $currentMonth['end']);
        $projectStmt->bindValue(':pm_start', $prevMonth['start']);
        $projectStmt->bindValue(':pm_end', $prevMonth['end']);
        foreach ($visDash['binds'] as $bk => $bv) {
            $projectStmt->bindValue(':' . $bk, $bv, PDO::PARAM_INT);
        }
        $projectStmt->execute();
        $projectRow = $projectStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $stats['active_projects'] = [
            'val' => (int) ($projectRow['total_active'] ?? 0),
            'current' => (int) ($projectRow['current_active'] ?? 0),
            'previous' => (int) ($projectRow['prev_active'] ?? 0),
            'growth' => dashboard_calculate_mom($projectRow['current_active'] ?? 0, $projectRow['prev_active'] ?? 0),
        ];
        }

        if (hasPermission('view_dispatch')) {
        $dispatchStmt = $pdo->prepare("
            SELECT
                COALESCE(SUM(CASE WHEN created_at >= ? AND created_at < ? THEN 1 ELSE 0 END), 0) AS today_count,
                COALESCE(SUM(CASE WHEN created_at >= ? AND created_at < ? THEN 1 ELSE 0 END), 0) AS current_month_count,
                COALESCE(SUM(CASE WHEN created_at >= ? AND created_at < ? THEN 1 ELSE 0 END), 0) AS prev_month_count
            FROM dispatch_register
        ");
        $dispatchStmt->execute([
            $today['start'],
            $today['end'],
            $currentMonth['start'],
            $currentMonth['end'],
            $prevMonth['start'],
            $prevMonth['end'],
        ]);
        $dispatchRow = $dispatchStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $stats['dispatched'] = [
            'val' => (int) ($dispatchRow['today_count'] ?? 0),
            'current' => (int) ($dispatchRow['current_month_count'] ?? 0),
            'previous' => (int) ($dispatchRow['prev_month_count'] ?? 0),
            'growth' => dashboard_calculate_mom($dispatchRow['current_month_count'] ?? 0, $dispatchRow['prev_month_count'] ?? 0),
        ];
        }

        if (hasPermission('view_users')) {
        $userStmt = $pdo->prepare("
            SELECT
                COUNT(*) AS total_users,
                COALESCE(SUM(CASE WHEN created_at >= ? AND created_at < ? THEN 1 ELSE 0 END), 0) AS current_users,
                COALESCE(SUM(CASE WHEN created_at >= ? AND created_at < ? THEN 1 ELSE 0 END), 0) AS prev_users
            FROM users
        ");
        $userStmt->execute([
            $currentMonth['start'],
            $currentMonth['end'],
            $prevMonth['start'],
            $prevMonth['end'],
        ]);
        $userRow = $userStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $stats['users'] = [
            'val' => (int) ($userRow['total_users'] ?? 0),
            'current' => (int) ($userRow['current_users'] ?? 0),
            'previous' => (int) ($userRow['prev_users'] ?? 0),
            'growth' => dashboard_calculate_mom($userRow['current_users'] ?? 0, $userRow['prev_users'] ?? 0),
        ];
        }
    } catch (Throwable $exception) {
        return $stats;
    }

    return $stats;
}

function dashboard_empty_chart_data(array $series): array
{
    $monthCount = count($series);

    return [
        'months' => array_column($series, 'label'),
        'estimations_trend' => array_fill(0, $monthCount, 0),
        'invoices_trend' => array_fill(0, $monthCount, 0),
        'revenue_trend' => array_fill(0, $monthCount, 0.0),
        'collected_trend' => array_fill(0, $monthCount, 0.0),
        'invoice_status' => ['Paid' => 0, 'Unpaid' => 0, 'Overdue' => 0],
        'project_status' => ['In Progress' => 0, 'Completed' => 0, 'On Hold' => 0],
    ];
}

function dashboard_fetch_chart_data(PDO $pdo, int $months = 6, int $viewerUserId = 0, ?array $dateRange = null): array
{
    $dateRange = $dateRange ?? dashboard_resolve_date_range();
    $series = dashboard_date_range_month_series($dateRange, $months);
    $chartData = dashboard_empty_chart_data($series);

    if (!hasPermission('view_dashboard_revenue') && !hasPermission('view_invoices')) {
        return $chartData;
    }
    $monthIndex = array_flip(array_column($series, 'key'));
    $firstWindow = $series[0] ?? dashboard_month_window(0);
    $lastWindow = $series[count($series) - 1] ?? dashboard_month_window(0);

    try {
        $estimationStmt = $pdo->prepare("
            SELECT DATE_FORMAT(created_at, '%Y-%m') AS month_key, COUNT(*) AS aggregate_count
            FROM estimations
            WHERE status != 'Draft'
              AND created_at >= :start_date
              AND created_at < :end_date
            GROUP BY month_key
        ");
        $estimationStmt->execute([
            'start_date' => $firstWindow['start'],
            'end_date' => $lastWindow['end'],
        ]);
        foreach ($estimationStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $index = $monthIndex[$row['month_key']] ?? null;
            if ($index === null) {
                continue;
            }

            $chartData['estimations_trend'][$index] = (int) ($row['aggregate_count'] ?? 0);
        }

        $invoiceTrendStmt = $pdo->prepare("
            SELECT
                DATE_FORMAT(generated_date, '%Y-%m') AS month_key,
                COUNT(*) AS aggregate_count,
                COALESCE(SUM(total_amount), 0) AS revenue_total
            FROM invoices
            WHERE generated_date >= :start_date
              AND generated_date < :end_date
            GROUP BY month_key
        ");
        $invoiceTrendStmt->execute([
            'start_date' => $firstWindow['start'],
            'end_date' => $lastWindow['end'],
        ]);
        foreach ($invoiceTrendStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $index = $monthIndex[$row['month_key']] ?? null;
            if ($index === null) {
                continue;
            }

            $chartData['invoices_trend'][$index] = (int) ($row['aggregate_count'] ?? 0);
            $chartData['revenue_trend'][$index] = (float) ($row['revenue_total'] ?? 0);
        }

        $paymentTrendStmt = $pdo->prepare("
            SELECT DATE_FORMAT(payment_date, '%Y-%m') AS month_key, COALESCE(SUM(amount), 0) AS collected_total
            FROM invoice_payments
            WHERE payment_date >= :start_date
              AND payment_date < :end_date
            GROUP BY month_key
        ");
        $paymentTrendStmt->execute([
            'start_date' => $firstWindow['start'],
            'end_date' => $lastWindow['end'],
        ]);
        foreach ($paymentTrendStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $index = $monthIndex[$row['month_key']] ?? null;
            if ($index === null) {
                continue;
            }

            $chartData['collected_trend'][$index] = (float) ($row['collected_total'] ?? 0);
        }

        $invoiceStatusStmt = $pdo->query("SELECT status, COUNT(*) AS aggregate_count FROM invoices GROUP BY status");
        foreach ($invoiceStatusStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $status = (string) ($row['status'] ?? '');
            $chartData['invoice_status'][$status] = (int) ($row['aggregate_count'] ?? 0);
        }

        $visProjChart = project_visibility_sql_where_for_projects('p', $viewerUserId, $pdo);

        $projectStatusStmt = $pdo->prepare(
            "SELECT p.status, COUNT(*) AS aggregate_count FROM projects p WHERE 1=1 {$visProjChart['clause']} GROUP BY p.status"
        );
        foreach ($visProjChart['binds'] as $bk => $bv) {
            $projectStatusStmt->bindValue(':' . $bk, $bv, PDO::PARAM_INT);
        }
        $projectStatusStmt->execute();
        foreach ($projectStatusStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $status = (string) ($row['status'] ?? '');
            $chartData['project_status'][$status] = (int) ($row['aggregate_count'] ?? 0);
        }
    } catch (Throwable $exception) {
        return $chartData;
    }

    return $chartData;
}

function dashboard_table_has_column(PDO $pdo, string $table, string $column): bool
{
    static $cache = [];
    $cacheKey = $table . '.' . $column;
    if (array_key_exists($cacheKey, $cache)) {
        return $cache[$cacheKey];
    }

    try {
        $stmt = $pdo->prepare('SHOW COLUMNS FROM `' . str_replace('`', '', $table) . '` LIKE ?');
        $stmt->execute([$column]);
        $cache[$cacheKey] = (bool) $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $exception) {
        $cache[$cacheKey] = false;
    }

    return $cache[$cacheKey];
}

function dashboard_hero_trend_buckets(array $dateRange): array
{
    $key = dashboard_normalize_date_range_key((string) ($dateRange['key'] ?? 'month'));
    $start = new DateTimeImmutable((string) ($dateRange['current']['start'] ?? date('Y-m-d')));
    $end = new DateTimeImmutable((string) ($dateRange['current']['end'] ?? date('Y-m-d')));

    if ($key === 'day') {
        $buckets = [];
        for ($hour = 0; $hour < 24; $hour++) {
            $stamp = $start->setTime($hour, 0);
            $buckets[] = [
                'key' => (string) $hour,
                'label' => $stamp->format('ga'),
                'start' => $stamp->format('Y-m-d H:i:s'),
                'end' => $stamp->setTime($hour, 59, 59)->format('Y-m-d H:i:s'),
            ];
        }

        return [
            'granularity' => 'hour',
            'buckets' => $buckets,
        ];
    }

    if ($key === 'week') {
        $buckets = [];
        $cursor = $start;
        while ($cursor < $end) {
            $next = $cursor->modify('+1 day');
            $buckets[] = [
                'key' => $cursor->format('Y-m-d'),
                'label' => $cursor->format('D j'),
                'start' => $cursor->format('Y-m-d'),
                'end' => $next->format('Y-m-d'),
            ];
            $cursor = $next;
        }

        return [
            'granularity' => 'day',
            'buckets' => $buckets,
        ];
    }

    if ($key === 'month') {
        $buckets = [];
        $cursor = $start;
        while ($cursor < $end) {
            $next = $cursor->modify('+7 days');
            if ($next > $end) {
                $next = $end;
            }
            $buckets[] = [
                'key' => $cursor->format('Y-m-d'),
                'label' => $cursor->format('M j'),
                'start' => $cursor->format('Y-m-d'),
                'end' => $next->format('Y-m-d'),
            ];
            if ($next >= $end) {
                break;
            }
            $cursor = $next;
        }

        return [
            'granularity' => 'week',
            'buckets' => $buckets,
        ];
    }

    $buckets = [];
    $cursor = $start;
    while ($cursor < $end) {
        $next = $cursor->modify('+1 month');
        $buckets[] = [
            'key' => $cursor->format('Y-m'),
            'label' => $cursor->format('M'),
            'start' => $cursor->format('Y-m-d'),
            'end' => $next->format('Y-m-d'),
        ];
        $cursor = $next;
    }

    return [
        'granularity' => 'month',
        'buckets' => $buckets,
    ];
}

function dashboard_empty_hero_trend(array $dateRange): array
{
    $bucketPack = dashboard_hero_trend_buckets($dateRange);
    $buckets = $bucketPack['buckets'] ?? [];

    return [
        'labels' => array_column($buckets, 'label'),
        'values' => array_fill(0, count($buckets), 0.0),
        'granularity' => $bucketPack['granularity'] ?? 'day',
        'metric' => 'activity',
        'metric_label' => 'Activity',
        'total' => 0.0,
    ];
}

function dashboard_fetch_hero_trend(PDO $pdo, array $dateRange): array
{
    $bucketPack = dashboard_hero_trend_buckets($dateRange);
    $buckets = $bucketPack['buckets'] ?? [];
    $granularity = (string) ($bucketPack['granularity'] ?? 'day');
    $labels = array_column($buckets, 'label');
    $values = array_fill(0, count($buckets), 0.0);
    $metric = 'activity';
    $metricLabel = 'Activity';

    if (hasPermission('view_dashboard_revenue') || hasPermission('view_invoices')) {
        $metric = 'collected';
        $metricLabel = 'Collections';
    } elseif (hasPermission('view_estimations')) {
        $metric = 'estimations';
        $metricLabel = 'Estimations';
    }

    if (empty($buckets)) {
        return dashboard_empty_hero_trend($dateRange);
    }

    $rangeStart = (string) ($dateRange['current']['start'] ?? $buckets[0]['start']);
    $rangeEnd = (string) ($dateRange['current']['end'] ?? end($buckets)['end']);

    try {
        if ($metric === 'estimations') {
            if ($granularity === 'hour') {
                $stmt = $pdo->prepare("
                    SELECT HOUR(created_at) AS bucket_key, COUNT(*) AS aggregate_total
                    FROM estimations
                    WHERE status != 'Draft'
                      AND created_at >= :start_at
                      AND created_at < :end_at
                    GROUP BY bucket_key
                ");
                $stmt->execute([
                    'start_at' => $rangeStart . ' 00:00:00',
                    'end_at' => $rangeEnd . ' 00:00:00',
                ]);
            } else {
                $groupExpr = $granularity === 'month'
                    ? "DATE_FORMAT(created_at, '%Y-%m')"
                    : 'DATE(created_at)';
                $stmt = $pdo->prepare("
                    SELECT {$groupExpr} AS bucket_key, COUNT(*) AS aggregate_total
                    FROM estimations
                    WHERE status != 'Draft'
                      AND created_at >= :start_at
                      AND created_at < :end_at
                    GROUP BY bucket_key
                ");
                $stmt->execute([
                    'start_at' => $rangeStart . ' 00:00:00',
                    'end_at' => $rangeEnd . ' 00:00:00',
                ]);
            }

            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $bucketKey = (string) ($row['bucket_key'] ?? '');
                foreach ($buckets as $index => $bucket) {
                    if ($granularity === 'hour' && (string) $bucket['key'] === $bucketKey) {
                        $values[$index] = (float) ($row['aggregate_total'] ?? 0);
                    } elseif ($granularity === 'day' && $bucket['key'] === $bucketKey) {
                        $values[$index] = (float) ($row['aggregate_total'] ?? 0);
                    } elseif ($granularity === 'week' && $bucketKey >= $bucket['start'] && $bucketKey < $bucket['end']) {
                        $values[$index] += (float) ($row['aggregate_total'] ?? 0);
                    } elseif ($granularity === 'month' && $bucket['key'] === $bucketKey) {
                        $values[$index] = (float) ($row['aggregate_total'] ?? 0);
                    }
                }
            }
        } else {
            $hourColumn = dashboard_table_has_column($pdo, 'invoice_payments', 'created_at')
                ? 'created_at'
                : (dashboard_table_has_column($pdo, 'invoice_payments', 'recorded_at') ? 'recorded_at' : null);

            if ($granularity === 'hour') {
                if ($hourColumn !== null) {
                    $stmt = $pdo->prepare("
                        SELECT HOUR({$hourColumn}) AS bucket_key, COALESCE(SUM(amount), 0) AS aggregate_total
                        FROM invoice_payments
                        WHERE {$hourColumn} >= :start_at
                          AND {$hourColumn} < :end_at
                        GROUP BY bucket_key
                    ");
                    $stmt->execute([
                        'start_at' => $rangeStart . ' 00:00:00',
                        'end_at' => $rangeEnd . ' 00:00:00',
                    ]);
                    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                        foreach ($buckets as $index => $bucket) {
                            if ((string) $bucket['key'] === (string) ($row['bucket_key'] ?? '')) {
                                $values[$index] = (float) ($row['aggregate_total'] ?? 0);
                            }
                        }
                    }
                } else {
                    $stmt = $pdo->prepare("
                        SELECT COALESCE(SUM(amount), 0) AS aggregate_total
                        FROM invoice_payments
                        WHERE payment_date >= :start_date
                          AND payment_date < :end_date
                    ");
                    $stmt->execute([
                        'start_date' => $rangeStart,
                        'end_date' => $rangeEnd,
                    ]);
                    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
                    $total = (float) ($row['aggregate_total'] ?? 0);
                    if ($total > 0) {
                        $activeHour = (int) (new DateTimeImmutable('now'))->format('G');
                        $values[$activeHour] = $total;
                    }
                }
            } else {
                $stmt = $pdo->prepare("
                    SELECT payment_date AS bucket_key, COALESCE(SUM(amount), 0) AS aggregate_total
                    FROM invoice_payments
                    WHERE payment_date >= :start_date
                      AND payment_date < :end_date
                    GROUP BY bucket_key
                ");
                $stmt->execute([
                    'start_date' => $rangeStart,
                    'end_date' => $rangeEnd,
                ]);

                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $bucketKey = (string) ($row['bucket_key'] ?? '');
                    $amount = (float) ($row['aggregate_total'] ?? 0);
                    foreach ($buckets as $index => $bucket) {
                        if ($granularity === 'day' && $bucket['key'] === $bucketKey) {
                            $values[$index] = $amount;
                        } elseif ($granularity === 'week' && $bucketKey >= $bucket['start'] && $bucketKey < $bucket['end']) {
                            $values[$index] += $amount;
                        } elseif ($granularity === 'month' && str_starts_with($bucketKey, $bucket['key'])) {
                            $values[$index] += $amount;
                        }
                    }
                }
            }
        }
    } catch (Throwable $exception) {
        return dashboard_empty_hero_trend($dateRange);
    }

    return [
        'labels' => $labels,
        'values' => $values,
        'granularity' => $granularity,
        'metric' => $metric,
        'metric_label' => $metricLabel,
        'total' => array_sum($values),
    ];
}
