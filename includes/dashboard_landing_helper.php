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
                'focus' => 2,
                'attention' => 3,
                'main_grid' => 4,
                'activity' => 5,
            ],
            'finance' => [
                'kpis' => 1,
                'focus' => 2,
                'attention' => 3,
                'main_grid' => 4,
                'activity' => 5,
            ],
            'production' => [
                'kpis' => 1,
                'focus' => 2,
                'attention' => 3,
                'main_grid' => 4,
                'activity' => 5,
            ],
            'project_manager' => [
                'kpis' => 1,
                'focus' => 2,
                'attention' => 3,
                'main_grid' => 4,
                'activity' => 5,
            ],
            'task_worker' => [
                'kpis' => 1,
                'focus' => 2,
                'attention' => 3,
                'main_grid' => 4,
                'activity' => 5,
            ],
            'estimator' => [
                'kpis' => 1,
                'focus' => 2,
                'attention' => 3,
                'main_grid' => 4,
                'activity' => 5,
            ],
            'hr_admin' => [
                'kpis' => 1,
                'focus' => 2,
                'attention' => 3,
                'main_grid' => 4,
                'activity' => 5,
            ],
        ];

        return $orders[$persona] ?? $orders['general'];
    }
}

if (!function_exists('dashboard_main_column_order')) {
    /**
     * CSS flex order map for panels inside the main dashboard column.
     *
     * @return array<string, int>
     */
    function dashboard_main_column_order(?string $persona = null): array
    {
        $persona = $persona ?? dashboard_resolve_persona();

        $orders = [
            'general' => [
                'work_orders' => 1,
                'production_pipeline' => 2,
                'finance' => 3,
                'estimation_funnel' => 4,
                'project_health' => 5,
                'debtors' => 6,
                'approvals' => 7,
            ],
            'finance' => [
                'finance' => 1,
                'debtors' => 2,
                'estimation_funnel' => 3,
                'approvals' => 4,
                'work_orders' => 5,
                'production_pipeline' => 6,
                'project_health' => 7,
            ],
            'production' => [
                'work_orders' => 1,
                'production_pipeline' => 2,
                'finance' => 3,
                'estimation_funnel' => 4,
                'project_health' => 5,
                'debtors' => 6,
                'approvals' => 7,
            ],
            'project_manager' => [
                'project_health' => 1,
                'approvals' => 2,
                'work_orders' => 3,
                'production_pipeline' => 4,
                'finance' => 5,
                'estimation_funnel' => 6,
                'debtors' => 7,
            ],
            'task_worker' => [
                'approvals' => 1,
                'project_health' => 2,
                'work_orders' => 3,
                'finance' => 4,
                'estimation_funnel' => 5,
                'production_pipeline' => 6,
                'debtors' => 7,
            ],
            'estimator' => [
                'estimation_funnel' => 1,
                'approvals' => 2,
                'finance' => 3,
                'work_orders' => 4,
                'production_pipeline' => 5,
                'project_health' => 6,
                'debtors' => 7,
            ],
            'hr_admin' => [
                'approvals' => 1,
                'project_health' => 2,
                'finance' => 3,
                'debtors' => 4,
                'work_orders' => 5,
                'estimation_funnel' => 6,
                'production_pipeline' => 7,
            ],
        ];

        return $orders[$persona] ?? $orders['general'];
    }
}

if (!function_exists('dashboard_prioritize_primary_cards')) {
    /**
     * Pick the four most relevant KPI cards for the active persona.
     *
     * @param array<int, array<string, mixed>> $cards
     * @return array<int, array<string, mixed>>
     */
    function dashboard_prioritize_primary_cards(array $cards, ?string $persona = null): array
    {
        if (count($cards) <= 4) {
            return $cards;
        }

        $persona = $persona ?? dashboard_resolve_persona();
        $priorityLabels = [
            'finance' => [
                'Total Revenue',
                'Open Invoices',
                'Active Projects',
                'Estimations',
                'Active Work Orders',
                'Tasks Due Today',
                'Dispatch Today',
            ],
            'production' => [
                'Active Work Orders',
                'Dispatch Today',
                'Tasks Due Today',
                'Active Projects',
                'Open Invoices',
                'Total Revenue',
                'Estimations',
            ],
            'project_manager' => [
                'Active Projects',
                'Tasks Due Today',
                'Active Work Orders',
                'Open Invoices',
                'Total Revenue',
                'Estimations',
                'Dispatch Today',
            ],
            'task_worker' => [
                'Tasks Due Today',
                'Active Projects',
                'Active Work Orders',
                'Estimations',
                'Open Invoices',
                'Total Revenue',
                'Dispatch Today',
            ],
            'estimator' => [
                'Estimations',
                'Open Invoices',
                'Total Revenue',
                'Active Projects',
                'Active Work Orders',
                'Tasks Due Today',
                'Dispatch Today',
            ],
            'hr_admin' => [
                'Active Projects',
                'Tasks Due Today',
                'Open Invoices',
                'Total Revenue',
                'Active Work Orders',
                'Estimations',
                'Dispatch Today',
            ],
            'general' => [
                'Total Revenue',
                'Open Invoices',
                'Active Work Orders',
                'Tasks Due Today',
                'Active Projects',
                'Dispatch Today',
                'Estimations',
            ],
        ];

        $labels = $priorityLabels[$persona] ?? $priorityLabels['general'];
        $picked = [];
        $usedIndexes = [];

        foreach ($labels as $labelPrefix) {
            foreach ($cards as $index => $card) {
                if (isset($usedIndexes[$index])) {
                    continue;
                }
                $cardLabel = (string) ($card['label'] ?? '');
                if (strpos($cardLabel, $labelPrefix) === 0) {
                    $picked[] = $card;
                    $usedIndexes[$index] = true;
                    break;
                }
            }
            if (count($picked) >= 4) {
                break;
            }
        }

        if (count($picked) < 4) {
            foreach ($cards as $index => $card) {
                if (isset($usedIndexes[$index])) {
                    continue;
                }
                $picked[] = $card;
                if (count($picked) >= 4) {
                    break;
                }
            }
        }

        return array_slice($picked, 0, 4);
    }
}

if (!function_exists('dashboard_persona_workspace_tile_ids')) {
    /**
     * Preferred workspace tile ids for the active persona.
     *
     * @return string[]
     */
    function dashboard_persona_workspace_tile_ids(?string $persona = null): array
    {
        $persona = $persona ?? dashboard_resolve_persona();

        $map = [
            'finance' => ['ws-tile-performance', 'ws-tile-reports', 'ws-tile-activity', 'ws-tile-actions'],
            'production' => ['ws-tile-activity', 'ws-tile-actions', 'ws-tile-tasks', 'ws-tile-reminders'],
            'project_manager' => ['ws-tile-projects', 'ws-tile-tasks', 'ws-tile-activity', 'ws-tile-actions'],
            'task_worker' => ['ws-tile-tasks', 'ws-tile-reminders', 'ws-tile-activity', 'ws-tile-actions'],
            'estimator' => ['ws-tile-reports', 'ws-tile-activity', 'ws-tile-performance', 'ws-tile-actions'],
            'hr_admin' => ['ws-tile-activity', 'ws-tile-projects', 'ws-tile-tasks', 'ws-tile-actions'],
            'general' => ['ws-tile-performance', 'ws-tile-activity', 'ws-tile-reports', 'ws-tile-actions'],
        ];

        return $map[$persona] ?? $map['general'];
    }
}

if (!function_exists('dashboard_filter_workspace_tiles')) {
    /**
     * @param array<int, array<string, mixed>> $tiles
     * @return array<int, array<string, mixed>>
     */
    function dashboard_filter_workspace_tiles(array $tiles, ?string $persona = null, int $limit = 4): array
    {
        $preferredIds = dashboard_persona_workspace_tile_ids($persona);
        $byId = [];
        foreach ($tiles as $tile) {
            $byId[(string) ($tile['id'] ?? '')] = $tile;
        }

        $filtered = [];
        foreach ($preferredIds as $id) {
            if (isset($byId[$id])) {
                $filtered[] = $byId[$id];
            }
        }

        if (count($filtered) < $limit) {
            foreach ($tiles as $tile) {
                $id = (string) ($tile['id'] ?? '');
                if ($id === '' || in_array($id, $preferredIds, true)) {
                    continue;
                }
                $filtered[] = $tile;
                if (count($filtered) >= $limit) {
                    break;
                }
            }
        }

        return array_slice($filtered, 0, $limit);
    }
}

if (!function_exists('dashboard_kpi_growth_meta')) {
    function dashboard_kpi_growth_meta(string $growth): array
    {
        $growth = trim($growth);
        if ($growth === '' || $growth === '0%' || $growth === '0.0%') {
            return ['direction' => 'flat', 'icon' => 'minus', 'class' => 'is-flat'];
        }

        if (strpos($growth, '-') === 0) {
            return ['direction' => 'down', 'icon' => 'trending-down', 'class' => 'is-down'];
        }

        return ['direction' => 'up', 'icon' => 'trending-up', 'class' => 'is-up'];
    }
}

if (!function_exists('dashboard_permitted_module_tiles')) {
    /**
     * Navigable module tiles for sparse-permission dashboard onboarding.
     *
     * @return array<int, array<string, string>>
     */
    function dashboard_permitted_module_tiles(): array
    {
        $tiles = [];

        if (hasPermission('view_estimations') || hasPermission('manage_estimations')) {
            $tiles[] = [
                'label' => 'Estimations',
                'description' => 'Create quotes and track customer approvals.',
                'icon' => 'file-text',
                'href' => BASE_URL . 'modules/estimations/list',
                'tone' => 'success',
            ];
        }
        if (hasPermission('view_invoices') || hasPermission('view_dashboard_revenue')) {
            $tiles[] = [
                'label' => 'Invoices & Sales',
                'description' => 'Review billing, collections, and receivables.',
                'icon' => 'receipt',
                'href' => BASE_URL . 'modules/invoices/list',
                'tone' => 'primary',
            ];
        }
        if (permissions_can_view_work_orders()) {
            $tiles[] = [
                'label' => 'Work Orders',
                'description' => 'Monitor production jobs from intake to dispatch.',
                'icon' => 'briefcase',
                'href' => BASE_URL . 'modules/work_orders/list',
                'tone' => 'warning',
            ];
        }
        if (hasPermission('view_projects')) {
            $tiles[] = [
                'label' => 'Projects',
                'description' => 'Track project delivery, tasks, and approvals.',
                'icon' => 'folder-open',
                'href' => BASE_URL . 'modules/projects/list',
                'tone' => 'neutral',
            ];
        }
        if (hasPermission('view_tasks')) {
            $tiles[] = [
                'label' => 'Tasks',
                'description' => 'See assigned work and due dates.',
                'icon' => 'circle-check',
                'href' => BASE_URL . 'modules/tasks/list?my_tasks=1',
                'tone' => 'accent',
            ];
        }
        if (hasPermission('view_materials')) {
            $tiles[] = [
                'label' => 'Materials',
                'description' => 'Review costing rates and material catalogues.',
                'icon' => 'layers',
                'href' => BASE_URL . 'modules/materials/list',
                'tone' => 'neutral',
            ];
        }
        if (hasPermission('view_dispatch')) {
            $tiles[] = [
                'label' => 'Dispatch',
                'description' => 'Track outbound deliveries and dispatch register.',
                'icon' => 'truck',
                'href' => BASE_URL . 'modules/dispatch/list',
                'tone' => 'neutral',
            ];
        }
        if (hasPermission('view_users')) {
            $tiles[] = [
                'label' => 'Team',
                'description' => 'Manage users, roles, and invitations.',
                'icon' => 'users',
                'href' => BASE_URL . 'modules/hr/users/list',
                'tone' => 'neutral',
            ];
        }

        return $tiles;
    }
}
