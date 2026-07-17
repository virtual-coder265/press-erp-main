<?php

if (!function_exists('work_order_notification_ensure_prefs')) {
    /**
     * Seed work_order notification preferences for all users (idempotent).
     */
    function work_order_notification_ensure_prefs(PDO $pdo): void
    {
        if (!function_exists('work_order_table_exists') || !work_order_table_exists($pdo, 'notification_settings')) {
            return;
        }

        try {
            $stmt = $pdo->prepare("
                INSERT IGNORE INTO notification_settings
                    (user_id, notification_type, email_enabled, in_app_enabled, push_enabled, sms_enabled, whatsapp_enabled)
                SELECT id, 'work_order', 1, 1, 1, 0, 0
                FROM users
            ");
            $stmt->execute();
        } catch (Throwable $exception) {
            error_log('work_order_notification_ensure_prefs failed: ' . $exception->getMessage());
        }
    }
}

if (!function_exists('work_order_department_user_ids')) {
    /**
     * User IDs assigned to a production department via production_department_users.
     *
     * @return int[]
     */
    function work_order_department_user_ids(PDO $pdo, int $departmentId): array
    {
        if ($departmentId <= 0 || !work_order_table_exists($pdo, 'production_department_users')) {
            return [];
        }

        $stmt = $pdo->prepare("
            SELECT user_id
            FROM production_department_users
            WHERE department_id = ?
            ORDER BY is_primary DESC, id ASC
        ");
        $stmt->execute([$departmentId]);

        return array_map('intval', array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'user_id'));
    }
}

if (!function_exists('work_order_queue_manager_user_ids')) {
    /**
     * Fallback recipients when no users are assigned to a production department.
     *
     * @return int[]
     */
    function work_order_queue_manager_user_ids(PDO $pdo, ?int $excludeUserId = null): array
    {
        if (!work_order_table_exists($pdo, 'permissions')) {
            return [];
        }

        $sql = "
            SELECT DISTINCT u.id
            FROM users u
            INNER JOIN roles r ON r.id = u.role_id
            INNER JOIN role_permissions rp ON rp.role_id = r.id
            INNER JOIN permissions p ON p.id = rp.permission_id
            WHERE p.slug IN ('manage_production_queues', 'manage_work_orders')
        ";
        $params = [];
        if ($excludeUserId !== null && $excludeUserId > 0) {
            $sql .= ' AND u.id <> :exclude_user_id';
            $params['exclude_user_id'] = $excludeUserId;
        }
        $sql .= ' ORDER BY u.id ASC';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        return array_map('intval', array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'id'));
    }
}

if (!function_exists('work_order_department_recipients')) {
    /**
     * Resolve notification recipients for a production department.
     *
     * @return int[]
     */
    function work_order_department_recipients(PDO $pdo, int $departmentId, ?int $excludeUserId = null): array
    {
        $userIds = work_order_department_user_ids($pdo, $departmentId);

        if (empty($userIds)) {
            $userIds = work_order_queue_manager_user_ids($pdo, $excludeUserId);
        }

        if ($excludeUserId !== null && $excludeUserId > 0) {
            $userIds = array_values(array_filter(
                $userIds,
                static fn (int $id): bool => $id !== $excludeUserId
            ));
        }

        return array_values(array_unique($userIds));
    }
}

if (!function_exists('work_order_notification_workspace_link')) {
    function work_order_notification_workspace_link(string $departmentSlug, string $tab = 'incoming'): string
    {
        $query = http_build_query([
            'department' => $departmentSlug,
            'tab' => $tab,
        ]);

        return 'modules/work_orders/workspace?' . $query;
    }
}

if (!function_exists('work_order_fetch_user_display_name')) {
    function work_order_fetch_user_display_name(PDO $pdo, int $userId): string
    {
        if ($userId <= 0) {
            return 'Someone';
        }

        $stmt = $pdo->prepare('SELECT name FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$userId]);
        $name = trim((string) $stmt->fetchColumn());

        return $name !== '' ? $name : 'Someone';
    }
}

if (!function_exists('work_order_notify_department_incoming')) {
    /**
     * Notify department users that a work order is waiting in their incoming queue.
     */
    function work_order_notify_department_incoming(
        PDO $pdo,
        int $departmentId,
        string $departmentSlug,
        string $departmentName,
        int $workOrderId,
        string $workOrderNumber,
        int $actorUserId,
        string $event = 'incoming',
        ?string $fromDepartmentName = null,
        ?string $remarks = null
    ): void {
        if ($departmentId <= 0 || $workOrderId <= 0 || $workOrderNumber === '') {
            return;
        }

        work_order_notification_ensure_prefs($pdo);

        require_once __DIR__ . '/../libs/NotificationManager.php';
        $notifManager = new NotificationManager($pdo);

        $actorName = work_order_fetch_user_display_name($pdo, $actorUserId);
        $recipientIds = work_order_department_recipients($pdo, $departmentId, $actorUserId);

        if (empty($recipientIds)) {
            return;
        }

        $title = match ($event) {
            'send_to_origination' => 'New work order in ' . $departmentName,
            'handoff' => 'New work order in ' . $departmentName,
            'send_back' => 'Work order returned to ' . $departmentName,
            default => 'Incoming work order for ' . $departmentName,
        };

        $descriptionParts = [];
        if ($event === 'send_back') {
            $descriptionParts[] = $actorName . ' sent work order ' . $workOrderNumber . ' back to ' . $departmentName . '.';
        } elseif ($fromDepartmentName !== null && $fromDepartmentName !== '') {
            $descriptionParts[] = $actorName . ' sent work order ' . $workOrderNumber . ' from ' . $fromDepartmentName . ' to your incoming queue.';
        } else {
            $descriptionParts[] = 'Work order ' . $workOrderNumber . ' is waiting in the ' . $departmentName . ' incoming queue.';
        }

        if ($remarks !== null && trim($remarks) !== '') {
            $descriptionParts[] = trim($remarks);
        }

        $description = implode(' ', $descriptionParts);
        $link = work_order_notification_workspace_link($departmentSlug, 'incoming');

        $context = [
            'workOrderNumber' => $workOrderNumber,
            'department' => $departmentName,
            'departmentSlug' => $departmentSlug,
            'fromDepartment' => $fromDepartmentName ?? '',
            'senderName' => $actorName,
            'event' => $event,
            'remarks' => $remarks ?? '',
        ];

        foreach ($recipientIds as $recipientId) {
            $notifManager->notify(
                $recipientId,
                'work_order',
                $title,
                $description,
                $link,
                $workOrderId,
                false,
                false,
                $context
            );
        }
    }
}

if (!function_exists('work_order_fetch_department_user_map')) {
    /**
     * @return array<int, array<int, array{id:int,user_id:int,is_primary:int}>>
     */
    function work_order_fetch_department_user_map(PDO $pdo): array
    {
        if (!work_order_table_exists($pdo, 'production_department_users')) {
            return [];
        }

        $stmt = $pdo->query("
            SELECT id, department_id, user_id, is_primary
            FROM production_department_users
            ORDER BY department_id ASC, is_primary DESC, id ASC
        ");
        $map = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $deptId = (int) $row['department_id'];
            $map[$deptId][] = [
                'id' => (int) $row['id'],
                'user_id' => (int) $row['user_id'],
                'is_primary' => (int) $row['is_primary'],
            ];
        }

        return $map;
    }
}

if (!function_exists('work_order_save_department_users')) {
    /**
     * Replace all user assignments for a production department.
     *
     * @param int[] $userIds
     */
    function work_order_save_department_users(PDO $pdo, int $departmentId, array $userIds, ?int $primaryUserId = null): void
    {
        if ($departmentId <= 0 || !work_order_table_exists($pdo, 'production_department_users')) {
            throw new RuntimeException('Production department assignments are not available.');
        }

        $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds), static fn (int $id): bool => $id > 0)));
        if ($primaryUserId !== null && $primaryUserId > 0 && !in_array($primaryUserId, $userIds, true)) {
            $primaryUserId = null;
        }
        if ($primaryUserId === null && count($userIds) === 1) {
            $primaryUserId = $userIds[0];
        }

        $pdo->beginTransaction();
        try {
            $delete = $pdo->prepare('DELETE FROM production_department_users WHERE department_id = ?');
            $delete->execute([$departmentId]);

            if (!empty($userIds)) {
                $insert = $pdo->prepare("
                    INSERT INTO production_department_users (department_id, user_id, is_primary)
                    VALUES (?, ?, ?)
                ");
                foreach ($userIds as $userId) {
                    $insert->execute([$departmentId, $userId, ($primaryUserId === $userId) ? 1 : 0]);
                }
            }

            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }
}
