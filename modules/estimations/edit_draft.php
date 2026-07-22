<?php
require_once __DIR__ . '/../../config/app.php';
checkAuth();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/permissions_helper.php';
require_once __DIR__ . '/../../libs/EstimationAuditMigrator.php';
require_once __DIR__ . '/../../libs/ProductionLabourMigrator.php';
require_once __DIR__ . '/../../includes/estimation_draft_restore_helper.php';
require_once __DIR__ . '/../../includes/estimation_access_helper.php';
require_once __DIR__ . '/../../includes/material_match_helper.php';
permissions_require_one_of(['manage_estimations']);
EstimationAuditMigrator::ensure($pdo);
ProductionLabourMigrator::ensure($pdo);

$est_id = isset($_GET['id']) ? (int)$_GET['id'] : null;

if (!$est_id) {
    redirect('list?error=Invalid estimation ID');
}

$estimation = estimation_fetch_draft_row($pdo, $est_id);

if (!$estimation) {
    redirect('list?error=Draft estimation not found or unauthorized');
}

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

$draft_resolution = estimation_resolve_draft_fields($pdo, $estimation);
$draft_data = $draft_resolution['fields'];
$draft_source = $draft_resolution['source'];
$draft_repaired = !empty($draft_resolution['repaired']);

if ($draft_repaired) {
    estimation_repair_draft_data(
        $pdo,
        (int) $est_id,
        $draft_data,
        (int) ($estimation['draft_step'] ?? 1)
    );
}

$user_email = '';
$userStmt = $pdo->prepare('SELECT email FROM users WHERE id = :id LIMIT 1');
$userStmt->execute(['id' => $_SESSION['user_id']]);
$user_email = (string) ($userStmt->fetchColumn() ?: '');

include '../../includes/header.php';
?>

<style>
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

    .draft-bar {
        background: #f8fafc;
        border: 1px solid #e2e8f0;
    }

    @keyframes fadeInOut {
        0% { opacity: 0; transform: translateY(10px); }
        10% { opacity: 1; transform: translateY(0); }
        90% { opacity: 1; transform: translateY(0); }
        100% { opacity: 0; transform: translateY(10px); }
    }
</style>

<div class="draft-bar rounded-lg px-4 py-3 mb-6 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
    <div class="min-w-0 flex-1">
        <div class="flex flex-wrap items-center gap-x-3 gap-y-1 text-sm">
            <span class="font-semibold text-gray-800 truncate">
                #<?php echo htmlspecialchars($estimation['estimation_number']); ?>
            </span>
            <span class="text-gray-500">Step <?php echo (int) ($estimation['draft_step'] ?? 1); ?>/8</span>
            <?php if ($estimation['last_auto_saved']): ?>
            <span class="text-gray-500">Saved <?php echo date('M d, H:i', strtotime($estimation['last_auto_saved'])); ?></span>
            <?php endif; ?>
        </div>
        <?php if ($draft_repaired): ?>
        <p class="text-xs text-amber-800 mt-1 font-semibold">Fields rebuilt from saved line items — any incomplete draft snapshot was merged with stored data.</p>
        <?php else: ?>
        <p class="text-xs text-gray-500 mt-1">Changes auto-save to this draft only.</p>
        <?php endif; ?>
    </div>
    <div class="flex items-center gap-2 shrink-0">
        <div class="relative" id="draftHistoryWrap">
            <button type="button" id="draftHistoryToggle"
                class="inline-flex items-center gap-1.5 bg-white border border-gray-300 text-gray-700 px-3 py-2 rounded-lg hover:bg-gray-50 text-sm font-semibold">
                <i data-lucide="history" class="h-4 w-4" aria-hidden="true"></i>
                History
                <i data-lucide="chevron-down" class="h-4 w-4" aria-hidden="true"></i>
            </button>
            <div id="draftHistoryPanel"
                class="hidden absolute right-0 mt-2 w-72 bg-white border border-gray-200 rounded-lg shadow-lg z-20 overflow-hidden">
                <div class="px-3 py-2 border-b border-gray-100 text-xs font-semibold text-gray-500 uppercase tracking-wide">
                    Step checkpoints (8 steps)
                </div>
                <div id="draftHistoryList" class="max-h-64 overflow-y-auto">
                    <p class="px-3 py-4 text-sm text-gray-500">Loading…</p>
                </div>
            </div>
        </div>
        <button type="button" onclick="openDiscardModal()"
            class="inline-flex items-center gap-1.5 bg-red-50 border border-red-200 text-red-700 px-3 py-2 rounded-lg hover:bg-red-100 text-sm font-semibold">
            <i data-lucide="trash-2" class="h-4 w-4" aria-hidden="true"></i>
            Discard
        </button>
    </div>
</div>

<div class="mb-6">
    <div class="flex items-center gap-2 mb-4">
        <a href="list?view=drafts" class="text-green-600 hover:underline flex items-center">
            <i data-lucide="arrow-left" class="mr-1 inline-block h-4 w-4 flex-shrink-0" aria-hidden="true"></i> Back to Drafts
        </a>
    </div>
    <h1 class="text-3xl font-bold text-gray-800">Edit Estimation Draft</h1>
    <p class="text-gray-600">Resume and edit this draft. Other drafts are not affected.</p>
</div>

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

<div class="bg-white shadow-md rounded-xl p-8">
    <div id="estimation-draft-status" class="mb-4 text-sm text-gray-600 hidden" aria-live="polite"></div>
    <form id="estimationForm" method="POST" action="save" data-unsaved-guard data-unsaved-label="the estimation form" data-unsaved-discard="reload">
        <input type="hidden" name="est_id" id="est_id" value="<?php echo $est_id; ?>">
        <input type="hidden" name="is_draft_edit" value="1">
        <?php include __DIR__ . '/create_form_partial.php'; ?>
    </form>
</div>

<?php include __DIR__ . '/estimation_wizard_modals.php'; ?>

<div id="discardModal" class="fixed inset-0 bg-black bg-opacity-50 hidden flex items-center justify-center z-50">
    <div class="bg-white rounded-xl shadow-2xl p-8 w-full max-w-md">
        <h3 class="text-2xl font-bold text-gray-800 mb-4">Discard Draft?</h3>
        <p class="text-gray-600 mb-6">Are you sure you want to discard this draft? This action cannot be undone.</p>
        <div class="flex gap-3">
            <button type="button" onclick="closeDiscardModal()"
                class="flex-1 bg-gray-300 text-gray-800 font-bold py-2 rounded-lg hover:bg-gray-400">
                Cancel
            </button>
            <button type="button" onclick="confirmDiscard()"
                class="flex-1 bg-red-600 text-white font-bold py-2 rounded-lg hover:bg-red-700">
                Discard Draft
            </button>
        </div>
    </div>
</div>

<script>
    window.estimationWizardConfig = {
        userId: <?php echo (int) $_SESSION['user_id']; ?>,
        userEmail: <?php echo json_encode($user_email); ?>,
        baseUrl: <?php echo json_encode(BASE_URL); ?>,
        draftMode: true,
        draftEstId: <?php echo $est_id; ?>,
        draftData: <?php echo json_encode($draft_data); ?>,
        draftSource: <?php echo json_encode($draft_source); ?>,
        draftHydratedFromDb: <?php echo $draft_repaired ? 'true' : 'false'; ?>,
        serverDraftUpdatedAt: <?php echo json_encode($estimation['last_auto_saved'] ?? null); ?>,
        serverDraftStep: <?php echo (int) ($estimation['draft_step'] ?? 1); ?>,
        serverDraftRevision: <?php echo (int) ($estimation['draft_revision'] ?? 0); ?>,
        serverDraftContentHash: <?php echo json_encode($estimation['draft_content_hash'] ?? null); ?>,
        endpoints: {
            saveDraft: 'save_draft',
            discardDraft: 'discard_draft',
            draftVersions: 'draft_versions',
            materialSearch: <?php echo json_encode(BASE_URL . 'modules/materials/search.php'); ?>,
            materialSave: <?php echo json_encode(BASE_URL . 'modules/materials/save.php'); ?>,
            sessionPing: <?php echo json_encode(BASE_URL . 'modules/auth/session_ping'); ?>,
            reauth: <?php echo json_encode(BASE_URL . 'modules/auth/reauth'); ?>
        },
        stdMaterialSlots: <?php echo json_encode(ESTIMATION_STD_MATERIAL_SLOTS); ?>
    };

    function openDiscardModal() {
        document.getElementById('discardModal').classList.remove('hidden');
    }

    function closeDiscardModal() {
        document.getElementById('discardModal').classList.add('hidden');
    }

    function confirmDiscard() {
        fetch('discard_draft', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'est_id=<?php echo $est_id; ?>'
        })
        .then(function (response) { return response.json(); })
        .then(function (data) {
            if (!data.success) {
                throw new Error(data.message || 'Discard failed');
            }
            if (window.FormDraftStore) {
                var userId = <?php echo (int) $_SESSION['user_id']; ?>;
                var estId = <?php echo $est_id; ?>;
                var keys = [
                    'estimation_draft:' + userId + ':active',
                    'estimation_draft:' + userId,
                    'estimation_draft:' + userId + ':' + estId
                ];
                return Promise.all(keys.map(function (key) {
                    return FormDraftStore.remove(key).catch(function () {});
                })).then(function () {
                    FormDraftStore.clearPointer();
                });
            }
        })
        .then(function () {
            window.location.href = 'list';
        })
        .catch(function (err) {
            alert('Error discarding draft: ' + err.message);
            closeDiscardModal();
        });
    }
</script>
<script src="../../assets/js/form-draft-store.js?v=<?php echo filemtime(__DIR__ . '/../../assets/js/form-draft-store.js'); ?>"></script>
<script src="../../assets/js/session-guard.js?v=<?php echo filemtime(__DIR__ . '/../../assets/js/session-guard.js'); ?>"></script>
<script src="../../assets/js/estimation_wizard.js?v=<?php echo filemtime(__DIR__ . '/../../assets/js/estimation_wizard.js'); ?>"></script>
<script>if (typeof window.refreshAppShellIcons === 'function') { window.refreshAppShellIcons(); }</script>
<?php include '../../includes/footer.php'; ?>
