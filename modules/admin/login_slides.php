<?php

require_once __DIR__ . '/../../config/app.php';
checkAuth();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/settings_helper.php';
require_once __DIR__ . '/../../includes/upload_helper.php';
require_once __DIR__ . '/../../includes/login_slides_helper.php';
require_once __DIR__ . '/_audit_helpers.php';

audit_require_access();

if (($_SESSION['role'] ?? '') !== 'System Admin' && !hasPermission('manage_settings')) {
    header('HTTP/1.1 403 Forbidden');
    die('Access Denied.');
}

$formAction = 'login_slides';
$redirectPath = 'modules/admin/login_slides';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    $statusKey = '';

    if (!verify_csrf_token($_POST['_csrf'] ?? null, 'login_slides_admin')) {
        $statusKey = 'csrf';
    } else {
        try {
            $slides = login_slides_get_all();

            switch ($action) {
                case 'add':
                    if (!isset($_FILES['slide_image']) || $_FILES['slide_image']['error'] !== UPLOAD_ERR_OK) {
                        throw new RuntimeException('Please choose a background image before adding a slide.');
                    }

                    $title = trim((string) ($_POST['title'] ?? ''));
                    $caption = trim((string) ($_POST['caption'] ?? ''));
                    if ($title === '') {
                        throw new RuntimeException('A slide title is required.');
                    }

                    $stored = store_validated_uploaded_file(
                        $_FILES['slide_image'],
                        'login_slide',
                        ROOT_PATH . 'assets' . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'login-slides' . DIRECTORY_SEPARATOR,
                        '/assets/uploads/login-slides',
                        'login-slide-'
                    );

                    $slides[] = [
                        'id' => 'slide-' . bin2hex(random_bytes(8)),
                        'image' => ltrim($stored, '/'),
                        'title' => $title,
                        'caption' => $caption,
                        'enabled' => isset($_POST['enabled']),
                        'sort' => login_slides_next_sort($slides),
                    ];

                    if (!login_slides_save_all($slides)) {
                        throw new RuntimeException('Unable to save the new slide.');
                    }

                    $statusKey = 'added';
                    break;

                case 'update':
                    $id = trim((string) ($_POST['slide_id'] ?? ''));
                    $found = false;

                    foreach ($slides as $index => $slide) {
                        if ($slide['id'] !== $id) {
                            continue;
                        }

                        $found = true;
                        $title = trim((string) ($_POST['title'] ?? ''));
                        $caption = trim((string) ($_POST['caption'] ?? ''));
                        if ($title === '') {
                            throw new RuntimeException('A slide title is required.');
                        }

                        $slides[$index]['title'] = $title;
                        $slides[$index]['caption'] = $caption;
                        $slides[$index]['enabled'] = isset($_POST['enabled']);

                        if (isset($_FILES['slide_image']) && $_FILES['slide_image']['error'] === UPLOAD_ERR_OK) {
                            $stored = store_validated_uploaded_file(
                                $_FILES['slide_image'],
                                'login_slide',
                                ROOT_PATH . 'assets' . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'login-slides' . DIRECTORY_SEPARATOR,
                                '/assets/uploads/login-slides',
                                'login-slide-'
                            );
                            $previous = $slides[$index]['image'];
                            $slides[$index]['image'] = ltrim($stored, '/');
                            login_slides_delete_uploaded_file($previous);
                        }

                        break;
                    }

                    if (!$found) {
                        throw new RuntimeException('Slide not found.');
                    }

                    if (!login_slides_save_all($slides)) {
                        throw new RuntimeException('Unable to update the slide.');
                    }

                    $statusKey = 'updated';
                    break;

                case 'delete':
                    $id = trim((string) ($_POST['slide_id'] ?? ''));
                    $remaining = [];
                    $removedImage = '';

                    foreach ($slides as $slide) {
                        if ($slide['id'] === $id) {
                            $removedImage = $slide['image'];
                            continue;
                        }
                        $remaining[] = $slide;
                    }

                    if ($removedImage === '') {
                        throw new RuntimeException('Slide not found.');
                    }

                    if (count($remaining) < 1) {
                        throw new RuntimeException('At least one slide must remain on the login screen.');
                    }

                    if (!login_slides_save_all($remaining)) {
                        throw new RuntimeException('Unable to delete the slide.');
                    }

                    login_slides_delete_uploaded_file($removedImage);
                    $statusKey = 'deleted';
                    break;

                case 'move':
                    $id = trim((string) ($_POST['slide_id'] ?? ''));
                    $direction = (string) ($_POST['direction'] ?? '');
                    $index = null;

                    foreach ($slides as $i => $slide) {
                        if ($slide['id'] === $id) {
                            $index = $i;
                            break;
                        }
                    }

                    if ($index === null) {
                        throw new RuntimeException('Slide not found.');
                    }

                    $swapWith = $direction === 'up' ? $index - 1 : $index + 1;
                    if ($swapWith < 0 || $swapWith >= count($slides)) {
                        throw new RuntimeException('Slide is already at the edge of the list.');
                    }

                    $currentSort = (int) $slides[$index]['sort'];
                    $slides[$index]['sort'] = (int) $slides[$swapWith]['sort'];
                    $slides[$swapWith]['sort'] = $currentSort;

                    usort($slides, static function (array $left, array $right): int {
                        return ($left['sort'] <=> $right['sort']) ?: strcmp($left['id'], $right['id']);
                    });

                    if (!login_slides_save_all($slides)) {
                        throw new RuntimeException('Unable to reorder slides.');
                    }

                    $statusKey = 'reordered';
                    break;

                case 'reset_defaults':
                    if (!login_slides_reset_to_defaults()) {
                        throw new RuntimeException('Unable to restore default slides.');
                    }
                    $statusKey = 'reset';
                    break;

                default:
                    $statusKey = 'unknown_action';
            }
        } catch (Exception $e) {
            $_SESSION['login_slides_flash_error'] = $e->getMessage();
            $statusKey = 'error';
        }
    }

    redirect($redirectPath . '?status=' . urlencode($statusKey));
}

$status = (string) ($_GET['status'] ?? '');
$success = '';
$error = '';

switch ($status) {
    case 'added':
        $success = 'Login slide added.';
        break;
    case 'updated':
        $success = 'Login slide updated.';
        break;
    case 'deleted':
        $success = 'Login slide removed.';
        break;
    case 'reordered':
        $success = 'Slide order updated.';
        break;
    case 'reset':
        $success = 'Default login slides restored. Custom uploads were removed.';
        break;
    case 'csrf':
        $error = 'Your session expired. Please refresh the page and try again.';
        break;
    case 'unknown_action':
        $error = 'Unknown action.';
        break;
    case 'error':
        $error = 'Could not complete the action: ' . (string) ($_SESSION['login_slides_flash_error'] ?? 'Unexpected error.');
        unset($_SESSION['login_slides_flash_error']);
        break;
}

$slides = login_slides_get_all();
$activeCount = count(array_filter($slides, static function (array $slide): bool {
    return !empty($slide['enabled']);
}));
$usingDefaults = !login_slides_has_custom_config();
$uploadCount = 0;
foreach ($slides as $slide) {
    if (strpos($slide['image'], 'assets/uploads/login-slides/') === 0) {
        $uploadCount++;
    }
}

include __DIR__ . '/../../includes/header.php';
?>

<style>
    .lslide-preview {
        position: relative;
        width: 100%;
        aspect-ratio: 16 / 10;
        background-color: #0f172a;
        border-radius: 0.75rem 0.75rem 0 0;
        overflow: hidden;
    }

    .lslide-preview img {
        width: 100%;
        height: 100%;
        object-fit: cover;
        display: block;
    }

    .lslide-card {
        display: flex;
        flex-direction: column;
        border: 1px solid #e2e8f0;
        border-radius: 0.75rem;
        overflow: hidden;
        background: #fff;
    }

    .lslide-body {
        padding: 1rem;
        display: flex;
        flex-direction: column;
        gap: 0.75rem;
        flex: 1 1 auto;
    }

    .lslide-grid {
        display: grid;
        grid-template-columns: 1fr;
        gap: 1.25rem;
    }

    @media (min-width: 768px) {
        .lslide-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
    }

    @media (min-width: 1280px) {
        .lslide-grid {
            grid-template-columns: repeat(3, minmax(0, 1fr));
        }
    }

    .lslide-file-input {
        display: block;
        width: 100%;
        font-size: 0.875rem;
        color: #475569;
    }

    .lslide-order-actions {
        display: flex;
        gap: 0.5rem;
        flex-wrap: wrap;
    }
</style>

<div class="container mx-auto px-4 py-8 max-w-7xl">
    <div class="flex flex-col gap-2 mb-6">
        <h1 class="text-2xl font-bold text-gray-800">Login background slides</h1>
        <p class="text-sm text-gray-500">Manage the rotating images and captions on the sign-in screen. Only slides marked as active are shown. At least one active slide is required.</p>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4">
            <p class="text-xs uppercase tracking-wide text-gray-400">Total slides</p>
            <span class="inline-flex mt-3 px-3 py-1 rounded-full text-sm font-semibold bg-slate-100 text-slate-700"><?php echo count($slides); ?></span>
        </div>
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4">
            <p class="text-xs uppercase tracking-wide text-gray-400">Active on login</p>
            <span class="inline-flex mt-3 px-3 py-1 rounded-full text-sm font-semibold <?php echo $activeCount > 0 ? 'bg-emerald-50 text-emerald-700' : 'bg-red-50 text-red-700'; ?>"><?php echo $activeCount; ?></span>
        </div>
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4">
            <p class="text-xs uppercase tracking-wide text-gray-400">Configuration</p>
            <span class="inline-flex mt-3 px-3 py-1 rounded-full text-sm font-semibold <?php echo $usingDefaults ? 'bg-blue-50 text-blue-700' : 'bg-violet-50 text-violet-700'; ?>">
                <?php echo $usingDefaults ? 'Using defaults' : 'Custom'; ?>
            </span>
        </div>
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4">
            <p class="text-xs uppercase tracking-wide text-gray-400">Uploaded images</p>
            <span class="inline-flex mt-3 px-3 py-1 rounded-full text-sm font-semibold bg-amber-50 text-amber-700"><?php echo $uploadCount; ?></span>
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

    <div class="bg-white shadow-md rounded-xl p-6 mb-8">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
            <div>
                <h2 class="text-lg font-semibold text-gray-800">Restore defaults</h2>
                <p class="text-sm text-gray-500 mt-1">Remove all custom slides and uploaded files, then use the five bundled login backgrounds again.</p>
            </div>
            <form method="POST" action="<?php echo htmlspecialchars($formAction); ?>" onsubmit="return confirm('Restore default login slides? This deletes custom uploads.');">
                <input type="hidden" name="action" value="reset_defaults">
                <input type="hidden" name="_csrf" value="<?php echo htmlspecialchars(csrf_token('login_slides_admin')); ?>">
                <button type="submit" class="bg-red-600 text-white px-4 py-2 rounded-lg shadow-sm hover:bg-red-700 text-sm font-medium">
                    Reset to defaults
                </button>
            </form>
        </div>
    </div>

    <div class="bg-white shadow-md rounded-xl p-6 mb-8">
        <h2 class="text-lg font-semibold text-gray-800 mb-1">Add slide</h2>
        <p class="text-sm text-gray-500 mb-4">Recommended size: at least 1600×900 px, landscape orientation.</p>

        <form method="POST" action="<?php echo htmlspecialchars($formAction); ?>" enctype="multipart/form-data" class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <input type="hidden" name="action" value="add">
            <input type="hidden" name="_csrf" value="<?php echo htmlspecialchars(csrf_token('login_slides_admin')); ?>">

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Title</label>
                <input type="text" name="title" required maxlength="120" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm" placeholder="Headline shown on the slide">
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Caption</label>
                <input type="text" name="caption" maxlength="220" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm" placeholder="Supporting line under the title">
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Background image</label>
                <input type="file" name="slide_image" accept="image/jpeg,image/png,image/webp,image/avif" required class="lslide-file-input">
            </div>

            <div class="flex items-end gap-4">
                <label class="flex items-center gap-2 text-sm text-gray-700">
                    <input type="checkbox" name="enabled" class="h-4 w-4 rounded border-gray-300 text-blue-600" checked>
                    Show on login screen
                </label>
                <button type="submit" class="ml-auto bg-blue-600 text-white px-4 py-2 rounded-lg shadow-sm hover:bg-blue-700 text-sm font-medium">
                    Add slide
                </button>
            </div>
        </form>
    </div>

    <div class="lslide-grid">
        <?php foreach ($slides as $index => $slide): ?>
            <?php $previewUrl = login_slides_resolve_image_url($slide['image']); ?>
            <article class="lslide-card">
                <div class="lslide-preview">
                    <?php if ($previewUrl !== ''): ?>
                        <img src="<?php echo htmlspecialchars($previewUrl); ?>" alt="" loading="lazy">
                    <?php endif; ?>
                </div>
                <div class="lslide-body">
                    <div class="flex items-center justify-between gap-2">
                        <span class="text-xs font-semibold uppercase tracking-wide text-gray-400">Slide <?php echo $index + 1; ?></span>
                        <span class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium <?php echo !empty($slide['enabled']) ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-600'; ?>">
                            <?php echo !empty($slide['enabled']) ? 'Active' : 'Hidden'; ?>
                        </span>
                    </div>

                    <form method="POST" action="<?php echo htmlspecialchars($formAction); ?>" enctype="multipart/form-data" class="space-y-3">
                        <input type="hidden" name="action" value="update">
                        <input type="hidden" name="slide_id" value="<?php echo htmlspecialchars($slide['id']); ?>">
                        <input type="hidden" name="_csrf" value="<?php echo htmlspecialchars(csrf_token('login_slides_admin')); ?>">

                        <div>
                            <label class="block text-xs font-medium text-gray-600 mb-1">Title</label>
                            <input type="text" name="title" required maxlength="120" value="<?php echo htmlspecialchars($slide['title']); ?>" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
                        </div>

                        <div>
                            <label class="block text-xs font-medium text-gray-600 mb-1">Caption</label>
                            <input type="text" name="caption" maxlength="220" value="<?php echo htmlspecialchars($slide['caption']); ?>" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
                        </div>

                        <div>
                            <label class="block text-xs font-medium text-gray-600 mb-1">Replace image (optional)</label>
                            <input type="file" name="slide_image" accept="image/jpeg,image/png,image/webp,image/avif" class="lslide-file-input">
                        </div>

                        <label class="flex items-center gap-2 text-sm text-gray-700">
                            <input type="checkbox" name="enabled" class="h-4 w-4 rounded border-gray-300 text-blue-600" <?php echo !empty($slide['enabled']) ? 'checked' : ''; ?>>
                            Show on login screen
                        </label>

                        <button type="submit" class="w-full bg-blue-600 text-white px-4 py-2 rounded-lg shadow-sm hover:bg-blue-700 text-sm font-medium">
                            Save changes
                        </button>
                    </form>

                    <div class="lslide-order-actions pt-1 border-t border-gray-100">
                        <form method="POST" action="<?php echo htmlspecialchars($formAction); ?>" class="inline">
                            <input type="hidden" name="action" value="move">
                            <input type="hidden" name="slide_id" value="<?php echo htmlspecialchars($slide['id']); ?>">
                            <input type="hidden" name="direction" value="up">
                            <input type="hidden" name="_csrf" value="<?php echo htmlspecialchars(csrf_token('login_slides_admin')); ?>">
                            <button type="submit" class="px-3 py-1.5 text-xs font-medium rounded-lg border border-gray-200 text-gray-700 hover:bg-gray-50" <?php echo $index === 0 ? 'disabled' : ''; ?>>Move up</button>
                        </form>
                        <form method="POST" action="<?php echo htmlspecialchars($formAction); ?>" class="inline">
                            <input type="hidden" name="action" value="move">
                            <input type="hidden" name="slide_id" value="<?php echo htmlspecialchars($slide['id']); ?>">
                            <input type="hidden" name="direction" value="down">
                            <input type="hidden" name="_csrf" value="<?php echo htmlspecialchars(csrf_token('login_slides_admin')); ?>">
                            <button type="submit" class="px-3 py-1.5 text-xs font-medium rounded-lg border border-gray-200 text-gray-700 hover:bg-gray-50" <?php echo $index === count($slides) - 1 ? 'disabled' : ''; ?>>Move down</button>
                        </form>
                        <?php if (count($slides) > 1): ?>
                            <form method="POST" action="<?php echo htmlspecialchars($formAction); ?>" class="inline ml-auto" onsubmit="return confirm('Delete this slide?');">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="slide_id" value="<?php echo htmlspecialchars($slide['id']); ?>">
                                <input type="hidden" name="_csrf" value="<?php echo htmlspecialchars(csrf_token('login_slides_admin')); ?>">
                                <button type="submit" class="px-3 py-1.5 text-xs font-medium rounded-lg border border-red-200 text-red-700 hover:bg-red-50">Delete</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
