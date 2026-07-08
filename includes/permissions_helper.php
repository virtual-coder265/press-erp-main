<?php

if (!function_exists('permissions_catalog')) {
    /**
     * Canonical permission definitions grouped by module.
     * Each entry: [module, name, slug, description]
     */
    function permissions_catalog(): array
    {
        return [
            ['Dashboard', 'View dashboard revenue', 'view_dashboard_revenue', 'Access revenue, collections, and financial dashboard widgets.'],

            ['Estimations', 'View estimations', 'view_estimations', 'Browse and open estimation records.'],
            ['Estimations', 'Manage estimations', 'manage_estimations', 'Create, edit, and delete estimations.'],

            ['Invoices', 'View invoices', 'view_invoices', 'Browse invoices, balances, and payment history.'],
            ['Invoices', 'Manage invoices', 'manage_invoices', 'Create, edit, and delete invoices and record payments.'],

            ['Sales', 'View sales', 'view_sales', 'Access the sales overview and revenue summaries.'],
            ['Sales', 'Manage sales', 'manage_sales', 'Record direct sales and process sale transactions.'],

            ['Dispatch', 'View dispatch register', 'view_dispatch', 'Browse dispatch register entries and exports.'],
            ['Dispatch', 'Manage dispatch register', 'manage_dispatch', 'Create, edit, import, and delete dispatch entries.'],

            ['Materials', 'View materials', 'view_materials', 'Browse material inventory and categories.'],
            ['Materials', 'Manage materials', 'manage_materials', 'Create and edit materials, rates, and categories.'],

            ['Products', 'View products', 'view_products', 'Browse product catalog and categories.'],
            ['Products', 'Manage products', 'manage_products', 'Create and edit products and categories.'],

            ['Services', 'View services', 'view_services', 'Browse service catalog and categories.'],
            ['Services', 'Manage services', 'manage_services', 'Create and edit services and categories.'],

            ['Projects', 'View projects', 'view_projects', 'Browse projects and project activity.'],
            ['Projects', 'Manage projects', 'manage_projects', 'Create and manage projects and team access.'],

            ['Tasks', 'View tasks', 'view_tasks', 'Browse and open assigned tasks.'],
            ['Tasks', 'Manage tasks', 'manage_tasks', 'Create, assign, and manage tasks.'],

            ['Files', 'View files', 'view_files', 'Browse the shared file library.'],
            ['Files', 'Manage files', 'manage_files', 'Upload, organize, and delete shared files.'],

            ['Work Orders', 'View work orders', 'view_work_orders', 'View work-order records, timelines, and production summaries.'],
            ['Work Orders', 'Manage work orders', 'manage_work_orders', 'Create work orders and manage lifecycle changes.'],
            ['Work Orders', 'Manage production queues', 'manage_production_queues', 'Receive, start, hold, complete, and dispatch queue items.'],
            ['Work Orders', 'View work-order reports', 'view_work_order_reports', 'Access production dashboards, KPIs, and work-order reports.'],

            ['HR', 'View users', 'view_users', 'Browse user accounts.'],
            ['HR', 'Manage users', 'manage_users', 'Create and edit user accounts.'],
            ['HR', 'View roles', 'view_roles', 'Browse roles and permission assignments.'],
            ['HR', 'Manage roles', 'manage_roles', 'Create roles and assign permissions.'],
            ['HR', 'View departments', 'view_departments', 'Browse departments.'],
            ['HR', 'Manage departments', 'manage_departments', 'Create and edit departments.'],
            ['HR', 'View branches', 'view_branches', 'Browse branches.'],
            ['HR', 'Manage branches', 'manage_branches', 'Create and edit branches.'],

            ['Settings', 'Manage settings', 'manage_settings', 'Access system, business, and operational settings.'],

            ['Audit', 'View audit logs', 'view_audit_logs', 'Browse audit and security event logs.'],
            ['Audit', 'View system health', 'view_system_health', 'View queue health and system diagnostics.'],
            ['Audit', 'Manage security controls', 'manage_security_controls', 'Block IPs and run security maintenance actions.'],
        ];
    }
}

if (!function_exists('permissions_legacy_role_map')) {
    function permissions_legacy_role_map(): array
    {
        return [
            'Costing' => [
                'view_estimations', 'manage_estimations',
                'view_invoices', 'manage_invoices',
                'view_sales', 'manage_sales',
                'view_dashboard_revenue',
                'view_products', 'view_services',
                'view_work_orders', 'manage_work_orders',
                'view_dispatch',
            ],
            'Procurement' => [
                'view_materials', 'manage_materials',
                'view_products', 'manage_products',
                'view_services', 'manage_services',
            ],
        ];
    }
}

if (!function_exists('permissions_table_exists')) {
    function permissions_table_exists(PDO $pdo, string $tableName): bool
    {
        if (function_exists('installer_table_exists')) {
            return installer_table_exists($pdo, $tableName);
        }

        $stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
        ");
        $stmt->execute([$tableName]);

        return ((int) $stmt->fetchColumn()) > 0;
    }
}

if (!function_exists('permissions_ensure_catalog')) {
    function permissions_ensure_catalog(PDO $pdo): array
    {
        if (!permissions_table_exists($pdo, 'permissions')) {
            return [];
        }

        $changes = [];
        $selectStmt = $pdo->prepare('SELECT id FROM permissions WHERE slug = ? LIMIT 1');
        $insertStmt = $pdo->prepare('
            INSERT INTO permissions (`module`, `name`, `slug`, `description`)
            VALUES (?, ?, ?, ?)
        ');

        foreach (permissions_catalog() as $permission) {
            [$module, $name, $slug, $description] = $permission;
            $selectStmt->execute([$slug]);
            if ($selectStmt->fetchColumn()) {
                continue;
            }

            $insertStmt->execute([$module, $name, $slug, $description]);
            $changes[] = 'permissions.' . $slug;
        }

        $changes = array_merge($changes, permissions_migrate_legacy_role_grants($pdo));

        return $changes;
    }
}

if (!function_exists('permissions_migrate_legacy_role_grants')) {
    function permissions_migrate_legacy_role_grants(PDO $pdo): array
    {
        if (!permissions_table_exists($pdo, 'roles') || !permissions_table_exists($pdo, 'role_permissions')) {
            return [];
        }

        $changes = [];
        $roleStmt = $pdo->prepare('SELECT id, name FROM roles WHERE name = ? LIMIT 1');
        $permIdStmt = $pdo->prepare('SELECT id FROM permissions WHERE slug = ? LIMIT 1');
        $assignedStmt = $pdo->prepare('
            SELECT COUNT(*)
            FROM role_permissions
            WHERE role_id = ? AND permission_id = ?
        ');
        $insertStmt = $pdo->prepare('
            INSERT INTO role_permissions (role_id, permission_id)
            VALUES (?, ?)
        ');

        foreach (permissions_legacy_role_map() as $roleName => $slugs) {
            $roleStmt->execute([$roleName]);
            $roleId = (int) $roleStmt->fetchColumn();
            if ($roleId <= 0) {
                continue;
            }

            foreach ($slugs as $slug) {
                $permIdStmt->execute([$slug]);
                $permissionId = (int) $permIdStmt->fetchColumn();
                if ($permissionId <= 0) {
                    continue;
                }

                $assignedStmt->execute([$roleId, $permissionId]);
                if ((int) $assignedStmt->fetchColumn() > 0) {
                    continue;
                }

                $insertStmt->execute([$roleId, $permissionId]);
                $changes[] = 'role_permissions.' . $roleName . '.' . $slug;
            }
        }

        return $changes;
    }
}

if (!function_exists('permissions_require_one_of')) {
    function permissions_require_one_of(array $slugs): void
    {
        foreach ($slugs as $slug) {
            if (hasPermission((string) $slug)) {
                return;
            }
        }

        checkPermission((string) ($slugs[0] ?? 'view_dashboard_revenue'));
    }
}

if (!function_exists('permissions_can_view_work_orders')) {
    function permissions_can_view_work_orders(): bool
    {
        return hasPermission('view_work_orders')
            || hasPermission('manage_work_orders')
            || hasPermission('manage_production_queues')
            || hasPermission('view_work_order_reports');
    }
}

if (!function_exists('permissions_can_view_commercial')) {
    function permissions_can_view_commercial(): bool
    {
        return hasPermission('view_estimations')
            || hasPermission('view_invoices')
            || hasPermission('view_dashboard_revenue')
            || hasPermission('view_sales');
    }
}

if (!function_exists('permissions_can_view_operations')) {
    function permissions_can_view_operations(): bool
    {
        return hasPermission('view_materials')
            || hasPermission('view_products')
            || hasPermission('view_services')
            || hasPermission('view_projects')
            || hasPermission('view_tasks')
            || hasPermission('view_dispatch')
            || permissions_can_view_work_orders()
            || hasPermission('view_files');
    }
}
