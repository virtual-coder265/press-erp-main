<?php
require_once __DIR__ . '/../../config/app.php';
checkAuth();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/permissions_helper.php';
permissions_require_one_of(['manage_estimations']);

$est_id = isset($_GET['id']) ? (int)$_GET['id'] : null;

if (!$est_id) {
    redirect('list?error=Invalid estimation ID');
}

// Fetch the draft estimation
$stmt = $pdo->prepare("SELECT * FROM estimations WHERE id = :id AND created_by = :user AND status = 'Draft'");
$stmt->execute(['id' => $est_id, 'user' => $_SESSION['user_id']]);
$estimation = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$estimation) {
    redirect('list?error=Draft estimation not found or unauthorized');
}

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

// Parse draft data if available
$draft_data = [];
if ($estimation['draft_data']) {
    $draft_data = json_decode($estimation['draft_data'], true) ?? [];
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

    .draft-info-banner {
        background: linear-gradient(to right, #e3f2fd, #f3e5f5);
        border-left: 4px solid #2196f3;
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

<!-- Draft Info Banner -->
<div class="bg-blue-50 border border-blue-200 rounded-lg p-4 mb-6 draft-info-banner">
    <div class="flex items-start gap-3">
        <i data-lucide="info" class="h-5 w-5 text-blue-600 flex-shrink-0 mt-0.5"></i>
        <div>
            <h3 class="font-semibold text-blue-900">Editing Draft Estimation</h3>
            <p class="text-sm text-blue-800 mt-1">
                <strong>ID:</strong> <?php echo htmlspecialchars($estimation['estimation_number']); ?> |
                <strong>Last auto-saved:</strong> <?php echo $estimation['last_auto_saved'] ? date('M d, Y H:i:s', strtotime($estimation['last_auto_saved'])) : 'Never'; ?>
            </p>
            <p class="text-xs text-blue-700 mt-2">All changes are automatically saved as you navigate between steps.</p>
        </div>
    </div>
</div>

<div class="mb-6">
    <div class="flex items-center gap-2 mb-4">
        <a href="list" class="text-green-600 hover:underline flex items-center">
            <i data-lucide="arrow-left" class="mr-1 inline-block h-4 w-4 flex-shrink-0" aria-hidden="true"></i> Back to Estimations
        </a>
    </div>
    <h1 class="text-3xl font-bold text-gray-800">Edit Estimation Draft</h1>
    <p class="text-gray-600">Make changes to your estimation. All progress is automatically saved.</p>
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

<!-- Form (Content from create.php, but with draft data loading) -->
<div class="bg-white shadow-md rounded-xl p-8">
    <form id="estimationForm" method="POST" action="save">
        <!-- Hidden field for draft tracking -->
        <input type="hidden" name="est_id" id="est_id" value="<?php echo $est_id; ?>">
        <input type="hidden" name="is_draft_edit" value="1">

        <!-- Include the full form from create.php here -->
        <!-- For brevity, importing the main sections -->
        <?php 
        // Rather than duplicate, we'll set a flag to inject draft data into create form
        $_draft_editing = true;
        $_draft_id = $est_id;
        include 'create_form_partial.php';
        ?>
    </form>
</div>

<!-- Discard Draft Modal -->
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
    // Set draft mode for the wizard
    window.draftMode = true;
    window.draftEstId = <?php echo $est_id; ?>;
    window.draftData = <?php echo json_encode($draft_data); ?>;

    function openDiscardModal() {
        document.getElementById('discardModal').classList.remove('hidden');
    }

    function closeDiscardModal() {
        document.getElementById('discardModal').classList.add('hidden');
    }

    function confirmDiscard() {
        fetch('discard_draft.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'est_id=<?php echo $est_id; ?>'
        })
        .then(() => window.location.href = 'list')
        .catch(err => {
            alert('Error discarding draft: ' + err);
            closeDiscardModal();
        });
    }
</script>

<script src="../../assets/js/estimation_wizard.js?v=<?php echo time(); ?>"></script>
<script>if (typeof window.refreshAppShellIcons === 'function') { window.refreshAppShellIcons(); }</script>
<?php include '../../includes/footer.php'; ?>
