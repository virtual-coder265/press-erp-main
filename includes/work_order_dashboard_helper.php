<?php

if (!function_exists('work_order_dashboard_due_label')) {
    function work_order_dashboard_due_label(?string $dueDate): array
    {
        if ($dueDate === null || $dueDate === '') {
            return ['label' => 'No due date', 'tone' => 'neutral'];
        }

        $due = strtotime($dueDate);
        if ($due === false) {
            return ['label' => $dueDate, 'tone' => 'neutral'];
        }

        $today = strtotime('today');
        $tomorrow = strtotime('tomorrow');

        if ($due < $today) {
            return ['label' => 'Overdue', 'tone' => 'danger'];
        }
        if ($due === $today) {
            return ['label' => 'Due today', 'tone' => 'warning'];
        }
        if ($due === $tomorrow) {
            return ['label' => 'Due tomorrow', 'tone' => 'info'];
        }

        return ['label' => date('M j, Y', $due), 'tone' => 'neutral'];
    }
}

if (!function_exists('work_order_dashboard_department_ids_by_slugs')) {
    function work_order_dashboard_department_ids_by_slugs(PDO $pdo, array $slugs): array
    {
        if (empty($slugs)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($slugs), '?'));
        $stmt = $pdo->prepare(
            "SELECT id, slug FROM production_departments WHERE slug IN ($placeholders) AND is_active = 1"
        );
        $stmt->execute(array_values($slugs));

        $map = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $map[(string) $row['slug']] = (int) $row['id'];
        }

        return $map;
    }
}

if (!function_exists('work_order_dashboard_kpis')) {
    function work_order_dashboard_kpis(PDO $pdo): array
    {
        $row = work_order_safe_fetch(
            $pdo,
            "SELECT
                COUNT(*) AS total,
                COALESCE(SUM(CASE WHEN status NOT IN ('Completed', 'Cancelled') THEN 1 ELSE 0 END), 0) AS active,
                COALESCE(SUM(CASE WHEN status = 'In Production' THEN 1 ELSE 0 END), 0) AS in_production,
                COALESCE(SUM(CASE WHEN status = 'Awaiting Dispatch' THEN 1 ELSE 0 END), 0) AS awaiting_dispatch,
                COALESCE(SUM(CASE WHEN status = 'Completed' THEN 1 ELSE 0 END), 0) AS completed,
                COALESCE(SUM(CASE WHEN DATE(completed_at) = CURDATE() THEN 1 ELSE 0 END), 0) AS completed_today,
                COALESCE(SUM(CASE
                    WHEN due_date IS NOT NULL
                         AND due_date < CURDATE()
                         AND status NOT IN ('Completed', 'Cancelled')
                    THEN 1 ELSE 0 END), 0) AS overdue,
                COALESCE(SUM(CASE
                    WHEN status = 'In Production'
                         AND due_date IS NOT NULL
                         AND due_date < CURDATE()
                    THEN 1 ELSE 0 END), 0) AS in_production_overdue
             FROM work_orders"
        );
        $stats = $row[0] ?? [];

        $active = max(0, (int) ($stats['active'] ?? 0));
        $total = max(0, (int) ($stats['total'] ?? 0));
        $inProduction = max(0, (int) ($stats['in_production'] ?? 0));
        $awaitingDispatch = max(0, (int) ($stats['awaiting_dispatch'] ?? 0));
        $completedToday = max(0, (int) ($stats['completed_today'] ?? 0));
        $inProductionOverdue = max(0, (int) ($stats['in_production_overdue'] ?? 0));

        $turnaroundRow = work_order_safe_fetch(
            $pdo,
            "SELECT AVG(TIMESTAMPDIFF(HOUR, created_at, completed_at)) AS avg_hours
             FROM work_orders
             WHERE completed_at IS NOT NULL
               AND completed_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)"
        );
        $avgHours = $turnaroundRow[0]['avg_hours'] ?? null;
        $avgDays = null;
        if ($avgHours !== null && $avgHours !== '') {
            $avgDays = round(((float) $avgHours) / 24, 1);
        }

        $pct = static function (int $count, int $denominator): int {
            if ($denominator <= 0) {
                return 0;
            }

            return (int) round(($count / $denominator) * 100);
        };

        return [
            [
                'key' => 'total',
                'label' => 'Total Work Orders',
                'value' => (string) $total,
                'meta' => $active . ' currently open',
                'progress' => $total > 0 ? $pct($active, $total) : 0,
                'tone' => 'emerald',
                'icon' => 'clipboard-list',
                'href' => 'list',
            ],
            [
                'key' => 'in_production',
                'label' => 'In Production',
                'value' => (string) $inProduction,
                'meta' => $inProductionOverdue > 0
                    ? $inProductionOverdue . ' overdue in production'
                    : ($inProduction > 0 ? 'Jobs on the floor' : 'No jobs in production'),
                'progress' => $pct($inProduction, $active),
                'tone' => 'indigo',
                'icon' => 'factory',
                'href' => 'list?status=In+Production',
            ],
            [
                'key' => 'awaiting_dispatch',
                'label' => 'Awaiting Dispatch',
                'value' => (string) $awaitingDispatch,
                'meta' => $awaitingDispatch > 0 ? 'Needs attention' : 'Queue clear',
                'progress' => $pct($awaitingDispatch, $active),
                'tone' => 'purple',
                'icon' => 'truck',
                'href' => 'list?status=Awaiting+Dispatch',
            ],
            [
                'key' => 'completed_today',
                'label' => 'Completed Today',
                'value' => (string) $completedToday,
                'meta' => $completedToday > 0 ? 'Finished today' : 'No completions yet today',
                'progress' => $pct($completedToday, max($active, 1)),
                'tone' => 'blue',
                'icon' => 'check-circle',
                'href' => 'list?status=Completed',
            ],
            [
                'key' => 'turnaround',
                'label' => 'Avg Turnaround',
                'value' => $avgDays !== null ? $avgDays . ' days' : '—',
                'meta' => 'Last 30 days completed jobs',
                'progress' => $avgDays !== null ? min(100, (int) round($avgDays / 7 * 100)) : 0,
                'tone' => 'teal',
                'icon' => 'clock',
                'href' => (hasPermission('view_work_order_reports') || hasPermission('manage_work_orders'))
                    ? 'reports'
                    : 'list?status=Completed',
            ],
        ];
    }
}

if (!function_exists('work_order_dashboard_pipeline')) {
    function work_order_dashboard_pipeline(PDO $pdo): array
    {
        $deptIds = work_order_dashboard_department_ids_by_slugs($pdo, [
            'origination',
            'photosetters',
            'machine',
            'new-site',
            'binding',
            'finishing',
            'dispatch-office',
        ]);

        $accepted = (int) ($pdo->query(
            "SELECT COUNT(*) FROM work_orders
             WHERE status IN ('Draft', 'Waiting Payment', 'Ready for Production')"
        )->fetchColumn() ?: 0);

        $countByDeptIds = static function (array $ids) use ($pdo): int {
            $ids = array_values(array_filter(array_map('intval', $ids)));
            if (empty($ids)) {
                return 0;
            }

            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $pdo->prepare(
                "SELECT COUNT(*) FROM work_orders
                 WHERE status = 'In Production' AND current_department_id IN ($placeholders)"
            );
            $stmt->execute($ids);

            return (int) ($stmt->fetchColumn() ?: 0);
        };

        $originationCount = $countByDeptIds([$deptIds['origination'] ?? 0]);
        $printingCount = $countByDeptIds([
            $deptIds['photosetters'] ?? 0,
            $deptIds['machine'] ?? 0,
            $deptIds['new-site'] ?? 0,
        ]);
        $bindingCount = $countByDeptIds([$deptIds['binding'] ?? 0]);
        $finishingCount = $countByDeptIds([$deptIds['finishing'] ?? 0]);

        $dispatchStmt = $pdo->prepare(
            "SELECT COUNT(*) FROM work_orders wo
             LEFT JOIN production_departments pd ON wo.current_department_id = pd.id
             WHERE wo.status = 'Awaiting Dispatch'
                OR (wo.status = 'In Production' AND pd.slug = 'dispatch-office')"
        );
        $dispatchStmt->execute();
        $dispatchCount = (int) ($dispatchStmt->fetchColumn() ?: 0);

        $stages = [
            [
                'label' => 'Accepted',
                'count' => $accepted,
                'icon' => 'file-check',
                'href' => 'list?status=Ready+for+Production',
            ],
            [
                'label' => 'Origination',
                'count' => $originationCount,
                'icon' => 'pen-tool',
                'href' => 'list?department=origination',
            ],
            [
                'label' => 'Printing',
                'count' => $printingCount,
                'icon' => 'printer',
                'href' => 'list?department=machine',
            ],
            [
                'label' => 'Binding',
                'count' => $bindingCount,
                'icon' => 'book-open',
                'href' => 'list?department=binding',
            ],
            [
                'label' => 'Finishing',
                'count' => $finishingCount,
                'icon' => 'scissors',
                'href' => 'list?department=finishing',
            ],
            [
                'label' => 'Dispatch',
                'count' => $dispatchCount,
                'icon' => 'package-check',
                'href' => 'list?status=Awaiting+Dispatch',
            ],
        ];

        $totalOpen = (int) ($pdo->query(
            "SELECT COUNT(*) FROM work_orders WHERE status NOT IN ('Completed', 'Cancelled')"
        )->fetchColumn() ?: 0);
        $denominator = max($totalOpen, 1);

        foreach ($stages as &$stage) {
            $stage['pct'] = (int) round(((int) $stage['count'] / $denominator) * 100);
        }
        unset($stage);

        return $stages;
    }
}

if (!function_exists('work_order_dashboard_active_queue')) {
    function work_order_dashboard_active_queue(PDO $pdo, int $limit = 8): array
    {
        $rows = work_order_safe_fetch(
            $pdo,
            "SELECT wo.id, wo.work_order_number, wo.customer_name, wo.status, wo.priority,
                    wo.due_date, pd.name AS department_name, pd.slug AS department_slug
             FROM work_orders wo
             LEFT JOIN production_departments pd ON wo.current_department_id = pd.id
             WHERE wo.status = 'In Production'
             ORDER BY
                CASE wo.priority WHEN 'Critical' THEN 0 WHEN 'Urgent' THEN 1 ELSE 2 END ASC,
                COALESCE(wo.due_date, '2999-12-31') ASC
             LIMIT " . max(1, (int) $limit)
        );

        foreach ($rows as &$row) {
            $due = work_order_dashboard_due_label(isset($row['due_date']) ? (string) $row['due_date'] : null);
            $row['due_label'] = $due['label'];
            $row['due_tone'] = $due['tone'];
        }
        unset($row);

        return $rows;
    }
}

if (!function_exists('work_order_dashboard_trend')) {
    function work_order_dashboard_trend(PDO $pdo, int $days = 7): array
    {
        $days = max(1, $days);
        $startDate = (new DateTimeImmutable('today'))->modify('-' . ($days - 1) . ' days');

        $completedRows = work_order_safe_fetch(
            $pdo,
            "SELECT DATE(completed_at) AS day_key, COUNT(*) AS total
             FROM work_orders
             WHERE completed_at IS NOT NULL
               AND DATE(completed_at) >= DATE_SUB(CURDATE(), INTERVAL :offset DAY)
             GROUP BY DATE(completed_at)",
            ['offset' => $days - 1]
        );
        $startedRows = work_order_safe_fetch(
            $pdo,
            "SELECT DATE(production_started_at) AS day_key, COUNT(*) AS total
             FROM work_orders
             WHERE production_started_at IS NOT NULL
               AND DATE(production_started_at) >= DATE_SUB(CURDATE(), INTERVAL :offset DAY)
             GROUP BY DATE(production_started_at)",
            ['offset' => $days - 1]
        );

        $completedMap = [];
        foreach ($completedRows as $row) {
            $completedMap[(string) $row['day_key']] = (int) ($row['total'] ?? 0);
        }

        $startedMap = [];
        foreach ($startedRows as $row) {
            $startedMap[(string) $row['day_key']] = (int) ($row['total'] ?? 0);
        }

        $labels = [];
        $completed = [];
        $started = [];

        for ($i = 0; $i < $days; $i++) {
            $day = $startDate->modify('+' . $i . ' days');
            $key = $day->format('Y-m-d');
            $labels[] = $day->format('D');
            $completed[] = $completedMap[$key] ?? 0;
            $started[] = $startedMap[$key] ?? 0;
        }

        return [
            'labels' => $labels,
            'completed' => $completed,
            'started' => $started,
        ];
    }
}

if (!function_exists('work_order_dashboard_recent')) {
    function work_order_dashboard_recent(PDO $pdo, int $limit = 10): array
    {
        return work_order_safe_fetch(
            $pdo,
            "SELECT wo.*, i.invoice_number, i.balance, e.estimation_number,
                    pd.name AS current_department_name, pd.slug AS current_department_slug
             FROM work_orders wo
             INNER JOIN invoices i ON wo.invoice_id = i.id
             LEFT JOIN estimations e ON wo.estimation_id = e.id
             LEFT JOIN production_departments pd ON wo.current_department_id = pd.id
             ORDER BY wo.created_at DESC
             LIMIT " . max(1, (int) $limit)
        );
    }
}

if (!function_exists('work_order_dashboard_quick_actions')) {
    function work_order_dashboard_quick_actions(): array
    {
        $actions = [];

        if (hasPermission('manage_work_orders') || hasPermission('manage_invoices')) {
            $actions[] = [
                'label' => 'New Work Order',
                'href' => 'create',
                'icon' => 'plus-circle',
                'tone' => 'emerald',
            ];
        }

        if (hasPermission('manage_production_queues') || hasPermission('manage_work_orders')) {
            $actions[] = [
                'label' => 'Production Workspace',
                'href' => 'workspace',
                'icon' => 'layout-grid',
                'tone' => 'indigo',
            ];
        }

        if (hasPermission('view_work_orders') || hasPermission('manage_work_orders')) {
            $actions[] = [
                'label' => 'Dispatch',
                'href' => 'dispatch',
                'icon' => 'truck',
                'tone' => 'orange',
            ];
            $actions[] = [
                'label' => 'Production Timeline',
                'href' => 'timeline',
                'icon' => 'git-branch',
                'tone' => 'blue',
            ];
        }

        if (hasPermission('view_work_order_reports') || hasPermission('manage_work_orders')) {
            $actions[] = [
                'label' => 'Reports',
                'href' => 'reports',
                'icon' => 'bar-chart-3',
                'tone' => 'purple',
            ];
        }

        return $actions;
    }
}
