<?php

require_once __DIR__ . '/permissions_helper.php';

if (!function_exists('dashboard_resolve_persona')) {
    /**
     * Resolve the dashboard persona for the current session user.
     */
    function dashboard_resolve_persona(): string
    {
        if (hasPermission('view_dashboard_revenue') || hasPermission('view_invoices')) {
            if (hasPermission('view_work_orders') && !hasPermission('view_projects') && !hasPermission('view_tasks')) {
                return 'production';
            }

            return 'finance';
        }

        if (permissions_can_view_work_orders() && !permissions_can_view_commercial()) {
            return 'production';
        }

        if (hasPermission('view_projects') || hasPermission('manage_projects')) {
            return 'project_manager';
        }

        if (hasPermission('view_tasks')) {
            return 'task_worker';
        }

        if (hasPermission('manage_estimations') || hasPermission('view_estimations')) {
            return 'estimator';
        }

        if (hasPermission('view_users') || hasPermission('view_audit_logs')) {
            return 'hr_admin';
        }

        return 'general';
    }
}

if (!function_exists('dashboard_persona_label')) {
    function dashboard_persona_label(string $persona): string
    {
        $labels = [
            'finance' => 'Finance',
            'production' => 'Production',
            'project_manager' => 'Project Manager',
            'task_worker' => 'Task Owner',
            'estimator' => 'Estimator',
            'hr_admin' => 'Administration',
            'general' => 'Operations',
        ];

        return $labels[$persona] ?? 'Operations';
    }
}

if (!function_exists('dashboard_default_landing_path')) {
    /**
     * Relative path for post-login landing (no leading slash).
     */
    function dashboard_default_landing_path(): string
    {
        $persona = dashboard_resolve_persona();

        if ($persona === 'production' && permissions_can_view_work_orders()) {
            return 'modules/work_orders/dashboard';
        }

        if ($persona === 'task_worker' && hasPermission('view_tasks') && !permissions_can_view_commercial()) {
            return 'modules/tasks/list';
        }

        return 'modules/dashboard/index';
    }
}

if (!function_exists('dashboard_panel_order')) {
    /**
     * CSS flex order map for main dashboard panels by persona.
     *
     * @return array<string, int>
     */
    function dashboard_panel_order(?string $persona = null): array
    {
        $persona = $persona ?? dashboard_resolve_persona();

        $orders = [
            'general' => [
                'kpis' => 1,
                'work_orders' => 2,
                'finance' => 3,
                'revenue' => 4,
                'materials' => 5,
                'debtors' => 6,
                'approvals' => 7,
                'activity' => 8,
            ],
            'finance' => [
                'kpis' => 1,
                'finance' => 2,
                'revenue' => 3,
                'debtors' => 4,
                'approvals' => 5,
                'work_orders' => 6,
                'materials' => 7,
                'activity' => 8,
            ],
            'production' => [
                'kpis' => 1,
                'work_orders' => 2,
                'materials' => 3,
                'approvals' => 4,
                'finance' => 5,
                'revenue' => 6,
                'debtors' => 7,
                'activity' => 8,
            ],
            'project_manager' => [
                'kpis' => 1,
                'approvals' => 2,
                'activity' => 3,
                'work_orders' => 4,
                'finance' => 5,
                'revenue' => 6,
                'materials' => 7,
                'debtors' => 8,
            ],
            'task_worker' => [
                'kpis' => 1,
                'approvals' => 2,
                'activity' => 3,
                'work_orders' => 4,
                'finance' => 5,
                'revenue' => 6,
                'materials' => 7,
                'debtors' => 8,
            ],
            'estimator' => [
                'kpis' => 1,
                'approvals' => 2,
                'finance' => 3,
                'revenue' => 4,
                'work_orders' => 5,
                'materials' => 6,
                'debtors' => 7,
                'activity' => 8,
            ],
            'hr_admin' => [
                'kpis' => 1,
                'approvals' => 2,
                'activity' => 3,
                'finance' => 4,
                'revenue' => 5,
                'debtors' => 6,
                'work_orders' => 7,
                'materials' => 8,
            ],
        ];

        return $orders[$persona] ?? $orders['general'];
    }
}
