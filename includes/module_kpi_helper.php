<?php

if (!function_exists('module_kpi_format_currency')) {
    function module_kpi_format_currency($value): string
    {
        return 'MK ' . number_format((float) $value, 2);
    }
}

if (!function_exists('sales_module_kpis')) {
    function sales_module_kpis(PDO $pdo): array
    {
        $row = $pdo->query(
            "SELECT
                COALESCE(SUM(balance), 0) AS outstanding,
                COALESCE(SUM(CASE WHEN status = 'Overdue' THEN 1 ELSE 0 END), 0) AS overdue_count,
                COALESCE(SUM(CASE WHEN status = 'Overdue' THEN balance ELSE 0 END), 0) AS overdue_balance,
                COALESCE(SUM(CASE WHEN MONTH(generated_date) = MONTH(CURDATE()) AND YEAR(generated_date) = YEAR(CURDATE()) THEN total_amount ELSE 0 END), 0) AS mtd_invoiced
             FROM invoices"
        )->fetch(PDO::FETCH_ASSOC) ?: [];

        $collected = $pdo->query(
            "SELECT COALESCE(SUM(amount), 0) AS total
             FROM invoice_payments
             WHERE MONTH(payment_date) = MONTH(CURDATE())
               AND YEAR(payment_date) = YEAR(CURDATE())"
        )->fetchColumn();

        return [
            [
                'label' => 'Outstanding',
                'value' => module_kpi_format_currency($row['outstanding'] ?? 0),
                'icon' => 'wallet',
                'tone' => ((float) ($row['outstanding'] ?? 0)) > 0 ? 'warning' : 'success',
            ],
            [
                'label' => 'Overdue invoices',
                'value' => number_format((int) ($row['overdue_count'] ?? 0)),
                'icon' => 'alert-circle',
                'tone' => ((int) ($row['overdue_count'] ?? 0)) > 0 ? 'danger' : 'success',
            ],
            [
                'label' => 'MTD invoiced',
                'value' => module_kpi_format_currency($row['mtd_invoiced'] ?? 0),
                'icon' => 'receipt',
                'tone' => 'indigo',
            ],
            [
                'label' => 'MTD collected',
                'value' => module_kpi_format_currency($collected ?? 0),
                'icon' => 'banknote',
                'tone' => 'emerald',
            ],
        ];
    }
}

if (!function_exists('dispatch_module_kpis')) {
    function dispatch_module_kpis(PDO $pdo): array
    {
        $row = $pdo->query(
            "SELECT
                COALESCE(SUM(CASE WHEN DATE(date_in) = CURDATE() THEN 1 ELSE 0 END), 0) AS today_count,
                COALESCE(SUM(CASE WHEN date_out IS NULL OR date_out = '' THEN 1 ELSE 0 END), 0) AS pending_count,
                COALESCE(SUM(CASE WHEN DATE(date_out) = CURDATE() THEN 1 ELSE 0 END), 0) AS dispatched_today,
                COUNT(*) AS total_visible
             FROM dispatch_register"
        )->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            [
                'label' => 'Today\'s entries',
                'value' => number_format((int) ($row['today_count'] ?? 0)),
                'icon' => 'calendar',
                'tone' => 'indigo',
            ],
            [
                'label' => 'Pending dispatch',
                'value' => number_format((int) ($row['pending_count'] ?? 0)),
                'icon' => 'clock',
                'tone' => ((int) ($row['pending_count'] ?? 0)) > 0 ? 'warning' : 'success',
            ],
            [
                'label' => 'Dispatched today',
                'value' => number_format((int) ($row['dispatched_today'] ?? 0)),
                'icon' => 'truck',
                'tone' => 'emerald',
            ],
            [
                'label' => 'Register total',
                'value' => number_format((int) ($row['total_visible'] ?? 0)),
                'icon' => 'clipboard-list',
                'tone' => 'neutral',
            ],
        ];
    }
}

if (!function_exists('estimations_module_kpis')) {
    function estimations_module_kpis(PDO $pdo): array
    {
        $rows = $pdo->query(
            "SELECT status, COUNT(*) AS total_count, COALESCE(SUM(total_amount), 0) AS total_amount
             FROM estimations
             GROUP BY status"
        )->fetchAll(PDO::FETCH_ASSOC);

        $byStatus = [];
        foreach ($rows as $row) {
            $byStatus[(string) $row['status']] = $row;
        }

        $draftCount = (int) ($byStatus['Draft']['total_count'] ?? 0);
        $completedCount = (int) (($byStatus['Completed']['total_count'] ?? 0) + ($byStatus['Approved']['total_count'] ?? 0));
        $invoicedCount = (int) ($byStatus['Invoiced']['total_count'] ?? 0);
        $pipelineValue = 0.0;
        foreach (['Draft', 'Completed', 'Approved'] as $status) {
            $pipelineValue += (float) ($byStatus[$status]['total_amount'] ?? 0);
        }

        return [
            [
                'label' => 'Drafts',
                'value' => number_format($draftCount),
                'icon' => 'file-edit',
                'tone' => $draftCount > 0 ? 'warning' : 'neutral',
            ],
            [
                'label' => 'Completed',
                'value' => number_format($completedCount),
                'icon' => 'check-circle',
                'tone' => 'indigo',
            ],
            [
                'label' => 'Invoiced',
                'value' => number_format($invoicedCount),
                'icon' => 'receipt',
                'tone' => 'emerald',
            ],
            [
                'label' => 'Pipeline value',
                'value' => module_kpi_format_currency($pipelineValue),
                'icon' => 'trending-up',
                'tone' => 'success',
            ],
        ];
    }
}
