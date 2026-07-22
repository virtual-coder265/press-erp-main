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



$userId = (int) $_SESSION['user_id'];



/**

 * @return array<string, mixed>|null

 */

function draft_versions_load_owned(PDO $pdo, int $estId, int $userId, bool $forUpdate = false): ?array

{

    return estimation_fetch_draft_row(

        $pdo,

        $estId,

        'id, estimation_number, draft_data, draft_step, draft_revision, draft_content_hash,

         customer_name, customer_email, customer_phone, job_description,

         last_auto_saved, status',

        $forUpdate

    );

}



try {

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {

        $estId = isset($_GET['est_id']) ? (int) $_GET['est_id'] : 0;

        if ($estId <= 0) {

            http_response_code(400);

            echo json_encode(['success' => false, 'message' => 'Invalid estimation ID']);

            exit;

        }



        $estimation = draft_versions_load_owned($pdo, $estId, $userId);

        if (!$estimation) {

            http_response_code(404);

            echo json_encode(['success' => false, 'message' => 'Draft not found or unauthorized']);

            exit;

        }



        $items = estimation_draft_list_step_history($pdo, $estId, $estimation);

        $currentRevision = (int) ($estimation['draft_revision'] ?? 0);



        echo json_encode([

            'success' => true,

            'est_id' => $estId,

            'estimation_number' => $estimation['estimation_number'] ?? null,

            'current_revision' => $currentRevision,

            'step_count' => ESTIMATION_DRAFT_STEP_COUNT,

            'versions' => $items,

        ]);

        exit;

    }



    if ($_SERVER['REQUEST_METHOD'] === 'POST') {

        $action = strtolower(trim((string) ($_POST['action'] ?? '')));

        $estId = isset($_POST['est_id']) ? (int) $_POST['est_id'] : 0;

        $step = isset($_POST['step']) ? (int) $_POST['step'] : 0;

        // Backward compatibility: revision param treated as step when step omitted.

        if ($step <= 0 && isset($_POST['revision'])) {

            $step = (int) $_POST['revision'];

        }



        if ($estId <= 0 || $step <= 0) {

            http_response_code(400);

            echo json_encode(['success' => false, 'message' => 'Invalid estimation or step']);

            exit;

        }



        if ($action !== 'restore') {

            http_response_code(400);

            echo json_encode(['success' => false, 'message' => 'Unknown action']);

            exit;

        }



        $step = estimation_draft_normalize_step($step);



        $pdo->beginTransaction();



        $estimation = draft_versions_load_owned($pdo, $estId, $userId, true);

        if (!$estimation) {

            $pdo->rollBack();

            http_response_code(404);

            echo json_encode(['success' => false, 'message' => 'Draft not found or unauthorized']);

            exit;

        }



        $currentRevision = (int) ($estimation['draft_revision'] ?? 0);

        $currentStep = estimation_draft_normalize_step((int) ($estimation['draft_step'] ?? 1));



        if ($step === $currentStep && !empty($estimation['draft_data'])) {

            $pdo->commit();

            echo json_encode([

                'success' => true,

                'message' => 'Already on this step',

                'est_id' => $estId,

                'draft_revision' => $currentRevision,

                'draft_step' => $currentStep,

            ]);

            exit;

        }



        $versionRow = estimation_draft_get_step_checkpoint($pdo, $estId, $step);

        if (!$versionRow || empty($versionRow['draft_data'])) {

            $pdo->rollBack();

            http_response_code(404);

            echo json_encode(['success' => false, 'message' => 'No saved checkpoint for this step']);

            exit;

        }



        $restoredData = (string) $versionRow['draft_data'];

        $decoded = json_decode($restoredData, true);

        if (!is_array($decoded)) {

            $pdo->rollBack();

            http_response_code(500);

            echo json_encode(['success' => false, 'message' => 'Invalid checkpoint data']);

            exit;

        }



        $contentHash = estimation_draft_content_hash($decoded);

        $newRevision = ($currentRevision > 0 ? $currentRevision : 0) + 1;



        $customerName = $decoded['customer_name'] ?? ($estimation['customer_name'] ?? 'Unnamed Customer');

        $customerEmail = $decoded['customer_email'] ?? ($estimation['customer_email'] ?? '');

        $customerPhone = $decoded['customer_phone'] ?? ($estimation['customer_phone'] ?? '');

        $job = $decoded['job_title'] ?? ($estimation['job_description'] ?? '');



        $ownerScope = estimation_owner_scope('created_by', 'user');

        $updateStmt = $pdo->prepare(

            "UPDATE estimations

             SET draft_data = :draft,

                 draft_step = :step,

                 last_auto_saved = NOW(),

                 customer_name = :name,

                 customer_email = :email,

                 customer_phone = :phone,

                 job_description = :job,

                 draft_origin = 'manual',

                 draft_revision = :revision,

                 draft_content_hash = :hash

             WHERE id = :id AND status = 'Draft'{$ownerScope['sql']}"

        );

        $updateStmt->execute(array_merge([

            'id' => $estId,

            'draft' => $restoredData,

            'step' => $step,

            'name' => $customerName,

            'email' => $customerEmail,

            'phone' => $customerPhone,

            'job' => $job,

            'revision' => $newRevision,

            'hash' => $contentHash,

        ], $ownerScope['params']));



        $pdo->commit();



        $labels = estimation_draft_step_labels();



        echo json_encode([

            'success' => true,

            'message' => 'Step checkpoint restored',

            'est_id' => $estId,

            'draft_revision' => $newRevision,

            'draft_step' => $step,

            'step_label' => $labels[$step] ?? ('Step ' . $step),

            'draft_data' => $decoded,

            'draft_content_hash' => $contentHash,

            'timestamp' => estimation_draft_utc_now(),

        ]);

        exit;

    }



    http_response_code(405);

    echo json_encode(['success' => false, 'message' => 'Method not allowed']);

} catch (Exception $e) {

    if ($pdo->inTransaction()) {

        $pdo->rollBack();

    }

    http_response_code(500);

    echo json_encode([

        'success' => false,

        'message' => 'Error: ' . $e->getMessage(),

    ]);

}

