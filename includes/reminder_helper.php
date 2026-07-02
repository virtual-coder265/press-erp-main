<?php
require_once __DIR__ . '/task_management_helper.php';

/**
 * Default navigation URL for Reminder Hub (calendar-first entry).
 */
function reminders_hub_entry_url(): string
{
    return BASE_URL . 'modules/reminders/index';
}

function reminder_table_exists(PDO $pdo, string $tableName): bool
{
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM information_schema.tables
        WHERE table_schema = DATABASE()
          AND table_name = ?
    ");
    $stmt->execute([$tableName]);

    return (int) $stmt->fetchColumn() > 0;
}

function reminder_column_exists(PDO $pdo, string $tableName, string $columnName): bool
{
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM information_schema.columns
        WHERE table_schema = DATABASE()
          AND table_name = ?
          AND column_name = ?
    ");
    $stmt->execute([$tableName, $columnName]);

    return (int) $stmt->fetchColumn() > 0;
}

function reminder_ensure_column(PDO $pdo, string $tableName, string $columnName, string $definition): void
{
    if (reminder_column_exists($pdo, $tableName, $columnName)) {
        return;
    }

    $pdo->exec("ALTER TABLE `$tableName` ADD COLUMN `$columnName` $definition");
}

function reminder_schema_cache_version(): int
{
    return 1;
}

function reminder_schema_cache_ttl(): int
{
    return 21600;
}

function reminder_schema_cache_path(): string
{
    return ROOT_PATH
        . 'logs'
        . DIRECTORY_SEPARATOR
        . 'cache'
        . DIRECTORY_SEPARATOR
        . 'reminder-schema-state.json';
}

function reminder_read_schema_cache(): ?array
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        $sessionCache = $_SESSION['reminder_schema_state'] ?? null;
        if (is_array($sessionCache)) {
            $checkedAt = (int) ($sessionCache['checked_at'] ?? 0);
            $version = (int) ($sessionCache['version'] ?? 0);
            if (
                $checkedAt >= (time() - reminder_schema_cache_ttl())
                && $version === reminder_schema_cache_version()
            ) {
                return $sessionCache;
            }
        }
    }

    $cachePath = reminder_schema_cache_path();
    if (!is_file($cachePath)) {
        return null;
    }

    $raw = @file_get_contents($cachePath);
    if (!is_string($raw) || trim($raw) === '') {
        return null;
    }

    $payload = json_decode($raw, true);
    if (!is_array($payload)) {
        return null;
    }

    $checkedAt = (int) ($payload['checked_at'] ?? 0);
    $version = (int) ($payload['version'] ?? 0);
    if (
        $checkedAt < (time() - reminder_schema_cache_ttl())
        || $version !== reminder_schema_cache_version()
    ) {
        return null;
    }

    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION['reminder_schema_state'] = $payload;
    }

    return $payload;
}

function reminder_write_schema_cache(bool $ready): void
{
    $payload = [
        'ready' => $ready,
        'checked_at' => time(),
        'version' => reminder_schema_cache_version(),
    ];

    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION['reminder_schema_state'] = $payload;
    }

    $cachePath = reminder_schema_cache_path();
    $cacheDir = dirname($cachePath);
    if (!is_dir($cacheDir)) {
        @mkdir($cacheDir, 0777, true);
    }

    if (!is_dir($cacheDir)) {
        return;
    }

    $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return;
    }

    @file_put_contents($cachePath, $json, LOCK_EX);
}

function reminder_module_ready(PDO $pdo, bool $attemptCreate = false): bool
{
    static $knownState = null;

    if ($knownState === true) {
        return true;
    }
    if ($knownState === false && !$attemptCreate) {
        return false;
    }

    $cachedState = reminder_read_schema_cache();
    if (is_array($cachedState)) {
        $ready = !empty($cachedState['ready']);
        if ($ready || !$attemptCreate) {
            $knownState = $ready;
            return $ready;
        }
    }

    try {
        $exists = reminder_table_exists($pdo, 'reminders');
        if (!$exists && $attemptCreate) {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS reminders (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    user_id INT NOT NULL,
                    task_id INT DEFAULT NULL,
                    source ENUM('self', 'task_assignment') NOT NULL DEFAULT 'self',
                    title VARCHAR(255) NOT NULL,
                    note TEXT DEFAULT NULL,
                    priority ENUM('Low', 'Medium', 'High', 'Urgent') NOT NULL DEFAULT 'Medium',
                    due_on DATE DEFAULT NULL,
                    remind_at DATETIME DEFAULT NULL,
                    status ENUM('active', 'completed', 'dismissed') NOT NULL DEFAULT 'active',
                    auto_generated TINYINT(1) NOT NULL DEFAULT 0,
                    pinned TINYINT(1) NOT NULL DEFAULT 0,
                    created_by INT DEFAULT NULL,
                    completed_at DATETIME DEFAULT NULL,
                    dismissed_at DATETIME DEFAULT NULL,
                    last_synced_at DATETIME DEFAULT NULL,
                    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    UNIQUE KEY uniq_reminder_task_owner (user_id, task_id, source),
                    KEY idx_reminders_user_status (user_id, status),
                    KEY idx_reminders_due_on (due_on),
                    KEY idx_reminders_task_id (task_id),
                    KEY idx_reminders_source (source),
                    KEY idx_reminders_remind_at (remind_at),
                    CONSTRAINT fk_reminders_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                    CONSTRAINT fk_reminders_task FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
                    CONSTRAINT fk_reminders_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
            $exists = reminder_table_exists($pdo, 'reminders');
        }

        if ($exists) {
            reminder_ensure_column($pdo, 'reminders', 'alarm_enabled', "TINYINT(1) NOT NULL DEFAULT 1 AFTER remind_at");
            reminder_ensure_column($pdo, 'reminders', 'alarm_offset_minutes', "INT NOT NULL DEFAULT 30 AFTER alarm_enabled");
            reminder_ensure_column($pdo, 'reminders', 'alarm_last_triggered_at', "DATETIME DEFAULT NULL AFTER alarm_offset_minutes");
        }

        $knownState = $exists;
        reminder_write_schema_cache($exists);
        return $exists;
    } catch (Exception $e) {
        error_log('Reminder module bootstrap failed: ' . $e->getMessage());
        $knownState = false;
        reminder_write_schema_cache(false);
        return false;
    }
}

function normalize_reminder_date($value): ?string
{
    $value = trim((string) $value);
    if ($value === '') {
        return null;
    }

    $timestamp = strtotime($value);
    if ($timestamp === false) {
        return null;
    }

    return date('Y-m-d', $timestamp);
}

function normalize_reminder_datetime($value): ?string
{
    $value = trim((string) $value);
    if ($value === '') {
        return null;
    }

    $timestamp = strtotime($value);
    if ($timestamp === false) {
        return null;
    }

    return date('Y-m-d H:i:s', $timestamp);
}

function format_reminder_datetime_local(?string $value): string
{
    if (empty($value)) {
        return '';
    }

    $timestamp = strtotime($value);
    if ($timestamp === false) {
        return '';
    }

    return date('Y-m-d\TH:i', $timestamp);
}

function format_reminder_datetime_readable(?string $value): ?string
{
    if (empty($value)) {
        return null;
    }

    $timestamp = strtotime($value);
    if ($timestamp === false) {
        return null;
    }

    return date('M j, Y g:i A', $timestamp);
}

function reminder_alarm_offset_options(): array
{
    return [
        0 => 'At due time',
        5 => '5 minutes before',
        10 => '10 minutes before',
        15 => '15 minutes before',
        30 => '30 minutes before',
        60 => '1 hour before',
        120 => '2 hours before',
        180 => '3 hours before',
        720 => '12 hours before',
        1440 => '1 day before',
    ];
}

function normalize_alarm_offset_minutes($value): int
{
    $value = (int) $value;
    $options = array_keys(reminder_alarm_offset_options());

    return in_array($value, $options, true) ? $value : 30;
}

function format_alarm_offset_label(int $minutes): string
{
    $minutes = max(0, $minutes);

    if ($minutes === 0) {
        return 'At due time';
    }

    if ($minutes < 60) {
        return $minutes === 1 ? '1 minute before' : $minutes . ' minutes before';
    }

    if ($minutes % 1440 === 0) {
        $days = (int) ($minutes / 1440);
        return $days === 1 ? '1 day before' : $days . ' days before';
    }

    if ($minutes % 60 === 0) {
        $hours = (int) ($minutes / 60);
        return $hours === 1 ? '1 hour before' : $hours . ' hours before';
    }

    $hours = floor($minutes / 60);
    $remainingMinutes = $minutes % 60;
    $parts = [];

    if ($hours > 0) {
        $parts[] = $hours === 1 ? '1 hour' : $hours . ' hours';
    }

    if ($remainingMinutes > 0) {
        $parts[] = $remainingMinutes === 1 ? '1 minute' : $remainingMinutes . ' minutes';
    }

    return implode(' ', $parts) . ' before';
}

function reminder_effective_due_datetime(?string $dueOn, ?string $remindAt = null): ?string
{
    $remindAt = normalize_reminder_datetime($remindAt);
    if ($remindAt !== null) {
        return $remindAt;
    }

    $dueOn = normalize_reminder_date($dueOn);
    if ($dueOn === null) {
        return null;
    }

    // Task dates are date-only today, so alarms fall back to a stable morning checkpoint.
    return $dueOn . ' 09:00:00';
}

function normalize_reminder_priority($value): string
{
    $allowed = ['Low', 'Medium', 'High', 'Urgent'];
    $value = trim((string) $value);

    return in_array($value, $allowed, true) ? $value : 'Medium';
}

function reminder_alarm_meta(array $reminder): array
{
    $status = (string) ($reminder['status'] ?? 'active');
    $isEnabled = !isset($reminder['alarm_enabled']) || (int) $reminder['alarm_enabled'] === 1;
    $offsetMinutes = normalize_alarm_offset_minutes($reminder['alarm_offset_minutes'] ?? 30);
    $targetAt = reminder_effective_due_datetime($reminder['due_on'] ?? null, $reminder['remind_at'] ?? null);
    $usesFallbackTime = empty($reminder['remind_at']) && !empty($reminder['due_on']);

    if ($status !== 'active') {
        return [
            'enabled' => false,
            'offset_minutes' => $offsetMinutes,
            'offset_label' => format_alarm_offset_label($offsetMinutes),
            'trigger_at' => null,
            'trigger_at_display' => null,
            'uses_fallback_time' => $usesFallbackTime,
            'label' => $status === 'completed' ? 'Alarm completed' : 'Alarm archived',
            'detail' => $status === 'completed'
                ? 'Completed reminders do not ring again unless they are reopened.'
                : 'Archived reminders stay silent until moved back to active.',
            'badge_class' => 'bg-slate-100 text-slate-600',
        ];
    }

    if (!$isEnabled) {
        return [
            'enabled' => false,
            'offset_minutes' => $offsetMinutes,
            'offset_label' => format_alarm_offset_label($offsetMinutes),
            'trigger_at' => null,
            'trigger_at_display' => null,
            'uses_fallback_time' => $usesFallbackTime,
            'label' => 'Alarm off',
            'detail' => 'No pre-due alarm will ring for this card.',
            'badge_class' => 'bg-slate-100 text-slate-600',
        ];
    }

    if ($targetAt === null) {
        return [
            'enabled' => true,
            'offset_minutes' => $offsetMinutes,
            'offset_label' => format_alarm_offset_label($offsetMinutes),
            'trigger_at' => null,
            'trigger_at_display' => null,
            'uses_fallback_time' => false,
            'label' => 'Alarm pending schedule',
            'detail' => 'Set a due date first so the alarm can be scheduled.',
            'badge_class' => 'bg-amber-100 text-amber-700',
        ];
    }

    $triggerTimestamp = strtotime($targetAt) - ($offsetMinutes * 60);
    $triggerAt = date('Y-m-d H:i:s', $triggerTimestamp);
    $detail = 'Rings ' . date('M j, g:i A', $triggerTimestamp) . '.';

    if ($usesFallbackTime) {
        $detail .= ' Date-only task reminders use 9:00 AM on the due date until timed tasks are introduced.';
    }

    return [
        'enabled' => true,
        'offset_minutes' => $offsetMinutes,
        'offset_label' => format_alarm_offset_label($offsetMinutes),
        'trigger_at' => $triggerAt,
        'trigger_at_display' => format_reminder_datetime_readable($triggerAt),
        'uses_fallback_time' => $usesFallbackTime,
        'label' => format_alarm_offset_label($offsetMinutes),
        'detail' => $detail,
        'badge_class' => 'bg-violet-100 text-violet-700',
    ];
}

function reminder_due_meta(?string $dueOn, ?string $remindAt = null, string $status = 'active'): array
{
    if ($status === 'completed') {
        return [
            'label' => 'Completed',
            'compact_label' => 'Completed',
            'tone' => 'success',
            'badge_class' => 'bg-emerald-100 text-emerald-700',
            'sort_weight' => 4,
        ];
    }

    if ($status === 'dismissed') {
        return [
            'label' => 'Archived',
            'compact_label' => 'Archived',
            'tone' => 'neutral',
            'badge_class' => 'bg-slate-100 text-slate-600',
            'sort_weight' => 5,
        ];
    }

    if (!empty($remindAt)) {
        $targetTimestamp = strtotime($remindAt);
        if ($targetTimestamp === false) {
            $remindAt = null;
        } else {
            $today = date('Y-m-d');
            $tomorrow = date('Y-m-d', strtotime('+1 day'));
            $targetDay = date('Y-m-d', $targetTimestamp);
            $timeLabel = date('g:i A', $targetTimestamp);

            if ($targetTimestamp < time()) {
                $fullLabel = $targetDay === $today
                    ? 'Overdue since ' . $timeLabel
                    : 'Overdue since ' . date('M j, g:i A', $targetTimestamp);
                $compactLabel = $targetDay === $today
                    ? 'Overdue ' . $timeLabel
                    : 'Overdue ' . date('M j', $targetTimestamp);

                return [
                    'label' => $fullLabel,
                    'compact_label' => $compactLabel,
                    'tone' => 'danger',
                    'badge_class' => 'bg-rose-100 text-rose-700',
                    'sort_weight' => 0,
                ];
            }

            if ($targetDay === $today) {
                return [
                    'label' => 'Today at ' . $timeLabel,
                    'compact_label' => 'Today ' . $timeLabel,
                    'tone' => 'warning',
                    'badge_class' => 'bg-amber-100 text-amber-700',
                    'sort_weight' => 1,
                ];
            }

            if ($targetDay === $tomorrow) {
                return [
                    'label' => 'Tomorrow at ' . $timeLabel,
                    'compact_label' => 'Tomorrow ' . $timeLabel,
                    'tone' => 'info',
                    'badge_class' => 'bg-blue-100 text-blue-700',
                    'sort_weight' => 2,
                ];
            }

            return [
                'label' => 'Due ' . date('M j, Y g:i A', $targetTimestamp),
                'compact_label' => date('M j, g:i A', $targetTimestamp),
                'tone' => 'info',
                'badge_class' => 'bg-blue-100 text-blue-700',
                'sort_weight' => 2,
            ];
        }
    }

    if (empty($dueOn)) {
        return [
            'label' => 'No target date',
            'compact_label' => 'No date',
            'tone' => 'neutral',
            'badge_class' => 'bg-slate-100 text-slate-600',
            'sort_weight' => 3,
        ];
    }

    $today = strtotime(date('Y-m-d'));
    $dueDay = strtotime(date('Y-m-d', strtotime($dueOn)));
    $dayDelta = (int) round(($dueDay - $today) / 86400);

    if ($dayDelta < 0) {
        $daysLate = abs($dayDelta);
        return [
            'label' => $daysLate === 1 ? '1 day overdue' : $daysLate . ' days overdue',
            'compact_label' => 'Overdue',
            'tone' => 'danger',
            'badge_class' => 'bg-rose-100 text-rose-700',
            'sort_weight' => 0,
        ];
    }

    if ($dayDelta === 0) {
        return [
            'label' => 'Due today',
            'compact_label' => 'Today',
            'tone' => 'warning',
            'badge_class' => 'bg-amber-100 text-amber-700',
            'sort_weight' => 1,
        ];
    }

    if ($dayDelta === 1) {
        return [
            'label' => 'Due tomorrow',
            'compact_label' => 'Tomorrow',
            'tone' => 'info',
            'badge_class' => 'bg-blue-100 text-blue-700',
            'sort_weight' => 2,
        ];
    }

    return [
        'label' => 'Due ' . date('M j, Y', strtotime($dueOn)),
        'compact_label' => date('M j', strtotime($dueOn)),
        'tone' => 'info',
        'badge_class' => 'bg-blue-100 text-blue-700',
        'sort_weight' => 2,
    ];
}

function reminder_priority_badge_class(string $priority): string
{
    $map = [
        'Low' => 'bg-slate-100 text-slate-600',
        'Medium' => 'bg-blue-100 text-blue-700',
        'High' => 'bg-amber-100 text-amber-700',
        'Urgent' => 'bg-rose-100 text-rose-700',
    ];

    return $map[$priority] ?? $map['Medium'];
}

function reminder_source_label(array $reminder): string
{
    return ($reminder['source'] ?? 'self') === 'task_assignment' ? 'Task-linked' : 'Personal';
}

function decorate_reminder_row(array $row): array
{
    $row['alarm_enabled'] = isset($row['alarm_enabled']) ? (int) $row['alarm_enabled'] : 1;
    $row['alarm_offset_minutes'] = normalize_alarm_offset_minutes($row['alarm_offset_minutes'] ?? 30);
    $row['due_meta'] = reminder_due_meta($row['due_on'] ?? null, $row['remind_at'] ?? null, $row['status'] ?? 'active');
    $row['priority_badge_class'] = reminder_priority_badge_class($row['priority'] ?? 'Medium');
    $row['source_label'] = reminder_source_label($row);
    $row['is_task_linked'] = ($row['source'] ?? 'self') === 'task_assignment';
    $row['can_edit'] = !$row['is_task_linked'];
    $row['is_personal'] = !$row['is_task_linked'];
    $row['remind_at_local'] = format_reminder_datetime_local($row['remind_at'] ?? null);
    $row['remind_at_display'] = format_reminder_datetime_readable($row['remind_at'] ?? null);
    $row['alarm_meta'] = reminder_alarm_meta($row);

    return $row;
}

function personal_reminder_last_action_at(array $reminder): ?string
{
    $status = (string) ($reminder['status'] ?? 'active');

    if ($status === 'completed') {
        return $reminder['completed_at'] ?? null;
    }

    if ($status === 'dismissed') {
        return $reminder['dismissed_at'] ?? null;
    }

    return null;
}

function personal_reminder_has_changes_since_action(array $reminder): bool
{
    $status = (string) ($reminder['status'] ?? 'active');
    if (!in_array($status, ['completed', 'dismissed'], true)) {
        return false;
    }

    return has_workflow_changes_after(
        $reminder['updated_at'] ?? null,
        personal_reminder_last_action_at($reminder)
    );
}

function build_task_assignment_reminder_note(array $task, array $assignee): string
{
    $parts = [];

    if (!empty($task['project_name'])) {
        $parts[] = 'Project: ' . $task['project_name'];
    }

    $parts[] = !empty($assignee['is_primary']) ? 'You are the primary owner.' : 'You are supporting delivery on this task.';
    $parts[] = 'Current task status: ' . ($task['status'] ?? 'Not Started') . '.';

    if (!empty($task['due_date'])) {
        $parts[] = 'Target date: ' . date('M j, Y', strtotime($task['due_date'])) . '.';
    } else {
        $parts[] = 'No target date has been set yet.';
    }

    return implode(' ', $parts);
}

function reminder_task_sync_status(array $task): array
{
    $taskStatus = $task['status'] ?? 'Not Started';

    if ($taskStatus === 'Completed') {
        return [
            'status' => 'completed',
            'completed_at' => !empty($task['completed_at']) ? $task['completed_at'] : date('Y-m-d H:i:s'),
            'dismissed_at' => null,
        ];
    }

    if ($taskStatus === 'Cancelled') {
        return [
            'status' => 'dismissed',
            'completed_at' => null,
            'dismissed_at' => date('Y-m-d H:i:s'),
        ];
    }

    return [
        'status' => 'active',
        'completed_at' => null,
        'dismissed_at' => null,
    ];
}

function sync_task_assignment_reminders_for_task(PDO $pdo, int $taskId, int $actorId = 0): void
{
    if ($taskId <= 0 || !reminder_module_ready($pdo, true)) {
        return;
    }

    $task = fetch_task_workflow_context($pdo, $taskId);
    if (!$task) {
        return;
    }

    $assignees = $task['task_assignees'] ?? fetch_task_assignees($pdo, $taskId);
    $activeAssigneeIds = [];
    $syncState = reminder_task_sync_status($task);

    $selectStmt = $pdo->prepare("
        SELECT id
        FROM reminders
        WHERE user_id = ? AND task_id = ? AND source = 'task_assignment'
        LIMIT 1
    ");
    $insertStmt = $pdo->prepare("
        INSERT INTO reminders (
            user_id, task_id, source, title, note, priority, due_on, remind_at, status,
            auto_generated, pinned, created_by, completed_at, dismissed_at, last_synced_at,
            alarm_enabled, alarm_offset_minutes
        )
        VALUES (?, ?, 'task_assignment', ?, ?, ?, ?, ?, ?, 1, 0, ?, ?, ?, NOW(), 1, 30)
    ");
    $updateStmt = $pdo->prepare("
        UPDATE reminders
        SET alarm_last_triggered_at = CASE
                WHEN COALESCE(due_on, '1000-01-01') <> COALESCE(?, '1000-01-01')
                  OR COALESCE(remind_at, '1000-01-01 00:00:00') <> COALESCE(?, '1000-01-01 00:00:00')
                  OR status <> ?
                THEN NULL
                ELSE alarm_last_triggered_at
            END,
            title = ?,
            note = ?,
            priority = ?,
            due_on = ?,
            remind_at = ?,
            status = ?,
            auto_generated = 1,
            completed_at = ?,
            dismissed_at = ?,
            last_synced_at = NOW()
        WHERE id = ?
    ");

    foreach ($assignees as $assignee) {
        $assigneeId = (int) ($assignee['user_id'] ?? 0);
        if ($assigneeId <= 0) {
            continue;
        }

        $activeAssigneeIds[] = $assigneeId;
        $title = trim((string) ($task['name'] ?? 'Assigned task'));
        $note = build_task_assignment_reminder_note($task, $assignee);
        $priority = normalize_reminder_priority($task['priority'] ?? 'Medium');
        $dueOn = normalize_reminder_date($task['due_date'] ?? null);
        $remindAt = null;

        $selectStmt->execute([$assigneeId, $taskId]);
        $existingId = (int) $selectStmt->fetchColumn();

        if ($existingId > 0) {
            $updateStmt->execute([
                $dueOn,
                $remindAt,
                $syncState['status'],
                $title,
                $note,
                $priority,
                $dueOn,
                $remindAt,
                $syncState['status'],
                $syncState['completed_at'],
                $syncState['dismissed_at'],
                $existingId,
            ]);
            continue;
        }

        $insertStmt->execute([
            $assigneeId,
            $taskId,
            $title,
            $note,
            $priority,
            $dueOn,
            $remindAt,
            $syncState['status'],
            $actorId > 0 ? $actorId : null,
            $syncState['completed_at'],
            $syncState['dismissed_at'],
        ]);
    }

    $dismissSql = "
        UPDATE reminders
        SET status = 'dismissed',
            dismissed_at = NOW(),
            completed_at = NULL,
            last_synced_at = NOW()
        WHERE task_id = ?
          AND source = 'task_assignment'
    ";
    $dismissParams = [$taskId];

    if (!empty($activeAssigneeIds)) {
        $placeholders = implode(',', array_fill(0, count($activeAssigneeIds), '?'));
        $dismissSql .= " AND user_id NOT IN ($placeholders)";
        $dismissParams = array_merge($dismissParams, $activeAssigneeIds);
    }

    $dismissStmt = $pdo->prepare($dismissSql);
    $dismissStmt->execute($dismissParams);
}

function backfill_task_assignment_reminders_for_user(PDO $pdo, int $userId): void
{
    if ($userId <= 0 || !reminder_module_ready($pdo, true)) {
        return;
    }

    $stmt = $pdo->prepare("
        SELECT DISTINCT t.id
        FROM tasks t
        WHERE t.assigned_to = :user_id
           OR EXISTS (
                SELECT 1
                FROM task_assignees ta
                WHERE ta.task_id = t.id AND ta.user_id = :user_id_shadow
           )
        ORDER BY t.updated_at DESC, t.id DESC
        LIMIT 200
    ");
    $stmt->execute([
        'user_id' => $userId,
        'user_id_shadow' => $userId,
    ]);

    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $taskId) {
        sync_task_assignment_reminders_for_task($pdo, (int) $taskId);
    }
}

function fetch_reminder_counts_for_user(PDO $pdo, int $userId): array
{
    if ($userId <= 0 || !reminder_module_ready($pdo)) {
        return [
            'active' => 0,
            'due_today' => 0,
            'overdue' => 0,
            'task_linked' => 0,
            'personal' => 0,
            'completed' => 0,
            'dismissed' => 0,
        ];
    }

    $stmt = $pdo->prepare("
        SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) AS active_count,
            SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS completed_count,
            SUM(CASE WHEN status = 'dismissed' THEN 1 ELSE 0 END) AS dismissed_count,
            SUM(CASE
                WHEN status = 'active'
                 AND (
                    (remind_at IS NOT NULL AND DATE(remind_at) = CURDATE() AND remind_at >= NOW())
                    OR (remind_at IS NULL AND due_on = CURDATE())
                 )
                THEN 1 ELSE 0 END) AS due_today_count,
            SUM(CASE
                WHEN status = 'active'
                 AND (
                    (remind_at IS NOT NULL AND remind_at < NOW())
                    OR (remind_at IS NULL AND due_on IS NOT NULL AND due_on < CURDATE())
                 )
                THEN 1 ELSE 0 END) AS overdue_count,
            SUM(CASE WHEN source = 'task_assignment' AND status = 'active' THEN 1 ELSE 0 END) AS task_linked_count,
            SUM(CASE WHEN source = 'self' AND status = 'active' THEN 1 ELSE 0 END) AS personal_count
        FROM reminders
        WHERE user_id = ?
    ");
    $stmt->execute([$userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    return [
        'active' => (int) ($row['active_count'] ?? 0),
        'due_today' => (int) ($row['due_today_count'] ?? 0),
        'overdue' => (int) ($row['overdue_count'] ?? 0),
        'task_linked' => (int) ($row['task_linked_count'] ?? 0),
        'personal' => (int) ($row['personal_count'] ?? 0),
        'completed' => (int) ($row['completed_count'] ?? 0),
        'dismissed' => (int) ($row['dismissed_count'] ?? 0),
    ];
}

function fetch_reminders_for_user(PDO $pdo, int $userId, array $filters = []): array
{
    if ($userId <= 0 || !reminder_module_ready($pdo)) {
        return [];
    }

    $status = trim((string) ($filters['status'] ?? 'active'));
    $source = trim((string) ($filters['source'] ?? 'all'));
    $search = trim((string) ($filters['search'] ?? ''));
    $limit = max(1, min(200, (int) ($filters['limit'] ?? 50)));

    $sql = "
        SELECT
            r.*,
            t.status AS task_status,
            t.project_id,
            p.name AS project_name
        FROM reminders r
        LEFT JOIN tasks t ON t.id = r.task_id
        LEFT JOIN projects p ON p.id = t.project_id
        WHERE r.user_id = :user_id
    ";
    $params = ['user_id' => $userId];

    if ($status !== 'all') {
        $sql .= " AND r.status = :status";
        $params['status'] = $status;
    }

    if (in_array($source, ['self', 'task_assignment'], true)) {
        $sql .= " AND r.source = :source";
        $params['source'] = $source;
    }

    if ($search !== '') {
        $sql .= " AND (r.title LIKE :search OR r.note LIKE :search OR p.name LIKE :search)";
        $params['search'] = '%' . $search . '%';
    }

    $sql .= "
        ORDER BY
            CASE
                WHEN r.status = 'active'
                 AND (
                    (r.remind_at IS NOT NULL AND r.remind_at < NOW())
                    OR (r.remind_at IS NULL AND r.due_on IS NOT NULL AND r.due_on < CURDATE())
                 ) THEN 0
                WHEN r.status = 'active'
                 AND (
                    (r.remind_at IS NOT NULL AND DATE(r.remind_at) = CURDATE())
                    OR (r.remind_at IS NULL AND r.due_on = CURDATE())
                 ) THEN 1
                WHEN r.status = 'active' THEN 2
                WHEN r.status = 'completed' THEN 3
                ELSE 4
            END ASC,
            r.pinned DESC,
            CASE WHEN COALESCE(r.remind_at, r.due_on) IS NULL THEN 1 ELSE 0 END ASC,
            COALESCE(r.remind_at, CONCAT(r.due_on, ' 23:59:59')) ASC,
            r.updated_at DESC
        LIMIT $limit
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as &$row) {
        $row = decorate_reminder_row($row);
    }
    unset($row);

    return $rows;
}

function fetch_user_reminder(PDO $pdo, int $userId, int $reminderId): ?array
{
    if ($userId <= 0 || $reminderId <= 0 || !reminder_module_ready($pdo)) {
        return null;
    }

    $stmt = $pdo->prepare("
        SELECT *
        FROM reminders
        WHERE id = ? AND user_id = ?
        LIMIT 1
    ");
    $stmt->execute([$reminderId, $userId]);
    $reminder = $stmt->fetch(PDO::FETCH_ASSOC);

    return $reminder ? decorate_reminder_row($reminder) : null;
}

function fetch_personal_reminder(PDO $pdo, int $userId, int $reminderId): ?array
{
    if ($userId <= 0 || $reminderId <= 0 || !reminder_module_ready($pdo)) {
        return null;
    }

    $stmt = $pdo->prepare("
        SELECT *
        FROM reminders
        WHERE id = ? AND user_id = ? AND source = 'self'
        LIMIT 1
    ");
    $stmt->execute([$reminderId, $userId]);
    $reminder = $stmt->fetch(PDO::FETCH_ASSOC);

    return $reminder ? decorate_reminder_row($reminder) : null;
}

function save_personal_reminder(PDO $pdo, int $userId, array $payload, ?int $reminderId = null): int
{
    if ($userId <= 0 || !reminder_module_ready($pdo, true)) {
        throw new RuntimeException('Reminder module is not ready.');
    }

    $title = trim((string) ($payload['title'] ?? ''));
    if ($title === '') {
        throw new RuntimeException('Reminder title is required.');
    }

    $title = substr($title, 0, 255);
    $note = trim((string) ($payload['note'] ?? ''));
    $note = $note !== '' ? $note : null;
    $priority = normalize_reminder_priority($payload['priority'] ?? 'Medium');
    $remindAt = normalize_reminder_datetime($payload['remind_at'] ?? null);
    $dueOn = $remindAt !== null ? date('Y-m-d', strtotime($remindAt)) : null;
    $pinned = !empty($payload['pinned']) ? 1 : 0;
    $alarmEnabled = !empty($payload['alarm_enabled']) ? 1 : 0;
    if ($alarmEnabled && $remindAt === null) {
        throw new RuntimeException('Choose a due date and time before enabling reminder alarms.');
    }
    $alarmOffsetMinutes = normalize_alarm_offset_minutes($payload['alarm_offset_minutes'] ?? 30);

    if ($reminderId !== null) {
        $existing = fetch_personal_reminder($pdo, $userId, $reminderId);
        if (!$existing) {
            throw new RuntimeException('Reminder not found.');
        }

        $stmt = $pdo->prepare("
            UPDATE reminders
            SET title = ?,
                note = ?,
                priority = ?,
                due_on = ?,
                remind_at = ?,
                pinned = ?,
                alarm_enabled = ?,
                alarm_offset_minutes = ?,
                alarm_last_triggered_at = NULL
            WHERE id = ? AND user_id = ? AND source = 'self'
        ");
        $stmt->execute([
            $title,
            $note,
            $priority,
            $dueOn,
            $remindAt,
            $pinned,
            $alarmEnabled,
            $alarmOffsetMinutes,
            $reminderId,
            $userId,
        ]);

        return $reminderId;
    }

    $stmt = $pdo->prepare("
        INSERT INTO reminders (
            user_id, task_id, source, title, note, priority, due_on, remind_at, status,
            auto_generated, pinned, created_by, alarm_enabled, alarm_offset_minutes
        )
        VALUES (?, NULL, 'self', ?, ?, ?, ?, ?, 'active', 0, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $userId,
        $title,
        $note,
        $priority,
        $dueOn,
        $remindAt,
        $pinned,
        $userId,
        $alarmEnabled,
        $alarmOffsetMinutes,
    ]);

    return (int) $pdo->lastInsertId();
}

function update_reminder_alarm_settings(PDO $pdo, int $userId, int $reminderId, array $payload): void
{
    if ($userId <= 0 || $reminderId <= 0 || !reminder_module_ready($pdo, true)) {
        throw new RuntimeException('Reminder module is not ready.');
    }

    $reminder = fetch_user_reminder($pdo, $userId, $reminderId);
    if (!$reminder) {
        throw new RuntimeException('Reminder not found.');
    }

    $alarmEnabled = !empty($payload['alarm_enabled']) ? 1 : 0;
    $alarmOffsetMinutes = normalize_alarm_offset_minutes($payload['alarm_offset_minutes'] ?? 30);

    if ($alarmEnabled && reminder_effective_due_datetime($reminder['due_on'] ?? null, $reminder['remind_at'] ?? null) === null) {
        throw new RuntimeException('Add a due date before enabling reminder alarms.');
    }

    $stmt = $pdo->prepare("
        UPDATE reminders
        SET alarm_enabled = ?,
            alarm_offset_minutes = ?,
            alarm_last_triggered_at = NULL
        WHERE id = ? AND user_id = ?
    ");
    $stmt->execute([$alarmEnabled, $alarmOffsetMinutes, $reminderId, $userId]);
}

function update_personal_reminder_status(PDO $pdo, int $userId, int $reminderId, string $action): void
{
    if ($userId <= 0 || $reminderId <= 0 || !reminder_module_ready($pdo)) {
        throw new RuntimeException('Reminder module is not ready.');
    }

    $reminder = fetch_personal_reminder($pdo, $userId, $reminderId);
    if (!$reminder) {
        throw new RuntimeException('Reminder not found.');
    }

    if ($action === 'complete') {
        if (($reminder['status'] ?? 'active') === 'completed' && !personal_reminder_has_changes_since_action($reminder)) {
            throw new RuntimeException('Update the reminder before marking it complete again.');
        }

        $stmt = $pdo->prepare("
            UPDATE reminders
            SET status = 'completed',
                completed_at = NOW(),
                dismissed_at = NULL
            WHERE id = ? AND user_id = ? AND source = 'self'
        ");
        $stmt->execute([$reminderId, $userId]);
        return;
    }

    if ($action === 'reopen') {
        $stmt = $pdo->prepare("
            UPDATE reminders
            SET status = 'active',
                completed_at = NULL,
                dismissed_at = NULL,
                alarm_last_triggered_at = NULL
            WHERE id = ? AND user_id = ? AND source = 'self'
        ");
        $stmt->execute([$reminderId, $userId]);
        return;
    }

    if ($action === 'dismiss') {
        if (($reminder['status'] ?? 'active') === 'dismissed' && !personal_reminder_has_changes_since_action($reminder)) {
            throw new RuntimeException('Update the reminder before archiving it again.');
        }

        $stmt = $pdo->prepare("
            UPDATE reminders
            SET status = 'dismissed',
                completed_at = NULL,
                dismissed_at = NOW()
            WHERE id = ? AND user_id = ? AND source = 'self'
        ");
        $stmt->execute([$reminderId, $userId]);
        return;
    }

    throw new RuntimeException('Invalid reminder action.');
}

function delete_personal_reminder(PDO $pdo, int $userId, int $reminderId): void
{
    if ($userId <= 0 || $reminderId <= 0 || !reminder_module_ready($pdo)) {
        throw new RuntimeException('Reminder module is not ready.');
    }

    $reminder = fetch_personal_reminder($pdo, $userId, $reminderId);
    if (!$reminder) {
        throw new RuntimeException('Reminder not found.');
    }

    $stmt = $pdo->prepare("
        DELETE FROM reminders
        WHERE id = ? AND user_id = ? AND source = 'self'
        LIMIT 1
    ");
    $stmt->execute([$reminderId, $userId]);
}

function snooze_personal_reminder(PDO $pdo, int $userId, int $reminderId, int $minutes = 10): void
{
    if ($userId <= 0 || $reminderId <= 0 || !reminder_module_ready($pdo)) {
        throw new RuntimeException('Reminder module is not ready.');
    }

    $reminder = fetch_personal_reminder($pdo, $userId, $reminderId);
    if (!$reminder) {
        throw new RuntimeException('Reminder not found.');
    }

    $minutes = max(5, min(1440, (int) $minutes));
    $baseAt = reminder_effective_due_datetime($reminder['due_on'] ?? null, $reminder['remind_at'] ?? null);
    $baseTimestamp = $baseAt ? strtotime($baseAt) : false;
    $anchorTimestamp = $baseTimestamp !== false && $baseTimestamp > time() ? $baseTimestamp : time();
    $snoozedTimestamp = $anchorTimestamp + ($minutes * 60);
    $snoozedAt = date('Y-m-d H:i:s', $snoozedTimestamp);

    $stmt = $pdo->prepare("
        UPDATE reminders
        SET status = 'active',
            due_on = ?,
            remind_at = ?,
            completed_at = NULL,
            dismissed_at = NULL,
            alarm_last_triggered_at = NULL
        WHERE id = ? AND user_id = ? AND source = 'self'
    ");
    $stmt->execute([
        date('Y-m-d', $snoozedTimestamp),
        $snoozedAt,
        $reminderId,
        $userId,
    ]);
}

function fetch_due_reminder_alarms(PDO $pdo, int $userId, int $limit = 10): array
{
    if ($userId <= 0 || !reminder_module_ready($pdo, true)) {
        return [];
    }

    $limit = max(1, min(25, $limit));
    $targetExpression = "COALESCE(r.remind_at, CASE WHEN r.due_on IS NOT NULL THEN CONCAT(r.due_on, ' 09:00:00') ELSE NULL END)";
    $alarmExpression = "TIMESTAMPADD(MINUTE, -COALESCE(r.alarm_offset_minutes, 30), $targetExpression)";

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("
            SELECT
                r.*,
                t.project_id,
                p.name AS project_name,
                $targetExpression AS alarm_target_at,
                $alarmExpression AS alarm_trigger_at
            FROM reminders r
            LEFT JOIN tasks t ON t.id = r.task_id
            LEFT JOIN projects p ON p.id = t.project_id
            WHERE r.user_id = :user_id
              AND r.status = 'active'
              AND COALESCE(r.alarm_enabled, 1) = 1
              AND r.alarm_last_triggered_at IS NULL
              AND $targetExpression IS NOT NULL
              AND $alarmExpression <= NOW()
            ORDER BY alarm_trigger_at ASC, r.updated_at DESC
            LIMIT $limit
            FOR UPDATE
        ");
        $stmt->execute(['user_id' => $userId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($rows)) {
            $pdo->commit();
            return [];
        }

        $reminderIds = array_map(static function (array $row): int {
            return (int) $row['id'];
        }, $rows);
        $placeholders = implode(',', array_fill(0, count($reminderIds), '?'));
        $updateStmt = $pdo->prepare("
            UPDATE reminders
            SET alarm_last_triggered_at = NOW()
            WHERE user_id = ? AND id IN ($placeholders)
        ");
        $updateStmt->execute(array_merge([$userId], $reminderIds));

        $pdo->commit();
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    $alarms = [];
    foreach ($rows as $row) {
        $row = decorate_reminder_row($row);
        $alarms[] = [
            'id' => (int) $row['id'],
            'title' => $row['title'] ?? 'Reminder',
            'note' => $row['note'] ?? '',
            'source' => $row['source'] ?? 'self',
            'source_label' => $row['source_label'],
            'project_name' => $row['project_name'] ?? null,
            'due_label' => $row['due_meta']['label'] ?? 'No target date',
            'alarm_label' => $row['alarm_meta']['label'] ?? format_alarm_offset_label($row['alarm_offset_minutes']),
            'alarm_detail' => $row['alarm_meta']['detail'] ?? '',
            'alarm_trigger_at' => $row['alarm_trigger_at'] ?? null,
            'href' => !empty($row['task_id'])
                ? BASE_URL . 'modules/tasks/view?id=' . (int) $row['task_id']
                : BASE_URL . 'modules/reminders/index?detail=' . (int) $row['id'],
            'priority' => $row['priority'] ?? 'Medium',
        ];
    }

    return $alarms;
}

function reminder_effective_calendar_day(?string $dueOn, ?string $remindAt): ?string
{
    $remindAt = trim((string) $remindAt);
    if ($remindAt !== '') {
        $ts = strtotime($remindAt);
        if ($ts !== false) {
            return date('Y-m-d', $ts);
        }
    }

    return normalize_reminder_date($dueOn);
}

/**
 * Reminders that fall on a calendar day within [startDate, endDate] (inclusive).
 * Calendar day is COALESCE(DATE(remind_at), due_on).
 *
 * @param array $filters status: active|all; source: all|self|task_assignment
 */
function fetch_reminders_for_calendar_range(PDO $pdo, int $userId, string $startDate, string $endDate, array $filters = []): array
{
    if ($userId <= 0 || !reminder_module_ready($pdo)) {
        return [];
    }

    $status = trim((string) ($filters['status'] ?? 'active'));
    if (!in_array($status, ['active', 'all'], true)) {
        $status = 'active';
    }

    $source = trim((string) ($filters['source'] ?? 'all'));
    if (!in_array($source, ['all', 'self', 'task_assignment'], true)) {
        $source = 'all';
    }

    $search = trim((string) ($filters['search'] ?? ''));

    $sql = "
        SELECT
            r.*,
            t.status AS task_status,
            t.project_id,
            p.name AS project_name,
            COALESCE(DATE(r.remind_at), r.due_on) AS calendar_day
        FROM reminders r
        LEFT JOIN tasks t ON t.id = r.task_id
        LEFT JOIN projects p ON p.id = t.project_id
        WHERE r.user_id = :user_id
          AND COALESCE(DATE(r.remind_at), r.due_on) IS NOT NULL
          AND COALESCE(DATE(r.remind_at), r.due_on) BETWEEN :start_date AND :end_date
    ";
    $params = [
        'user_id' => $userId,
        'start_date' => $startDate,
        'end_date' => $endDate,
    ];

    if ($status !== 'all') {
        $sql .= " AND r.status = 'active'";
    }

    if (in_array($source, ['self', 'task_assignment'], true)) {
        $sql .= " AND r.source = :source";
        $params['source'] = $source;
    }

    if ($search !== '') {
        $sql .= " AND (r.title LIKE :search OR r.note LIKE :search OR p.name LIKE :search)";
        $params['search'] = '%' . $search . '%';
    }

    $sql .= "
        ORDER BY
            calendar_day ASC,
            CASE WHEN r.remind_at IS NOT NULL THEN r.remind_at ELSE CONCAT(r.due_on, ' 23:59:59') END ASC,
            r.pinned DESC,
            r.updated_at DESC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as &$row) {
        $row = decorate_reminder_row($row);
        $row['calendar_day'] = $row['calendar_day'] ?? reminder_effective_calendar_day($row['due_on'] ?? null, $row['remind_at'] ?? null);
    }
    unset($row);

    return $rows;
}

/**
 * Group calendar rows by Y-m-d for quick lookups.
 *
 * @return array<string, list<array>>
 */
function group_reminders_by_calendar_day(array $rows): array
{
    $map = [];
    foreach ($rows as $row) {
        $day = (string) ($row['calendar_day'] ?? '');
        if ($day === '') {
            continue;
        }
        if (!isset($map[$day])) {
            $map[$day] = [];
        }
        $map[$day][] = $row;
    }

    ksort($map);

    return $map;
}

/**
 * Count active reminders with alarms enabled and a schedulable target datetime.
 */
function count_scheduled_active_alarms(PDO $pdo, int $userId): int
{
    if ($userId <= 0 || !reminder_module_ready($pdo)) {
        return 0;
    }

    $targetExpression = "COALESCE(r.remind_at, CASE WHEN r.due_on IS NOT NULL THEN CONCAT(r.due_on, ' 09:00:00') ELSE NULL END)";

    $stmt = $pdo->prepare("
        SELECT COUNT(*) AS c
        FROM reminders r
        WHERE r.user_id = ?
          AND r.status = 'active'
          AND COALESCE(r.alarm_enabled, 1) = 1
          AND $targetExpression IS NOT NULL
    ");
    $stmt->execute([$userId]);

    return (int) $stmt->fetchColumn();
}

/**
 * Upcoming alarm trigger moments (future only), for Alarm Center preview.
 *
 * @return list<array{id:int,title:string,source:string,trigger_at:string,trigger_display:string,offset_label:string,href:string}>
 */
function fetch_upcoming_alarm_schedule(PDO $pdo, int $userId, int $limit = 20): array
{
    if ($userId <= 0 || !reminder_module_ready($pdo)) {
        return [];
    }

    $limit = max(1, min(50, $limit));
    $targetExpression = "COALESCE(r.remind_at, CASE WHEN r.due_on IS NOT NULL THEN CONCAT(r.due_on, ' 09:00:00') ELSE NULL END)";
    $alarmExpression = "TIMESTAMPADD(MINUTE, -COALESCE(r.alarm_offset_minutes, 30), $targetExpression)";

    $stmt = $pdo->prepare("
        SELECT
            r.*,
            t.project_id,
            p.name AS project_name,
            $targetExpression AS alarm_target_at,
            $alarmExpression AS alarm_trigger_at
        FROM reminders r
        LEFT JOIN tasks t ON t.id = r.task_id
        LEFT JOIN projects p ON p.id = t.project_id
        WHERE r.user_id = ?
          AND r.status = 'active'
          AND COALESCE(r.alarm_enabled, 1) = 1
          AND $targetExpression IS NOT NULL
          AND $alarmExpression > NOW()
        ORDER BY $alarmExpression ASC
        LIMIT $limit
    ");
    $stmt->execute([$userId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $out = [];
    foreach ($rows as $row) {
        $row = decorate_reminder_row($row);
        $triggerAt = $row['alarm_trigger_at'] ?? null;
        if (empty($triggerAt)) {
            continue;
        }
        $rid = (int) ($row['id'] ?? 0);
        $out[] = [
            'id' => $rid,
            'title' => (string) ($row['title'] ?? 'Reminder'),
            'source' => (string) ($row['source'] ?? 'self'),
            'source_label' => $row['source_label'] ?? reminder_source_label($row),
            'project_name' => $row['project_name'] ?? null,
            'trigger_at' => $triggerAt,
            'trigger_display' => format_reminder_datetime_readable($triggerAt) ?? $triggerAt,
            'offset_label' => format_alarm_offset_label((int) ($row['alarm_offset_minutes'] ?? 30)),
            'href' => !empty($row['task_id'])
                ? BASE_URL . 'modules/tasks/view?id=' . (int) $row['task_id']
                : BASE_URL . 'modules/reminders/index?detail=' . $rid,
        ];
    }

    return $out;
}
