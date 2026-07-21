<?php
require_once __DIR__ . '/../../config/app.php';
checkAuthApi();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/permissions_helper.php';
require_once __DIR__ . '/../../libs/EstimationAuditMigrator.php';
require_once __DIR__ . '/../../includes/estimation_draft_version_helper.php';
permissions_require_one_of(['manage_estimations']);

EstimationAuditMigrator::ensure($pdo);

header('Content-Type: application/json');

$userId = (int) $_SESSION['user_id'];

/**
 * @return array<string, mixed>|null
 */
function draft_versions_load_owned(PDO $pdo, int $estId, int $userId, bool $forUpdate = false): ?array
{
    $lock = $forUpdate ? ' FOR UPDATE' : '';
    $stmt = $pdo->prepare(
        "SELECT id, estimation_number, draft_data, draft_step, draft_revision, draft_content_hash,
                customer_name, customer_email, customer_phone, job_description,
                last_auto_saved, status
         FROM estimations
         WHERE id = :id AND created_by = :user AND status = 'Draft'
         LIMIT 1{$lock}"
    );
    $stmt->execute(['id' => $estId, 'user' => $userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
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

        $versions = estimation_draft_list_versions($pdo, $estId);
        $currentRevision = (int) ($estimation['draft_revision'] ?? 0);

        $items = [];
        $items[] = [
            'revision' => $currentRevision,
            'draft_step' => (int) ($estimation['draft_step'] ?? 1),
            'saved_at' => $estimation['last_auto_saved'] ?? null,
            'is_current' => true,
            'label' => 'Current',
        ];

        foreach ($versions as $version) {
            $items[] = [
                'revision' => (int) $version['revision'],
                'draft_step' => (int) ($version['draft_step'] ?? 1),
                'saved_at' => $version['saved_at'] ?? null,
                'is_current' => false,
                'label' => 'rev ' . (int) $version['revision'],
            ];
        }

        // Cap at 4 entries total including current.
        $items = array_slice($items, 0, ESTIMATION_DRAFT_VERSION_LIMIT);

        echo json_encode([
            'success' => true,
            'est_id' => $estId,
            'estimation_number' => $estimation['estimation_number'] ?? null,
            'current_revision' => $currentRevision,
            'versions' => $items,
        ]);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = strtolower(trim((string) ($_POST['action'] ?? '')));
        $estId = isset($_POST['est_id']) ? (int) $_POST['est_id'] : 0;
        $revision = isset($_POST['revision']) ? (int) $_POST['revision'] : 0;

        if ($estId <= 0 || $revision <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid estimation or revision']);
            exit;
        }

        if ($action !== 'restore') {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Unknown action']);
            exit;
        }

        $pdo->beginTransaction();

        $estimation = draft_versions_load_owned($pdo, $estId, $userId, true);
        if (!$estimation) {
            $pdo->rollBack();
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Draft not found or unauthorized']);
            exit;
        }

        $currentRevision = (int) ($estimation['draft_revision'] ?? 0);
        if ($revision === $currentRevision) {
            $pdo->commit();
            echo json_encode([
                'success' => true,
                'message' => 'Already on this version',
                'est_id' => $estId,
                'draft_revision' => $currentRevision,
            ]);
            exit;
        }

        $versionRow = estimation_draft_get_version($pdo, $estId, $revision);
        if (!$versionRow || empty($versionRow['draft_data'])) {
            $pdo->rollBack();
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Version not found']);
            exit;
        }

        $restoredData = (string) $versionRow['draft_data'];
        $decoded = json_decode($restoredData, true);
        if (!is_array($decoded)) {
            $pdo->rollBack();
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Invalid version data']);
            exit;
        }

        $contentHash = estimation_draft_content_hash($decoded);
        $storedRevision = $currentRevision > 0 ? $currentRevision : 1;

        if (!empty($estimation['draft_data'])) {
            estimation_draft_store_version(
                $pdo,
                $estId,
                $storedRevision,
                (string) $estimation['draft_data'],
                (int) ($estimation['draft_step'] ?? 1),
                $estimation['draft_content_hash'] ?? null,
                $userId
            );
        }

        $newRevision = $storedRevision + 1;
        $restoredStep = (int) ($versionRow['draft_step'] ?? 1);
        $customerName = $decoded['customer_name'] ?? ($estimation['customer_name'] ?? 'Unnamed Customer');
        $customerEmail = $decoded['customer_email'] ?? ($estimation['customer_email'] ?? '');
        $customerPhone = $decoded['customer_phone'] ?? ($estimation['customer_phone'] ?? '');
        $job = $decoded['job_title'] ?? ($estimation['job_description'] ?? '');

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
             WHERE id = :id AND created_by = :user AND status = 'Draft'"
        );
        $updateStmt->execute([
            'id' => $estId,
            'user' => $userId,
            'draft' => $restoredData,
            'step' => $restoredStep,
            'name' => $customerName,
            'email' => $customerEmail,
            'phone' => $customerPhone,
            'job' => $job,
            'revision' => $newRevision,
            'hash' => $contentHash,
        ]);

        $pdo->commit();

        echo json_encode([
            'success' => true,
            'message' => 'Version restored',
            'est_id' => $estId,
            'draft_revision' => $newRevision,
            'draft_step' => $restoredStep,
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
