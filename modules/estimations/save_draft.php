<?php
require_once __DIR__ . '/../../config/app.php';
checkAuthApi();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/permissions_helper.php';
require_once __DIR__ . '/../../libs/EstimationAuditMigrator.php';
require_once __DIR__ . '/../../includes/estimation_draft_version_helper.php';
require_once __DIR__ . '/../../includes/estimation_access_helper.php';
permissions_require_one_of(['manage_estimations']);

EstimationAuditMigrator::ensure($pdo);

header('Content-Type: application/json');

/**
 * Format a MySQL datetime as UTC ISO-8601 when possible.
 */
function estimation_draft_format_timestamp(?string $mysqlDatetime): ?string
{
    if ($mysqlDatetime === null || $mysqlDatetime === '') {
        return null;
    }

    try {
        $dt = new DateTimeImmutable($mysqlDatetime);
        return $dt->setTimezone(new DateTimeZone('UTC'))->format('c');
    } catch (Exception $e) {
        return str_replace(' ', 'T', $mysqlDatetime) . 'Z';
    }
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method not allowed']);
        exit;
    }

    $pdo->beginTransaction();

    $est_id = isset($_POST['est_id']) && $_POST['est_id'] !== '' ? (int) $_POST['est_id'] : null;
    $current_step = isset($_POST['current_step']) ? (int) $_POST['current_step'] : 1;
    $save_action = strtolower(trim((string) ($_POST['action'] ?? 'autosave')));
    $base_revision = isset($_POST['base_revision']) ? (int) $_POST['base_revision'] : 0;
    $checkpoint_step = isset($_POST['checkpoint_step']) ? (int) $_POST['checkpoint_step'] : 0;
    $is_override = ($save_action === 'override');
    $is_clone = ($save_action === 'clone');
    $is_step_checkpoint = ($save_action === 'step_checkpoint');

    if ($is_step_checkpoint && ($checkpoint_step < 1 || $checkpoint_step > ESTIMATION_DRAFT_STEP_COUNT)) {
        $checkpoint_step = $current_step;
    }

    $draft_origin = 'autosave';
    if ($save_action === 'manual') {
        $draft_origin = 'manual';
    } elseif (in_array($save_action, ['recovered', 'override'], true)) {
        $draft_origin = 'recovered';
    } elseif ($save_action === 'clone') {
        $draft_origin = 'recovered';
    }

    $form_data = $_POST;
    unset(
        $form_data['est_id'],
        $form_data['current_step'],
        $form_data['save_draft'],
        $form_data['action'],
        $form_data['base_revision'],
        $form_data['content_hash'],
        $form_data['checkpoint_step']
    );

    $draft_json = json_encode($form_data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($draft_json === false) {
        throw new RuntimeException('Unable to encode draft data');
    }
    $content_hash = estimation_draft_content_hash($form_data);

    $customer_name = $_POST['customer_name'] ?? 'Unnamed Customer';
    $customer_email = $_POST['customer_email'] ?? '';
    $customer_phone = $_POST['customer_phone'] ?? '';
    $job = $_POST['job_title'] ?? '';
    $user_id = (int) $_SESSION['user_id'];

    // Clone / keep-both always creates a new draft row from the current form.
    if ($is_clone || !$est_id) {
        $est_number = 'DRAFT-' . date('YmdHis') . '-' . mt_rand(1000, 9999);

        $stmt = $pdo->prepare("
            INSERT INTO estimations
            (estimation_number, customer_name, customer_email, customer_phone, job_description,
             status, created_by, draft_data, draft_step, last_auto_saved, total_amount,
             draft_origin, draft_revision, draft_content_hash)
            VALUES
            (:num, :name, :email, :phone, :job, 'Draft', :user, :draft, :step, NOW(), 0,
             :origin, 1, :hash)
        ");

        $stmt->execute([
            'num' => $est_number,
            'name' => $customer_name,
            'email' => $customer_email,
            'phone' => $customer_phone,
            'job' => $job,
            'user' => $user_id,
            'draft' => $draft_json,
            'step' => $current_step,
            'origin' => $draft_origin,
            'hash' => $content_hash,
        ]);

        $est_id = (int) $pdo->lastInsertId();
        if ($est_id <= 0) {
            throw new RuntimeException('Failed to create draft');
        }

        if ($is_step_checkpoint && $checkpoint_step >= 1) {
            estimation_draft_store_step_checkpoint(
                $pdo,
                $est_id,
                $checkpoint_step,
                $draft_json,
                $content_hash,
                $user_id
            );
        }

        $pdo->commit();

        echo json_encode([
            'success' => true,
            'est_id' => $est_id,
            'estimation_number' => $est_number,
            'message' => $is_clone ? 'Draft copy saved successfully' : 'Draft saved successfully',
            'timestamp' => estimation_draft_utc_now(),
            'draft_origin' => $draft_origin,
            'draft_revision' => 1,
            'draft_content_hash' => $content_hash,
            'noop' => false,
        ]);
        exit;
    }

    $existing = estimation_fetch_draft_row(
        $pdo,
        (int) $est_id,
        'id, estimation_number, draft_data, draft_step, draft_origin, draft_revision, draft_content_hash, last_auto_saved, status',
        true
    );

    if (!$existing || strtolower((string) ($existing['status'] ?? '')) !== 'draft') {
        $pdo->rollBack();
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'message' => 'Draft estimation not found or unauthorized',
        ]);
        exit;
    }

    $storedRevision = (int) ($existing['draft_revision'] ?? 0);
    $storedHash = $existing['draft_content_hash'] ?? null;
    if (!$storedHash && !empty($existing['draft_data'])) {
        $storedHash = estimation_draft_content_hash($existing['draft_data']);
    }

    // Identical content: skip draft row update unless saving a step checkpoint.
    if (
        !$is_step_checkpoint
        && $storedHash
        && hash_equals((string) $storedHash, $content_hash)
    ) {
        $pdo->commit();
        echo json_encode([
            'success' => true,
            'est_id' => $est_id,
            'estimation_number' => $existing['estimation_number'] ?? null,
            'message' => 'Draft already up to date',
            'timestamp' => estimation_draft_format_timestamp($existing['last_auto_saved']) ?: estimation_draft_utc_now(),
            'draft_origin' => $existing['draft_origin'] ?: $draft_origin,
            'draft_revision' => $storedRevision > 0 ? $storedRevision : 1,
            'draft_content_hash' => $content_hash,
            'noop' => true,
        ]);
        exit;
    }

    // Conflict: another device advanced the revision (not for step checkpoints — they force-sync).
    if (!$is_override && !$is_step_checkpoint && $base_revision !== $storedRevision) {
        $pdo->rollBack();
        $serverDraft = [];
        if (!empty($existing['draft_data'])) {
            $decoded = json_decode((string) $existing['draft_data'], true);
            if (is_array($decoded)) {
                $serverDraft = $decoded;
            }
        }
        http_response_code(409);
        echo json_encode([
            'success' => false,
            'conflict' => true,
            'message' => 'Draft was updated elsewhere. Choose which version to keep.',
            'est_id' => $est_id,
            'draft_revision' => $storedRevision,
            'draft_content_hash' => $storedHash,
            'last_auto_saved' => estimation_draft_format_timestamp($existing['last_auto_saved']),
            'timestamp' => estimation_draft_format_timestamp($existing['last_auto_saved']),
            'draft_data' => $serverDraft,
            'draft_step' => isset($existing['draft_step']) ? (int) $existing['draft_step'] : 1,
        ]);
        exit;
    }

    $existingOrigin = $existing['draft_origin'] ?: null;
    $resolvedOrigin = $draft_origin;
    if (!in_array($draft_origin, ['manual', 'recovered'], true)) {
        $resolvedOrigin = $existingOrigin ?: 'autosave';
    }

    $newRevision = $storedRevision + 1;
    if ($newRevision < 1) {
        $newRevision = 1;
    }

    // Step checkpoints are stored separately (one per wizard step). Autosaves only update live draft_data.
    if ($is_step_checkpoint && $storedHash && hash_equals((string) $storedHash, $content_hash)) {
        estimation_draft_store_step_checkpoint(
            $pdo,
            (int) $est_id,
            $checkpoint_step,
            $draft_json,
            $content_hash,
            $user_id
        );

        $pdo->commit();

        echo json_encode([
            'success' => true,
            'est_id' => $est_id,
            'estimation_number' => $existing['estimation_number'] ?? null,
            'message' => 'Step checkpoint saved',
            'timestamp' => estimation_draft_utc_now(),
            'draft_origin' => $existing['draft_origin'] ?: $draft_origin,
            'draft_revision' => $storedRevision > 0 ? $storedRevision : 1,
            'draft_content_hash' => $content_hash,
            'checkpoint_step' => $checkpoint_step,
            'noop' => false,
        ]);
        exit;
    }

    $ownerScope = estimation_owner_scope('created_by', 'user');
    $stmt = $pdo->prepare("
        UPDATE estimations
        SET draft_data = :draft,
            draft_step = :step,
            last_auto_saved = NOW(),
            customer_name = :name,
            customer_email = :email,
            customer_phone = :phone,
            job_description = :job,
            draft_origin = :origin,
            draft_revision = :revision,
            draft_content_hash = :hash
        WHERE id = :id AND status = 'Draft'{$ownerScope['sql']}
    ");

    $stmt->execute(array_merge([
        'id' => $est_id,
        'draft' => $draft_json,
        'step' => $current_step,
        'name' => $customer_name,
        'email' => $customer_email,
        'phone' => $customer_phone,
        'job' => $job,
        'origin' => $resolvedOrigin,
        'revision' => $newRevision,
        'hash' => $content_hash,
    ], $ownerScope['params']));

    if ($stmt->rowCount() < 1) {
        $pdo->rollBack();
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'message' => 'Draft estimation not found or unauthorized',
        ]);
        exit;
    }

    if ($is_step_checkpoint && $checkpoint_step >= 1) {
        estimation_draft_store_step_checkpoint(
            $pdo,
            (int) $est_id,
            $checkpoint_step,
            $draft_json,
            $content_hash,
            $user_id
        );
    }

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'est_id' => $est_id,
        'estimation_number' => $existing['estimation_number'] ?? null,
        'message' => $is_step_checkpoint ? 'Step checkpoint saved' : 'Draft saved successfully',
        'timestamp' => estimation_draft_utc_now(),
        'draft_origin' => $resolvedOrigin,
        'draft_revision' => $newRevision,
        'draft_content_hash' => $content_hash,
        'checkpoint_step' => $is_step_checkpoint ? $checkpoint_step : null,
        'noop' => false,
    ]);
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error saving draft: ' . $e->getMessage(),
    ]);
}
