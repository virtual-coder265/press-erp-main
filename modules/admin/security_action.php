<?php

require_once __DIR__ . '/../../config/app.php';
checkAuth();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/email_helper.php';
require_once __DIR__ . '/../../libs/AuditLogger.php';
require_once __DIR__ . '/../../libs/NotificationManager.php';
require_once __DIR__ . '/_audit_helpers.php';

audit_require_access();

if (!audit_security_control_allowed()) {
    header('HTTP/1.1 403 Forbidden');
    die('Access Denied.');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verify_csrf_token($_POST['_csrf'] ?? null, 'admin_security_action')) {
    redirect('modules/admin/audit_center?error=invalid_security_request');
}

$action = trim((string) ($_POST['action'] ?? ''));
$tab = trim((string) ($_POST['tab'] ?? ''));
$ipAddress = trim((string) ($_POST['ip_address'] ?? ''));
$reason = trim((string) ($_POST['reason'] ?? ''));
$notes = trim((string) ($_POST['notes'] ?? ''));
$durationMinutes = isset($_POST['duration_minutes']) ? max(0, (int) $_POST['duration_minutes']) : 1440;
$queueLimit = isset($_POST['queue_limit']) ? max(1, min(500, (int) $_POST['queue_limit'])) : 25;

/** 
 * Build redirect URL with preserved tab state
 */
function audit_security_get_redirect_url(string $base, array $params = []): string
{
    global $tab;
    if ($tab !== '') {
        $params['tab'] = $tab;
    }
    $query = !empty($params) ? '?' . http_build_query($params) : '';
    return $base . $query;
}

$auditLogger = new AuditLogger($pdo);
$currentUserId = (int) ($_SESSION['user_id'] ?? 0);

if ($action === 'block_ip') {
    if (!filter_var($ipAddress, FILTER_VALIDATE_IP)) {
        redirect(audit_security_get_redirect_url('modules/admin/audit_center', ['error' => 'invalid_ip_address']));
    }

    $block = $auditLogger->blockIp(
        $ipAddress,
        $reason !== '' ? $reason : 'Blocked by administrator from audit console.',
        $currentUserId,
        $durationMinutes,
        'manual',
        [
            'notes' => $notes,
            'source' => 'modules/admin/security_action',
        ]
    );

    if ($block) {
        redirect(audit_security_get_redirect_url('modules/admin/audit_center', ['success' => 'ip_blocked']));
    }

    redirect(audit_security_get_redirect_url('modules/admin/audit_center', ['error' => 'unable_to_block_ip']));
}

if ($action === 'unblock_ip') {
    if (!filter_var($ipAddress, FILTER_VALIDATE_IP)) {
        redirect(audit_security_get_redirect_url('modules/admin/audit_center', ['error' => 'invalid_ip_address']));
    }

    if ($auditLogger->unblockIp($ipAddress, $currentUserId, $notes)) {
        redirect(audit_security_get_redirect_url('modules/admin/audit_center', ['success' => 'ip_unblocked']));
    }

    redirect(audit_security_get_redirect_url('modules/admin/audit_center', ['error' => 'unable_to_unblock_ip']));
}

if ($action === 'process_notification_queue') {
    $manager = new NotificationManager($pdo);
    $stats = $manager->processQueue($queueLimit, [
        'force' => true,
        'trigger_source' => 'manual_dispatch',
        'triggered_by_user_id' => $currentUserId,
    ]);

    $auditLogger->log(
        'queue',
        'manual_notification_queue_dispatch',
        'Administrator triggered notification queue dispatch.',
        [
            'severity' => ($stats['failed'] ?? 0) > 0 ? 'warning' : 'info',
            'status' => (($stats['processed'] ?? 0) > 0) ? 'success' : 'noop',
            'context' => array_merge($stats, ['limit' => $queueLimit]),
        ]
    );

    redirect(audit_security_get_redirect_url('modules/admin/audit_center', [
        'success' => 'queue_action_completed',
        'queue_action' => 'notification_dispatch',
        'queue_processed' => (int) ($stats['processed'] ?? 0),
        'queue_sent' => (int) ($stats['sent'] ?? 0),
        'queue_failed' => (int) ($stats['failed'] ?? 0),
        'queue_retried' => (int) ($stats['retried'] ?? 0),
        'queue_limit' => $queueLimit,
    ]));
}

if ($action === 'retry_failed_notifications') {
    $manager = new NotificationManager($pdo);
    $requeued = $manager->requeueFailedJobs($queueLimit, null, $currentUserId, 'manual_requeue');
    $stats = $manager->processQueue($queueLimit, [
        'force' => true,
        'trigger_source' => 'manual_dispatch',
        'triggered_by_user_id' => $currentUserId,
    ]);

    $auditLogger->log(
        'queue',
        'manual_notification_queue_retry',
        'Administrator requeued failed notification jobs and dispatched the queue.',
        [
            'severity' => ($stats['failed'] ?? 0) > 0 ? 'warning' : 'info',
            'status' => (($stats['processed'] ?? 0) > 0 || $requeued > 0) ? 'success' : 'noop',
            'context' => array_merge($stats, ['limit' => $queueLimit, 'requeued' => $requeued]),
        ]
    );

    redirect(audit_security_get_redirect_url('modules/admin/audit_center', [
        'success' => 'queue_action_completed',
        'queue_action' => 'notification_retry',
        'queue_processed' => (int) ($stats['processed'] ?? 0),
        'queue_sent' => (int) ($stats['sent'] ?? 0),
        'queue_failed' => (int) ($stats['failed'] ?? 0),
        'queue_retried' => (int) ($stats['retried'] ?? 0),
        'queue_requeued' => $requeued,
        'queue_limit' => $queueLimit,
    ]));
}

if ($action === 'process_email_queue') {
    $stats = processEmailQueue($pdo, $queueLimit, [
        'force' => true,
        'trigger_source' => 'manual_dispatch',
        'triggered_by_user_id' => $currentUserId,
    ]);

    $auditLogger->log(
        'queue',
        'manual_email_queue_dispatch',
        'Administrator triggered email queue dispatch.',
        [
            'severity' => ($stats['failed'] ?? 0) > 0 ? 'warning' : 'info',
            'status' => (($stats['processed'] ?? 0) > 0) ? 'success' : 'noop',
            'context' => array_merge($stats, ['limit' => $queueLimit]),
        ]
    );

    redirect(audit_security_get_redirect_url('modules/admin/audit_center', [
        'success' => 'queue_action_completed',
        'queue_action' => 'email_dispatch',
        'queue_processed' => (int) ($stats['processed'] ?? 0),
        'queue_sent' => (int) ($stats['sent'] ?? 0),
        'queue_failed' => (int) ($stats['failed'] ?? 0),
        'queue_limit' => $queueLimit,
    ]));
}

if ($action === 'retry_failed_emails') {
    $requeued = requeueFailedEmails($pdo, $queueLimit, $currentUserId, 'manual_requeue');
    $stats = processEmailQueue($pdo, $queueLimit, [
        'force' => true,
        'trigger_source' => 'manual_dispatch',
        'triggered_by_user_id' => $currentUserId,
    ]);

    $auditLogger->log(
        'queue',
        'manual_email_queue_retry',
        'Administrator requeued failed email jobs and dispatched the email queue.',
        [
            'severity' => ($stats['failed'] ?? 0) > 0 ? 'warning' : 'info',
            'status' => (($stats['processed'] ?? 0) > 0 || $requeued > 0) ? 'success' : 'noop',
            'context' => array_merge($stats, ['limit' => $queueLimit, 'requeued' => $requeued]),
        ]
    );

    redirect(audit_security_get_redirect_url('modules/admin/audit_center', [
        'success' => 'queue_action_completed',
        'queue_action' => 'email_retry',
        'queue_processed' => (int) ($stats['processed'] ?? 0),
        'queue_sent' => (int) ($stats['sent'] ?? 0),
        'queue_failed' => (int) ($stats['failed'] ?? 0),
        'queue_requeued' => $requeued,
        'queue_limit' => $queueLimit,
    ]));
}

redirect(audit_security_get_redirect_url('modules/admin/audit_center', ['error' => 'unsupported_action']));
