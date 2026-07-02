<?php

require_once __DIR__ . '/../../config/app.php';
checkAuth();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../libs/AuditLogger.php';
require_once __DIR__ . '/_audit_helpers.php';

audit_require_access();

$auditLogger = new AuditLogger($pdo);
$filters = audit_read_filters($_GET);
$perPage = max(10, min(100, (int) get_setting('records_per_page', 25)));
$page = $filters['page'];
$offset = ($page - 1) * $perPage;
$canManageSecurity = audit_security_control_allowed();
$subsystemReady = $auditLogger->isSubsystemReady();

$summary = [
    'critical_last_24h' => 0,
    'failed_logins_last_24h' => 0,
    'active_blocks' => 0,
    'pending_notifications' => 0,
    'failed_notifications' => 0,
    'failed_emails' => 0,
];

$logs = [];
$logCount = 0;
$categories = audit_fetch_categories($pdo);
$activeBlocks = [];
$recentAttempts = [];
$failedNotifications = [];
$pendingNotifications = [];
$failedEmails = [];
$recentEmailFailures = [];
$recentQueueEvents = [];
$dispatchLogs = [];
$dispatchLogCount24h = 0;
$dispatchFailedCount24h = 0;
$errorLogInsights = audit_collect_recent_error_logs(12, 300);
$recentErrorLogs = $errorLogInsights['entries'] ?? [];
$errorLogSources = $errorLogInsights['sources'] ?? [];
$errorLogSummary = $errorLogInsights['summary'] ?? ['critical' => 0, 'warning' => 0, 'info' => 0];
$emailQueueHasLastError = audit_column_exists($pdo, 'email_queue', 'last_error');

if (audit_table_exists($pdo, 'audit_logs')) {
    $summaryStmt = $pdo->query("
        SELECT
            SUM(CASE WHEN severity = 'critical' AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR) THEN 1 ELSE 0 END) AS critical_last_24h
        FROM audit_logs
    ");
    $summary['critical_last_24h'] = (int) ($summaryStmt->fetchColumn() ?: 0);

    $filterSql = audit_build_log_filter_sql($filters);

    $countStmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM audit_logs l
        LEFT JOIN users actor ON actor.id = l.user_id
        LEFT JOIN users target ON target.id = l.target_user_id
        WHERE {$filterSql['where_sql']}
    ");
    foreach ($filterSql['params'] as $key => $value) {
        $countStmt->bindValue(':' . $key, $value);
    }
    $countStmt->execute();
    $logCount = (int) $countStmt->fetchColumn();

    $logStmt = $pdo->prepare("
        SELECT
            l.*,
            actor.name AS actor_name,
            target.name AS target_name
        FROM audit_logs l
        LEFT JOIN users actor ON actor.id = l.user_id
        LEFT JOIN users target ON target.id = l.target_user_id
        WHERE {$filterSql['where_sql']}
        ORDER BY l.created_at DESC
        LIMIT :limit OFFSET :offset
    ");
    foreach ($filterSql['params'] as $key => $value) {
        $logStmt->bindValue(':' . $key, $value);
    }
    $logStmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $logStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $logStmt->execute();
    $logs = $logStmt->fetchAll();

    $queueEventStmt = $pdo->query("
        SELECT created_at, event_type, severity, status, message
        FROM audit_logs
        WHERE category = 'queue'
        ORDER BY created_at DESC
        LIMIT 8
    ");
    $recentQueueEvents = $queueEventStmt->fetchAll();
}

if (audit_table_exists($pdo, 'security_login_attempts')) {
    $attemptSummaryStmt = $pdo->query("
        SELECT COUNT(*)
        FROM security_login_attempts
        WHERE outcome IN ('failed', 'blocked')
          AND occurred_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
    ");
    $summary['failed_logins_last_24h'] = (int) ($attemptSummaryStmt->fetchColumn() ?: 0);

    $attemptStmt = $pdo->query("
        SELECT a.*, u.name AS matched_user_name
        FROM security_login_attempts a
        LEFT JOIN users u ON u.id = a.user_id
        ORDER BY a.occurred_at DESC
        LIMIT 25
    ");
    $recentAttempts = $attemptStmt->fetchAll();
}

if (audit_table_exists($pdo, 'security_ip_blocks')) {
    $auditLogger->cleanupExpiredIpBlocks();

    $blockCountStmt = $pdo->query("
        SELECT COUNT(*)
        FROM security_ip_blocks
        WHERE is_active = 1
          AND (blocked_until IS NULL OR blocked_until > NOW())
    ");
    $summary['active_blocks'] = (int) ($blockCountStmt->fetchColumn() ?: 0);

    $blockStmt = $pdo->query("
        SELECT
            b.*,
            blocker.name AS blocked_by_name
        FROM security_ip_blocks b
        LEFT JOIN users blocker ON blocker.id = b.blocked_by_user_id
        WHERE b.is_active = 1
          AND (b.blocked_until IS NULL OR b.blocked_until > NOW())
        ORDER BY b.created_at DESC
        LIMIT 20
    ");
    $activeBlocks = $blockStmt->fetchAll();
}

if (audit_table_exists($pdo, 'notification_queue')) {
    $notifSummaryStmt = $pdo->query("
        SELECT
            SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending_count,
            SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failed_count
        FROM notification_queue
    ");
    $notifSummary = $notifSummaryStmt->fetch() ?: [];
    $summary['pending_notifications'] = (int) ($notifSummary['pending_count'] ?? 0);
    $summary['failed_notifications'] = (int) ($notifSummary['failed_count'] ?? 0);

    $failedNotifStmt = $pdo->query("
        SELECT id, channel, notification_type, title, attempts, last_error, created_at, processed_at
        FROM notification_queue
        WHERE status = 'failed'
        ORDER BY processed_at DESC, created_at DESC
        LIMIT 10
    ");
    $failedNotifications = $failedNotifStmt->fetchAll();

    $pendingNotifStmt = $pdo->query("
        SELECT id, channel, notification_type, title, attempts, available_at, created_at
        FROM notification_queue
        WHERE status = 'pending'
        ORDER BY available_at ASC, created_at ASC
        LIMIT 10
    ");
    $pendingNotifications = $pendingNotifStmt->fetchAll();
}

if (audit_table_exists($pdo, 'email_queue')) {
    $emailSummaryStmt = $pdo->query("
        SELECT SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failed_count
        FROM email_queue
    ");
    $summary['failed_emails'] = (int) ($emailSummaryStmt->fetchColumn() ?: 0);

    $failedEmailColumns = $emailQueueHasLastError
        ? 'id, recipient, subject, attempts, last_attempt, last_error, created_at'
        : "id, recipient, subject, attempts, last_attempt, NULL AS last_error, created_at";
    $failedEmailStmt = $pdo->query("
        SELECT {$failedEmailColumns}
        FROM email_queue
        WHERE status = 'failed'
        ORDER BY last_attempt DESC, created_at DESC
        LIMIT 10
    ");
    $failedEmails = $failedEmailStmt->fetchAll();
}

if (audit_table_exists($pdo, 'email_log')) {
    $emailFailureStmt = $pdo->query("
        SELECT recipient, subject, error_message, sent_at
        FROM email_log
        WHERE success = 0
        ORDER BY sent_at DESC
        LIMIT 10
    ");
    $recentEmailFailures = $emailFailureStmt->fetchAll();
}

if (audit_table_exists($pdo, 'notification_dispatch_logs')) {
    $dispatchSummaryStmt = $pdo->query("
        SELECT
            COUNT(*) AS total_last_24h,
            SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failed_last_24h
        FROM notification_dispatch_logs
        WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
    ");
    $dispatchSummary = $dispatchSummaryStmt->fetch() ?: [];
    $dispatchLogCount24h = (int) ($dispatchSummary['total_last_24h'] ?? 0);
    $dispatchFailedCount24h = (int) ($dispatchSummary['failed_last_24h'] ?? 0);

    $dispatchLogStmt = $pdo->query("
        SELECT l.*, u.name AS triggered_by_name
        FROM notification_dispatch_logs l
        LEFT JOIN users u ON u.id = l.triggered_by_user_id
        ORDER BY l.created_at DESC
        LIMIT 25
    ");
    $dispatchLogs = $dispatchLogStmt->fetchAll();
}

$pageCount = max(1, (int) ceil(max(1, $logCount) / $perPage));
$baseQuery = $_GET;
unset($baseQuery['page']);
$baseQueryString = http_build_query($baseQuery);

$dbVersion = 'Unavailable';
try {
    $dbVersion = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
} catch (Exception $exception) {
    $dbVersion = 'Unavailable';
}

$serverStatus = [
    'Current time' => date('Y-m-d H:i:s'),
    'Timezone' => date_default_timezone_get(),
    'Application mode' => env('APP_ENV', 'development'),
    'PHP version' => PHP_VERSION,
    'PHP SAPI' => php_sapi_name(),
    'Web server' => $_SERVER['SERVER_SOFTWARE'] ?? 'CLI / background worker',
    'Database version' => $dbVersion,
    'Disk free' => audit_format_bytes(@disk_free_space(ROOT_PATH)),
    'Memory limit' => (string) ini_get('memory_limit'),
    'Upload max filesize' => (string) ini_get('upload_max_filesize'),
    'Post max size' => (string) ini_get('post_max_size'),
];

$success = trim((string) ($_GET['success'] ?? ''));
$error = trim((string) ($_GET['error'] ?? ''));
$queueAction = trim((string) ($_GET['queue_action'] ?? ''));
$queueProcessed = max(0, (int) ($_GET['queue_processed'] ?? 0));
$queueSent = max(0, (int) ($_GET['queue_sent'] ?? 0));
$queueFailed = max(0, (int) ($_GET['queue_failed'] ?? 0));
$queueRetried = max(0, (int) ($_GET['queue_retried'] ?? 0));
$queueRequeued = max(0, (int) ($_GET['queue_requeued'] ?? 0));
$queueLimit = max(0, (int) ($_GET['queue_limit'] ?? 0));
$queueActionLabels = [
    'notification_dispatch' => 'Notification queue dispatch',
    'notification_retry' => 'Notification queue retry',
    'email_dispatch' => 'Email queue dispatch',
    'email_retry' => 'Email queue retry',
];
$queueActionMessage = '';

if ($queueAction !== '' && isset($queueActionLabels[$queueAction])) {
    $queueParts = [];
    $queueParts[] = $queueActionLabels[$queueAction] . ' completed.';
    $queueParts[] = 'Processed ' . number_format($queueProcessed) . '.';
    $queueParts[] = 'Sent ' . number_format($queueSent) . '.';
    if ($queueRetried > 0) {
        $queueParts[] = 'Retried ' . number_format($queueRetried) . '.';
    }
    if ($queueRequeued > 0) {
        $queueParts[] = 'Requeued ' . number_format($queueRequeued) . '.';
    }
    if ($queueFailed > 0) {
        $queueParts[] = 'Failed ' . number_format($queueFailed) . '.';
    }
    if ($queueLimit > 0) {
        $queueParts[] = 'Limit ' . number_format($queueLimit) . '.';
    }
    if ($queueProcessed === 0 && $queueRequeued === 0) {
        $queueParts[] = 'No eligible jobs were waiting at the time of dispatch.';
    }

    $queueActionMessage = implode(' ', $queueParts);
}

$activeTab = trim((string) ($_GET['tab'] ?? 'overview'));
$validTabs = ['overview', 'logs', 'security', 'deliveries', 'runtime'];
if (!in_array($activeTab, $validTabs)) {
    $activeTab = 'overview';
}

include __DIR__ . '/../../includes/header.php';
?>

<div class="container mx-auto px-4 py-8 max-w-7xl">
    <div class="flex flex-col gap-6 mb-8 md:flex-row md:items-end md:justify-between">
        <div class="flex flex-col gap-2">
            <h1 class="text-2xl font-bold text-gray-800">Audit and Security Center</h1>
            <p class="text-sm text-gray-500">Monitor critical system activity, audit trails, and security health from one administrative workspace.</p>
        </div>
        <div class="flex overflow-x-auto rounded-xl bg-gray-100 p-1 mt-4 md:mt-0">
            <?php
            $tabs = [
                'overview' => 'Overview',
                'logs' => 'Audit logs',
                'security' => 'Security',
                'deliveries' => 'Deliveries',
                'runtime' => 'Runtime',
            ];
            foreach ($tabs as $id => $label):
                $isActive = $activeTab === $id;
                $tabUrl = BASE_URL . "modules/admin/audit_center?tab=" . $id;
            ?>
                <a href="<?php echo $tabUrl; ?>" class="px-4 py-2 text-sm font-medium rounded-lg whitespace-nowrap transition-all <?php echo $isActive ? 'bg-white text-blue-600 shadow-sm' : 'text-gray-500 hover:text-gray-700 hover:bg-gray-200'; ?>">
                    <?php echo $label; ?>
                </a>
            <?php endforeach; ?>
        </div>
    </div>

    <?php if (!$subsystemReady): ?>
        <div class="mb-6 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
            The audit subsystem tables are not available yet. Run <code>php sql/run_admin_audit_utilities_migration.php</code> to create the new security and audit structures.
        </div>
    <?php endif; ?>

    <?php if ($success !== ''): ?>
        <div class="mb-4 rounded-xl border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800">
            <?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $success))); ?>
        </div>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
        <div class="mb-4 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
            <?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $error))); ?>
        </div>
    <?php endif; ?>

    <?php if ($queueActionMessage !== ''): ?>
        <div class="mb-4 rounded-xl border px-4 py-3 text-sm <?php echo $queueFailed > 0 ? 'border-amber-200 bg-amber-50 text-amber-900' : 'border-blue-200 bg-blue-50 text-blue-900'; ?>">
            <?php echo htmlspecialchars($queueActionMessage); ?>
        </div>
    <?php endif; ?>

    <?php if ($activeTab === 'overview'): ?>
        <!-- AUDIT HUB OVERVIEW: High Level Metrics -->
        <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4 mb-8">
            <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-5">
                <p class="text-xs uppercase tracking-wide text-gray-400">Critical events, 24h</p>
                <p class="mt-3 text-3xl font-bold text-rose-600"><?php echo number_format($summary['critical_last_24h']); ?></p>
                <p class="mt-2 text-sm text-gray-500">High-severity audit entries that need administrator attention.</p>
            </div>
            <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-5">
                <p class="text-xs uppercase tracking-wide text-gray-400">Failed or blocked logins, 24h</p>
                <p class="mt-3 text-3xl font-bold text-amber-600"><?php echo number_format($summary['failed_logins_last_24h']); ?></p>
                <p class="mt-2 text-sm text-gray-500">Unauthorized login attempts and requests rejected by active IP blocks.</p>
            </div>
            <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-5">
                <p class="text-xs uppercase tracking-wide text-gray-400">Active IP blocks</p>
                <p class="mt-3 text-3xl font-bold text-slate-800"><?php echo number_format($summary['active_blocks']); ?></p>
                <p class="mt-2 text-sm text-gray-500">IPs currently blocked from accessing the ERP.</p>
            </div>
            <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-5">
                <p class="text-xs uppercase tracking-wide text-gray-400">Pending notifications</p>
                <p class="mt-3 text-3xl font-bold text-blue-600"><?php echo number_format($summary['pending_notifications']); ?></p>
                <p class="mt-2 text-sm text-gray-500">Queued notification jobs still waiting for dispatch.</p>
            </div>
            <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-5">
                <p class="text-xs uppercase tracking-wide text-gray-400">Failed notifications</p>
                <p class="mt-3 text-3xl font-bold text-rose-600"><?php echo number_format($summary['failed_notifications']); ?></p>
                <p class="mt-2 text-sm text-gray-500">Notification queue items that exhausted delivery attempts.</p>
            </div>
            <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-5">
                <p class="text-xs uppercase tracking-wide text-gray-400">Failed emails</p>
                <p class="mt-3 text-3xl font-bold text-rose-600"><?php echo number_format($summary['failed_emails']); ?></p>
                <p class="mt-2 text-sm text-gray-500">Email queue records that need troubleshooting or requeue support.</p>
            </div>
        </div>

        <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-6 mb-8">
            <div class="flex flex-col gap-2 mb-4">
                <h2 class="text-lg font-semibold text-gray-800">Server Status</h2>
                <p class="text-sm text-gray-500">Quick operational indicators for environment, runtime, and storage health.</p>
            </div>

            <div class="space-y-3 text-sm">
                <?php foreach ($serverStatus as $label => $value): ?>
                    <div class="flex items-start justify-between gap-4 border-b border-gray-100 pb-3">
                        <span class="text-gray-500"><?php echo htmlspecialchars($label); ?></span>
                        <span class="text-right font-medium text-gray-800"><?php echo htmlspecialchars((string) $value); ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>


    <?php if ($activeTab === 'logs'): ?>
    <div class="space-y-6 mb-8">
        <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-6">
            <div class="flex items-start justify-between gap-4 mb-4">
                <div>
                    <h2 class="text-lg font-semibold text-gray-800">Recent Error Logs</h2>
                    <p class="text-sm text-gray-500">Latest runtime and installer issues pulled from the monitored error log sources so critical failures stand out quickly.</p>
                </div>
                <div class="text-right text-xs text-gray-500 whitespace-nowrap">
                    <div>Critical: <?php echo number_format((int) ($errorLogSummary['critical'] ?? 0)); ?></div>
                    <div>Warnings: <?php echo number_format((int) ($errorLogSummary['warning'] ?? 0)); ?></div>
                </div>
            </div>

            <div class="space-y-3">
                <?php if (empty($recentErrorLogs)): ?>
                    <div class="rounded-lg border border-dashed border-gray-200 px-4 py-6 text-center text-sm text-gray-500">
                        No recent error log entries were found in the monitored sources.
                    </div>
                <?php else: ?>
                    <?php foreach ($recentErrorLogs as $entry): ?>
                        <?php
                        $errorTone = 'bg-slate-100 text-slate-700';
                        if (($entry['severity'] ?? '') === 'warning') {
                            $errorTone = 'bg-amber-100 text-amber-800';
                        } elseif (($entry['severity'] ?? '') === 'critical') {
                            $errorTone = 'bg-rose-100 text-rose-800';
                        }
                        $errorTimestamp = !empty($entry['timestamp_unix'])
                            ? date('Y-m-d H:i', (int) $entry['timestamp_unix'])
                            : (string) ($entry['timestamp'] ?? 'Unknown time');
                        $errorMessage = str_replace('\\n', "\n", (string) ($entry['message'] ?? ''));
                        ?>
                        <div class="rounded-xl border border-gray-100 p-4">
                            <div class="flex flex-col gap-2 md:flex-row md:items-start md:justify-between">
                                <div class="min-w-0">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold <?php echo $errorTone; ?>">
                                            <?php echo htmlspecialchars(ucfirst((string) ($entry['severity'] ?? 'info'))); ?>
                                        </span>
                                        <span class="font-semibold text-gray-800"><?php echo htmlspecialchars((string) ($entry['source'] ?? 'Log source')); ?></span>
                                        <span class="text-xs text-gray-500"><?php echo htmlspecialchars((string) ($entry['origin'] ?? 'runtime')); ?></span>
                                    </div>
                                </div>
                                <div class="text-xs text-gray-500 whitespace-nowrap"><?php echo htmlspecialchars($errorTimestamp); ?></div>
                            </div>
                            <div class="mt-3 text-sm text-gray-700 break-words leading-6"><?php echo nl2br(htmlspecialchars($errorMessage)); ?></div>
                            <div class="mt-3 text-xs text-gray-500 break-all font-mono"><?php echo htmlspecialchars((string) ($entry['path'] ?? '')); ?></div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-6">
            <div class="flex flex-col gap-2 mb-4">
                <h2 class="text-lg font-semibold text-gray-800">Error Log Sources</h2>
                <p class="text-sm text-gray-500">Files currently monitored by this audit view.</p>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-3">
                <?php if (empty($errorLogSources)): ?>
                    <div class="rounded-lg border border-dashed border-gray-200 px-4 py-6 text-center text-sm text-gray-500 md:col-span-2 xl:col-span-3">
                        No readable error log sources were detected on this server.
                    </div>
                <?php else: ?>
                    <?php foreach ($errorLogSources as $source): ?>
                        <div class="rounded-lg border border-gray-100 p-4">
                            <div class="font-semibold text-gray-800"><?php echo htmlspecialchars((string) ($source['label'] ?? 'Log source')); ?></div>
                            <div class="mt-2 text-xs text-gray-500 break-all font-mono"><?php echo htmlspecialchars((string) ($source['path'] ?? '')); ?></div>
                            <div class="mt-2 text-xs text-gray-500">
                                Updated:
                                <?php echo !empty($source['modified_at']) ? htmlspecialchars(date('Y-m-d H:i', (int) $source['modified_at'])) : 'Unknown'; ?>
                                <?php if (!empty($source['size_bytes'])): ?>
                                    / <?php echo htmlspecialchars(audit_format_bytes((int) $source['size_bytes'])); ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($activeTab === 'logs'): ?>
        <!-- SECTION: AUDIT LOGS VIEW -->
        <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-6 mb-8">
            <div class="flex flex-col gap-2 mb-4">
                <h2 class="text-lg font-semibold text-gray-800">Audit Report Filters</h2>
                <p class="text-sm text-gray-500">Slice event history by time, severity, category, status, or free-text search.</p>
            </div>

            <form method="GET" class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
                <input type="hidden" name="tab" value="logs">
                <div>
                    <label for="date_from" class="block text-sm font-medium text-gray-700">From Date</label>
                    <?php echo press_datetime_picker_field([
                        'name' => 'date_from',
                        'id' => 'date_from',
                        'value' => (string) $filters['date_from'],
                        'mode' => 'date',
                        'class' => 'mt-1 block w-full border border-gray-300 rounded-lg px-3 py-2 text-sm',
                    ]); ?>
                </div>
                <div>
                    <label for="date_to" class="block text-sm font-medium text-gray-700">To Date</label>
                    <?php echo press_datetime_picker_field([
                        'name' => 'date_to',
                        'id' => 'date_to',
                        'value' => (string) $filters['date_to'],
                        'mode' => 'date',
                        'class' => 'mt-1 block w-full border border-gray-300 rounded-lg px-3 py-2 text-sm',
                    ]); ?>
                </div>
                <div>
                    <label for="category" class="block text-sm font-medium text-gray-700">Category</label>
                    <select id="category" name="category" class="mt-1 block w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
                        <option value="">All categories</option>
                        <?php foreach ($categories as $category): ?>
                            <option value="<?php echo htmlspecialchars($category); ?>" <?php echo $filters['category'] === $category ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars(ucfirst($category)); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label for="severity" class="block text-sm font-medium text-gray-700">Severity</label>
                    <select id="severity" name="severity" class="mt-1 block w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
                        <option value="">All severities</option>
                        <option value="info" <?php echo $filters['severity'] === 'info' ? 'selected' : ''; ?>>Info</option>
                        <option value="warning" <?php echo $filters['severity'] === 'warning' ? 'selected' : ''; ?>>Warning</option>
                        <option value="critical" <?php echo $filters['severity'] === 'critical' ? 'selected' : ''; ?>>Critical</option>
                    </select>
                </div>
                <div>
                    <label for="status" class="block text-sm font-medium text-gray-700">Status</label>
                    <input type="text" id="status" name="status" value="<?php echo htmlspecialchars($filters['status']); ?>" placeholder="success, failed, blocked..." class="mt-1 block w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
                </div>
                <div>
                    <label for="search" class="block text-sm font-medium text-gray-700">Search term</label>
                    <input type="text" id="search" name="search" value="<?php echo htmlspecialchars($filters['search']); ?>" placeholder="IP, message, route, actor..." class="mt-1 block w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
                </div>
                <div class="md:col-span-2 xl:col-span-3 flex flex-wrap gap-3 pt-2">
                    <button type="submit" class="bg-blue-600 text-white px-5 py-2.5 rounded-lg text-sm font-medium hover:bg-blue-700 shadow-sm border border-blue-700 transition-colors">Apply Filters</button>
                    <a href="<?php echo BASE_URL; ?>modules/admin/audit_center?tab=logs" class="bg-white text-gray-700 px-5 py-2.5 rounded-lg text-sm font-medium hover:bg-gray-50 border border-gray-200 shadow-sm transition-colors">Reset</a>
                    <a href="<?php echo BASE_URL; ?>modules/admin/export_audit_report?<?php echo htmlspecialchars(http_build_query($filters)); ?>" class="bg-emerald-600 text-white px-5 py-2.5 rounded-lg text-sm font-medium hover:bg-emerald-700 shadow-sm border border-emerald-700 ml-auto transition-colors">Export CSV</a>
                </div>
            </form>
        </div>

        <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-6 overflow-hidden">
            <div class="flex flex-col gap-2 mb-6 md:flex-row md:items-center md:justify-between">
                <div>
                    <h2 class="text-lg font-semibold text-gray-800">Audit Trail</h2>
                    <p class="text-sm text-gray-500">Chronological history of system events.</p>
                </div>
                <div class="text-xs text-gray-400 bg-gray-50 px-3 py-1 rounded-full border border-gray-100">
                    Showing <?php echo number_format($logCount); ?> entries
                </div>
            </div>

            <div class="overflow-x-auto -mx-6 px-6">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50/50">
                        <tr>
                            <th class="px-3 py-4 text-left font-semibold text-gray-600">Timestamp</th>
                            <th class="px-3 py-4 text-left font-semibold text-gray-600">Event</th>
                            <th class="px-3 py-4 text-left font-semibold text-gray-600">Actor</th>
                            <th class="px-3 py-4 text-left font-semibold text-gray-600">Severity</th>
                            <th class="px-3 py-4 text-left font-semibold text-gray-600">Context</th>
                            <th class="px-3 py-4 text-left font-semibold text-gray-600">Details</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 bg-white">
                        <?php if (empty($logs)): ?>
                            <tr>
                                <td colspan="6" class="px-3 py-10 text-center text-gray-400">No matching events found.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($logs as $log): ?>
                                <tr class="hover:bg-gray-50/50 transition-colors">
                                    <td class="px-3 py-4 align-top text-gray-500 whitespace-nowrap tabular-nums text-xs"><?php echo htmlspecialchars(date('Y-m-d H:i:s', strtotime($log['created_at']))); ?></td>
                                    <td class="px-3 py-4 align-top">
                                        <div class="font-semibold text-gray-800"><?php echo htmlspecialchars($log['event_type']); ?></div>
                                        <div class="text-[10px] text-gray-400 mt-1"><?php echo htmlspecialchars(ucfirst((string) $log['category'])); ?></div>
                                    </td>
                                    <td class="px-3 py-4 align-top">
                                        <div class="text-gray-700"><?php echo htmlspecialchars((string) ($log['actor_name'] ?: 'System / Guest')); ?></div>
                                        <?php if (!empty($log['target_name'])): ?>
                                            <div class="text-[10px] text-gray-400 mt-1">Target: <?php echo htmlspecialchars((string) $log['target_name']); ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-3 py-4 align-top">
                                        <?php
                                        $severityTone = 'bg-gray-100 text-gray-600';
                                        if ($log['severity'] === 'warning') $severityTone = 'bg-amber-50 text-amber-700 border-amber-100';
                                        elseif ($log['severity'] === 'critical') $severityTone = 'bg-rose-50 text-rose-700 border-rose-100';
                                        ?>
                                        <span class="inline-flex rounded-full border px-2.5 py-0.5 text-[10px] font-bold uppercase tracking-wider <?php echo $severityTone; ?>">
                                            <?php echo htmlspecialchars($log['severity']); ?>
                                        </span>
                                    </td>
                                    <td class="px-3 py-4 align-top">
                                        <div class="text-gray-600 font-mono text-[10px]"><?php echo htmlspecialchars((string) ($log['ip_address'] ?: 'N/A')); ?></div>
                                        <div class="text-[10px] font-medium mt-1 <?php echo strpos((string)$log['status'], 'failed') !== false || strpos((string)$log['status'], 'block') !== false ? 'text-rose-600' : 'text-emerald-600'; ?>">
                                            <?php echo htmlspecialchars(strtoupper((string)($log['status'] ?: 'N/A'))); ?>
                                        </div>
                                    </td>
                                    <td class="px-3 py-4 align-top">
                                        <div class="text-gray-700 leading-relaxed max-w-md text-xs"><?php echo htmlspecialchars((string) $log['message']); ?></div>
                                        <?php if (!empty($log['route'])): ?>
                                            <div class="text-[10px] text-blue-600 font-mono mt-1 break-all bg-blue-50/50 px-2 py-0.5 rounded"><?php echo htmlspecialchars((string) $log['request_method']); ?> <?php echo htmlspecialchars((string) $log['route']); ?></div>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div class="flex flex-col gap-4 mt-6 md:flex-row md:items-center md:justify-between border-t border-gray-100 pt-6">
                <div class="text-sm text-gray-500">
                    Page <span class="font-semibold text-gray-800"><?php echo number_format($page); ?></span> of <?php echo number_format(max(1, $pageCount)); ?>
                </div>
                <div class="flex items-center gap-2">
                    <a href="<?php echo BASE_URL; ?>modules/admin/audit_center?<?php echo htmlspecialchars(http_build_query(array_merge($filters, ['tab' => 'logs', 'page' => max(1, $page-1)]))); ?>" 
                       class="px-4 py-2 text-sm font-medium rounded-lg border border-gray-200 transition-all <?php echo $page <= 1 ? 'bg-gray-50 text-gray-300 cursor-not-allowed pointer-events-none' : 'bg-white text-gray-700 hover:bg-gray-50'; ?>">
                        Previous
                    </a>
                    <a href="<?php echo BASE_URL; ?>modules/admin/audit_center?<?php echo htmlspecialchars(http_build_query(array_merge($filters, ['tab' => 'logs', 'page' => min($pageCount, $page+1)]))); ?>" 
                       class="px-4 py-2 text-sm font-medium rounded-lg border border-gray-200 transition-all <?php echo $page >= $pageCount ? 'bg-gray-50 text-gray-300 cursor-not-allowed pointer-events-none' : 'bg-white text-gray-700 hover:bg-gray-50'; ?>">
                        Next
                    </a>
                </div>
            </div>
        </div>
    <?php endif; ?>


    <?php if ($activeTab === 'security'): ?>
        <!-- SECTION: SECURITY & THREAT MITIGATION -->
        <div class="space-y-6 mb-8">
            <!-- Login Intrusions Table -->
            <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-6 overflow-hidden">
                <div class="mb-6">
                    <h2 class="text-lg font-semibold text-gray-800">Security Login Attempts</h2>
                    <p class="text-sm text-gray-500">History of successful, failed, and rejected authentication events.</p>
                </div>

                <div class="overflow-x-auto -mx-6 px-6">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-50/50">
                            <tr>
                                <th class="px-3 py-4 text-left font-semibold text-gray-600">Identity / IP</th>
                                <th class="px-3 py-4 text-left font-semibold text-gray-600">Outcome</th>
                                <th class="px-3 py-4 text-left font-semibold text-gray-600">Mitigation</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 bg-white">
                            <?php if (empty($recentAttempts)): ?>
                                <tr><td colspan="3" class="px-3 py-8 text-center text-gray-400">No login history available.</td></tr>
                            <?php else: ?>
                                <?php foreach ($recentAttempts as $attempt): ?>
                                    <?php $activeBlock = $auditLogger->getActiveIpBlock((string) $attempt['ip_address']); ?>
                                    <tr class="hover:bg-gray-50/50 transition-colors">
                                        <td class="px-3 py-4 align-top">
                                            <div class="font-semibold text-gray-800"><?php echo htmlspecialchars((string) ($attempt['attempted_identifier'] ?: 'N/A')); ?></div>
                                            <div class="text-[10px] text-gray-400 mt-1 font-mono"><?php echo htmlspecialchars((string) $attempt['ip_address']); ?></div>
                                            <div class="text-[10px] text-gray-400 mt-1 uppercase tabular-nums"><?php echo date('Y-m-d H:i', strtotime($attempt['occurred_at'])); ?></div>
                                        </td>
                                        <td class="px-3 py-4 align-top">
                                            <?php
                                            $badgeTone = 'bg-emerald-50 text-emerald-700 border-emerald-100';
                                            if ($attempt['outcome'] === 'failed') $badgeTone = 'bg-amber-50 text-amber-700 border-amber-100';
                                            elseif ($attempt['outcome'] === 'blocked') $badgeTone = 'bg-rose-50 text-rose-700 border-rose-100';
                                            ?>
                                            <span class="inline-flex rounded-full border px-2 py-0.5 text-[10px] font-bold uppercase <?php echo $badgeTone; ?>">
                                                <?php echo htmlspecialchars($attempt['outcome']); ?>
                                            </span>
                                            <?php if (!empty($attempt['failure_reason'])): ?>
                                                <div class="text-[11px] text-gray-500 mt-1 italic"><?php echo htmlspecialchars((string) $attempt['failure_reason']); ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-3 py-4 align-top">
                                            <?php if ($canManageSecurity): ?>
                                                <?php if ($activeBlock): ?>
                                                    <form method="POST" action="<?php echo BASE_URL; ?>modules/admin/security_action">
                                                        <input type="hidden" name="_csrf" value="<?php echo htmlspecialchars(csrf_token('admin_security_action')); ?>">
                                                        <input type="hidden" name="action" value="unblock_ip">
                                                        <input type="hidden" name="tab" value="security">
                                                        <input type="hidden" name="ip_address" value="<?php echo htmlspecialchars((string) $attempt['ip_address']); ?>">
                                                        <button type="submit" class="text-emerald-600 font-semibold text-xs hover:underline">Unblock IP</button>
                                                    </form>
                                                <?php else: ?>
                                                    <form method="POST" action="<?php echo BASE_URL; ?>modules/admin/security_action">
                                                        <input type="hidden" name="_csrf" value="<?php echo htmlspecialchars(csrf_token('admin_security_action')); ?>">
                                                        <input type="hidden" name="action" value="block_ip">
                                                        <input type="hidden" name="tab" value="security">
                                                        <input type="hidden" name="ip_address" value="<?php echo htmlspecialchars((string) $attempt['ip_address']); ?>">
                                                        <input type="hidden" name="reason" value="Security mitigation after reviewing login history.">
                                                        <input type="hidden" name="duration_minutes" value="1440">
                                                        <button type="submit" class="text-rose-600 font-semibold text-xs hover:underline">Block for 24h</button>
                                                    </form>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span class="text-xs text-gray-300">View only</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- IP Control Panel -->
            <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-6">
                <div class="flex flex-col gap-2 mb-6">
                    <h2 class="text-lg font-semibold text-gray-800">IP Controls</h2>
                    <p class="text-sm text-gray-500">Review active mitigations and apply manual blocks without forcing the page into a sidebar layout.</p>
                </div>
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                    <!-- Manual Block Form -->
                    <div class="rounded-xl border border-gray-100 p-6">
                        <h3 class="text-lg font-semibold text-gray-800 mb-4">Manual IP Block</h3>
                        <?php if ($canManageSecurity): ?>
                            <form method="POST" action="<?php echo BASE_URL; ?>modules/admin/security_action" class="space-y-4">
                                <input type="hidden" name="_csrf" value="<?php echo htmlspecialchars(csrf_token('admin_security_action')); ?>">
                                <input type="hidden" name="action" value="block_ip">
                                <input type="hidden" name="tab" value="security">
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                    <div>
                                        <label class="block text-xs font-bold text-gray-500 uppercase tracking-tight">IP Address</label>
                                        <input type="text" name="ip_address" placeholder="e.g. 154.0.0.1" class="mt-1 block w-full border border-gray-300 rounded-lg px-3 py-2 text-sm" required>
                                    </div>
                                    <div>
                                        <label class="block text-xs font-bold text-gray-500 uppercase tracking-tight">Duration (m)</label>
                                        <input type="number" name="duration_minutes" value="1440" class="mt-1 block w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
                                    </div>
                                </div>
                                <div>
                                    <label class="block text-xs font-bold text-gray-500 uppercase tracking-tight">Reason</label>
                                    <input type="text" name="reason" placeholder="Suspicious activity, bot spam..." class="mt-1 block w-full border border-gray-300 rounded-lg px-3 py-2 text-sm" required>
                                </div>
                                <button type="submit" class="w-full bg-rose-600 text-white font-semibold py-2.5 rounded-lg text-sm hover:bg-rose-700 transition-colors shadow-sm">Execute Security Block</button>
                            </form>
                        <?php else: ?>
                            <div class="p-8 text-center bg-gray-50 rounded-lg border border-dashed border-gray-200 text-sm text-gray-400">
                                Permission required to modify active IP blocks.
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Active Blocks List -->
                    <div class="rounded-xl border border-gray-100 overflow-hidden">
                        <div class="flex items-center justify-between gap-4 px-6 pt-6 mb-4">
                            <h3 class="text-lg font-semibold text-gray-800">Active Blocks</h3>
                            <span class="text-[10px] font-bold text-rose-600 bg-rose-50 px-2 py-0.5 rounded-full border border-rose-100"><?php echo count($activeBlocks); ?> BLOCKED</span>
                        </div>
                        <div class="overflow-x-auto -mx-6 px-6 pb-6">
                            <table class="min-w-full divide-y divide-gray-200 text-sm">
                                <thead class="bg-gray-50/50">
                                    <tr>
                                        <th class="px-3 py-3 text-left font-semibold text-gray-600">Target</th>
                                        <th class="px-3 py-3 text-left font-semibold text-gray-600">Reason / Expires</th>
                                        <th class="px-3 py-3 text-left"></th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100">
                                    <?php if (empty($activeBlocks)): ?>
                                        <tr><td colspan="3" class="px-3 py-6 text-center text-gray-400">No active blocks.</td></tr>
                                    <?php else: ?>
                                        <?php foreach ($activeBlocks as $block): ?>
                                            <tr class="hover:bg-gray-50/50 transition-colors">
                                                <td class="px-3 py-4 align-top tabular-nums font-semibold text-gray-800"><?php echo htmlspecialchars((string) $block['ip_address']); ?></td>
                                                <td class="px-3 py-4 align-top">
                                                    <div class="text-xs text-gray-600 break-words"><?php echo htmlspecialchars((string) $block['reason']); ?></div>
                                                    <div class="text-[10px] text-gray-400 mt-1 italic"><?php echo htmlspecialchars(audit_format_block_until($block['blocked_until'] ?? null)); ?></div>
                                                </td>
                                                <td class="px-3 py-4 align-top text-right">
                                                    <?php if ($canManageSecurity): ?>
                                                        <form method="POST" action="<?php echo BASE_URL; ?>modules/admin/security_action">
                                                            <input type="hidden" name="_csrf" value="<?php echo htmlspecialchars(csrf_token('admin_security_action')); ?>">
                                                            <input type="hidden" name="action" value="unblock_ip">
                                                            <input type="hidden" name="tab" value="security">
                                                            <input type="hidden" name="ip_address" value="<?php echo htmlspecialchars((string) $block['ip_address']); ?>">
                                                            <button type="submit" class="text-blue-600 font-semibold text-xs hover:underline">Release</button>
                                                        </form>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($activeTab === 'deliveries'): ?>
        <!-- SECTION: EMAIL & NOTIFICATION DELIVERY HUB -->
        <div class="space-y-6 pb-8">
            <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-6">
                <div class="flex flex-col gap-2 mb-6">
                    <h2 class="text-lg font-semibold text-gray-800">Delivery Operations</h2>
                    <p class="text-sm text-gray-500">Watch recent queue activity and trigger manual recovery actions from one responsive section row.</p>
                </div>
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                    <div class="rounded-xl border border-gray-100 p-6">
                        <h3 class="text-lg font-semibold text-gray-800 mb-4">Queue Signals</h3>
                        <div class="space-y-4">
                            <?php if (empty($recentQueueEvents)): ?>
                                <div class="text-sm text-gray-400 italic">No recent activity pulse.</div>
                            <?php else: ?>
                                <?php foreach (array_slice($recentQueueEvents, 0, 5) as $event): ?>
                                    <div class="border-l-2 border-blue-500 pl-3 py-1">
                                        <div class="text-xs font-bold text-gray-800"><?php echo htmlspecialchars((string) $event['event_type']); ?></div>
                                        <div class="text-[11px] text-gray-500 mt-0.5 line-clamp-2"><?php echo htmlspecialchars((string) $event['message']); ?></div>
                                        <div class="text-[10px] text-gray-400 mt-1 tabular-nums"><?php echo date('H:i:s', strtotime($event['created_at'])); ?></div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="bg-slate-900 rounded-xl border border-slate-800 shadow-sm p-6 text-slate-100">
                        <h3 class="text-lg font-semibold mb-2">Manual Dispatch</h3>
                        <p class="text-xs text-slate-400 mb-4">Trigger workers to clear pending messages or retry failures.</p>
                        
                        <?php if ($canManageSecurity): ?>
                            <div class="space-y-3">
                                <form method="POST" action="<?php echo BASE_URL; ?>modules/admin/security_action">
                                    <input type="hidden" name="_csrf" value="<?php echo htmlspecialchars(csrf_token('admin_security_action')); ?>">
                                    <input type="hidden" name="action" value="process_notification_queue">
                                    <input type="hidden" name="tab" value="deliveries">
                                    <button type="submit" class="w-full bg-blue-600 hover:bg-blue-500 text-white text-xs font-bold py-2.5 rounded-lg transition-colors shadow-sm">Run Workers</button>
                                </form>
                                <form method="POST" action="<?php echo BASE_URL; ?>modules/admin/security_action">
                                    <input type="hidden" name="_csrf" value="<?php echo htmlspecialchars(csrf_token('admin_security_action')); ?>">
                                    <input type="hidden" name="action" value="retry_failed_notifications">
                                    <input type="hidden" name="tab" value="deliveries">
                                    <button type="submit" class="w-full bg-amber-600 hover:bg-amber-500 text-white text-xs font-bold py-2.5 rounded-lg transition-colors shadow-sm">Retry Failures</button>
                                </form>
                                <form method="POST" action="<?php echo BASE_URL; ?>modules/admin/security_action">
                                    <input type="hidden" name="_csrf" value="<?php echo htmlspecialchars(csrf_token('admin_security_action')); ?>">
                                    <input type="hidden" name="action" value="process_email_queue">
                                    <input type="hidden" name="tab" value="deliveries">
                                    <button type="submit" class="w-full bg-slate-700 hover:bg-slate-600 text-white text-xs font-bold py-2.5 rounded-lg transition-colors shadow-sm">Run Legacy Mailer</button>
                                </form>
                            </div>
                        <?php else: ?>
                            <div class="text-xs text-slate-500 p-4 border border-dashed border-slate-700 rounded-lg italic">Read-only queue observation.</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Detailed Delivery Attempts -->
            <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-6 overflow-hidden">
                    <div class="flex items-center justify-between gap-4 mb-6">
                        <div>
                            <h2 class="text-lg font-semibold text-gray-800">Dispatch Registry</h2>
                            <p class="text-sm text-gray-500">Atomic log of every message attempt made by the system.</p>
                        </div>
                        <div class="text-xs text-gray-400 font-mono bg-gray-50 px-3 py-1 rounded-full border border-gray-100">
                            Volume 24h: <?php echo number_format($dispatchLogCount24h); ?>
                        </div>
                    </div>

                    <div class="overflow-x-auto -mx-6 px-6">
                        <table class="min-w-full divide-y divide-gray-200 text-[13px]">
                            <thead class="bg-gray-50/50">
                                <tr>
                                    <th class="px-3 py-4 text-left font-semibold text-gray-600">Timestamp / Queue</th>
                                    <th class="px-3 py-4 text-left font-semibold text-gray-600">Recipient</th>
                                    <th class="px-3 py-4 text-left font-semibold text-gray-600">Status</th>
                                    <th class="px-3 py-4 text-left font-semibold text-gray-600">Trigger</th>
                                    <th class="px-3 py-4 text-left font-semibold text-gray-600">Diagnostics</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 bg-white">
                                <?php if (empty($dispatchLogs)): ?>
                                    <tr><td colspan="5" class="px-3 py-10 text-center text-gray-400 italic">No delivery attempt logs found.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($dispatchLogs as $log): ?>
                                        <tr class="hover:bg-gray-50/50 transition-colors">
                                            <td class="px-3 py-4 align-top">
                                                <div class="text-gray-500 text-[11px] tabular-nums"><?php echo date('H:i:s, M d', strtotime($log['created_at'])); ?></div>
                                                <div class="font-bold text-gray-800 mt-1"><?php echo htmlspecialchars((string) $log['queue_name']); ?></div>
                                                <div class="text-[10px] text-blue-600 font-mono mt-1 uppercase"><?php echo htmlspecialchars((string) $log['channel']); ?> / <?php echo htmlspecialchars((string) ($log['provider'] ?: 'Internal')); ?></div>
                                            </td>
                                            <td class="px-3 py-4 align-top">
                                                <div class="font-semibold text-gray-800 break-all max-w-[180px]"><?php echo htmlspecialchars((string) $log['recipient']); ?></div>
                                                <?php if (!empty($log['subject_line'])): ?>
                                                    <div class="text-[11px] text-gray-500 mt-1 line-clamp-1 italic"><?php echo htmlspecialchars((string) $log['subject_line']); ?></div>
                                                <?php endif; ?>
                                            </td>
                                            <td class="px-3 py-4 align-top">
                                                <?php
                                                $statTone = 'bg-gray-100 text-gray-600';
                                                if ($log['status'] === 'sent') $statTone = 'bg-emerald-50 text-emerald-700 border-emerald-100';
                                                elseif ($log['status'] === 'failed') $statTone = 'bg-rose-50 text-rose-700 border-rose-100';
                                                elseif (in_array($log['status'], ['requeued', 'retried'])) $statTone = 'bg-amber-50 text-amber-700 border-amber-100';
                                                ?>
                                                <span class="inline-flex rounded-full border px-2 py-0.5 text-[10px] font-bold uppercase <?php echo $statTone; ?>">
                                                    <?php echo htmlspecialchars($log['status']); ?>
                                                </span>
                                            </td>
                                            <td class="px-3 py-4 align-top text-[11px]">
                                                <div class="text-gray-700 font-medium">Attempt #<?php echo $log['attempt_number']; ?></div>
                                                <div class="text-[10px] text-gray-400 mt-1"><?php echo htmlspecialchars((string) $log['trigger_source']); ?></div>
                                            </td>
                                            <td class="px-3 py-4 align-top max-w-xs">
                                                <?php if (!empty($log['error_message'])): ?>
                                                    <div class="text-[11px] text-rose-600 font-medium italic mb-2 line-clamp-2" title="<?php echo htmlspecialchars((string)$log['error_message']); ?>"><?php echo htmlspecialchars((string) $log['error_message']); ?></div>
                                                <?php endif; ?>
                                                <?php if (!empty($log['response_json'])): ?>
                                                    <details class="text-[11px]">
                                                        <summary class="cursor-pointer text-blue-600 hover:underline">View Response</summary>
                                                        <pre class="mt-2 p-3 bg-slate-900 text-slate-300 rounded-lg overflow-auto max-h-40 font-mono text-[10px]"><?php echo htmlspecialchars($log['response_json']); ?></pre>
                                                    </details>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

            <!-- Troubleshooting Section -->
            <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-6">
                <div class="flex flex-col gap-2 mb-6">
                    <h2 class="text-lg font-semibold text-gray-800">Delivery Troubleshooting</h2>
                    <p class="text-sm text-gray-500">Keep notification and email diagnostics aligned in a responsive row instead of splitting the page into separate columns.</p>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div class="rounded-xl border border-gray-100 p-6">
                        <h3 class="text-lg font-semibold text-gray-800 mb-6 flex items-center gap-2">
                            <span class="w-2 h-2 rounded-full bg-amber-500"></span>
                            Notification Health
                        </h3>
                        <div class="space-y-6">
                            <div>
                                <h4 class="text-xs font-bold text-gray-500 uppercase tracking-widest mb-3">Recent Stuck Jobs</h4>
                                <?php if (empty($failedNotifications)): ?>
                                    <div class="text-sm text-gray-400 italic bg-gray-50 p-4 rounded-xl border border-dashed border-gray-200">No stuck notifications.</div>
                                <?php else: ?>
                                    <div class="space-y-3">
                                        <?php foreach (array_slice($failedNotifications, 0, 3) as $job): ?>
                                            <div class="bg-rose-50 border border-rose-100 rounded-lg p-3">
                                                <div class="font-bold text-rose-900 text-sm"><?php echo htmlspecialchars($job['title']); ?></div>
                                                <div class="text-xs text-rose-700 mt-1"><?php echo htmlspecialchars((string)$job['last_error']); ?></div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <div class="rounded-xl border border-gray-100 p-6">
                        <h3 class="text-lg font-semibold text-gray-800 mb-6 flex items-center gap-2">
                            <span class="w-2 h-2 rounded-full bg-blue-500"></span>
                            Email Health
                        </h3>
                        <div class="space-y-6">
                            <div>
                                <h4 class="text-xs font-bold text-gray-500 uppercase tracking-widest mb-3">Mailer Diagnostics</h4>
                                <?php if (empty($recentEmailFailures)): ?>
                                    <div class="text-sm text-gray-400 italic bg-gray-50 p-4 rounded-xl border border-dashed border-gray-200">Mailer active and healthy.</div>
                                <?php else: ?>
                                    <div class="space-y-3">
                                        <?php foreach (array_slice($recentEmailFailures, 0, 3) as $fail): ?>
                                            <div class="bg-gray-50 border border-gray-100 rounded-lg p-3">
                                                <div class="font-bold text-gray-800 text-sm"><?php echo htmlspecialchars($fail['recipient']); ?></div>
                                                <div class="text-xs text-gray-500 mt-1 line-clamp-1 italic"><?php echo htmlspecialchars((string)$fail['error_message']); ?></div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($activeTab === 'runtime'): ?>
        <!-- SECTION: RUNTIME DIAGNOSTICS & SYSTEM ERRORS -->
        <div class="space-y-6 mb-8">
            <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-6 overflow-hidden">
                <div class="flex items-center justify-between gap-4 mb-6">
                    <div>
                        <h2 class="text-lg font-semibold text-gray-800">Runtime Error Logs</h2>
                        <p class="text-sm text-gray-500">Live monitoring of system exceptions and runtime warnings.</p>
                    </div>
                </div>

                <div class="space-y-4">
                    <?php if (empty($recentErrorLogs)): ?>
                        <div class="px-4 py-20 text-center bg-gray-50 rounded-xl border border-dashed border-gray-200">
                            <div class="text-gray-400">No runtime errors detected in current log cycles.</div>
                        </div>
                    <?php else: ?>
                        <?php foreach ($recentErrorLogs as $entry): ?>
                            <?php
                            $logIntensity = 'border-gray-100';
                            $logBg = 'bg-white';
                            if (($entry['severity'] ?? '') === 'critical') {
                                $logIntensity = 'border-rose-200';
                                $logBg = 'bg-rose-50/20';
                            } elseif (($entry['severity'] ?? '') === 'warning') {
                                $logIntensity = 'border-amber-200';
                            }
                            ?>
                            <div class="rounded-xl border p-4 <?php echo $logIntensity; ?> <?php echo $logBg; ?> transition-all hover:shadow-md">
                                <div class="flex items-start justify-between gap-4">
                                    <div class="min-w-0">
                                        <div class="flex items-center flex-wrap gap-2 text-[10px] font-bold uppercase tracking-wider">
                                            <span class="<?php echo ($entry['severity'] ?? '') === 'critical' ? 'text-rose-600' : 'text-amber-600'; ?>">
                                                [<?php echo htmlspecialchars($entry['severity'] ?? 'INFO'); ?>]
                                            </span>
                                            <span class="text-gray-400"><?php echo htmlspecialchars((string)($entry['source'] ?? 'PHP')); ?> / <?php echo htmlspecialchars((string)($entry['origin'] ?? 'kernel')); ?></span>
                                        </div>
                                        <div class="mt-2 text-sm text-gray-800 font-medium leading-relaxed break-words">
                                            <?php echo htmlspecialchars((string)($entry['message'] ?? '')); ?>
                                        </div>
                                        <div class="mt-2 text-[11px] text-gray-400 font-mono break-all bg-gray-50 px-2 py-1 rounded border border-gray-100">
                                            Pipeline: <?php echo htmlspecialchars((string)($entry['path'] ?? 'Unknown location')); ?>
                                        </div>
                                    </div>
                                    <div class="text-[10px] text-gray-400 tabular-nums whitespace-nowrap bg-gray-100 px-2 py-1 rounded">
                                        <?php echo date('H:i:s, M d', (int)($entry['timestamp_unix'] ?? time())); ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Monitored Sources -->
            <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-6">
                <div class="flex flex-col gap-2 mb-6">
                    <h2 class="text-lg font-semibold text-gray-800">Pipeline Status</h2>
                    <p class="text-sm text-gray-500">Monitoring live streams from the current runtime log targets.</p>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
                    <?php if (empty($errorLogSources)): ?>
                        <div class="rounded-lg border border-dashed border-gray-200 px-4 py-6 text-center text-sm text-gray-500 md:col-span-2 xl:col-span-3">
                            No monitored runtime sources were detected on this server.
                        </div>
                    <?php else: ?>
                        <?php foreach ($errorLogSources as $source): ?>
                            <div class="p-4 bg-gray-50 rounded-lg border border-gray-100 hover:bg-white transition-colors">
                                <div class="text-sm font-bold text-gray-800"><?php echo htmlspecialchars($source['label']); ?></div>
                                <div class="text-[10px] text-gray-400 mt-1 font-mono break-all"><?php echo htmlspecialchars($source['path']); ?></div>
                                <div class="mt-3 flex items-center justify-between text-[10px] text-gray-500 font-medium border-t border-gray-200/50 pt-2">
                                    <span><?php echo audit_format_bytes((int)$source['size_bytes']); ?></span>
                                    <span><?php echo date('H:i, M d', (int)$source['modified_at']); ?></span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>

                    <div class="bg-blue-600 rounded-xl p-6 text-white shadow-lg shadow-blue-200">
                        <h3 class="font-bold text-sm mb-2">Live Diagnostics</h3>
                        <p class="text-[11px] leading-relaxed opacity-90">Runtime logs are aggregated from local PHP error logs and critical system observers. Pipeline latency is usually &lt;1s.</p>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
