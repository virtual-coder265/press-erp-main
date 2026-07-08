<?php

require_once __DIR__ . '/../../config/app.php';
checkAuth();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/_audit_helpers.php';
require_once __DIR__ . '/../../includes/data_reset_helper.php';

data_reset_require_access();

$groups = data_reset_groups();
$preservedTables = data_reset_preserved_tables();
$defaultGroups = data_reset_default_group_keys();
$groupStats = data_reset_group_stats($pdo, array_keys($groups));

$flash = $_SESSION['data_reset_flash'] ?? null;
unset($_SESSION['data_reset_flash']);

$success = '';
$error = '';

if (is_array($flash)) {
    if (($flash['type'] ?? '') === 'success') {
        $success = (string) ($flash['message'] ?? 'Data reset completed.');
    } else {
        $error = (string) ($flash['message'] ?? 'Data reset failed.');
    }
}

switch (trim((string) ($_GET['error'] ?? ''))) {
    case 'invalid_request':
        $error = 'Your session expired or the request was invalid. Please try again.';
        break;
    case 'confirmation_required':
        $error = 'Type RESET in the confirmation field to proceed.';
        break;
    case 'execution_failed':
        if ($error === '') {
            $error = 'The reset could not be completed.';
        }
        break;
}

$totalRows = 0;
$totalTables = 0;

foreach ($groupStats as $stat) {
    $totalRows += (int) ($stat['row_total'] ?? 0);
    $totalTables += (int) ($stat['table_count'] ?? 0);
}

include __DIR__ . '/../../includes/header.php';
?>

<div class="container mx-auto px-4 py-8 max-w-5xl">
    <div class="flex flex-col gap-2 mb-6">
        <h1 class="text-2xl font-bold text-gray-800">Data reset utility</h1>
        <p class="text-sm text-gray-500">
            Remove mockup and transactional test data from the database. User accounts, roles, permissions, departments, branches, and system settings are always preserved.
        </p>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4">
            <p class="text-xs uppercase tracking-wide text-gray-400">Database</p>
            <span class="inline-flex mt-3 px-3 py-1 rounded-full text-sm font-semibold bg-slate-100 text-slate-700"><?php echo htmlspecialchars(DB_NAME); ?></span>
        </div>
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4">
            <p class="text-xs uppercase tracking-wide text-gray-400">Transactional tables</p>
            <span class="inline-flex mt-3 px-3 py-1 rounded-full text-sm font-semibold bg-blue-50 text-blue-700"><?php echo $totalTables; ?></span>
        </div>
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4">
            <p class="text-xs uppercase tracking-wide text-gray-400">Rows to clear (default)</p>
            <span class="inline-flex mt-3 px-3 py-1 rounded-full text-sm font-semibold bg-amber-50 text-amber-700"><?php echo number_format($totalRows); ?></span>
        </div>
    </div>

    <?php if ($success !== ''): ?>
        <div class="mb-4 rounded-xl border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800">
            <?php echo htmlspecialchars($success); ?>
        </div>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
        <div class="mb-4 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
            <?php echo htmlspecialchars($error); ?>
        </div>
    <?php endif; ?>

    <div class="mb-6 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
        <p class="font-semibold">This action cannot be undone.</p>
        <p class="mt-1">Selected tables will be truncated and related upload folders emptied. Production department configuration and binding types are kept so work-order queues remain usable.</p>
    </div>

    <form method="post" action="<?php echo BASE_URL; ?>modules/admin/data_reset_action" class="space-y-6" id="data-reset-form">
        <input type="hidden" name="_csrf" value="<?php echo htmlspecialchars(csrf_token('data_reset_action')); ?>">

        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-5 space-y-4">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h2 class="text-lg font-semibold text-gray-800">Data groups</h2>
                    <p class="text-sm text-gray-500">Choose which categories of test data to remove.</p>
                </div>
                <div class="flex gap-2">
                    <button type="button" id="select-default-groups" class="px-3 py-1.5 text-sm rounded-lg border border-gray-200 text-gray-700 hover:bg-gray-50">Default selection</button>
                    <button type="button" id="select-all-groups" class="px-3 py-1.5 text-sm rounded-lg border border-gray-200 text-gray-700 hover:bg-gray-50">Select all</button>
                </div>
            </div>

            <div class="space-y-3">
                <?php foreach ($groups as $groupKey => $group): ?>
                    <?php
                    $stat = $groupStats[$groupKey] ?? ['row_total' => 0, 'table_count' => 0];
                    $isDefault = in_array($groupKey, $defaultGroups, true);
                    ?>
                    <label class="flex items-start gap-3 rounded-xl border border-gray-100 p-4 hover:border-gray-200 cursor-pointer">
                        <input
                            type="checkbox"
                            name="groups[]"
                            value="<?php echo htmlspecialchars($groupKey); ?>"
                            class="mt-1 h-4 w-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500"
                            data-default="<?php echo $isDefault ? '1' : '0'; ?>"
                            <?php echo $isDefault ? 'checked' : ''; ?>
                        >
                        <span class="flex-1 min-w-0">
                            <span class="flex flex-wrap items-center gap-2">
                                <span class="font-medium text-gray-800"><?php echo htmlspecialchars($group['label']); ?></span>
                                <span class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium bg-slate-100 text-slate-600">
                                    <?php echo (int) $stat['table_count']; ?> tables
                                </span>
                                <span class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium bg-amber-50 text-amber-700">
                                    <?php echo number_format((int) $stat['row_total']); ?> rows
                                </span>
                            </span>
                            <span class="block mt-1 text-sm text-gray-500"><?php echo htmlspecialchars($group['description']); ?></span>
                        </span>
                    </label>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-5">
            <h2 class="text-lg font-semibold text-gray-800 mb-2">Always preserved</h2>
            <p class="text-sm text-gray-500 mb-3">These tables are never touched by this utility.</p>
            <div class="flex flex-wrap gap-2">
                <?php foreach ($preservedTables as $tableName): ?>
                    <span class="inline-flex px-2.5 py-1 rounded-full text-xs font-medium bg-emerald-50 text-emerald-700"><?php echo htmlspecialchars($tableName); ?></span>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="bg-white rounded-xl shadow-sm border border-red-100 p-5 space-y-4">
            <h2 class="text-lg font-semibold text-red-800">Confirm reset</h2>
            <p class="text-sm text-gray-600">Type <strong>RESET</strong> below to confirm you want to permanently delete the selected data.</p>
            <div>
                <label for="confirmation" class="block text-sm font-medium text-gray-700 mb-1">Confirmation</label>
                <input
                    type="text"
                    id="confirmation"
                    name="confirmation"
                    autocomplete="off"
                    class="w-full max-w-xs rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-red-500 focus:ring-red-500"
                    placeholder="Type RESET"
                >
            </div>
            <button
                type="submit"
                id="reset-submit"
                class="inline-flex items-center gap-2 rounded-lg bg-red-600 px-4 py-2 text-sm font-semibold text-white hover:bg-red-700 disabled:opacity-50 disabled:cursor-not-allowed"
                disabled
            >
                <i data-lucide="trash-2" class="h-4 w-4" aria-hidden="true"></i>
                Reset selected data
            </button>
        </div>
    </form>
</div>

<script>
(function () {
    const form = document.getElementById('data-reset-form');
    const confirmation = document.getElementById('confirmation');
    const submitButton = document.getElementById('reset-submit');
    const defaultButton = document.getElementById('select-default-groups');
    const allButton = document.getElementById('select-all-groups');
    const groupInputs = Array.from(form.querySelectorAll('input[name="groups[]"]'));

    function updateSubmitState() {
        const hasGroup = groupInputs.some(function (input) { return input.checked; });
        const confirmed = (confirmation.value || '').trim().toUpperCase() === 'RESET';
        submitButton.disabled = !(hasGroup && confirmed);
    }

    confirmation.addEventListener('input', updateSubmitState);
    groupInputs.forEach(function (input) {
        input.addEventListener('change', updateSubmitState);
    });

    defaultButton.addEventListener('click', function () {
        groupInputs.forEach(function (input) {
            input.checked = input.dataset.default === '1';
        });
        updateSubmitState();
    });

    allButton.addEventListener('click', function () {
        groupInputs.forEach(function (input) {
            input.checked = true;
        });
        updateSubmitState();
    });

    form.addEventListener('submit', function (event) {
        const selected = groupInputs.filter(function (input) { return input.checked; });
        if (selected.length === 0) {
            event.preventDefault();
            window.alert('Select at least one data group to reset.');
            return;
        }

        if ((confirmation.value || '').trim().toUpperCase() !== 'RESET') {
            event.preventDefault();
            window.alert('Type RESET in the confirmation field to proceed.');
            return;
        }

        const labels = selected.map(function (input) {
            const label = input.closest('label');
            const title = label ? label.querySelector('.font-medium') : null;
            return title ? title.textContent.trim() : input.value;
        });

        const message = 'This will permanently delete data from:\n\n- ' + labels.join('\n- ') + '\n\nUser accounts and system settings will be preserved.\n\nContinue?';
        if (!window.confirm(message)) {
            event.preventDefault();
        }
    });

    updateSubmitState();
})();
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
