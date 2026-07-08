<?php

require_once __DIR__ . '/installer_helper.php';

/**
 * Table groups for selective transactional data reset.
 * Users, roles, permissions, departments, branches, and business_settings are always preserved.
 */
function data_reset_groups(): array
{
    return [
        'commercial' => [
            'label' => 'Commercial & sales',
            'description' => 'Estimations, invoices, payments, work orders, dispatch register, and customer records.',
            'default' => true,
            'tables' => [
                'production_movements',
                'production_progress',
                'production_routes',
                'work_order_specifications',
                'dispatch_register',
                'dispatch_remarks',
                'work_orders',
                'invoice_payments',
                'invoice_items',
                'invoices',
                'estimation_status_history',
                'estimation_items',
                'estimation_papers',
                'estimation_ink_colours',
                'estimation_binding_materials',
                'estimation_prepress_labour',
                'estimation_press_labour',
                'estimation_finishing_labour',
                'estimations',
                'customers',
            ],
            'upload_dirs' => [],
        ],
        'projects' => [
            'label' => 'Projects & tasks',
            'description' => 'Projects, tasks, comments, attachments, expenses, reminders, and related activity.',
            'default' => true,
            'tables' => [
                'task_progress_log_attachments',
                'task_progress_log_steps',
                'task_progress_logs',
                'task_comment_attachments',
                'task_comments',
                'task_attachments',
                'task_expenses',
                'task_reviews',
                'task_documentation',
                'task_reminder_log',
                'task_assignees',
                'task_team_invitations',
                'task_procedure_steps',
                'reminders',
                'tasks',
                'project_comment_attachments',
                'project_comments',
                'project_files',
                'project_timeline_items',
                'project_team_invitations',
                'project_team_members',
                'project_activity_log',
                'project_risks',
                'projects',
            ],
            'upload_dirs' => [
                'uploads/projects',
                'uploads/tasks',
                'uploads/task_comments',
                'uploads/project_comments',
            ],
        ],
        'messaging' => [
            'label' => 'Messaging',
            'description' => 'Internal conversations and message attachments.',
            'default' => true,
            'tables' => [
                'message_attachments',
                'messages',
                'conversations',
            ],
            'upload_dirs' => [
                'uploads/messages',
            ],
        ],
        'notifications' => [
            'label' => 'Notifications & email queues',
            'description' => 'In-app notifications, notification queues, email queue, and email delivery logs.',
            'default' => true,
            'tables' => [
                'notification_dispatch_logs',
                'notification_queue',
                'notifications',
                'notification_settings',
                'email_queue',
                'email_log',
            ],
            'upload_dirs' => [],
        ],
        'audit_logs' => [
            'label' => 'Audit & security logs',
            'description' => 'Audit trail, failed login attempts, IP blocks, AI usage events, and push subscriptions.',
            'default' => true,
            'tables' => [
                'audit_logs',
                'security_login_attempts',
                'security_ip_blocks',
                'ai_usage_events',
                'web_push_subscriptions',
            ],
            'upload_dirs' => [],
        ],
        'file_library' => [
            'label' => 'File library',
            'description' => 'Shared file library folders and uploaded files metadata.',
            'default' => true,
            'tables' => [
                'file_library_files',
                'file_library_folders',
            ],
            'upload_dirs' => [
                'uploads/file_library',
            ],
        ],
        'catalog' => [
            'label' => 'Product & material catalogs',
            'description' => 'Materials, products, services, and their categories. Leave unchecked to keep pricing catalogs.',
            'default' => false,
            'tables' => [
                'material_rates',
                'materials',
                'material_categories',
                'products',
                'product_categories',
                'services',
                'service_categories',
            ],
            'upload_dirs' => [],
        ],
    ];
}

function data_reset_preserved_tables(): array
{
    return [
        'users',
        'roles',
        'permissions',
        'role_permissions',
        'departments',
        'branches',
        'business_settings',
        'production_departments',
        'production_department_users',
        'work_order_binding_types',
    ];
}

function data_reset_access_allowed(): bool
{
    return ($_SESSION['role'] ?? '') === 'System Admin'
        || hasPermission('manage_settings');
}

function data_reset_require_access(): void
{
    if (!data_reset_access_allowed()) {
        header('HTTP/1.1 403 Forbidden');
        die('Access Denied.');
    }
}

function data_reset_table_exists(PDO $pdo, string $tableName): bool
{
    return installer_table_exists($pdo, $tableName);
}

function data_reset_count_table(PDO $pdo, string $tableName): ?int
{
    if (!data_reset_table_exists($pdo, $tableName)) {
        return null;
    }

    try {
        return (int) $pdo->query('SELECT COUNT(*) FROM `' . str_replace('`', '``', $tableName) . '`')->fetchColumn();
    } catch (Exception $exception) {
        return null;
    }
}

function data_reset_group_stats(PDO $pdo, array $selectedGroups): array
{
    $groups = data_reset_groups();
    $stats = [];

    foreach ($selectedGroups as $groupKey) {
        if (!isset($groups[$groupKey])) {
            continue;
        }

        $group = $groups[$groupKey];
        $tableStats = [];
        $rowTotal = 0;
        $existingTables = 0;

        foreach ($group['tables'] as $tableName) {
            $count = data_reset_count_table($pdo, $tableName);
            if ($count === null) {
                continue;
            }

            $existingTables++;
            $rowTotal += $count;
            $tableStats[] = [
                'table' => $tableName,
                'rows' => $count,
            ];
        }

        $stats[$groupKey] = [
            'label' => $group['label'],
            'tables' => $tableStats,
            'table_count' => $existingTables,
            'row_total' => $rowTotal,
        ];
    }

    return $stats;
}

function data_reset_collect_tables(array $selectedGroups): array
{
    $groups = data_reset_groups();
    $tables = [];

    foreach ($selectedGroups as $groupKey) {
        if (!isset($groups[$groupKey])) {
            continue;
        }

        foreach ($groups[$groupKey]['tables'] as $tableName) {
            $tables[$tableName] = true;
        }
    }

    return array_keys($tables);
}

function data_reset_collect_upload_dirs(array $selectedGroups): array
{
    $groups = data_reset_groups();
    $dirs = [];

    foreach ($selectedGroups as $groupKey) {
        if (!isset($groups[$groupKey])) {
            continue;
        }

        foreach ($group['upload_dirs'] as $relativeDir) {
            $dirs[$relativeDir] = true;
        }
    }

    return array_keys($dirs);
}

function data_reset_empty_directory(string $absolutePath): array
{
    $result = [
        'path' => $absolutePath,
        'removed_files' => 0,
        'removed_dirs' => 0,
        'skipped' => false,
    ];

    if (!is_dir($absolutePath)) {
        $result['skipped'] = true;
        return $result;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($absolutePath, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($iterator as $item) {
        if ($item->isDir()) {
            if (@rmdir($item->getPathname())) {
                $result['removed_dirs']++;
            }
            continue;
        }

        if (@unlink($item->getPathname())) {
            $result['removed_files']++;
        }
    }

    return $result;
}

function data_reset_truncate_tables(PDO $pdo, array $tableNames): array
{
    $results = [
        'truncated' => [],
        'skipped' => [],
        'errors' => [],
    ];

    $preserved = array_flip(data_reset_preserved_tables());

    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');

    foreach ($tableNames as $tableName) {
        if (isset($preserved[$tableName])) {
            $results['skipped'][] = $tableName;
            continue;
        }

        if (!data_reset_table_exists($pdo, $tableName)) {
            $results['skipped'][] = $tableName;
            continue;
        }

        try {
            $safeTable = str_replace('`', '``', $tableName);
            $pdo->exec('TRUNCATE TABLE `' . $safeTable . '`');
            $results['truncated'][] = $tableName;
        } catch (Exception $exception) {
            try {
                $safeTable = str_replace('`', '``', $tableName);
                $pdo->exec('DELETE FROM `' . $safeTable . '`');
                $results['truncated'][] = $tableName;
            } catch (Exception $deleteException) {
                $results['errors'][$tableName] = $deleteException->getMessage();
            }
        }
    }

    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

    return $results;
}

function data_reset_cleanup_uploads(array $relativeDirs): array
{
    $results = [];

    foreach ($relativeDirs as $relativeDir) {
        $relativeDir = trim(str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $relativeDir), DIRECTORY_SEPARATOR);
        if ($relativeDir === '') {
            continue;
        }

        $absolutePath = rtrim(ROOT_PATH, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $relativeDir;
        $results[] = data_reset_empty_directory($absolutePath);
    }

    return $results;
}

function data_reset_execute(PDO $pdo, array $selectedGroups): array
{
    $groups = data_reset_groups();
    $validGroups = [];

    foreach ($selectedGroups as $groupKey) {
        if (isset($groups[$groupKey])) {
            $validGroups[] = $groupKey;
        }
    }

    if ($validGroups === []) {
        throw new InvalidArgumentException('Select at least one data group to reset.');
    }

    $tableNames = data_reset_collect_tables($validGroups);
    $uploadDirs = data_reset_collect_upload_dirs($validGroups);

    $summary = [
        'groups' => $validGroups,
        'tables' => data_reset_truncate_tables($pdo, $tableNames),
        'uploads' => data_reset_cleanup_uploads($uploadDirs),
    ];

    return $summary;
}

function data_reset_default_group_keys(): array
{
    $keys = [];

    foreach (data_reset_groups() as $groupKey => $group) {
        if (!empty($group['default'])) {
            $keys[] = $groupKey;
        }
    }

    return $keys;
}

function data_reset_format_summary(array $summary): string
{
    $truncatedCount = count($summary['tables']['truncated'] ?? []);
    $uploadFiles = 0;

    foreach ($summary['uploads'] ?? [] as $uploadResult) {
        $uploadFiles += (int) ($uploadResult['removed_files'] ?? 0);
    }

    $parts = [];
    $parts[] = $truncatedCount . ' table' . ($truncatedCount === 1 ? '' : 's') . ' cleared';

    if ($uploadFiles > 0) {
        $parts[] = $uploadFiles . ' uploaded file' . ($uploadFiles === 1 ? '' : 's') . ' removed';
    }

    return implode(', ', $parts);
}
