<?php
require_once __DIR__ . '/../../config/app.php';
checkAuth();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/work_order_helper.php';
require_once __DIR__ . '/../../includes/work_order_notification_helper.php';

if (!hasPermission('manage_work_orders')) {
    http_response_code(403);
    die('Access Denied.');
}

work_order_bootstrap($pdo);

$successMessage = '';
$errorMessage = '';

$departments = work_order_safe_fetch(
    $pdo,
    "SELECT id, slug, name, queue_label FROM production_departments WHERE is_active = 1 ORDER BY default_order ASC, id ASC"
);
$users = work_order_safe_fetch(
    $pdo,
    "SELECT u.id, u.name, u.email, d.name AS hr_department
     FROM users u
     LEFT JOIN departments d ON d.id = u.department_id
     ORDER BY u.name ASC"
);
$assignmentMap = work_order_fetch_department_user_map($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        foreach ($departments as $department) {
            $deptId = (int) $department['id'];
            $field = 'dept_users_' . $deptId;
            $primaryField = 'dept_primary_' . $deptId;
            $selected = $_POST[$field] ?? [];
            if (!is_array($selected)) {
                $selected = [];
            }
            $primaryUserId = isset($_POST[$primaryField]) ? (int) $_POST[$primaryField] : 0;
            work_order_save_department_users($pdo, $deptId, $selected, $primaryUserId > 0 ? $primaryUserId : null);
        }
        $assignmentMap = work_order_fetch_department_user_map($pdo);
        $successMessage = 'Production department notification recipients saved.';
    } catch (Throwable $exception) {
        $errorMessage = $exception->getMessage();
    }
}

include '../../includes/header.php';
?>

<div class="mb-6">
    <a href="dashboard" class="text-blue-600 hover:underline flex items-center mb-4">
        <i class="material-icons text-sm mr-1">arrow_back</i> Back to dashboard
    </a>
    <h1 class="text-3xl font-bold text-gray-800">Department notification recipients</h1>
    <p class="text-sm text-gray-500 mt-1">
        Assign users to each production department. They receive in-app and email alerts when new work orders arrive in that department's incoming queue.
    </p>
</div>

<?php if ($successMessage !== ''): ?>
    <div class="mb-6 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
        <?php echo htmlspecialchars($successMessage); ?>
    </div>
<?php endif; ?>

<?php if ($errorMessage !== ''): ?>
    <div class="mb-6 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
        <?php echo htmlspecialchars($errorMessage); ?>
    </div>
<?php endif; ?>

<div class="mb-6 rounded-lg border border-blue-100 bg-blue-50 px-4 py-3 text-sm text-blue-900">
    <strong>Tip:</strong> If a department has no assigned users, notifications fall back to all users with production queue permissions until assignments are configured.
</div>

<form method="POST" action="department_users" class="space-y-6">
    <?php foreach ($departments as $department):
        $deptId = (int) $department['id'];
        $assigned = $assignmentMap[$deptId] ?? [];
        $assignedUserIds = array_column($assigned, 'user_id');
        $primaryUserId = 0;
        foreach ($assigned as $row) {
            if (!empty($row['is_primary'])) {
                $primaryUserId = (int) $row['user_id'];
                break;
            }
        }
    ?>
        <section class="bg-white shadow rounded-lg p-6">
            <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-3 mb-4">
                <div>
                    <h2 class="text-lg font-semibold text-gray-800"><?php echo htmlspecialchars($department['name']); ?></h2>
                    <p class="text-xs text-gray-500 mt-0.5">
                        Queue: <?php echo htmlspecialchars($department['queue_label'] ?? $department['name']); ?>
                        · Slug: <?php echo htmlspecialchars($department['slug']); ?>
                    </p>
                </div>
                <a href="workspace?department=<?php echo urlencode($department['slug']); ?>&tab=incoming"
                   class="text-sm text-indigo-600 hover:underline whitespace-nowrap">
                    Open incoming queue
                </a>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Notify these users</label>
                    <select
                        name="dept_users_<?php echo $deptId; ?>[]"
                        multiple
                        size="8"
                        class="block w-full border border-gray-300 rounded-lg px-3 py-2 text-sm"
                    >
                        <?php foreach ($users as $user): ?>
                            <option value="<?php echo (int) $user['id']; ?>" <?php echo in_array((int) $user['id'], $assignedUserIds, true) ? 'selected' : ''; ?>>
                                <?php
                                echo htmlspecialchars($user['name']);
                                if (!empty($user['hr_department'])) {
                                    echo ' — ' . htmlspecialchars($user['hr_department']);
                                }
                                ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="text-xs text-gray-500 mt-1">Hold Ctrl (Windows) or Cmd (Mac) to select multiple users.</p>
                </div>

                <div>
                    <label for="dept_primary_<?php echo $deptId; ?>" class="block text-sm font-medium text-gray-700 mb-2">Primary contact (optional)</label>
                    <select
                        id="dept_primary_<?php echo $deptId; ?>"
                        name="dept_primary_<?php echo $deptId; ?>"
                        class="block w-full border border-gray-300 rounded-lg px-3 py-2 text-sm"
                    >
                        <option value="0">— None —</option>
                        <?php foreach ($users as $user): ?>
                            <option value="<?php echo (int) $user['id']; ?>" <?php echo $primaryUserId === (int) $user['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($user['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="text-xs text-gray-500 mt-1">All assigned users are notified. Primary is used for ordering in reports only.</p>

                    <?php if (!empty($assignedUserIds)): ?>
                        <div class="mt-4 rounded-lg bg-gray-50 border border-gray-100 px-3 py-2">
                            <p class="text-xs font-semibold text-gray-600 uppercase tracking-wide mb-1">Currently assigned</p>
                            <ul class="text-sm text-gray-700 space-y-1">
                                <?php foreach ($assigned as $row):
                                    $userName = '';
                                    foreach ($users as $user) {
                                        if ((int) $user['id'] === (int) $row['user_id']) {
                                            $userName = (string) $user['name'];
                                            break;
                                        }
                                    }
                                ?>
                                    <li>
                                        <?php echo htmlspecialchars($userName !== '' ? $userName : 'User #' . $row['user_id']); ?>
                                        <?php if (!empty($row['is_primary'])): ?>
                                            <span class="text-xs text-indigo-600">(primary)</span>
                                        <?php endif; ?>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </section>
    <?php endforeach; ?>

    <div class="flex justify-end">
        <button type="submit" class="list-action-btn bg-indigo-600 text-white">
            <i data-lucide="save" class="sm:mr-1 inline-block h-5 w-5" aria-hidden="true"></i>
            Save assignments
        </button>
    </div>
</form>

<?php include '../../includes/footer.php'; ?>
