<?php
require_once __DIR__ . '/../../config/app.php';
checkAuth();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/permissions_helper.php';
require_once __DIR__ . '/../../libs/EstimationAuditMigrator.php';
require_once __DIR__ . '/../../libs/ProductionLabourMigrator.php';
require_once __DIR__ . '/../../includes/material_match_helper.php';
permissions_require_one_of(['manage_estimations']);
EstimationAuditMigrator::ensure($pdo);
ProductionLabourMigrator::ensure($pdo);

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

$paper_cat_id = null;
$paperCatStmt = $pdo->query("SELECT id FROM material_categories WHERE name='Printing Papers' LIMIT 1");
$paper_cat_id = $paperCatStmt->fetchColumn();

$consumable_materials = array_filter($all_materials, fn($m) => strtolower($m['category_name'] ?? '') === 'printing consumables');
$ink_materials = array_filter($all_materials, fn($m) => strtolower($m['category_name'] ?? '') === 'printing inks');

$all_labour_tasks = ProductionLabourMigrator::fetchTasks($pdo);
$prepress_labour_tasks = array_values(array_filter($all_labour_tasks, fn($t) => ($t['section'] ?? '') === 'prepress'));
$press_labour_tasks = array_values(array_filter($all_labour_tasks, fn($t) => ($t['section'] ?? '') === 'press'));
$finishing_labour_tasks = array_values(array_filter($all_labour_tasks, fn($t) => ($t['section'] ?? '') === 'finishing'));

// Count in-progress drafts for subtle header link (resume happens on list/edit_draft)
$draftCountStmt = $pdo->prepare("
    SELECT COUNT(*) FROM estimations
    WHERE created_by = :user AND status = 'Draft'
      AND draft_data IS NOT NULL AND draft_data != ''
");
$draftCountStmt->execute(['user' => $_SESSION['user_id']]);
$draft_count = (int) $draftCountStmt->fetchColumn();

$user_email = '';
$userStmt = $pdo->prepare('SELECT email FROM users WHERE id = :id LIMIT 1');
$userStmt->execute(['id' => $_SESSION['user_id']]);
$user_email = (string) ($userStmt->fetchColumn() ?: '');

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
    <div class="flex items-center justify-between gap-4 mb-4">
        <a href="list" class="text-green-600 hover:underline flex items-center">
            <i data-lucide="arrow-left" class="mr-1 inline-block h-4 w-4 flex-shrink-0" aria-hidden="true"></i> Back to Estimations
        </a>
        <?php if ($draft_count > 0): ?>
        <p class="text-sm text-gray-500 shrink-0">
            <?php echo $draft_count; ?> draft<?php echo $draft_count === 1 ? '' : 's'; ?> in progress ·
            <a href="list?view=drafts" class="text-green-600 hover:underline font-medium">View drafts</a>
        </p>
        <?php endif; ?>
    </div>
    <h1 class="text-3xl font-bold text-gray-800">New Estimation</h1>
    <p id="estimation-page-subtitle" class="text-gray-600">Complete the steps below to generate a cost estimation.</p>
</div>

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
        freshStart: true,
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
            materialSearch: <?php echo json_encode(BASE_URL . 'modules/materials/search.php'); ?>,
            materialSave: <?php echo json_encode(BASE_URL . 'modules/materials/save.php'); ?>,
            sessionPing: <?php echo json_encode(BASE_URL . 'modules/auth/session_ping'); ?>,
            reauth: <?php echo json_encode(BASE_URL . 'modules/auth/reauth'); ?>
        },
        stdMaterialSlots: <?php echo json_encode(ESTIMATION_STD_MATERIAL_SLOTS); ?>
    };
</script>
<script src="../../assets/js/form-draft-store.js?v=<?php echo filemtime(__DIR__ . '/../../assets/js/form-draft-store.js'); ?>"></script>
<script src="../../assets/js/session-guard.js?v=<?php echo filemtime(__DIR__ . '/../../assets/js/session-guard.js'); ?>"></script>
<script src="../../assets/js/estimation_wizard.js?v=<?php echo filemtime(__DIR__ . '/../../assets/js/estimation_wizard.js'); ?>"></script>
<script>if (typeof window.refreshAppShellIcons === 'function') { window.refreshAppShellIcons(); }</script>
<?php include '../../includes/footer.php'; ?>
