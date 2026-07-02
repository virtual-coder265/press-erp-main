<?php
/**
 * Estimation Edit Page
 *
 * Lets a user adjust the editable header information on an estimation
 * (customer details and the free-form job description) and refresh the
 * audit columns surfaced on the view page.
 *
 * Deep edits to wizard sections (paper, ink, binding, labour, etc.) still
 * happen via Create / Delete + recreate; that flow is preserved untouched.
 *
 * Routing: this file is reached via the extensionless URL
 * `modules/estimations/edit?id=X` — both GET (render form) and POST
 * (apply update) honour the .htaccess rewrite rule.
 */

require_once __DIR__ . '/../../config/app.php';
checkAuth();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../libs/EstimationAuditMigrator.php';
require_once __DIR__ . '/../../libs/EstimationStatusManager.php';

if (function_exists('checkPermission')) {
    checkPermission('manage_estimations');
}

EstimationAuditMigrator::ensure($pdo);

$id = isset($_GET['id']) ? (int) $_GET['id'] : (int) ($_POST['id'] ?? 0);
if ($id <= 0) {
    redirect('modules/estimations/list');
}

$stmt = $pdo->prepare("SELECT * FROM estimations WHERE id = :id");
$stmt->execute(['id' => $id]);
$est = $stmt->fetch();

if (!$est) {
    http_response_code(404);
    die('Estimation not found.');
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!verify_csrf_token($token, 'estimation_edit')) {
        $errors[] = 'Security check failed. Please reload the page and try again.';
    }

    $customer_name  = trim((string) ($_POST['customer_name']  ?? ''));
    $customer_email = trim((string) ($_POST['customer_email'] ?? ''));
    $customer_phone = trim((string) ($_POST['customer_phone'] ?? ''));
    $job_description = trim((string) ($_POST['job_description'] ?? ''));

    if ($customer_name === '') {
        $errors[] = 'Customer name is required.';
    }
    if ($customer_email !== '' && !filter_var($customer_email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Customer email is not a valid address.';
    }

    if (empty($errors)) {
        try {
            $update = $pdo->prepare("
                UPDATE estimations
                SET customer_name = :name,
                    customer_email = :email,
                    customer_phone = :phone,
                    job_description = :job,
                    last_edited_at = NOW(),
                    last_edited_by = :editor
                WHERE id = :id
            ");
            $update->execute([
                'name'   => $customer_name,
                'email'  => $customer_email,
                'phone'  => $customer_phone,
                'job'    => $job_description,
                'editor' => (int) $_SESSION['user_id'],
                'id'     => $id,
            ]);

            $_SESSION['success'] = 'Estimation updated.';
            redirect('modules/estimations/view?id=' . $id);
        } catch (PDOException $e) {
            error_log('Estimation edit failed: ' . $e->getMessage());
            $errors[] = 'Could not save changes: ' . $e->getMessage();
        }
    }

    // Re-render with submitted (rejected) values so the user does not lose typing.
    $est['customer_name']   = $customer_name;
    $est['customer_email']  = $customer_email;
    $est['customer_phone']  = $customer_phone;
    $est['job_description'] = $job_description;
}

include '../../includes/header.php';
?>

<div class="mb-6">
    <div class="flex items-center gap-2 mb-4">
        <a href="view?id=<?php echo (int) $est['id']; ?>" class="text-green-600 hover:underline flex items-center">
            <i data-lucide="arrow-left" class="mr-1 inline-block h-4 w-4 flex-shrink-0" aria-hidden="true"></i>
            Back to estimation
        </a>
    </div>
    <h1 class="text-3xl font-bold text-gray-800">
        Edit Estimation <?php echo htmlspecialchars($est['estimation_number']); ?>
    </h1>
    <p class="text-gray-600 mt-1">
        Update the customer details and job description. Cost-line edits (paper, ink, binding, labour) are
        managed via the original wizard.
    </p>
</div>

<?php if (!empty($errors)): ?>
    <div class="bg-red-50 border border-red-200 text-red-800 rounded-lg p-4 mb-6">
        <p class="font-semibold mb-1">We couldn't save your changes:</p>
        <ul class="list-disc list-inside text-sm">
            <?php foreach ($errors as $err): ?>
                <li><?php echo htmlspecialchars($err); ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<form method="POST" action="edit?id=<?php echo (int) $est['id']; ?>" class="bg-white shadow-md rounded-xl p-8 space-y-8">
    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token('estimation_edit')); ?>">
    <input type="hidden" name="id" value="<?php echo (int) $est['id']; ?>">

    <section>
        <h2 class="text-xl font-bold text-gray-800 mb-4">Customer details</h2>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <div>
                <label class="block text-gray-700 font-semibold mb-2" for="customer_name">Customer Name *</label>
                <input id="customer_name" name="customer_name" type="text" required
                    value="<?php echo htmlspecialchars((string) $est['customer_name']); ?>"
                    class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-green-500">
            </div>
            <div>
                <label class="block text-gray-700 font-semibold mb-2" for="customer_email">Email</label>
                <input id="customer_email" name="customer_email" type="email"
                    value="<?php echo htmlspecialchars((string) $est['customer_email']); ?>"
                    class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-green-500">
            </div>
            <div>
                <label class="block text-gray-700 font-semibold mb-2" for="customer_phone">Phone</label>
                <input id="customer_phone" name="customer_phone" type="text"
                    value="<?php echo htmlspecialchars((string) $est['customer_phone']); ?>"
                    class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-green-500">
            </div>
        </div>
    </section>

    <section>
        <h2 class="text-xl font-bold text-gray-800 mb-4">Job description</h2>
        <textarea id="job_description" name="job_description" rows="8"
            class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-green-500 resize-y"
            placeholder="Job title, type, brief, special instructions..."><?php echo htmlspecialchars((string) $est['job_description']); ?></textarea>
        <p class="text-xs text-gray-500 mt-2">
            Tip: keep the first line as <code class="bg-gray-100 px-1 rounded">Job Title (Job Type)</code> so the
            list view and PDF render with the proper heading.
        </p>
    </section>

    <div class="flex flex-col-reverse sm:flex-row sm:justify-end gap-3 pt-6 border-t border-gray-200">
        <a href="view?id=<?php echo (int) $est['id']; ?>"
            class="inline-flex items-center justify-center px-5 py-3 rounded-lg border border-gray-300 text-gray-700 hover:bg-gray-50 transition">
            Cancel
        </a>
        <button type="submit"
            class="inline-flex items-center justify-center px-5 py-3 rounded-lg bg-green-600 text-white font-semibold hover:bg-green-700 transition">
            <i data-lucide="save" class="h-4 w-4 mr-2" aria-hidden="true"></i>
            Save changes
        </button>
    </div>
</form>

<script>if (typeof window.refreshAppShellIcons === 'function') { window.refreshAppShellIcons(); }</script>

<?php include '../../includes/footer.php'; ?>
