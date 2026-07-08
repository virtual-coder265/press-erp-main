<?php

if (!function_exists('work_order_table_exists')) {
    function work_order_table_exists(PDO $pdo, string $tableName): bool
    {
        static $cache = [];
        $key = strtolower($tableName);
        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }

        $stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
        ");
        $stmt->execute([$tableName]);

        $cache[$key] = ((int) $stmt->fetchColumn()) > 0;
        return $cache[$key];
    }
}

if (!function_exists('work_order_column_exists')) {
    function work_order_column_exists(PDO $pdo, string $tableName, string $columnName): bool
    {
        $stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
              AND COLUMN_NAME = ?
        ");
        $stmt->execute([$tableName, $columnName]);

        return ((int) $stmt->fetchColumn()) > 0;
    }
}

if (!function_exists('work_order_index_exists')) {
    function work_order_index_exists(PDO $pdo, string $tableName, string $indexName): bool
    {
        $stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
              AND INDEX_NAME = ?
        ");
        $stmt->execute([$tableName, $indexName]);

        return ((int) $stmt->fetchColumn()) > 0;
    }
}

if (!function_exists('work_order_fetch_all')) {
    function work_order_fetch_all(PDOStatement $stmt): array
    {
        $rows = $stmt->fetchAll();
        return is_array($rows) ? $rows : [];
    }
}

if (!function_exists('work_order_safe_fetch')) {
    function work_order_safe_fetch(PDO $pdo, string $sql, array $params = []): array
    {
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            return work_order_fetch_all($stmt);
        } catch (Throwable $exception) {
            return [];
        }
    }
}

if (!function_exists('work_order_default_departments')) {
    function work_order_default_departments(): array
    {
        return [
            ['slug' => 'origination', 'name' => 'Origination', 'queue_label' => 'Origination Queue', 'default_order' => 1, 'is_dispatch' => 0, 'workflow_mode' => 'routing'],
            ['slug' => 'photosetters', 'name' => 'Photosetters', 'queue_label' => 'Photosetters Queue', 'default_order' => 2, 'is_dispatch' => 0, 'workflow_mode' => 'production'],
            ['slug' => 'machine', 'name' => 'Machine', 'queue_label' => 'Machine Queue', 'default_order' => 3, 'is_dispatch' => 0, 'workflow_mode' => 'production'],
            ['slug' => 'new-site', 'name' => 'New Site', 'queue_label' => 'New Site Queue', 'default_order' => 4, 'is_dispatch' => 0, 'workflow_mode' => 'production'],
            ['slug' => 'finishing', 'name' => 'Finishing', 'queue_label' => 'Finishing Queue', 'default_order' => 5, 'is_dispatch' => 0, 'workflow_mode' => 'production'],
            ['slug' => 'binding', 'name' => 'Binding', 'queue_label' => 'Binding Queue', 'default_order' => 6, 'is_dispatch' => 0, 'workflow_mode' => 'production'],
            ['slug' => 'dispatch-office', 'name' => 'Dispatch Office', 'queue_label' => 'Dispatch Queue', 'default_order' => 7, 'is_dispatch' => 1, 'workflow_mode' => 'dispatch'],
        ];
    }
}

if (!function_exists('work_order_department_workflow_mode')) {
    function work_order_department_workflow_mode(string $slug, ?array $departmentRow = null): string
    {
        if ($departmentRow !== null && !empty($departmentRow['workflow_mode'])) {
            return (string) $departmentRow['workflow_mode'];
        }

        foreach (work_order_default_departments() as $department) {
            if ($department['slug'] === $slug) {
                return (string) ($department['workflow_mode'] ?? 'production');
            }
        }

        return 'production';
    }
}

if (!function_exists('work_order_ensure_permissions')) {
    function work_order_ensure_permissions(PDO $pdo): array
    {
        if (!work_order_table_exists($pdo, 'permissions')) {
            return [];
        }

        $changes = [];
        $permissions = [
            ['Work Orders', 'View work orders', 'view_work_orders', 'View work-order records, timelines, and production summaries.'],
            ['Work Orders', 'Manage work orders', 'manage_work_orders', 'Create work orders, edit production details, and manage lifecycle changes.'],
            ['Work Orders', 'Manage production queues', 'manage_production_queues', 'Receive, start, hold, complete, and dispatch department queue items.'],
            ['Work Orders', 'View work-order reports', 'view_work_order_reports', 'Access production dashboards, KPIs, and work-order reports.'],
        ];

        $selectStmt = $pdo->prepare("SELECT id FROM permissions WHERE slug = ? LIMIT 1");
        $insertStmt = $pdo->prepare("
            INSERT INTO permissions (`module`, `name`, `slug`, `description`)
            VALUES (?, ?, ?, ?)
        ");

        foreach ($permissions as $permission) {
            [$module, $name, $slug, $description] = $permission;
            $selectStmt->execute([$slug]);
            if ($selectStmt->fetchColumn()) {
                continue;
            }

            $insertStmt->execute([$module, $name, $slug, $description]);
            $changes[] = 'permissions.' . $slug;
        }

        return $changes;
    }
}

if (!function_exists('work_order_ensure_schema')) {
    function work_order_ensure_schema(PDO $pdo): array
    {
        $changes = [];

        if (!work_order_table_exists($pdo, 'production_departments')) {
            $pdo->exec("
                CREATE TABLE `production_departments` (
                    `id` INT PRIMARY KEY AUTO_INCREMENT,
                    `slug` VARCHAR(80) NOT NULL,
                    `name` VARCHAR(120) NOT NULL,
                    `queue_label` VARCHAR(150) DEFAULT NULL,
                    `default_order` INT NOT NULL DEFAULT 0,
                    `is_dispatch` TINYINT(1) NOT NULL DEFAULT 0,
                    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
                    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    UNIQUE KEY `uq_production_departments_slug` (`slug`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
            ");
            $changes[] = 'production_departments.create';
        }

        if (!work_order_table_exists($pdo, 'production_department_users')) {
            $pdo->exec("
                CREATE TABLE `production_department_users` (
                    `id` INT PRIMARY KEY AUTO_INCREMENT,
                    `department_id` INT NOT NULL,
                    `user_id` INT NOT NULL,
                    `is_primary` TINYINT(1) NOT NULL DEFAULT 0,
                    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY `uq_production_department_users` (`department_id`, `user_id`),
                    KEY `idx_production_department_users_user` (`user_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
            ");
            $changes[] = 'production_department_users.create';
        }

        if (!work_order_table_exists($pdo, 'work_orders')) {
            $pdo->exec("
                CREATE TABLE `work_orders` (
                    `id` INT PRIMARY KEY AUTO_INCREMENT,
                    `work_order_number` VARCHAR(60) NOT NULL,
                    `invoice_id` INT NOT NULL,
                    `estimation_id` INT DEFAULT NULL,
                    `customer_name` VARCHAR(255) DEFAULT NULL,
                    `customer_email` VARCHAR(255) DEFAULT NULL,
                    `customer_phone` VARCHAR(50) DEFAULT NULL,
                    `ministry_department` VARCHAR(255) DEFAULT NULL,
                    `job_description` TEXT DEFAULT NULL,
                    `priority` ENUM('Normal','Urgent','Critical') NOT NULL DEFAULT 'Normal',
                    `payment_status` ENUM('Unpaid','Partially Paid','Paid') NOT NULL DEFAULT 'Unpaid',
                    `status` ENUM('Draft','Waiting Payment','Ready for Production','In Production','Awaiting Dispatch','Dispatched','Completed','Cancelled') NOT NULL DEFAULT 'Draft',
                    `current_department_id` INT DEFAULT NULL,
                    `due_date` DATE DEFAULT NULL,
                    `accepted_at` DATETIME DEFAULT NULL,
                    `accepted_by` INT DEFAULT NULL,
                    `production_started_at` DATETIME DEFAULT NULL,
                    `production_completed_at` DATETIME DEFAULT NULL,
                    `dispatch_ready_at` DATETIME DEFAULT NULL,
                    `dispatched_at` DATETIME DEFAULT NULL,
                    `completed_at` DATETIME DEFAULT NULL,
                    `cancelled_at` DATETIME DEFAULT NULL,
                    `remarks` TEXT DEFAULT NULL,
                    `created_by` INT DEFAULT NULL,
                    `updated_by` INT DEFAULT NULL,
                    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    UNIQUE KEY `uq_work_orders_number` (`work_order_number`),
                    UNIQUE KEY `uq_work_orders_invoice` (`invoice_id`),
                    KEY `idx_work_orders_status` (`status`),
                    KEY `idx_work_orders_current_department` (`current_department_id`),
                    KEY `idx_work_orders_due_date` (`due_date`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
            ");
            $changes[] = 'work_orders.create';
        }

        if (!work_order_table_exists($pdo, 'work_order_specifications')) {
            $pdo->exec("
                CREATE TABLE `work_order_specifications` (
                    `id` INT PRIMARY KEY AUTO_INCREMENT,
                    `work_order_id` INT NOT NULL,
                    `estimation_snapshot_json` LONGTEXT DEFAULT NULL,
                    `invoice_snapshot_json` LONGTEXT DEFAULT NULL,
                    `items_json` LONGTEXT DEFAULT NULL,
                    `papers_json` LONGTEXT DEFAULT NULL,
                    `ink_json` LONGTEXT DEFAULT NULL,
                    `binding_json` LONGTEXT DEFAULT NULL,
                    `prepress_json` LONGTEXT DEFAULT NULL,
                    `press_json` LONGTEXT DEFAULT NULL,
                    `finishing_json` LONGTEXT DEFAULT NULL,
                    `specification_summary` LONGTEXT DEFAULT NULL,
                    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY `uq_work_order_specifications_work_order` (`work_order_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
            ");
            $changes[] = 'work_order_specifications.create';
        }

        if (!work_order_table_exists($pdo, 'work_order_binding_types')) {
            $pdo->exec("
                CREATE TABLE `work_order_binding_types` (
                    `id` INT PRIMARY KEY AUTO_INCREMENT,
                    `name` VARCHAR(150) NOT NULL,
                    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
                    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY `uq_work_order_binding_types_name` (`name`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
            ");
            $changes[] = 'work_order_binding_types.create';
        }

        if (work_order_table_exists($pdo, 'work_orders')) {
            $workOrderColumns = [
                'numbering_start' => "ALTER TABLE `work_orders` ADD COLUMN `numbering_start` VARCHAR(100) DEFAULT NULL AFTER `job_description`",
                'binding_type_id' => "ALTER TABLE `work_orders` ADD COLUMN `binding_type_id` INT DEFAULT NULL AFTER `numbering_start`",
                'binding_type_name' => "ALTER TABLE `work_orders` ADD COLUMN `binding_type_name` VARCHAR(150) DEFAULT NULL AFTER `binding_type_id`",
                'previous_work_order_number' => "ALTER TABLE `work_orders` ADD COLUMN `previous_work_order_number` VARCHAR(60) DEFAULT NULL AFTER `binding_type_name`",
                'quantity' => "ALTER TABLE `work_orders` ADD COLUMN `quantity` INT DEFAULT NULL AFTER `previous_work_order_number`",
                'pages_count' => "ALTER TABLE `work_orders` ADD COLUMN `pages_count` INT DEFAULT NULL AFTER `quantity`",
                'size_deep' => "ALTER TABLE `work_orders` ADD COLUMN `size_deep` VARCHAR(20) DEFAULT NULL AFTER `pages_count`",
                'size_wide' => "ALTER TABLE `work_orders` ADD COLUMN `size_wide` VARCHAR(20) DEFAULT NULL AFTER `size_deep`",
                'order_ref_lpo' => "ALTER TABLE `work_orders` ADD COLUMN `order_ref_lpo` VARCHAR(100) DEFAULT NULL AFTER `size_wide`",
                'charge_vote' => "ALTER TABLE `work_orders` ADD COLUMN `charge_vote` VARCHAR(100) DEFAULT NULL AFTER `order_ref_lpo`",
                'delivery_instructions' => "ALTER TABLE `work_orders` ADD COLUMN `delivery_instructions` TEXT DEFAULT NULL AFTER `charge_vote`",
                'special_instructions' => "ALTER TABLE `work_orders` ADD COLUMN `special_instructions` TEXT DEFAULT NULL AFTER `delivery_instructions`",
                'forme_dressing_json' => "ALTER TABLE `work_orders` ADD COLUMN `forme_dressing_json` TEXT DEFAULT NULL AFTER `special_instructions`",
                'trim_margins_json' => "ALTER TABLE `work_orders` ADD COLUMN `trim_margins_json` TEXT DEFAULT NULL AFTER `forme_dressing_json`",
                'costed_by' => "ALTER TABLE `work_orders` ADD COLUMN `costed_by` INT DEFAULT NULL AFTER `accepted_by`",
                'issued_by' => "ALTER TABLE `work_orders` ADD COLUMN `issued_by` INT DEFAULT NULL AFTER `costed_by`",
                'total_cost_snapshot' => "ALTER TABLE `work_orders` ADD COLUMN `total_cost_snapshot` DECIMAL(12,2) DEFAULT NULL AFTER `issued_by`",
                'amount_paid_snapshot' => "ALTER TABLE `work_orders` ADD COLUMN `amount_paid_snapshot` DECIMAL(12,2) DEFAULT NULL AFTER `total_cost_snapshot`",
                'balance_snapshot' => "ALTER TABLE `work_orders` ADD COLUMN `balance_snapshot` DECIMAL(12,2) DEFAULT NULL AFTER `amount_paid_snapshot`",
                'sent_to_origination_at' => "ALTER TABLE `work_orders` ADD COLUMN `sent_to_origination_at` DATETIME DEFAULT NULL AFTER `balance_snapshot`",
                'sent_to_origination_by' => "ALTER TABLE `work_orders` ADD COLUMN `sent_to_origination_by` INT DEFAULT NULL AFTER `sent_to_origination_at`",
            ];
            foreach ($workOrderColumns as $columnName => $sql) {
                if (work_order_column_exists($pdo, 'work_orders', $columnName)) {
                    continue;
                }
                $pdo->exec($sql);
                $changes[] = 'work_orders.' . $columnName;
            }
        }

        if (work_order_table_exists($pdo, 'production_departments')
            && !work_order_column_exists($pdo, 'production_departments', 'workflow_mode')) {
            $pdo->exec("ALTER TABLE `production_departments` ADD COLUMN `workflow_mode` ENUM('routing','production','dispatch') NOT NULL DEFAULT 'production' AFTER `is_dispatch`");
            $changes[] = 'production_departments.workflow_mode';
        }

        if (work_order_table_exists($pdo, 'production_progress')) {
            $progressColumns = [
                'received_quantity' => "ALTER TABLE `production_progress` ADD COLUMN `received_quantity` INT DEFAULT NULL AFTER `received_at`",
                'received_by_user_id' => "ALTER TABLE `production_progress` ADD COLUMN `received_by_user_id` INT DEFAULT NULL AFTER `received_quantity`",
                'receive_notes' => "ALTER TABLE `production_progress` ADD COLUMN `receive_notes` TEXT DEFAULT NULL AFTER `received_by_user_id`",
                'designated_next_department_id' => "ALTER TABLE `production_progress` ADD COLUMN `designated_next_department_id` INT DEFAULT NULL AFTER `remarks`",
                'handoff_sample' => "ALTER TABLE `production_progress` ADD COLUMN `handoff_sample` VARCHAR(255) DEFAULT NULL AFTER `designated_next_department_id`",
                'handoff_delivered_by' => "ALTER TABLE `production_progress` ADD COLUMN `handoff_delivered_by` VARCHAR(150) DEFAULT NULL AFTER `handoff_sample`",
                'handoff_remarks' => "ALTER TABLE `production_progress` ADD COLUMN `handoff_remarks` TEXT DEFAULT NULL AFTER `handoff_delivered_by`",
            ];
            foreach ($progressColumns as $columnName => $sql) {
                if (work_order_column_exists($pdo, 'production_progress', $columnName)) {
                    continue;
                }
                $pdo->exec($sql);
                $changes[] = 'production_progress.' . $columnName;
            }
        }

        if (work_order_table_exists($pdo, 'work_order_specifications')
            && !work_order_column_exists($pdo, 'work_order_specifications', 'production_form_json')) {
            $pdo->exec("ALTER TABLE `work_order_specifications` ADD COLUMN `production_form_json` LONGTEXT DEFAULT NULL AFTER `specification_summary`");
            $changes[] = 'work_order_specifications.production_form_json';
        }

        if (!work_order_table_exists($pdo, 'production_routes')) {
            $pdo->exec("
                CREATE TABLE `production_routes` (
                    `id` INT PRIMARY KEY AUTO_INCREMENT,
                    `work_order_id` INT NOT NULL,
                    `department_id` INT NOT NULL,
                    `step_name` VARCHAR(150) NOT NULL,
                    `sequence_no` INT NOT NULL,
                    `is_required` TINYINT(1) NOT NULL DEFAULT 1,
                    `route_status` ENUM('Pending','Active','Completed','Skipped') NOT NULL DEFAULT 'Pending',
                    `sla_hours` INT DEFAULT NULL,
                    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    KEY `idx_production_routes_work_order` (`work_order_id`),
                    KEY `idx_production_routes_department` (`department_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
            ");
            $changes[] = 'production_routes.create';
        }

        if (!work_order_table_exists($pdo, 'production_progress')) {
            $pdo->exec("
                CREATE TABLE `production_progress` (
                    `id` INT PRIMARY KEY AUTO_INCREMENT,
                    `work_order_id` INT NOT NULL,
                    `route_id` INT NOT NULL,
                    `department_id` INT NOT NULL,
                    `status` ENUM('Pending','Received','In Progress','Completed','Dispatched','Returned','On Hold') NOT NULL DEFAULT 'Pending',
                    `received_at` DATETIME DEFAULT NULL,
                    `started_at` DATETIME DEFAULT NULL,
                    `completed_at` DATETIME DEFAULT NULL,
                    `dispatched_at` DATETIME DEFAULT NULL,
                    `returned_at` DATETIME DEFAULT NULL,
                    `on_hold_at` DATETIME DEFAULT NULL,
                    `assigned_user_id` INT DEFAULT NULL,
                    `updated_by` INT DEFAULT NULL,
                    `remarks` TEXT DEFAULT NULL,
                    `hold_reason` TEXT DEFAULT NULL,
                    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    UNIQUE KEY `uq_production_progress_route` (`route_id`),
                    KEY `idx_production_progress_department_status` (`department_id`, `status`),
                    KEY `idx_production_progress_work_order` (`work_order_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
            ");
            $changes[] = 'production_progress.create';
        }

        if (!work_order_table_exists($pdo, 'production_movements')) {
            $pdo->exec("
                CREATE TABLE `production_movements` (
                    `id` INT PRIMARY KEY AUTO_INCREMENT,
                    `work_order_id` INT NOT NULL,
                    `from_department_id` INT DEFAULT NULL,
                    `to_department_id` INT DEFAULT NULL,
                    `sender_user_id` INT DEFAULT NULL,
                    `receiver_user_id` INT DEFAULT NULL,
                    `movement_type` VARCHAR(50) NOT NULL,
                    `remarks` TEXT DEFAULT NULL,
                    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    KEY `idx_production_movements_work_order` (`work_order_id`),
                    KEY `idx_production_movements_created_at` (`created_at`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
            ");
            $changes[] = 'production_movements.create';
        }

        if (work_order_table_exists($pdo, 'invoices')) {
            $invoiceColumns = [
                'customer_accepted_at' => "ALTER TABLE `invoices` ADD COLUMN `customer_accepted_at` DATETIME DEFAULT NULL AFTER `due_date`",
                'customer_accepted_by' => "ALTER TABLE `invoices` ADD COLUMN `customer_accepted_by` INT DEFAULT NULL AFTER `customer_accepted_at`",
            ];
            foreach ($invoiceColumns as $columnName => $sql) {
                if (work_order_column_exists($pdo, 'invoices', $columnName)) {
                    continue;
                }
                $pdo->exec($sql);
                $changes[] = 'invoices.' . $columnName;
            }
        }

        if (work_order_table_exists($pdo, 'dispatch_register')) {
            $dispatchColumns = [
                'work_order_id' => "ALTER TABLE `dispatch_register` ADD COLUMN `work_order_id` INT DEFAULT NULL AFTER `work_order_number`",
                'customer_name' => "ALTER TABLE `dispatch_register` ADD COLUMN `customer_name` VARCHAR(255) DEFAULT NULL AFTER `ministry_department`",
                'collected_by_name' => "ALTER TABLE `dispatch_register` ADD COLUMN `collected_by_name` VARCHAR(255) DEFAULT NULL AFTER `authorised_dispatcher_id`",
                'collected_phone' => "ALTER TABLE `dispatch_register` ADD COLUMN `collected_phone` VARCHAR(50) DEFAULT NULL AFTER `collected_by_name`",
                'collection_notes' => "ALTER TABLE `dispatch_register` ADD COLUMN `collection_notes` TEXT DEFAULT NULL AFTER `collected_phone`",
                'collected_at' => "ALTER TABLE `dispatch_register` ADD COLUMN `collected_at` DATETIME DEFAULT NULL AFTER `collection_notes`",
                'closed_by' => "ALTER TABLE `dispatch_register` ADD COLUMN `closed_by` INT DEFAULT NULL AFTER `collected_at`",
            ];
            foreach ($dispatchColumns as $columnName => $sql) {
                if (work_order_column_exists($pdo, 'dispatch_register', $columnName)) {
                    continue;
                }
                $pdo->exec($sql);
                $changes[] = 'dispatch_register.' . $columnName;
            }

            if (!work_order_index_exists($pdo, 'dispatch_register', 'idx_dispatch_work_order_id')) {
                $pdo->exec("ALTER TABLE `dispatch_register` ADD INDEX `idx_dispatch_work_order_id` (`work_order_id`)");
                $changes[] = 'dispatch_register.idx_dispatch_work_order_id';
            }
        }

        $seedStmt = $pdo->prepare("
            INSERT INTO production_departments (`slug`, `name`, `queue_label`, `default_order`, `is_dispatch`, `workflow_mode`, `is_active`)
            VALUES (:slug, :name, :queue_label, :default_order, :is_dispatch, :workflow_mode, 1)
            ON DUPLICATE KEY UPDATE
                `name` = VALUES(`name`),
                `queue_label` = VALUES(`queue_label`),
                `default_order` = VALUES(`default_order`),
                `is_dispatch` = VALUES(`is_dispatch`),
                `workflow_mode` = VALUES(`workflow_mode`),
                `is_active` = 1
        ");

        foreach (work_order_default_departments() as $department) {
            $seedStmt->execute($department);
        }

        if (work_order_table_exists($pdo, 'work_order_binding_types')) {
            $bindingSeed = $pdo->prepare("
                INSERT INTO work_order_binding_types (`name`, `is_active`)
                VALUES (?, 1)
                ON DUPLICATE KEY UPDATE `is_active` = 1
            ");
            foreach (work_order_default_binding_type_names() as $bindingName) {
                $bindingSeed->execute([$bindingName]);
            }
        }

        return array_merge($changes, work_order_ensure_permissions($pdo));
    }
}

if (!function_exists('work_order_bootstrap')) {
    function work_order_bootstrap(PDO $pdo): void
    {
        static $bootstrapped = false;
        if ($bootstrapped) {
            return;
        }
        work_order_ensure_schema($pdo);
        $bootstrapped = true;
    }
}

if (!function_exists('work_order_generate_number')) {
    function work_order_generate_number(PDO $pdo): string
    {
        $prefix = 'WO-' . date('Ymd') . '-';
        do {
            $candidate = $prefix . str_pad((string) random_int(1, 999), 3, '0', STR_PAD_LEFT);
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM work_orders WHERE work_order_number = ?");
            $stmt->execute([$candidate]);
        } while ((int) $stmt->fetchColumn() > 0);

        return $candidate;
    }
}

if (!function_exists('work_order_estimation_children')) {
    function work_order_estimation_children(PDO $pdo, int $estimationId): array
    {
        return [
            'items' => work_order_safe_fetch($pdo, "SELECT * FROM estimation_items WHERE estimation_id = ? ORDER BY id ASC", [$estimationId]),
            'papers' => work_order_safe_fetch($pdo, "SELECT * FROM estimation_papers WHERE estimation_id = ? ORDER BY sort_order ASC, id ASC", [$estimationId]),
            'ink' => work_order_safe_fetch($pdo, "SELECT * FROM estimation_ink_colours WHERE estimation_id = ? ORDER BY sort_order ASC, id ASC", [$estimationId]),
            'binding' => work_order_safe_fetch($pdo, "SELECT * FROM estimation_binding_materials WHERE estimation_id = ? ORDER BY sort_order ASC, id ASC", [$estimationId]),
            'prepress' => work_order_safe_fetch($pdo, "SELECT * FROM estimation_prepress_labour WHERE estimation_id = ? ORDER BY sort_order ASC, id ASC", [$estimationId]),
            'press' => work_order_safe_fetch($pdo, "SELECT * FROM estimation_press_labour WHERE estimation_id = ? ORDER BY sort_order ASC, id ASC", [$estimationId]),
            'finishing' => work_order_safe_fetch($pdo, "SELECT * FROM estimation_finishing_labour WHERE estimation_id = ? ORDER BY sort_order ASC, id ASC", [$estimationId]),
        ];
    }
}

if (!function_exists('work_order_route_blueprint')) {
    function work_order_route_blueprint(array $estimation, array $children): array
    {
        $jobText = strtolower((string) ($estimation['job_description'] ?? ''));
        $hasPapers = !empty($children['papers']);
        $hasInk = !empty($children['ink']);
        $hasBinding = !empty($children['binding']);
        $hasFinishing = !empty($children['finishing']);
        $hasPress = !empty($children['press']);

        $steps = [];
        foreach (work_order_default_departments() as $department) {
            $required = false;

            switch ($department['slug']) {
                case 'origination':
                    $required = true;
                    break;
                case 'photosetters':
                    $required = $hasPapers || $hasInk || str_contains($jobText, 'plate') || str_contains($jobText, 'prepress');
                    break;
                case 'machine':
                    $required = $hasPress || $hasPapers || str_contains($jobText, 'print');
                    break;
                case 'new-site':
                    $required = str_contains($jobText, 'site') || str_contains($jobText, 'signage') || str_contains($jobText, 'banner');
                    break;
                case 'finishing':
                    $required = $hasFinishing || str_contains($jobText, 'finish') || str_contains($jobText, 'lamination');
                    break;
                case 'binding':
                    $required = $hasBinding || str_contains($jobText, 'bind');
                    break;
                case 'dispatch-office':
                    $required = true;
                    break;
            }

            if (!$required && !in_array($department['slug'], ['new-site', 'binding', 'finishing', 'photosetters'], true)) {
                $required = true;
            }

            if ($required) {
                $steps[] = $department['slug'];
            }
        }

        return array_values(array_unique($steps));
    }
}

if (!function_exists('work_order_department_map')) {
    function work_order_department_map(PDO $pdo): array
    {
        $stmt = $pdo->query("SELECT * FROM production_departments WHERE is_active = 1 ORDER BY default_order ASC, id ASC");
        $rows = work_order_fetch_all($stmt);
        $map = [];
        foreach ($rows as $row) {
            $map[(string) $row['slug']] = $row;
        }
        return $map;
    }
}

if (!function_exists('work_order_build_spec_summary')) {
    function work_order_build_spec_summary(array $estimation, array $children): array
    {
        return [
            'customer' => [
                'name' => $estimation['customer_name'] ?? '',
                'email' => $estimation['customer_email'] ?? '',
                'phone' => $estimation['customer_phone'] ?? '',
            ],
            'job_description' => $estimation['job_description'] ?? '',
            'totals' => [
                'pre_vat_total' => (float) ($estimation['pre_vat_total'] ?? 0),
                'vat_percent' => (float) ($estimation['vat_percent'] ?? 0),
                'vat_amount' => (float) ($estimation['vat_amount'] ?? 0),
                'grand_total' => (float) ($estimation['total_amount'] ?? 0),
            ],
            'counts' => [
                'items' => count($children['items']),
                'papers' => count($children['papers']),
                'ink' => count($children['ink']),
                'binding' => count($children['binding']),
                'prepress' => count($children['prepress']),
                'press' => count($children['press']),
                'finishing' => count($children['finishing']),
            ],
        ];
    }
}

if (!function_exists('work_order_payment_status_from_invoice')) {
    function work_order_payment_status_from_invoice(array $invoice): string
    {
        $balance = (float) ($invoice['balance'] ?? 0);
        $paidAmount = (float) ($invoice['paid_amount'] ?? 0);
        if ($balance <= 0 && $paidAmount > 0) {
            return 'Paid';
        }
        if ($paidAmount > 0) {
            return 'Partially Paid';
        }
        return 'Unpaid';
    }
}

if (!function_exists('work_order_initial_status_from_invoice')) {
    function work_order_initial_status_from_invoice(array $invoice): string
    {
        return 'Draft';
    }
}

if (!function_exists('work_order_default_binding_type_names')) {
    function work_order_default_binding_type_names(): array
    {
        return [
            'Saddle Stitching',
            'Side Stitching',
            'Perfect Binding',
            'Spiral Wire Binding',
        ];
    }
}

if (!function_exists('work_order_fetch_binding_types')) {
    function work_order_fetch_binding_types(PDO $pdo): array
    {
        work_order_bootstrap($pdo);
        $stmt = $pdo->query("
            SELECT id, name
            FROM work_order_binding_types
            WHERE is_active = 1
            ORDER BY name ASC
        ");
        return work_order_fetch_all($stmt);
    }
}

if (!function_exists('work_order_add_binding_type')) {
    function work_order_add_binding_type(PDO $pdo, string $name): array
    {
        work_order_bootstrap($pdo);
        $name = trim($name);
        if ($name === '') {
            throw new InvalidArgumentException('Binding type name is required.');
        }

        $existing = $pdo->prepare("SELECT id, name FROM work_order_binding_types WHERE name = ? LIMIT 1");
        $existing->execute([$name]);
        $row = $existing->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            return ['id' => (int) $row['id'], 'name' => (string) $row['name']];
        }

        $insert = $pdo->prepare("INSERT INTO work_order_binding_types (`name`, `is_active`) VALUES (?, 1)");
        $insert->execute([$name]);

        return ['id' => (int) $pdo->lastInsertId(), 'name' => $name];
    }
}

if (!function_exists('work_order_derive_job_specs')) {
    function work_order_derive_job_specs(array $estimation, array $children): array
    {
        $quantity = 0;
        foreach ($children['items'] as $item) {
            $quantity = max($quantity, (int) round((float) ($item['quantity'] ?? 0)));
        }

        $pages = 0;
        foreach ($children['ink'] as $inkRow) {
            $details = json_decode((string) ($inkRow['details_json'] ?? ''), true);
            if (is_array($details)) {
                $pages = max($pages, (int) ($details['pages'] ?? 0));
                $quantity = max($quantity, (int) ($details['copies'] ?? 0));
            }
        }

        $sizeDeep = '';
        $sizeWide = '';
        if (!empty($children['papers'][0]['paper_size'])) {
            $paperSize = trim((string) $children['papers'][0]['paper_size']);
            if (preg_match('/(\d+)\s*[x×]\s*(\d+)/i', $paperSize, $matches)) {
                $sizeWide = $matches[1];
                $sizeDeep = $matches[2];
            } else {
                $sizeWide = $paperSize;
            }
        }

        return [
            'quantity' => $quantity > 0 ? $quantity : null,
            'pages_count' => $pages > 0 ? $pages : null,
            'size_deep' => $sizeDeep !== '' ? $sizeDeep : null,
            'size_wide' => $sizeWide !== '' ? $sizeWide : null,
            'job_description' => trim((string) ($estimation['job_description'] ?? '')),
        ];
    }
}

if (!function_exists('work_order_prefill_from_invoice')) {
    function work_order_prefill_from_invoice(PDO $pdo, int $invoiceId): array
    {
        work_order_bootstrap($pdo);

        $stmt = $pdo->prepare("
            SELECT i.*, e.estimation_number, e.job_description AS est_job_description
            FROM invoices i
            LEFT JOIN estimations e ON i.estimation_id = e.id
            WHERE i.id = ?
            LIMIT 1
        ");
        $stmt->execute([$invoiceId]);
        $invoice = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$invoice) {
            throw new RuntimeException('Invoice not found.');
        }

        $estimation = [];
        $children = work_order_estimation_children($pdo, 0);
        $estimationId = (int) ($invoice['estimation_id'] ?? 0);
        if ($estimationId > 0) {
            $estStmt = $pdo->prepare("SELECT * FROM estimations WHERE id = ?");
            $estStmt->execute([$estimationId]);
            $estimation = $estStmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $children = work_order_estimation_children($pdo, $estimationId);
        }

        $derived = work_order_derive_job_specs($estimation, $children);

        return [
            'invoice' => $invoice,
            'estimation' => $estimation,
            'children' => $children,
            'ministry_department' => $invoice['department'] ?? '',
            'job_description' => $derived['job_description'] ?: ($invoice['items_json'] ?? ''),
            'quantity' => $derived['quantity'],
            'pages_count' => $derived['pages_count'],
            'size_deep' => $derived['size_deep'],
            'size_wide' => $derived['size_wide'],
            'order_ref_lpo' => '',
            'total_cost' => (float) ($invoice['total_amount'] ?? 0),
            'amount_paid' => (float) ($invoice['paid_amount'] ?? 0),
            'balance' => (float) ($invoice['balance'] ?? 0),
        ];
    }
}

if (!function_exists('work_order_parse_costing_fields')) {
    function work_order_parse_costing_fields(array $input): array
    {
        $bindingTypeId = (int) ($input['binding_type_id'] ?? 0);
        $bindingTypeName = trim((string) ($input['binding_type_name'] ?? ''));
        if ($bindingTypeId <= 0 && $bindingTypeName === '') {
            throw new InvalidArgumentException('Type of binding is required.');
        }

        $formeDressing = [
            'backs' => trim((string) ($input['forme_backs'] ?? '')),
            'heads' => trim((string) ($input['forme_heads'] ?? '')),
            'gutters' => trim((string) ($input['forme_gutters'] ?? '')),
            'tails' => trim((string) ($input['forme_tails'] ?? '')),
        ];
        $trimMargins = [
            'backs' => trim((string) ($input['trim_backs'] ?? '')),
            'heads' => trim((string) ($input['trim_heads'] ?? '')),
            'fore_edge' => trim((string) ($input['trim_fore_edge'] ?? '')),
            'tails' => trim((string) ($input['trim_tails'] ?? '')),
        ];

        return [
            'numbering_start' => trim((string) ($input['numbering_start'] ?? '')) ?: null,
            'binding_type_id' => $bindingTypeId > 0 ? $bindingTypeId : null,
            'binding_type_name' => $bindingTypeName !== '' ? $bindingTypeName : null,
            'previous_work_order_number' => trim((string) ($input['previous_work_order_number'] ?? '')) ?: null,
            'quantity' => ($input['quantity'] ?? '') !== '' ? (int) $input['quantity'] : null,
            'pages_count' => ($input['pages_count'] ?? '') !== '' ? (int) $input['pages_count'] : null,
            'size_deep' => trim((string) ($input['size_deep'] ?? '')) ?: null,
            'size_wide' => trim((string) ($input['size_wide'] ?? '')) ?: null,
            'order_ref_lpo' => trim((string) ($input['order_ref_lpo'] ?? '')) ?: null,
            'charge_vote' => trim((string) ($input['charge_vote'] ?? '')) ?: null,
            'delivery_instructions' => trim((string) ($input['delivery_instructions'] ?? '')) ?: null,
            'special_instructions' => trim((string) ($input['special_instructions'] ?? '')) ?: null,
            'forme_dressing_json' => json_encode($formeDressing),
            'trim_margins_json' => json_encode($trimMargins),
            'ministry_department' => trim((string) ($input['ministry_department'] ?? '')) ?: null,
            'job_description' => trim((string) ($input['job_description'] ?? '')) ?: null,
            'priority' => in_array(($input['priority'] ?? ''), ['Normal', 'Urgent', 'Critical'], true)
                ? $input['priority']
                : 'Normal',
            'remarks' => trim((string) ($input['remarks'] ?? '')) ?: null,
        ];
    }
}

if (!function_exists('work_order_build_production_form')) {
    function work_order_build_production_form(array $input): array
    {
        $paperRows = [];
        $ledgerNos = $input['paper_ledger_no'] ?? [];
        if (is_array($ledgerNos)) {
            foreach ($ledgerNos as $index => $ledgerNo) {
                $ledgerNo = trim((string) $ledgerNo);
                $qty = trim((string) ($input['paper_qty'][$index] ?? ''));
                $cutTo = trim((string) ($input['paper_cut_to'][$index] ?? ''));
                $riv = trim((string) ($input['paper_riv_no'][$index] ?? ''));
                $date = trim((string) ($input['paper_date'][$index] ?? ''));
                if ($ledgerNo === '' && $qty === '' && $cutTo === '' && $riv === '' && $date === '') {
                    continue;
                }
                $paperRows[] = [
                    'ledger_no' => $ledgerNo,
                    'quantity' => $qty,
                    'cut_to' => $cutTo,
                    'riv_no' => $riv,
                    'date' => $date,
                    'notes' => trim((string) ($input['paper_notes'][$index] ?? '')),
                ];
            }
        }

        return [
            'composing' => [
                'compositor_name' => trim((string) ($input['compositor_name'] ?? '')),
                'date_received' => trim((string) ($input['composing_date_received'] ?? '')),
                'type' => trim((string) ($input['composing_type'] ?? '')),
                'type_area_wide_ems' => trim((string) ($input['type_area_wide_ems'] ?? '')),
                'type_area_deep_ems' => trim((string) ($input['type_area_deep_ems'] ?? '')),
                'proof_to_date' => trim((string) ($input['proof_to_date'] ?? '')),
                'special_instructions' => trim((string) ($input['composing_special_instructions'] ?? '')),
            ],
            'letterpress' => [
                'machine_minder_name' => trim((string) ($input['press_minder_name'] ?? '')),
                'date_received' => trim((string) ($input['press_date_received'] ?? '')),
                'machine_type' => trim((string) ($input['press_machine_type'] ?? '')),
                'ink_colour' => trim((string) ($input['press_ink_colour'] ?? '')),
                'overs_allowed' => trim((string) ($input['press_overs_allowed'] ?? '')),
                'plate_type' => trim((string) ($input['press_plate_type'] ?? '')),
                'camera_percent' => trim((string) ($input['press_camera_percent'] ?? '')),
                'process' => trim((string) ($input['press_process'] ?? '')),
                'size' => trim((string) ($input['press_size'] ?? '')),
                'special_instructions' => trim((string) ($input['press_special_instructions'] ?? '')),
            ],
            'bookbinding' => [
                'machine_minder_name' => trim((string) ($input['binding_minder_name'] ?? '')),
                'date_received' => trim((string) ($input['binding_date_received'] ?? '')),
                'ruling' => trim((string) ($input['binding_ruling'] ?? '')),
                'perforating' => trim((string) ($input['binding_perforating'] ?? '')),
                'trim_fore_edge' => trim((string) ($input['bind_trim_fore_edge'] ?? '')),
                'trim_back' => trim((string) ($input['bind_trim_back'] ?? '')),
                'trim_head' => trim((string) ($input['bind_trim_head'] ?? '')),
                'trim_tail' => trim((string) ($input['bind_trim_tail'] ?? '')),
                'special_instructions' => trim((string) ($input['binding_special_instructions'] ?? '')),
            ],
            'paper_materials' => $paperRows,
            'dispatch_received' => [
                'quantity' => trim((string) ($input['dispatch_received_qty'] ?? '')),
                'initials' => trim((string) ($input['dispatch_received_initials'] ?? '')),
                'date' => trim((string) ($input['dispatch_received_date'] ?? '')),
            ],
            'costing_tracking' => [
                'passed_to_costing_initials' => trim((string) ($input['passed_to_costing_initials'] ?? '')),
                'passed_to_costing_date' => trim((string) ($input['passed_to_costing_date'] ?? '')),
                'final_dispatch_initials' => trim((string) ($input['final_dispatch_initials'] ?? '')),
                'final_dispatch_date' => trim((string) ($input['final_dispatch_date'] ?? '')),
            ],
        ];
    }
}

if (!function_exists('work_order_decode_json_field')) {
    function work_order_decode_json_field(?string $json): array
    {
        if ($json === null || $json === '') {
            return [];
        }
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : [];
    }
}

if (!function_exists('work_order_update_traveler')) {
    function work_order_update_traveler(PDO $pdo, int $workOrderId, int $userId, array $input): void
    {
        work_order_bootstrap($pdo);
        $costing = work_order_parse_costing_fields($input);
        $productionForm = work_order_build_production_form($input);

        if (!empty($costing['binding_type_id'])) {
            $bindingStmt = $pdo->prepare("SELECT name FROM work_order_binding_types WHERE id = ? LIMIT 1");
            $bindingStmt->execute([$costing['binding_type_id']]);
            $bindingName = $bindingStmt->fetchColumn();
            if ($bindingName) {
                $costing['binding_type_name'] = (string) $bindingName;
            }
        }

        $pdo->prepare("
            UPDATE work_orders SET
                ministry_department = :ministry_department,
                job_description = :job_description,
                numbering_start = :numbering_start,
                binding_type_id = :binding_type_id,
                binding_type_name = :binding_type_name,
                previous_work_order_number = :previous_work_order_number,
                quantity = :quantity,
                pages_count = :pages_count,
                size_deep = :size_deep,
                size_wide = :size_wide,
                order_ref_lpo = :order_ref_lpo,
                charge_vote = :charge_vote,
                delivery_instructions = :delivery_instructions,
                special_instructions = :special_instructions,
                forme_dressing_json = :forme_dressing_json,
                trim_margins_json = :trim_margins_json,
                priority = :priority,
                remarks = :remarks,
                updated_by = :updated_by
            WHERE id = :id
        ")->execute([
            'ministry_department' => $costing['ministry_department'],
            'job_description' => $costing['job_description'],
            'numbering_start' => $costing['numbering_start'],
            'binding_type_id' => $costing['binding_type_id'],
            'binding_type_name' => $costing['binding_type_name'],
            'previous_work_order_number' => $costing['previous_work_order_number'],
            'quantity' => $costing['quantity'],
            'pages_count' => $costing['pages_count'],
            'size_deep' => $costing['size_deep'],
            'size_wide' => $costing['size_wide'],
            'order_ref_lpo' => $costing['order_ref_lpo'],
            'charge_vote' => $costing['charge_vote'],
            'delivery_instructions' => $costing['delivery_instructions'],
            'special_instructions' => $costing['special_instructions'],
            'forme_dressing_json' => $costing['forme_dressing_json'],
            'trim_margins_json' => $costing['trim_margins_json'],
            'priority' => $costing['priority'],
            'remarks' => $costing['remarks'],
            'updated_by' => $userId,
            'id' => $workOrderId,
        ]);

        $pdo->prepare("
            UPDATE work_order_specifications
            SET production_form_json = ?
            WHERE work_order_id = ?
        ")->execute([json_encode($productionForm), $workOrderId]);
    }
}

if (!function_exists('work_order_create_from_invoice')) {
    function work_order_create_from_invoice(PDO $pdo, int $invoiceId, int $userId, array $overrides = []): array
    {
        work_order_bootstrap($pdo);

        $stmt = $pdo->prepare("
            SELECT i.*, e.estimation_number, e.customer_name AS est_customer_name,
                   e.customer_email AS est_customer_email, e.customer_phone AS est_customer_phone,
                   e.job_description AS est_job_description
            FROM invoices i
            LEFT JOIN estimations e ON i.estimation_id = e.id
            WHERE i.id = ?
            LIMIT 1
        ");
        $stmt->execute([$invoiceId]);
        $invoice = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$invoice) {
            throw new RuntimeException('Invoice not found.');
        }

        $existingStmt = $pdo->prepare("SELECT id, work_order_number FROM work_orders WHERE invoice_id = ? LIMIT 1");
        $existingStmt->execute([$invoiceId]);
        $existing = $existingStmt->fetch(PDO::FETCH_ASSOC);
        if ($existing) {
            throw new RuntimeException('A work order already exists for this invoice: ' . $existing['work_order_number']);
        }

        $estimation = [];
        $children = [
            'items' => [],
            'papers' => [],
            'ink' => [],
            'binding' => [],
            'prepress' => [],
            'press' => [],
            'finishing' => [],
        ];

        $estimationId = (int) ($invoice['estimation_id'] ?? 0);
        if ($estimationId > 0) {
            $estStmt = $pdo->prepare("SELECT * FROM estimations WHERE id = ?");
            $estStmt->execute([$estimationId]);
            $estimation = $estStmt->fetch(PDO::FETCH_ASSOC) ?: [];
            if ($estimation) {
                $children = work_order_estimation_children($pdo, $estimationId);
            }
        }

        $departmentMap = work_order_department_map($pdo);

        $jobDescription = trim((string) ($overrides['job_description'] ?? $estimation['job_description'] ?? $invoice['items_json'] ?? $invoice['customer_name'] ?? ''));
        $dueDate = $overrides['due_date'] ?? ($invoice['due_date'] ?? null);
        $priority = $overrides['priority'] ?? ((float) ($invoice['balance'] ?? 0) > 0 ? 'Normal' : 'Urgent');
        if (!in_array($priority, ['Normal', 'Urgent', 'Critical'], true)) {
            $priority = 'Normal';
        }

        $costingFields = [];
        $productionFormJson = null;
        if (!empty($overrides['costing_fields']) && is_array($overrides['costing_fields'])) {
            $costingFields = $overrides['costing_fields'];
            if (!empty($costingFields['binding_type_id']) && empty($costingFields['binding_type_name'])) {
                $bindingStmt = $pdo->prepare("SELECT name FROM work_order_binding_types WHERE id = ? LIMIT 1");
                $bindingStmt->execute([(int) $costingFields['binding_type_id']]);
                $bindingName = $bindingStmt->fetchColumn();
                if ($bindingName) {
                    $costingFields['binding_type_name'] = (string) $bindingName;
                }
            }
            if (!empty($costingFields['job_description'])) {
                $jobDescription = $costingFields['job_description'];
            }
            if (!empty($costingFields['priority'])) {
                $priority = $costingFields['priority'];
            }
        }
        if (!empty($overrides['production_form']) && is_array($overrides['production_form'])) {
            $productionFormJson = json_encode($overrides['production_form']);
        }

        $paymentStatus = work_order_payment_status_from_invoice($invoice);
        $status = $overrides['status'] ?? work_order_initial_status_from_invoice($invoice);
        $acceptedAt = date('Y-m-d H:i:s');
        $workOrderNumber = work_order_generate_number($pdo);
        $totalCost = (float) ($invoice['total_amount'] ?? 0);
        $amountPaid = (float) ($invoice['paid_amount'] ?? 0);
        $balance = (float) ($invoice['balance'] ?? 0);

        $pdo->beginTransaction();
        try {
            $insertWorkOrder = $pdo->prepare("
                INSERT INTO work_orders (
                    work_order_number, invoice_id, estimation_id, customer_name, customer_email, customer_phone,
                    ministry_department, job_description, numbering_start, binding_type_id, binding_type_name,
                    previous_work_order_number, quantity, pages_count, size_deep, size_wide, order_ref_lpo,
                    charge_vote, delivery_instructions, special_instructions, forme_dressing_json, trim_margins_json,
                    priority, payment_status, status, current_department_id,
                    due_date, accepted_at, accepted_by, costed_by, issued_by,
                    total_cost_snapshot, amount_paid_snapshot, balance_snapshot,
                    remarks, created_by, updated_by
                ) VALUES (
                    :work_order_number, :invoice_id, :estimation_id, :customer_name, :customer_email, :customer_phone,
                    :ministry_department, :job_description, :numbering_start, :binding_type_id, :binding_type_name,
                    :previous_work_order_number, :quantity, :pages_count, :size_deep, :size_wide, :order_ref_lpo,
                    :charge_vote, :delivery_instructions, :special_instructions, :forme_dressing_json, :trim_margins_json,
                    :priority, :payment_status, :status, :current_department_id,
                    :due_date, :accepted_at, :accepted_by, :costed_by, :issued_by,
                    :total_cost_snapshot, :amount_paid_snapshot, :balance_snapshot,
                    :remarks, :created_by, :updated_by
                )
            ");
            $insertWorkOrder->execute([
                'work_order_number' => $workOrderNumber,
                'invoice_id' => $invoiceId,
                'estimation_id' => $estimationId ?: null,
                'customer_name' => $invoice['customer_name'] ?: ($estimation['customer_name'] ?? null),
                'customer_email' => $invoice['customer_email'] ?: ($estimation['customer_email'] ?? null),
                'customer_phone' => $invoice['customer_phone'] ?: ($estimation['customer_phone'] ?? null),
                'ministry_department' => $costingFields['ministry_department'] ?? $overrides['ministry_department'] ?? ($invoice['department'] ?? null),
                'job_description' => $jobDescription,
                'numbering_start' => $costingFields['numbering_start'] ?? null,
                'binding_type_id' => $costingFields['binding_type_id'] ?? null,
                'binding_type_name' => $costingFields['binding_type_name'] ?? null,
                'previous_work_order_number' => $costingFields['previous_work_order_number'] ?? null,
                'quantity' => $costingFields['quantity'] ?? null,
                'pages_count' => $costingFields['pages_count'] ?? null,
                'size_deep' => $costingFields['size_deep'] ?? null,
                'size_wide' => $costingFields['size_wide'] ?? null,
                'order_ref_lpo' => $costingFields['order_ref_lpo'] ?? null,
                'charge_vote' => $costingFields['charge_vote'] ?? null,
                'delivery_instructions' => $costingFields['delivery_instructions'] ?? null,
                'special_instructions' => $costingFields['special_instructions'] ?? null,
                'forme_dressing_json' => $costingFields['forme_dressing_json'] ?? null,
                'trim_margins_json' => $costingFields['trim_margins_json'] ?? null,
                'priority' => $priority,
                'payment_status' => $paymentStatus,
                'status' => $status,
                'current_department_id' => null,
                'due_date' => $dueDate ?: null,
                'accepted_at' => $acceptedAt,
                'accepted_by' => $userId,
                'costed_by' => $userId,
                'issued_by' => $userId,
                'total_cost_snapshot' => $totalCost,
                'amount_paid_snapshot' => $amountPaid,
                'balance_snapshot' => $balance,
                'remarks' => $costingFields['remarks'] ?? $overrides['remarks'] ?? null,
                'created_by' => $userId,
                'updated_by' => $userId,
            ]);
            $workOrderId = (int) $pdo->lastInsertId();

            $specStmt = $pdo->prepare("
                INSERT INTO work_order_specifications (
                    work_order_id, estimation_snapshot_json, invoice_snapshot_json,
                    items_json, papers_json, ink_json, binding_json,
                    prepress_json, press_json, finishing_json, specification_summary, production_form_json
                ) VALUES (
                    :work_order_id, :estimation_snapshot_json, :invoice_snapshot_json,
                    :items_json, :papers_json, :ink_json, :binding_json,
                    :prepress_json, :press_json, :finishing_json, :specification_summary, :production_form_json
                )
            ");
            $specStmt->execute([
                'work_order_id' => $workOrderId,
                'estimation_snapshot_json' => !empty($estimation) ? json_encode($estimation) : null,
                'invoice_snapshot_json' => json_encode($invoice),
                'items_json' => json_encode($children['items']),
                'papers_json' => json_encode($children['papers']),
                'ink_json' => json_encode($children['ink']),
                'binding_json' => json_encode($children['binding']),
                'prepress_json' => json_encode($children['prepress']),
                'press_json' => json_encode($children['press']),
                'finishing_json' => json_encode($children['finishing']),
                'specification_summary' => json_encode(work_order_build_spec_summary($estimation, $children)),
                'production_form_json' => $productionFormJson,
            ]);

            $movementStmt = $pdo->prepare("
                INSERT INTO production_movements (
                    work_order_id, from_department_id, to_department_id, sender_user_id, receiver_user_id, movement_type, remarks
                ) VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $movementStmt->execute([
                $workOrderId,
                null,
                null,
                $userId,
                null,
                'generated',
                'Work order created from costing for invoice ' . ($invoice['invoice_number'] ?? ('#' . $invoiceId)) . '. Send to Origination when ready.',
            ]);

            $invoiceUpdate = $pdo->prepare("
                UPDATE invoices
                SET customer_accepted_at = COALESCE(customer_accepted_at, :accepted_at),
                    customer_accepted_by = COALESCE(customer_accepted_by, :accepted_by)
                WHERE id = :invoice_id
            ");
            $invoiceUpdate->execute([
                'accepted_at' => $acceptedAt,
                'accepted_by' => $userId,
                'invoice_id' => $invoiceId,
            ]);

            $pdo->commit();

            return [
                'id' => $workOrderId,
                'work_order_number' => $workOrderNumber,
            ];
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }
}

if (!function_exists('work_order_fetch_route')) {
    function work_order_fetch_route(PDO $pdo, int $workOrderId): array
    {
        $stmt = $pdo->prepare("
            SELECT pr.*, pd.slug, pd.name AS department_name, pd.queue_label, pd.workflow_mode,
                   pp.id AS progress_id, pp.status, pp.received_at, pp.received_quantity,
                   pp.received_by_user_id, pp.receive_notes, pp.started_at, pp.completed_at,
                   pp.dispatched_at, pp.returned_at, pp.on_hold_at, pp.remarks, pp.hold_reason,
                   pp.assigned_user_id, pp.designated_next_department_id,
                   pp.handoff_sample, pp.handoff_delivered_by, pp.handoff_remarks,
                   u.name AS assigned_user_name, rb.name AS received_by_name,
                   nd.name AS designated_next_department_name
            FROM production_routes pr
            INNER JOIN production_departments pd ON pr.department_id = pd.id
            LEFT JOIN production_progress pp ON pp.route_id = pr.id
            LEFT JOIN users u ON pp.assigned_user_id = u.id
            LEFT JOIN users rb ON pp.received_by_user_id = rb.id
            LEFT JOIN production_departments nd ON pp.designated_next_department_id = nd.id
            WHERE pr.work_order_id = ?
            ORDER BY pr.sequence_no ASC, pr.id ASC
        ");
        $stmt->execute([$workOrderId]);
        return work_order_fetch_all($stmt);
    }
}

if (!function_exists('work_order_fetch_one')) {
    function work_order_fetch_one(PDO $pdo, int $workOrderId): ?array
    {
        work_order_bootstrap($pdo);

        $stmt = $pdo->prepare("
            SELECT wo.*, i.invoice_number, i.status AS invoice_status, i.paid_amount, i.balance, i.total_amount AS invoice_total,
                   e.estimation_number, pd.name AS current_department_name,
                   wbt.name AS binding_catalog_name,
                   uc.name AS costed_by_name, ui.name AS issued_by_name, ua.name AS accepted_by_name
            FROM work_orders wo
            INNER JOIN invoices i ON wo.invoice_id = i.id
            LEFT JOIN estimations e ON wo.estimation_id = e.id
            LEFT JOIN production_departments pd ON wo.current_department_id = pd.id
            LEFT JOIN work_order_binding_types wbt ON wo.binding_type_id = wbt.id
            LEFT JOIN users uc ON wo.costed_by = uc.id
            LEFT JOIN users ui ON wo.issued_by = ui.id
            LEFT JOIN users ua ON wo.accepted_by = ua.id
            WHERE wo.id = ?
            LIMIT 1
        ");
        $stmt->execute([$workOrderId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}

if (!function_exists('work_order_sync_payment_status')) {
    function work_order_sync_payment_status(PDO $pdo, int $workOrderId): void
    {
        $stmt = $pdo->prepare("
            SELECT wo.id, i.paid_amount, i.balance
            FROM work_orders wo
            INNER JOIN invoices i ON wo.invoice_id = i.id
            WHERE wo.id = ?
            LIMIT 1
        ");
        $stmt->execute([$workOrderId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return;
        }

        $paymentStatus = 'Unpaid';
        if ((float) $row['balance'] <= 0 && (float) $row['paid_amount'] > 0) {
            $paymentStatus = 'Paid';
        } elseif ((float) $row['paid_amount'] > 0) {
            $paymentStatus = 'Partially Paid';
        }

        $pdo->prepare("UPDATE work_orders SET payment_status = ? WHERE id = ?")->execute([$paymentStatus, $workOrderId]);
    }
}

if (!function_exists('work_order_status_badge_class')) {
    function work_order_status_badge_class(string $status): string
    {
        $map = [
            'Draft' => 'bg-slate-100 text-slate-800',
            'Waiting Payment' => 'bg-amber-100 text-amber-800',
            'Ready for Production' => 'bg-blue-100 text-blue-800',
            'In Production' => 'bg-indigo-100 text-indigo-800',
            'Awaiting Dispatch' => 'bg-purple-100 text-purple-800',
            'Dispatched' => 'bg-green-100 text-green-800',
            'Completed' => 'bg-emerald-100 text-emerald-800',
            'Cancelled' => 'bg-red-100 text-red-800',
        ];
        return $map[$status] ?? 'bg-slate-100 text-slate-800';
    }
}

if (!function_exists('work_order_queue_badge_class')) {
    function work_order_queue_badge_class(string $status): string
    {
        $map = [
            'Pending' => 'bg-slate-100 text-slate-700',
            'Received' => 'bg-blue-100 text-blue-800',
            'In Progress' => 'bg-indigo-100 text-indigo-800',
            'Completed' => 'bg-green-100 text-green-800',
            'Dispatched' => 'bg-emerald-100 text-emerald-800',
            'Returned' => 'bg-red-100 text-red-800',
            'On Hold' => 'bg-amber-100 text-amber-800',
        ];
        return $map[$status] ?? 'bg-slate-100 text-slate-700';
    }
}

if (!function_exists('work_order_fetch_specifications')) {
    function work_order_fetch_specifications(PDO $pdo, int $workOrderId): ?array
    {
        $stmt = $pdo->prepare("SELECT * FROM work_order_specifications WHERE work_order_id = ? LIMIT 1");
        $stmt->execute([$workOrderId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}

if (!function_exists('work_order_fetch_movements')) {
    function work_order_fetch_movements(PDO $pdo, int $workOrderId): array
    {
        $stmt = $pdo->prepare("
            SELECT pm.*, fd.name AS from_department_name, td.name AS to_department_name,
                   su.name AS sender_name, ru.name AS receiver_name
            FROM production_movements pm
            LEFT JOIN production_departments fd ON pm.from_department_id = fd.id
            LEFT JOIN production_departments td ON pm.to_department_id = td.id
            LEFT JOIN users su ON pm.sender_user_id = su.id
            LEFT JOIN users ru ON pm.receiver_user_id = ru.id
            WHERE pm.work_order_id = ?
            ORDER BY pm.created_at DESC, pm.id DESC
        ");
        $stmt->execute([$workOrderId]);
        return work_order_fetch_all($stmt);
    }
}

if (!function_exists('work_order_recalculate_state')) {
    function work_order_recalculate_state(PDO $pdo, int $workOrderId, ?int $userId = null): void
    {
        $statusStmt = $pdo->prepare("SELECT status FROM work_orders WHERE id = ? LIMIT 1");
        $statusStmt->execute([$workOrderId]);
        $currentWoStatus = (string) ($statusStmt->fetchColumn() ?: '');
        if ($currentWoStatus === 'Draft') {
            return;
        }

        $route = work_order_fetch_route($pdo, $workOrderId);
        if (empty($route)) {
            return;
        }

        $currentDepartmentId = null;
        $newStatus = 'Ready for Production';
        $productionStartedAt = null;
        $productionCompletedAt = null;
        $dispatchReadyAt = null;

        $allCompleted = true;
        $hasStarted = false;
        foreach ($route as $step) {
            $stepStatus = (string) ($step['status'] ?? 'Pending');
            if (in_array($stepStatus, ['Received', 'In Progress', 'Completed', 'Dispatched', 'On Hold', 'Returned'], true)) {
                $hasStarted = true;
            }
            if (!in_array($stepStatus, ['Completed', 'Dispatched'], true)) {
                $allCompleted = false;
            }

            if ($currentDepartmentId === null && !in_array($stepStatus, ['Completed', 'Dispatched'], true)) {
                $currentDepartmentId = (int) $step['department_id'];
                if (($step['slug'] ?? '') === 'dispatch-office' && in_array($stepStatus, ['Pending', 'Received', 'In Progress', 'Completed'], true)) {
                    $newStatus = 'Awaiting Dispatch';
                } elseif ($hasStarted || in_array($stepStatus, ['Received', 'In Progress', 'On Hold', 'Returned'], true)) {
                    $newStatus = 'In Production';
                } elseif ($stepStatus === 'Pending') {
                    $newStatus = 'Ready for Production';
                }
            }
        }

        if ($hasStarted) {
            $newStatus = $newStatus === 'Ready for Production' ? 'In Production' : $newStatus;
            $productionStartedAt = date('Y-m-d H:i:s');
        }

        if ($allCompleted) {
            $newStatus = 'Awaiting Dispatch';
            $dispatchReadyAt = date('Y-m-d H:i:s');
            $productionCompletedAt = date('Y-m-d H:i:s');
            $currentDepartmentId = null;
        }

        $stmt = $pdo->prepare("
            UPDATE work_orders
            SET status = :status,
                current_department_id = :current_department_id,
                updated_by = :updated_by,
                production_started_at = COALESCE(production_started_at, :production_started_at),
                production_completed_at = CASE
                    WHEN :set_production_completed = 0 THEN production_completed_at
                    ELSE COALESCE(production_completed_at, :production_completed_at)
                END,
                dispatch_ready_at = CASE
                    WHEN :set_dispatch_ready = 0 THEN dispatch_ready_at
                    ELSE COALESCE(dispatch_ready_at, :dispatch_ready_at)
                END
            WHERE id = :id
        ");
        $stmt->execute([
            'status' => $newStatus,
            'current_department_id' => $currentDepartmentId,
            'updated_by' => $userId,
            'production_started_at' => $productionStartedAt,
            'set_production_completed' => $productionCompletedAt ? 1 : 0,
            'production_completed_at' => $productionCompletedAt,
            'set_dispatch_ready' => $dispatchReadyAt ? 1 : 0,
            'dispatch_ready_at' => $dispatchReadyAt,
            'id' => $workOrderId,
        ]);
    }
}

if (!function_exists('work_order_can_send_to_origination')) {
    function work_order_can_send_to_origination(array $workOrder): bool
    {
        return (string) ($workOrder['status'] ?? '') === 'Draft'
            && empty($workOrder['sent_to_origination_at']);
    }
}

if (!function_exists('work_order_send_to_origination')) {
    function work_order_send_to_origination(PDO $pdo, int $workOrderId, int $userId): array
    {
        work_order_bootstrap($pdo);

        $workOrder = work_order_fetch_one($pdo, $workOrderId);
        if (!$workOrder) {
            throw new RuntimeException('Work order not found.');
        }
        if (!work_order_can_send_to_origination($workOrder)) {
            throw new RuntimeException('This work order cannot be sent to Origination.');
        }

        $deptStmt = $pdo->prepare("SELECT * FROM production_departments WHERE slug = 'origination' AND is_active = 1 LIMIT 1");
        $deptStmt->execute();
        $origination = $deptStmt->fetch(PDO::FETCH_ASSOC);
        if (!$origination) {
            throw new RuntimeException('Origination department is not configured.');
        }

        $pdo->beginTransaction();
        try {
            require_once __DIR__ . '/../libs/WorkOrderStatusManager.php';
            $statusManager = new WorkOrderStatusManager($pdo);
            $result = $statusManager->changeStatus(
                $workOrderId,
                WorkOrderStatusManager::STATUS_READY,
                $userId,
                'Sent to Origination from costing.'
            );
            if (!$result['success']) {
                throw new RuntimeException($result['message']);
            }

            $seqStmt = $pdo->prepare("SELECT COALESCE(MAX(sequence_no), 0) + 1 FROM production_routes WHERE work_order_id = ?");
            $seqStmt->execute([$workOrderId]);
            $sequenceNo = (int) $seqStmt->fetchColumn();

            $routeStmt = $pdo->prepare("
                INSERT INTO production_routes (work_order_id, department_id, step_name, sequence_no, is_required, route_status)
                VALUES (?, ?, ?, ?, 1, 'Active')
            ");
            $routeStmt->execute([
                $workOrderId,
                (int) $origination['id'],
                (string) $origination['name'],
                $sequenceNo,
            ]);
            $routeId = (int) $pdo->lastInsertId();

            $progressStmt = $pdo->prepare("
                INSERT INTO production_progress (work_order_id, route_id, department_id, status)
                VALUES (?, ?, ?, 'Pending')
            ");
            $progressStmt->execute([$workOrderId, $routeId, (int) $origination['id']]);

            $pdo->prepare("
                UPDATE work_orders
                SET current_department_id = ?,
                    sent_to_origination_at = NOW(),
                    sent_to_origination_by = ?,
                    updated_by = ?
                WHERE id = ?
            ")->execute([(int) $origination['id'], $userId, $userId, $workOrderId]);

            $pdo->prepare("
                INSERT INTO production_movements (
                    work_order_id, from_department_id, to_department_id, sender_user_id, movement_type, remarks
                ) VALUES (?, NULL, ?, ?, 'send_to_origination', ?)
            ")->execute([
                $workOrderId,
                (int) $origination['id'],
                $userId,
                'Work order sent to Origination from costing.',
            ]);

            $pdo->commit();

            return [
                'work_order_id' => $workOrderId,
                'work_order_number' => (string) $workOrder['work_order_number'],
                'department_slug' => 'origination',
                'message' => 'Work order ' . $workOrder['work_order_number'] . ' sent to Origination.',
            ];
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }
}

if (!function_exists('work_order_suggested_route_slugs')) {
    function work_order_suggested_route_slugs(PDO $pdo, int $workOrderId): array
    {
        $workOrder = work_order_fetch_one($pdo, $workOrderId);
        if (!$workOrder) {
            return [];
        }

        $estimation = [];
        $children = [
            'items' => [], 'papers' => [], 'ink' => [], 'binding' => [],
            'prepress' => [], 'press' => [], 'finishing' => [],
        ];
        $estimationId = (int) ($workOrder['estimation_id'] ?? 0);
        if ($estimationId > 0) {
            $estStmt = $pdo->prepare("SELECT * FROM estimations WHERE id = ?");
            $estStmt->execute([$estimationId]);
            $estimation = $estStmt->fetch(PDO::FETCH_ASSOC) ?: [];
            if ($estimation) {
                $children = work_order_estimation_children($pdo, $estimationId);
            }
        }

        return work_order_route_blueprint($estimation, $children);
    }
}

if (!function_exists('work_order_handoff_destinations')) {
    function work_order_handoff_destinations(PDO $pdo, int $workOrderId, int $fromDepartmentId, string $workflowMode = 'production'): array
    {
        work_order_bootstrap($pdo);

        $stmt = $pdo->query("
            SELECT id, slug, name, queue_label, workflow_mode, default_order
            FROM production_departments
            WHERE is_active = 1
            ORDER BY default_order ASC, id ASC
        ");
        $departments = work_order_fetch_all($stmt);
        $suggestedSlugs = work_order_suggested_route_slugs($pdo, $workOrderId);
        $destinations = [];

        foreach ($departments as $department) {
            $deptId = (int) $department['id'];
            if ($deptId === $fromDepartmentId) {
                continue;
            }

            $slug = (string) $department['slug'];
            $mode = (string) ($department['workflow_mode'] ?? 'production');

            if ($workflowMode === 'routing' && $slug === 'origination') {
                continue;
            }

            if ($mode === 'routing') {
                continue;
            }

            $destinations[] = [
                'id' => $deptId,
                'slug' => $slug,
                'name' => (string) $department['name'],
                'queue_label' => (string) ($department['queue_label'] ?? $department['name']),
                'workflow_mode' => $mode,
                'is_suggested' => in_array($slug, $suggestedSlugs, true),
            ];
        }

        return $destinations;
    }
}

if (!function_exists('work_order_handoff_needs_extra_fields')) {
    function work_order_handoff_needs_extra_fields(string $departmentSlug): bool
    {
        return in_array($departmentSlug, ['machine', 'new-site', 'finishing', 'binding'], true);
    }
}

if (!function_exists('work_order_can_handoff')) {
    function work_order_can_handoff(string $progressStatus, string $workflowMode = 'production'): bool
    {
        if ($workflowMode === 'routing') {
            return $progressStatus === 'Received';
        }

        return $progressStatus === 'Completed';
    }
}

if (!function_exists('work_order_designate_and_send')) {
    function work_order_designate_and_send(
        PDO $pdo,
        int $progressId,
        int $nextDepartmentId,
        int $userId,
        array $handoffData = []
    ): array {
        work_order_bootstrap($pdo);

        if ($nextDepartmentId <= 0) {
            throw new RuntimeException('Select the next department before sending.');
        }

        $stmt = $pdo->prepare("
            SELECT pp.*, pr.sequence_no, pr.work_order_id, pr.department_id, pr.id AS route_id,
                   pd.name AS department_name, pd.slug AS department_slug,
                   pd.workflow_mode AS department_workflow_mode,
                   wo.work_order_number, wo.status AS work_order_status
            FROM production_progress pp
            INNER JOIN production_routes pr ON pp.route_id = pr.id
            INNER JOIN production_departments pd ON pp.department_id = pd.id
            INNER JOIN work_orders wo ON pp.work_order_id = wo.id
            WHERE pp.id = ?
            LIMIT 1
        ");
        $stmt->execute([$progressId]);
        $progress = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$progress) {
            throw new RuntimeException('Queue item not found.');
        }

        $workflowMode = (string) ($progress['department_workflow_mode'] ?? 'production');
        if (!work_order_can_handoff((string) $progress['status'], $workflowMode)) {
            throw new RuntimeException('This job is not ready to send yet.');
        }

        $nextDeptStmt = $pdo->prepare("SELECT * FROM production_departments WHERE id = ? AND is_active = 1 LIMIT 1");
        $nextDeptStmt->execute([$nextDepartmentId]);
        $nextDepartment = $nextDeptStmt->fetch(PDO::FETCH_ASSOC);
        if (!$nextDepartment) {
            throw new RuntimeException('Selected department is not available.');
        }
        if ((int) $nextDepartment['id'] === (int) $progress['department_id']) {
            throw new RuntimeException('Cannot send a work order to the same department.');
        }

        $handoffNotes = trim((string) ($handoffData['handoff_notes'] ?? ''));
        $handoffSample = trim((string) ($handoffData['handoff_sample'] ?? ''));
        $handoffDeliveredBy = trim((string) ($handoffData['handoff_delivered_by'] ?? ''));
        $handoffRemarks = trim((string) ($handoffData['handoff_remarks'] ?? ''));

        $pdo->beginTransaction();
        try {
            $pdo->prepare("
                UPDATE production_progress
                SET status = 'Dispatched',
                    dispatched_at = COALESCE(dispatched_at, NOW()),
                    designated_next_department_id = ?,
                    handoff_sample = ?,
                    handoff_delivered_by = ?,
                    handoff_remarks = ?,
                    updated_by = ?,
                    remarks = ?
                WHERE id = ?
            ")->execute([
                $nextDepartmentId,
                $handoffSample !== '' ? $handoffSample : null,
                $handoffDeliveredBy !== '' ? $handoffDeliveredBy : null,
                $handoffRemarks !== '' ? $handoffRemarks : null,
                $userId,
                $handoffNotes !== '' ? $handoffNotes : ($progress['remarks'] ?? null),
                $progressId,
            ]);

            $pdo->prepare("UPDATE production_routes SET route_status = 'Completed' WHERE id = ?")
                ->execute([(int) $progress['route_id']]);

            $existingNextStmt = $pdo->prepare("
                SELECT pr.*
                FROM production_routes pr
                WHERE pr.work_order_id = ?
                  AND pr.department_id = ?
                  AND pr.route_status IN ('Pending', 'Active')
                ORDER BY pr.sequence_no ASC
                LIMIT 1
            ");
            $existingNextStmt->execute([(int) $progress['work_order_id'], $nextDepartmentId]);
            $existingNext = $existingNextStmt->fetch(PDO::FETCH_ASSOC);

            if ($existingNext) {
                $nextRouteId = (int) $existingNext['id'];
                $pdo->prepare("UPDATE production_routes SET route_status = 'Active' WHERE id = ?")
                    ->execute([$nextRouteId]);
                $progressCheck = $pdo->prepare("SELECT id FROM production_progress WHERE route_id = ? LIMIT 1");
                $progressCheck->execute([$nextRouteId]);
                if (!$progressCheck->fetchColumn()) {
                    $pdo->prepare("
                        INSERT INTO production_progress (work_order_id, route_id, department_id, status)
                        VALUES (?, ?, ?, 'Pending')
                    ")->execute([(int) $progress['work_order_id'], $nextRouteId, $nextDepartmentId]);
                }
            } else {
                $seqStmt = $pdo->prepare("SELECT COALESCE(MAX(sequence_no), 0) + 1 FROM production_routes WHERE work_order_id = ?");
                $seqStmt->execute([(int) $progress['work_order_id']]);
                $sequenceNo = (int) $seqStmt->fetchColumn();

                $pdo->prepare("
                    INSERT INTO production_routes (work_order_id, department_id, step_name, sequence_no, is_required, route_status)
                    VALUES (?, ?, ?, ?, 1, 'Active')
                ")->execute([
                    (int) $progress['work_order_id'],
                    $nextDepartmentId,
                    (string) $nextDepartment['name'],
                    $sequenceNo,
                ]);
                $nextRouteId = (int) $pdo->lastInsertId();

                $pdo->prepare("
                    INSERT INTO production_progress (work_order_id, route_id, department_id, status)
                    VALUES (?, ?, ?, 'Pending')
                ")->execute([(int) $progress['work_order_id'], $nextRouteId, $nextDepartmentId]);
            }

            $pdo->prepare("
                UPDATE work_orders
                SET current_department_id = ?, updated_by = ?
                WHERE id = ?
            ")->execute([$nextDepartmentId, $userId, (int) $progress['work_order_id']]);

            $movementRemarks = $handoffNotes !== ''
                ? $handoffNotes
                : ('Designated to ' . ($nextDepartment['name'] ?? 'next department'));

            $pdo->prepare("
                INSERT INTO production_movements (
                    work_order_id, from_department_id, to_department_id, sender_user_id, movement_type, remarks
                ) VALUES (?, ?, ?, ?, 'dispatch', ?)
            ")->execute([
                (int) $progress['work_order_id'],
                (int) $progress['department_id'],
                $nextDepartmentId,
                $userId,
                $movementRemarks,
            ]);

            if ((string) ($nextDepartment['slug'] ?? '') === 'dispatch-office') {
                require_once __DIR__ . '/../libs/WorkOrderStatusManager.php';
                $statusManager = new WorkOrderStatusManager($pdo);
                $statusManager->changeStatus(
                    (int) $progress['work_order_id'],
                    WorkOrderStatusManager::STATUS_AWAITING_DISPATCH,
                    $userId,
                    'Work order designated to Dispatch Office.'
                );
            } elseif ((string) $progress['status'] !== 'Completed' && $workflowMode === 'routing') {
                require_once __DIR__ . '/../libs/WorkOrderStatusManager.php';
                $statusManager = new WorkOrderStatusManager($pdo);
                $statusManager->changeStatus(
                    (int) $progress['work_order_id'],
                    WorkOrderStatusManager::STATUS_IN_PRODUCTION,
                    $userId,
                    'Origination designated next production section.'
                );
            }

            work_order_recalculate_state($pdo, (int) $progress['work_order_id'], $userId);
            $pdo->commit();

            return [
                'work_order_id' => (int) $progress['work_order_id'],
                'department_slug' => (string) ($progress['department_slug'] ?? ''),
                'next_department_slug' => (string) ($nextDepartment['slug'] ?? ''),
                'next_department_name' => (string) ($nextDepartment['name'] ?? ''),
                'message' => ($progress['department_name'] ?? 'Department') . ' sent to ' . ($nextDepartment['name'] ?? 'next department') . '.',
            ];
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }
}

if (!function_exists('work_order_department_form_section')) {
    function work_order_department_form_section(string $departmentSlug): ?string
    {
        $map = [
            'origination' => 'composing',
            'photosetters' => 'composing',
            'machine' => 'letterpress',
            'new-site' => 'letterpress',
            'binding' => 'bookbinding',
            'finishing' => 'bookbinding',
            'dispatch-office' => 'dispatch',
        ];

        return $map[$departmentSlug] ?? null;
    }
}

if (!function_exists('work_order_allowed_queue_actions')) {
    function work_order_allowed_queue_actions(string $progressStatus, bool $hasNextDepartment, string $workflowMode = 'production'): array
    {
        if ($workflowMode === 'routing') {
            return match ($progressStatus) {
                'Pending' => [],
                'Received' => ['hold'],
                'On Hold' => ['start', 'return'],
                'Returned' => [],
                default => [],
            };
        }

        $actions = match ($progressStatus) {
            'Pending' => [],
            'Received' => ['start', 'hold'],
            'In Progress' => ['complete', 'hold'],
            'On Hold' => ['start', 'return'],
            'Returned' => [],
            'Completed' => $hasNextDepartment ? ['hold'] : [],
            'Dispatched' => [],
            default => [],
        };

        return $actions;
    }
}

if (!function_exists('work_order_primary_workspace_action')) {
    function work_order_primary_workspace_action(
        string $progressStatus,
        bool $hasNextDepartment,
        ?string $nextDepartmentName = null,
        string $workflowMode = 'production',
        int $progressId = 0,
        int $workOrderId = 0,
        string $departmentSlug = ''
    ): ?array {
        if ($workflowMode === 'routing') {
            return match ($progressStatus) {
                'Pending' => [
                    'type' => 'receive_page',
                    'progress_id' => $progressId,
                    'label' => 'Receive job',
                    'description' => 'Record receipt and accept into Origination',
                    'button_class' => 'bg-blue-600 hover:bg-blue-700',
                ],
                'Received' => [
                    'type' => 'handoff',
                    'progress_id' => $progressId,
                    'label' => 'Designate & send',
                    'description' => 'Fill origination record and choose the next section',
                    'button_class' => 'bg-emerald-600 hover:bg-emerald-700',
                ],
                'On Hold' => [
                    'type' => 'queue',
                    'action' => 'start',
                    'label' => 'Resume',
                    'description' => 'Continue routing this job',
                    'button_class' => 'bg-amber-600 hover:bg-amber-700',
                ],
                'Returned' => [
                    'type' => 'receive_page',
                    'progress_id' => $progressId,
                    'label' => 'Receive again',
                    'description' => 'Re-accept this returned job',
                    'button_class' => 'bg-blue-600 hover:bg-blue-700',
                ],
                default => null,
            };
        }

        $nextLabel = $nextDepartmentName ? trim($nextDepartmentName) : 'next department';

        return match ($progressStatus) {
            'Pending' => [
                'type' => 'receive_page',
                'progress_id' => $progressId,
                'label' => 'Receive job',
                'description' => 'Record quantity, receiver, and notes',
                'button_class' => 'bg-blue-600 hover:bg-blue-700',
            ],
            'Received' => [
                'type' => 'queue',
                'action' => 'start',
                'label' => 'Start work',
                'description' => 'Begin production on this job',
                'button_class' => 'bg-indigo-600 hover:bg-indigo-700',
            ],
            'In Progress' => [
                'type' => 'queue',
                'action' => 'complete',
                'label' => 'Mark complete',
                'description' => 'Finish work here, then designate the next section',
                'button_class' => 'bg-indigo-600 hover:bg-indigo-700',
            ],
            'On Hold' => [
                'type' => 'queue',
                'action' => 'start',
                'label' => 'Resume work',
                'description' => 'Continue this job',
                'button_class' => 'bg-amber-600 hover:bg-amber-700',
            ],
            'Returned' => [
                'type' => 'receive_page',
                'progress_id' => $progressId,
                'label' => 'Receive again',
                'description' => 'Re-accept this returned job',
                'button_class' => 'bg-blue-600 hover:bg-blue-700',
            ],
            'Completed' => [
                'type' => 'handoff',
                'progress_id' => $progressId,
                'action' => 'dispatch',
                'label' => 'Designate & send',
                'description' => 'Choose the next section for this work order',
                'button_class' => 'bg-emerald-600 hover:bg-emerald-700',
            ],
            default => null,
        };
    }
}

if (!function_exists('work_order_workspace_steps')) {
    function work_order_workspace_steps(string $progressStatus, string $workflowMode = 'production'): array
    {
        if ($workflowMode === 'routing') {
            $labels = ['Receive', 'Record', 'Send'];
            $order = ['Pending' => 0, 'Received' => 1, 'Dispatched' => 3];
            $current = $order[$progressStatus] ?? 0;
        } else {
            $labels = ['Receive', 'Start', 'Complete', 'Send'];
            $order = ['Pending' => 0, 'Received' => 1, 'In Progress' => 2, 'Completed' => 3, 'Dispatched' => 4];
            $current = $order[$progressStatus] ?? 0;
        }

        $steps = [];
        foreach ($labels as $index => $label) {
            $stepNum = $index + 1;
            $state = 'upcoming';
            if ($progressStatus === 'Dispatched' || $current >= $stepNum) {
                $state = 'done';
            } elseif ($current + 1 === $stepNum) {
                $state = 'current';
            }
            $steps[] = ['label' => $label, 'state' => $state];
        }

        return $steps;
    }
}

if (!function_exists('work_order_fetch_handoff_context')) {
    function work_order_fetch_handoff_context(PDO $pdo, int $progressId): ?array
    {
        work_order_bootstrap($pdo);

        $stmt = $pdo->prepare("
            SELECT pp.*, pr.sequence_no, pr.work_order_id, pr.department_id, pr.id AS route_id,
                   pd.name AS department_name, pd.slug AS department_slug,
                   pd.workflow_mode AS department_workflow_mode,
                   wo.work_order_number, wo.customer_name, wo.job_description, wo.priority, wo.due_date,
                   wo.quantity, wo.pages_count, wo.binding_type_name
            FROM production_progress pp
            INNER JOIN production_routes pr ON pp.route_id = pr.id
            INNER JOIN production_departments pd ON pp.department_id = pd.id
            INNER JOIN work_orders wo ON pp.work_order_id = wo.id
            WHERE pp.id = ?
            LIMIT 1
        ");
        $stmt->execute([$progressId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }

        $destinations = work_order_handoff_destinations(
            $pdo,
            (int) $row['work_order_id'],
            (int) $row['department_id'],
            (string) ($row['department_workflow_mode'] ?? 'production')
        );
        $row['destinations'] = $destinations;
        $row['needs_extra_fields'] = work_order_handoff_needs_extra_fields((string) ($row['department_slug'] ?? ''));

        return $row;
    }
}

if (!function_exists('work_order_fetch_department_queue')) {
    function work_order_fetch_department_queue(PDO $pdo, string $departmentSlug, string $tab = 'incoming', array $filters = []): array
    {
        work_order_bootstrap($pdo);

        $workflowMode = 'production';
        $deptMetaStmt = $pdo->prepare("SELECT workflow_mode FROM production_departments WHERE slug = ? LIMIT 1");
        $deptMetaStmt->execute([$departmentSlug]);
        $deptMeta = $deptMetaStmt->fetch(PDO::FETCH_ASSOC);
        if ($deptMeta) {
            $workflowMode = (string) ($deptMeta['workflow_mode'] ?? 'production');
        }

        $query = "
            SELECT pp.id AS progress_id, pp.status AS progress_status, pp.received_at, pp.received_quantity,
                   pp.receive_notes, pp.started_at, pp.completed_at, pp.dispatched_at, pp.remarks, pp.hold_reason,
                   pp.designated_next_department_id, pp.handoff_sample, pp.handoff_delivered_by, pp.handoff_remarks,
                   wo.id AS work_order_id, wo.work_order_number, wo.customer_name, wo.job_description,
                   wo.priority, wo.status AS work_order_status, wo.due_date, wo.quantity, wo.pages_count,
                   wo.binding_type_name, wo.current_department_id, wo.created_at AS work_order_created_at,
                   pd.id AS department_id, pd.slug, pd.name AS department_name, pd.queue_label, pd.workflow_mode,
                   pr.id AS route_id, pr.sequence_no, pr.route_status,
                   rb.name AS received_by_name,
                   nd.name AS designated_next_department_name
            FROM production_progress pp
            INNER JOIN production_routes pr ON pp.route_id = pr.id
            INNER JOIN work_orders wo ON pp.work_order_id = wo.id
            INNER JOIN production_departments pd ON pp.department_id = pd.id
            LEFT JOIN users rb ON pp.received_by_user_id = rb.id
            LEFT JOIN production_departments nd ON pp.designated_next_department_id = nd.id
            WHERE pd.slug = :department_slug
              AND wo.status NOT IN ('Completed', 'Cancelled', 'Draft')
        ";

        $params = ['department_slug' => $departmentSlug];

        if ($tab === 'incoming') {
            $query .= " AND pr.route_status = 'Active' AND pp.status = 'Pending'";
        } elseif ($tab === 'active') {
            if ($workflowMode === 'routing') {
                $query .= " AND pr.route_status = 'Active' AND pp.status IN ('In Progress', 'On Hold', 'Returned')";
            } else {
                $query .= " AND pr.route_status = 'Active' AND pp.status IN ('Received', 'In Progress', 'On Hold', 'Returned')";
            }
        } elseif ($tab === 'ready') {
            if ($workflowMode === 'routing') {
                $query .= " AND pr.route_status = 'Active' AND pp.status = 'Received'";
            } else {
                $query .= " AND pr.route_status = 'Active' AND pp.status = 'Completed'";
            }
        } else {
            $query .= " AND (pp.status = 'Dispatched' OR (pr.route_status = 'Completed' AND pp.status IN ('Completed', 'Dispatched')))";
        }

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $query .= " AND (
                wo.work_order_number LIKE :search
                OR wo.customer_name LIKE :search
                OR wo.job_description LIKE :search
            )";
            $params['search'] = '%' . $search . '%';
        }

        $priority = trim((string) ($filters['priority'] ?? ''));
        if ($priority !== '' && in_array($priority, ['Normal', 'Urgent', 'Critical'], true)) {
            $query .= " AND wo.priority = :priority";
            $params['priority'] = $priority;
        }

        $dateFrom = trim((string) ($filters['date_from'] ?? ''));
        if ($dateFrom !== '') {
            $query .= " AND DATE(COALESCE(pp.received_at, wo.created_at)) >= :date_from";
            $params['date_from'] = $dateFrom;
        }

        $dateTo = trim((string) ($filters['date_to'] ?? ''));
        if ($dateTo !== '') {
            $query .= " AND DATE(COALESCE(pp.received_at, wo.created_at)) <= :date_to";
            $params['date_to'] = $dateTo;
        }

        $sort = trim((string) ($filters['sort'] ?? 'due_date'));
        $direction = strtoupper(trim((string) ($filters['direction'] ?? 'ASC'))) === 'DESC' ? 'DESC' : 'ASC';
        $sortMap = [
            'work_order_number' => 'wo.work_order_number',
            'customer_name' => 'wo.customer_name',
            'due_date' => 'COALESCE(wo.due_date, \'2999-12-31\')',
            'received_at' => 'COALESCE(pp.received_at, wo.created_at)',
            'created_at' => 'wo.created_at',
        ];
        $sortColumn = $sortMap[$sort] ?? $sortMap['due_date'];
        $query .= " ORDER BY {$sortColumn} {$direction}, wo.work_order_number ASC";

        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        return work_order_fetch_all($stmt);
    }
}

if (!function_exists('work_order_update_department_section')) {
    function work_order_update_department_section(PDO $pdo, int $workOrderId, string $departmentSlug, array $input, int $userId): void
    {
        work_order_bootstrap($pdo);
        $section = work_order_department_form_section($departmentSlug);
        if ($section === null) {
            throw new RuntimeException('This department does not have an editable traveler section.');
        }

        $spec = work_order_fetch_specifications($pdo, $workOrderId);
        if (!$spec) {
            throw new RuntimeException('Work order specifications not found.');
        }

        $productionForm = work_order_decode_json_field($spec['production_form_json'] ?? null);
        $fullForm = work_order_build_production_form($input);

        if ($section === 'composing') {
            $productionForm['composing'] = $fullForm['composing'];
        } elseif ($section === 'letterpress') {
            $productionForm['letterpress'] = $fullForm['letterpress'];
        } elseif ($section === 'bookbinding') {
            $productionForm['bookbinding'] = $fullForm['bookbinding'];
        } elseif ($section === 'dispatch') {
            $productionForm['dispatch_received'] = $fullForm['dispatch_received'];
            $productionForm['costing_tracking'] = $fullForm['costing_tracking'];
        }

        if (!empty($input['paper_ledger_no']) && is_array($input['paper_ledger_no'])) {
            $productionForm['paper_materials'] = $fullForm['paper_materials'];
        }

        $pdo->prepare("
            UPDATE work_order_specifications
            SET production_form_json = ?
            WHERE work_order_id = ?
        ")->execute([json_encode($productionForm), $workOrderId]);

        $pdo->prepare("UPDATE work_orders SET updated_by = ? WHERE id = ?")->execute([$userId, $workOrderId]);

        $deptStmt = $pdo->prepare("SELECT id, name FROM production_departments WHERE slug = ? LIMIT 1");
        $deptStmt->execute([$departmentSlug]);
        $department = $deptStmt->fetch(PDO::FETCH_ASSOC);

        $pdo->prepare("
            INSERT INTO production_movements (
                work_order_id, from_department_id, to_department_id, sender_user_id, movement_type, remarks
            ) VALUES (?, ?, ?, ?, ?, ?)
        ")->execute([
            $workOrderId,
            $department['id'] ?? null,
            $department['id'] ?? null,
            $userId,
            'department_update',
            ($department['name'] ?? $departmentSlug) . ' traveler section updated.',
        ]);
    }
}

if (!function_exists('work_order_process_queue_action')) {
    function work_order_process_queue_action(
        PDO $pdo,
        int $progressId,
        string $action,
        int $userId,
        string $remarks = '',
        string $holdReason = '',
        array $extra = []
    ): array {
        $allowedActions = ['receive', 'start', 'hold', 'complete', 'dispatch', 'return'];
        if (!in_array($action, $allowedActions, true)) {
            throw new RuntimeException('Unsupported queue action.');
        }

        if ($action === 'dispatch') {
            throw new RuntimeException('Use the designate and send screen to move work orders between departments.');
        }

        $stmt = $pdo->prepare("
            SELECT pp.*, pr.sequence_no, pr.work_order_id, pr.department_id, pr.id AS route_id,
                   pd.name AS department_name, pd.slug AS department_slug,
                   pd.workflow_mode AS department_workflow_mode
            FROM production_progress pp
            INNER JOIN production_routes pr ON pp.route_id = pr.id
            INNER JOIN production_departments pd ON pp.department_id = pd.id
            WHERE pp.id = ?
            LIMIT 1
        ");
        $stmt->execute([$progressId]);
        $progress = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$progress) {
            throw new RuntimeException('Queue item not found.');
        }

        $workflowMode = (string) ($progress['department_workflow_mode'] ?? 'production');

        $statusMap = [
            'receive' => 'Received',
            'start' => 'In Progress',
            'hold' => 'On Hold',
            'complete' => 'Completed',
            'dispatch' => 'Dispatched',
            'return' => 'Returned',
        ];

        if ($workflowMode === 'routing' && in_array($action, ['start', 'complete'], true)) {
            if ($action === 'start' && (string) ($progress['status'] ?? '') === 'On Hold') {
                $newStatus = 'Received';
            } else {
                throw new RuntimeException('Origination does not perform production work. Record the job and designate the next section.');
            }
        } else {
            $newStatus = $statusMap[$action];
        }
        $receivedQuantity = ($extra['received_quantity'] ?? '') !== '' ? (int) $extra['received_quantity'] : null;
        $receiveNotes = trim((string) ($extra['receive_notes'] ?? ''));

        $pdo->beginTransaction();
        try {
            $fields = [
                'status = :status',
                'updated_by = :updated_by',
                'remarks = :remarks',
            ];
            $params = [
                'status' => $newStatus,
                'updated_by' => $userId,
                'remarks' => $remarks !== '' ? $remarks : ($progress['remarks'] ?? null),
                'id' => $progressId,
            ];

            if ($action === 'receive') {
                $fields[] = 'received_at = COALESCE(received_at, NOW())';
                $fields[] = 'received_by_user_id = :received_by_user_id';
                $fields[] = 'received_quantity = :received_quantity';
                $fields[] = 'receive_notes = :receive_notes';
                $params['received_by_user_id'] = $userId;
                $params['received_quantity'] = $receivedQuantity;
                $params['receive_notes'] = $receiveNotes !== '' ? $receiveNotes : null;
            } elseif ($action === 'start') {
                $fields[] = 'started_at = COALESCE(started_at, NOW())';
            } elseif ($action === 'hold') {
                $fields[] = 'on_hold_at = NOW()';
                $fields[] = 'hold_reason = :hold_reason';
                $params['hold_reason'] = $holdReason;
            } elseif ($action === 'complete') {
                $fields[] = 'completed_at = COALESCE(completed_at, NOW())';
            } elseif ($action === 'return') {
                $fields[] = 'returned_at = NOW()';
            }

            $update = $pdo->prepare('UPDATE production_progress SET ' . implode(', ', $fields) . ' WHERE id = :id');
            $update->execute($params);

            if (in_array($action, ['start', 'receive'], true)) {
                $pdo->prepare("UPDATE production_routes SET route_status = 'Active' WHERE id = ?")->execute([(int) $progress['route_id']]);
            } elseif ($action === 'complete') {
                $pdo->prepare("UPDATE production_routes SET route_status = 'Active' WHERE id = ?")->execute([(int) $progress['route_id']]);
            } elseif ($action === 'hold' || $action === 'return') {
                $pdo->prepare("UPDATE production_routes SET route_status = 'Active' WHERE id = ?")->execute([(int) $progress['route_id']]);
            }

            $movementRemarks = $remarks;
            if ($action === 'receive') {
                $parts = [];
                if ($receivedQuantity !== null && $receivedQuantity > 0) {
                    $parts[] = 'Qty: ' . $receivedQuantity;
                }
                if ($receiveNotes !== '') {
                    $parts[] = $receiveNotes;
                }
                $movementRemarks = !empty($parts) ? implode(' | ', $parts) : 'Job received in department.';
            }

            $movementStmt = $pdo->prepare("
                INSERT INTO production_movements (
                    work_order_id, from_department_id, to_department_id, sender_user_id, receiver_user_id, movement_type, remarks
                ) VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $movementStmt->execute([
                (int) $progress['work_order_id'],
                (int) $progress['department_id'],
                (int) $progress['department_id'],
                $action === 'receive' ? null : $userId,
                $action === 'receive' ? $userId : null,
                $action,
                $movementRemarks !== '' ? $movementRemarks : null,
            ]);

            if ($action === 'receive' || $action === 'start') {
                require_once __DIR__ . '/../libs/WorkOrderStatusManager.php';
                $statusManager = new WorkOrderStatusManager($pdo);
                $statusManager->changeStatus(
                    (int) $progress['work_order_id'],
                    WorkOrderStatusManager::STATUS_IN_PRODUCTION,
                    $userId,
                    $action === 'receive' ? 'Job received in department.' : 'Job started.'
                );
            }

            work_order_recalculate_state($pdo, (int) $progress['work_order_id'], $userId);
            $pdo->commit();

            return [
                'work_order_id' => (int) $progress['work_order_id'],
                'department_slug' => (string) ($progress['department_slug'] ?? ''),
                'next_department_slug' => '',
                'message' => $progress['department_name'] . ' updated to ' . $newStatus . '.',
            ];
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }
}

