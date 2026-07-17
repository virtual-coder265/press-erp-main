<?php
declare(strict_types=1);

require_once __DIR__ . '/dashboard_metrics_helper.php';
require_once __DIR__ . '/settings_helper.php';

if (!function_exists('reports_catalog')) {
    function reports_catalog(): array
    {
        return [
            'invoices' => [
                'title' => 'Invoice Reports',
                'description' => 'Invoice volumes, balances, ageing, and payment status for the selected period.',
                'icon' => 'receipt',
                'href' => 'invoices',
                'permissions' => ['view_invoices'],
            ],
            'sales' => [
                'title' => 'Sales and Revenue',
                'description' => 'Revenue, collections, outstanding balances, and payment trends.',
                'icon' => 'wallet',
                'href' => 'sales',
                'permissions' => ['view_sales', 'view_invoices', 'view_dashboard_revenue'],
            ],
            'materials' => [
                'title' => 'Materials Reports',
                'description' => 'Inventory catalogue, category breakdown, and current rate snapshots.',
                'icon' => 'package',
                'href' => 'materials',
                'permissions' => ['view_materials'],
            ],
            'work_orders' => [
                'title' => 'Work Order Reports',
                'description' => 'Production status, payment readiness, department throughput, and turnaround.',
                'icon' => 'clipboard-list',
                'href' => 'work_orders',
                'permissions' => ['view_work_order_reports', 'manage_work_orders', 'view_work_orders'],
            ],
            'dispatch' => [
                'title' => 'Dispatch Reports',
                'description' => 'Dispatch register volumes, delivery notes, and collection activity.',
                'icon' => 'truck',
                'href' => 'dispatch',
                'permissions' => ['view_dispatch'],
            ],
        ];
    }
}

if (!function_exists('reports_can_access')) {
    function reports_can_access(string $reportKey): bool
    {
        $catalog = reports_catalog();
        if (!isset($catalog[$reportKey])) {
            return false;
        }

        foreach ($catalog[$reportKey]['permissions'] as $permission) {
            if (hasPermission($permission)) {
                return true;
            }
        }

        return false;
    }
}

if (!function_exists('reports_available_modules')) {
    function reports_available_modules(): array
    {
        $available = [];
        foreach (reports_catalog() as $key => $meta) {
            if (reports_can_access($key)) {
                $available[$key] = $meta;
            }
        }

        return $available;
    }
}

if (!function_exists('reports_read_filters')) {
    function reports_read_filters(): array
    {
        $preset = trim((string) ($_GET['preset'] ?? ''));
        $dateFrom = trim((string) ($_GET['date_from'] ?? ''));
        $dateTo = trim((string) ($_GET['date_to'] ?? ''));

        if ($preset !== '' && $preset !== 'custom') {
            $resolved = reports_resolve_preset_range($preset);
            $dateFrom = $resolved['from'];
            $dateTo = $resolved['to'];
        }

        return [
            'preset' => $preset !== '' ? $preset : ($dateFrom || $dateTo ? 'custom' : 'all_time'),
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'search' => trim((string) ($_GET['search'] ?? '')),
            'status' => trim((string) ($_GET['status'] ?? '')),
            'category' => trim((string) ($_GET['category'] ?? '')),
            'sale_type' => trim((string) ($_GET['sale_type'] ?? '')),
            'department' => trim((string) ($_GET['department'] ?? '')),
            'work_order' => trim((string) ($_GET['work_order'] ?? '')),
            'priority' => trim((string) ($_GET['priority'] ?? '')),
        ];
    }
}

if (!function_exists('reports_resolve_preset_range')) {
    function reports_resolve_preset_range(string $preset): array
    {
        $today = new DateTimeImmutable('today');

        switch ($preset) {
            case 'today':
                $from = $today->format('Y-m-d');
                $to = $today->format('Y-m-d');
                break;
            case 'this_week':
                $from = $today->modify('monday this week')->format('Y-m-d');
                $to = $today->format('Y-m-d');
                break;
            case 'this_month':
                $window = dashboard_month_window(0);
                $from = $window['start'];
                $to = $today->format('Y-m-d');
                break;
            case 'last_month':
                $window = dashboard_month_window(-1);
                $from = $window['start'];
                $to = (new DateTimeImmutable($window['end']))->modify('-1 day')->format('Y-m-d');
                break;
            case 'this_quarter':
                $month = (int) $today->format('n');
                $quarterStartMonth = (int) (floor(($month - 1) / 3) * 3 + 1);
                $from = $today->setDate((int) $today->format('Y'), $quarterStartMonth, 1)->format('Y-m-d');
                $to = $today->format('Y-m-d');
                break;
            case 'this_year':
                $from = $today->setDate((int) $today->format('Y'), 1, 1)->format('Y-m-d');
                $to = $today->format('Y-m-d');
                break;
            case 'last_30_days':
                $from = $today->modify('-29 days')->format('Y-m-d');
                $to = $today->format('Y-m-d');
                break;
            case 'last_90_days':
                $from = $today->modify('-89 days')->format('Y-m-d');
                $to = $today->format('Y-m-d');
                break;
            default:
                $from = '';
                $to = '';
        }

        return ['from' => $from, 'to' => $to];
    }
}

if (!function_exists('reports_preset_options')) {
    function reports_preset_options(): array
    {
        return [
            'all_time' => 'All time',
            'today' => 'Today',
            'this_week' => 'This week',
            'this_month' => 'This month',
            'last_month' => 'Last month',
            'this_quarter' => 'This quarter',
            'this_year' => 'This year',
            'last_30_days' => 'Last 30 days',
            'last_90_days' => 'Last 90 days',
            'custom' => 'Custom range',
        ];
    }
}

if (!function_exists('reports_build_query_string')) {
    function reports_build_query_string(array $filters, array $extra = []): string
    {
        $params = array_merge($filters, $extra);
        $parts = [];

        foreach ($params as $key => $value) {
            if ($value === '' || $value === null) {
                continue;
            }
            $parts[] = rawurlencode((string) $key) . '=' . rawurlencode((string) $value);
        }

        return $parts ? ('?' . implode('&', $parts)) : '';
    }
}

if (!function_exists('reports_format_money')) {
    function reports_format_money($value): string
    {
        return number_format((float) $value, 2);
    }
}

if (!function_exists('reports_get_branding')) {
    function reports_get_branding(): array
    {
        $settings = function_exists('get_business_pdf_settings') ? get_business_pdf_settings() : [];
        $businessName = trim((string) ($settings['business_name'] ?? ''));
        if ($businessName === '' && function_exists('get_setting')) {
            $businessName = (string) get_setting('system_app_name', defined('APP_NAME') ? APP_NAME : 'Press ERP');
        }

        return [
            'business_name' => $businessName,
            'business_address' => trim((string) ($settings['business_address'] ?? '')),
            'business_phone' => trim((string) ($settings['business_phone'] ?? '')),
            'business_email' => trim((string) ($settings['business_email'] ?? '')),
            'business_logo' => trim((string) ($settings['business_logo'] ?? '')),
        ];
    }
}

if (!function_exists('reports_append_date_filter')) {
    function reports_append_date_filter(string &$sql, array &$params, string $column, array $filters, string $fromKey = 'date_from', string $toKey = 'date_to'): void
    {
        if (!empty($filters[$fromKey])) {
            $sql .= " AND {$column} >= :{$fromKey}";
            $params[$fromKey] = $filters[$fromKey];
        }
        if (!empty($filters[$toKey])) {
            $sql .= " AND {$column} <= :{$toKey}";
            $params[$toKey] = $filters[$toKey];
        }
    }
}

if (!function_exists('reports_fetch_invoice_kpis')) {
    function reports_fetch_invoice_kpis(PDO $pdo, array $filters): array
    {
        $sql = "
            SELECT
                COUNT(*) AS total_invoices,
                COALESCE(SUM(total_amount), 0) AS total_billed,
                COALESCE(SUM(paid_amount), 0) AS total_collected,
                COALESCE(SUM(CASE WHEN status IN ('Unpaid', 'Partially Paid', 'Overdue') THEN balance ELSE 0 END), 0) AS total_outstanding,
                COALESCE(SUM(CASE WHEN status = 'Paid' THEN 1 ELSE 0 END), 0) AS paid_count,
                COALESCE(SUM(CASE WHEN status = 'Unpaid' THEN 1 ELSE 0 END), 0) AS unpaid_count,
                COALESCE(SUM(CASE WHEN status = 'Partially Paid' THEN 1 ELSE 0 END), 0) AS partial_count,
                COALESCE(SUM(CASE WHEN status = 'Overdue' OR (status IN ('Unpaid','Partially Paid') AND due_date < CURDATE() AND COALESCE(balance,0) > 0) THEN 1 ELSE 0 END), 0) AS overdue_count,
                COALESCE(SUM(CASE WHEN status = 'Cancelled' THEN 1 ELSE 0 END), 0) AS cancelled_count
            FROM invoices
            WHERE 1=1
        ";
        $params = [];
        reports_append_date_filter($sql, $params, 'generated_date', $filters);

        if (!empty($filters['status'])) {
            if ($filters['status'] === 'Overdue') {
                $sql .= " AND (status = 'Overdue' OR (status IN ('Unpaid','Partially Paid') AND due_date < CURDATE() AND COALESCE(balance,0) > 0))";
            } else {
                $sql .= ' AND status = :status';
                $params['status'] = $filters['status'];
            }
        }

        if (!empty($filters['search'])) {
            $sql .= ' AND (invoice_number LIKE :search OR customer_name LIKE :search)';
            $params['search'] = '%' . $filters['search'] . '%';
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            ['label' => 'Total Invoices', 'value' => (int) ($row['total_invoices'] ?? 0), 'tone' => 'indigo', 'icon' => 'receipt'],
            ['label' => 'Total Billed (MK)', 'value' => reports_format_money($row['total_billed'] ?? 0), 'tone' => 'emerald', 'icon' => 'banknote'],
            ['label' => 'Collected (MK)', 'value' => reports_format_money($row['total_collected'] ?? 0), 'tone' => 'purple', 'icon' => 'circle-dollar-sign'],
            ['label' => 'Outstanding (MK)', 'value' => reports_format_money($row['total_outstanding'] ?? 0), 'tone' => 'amber', 'icon' => 'alert-circle'],
            ['label' => 'Paid', 'value' => (int) ($row['paid_count'] ?? 0), 'tone' => 'emerald', 'icon' => 'check-circle'],
            ['label' => 'Overdue', 'value' => (int) ($row['overdue_count'] ?? 0), 'tone' => 'rose', 'icon' => 'clock'],
        ];
    }
}

if (!function_exists('reports_fetch_invoice_rows')) {
    function reports_fetch_invoice_rows(PDO $pdo, array $filters): array
    {
        $sql = "
            SELECT i.invoice_number, i.generated_date, i.due_date, i.customer_name,
                   i.total_amount, i.paid_amount, i.balance, i.status,
                   e.estimation_number,
                   wo.work_order_number
            FROM invoices i
            LEFT JOIN estimations e ON i.estimation_id = e.id
            LEFT JOIN work_orders wo ON wo.invoice_id = i.id
            WHERE 1=1
        ";
        $params = [];
        reports_append_date_filter($sql, $params, 'i.generated_date', $filters);

        if (!empty($filters['status'])) {
            if ($filters['status'] === 'Overdue') {
                $sql .= " AND (i.status = 'Overdue' OR (i.status IN ('Unpaid','Partially Paid') AND i.due_date < CURDATE() AND COALESCE(i.balance,0) > 0))";
            } else {
                $sql .= ' AND i.status = :status';
                $params['status'] = $filters['status'];
            }
        }

        if (!empty($filters['search'])) {
            $sql .= ' AND (i.invoice_number LIKE :search OR i.customer_name LIKE :search OR e.estimation_number LIKE :search)';
            $params['search'] = '%' . $filters['search'] . '%';
        }

        $sql .= ' ORDER BY i.generated_date DESC, i.id DESC';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as &$row) {
            $row['total_amount'] = reports_format_money($row['total_amount']);
            $row['paid_amount'] = reports_format_money($row['paid_amount']);
            $row['balance'] = reports_format_money($row['balance']);
        }

        return $rows;
    }
}

if (!function_exists('reports_fetch_invoice_status_breakdown')) {
    function reports_fetch_invoice_status_breakdown(PDO $pdo, array $filters): array
    {
        $sql = 'SELECT status, COUNT(*) AS total, COALESCE(SUM(total_amount),0) AS amount FROM invoices WHERE 1=1';
        $params = [];
        reports_append_date_filter($sql, $params, 'generated_date', $filters);
        $sql .= ' GROUP BY status ORDER BY total DESC';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('reports_fetch_sales_kpis')) {
    function reports_fetch_sales_kpis(PDO $pdo, array $filters): array
    {
        $invoiceSql = "
            SELECT
                COUNT(*) AS invoice_count,
                COALESCE(SUM(total_amount), 0) AS gross_revenue,
                COALESCE(SUM(paid_amount), 0) AS collected_on_invoices,
                COALESCE(SUM(CASE WHEN status IN ('Unpaid','Partially Paid','Overdue') THEN balance ELSE 0 END), 0) AS outstanding,
                COALESCE(SUM(CASE WHEN estimation_id IS NULL THEN total_amount ELSE 0 END), 0) AS direct_sales,
                COALESCE(SUM(CASE WHEN estimation_id IS NOT NULL THEN total_amount ELSE 0 END), 0) AS invoiced_sales
            FROM invoices
            WHERE status != 'Cancelled'
        ";
        $params = [];
        reports_append_date_filter($invoiceSql, $params, 'generated_date', $filters);

        if (!empty($filters['sale_type']) && $filters['sale_type'] === 'direct') {
            $invoiceSql .= ' AND estimation_id IS NULL';
        } elseif (!empty($filters['sale_type']) && $filters['sale_type'] === 'invoiced') {
            $invoiceSql .= ' AND estimation_id IS NOT NULL';
        }

        if (!empty($filters['status'])) {
            $invoiceSql .= ' AND status = :status';
            $params['status'] = $filters['status'];
        }

        $stmt = $pdo->prepare($invoiceSql);
        $stmt->execute($params);
        $inv = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $paymentSql = "
            SELECT COALESCE(SUM(ip.amount), 0) AS payments_collected
            FROM invoice_payments ip
            INNER JOIN invoices i ON i.id = ip.invoice_id
            WHERE i.status != 'Cancelled'
        ";
        $payParams = [];
        reports_append_date_filter($paymentSql, $payParams, 'ip.payment_date', $filters);

        if (!empty($filters['sale_type']) && $filters['sale_type'] === 'direct') {
            $paymentSql .= ' AND i.estimation_id IS NULL';
        } elseif (!empty($filters['sale_type']) && $filters['sale_type'] === 'invoiced') {
            $paymentSql .= ' AND i.estimation_id IS NOT NULL';
        }

        $payStmt = $pdo->prepare($paymentSql);
        $payStmt->execute($payParams);
        $pay = $payStmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $collectionRate = (float) ($inv['gross_revenue'] ?? 0) > 0
            ? round(((float) ($pay['payments_collected'] ?? 0) / (float) $inv['gross_revenue']) * 100, 1)
            : 0;

        return [
            ['label' => 'Gross Revenue (MK)', 'value' => reports_format_money($inv['gross_revenue'] ?? 0), 'tone' => 'emerald', 'icon' => 'trending-up'],
            ['label' => 'Payments Collected (MK)', 'value' => reports_format_money($pay['payments_collected'] ?? 0), 'tone' => 'indigo', 'icon' => 'wallet'],
            ['label' => 'Outstanding (MK)', 'value' => reports_format_money($inv['outstanding'] ?? 0), 'tone' => 'amber', 'icon' => 'alert-triangle'],
            ['label' => 'Collection Rate', 'value' => $collectionRate . '%', 'tone' => 'purple', 'icon' => 'percent'],
            ['label' => 'Direct Sales (MK)', 'value' => reports_format_money($inv['direct_sales'] ?? 0), 'tone' => 'sky', 'icon' => 'shopping-cart'],
            ['label' => 'Invoiced Sales (MK)', 'value' => reports_format_money($inv['invoiced_sales'] ?? 0), 'tone' => 'slate', 'icon' => 'file-text'],
        ];
    }
}

if (!function_exists('reports_fetch_sales_rows')) {
    function reports_fetch_sales_rows(PDO $pdo, array $filters): array
    {
        $sql = "
            SELECT i.invoice_number, i.generated_date, i.customer_name,
                   CASE WHEN i.estimation_id IS NULL THEN 'Direct Sale' ELSE 'Invoiced Sale' END AS sale_type,
                   i.total_amount, i.paid_amount, i.balance, i.status,
                   e.estimation_number
            FROM invoices i
            LEFT JOIN estimations e ON i.estimation_id = e.id
            WHERE i.status != 'Cancelled'
        ";
        $params = [];
        reports_append_date_filter($sql, $params, 'i.generated_date', $filters);

        if (!empty($filters['sale_type']) && $filters['sale_type'] === 'direct') {
            $sql .= ' AND i.estimation_id IS NULL';
        } elseif (!empty($filters['sale_type']) && $filters['sale_type'] === 'invoiced') {
            $sql .= ' AND i.estimation_id IS NOT NULL';
        }

        if (!empty($filters['status'])) {
            $sql .= ' AND i.status = :status';
            $params['status'] = $filters['status'];
        }

        if (!empty($filters['search'])) {
            $sql .= ' AND (i.invoice_number LIKE :search OR i.customer_name LIKE :search)';
            $params['search'] = '%' . $filters['search'] . '%';
        }

        $sql .= ' ORDER BY i.generated_date DESC, i.id DESC';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as &$row) {
            $row['total_amount'] = reports_format_money($row['total_amount']);
            $row['paid_amount'] = reports_format_money($row['paid_amount']);
            $row['balance'] = reports_format_money($row['balance']);
        }

        return $rows;
    }
}

if (!function_exists('reports_fetch_sales_monthly_trend')) {
    function reports_fetch_sales_monthly_trend(PDO $pdo, array $filters, int $months = 6): array
    {
        $series = dashboard_month_series($months);
        $trend = [];

        foreach ($series as $window) {
            if (!empty($filters['date_from']) && $window['end'] <= $filters['date_from']) {
                continue;
            }
            if (!empty($filters['date_to']) && $window['start'] > $filters['date_to']) {
                continue;
            }

            $stmt = $pdo->prepare("
                SELECT
                    COALESCE(SUM(total_amount), 0) AS revenue,
                    COALESCE(SUM(paid_amount), 0) AS collected
                FROM invoices
                WHERE status != 'Cancelled'
                  AND generated_date >= ? AND generated_date < ?
            ");
            $stmt->execute([$window['start'], $window['end']]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

            $trend[] = [
                'label' => $window['label'],
                'revenue' => (float) ($row['revenue'] ?? 0),
                'collected' => (float) ($row['collected'] ?? 0),
            ];
        }

        return $trend;
    }
}

if (!function_exists('reports_fetch_materials_kpis')) {
    function reports_fetch_materials_kpis(PDO $pdo, array $filters): array
    {
        $sql = "
            SELECT
                COUNT(*) AS total_materials,
                COUNT(DISTINCT m.category_id) AS categories_used,
                COALESCE(AVG(r.rate), 0) AS avg_rate,
                COALESCE(MAX(r.rate), 0) AS max_rate
            FROM materials m
            LEFT JOIN (
                SELECT material_id, rate
                FROM material_rates
                WHERE id IN (SELECT MAX(id) FROM material_rates GROUP BY material_id)
            ) r ON m.id = r.material_id
            WHERE 1=1
        ";
        $params = [];

        if (!empty($filters['category'])) {
            $sql .= ' AND m.category_id = :category';
            $params['category'] = $filters['category'];
        }

        if (!empty($filters['search'])) {
            $sql .= ' AND (m.name LIKE :search OR m.description LIKE :search)';
            $params['search'] = '%' . $filters['search'] . '%';
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            ['label' => 'Total Materials', 'value' => (int) ($row['total_materials'] ?? 0), 'tone' => 'indigo', 'icon' => 'package'],
            ['label' => 'Categories', 'value' => (int) ($row['categories_used'] ?? 0), 'tone' => 'purple', 'icon' => 'layers'],
            ['label' => 'Average Rate (MK)', 'value' => reports_format_money($row['avg_rate'] ?? 0), 'tone' => 'emerald', 'icon' => 'calculator'],
            ['label' => 'Highest Rate (MK)', 'value' => reports_format_money($row['max_rate'] ?? 0), 'tone' => 'amber', 'icon' => 'arrow-up'],
        ];
    }
}

if (!function_exists('reports_fetch_materials_rows')) {
    function reports_fetch_materials_rows(PDO $pdo, array $filters): array
    {
        $sql = "
            SELECT m.name, mc.name AS category_name, m.unit, m.description,
                   COALESCE(r.rate, 0) AS current_rate,
                   r.effective_date AS rate_effective_date
            FROM materials m
            LEFT JOIN material_categories mc ON mc.id = m.category_id
            LEFT JOIN (
                SELECT material_id, rate, effective_date
                FROM material_rates
                WHERE id IN (SELECT MAX(id) FROM material_rates GROUP BY material_id)
            ) r ON m.id = r.material_id
            WHERE 1=1
        ";
        $params = [];

        if (!empty($filters['category'])) {
            $sql .= ' AND m.category_id = :category';
            $params['category'] = $filters['category'];
        }

        if (!empty($filters['search'])) {
            $sql .= ' AND (m.name LIKE :search OR m.description LIKE :search)';
            $params['search'] = '%' . $filters['search'] . '%';
        }

        $sql .= ' ORDER BY mc.name ASC, m.name ASC';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as &$row) {
            $row['current_rate'] = reports_format_money($row['current_rate']);
        }

        return $rows;
    }
}

if (!function_exists('reports_fetch_materials_category_breakdown')) {
    function reports_fetch_materials_category_breakdown(PDO $pdo, array $filters): array
    {
        $sql = "
            SELECT COALESCE(mc.name, 'Uncategorised') AS category_name,
                   COUNT(*) AS material_count,
                   COALESCE(AVG(r.rate), 0) AS avg_rate
            FROM materials m
            LEFT JOIN material_categories mc ON mc.id = m.category_id
            LEFT JOIN (
                SELECT material_id, rate
                FROM material_rates
                WHERE id IN (SELECT MAX(id) FROM material_rates GROUP BY material_id)
            ) r ON m.id = r.material_id
            WHERE 1=1
        ";
        $params = [];

        if (!empty($filters['category'])) {
            $sql .= ' AND m.category_id = :category';
            $params['category'] = $filters['category'];
        }

        if (!empty($filters['search'])) {
            $sql .= ' AND (m.name LIKE :search OR m.description LIKE :search)';
            $params['search'] = '%' . $filters['search'] . '%';
        }

        $sql .= ' GROUP BY mc.id, mc.name ORDER BY material_count DESC';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as &$row) {
            $row['avg_rate'] = reports_format_money($row['avg_rate']);
        }

        return $rows;
    }
}

if (!function_exists('reports_fetch_work_order_kpis')) {
    function reports_fetch_work_order_kpis(PDO $pdo, array $filters): array
    {
        $sql = "
            SELECT
                COUNT(*) AS total_orders,
                COALESCE(SUM(CASE WHEN status IN ('Ready for Production','In Production','Waiting Payment','Awaiting Dispatch') THEN 1 ELSE 0 END), 0) AS active_orders,
                COALESCE(SUM(CASE WHEN status = 'Completed' THEN 1 ELSE 0 END), 0) AS completed_orders,
                COALESCE(SUM(CASE WHEN status = 'Dispatched' THEN 1 ELSE 0 END), 0) AS dispatched_orders,
                COALESCE(SUM(CASE WHEN payment_status = 'Paid' THEN 1 ELSE 0 END), 0) AS payment_ready,
                COALESCE(AVG(CASE WHEN completed_at IS NOT NULL THEN TIMESTAMPDIFF(HOUR, created_at, completed_at) END), 0) AS avg_turnaround_hours
            FROM work_orders
            WHERE 1=1
        ";
        $params = [];
        reports_append_date_filter($sql, $params, 'created_at', $filters);

        if (!empty($filters['status'])) {
            $sql .= ' AND status = :status';
            $params['status'] = $filters['status'];
        }

        if (!empty($filters['priority'])) {
            $sql .= ' AND priority = :priority';
            $params['priority'] = $filters['priority'];
        }

        if (!empty($filters['search'])) {
            $sql .= ' AND (work_order_number LIKE :search OR customer_name LIKE :search OR job_description LIKE :search)';
            $params['search'] = '%' . $filters['search'] . '%';
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            ['label' => 'Total Work Orders', 'value' => (int) ($row['total_orders'] ?? 0), 'tone' => 'indigo', 'icon' => 'clipboard-list'],
            ['label' => 'Active', 'value' => (int) ($row['active_orders'] ?? 0), 'tone' => 'amber', 'icon' => 'activity'],
            ['label' => 'Completed', 'value' => (int) ($row['completed_orders'] ?? 0), 'tone' => 'emerald', 'icon' => 'check-circle'],
            ['label' => 'Dispatched', 'value' => (int) ($row['dispatched_orders'] ?? 0), 'tone' => 'purple', 'icon' => 'truck'],
            ['label' => 'Payment Ready', 'value' => (int) ($row['payment_ready'] ?? 0), 'tone' => 'sky', 'icon' => 'wallet'],
            ['label' => 'Avg Turnaround (hrs)', 'value' => number_format((float) ($row['avg_turnaround_hours'] ?? 0), 1), 'tone' => 'slate', 'icon' => 'timer'],
        ];
    }
}

if (!function_exists('reports_fetch_work_order_rows')) {
    function reports_fetch_work_order_rows(PDO $pdo, array $filters): array
    {
        $sql = "
            SELECT wo.work_order_number, wo.customer_name, wo.status, wo.payment_status,
                   wo.priority, wo.created_at, wo.completed_at, wo.dispatched_at,
                   pd.name AS current_department,
                   TIMESTAMPDIFF(HOUR, wo.created_at, COALESCE(wo.completed_at, NOW())) AS turnaround_hours
            FROM work_orders wo
            LEFT JOIN production_departments pd ON pd.id = wo.current_department_id
            WHERE 1=1
        ";
        $params = [];
        reports_append_date_filter($sql, $params, 'wo.created_at', $filters);

        if (!empty($filters['status'])) {
            $sql .= ' AND wo.status = :status';
            $params['status'] = $filters['status'];
        }

        if (!empty($filters['priority'])) {
            $sql .= ' AND wo.priority = :priority';
            $params['priority'] = $filters['priority'];
        }

        if (!empty($filters['department'])) {
            $sql .= ' AND pd.slug = :department';
            $params['department'] = $filters['department'];
        }

        if (!empty($filters['search'])) {
            $sql .= ' AND (wo.work_order_number LIKE :search OR wo.customer_name LIKE :search OR wo.job_description LIKE :search)';
            $params['search'] = '%' . $filters['search'] . '%';
        }

        $sql .= ' ORDER BY wo.created_at DESC';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('reports_fetch_work_order_status_breakdown')) {
    function reports_fetch_work_order_status_breakdown(PDO $pdo, array $filters): array
    {
        $sql = 'SELECT status, COUNT(*) AS total FROM work_orders WHERE 1=1';
        $params = [];
        reports_append_date_filter($sql, $params, 'created_at', $filters);
        $sql .= ' GROUP BY status ORDER BY total DESC';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('reports_fetch_work_order_department_kpis')) {
    function reports_fetch_work_order_department_kpis(PDO $pdo, array $filters): array
    {
        $sql = "
            SELECT pd.name,
                   COUNT(*) AS total_steps,
                   SUM(CASE WHEN pp.status IN ('Received','In Progress','On Hold') THEN 1 ELSE 0 END) AS active_steps,
                   SUM(CASE WHEN pp.status IN ('Completed','Dispatched') THEN 1 ELSE 0 END) AS finished_steps
            FROM production_progress pp
            INNER JOIN production_departments pd ON pp.department_id = pd.id
            INNER JOIN work_orders wo ON wo.id = pp.work_order_id
            WHERE 1=1
        ";
        $params = [];
        reports_append_date_filter($sql, $params, 'wo.created_at', $filters);

        if (!empty($filters['department'])) {
            $sql .= ' AND pd.slug = :department';
            $params['department'] = $filters['department'];
        }

        $sql .= ' GROUP BY pd.id, pd.name ORDER BY pd.default_order ASC';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('reports_fetch_dispatch_kpis')) {
    function reports_fetch_dispatch_kpis(PDO $pdo, array $filters): array
    {
        $sql = "
            SELECT
                COUNT(*) AS total_dispatches,
                COALESCE(SUM(quantity), 0) AS total_quantity,
                COALESCE(SUM(CASE WHEN date_out IS NOT NULL THEN 1 ELSE 0 END), 0) AS dispatched_out,
                COALESCE(SUM(CASE WHEN collected_at IS NOT NULL THEN 1 ELSE 0 END), 0) AS collected,
                COALESCE(SUM(CASE WHEN date_out IS NULL THEN 1 ELSE 0 END), 0) AS pending_dispatch
            FROM dispatch_register
            WHERE 1=1
        ";
        $params = [];
        reports_append_date_filter($sql, $params, 'date_in', $filters);

        if (!empty($filters['work_order'])) {
            $sql .= ' AND work_order_number LIKE :work_order';
            $params['work_order'] = '%' . $filters['work_order'] . '%';
        }

        if (!empty($filters['search'])) {
            $sql .= ' AND (work_order_number LIKE :search OR ministry_department LIKE :search OR job_description LIKE :search OR delivery_note_number LIKE :search)';
            $params['search'] = '%' . $filters['search'] . '%';
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            ['label' => 'Total Entries', 'value' => (int) ($row['total_dispatches'] ?? 0), 'tone' => 'indigo', 'icon' => 'truck'],
            ['label' => 'Total Quantity', 'value' => number_format((int) ($row['total_quantity'] ?? 0)), 'tone' => 'emerald', 'icon' => 'package'],
            ['label' => 'Dispatched Out', 'value' => (int) ($row['dispatched_out'] ?? 0), 'tone' => 'purple', 'icon' => 'send'],
            ['label' => 'Collected', 'value' => (int) ($row['collected'] ?? 0), 'tone' => 'sky', 'icon' => 'user-check'],
            ['label' => 'Pending Dispatch', 'value' => (int) ($row['pending_dispatch'] ?? 0), 'tone' => 'amber', 'icon' => 'clock'],
        ];
    }
}

if (!function_exists('reports_fetch_dispatch_rows')) {
    function reports_fetch_dispatch_rows(PDO $pdo, array $filters): array
    {
        $sql = "
            SELECT d.work_order_number, d.date_in, d.date_out, d.ministry_department,
                   d.job_description, d.quantity, d.delivery_note_number,
                   d.collected_by_name, d.collected_at,
                   u1.name AS authorised_dispatcher_name
            FROM dispatch_register d
            LEFT JOIN users u1 ON d.authorised_dispatcher_id = u1.id
            WHERE 1=1
        ";
        $params = [];
        reports_append_date_filter($sql, $params, 'd.date_in', $filters);

        if (!empty($filters['work_order'])) {
            $sql .= ' AND d.work_order_number LIKE :work_order';
            $params['work_order'] = '%' . $filters['work_order'] . '%';
        }

        if (!empty($filters['search'])) {
            $sql .= ' AND (d.work_order_number LIKE :search OR d.ministry_department LIKE :search OR d.job_description LIKE :search OR d.delivery_note_number LIKE :search)';
            $params['search'] = '%' . $filters['search'] . '%';
        }

        $sql .= ' ORDER BY d.date_in DESC, d.created_at DESC';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('reports_get_columns')) {
    function reports_get_columns(string $reportKey): array
    {
        $columns = [
            'invoices' => [
                'invoice_number' => 'Invoice #',
                'generated_date' => 'Date',
                'due_date' => 'Due Date',
                'customer_name' => 'Customer',
                'estimation_number' => 'Estimation #',
                'work_order_number' => 'Work Order #',
                'total_amount' => 'Total (MK)',
                'paid_amount' => 'Paid (MK)',
                'balance' => 'Balance (MK)',
                'status' => 'Status',
            ],
            'sales' => [
                'invoice_number' => 'Invoice #',
                'generated_date' => 'Date',
                'customer_name' => 'Customer',
                'sale_type' => 'Type',
                'estimation_number' => 'Estimation #',
                'total_amount' => 'Total (MK)',
                'paid_amount' => 'Paid (MK)',
                'balance' => 'Balance (MK)',
                'status' => 'Status',
            ],
            'materials' => [
                'name' => 'Material',
                'category_name' => 'Category',
                'unit' => 'Unit',
                'current_rate' => 'Rate (MK)',
                'rate_effective_date' => 'Rate Date',
                'description' => 'Description',
            ],
            'work_orders' => [
                'work_order_number' => 'Work Order #',
                'customer_name' => 'Customer',
                'status' => 'Status',
                'payment_status' => 'Payment',
                'priority' => 'Priority',
                'current_department' => 'Department',
                'created_at' => 'Created',
                'completed_at' => 'Completed',
                'turnaround_hours' => 'Turnaround (hrs)',
            ],
            'dispatch' => [
                'work_order_number' => 'Work Order #',
                'date_in' => 'Date In',
                'date_out' => 'Date Out',
                'ministry_department' => 'Ministry/Department',
                'job_description' => 'Job Description',
                'quantity' => 'Quantity',
                'delivery_note_number' => 'Delivery Note #',
                'authorised_dispatcher_name' => 'Authorised By',
                'collected_by_name' => 'Collected By',
                'collected_at' => 'Collected At',
            ],
        ];

        return $columns[$reportKey] ?? [];
    }
}

if (!function_exists('reports_get_title')) {
    function reports_get_title(string $reportKey): string
    {
        $catalog = reports_catalog();

        return $catalog[$reportKey]['title'] ?? 'Report';
    }
}

if (!function_exists('reports_fetch_rows')) {
    function reports_fetch_rows(PDO $pdo, string $reportKey, array $filters): array
    {
        switch ($reportKey) {
            case 'invoices':
                return reports_fetch_invoice_rows($pdo, $filters);
            case 'sales':
                return reports_fetch_sales_rows($pdo, $filters);
            case 'materials':
                return reports_fetch_materials_rows($pdo, $filters);
            case 'work_orders':
                return reports_fetch_work_order_rows($pdo, $filters);
            case 'dispatch':
                return reports_fetch_dispatch_rows($pdo, $filters);
            default:
                return [];
        }
    }
}

if (!function_exists('reports_fetch_kpis')) {
    function reports_fetch_kpis(PDO $pdo, string $reportKey, array $filters): array
    {
        switch ($reportKey) {
            case 'invoices':
                return reports_fetch_invoice_kpis($pdo, $filters);
            case 'sales':
                return reports_fetch_sales_kpis($pdo, $filters);
            case 'materials':
                return reports_fetch_materials_kpis($pdo, $filters);
            case 'work_orders':
                return reports_fetch_work_order_kpis($pdo, $filters);
            case 'dispatch':
                return reports_fetch_dispatch_kpis($pdo, $filters);
            default:
                return [];
        }
    }
}

if (!function_exists('reports_filter_period_label')) {
    function reports_filter_period_label(array $filters): string
    {
        if (($filters['preset'] ?? '') === 'all_time' && empty($filters['date_from']) && empty($filters['date_to'])) {
            return 'All time';
        }

        if (!empty($filters['date_from']) && !empty($filters['date_to'])) {
            return date('M d, Y', strtotime($filters['date_from'])) . ' – ' . date('M d, Y', strtotime($filters['date_to']));
        }

        if (!empty($filters['date_from'])) {
            return 'From ' . date('M d, Y', strtotime($filters['date_from']));
        }

        if (!empty($filters['date_to'])) {
            return 'Until ' . date('M d, Y', strtotime($filters['date_to']));
        }

        $options = reports_preset_options();
        $preset = $filters['preset'] ?? 'all_time';

        return $options[$preset] ?? 'All time';
    }
}
