<?php
require_once __DIR__ . '/../../config/app.php';
checkAuth();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/permissions_helper.php';
permissions_require_one_of(['manage_dispatch']);
require_once __DIR__ . '/../../includes/work_order_helper.php';

work_order_bootstrap($pdo);

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
if ($id <= 0) {
    redirect('modules/dispatch/list');
}

$stmt = $pdo->prepare("SELECT * FROM dispatch_register WHERE id = ?");
$stmt->execute([$id]);
$dispatch = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$dispatch) {
    redirect('modules/dispatch/list?error=entry_not_found');
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '', 'dispatch_collect')) {
        $errors[] = 'Security check failed. Please reload the page and try again.';
    }

    $collectedByName = trim((string) ($_POST['collected_by_name'] ?? ''));
    $collectedPhone = trim((string) ($_POST['collected_phone'] ?? ''));
    $collectionNotes = trim((string) ($_POST['collection_notes'] ?? ''));
    $collectedAt = trim((string) ($_POST['collected_at'] ?? date('Y-m-d H:i:s')));

    if ($collectedByName === '') {
        $errors[] = 'Collector name is required.';
    }

    if (empty($errors)) {
        $pdo->beginTransaction();
        try {
            $update = $pdo->prepare("
                UPDATE dispatch_register
                SET collected_by_name = :collected_by_name,
                    collected_phone = :collected_phone,
                    collection_notes = :collection_notes,
                    collected_at = :collected_at,
                    closed_by = :closed_by
                WHERE id = :id
            ");
            $update->execute([
                'collected_by_name' => $collectedByName,
                'collected_phone' => $collectedPhone ?: null,
                'collection_notes' => $collectionNotes ?: null,
                'collected_at' => $collectedAt,
                'closed_by' => $_SESSION['user_id'] ?? null,
                'id' => $id,
            ]);

            if (!empty($dispatch['work_order_id'])) {
                $pdo->prepare("
                    UPDATE work_orders
                    SET status = 'Completed',
                        completed_at = COALESCE(completed_at, NOW()),
                        updated_by = ?
                    WHERE id = ?
                ")->execute([$_SESSION['user_id'] ?? null, (int) $dispatch['work_order_id']]);

                $pdo->prepare("
                    INSERT INTO production_movements
                        (work_order_id, movement_type, sender_user_id, remarks)
                    VALUES (?, 'collected', ?, ?)
                ")->execute([
                    (int) $dispatch['work_order_id'],
                    $_SESSION['user_id'] ?? null,
                    'Customer collection recorded for dispatch entry #' . $id,
                ]);
            }

            $pdo->commit();
            $_SESSION['success'] = 'Customer collection recorded successfully.';
            redirect('modules/dispatch/view?id=' . $id);
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errors[] = $exception->getMessage();
        }
    }
}

include '../../includes/header.php';
?>

<div class="mb-6">
    <a href="view?id=<?php echo (int) $dispatch['id']; ?>" class="text-green-600 hover:underline flex items-center mb-4">
        <i class="material-icons text-sm mr-1">arrow_back</i> Back to Dispatch Entry
    </a>
    <h1 class="text-3xl font-bold text-gray-800">Record Customer Collection</h1>
    <p class="text-gray-600">Close the dispatch cycle after the customer receives the job.</p>
</div>

<?php if (!empty($errors)): ?>
    <div class="bg-red-50 border border-red-200 text-red-800 rounded-lg p-4 mb-6">
        <ul class="list-disc list-inside text-sm">
            <?php foreach ($errors as $error): ?>
                <li><?php echo htmlspecialchars($error); ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<form method="POST" class="bg-white shadow rounded-xl p-8 max-w-3xl space-y-6">
    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token('dispatch_collect')); ?>">
    <input type="hidden" name="id" value="<?php echo (int) $dispatch['id']; ?>">

    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
        <div>
            <label class="block text-gray-700 font-bold mb-2">Collected By *</label>
            <input type="text" name="collected_by_name" required value="<?php echo htmlspecialchars((string) ($dispatch['collected_by_name'] ?? '')); ?>" class="w-full px-4 py-2 border border-gray-300 rounded-lg">
        </div>
        <div>
            <label class="block text-gray-700 font-bold mb-2">Phone</label>
            <input type="text" name="collected_phone" value="<?php echo htmlspecialchars((string) ($dispatch['collected_phone'] ?? '')); ?>" class="w-full px-4 py-2 border border-gray-300 rounded-lg">
        </div>
        <div>
            <label class="block text-gray-700 font-bold mb-2">Collection Date & Time</label>
            <?php echo press_datetime_picker_field([
                'name' => 'collected_at',
                'value' => !empty($dispatch['collected_at']) ? date('Y-m-d H:i', strtotime((string) $dispatch['collected_at'])) : date('Y-m-d H:i'),
                'mode' => 'datetime',
                'class' => 'w-full px-4 py-2 border border-gray-300 rounded-lg',
            ]); ?>
        </div>
        <div>
            <label class="block text-gray-700 font-bold mb-2">Linked Work Order</label>
            <input type="text" disabled value="<?php echo htmlspecialchars((string) ($dispatch['work_order_number'] ?? 'Legacy/manual')); ?>" class="w-full px-4 py-2 border border-gray-300 rounded-lg bg-gray-50">
        </div>
    </div>

    <div>
        <label class="block text-gray-700 font-bold mb-2">Collection Notes</label>
        <textarea name="collection_notes" rows="4" class="w-full px-4 py-2 border border-gray-300 rounded-lg"><?php echo htmlspecialchars((string) ($dispatch['collection_notes'] ?? '')); ?></textarea>
    </div>

    <div class="flex gap-4">
        <button type="submit" class="bg-green-600 text-white px-6 py-3 rounded-lg font-bold hover:bg-green-700 transition">Save Collection</button>
        <a href="view?id=<?php echo (int) $dispatch['id']; ?>" class="bg-gray-300 text-gray-700 px-6 py-3 rounded-lg font-bold hover:bg-gray-400 transition">Cancel</a>
    </div>
</form>

<?php include '../../includes/footer.php'; ?>
