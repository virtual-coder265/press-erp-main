<?php

require_once __DIR__ . '/../../config/app.php';
checkAuth();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../libs/AuditLogger.php';
require_once __DIR__ . '/_audit_helpers.php';
require_once __DIR__ . '/../../includes/data_reset_helper.php';

data_reset_require_access();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verify_csrf_token($_POST['_csrf'] ?? null, 'data_reset_action')) {
    redirect('modules/admin/data_reset?error=invalid_request');
}

$confirmation = strtoupper(trim((string) ($_POST['confirmation'] ?? '')));
if ($confirmation !== 'RESET') {
    redirect('modules/admin/data_reset?error=confirmation_required');
}

$selectedGroups = array_values(array_filter(array_map('strval', (array) ($_POST['groups'] ?? []))));
$currentUserId = (int) ($_SESSION['user_id'] ?? 0);

try {
    $summary = data_reset_execute($pdo, $selectedGroups);

    $auditLogger = new AuditLogger($pdo);
    $auditLogger->log(
        'maintenance',
        'data_reset_executed',
        'Administrator cleared mockup and transactional test data.',
        [
            'severity' => 'warning',
            'status' => 'success',
            'user_id' => $currentUserId,
            'context' => [
                'groups' => $summary['groups'],
                'truncated_tables' => $summary['tables']['truncated'] ?? [],
                'table_errors' => $summary['tables']['errors'] ?? [],
                'upload_cleanup' => $summary['uploads'] ?? [],
            ],
        ]
    );

    $_SESSION['data_reset_flash'] = [
        'type' => 'success',
        'message' => 'Data reset completed. ' . data_reset_format_summary($summary) . '.',
        'summary' => $summary,
    ];
    redirect('modules/admin/data_reset?success=1');
} catch (Throwable $exception) {
    $_SESSION['data_reset_flash'] = [
        'type' => 'error',
        'message' => 'Data reset failed: ' . $exception->getMessage(),
    ];
    redirect('modules/admin/data_reset?error=execution_failed');
}
