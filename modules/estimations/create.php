<?php
require_once __DIR__ . '/../../config/app.php';
checkAuth();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/permissions_helper.php';
require_once __DIR__ . '/../../libs/EstimationAuditMigrator.php';
permissions_require_one_of(['manage_estimations']);
EstimationAuditMigrator::ensure($pdo);

// Fetch all materials with their latest rates
$stmt = $pdo->query("
    SELECT m.*, r.rate, mc.name as category_name
    FROM materials m
    LEFT JOIN (
        SELECT material_id, rate
        FROM material_rates
        WHERE id IN (SELECT MAX(id) FROM material_rates GROUP BY material_id)
    ) r ON m.id = r.material_id
    LEFT JOIN material_categories mc ON m.category_id = mc.id
    ORDER BY m.name
");
$all_materials = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Separate binding materials
$binding_materials = array_filter($all_materials, fn($m) => strtolower($m['category_name'] ?? '') === 'binding materials');
$binding_cat_id = null;
$catStmt = $pdo->query("SELECT id FROM material_categories WHERE name='Binding Materials' LIMIT 1");
$binding_cat_id = $catStmt->fetchColumn();

// Fetch existing drafts for the current user
$stmt = $pdo->prepare("
    SELECT id, estimation_number, customer_name, job_description, draft_step, last_auto_saved, created_at
    FROM estimations
    WHERE created_by = :user AND status = 'Draft'
    ORDER BY last_auto_saved DESC, created_at DESC
    LIMIT 5
");
$stmt->execute(['user' => $_SESSION['user_id']]);
$existing_drafts = $stmt->fetchAll(PDO::FETCH_ASSOC);

$user_email = '';
$userStmt = $pdo->prepare('SELECT email FROM users WHERE id = :id LIMIT 1');
$userStmt->execute(['id' => $_SESSION['user_id']]);
$user_email = (string) ($userStmt->fetchColumn() ?: '');

$cookie_resume_draft = null;
if (!empty($_COOKIE['est_draft_ptr'])) {
    $ptr = json_decode($_COOKIE['est_draft_ptr'], true);
    $ptrEstId = isset($ptr['estId']) ? (int) $ptr['estId'] : 0;
    if ($ptrEstId > 0) {
        $resumeStmt = $pdo->prepare("
            SELECT id, estimation_number, customer_name, draft_step, last_auto_saved
            FROM estimations
            WHERE id = :id AND created_by = :user AND status = 'Draft'
            LIMIT 1
        ");
        $resumeStmt->execute(['id' => $ptrEstId, 'user' => $_SESSION['user_id']]);
        $cookie_resume_draft = $resumeStmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
}

include '../../includes/header.php';
?>

<style>
    /* Hide number input spinners to prevent accidental value changes on scroll */
    input[type="number"]::-webkit-outer-spin-button,
    input[type="number"]::-webkit-inner-spin-button {
        -webkit-appearance: none;
        margin: 0;
    }
    input[type="number"] {
        -moz-appearance: textfield;
    }

    button.inline-flex svg.lucide,
    button.flex svg.lucide {
        width: 1.125rem;
        height: 1.125rem;
        flex-shrink: 0;
    }

    /* Auto-save notification animation */
    @keyframes fadeInOut {
        0% {
            opacity: 0;
            transform: translateY(10px);
        }
        10% {
            opacity: 1;
            transform: translateY(0);
        }
        90% {
            opacity: 1;
            transform: translateY(0);
        }
        100% {
            opacity: 0;
            transform: translateY(10px);
        }
    }
</style>

<div class="mb-6">
    <div class="flex items-center gap-2 mb-4">
        <a href="list" class="text-green-600 hover:underline flex items-center">
            <i data-lucide="arrow-left" class="mr-1 inline-block h-4 w-4 flex-shrink-0" aria-hidden="true"></i> Back to Estimations
        </a>
    </div>
    <h1 class="text-3xl font-bold text-gray-800">New Estimation</h1>
    <p class="text-gray-600">Complete the steps below to generate a cost estimation.</p>
</div>

<!-- Existing Drafts Section -->
<?php if (!empty($existing_drafts)): ?>
<div class="bg-amber-50 border border-amber-200 rounded-lg p-6 mb-8">
    <div class="flex items-start gap-3 mb-4">
        <i data-lucide="file-text" class="h-5 w-5 text-amber-600 flex-shrink-0 mt-0.5"></i>
        <div>
            <h3 class="font-semibold text-amber-900">Resume Your Draft</h3>
            <p class="text-sm text-amber-800 mt-1">You have <?php echo count($existing_drafts); ?> unsaved draft(s) in progress.</p>
        </div>
    </div>
    <div class="space-y-2">
        <?php foreach ($existing_drafts as $draft): ?>
            <div class="flex items-center justify-between bg-white p-3 rounded-lg border border-amber-100">
                <div class="flex-1 min-w-0">
                    <p class="font-semibold text-gray-800 truncate">
                        <?php echo htmlspecialchars($draft['customer_name'] ?? 'Unnamed'); ?> 
                        <span class="text-xs text-gray-500 font-normal">#<?php echo htmlspecialchars($draft['estimation_number']); ?></span>
                    </p>
                    <p class="text-xs text-gray-600 truncate">
                        Step <?php echo $draft['draft_step']; ?> 
                        <?php if ($draft['last_auto_saved']): ?>
                            • Last saved: <?php echo date('M d H:i', strtotime($draft['last_auto_saved'])); ?>
                        <?php endif; ?>
                    </p>
                </div>
                <a href="edit_draft?id=<?php echo $draft['id']; ?>"
                    class="ml-2 bg-amber-600 text-white px-4 py-2 rounded-lg hover:bg-amber-700 transition text-sm font-semibold whitespace-nowrap">
                    Continue
                </a>
            </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<?php if ($cookie_resume_draft): ?>
<div class="bg-blue-50 border border-blue-200 rounded-lg p-4 mb-6 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
    <div>
        <p class="font-semibold text-blue-900">Resume your last session</p>
        <p class="text-sm text-blue-800">
            <?php echo htmlspecialchars($cookie_resume_draft['customer_name'] ?? 'Unnamed'); ?>
            — step <?php echo (int) ($cookie_resume_draft['draft_step'] ?? 1); ?>
            <?php if (!empty($cookie_resume_draft['last_auto_saved'])): ?>
                (saved <?php echo date('M d H:i', strtotime($cookie_resume_draft['last_auto_saved'])); ?>)
            <?php endif; ?>
        </p>
    </div>
    <a href="edit_draft?id=<?php echo (int) $cookie_resume_draft['id']; ?>"
        class="bg-blue-600 text-white px-4 py-2 rounded-lg hover:bg-blue-700 transition text-sm font-semibold whitespace-nowrap text-center">
        Resume last draft
    </a>
</div>
<?php endif; ?>

<!-- Progress Steps -->
<div class="bg-white shadow-md rounded-xl p-6 mb-8">
    <div class="flex items-center justify-between">
        <?php
        $steps = ['Client & Job', 'Materials', 'Paper', 'Ink', 'Binding', 'Labour', 'Consumables', 'Totals'];
        foreach ($steps as $index => $label):
            $stepNum = $index + 1;
            ?>
            <div class="flex items-center flex-1 step-indicator">
                <div class="flex flex-col items-center">
                    <div id="step-circle-<?php echo $stepNum; ?>"
                        class="w-10 h-10 rounded-full flex items-center justify-center bg-gray-300 text-gray-600 font-bold transition-colors">
                        <?php echo $stepNum; ?>
                    </div>
                    <span id="step-label-<?php echo $stepNum; ?>"
                        class="text-xs mt-2 text-gray-500 font-semibold transition-colors">
                        <?php echo $label; ?>
                    </span>
                </div>
                <?php if ($stepNum < count($steps)): ?>
                    <div id="step-line-<?php echo $stepNum; ?>" class="flex-1 h-1 mx-2 bg-gray-300 transition-colors"></div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- Form -->
<div class="bg-white shadow-md rounded-xl p-8">
    <div id="estimation-draft-status" class="mb-4 text-sm text-gray-600 hidden" aria-live="polite"></div>
    <form id="estimationForm" method="POST" action="save" novalidate data-unsaved-guard data-unsaved-label="the estimation form" data-unsaved-discard="reload">
        <input type="hidden" name="est_id" id="est_id" value="">
        <?php include __DIR__ . '/create_form_partial.php'; ?>
    </form>
</div>

<?php include __DIR__ . '/estimation_wizard_modals.php'; ?>

<script>
    window.estimationWizardConfig = {
        userId: <?php echo (int) $_SESSION['user_id']; ?>,
        userEmail: <?php echo json_encode($user_email); ?>,
        baseUrl: <?php echo json_encode(BASE_URL); ?>,
        draftMode: false,
        draftEstId: null,
        draftData: null,
        serverDraftUpdatedAt: null,
        serverDraftStep: null,
        serverDraftRevision: 0,
        serverDraftContentHash: null,
        endpoints: {
            saveDraft: 'save_draft',
            discardDraft: 'discard_draft',
            sessionPing: <?php echo json_encode(BASE_URL . 'modules/auth/session_ping'); ?>,
            reauth: <?php echo json_encode(BASE_URL . 'modules/auth/reauth'); ?>
        }
    };
</script>
<script src="../../assets/js/form-draft-store.js?v=<?php echo filemtime(__DIR__ . '/../../assets/js/form-draft-store.js'); ?>"></script>
<script src="../../assets/js/session-guard.js?v=<?php echo filemtime(__DIR__ . '/../../assets/js/session-guard.js'); ?>"></script>
<script src="../../assets/js/estimation_wizard.js?v=<?php echo filemtime(__DIR__ . '/../../assets/js/estimation_wizard.js'); ?>"></script>
<script>if (typeof window.refreshAppShellIcons === 'function') { window.refreshAppShellIcons(); }</script>
<?php include '../../includes/footer.php'; ?>
