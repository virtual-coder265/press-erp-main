<?php
require_once __DIR__ . '/../../config/app.php';
checkAuth();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/settings_helper.php';
require_once __DIR__ . '/../../includes/upload_helper.php';
require_once __DIR__ . '/../../includes/hero_weather_helper.php';

if (($_SESSION['role'] ?? '') !== 'System Admin' && !hasPermission('manage_settings')) {
    die('Access Denied.');
}

/**
 * All form actions on this page POST to the extensionless route. We use a
 * bare filename (`hero_weather`) so the form resolves against the current
 * directory exactly like the rest of /modules/settings/. The .htaccess
 * clean-URL rule routes the request to this script. Successful and failed
 * submissions complete with redirect() targeting the same extensionless URL
 * via BASE_URL, keeping refreshes idempotent and never exposing the .php
 * extension to the browser.
 */
$heroWeatherFormAction = 'hero_weather';
$heroWeatherRedirectPath = 'modules/settings/hero_weather';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    $statusKey = '';

    if (!verify_csrf_token($_POST['_csrf'] ?? null, 'hero_weather_settings')) {
        $statusKey = 'csrf';
    } else {
        try {
            switch ($action) {
                case 'save_toggle':
                    save_application_settings([
                        'hero_weather_enabled' => isset($_POST['hero_weather_enabled']),
                    ]);
                    $statusKey = isset($_POST['hero_weather_enabled']) ? 'toggle_on' : 'toggle_off';
                    break;

                case 'upload_slot':
                    $group = (string) ($_POST['group'] ?? '');
                    $daypart = (string) ($_POST['daypart'] ?? '');
                    if (!hero_weather_is_valid_slot($group, $daypart)) {
                        throw new RuntimeException('Unknown weather slot.');
                    }
                    if (!isset($_FILES['hero_image']) || $_FILES['hero_image']['error'] !== UPLOAD_ERR_OK) {
                        throw new RuntimeException('Please choose an image file before uploading.');
                    }

                    $stored = store_validated_uploaded_file(
                        $_FILES['hero_image'],
                        'hero_weather_bg',
                        ROOT_PATH . 'assets' . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'hero-weather-bg' . DIRECTORY_SEPARATOR,
                        '/assets/uploads/hero-weather-bg',
                        'hero-' . $group . '-' . $daypart . '-'
                    );
                    $relative = ltrim($stored, '/');

                    $overrides = hero_weather_get_overrides();
                    $slotKey = hero_weather_slot_key($group, $daypart);
                    $previous = $overrides[$slotKey] ?? '';
                    $overrides[$slotKey] = $relative;

                    if (!hero_weather_save_overrides($overrides)) {
                        throw new RuntimeException('Unable to save the new background mapping.');
                    }

                    if ($previous !== '' && $previous !== $relative) {
                        hero_weather_delete_uploaded_file($previous);
                    }

                    $statusKey = 'uploaded';
                    break;

                case 'remove_slot':
                    $group = (string) ($_POST['group'] ?? '');
                    $daypart = (string) ($_POST['daypart'] ?? '');
                    if (!hero_weather_is_valid_slot($group, $daypart)) {
                        throw new RuntimeException('Unknown weather slot.');
                    }

                    $overrides = hero_weather_get_overrides();
                    $slotKey = hero_weather_slot_key($group, $daypart);
                    if (isset($overrides[$slotKey])) {
                        $previous = $overrides[$slotKey];
                        unset($overrides[$slotKey]);

                        if (!hero_weather_save_overrides($overrides)) {
                            throw new RuntimeException('Unable to clear the override.');
                        }

                        hero_weather_delete_uploaded_file($previous);
                    }

                    $statusKey = 'removed';
                    break;

                case 'reset_all':
                    $overrides = hero_weather_get_overrides();
                    foreach ($overrides as $relative) {
                        hero_weather_delete_uploaded_file($relative);
                    }
                    if (!hero_weather_save_overrides([])) {
                        throw new RuntimeException('Unable to reset overrides.');
                    }
                    $statusKey = 'reset';
                    break;

                default:
                    $statusKey = 'unknown_action';
            }
        } catch (Exception $e) {
            // Stash the message in the session so the GET redirect can surface
            // it without exposing details in the URL.
            $_SESSION['hero_weather_flash_error'] = $e->getMessage();
            $statusKey = 'error';
        }
    }

    redirect($heroWeatherRedirectPath . '?status=' . urlencode($statusKey));
}

$status = (string) ($_GET['status'] ?? '');
$success = '';
$error = '';
switch ($status) {
    case 'toggle_on':
        $success = 'Dynamic hero weather backgrounds are now enabled.';
        break;
    case 'toggle_off':
        $success = 'Dynamic hero weather backgrounds have been disabled. The dashboard will use the default gradient.';
        break;
    case 'uploaded':
        $success = 'Background updated.';
        break;
    case 'removed':
        $success = 'Reverted to the default illustration for that slot.';
        break;
    case 'reset':
        $success = 'All custom backgrounds have been removed. Defaults restored.';
        break;
    case 'csrf':
        $error = 'Your session expired. Please refresh the page and try again.';
        break;
    case 'unknown_action':
        $error = 'Unknown action.';
        break;
    case 'error':
        $error = 'Could not complete the action: ' . (string) ($_SESSION['hero_weather_flash_error'] ?? 'Unexpected error.');
        unset($_SESSION['hero_weather_flash_error']);
        break;
}

$enabled = hero_weather_is_enabled();
$overrides = hero_weather_get_overrides();
$defaults = hero_weather_default_backgrounds();
$groups = hero_weather_groups();
$dayparts = hero_weather_dayparts();
$totalSlots = count($groups) * count($dayparts);
$customCount = count($overrides);

$cards = [
    [
        'label' => 'Hero backgrounds',
        'value' => $enabled ? 'Enabled' : 'Disabled',
        'tone' => $enabled ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-700',
    ],
    [
        'label' => 'Custom slots',
        'value' => $customCount . ' / ' . $totalSlots,
        'tone' => $customCount > 0 ? 'bg-violet-50 text-violet-700' : 'bg-blue-50 text-blue-700',
    ],
    [
        'label' => 'Weather groups',
        'value' => count($groups),
        'tone' => 'bg-indigo-50 text-indigo-700',
    ],
    [
        'label' => 'Dayparts',
        'value' => count($dayparts),
        'tone' => 'bg-amber-50 text-amber-700',
    ],
];

include __DIR__ . '/../../includes/header.php';
?>

<style>
    /*
     * Tailwind 2.2 (shipped in /assets/vendor/css) does not include the
     * aspect-ratio plugin nor arbitrary-value utilities like text-[0.65rem]
     * or file:* pseudo-prefixes. We reimplement the few bits this page needs
     * locally so the layout works on the bundled stylesheet.
     */
    .hwbg-preview {
        position: relative;
        width: 100%;
        background-color: #f1f5f9;
        aspect-ratio: 16 / 9;
        min-height: 8.5rem;
        overflow: hidden;
    }

    @supports not (aspect-ratio: 16 / 9) {
        .hwbg-preview {
            height: 0;
            padding-bottom: 56.25%;
        }
    }

    .hwbg-preview > .hwbg-cover {
        position: absolute;
        inset: 0;
        width: 100%;
        height: 100%;
        object-fit: cover;
    }

    .hwbg-preview > .hwbg-empty {
        position: absolute;
        inset: 0;
        display: flex;
        align-items: center;
        justify-content: center;
        color: #94a3b8;
        font-size: 0.75rem;
        text-transform: uppercase;
        letter-spacing: 0.08em;
    }

    .hwbg-pill {
        position: absolute;
        top: 0.5rem;
        display: inline-flex;
        align-items: center;
        gap: 0.25rem;
        padding: 0.2rem 0.55rem;
        border-radius: 999px;
        font-size: 0.65rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.06em;
        line-height: 1;
        white-space: nowrap;
    }

    .hwbg-pill svg,
    .hwbg-pill i {
        width: 0.85rem;
        height: 0.85rem;
    }

    .hwbg-pill--daypart {
        left: 0.5rem;
        background-color: rgba(255, 255, 255, 0.92);
        color: #334155;
        backdrop-filter: blur(6px);
        box-shadow: 0 1px 2px rgba(15, 23, 42, 0.18);
    }

    .hwbg-pill--daypart-active {
        background-color: #7c3aed;
        color: #ffffff;
        box-shadow: 0 1px 2px rgba(15, 23, 42, 0.18);
    }

    .hwbg-pill--badge {
        right: 0.5rem;
        font-size: 0.6rem;
    }

    .hwbg-pill--default {
        background-color: rgba(15, 23, 42, 0.78);
        color: #ffffff;
    }

    .hwbg-pill--custom {
        background-color: #059669;
        color: #ffffff;
    }

    .hwbg-file-input {
        display: block;
        width: 100%;
        font-size: 0.75rem;
        color: #475569;
    }

    .hwbg-file-input::-webkit-file-upload-button {
        margin-right: 0.5rem;
        padding: 0.35rem 0.7rem;
        border-radius: 0.375rem;
        border: 0;
        background-color: #eff6ff;
        color: #1d4ed8;
        font-size: 0.75rem;
        font-weight: 600;
        cursor: pointer;
    }

    .hwbg-file-input::file-selector-button {
        margin-right: 0.5rem;
        padding: 0.35rem 0.7rem;
        border-radius: 0.375rem;
        border: 0;
        background-color: #eff6ff;
        color: #1d4ed8;
        font-size: 0.75rem;
        font-weight: 600;
        cursor: pointer;
    }

    .hwbg-file-input:hover::-webkit-file-upload-button,
    .hwbg-file-input:hover::file-selector-button {
        background-color: #dbeafe;
    }

    .hwbg-tile {
        display: flex;
        flex-direction: column;
        border: 1px solid #e2e8f0;
        border-radius: 1rem;
        overflow: hidden;
        background-color: #ffffff;
    }

    .hwbg-tile-body {
        display: flex;
        flex-direction: column;
        gap: 0.5rem;
        padding: 0.75rem;
        flex: 1 1 auto;
    }

    .hwbg-grid {
        display: grid;
        grid-template-columns: repeat(1, minmax(0, 1fr));
        gap: 1rem;
    }

    @media (min-width: 640px) {
        .hwbg-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
    }

    @media (min-width: 1024px) {
        .hwbg-grid {
            grid-template-columns: repeat(5, minmax(0, 1fr));
        }
    }

    .hwbg-disabled {
        opacity: 0.6;
    }
</style>

<div class="container mx-auto px-4 py-8 max-w-7xl">
    <div class="flex flex-col gap-2 mb-6">
        <h1 class="text-2xl font-bold text-gray-800">Hero Weather Backgrounds</h1>
        <p class="text-sm text-gray-500">Curate the dashboard hero card art for every combination of weather and time of day. Uploaded images take priority over the bundled defaults; toggle the feature off to fall back to the plain gradient.</p>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
        <?php foreach ($cards as $card): ?>
            <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4">
                <p class="text-xs uppercase tracking-wide text-gray-400"><?php echo htmlspecialchars($card['label']); ?></p>
                <span class="inline-flex mt-3 px-3 py-1 rounded-full text-sm font-semibold <?php echo htmlspecialchars($card['tone']); ?>">
                    <?php echo htmlspecialchars((string) $card['value']); ?>
                </span>
            </div>
        <?php endforeach; ?>
    </div>

    <?php if ($success !== ''): ?>
        <div class="bg-green-100 border border-green-300 text-green-800 px-4 py-3 rounded-lg mb-4">
            <?php echo htmlspecialchars($success); ?>
        </div>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
        <div class="bg-red-100 border border-red-300 text-red-800 px-4 py-3 rounded-lg mb-4">
            <?php echo htmlspecialchars($error); ?>
        </div>
    <?php endif; ?>

    <div class="bg-white shadow-md rounded-xl p-6 mb-8">
        <div class="flex flex-col md:flex-row md:items-start md:justify-between gap-6">
            <div class="max-w-2xl">
                <h2 class="text-lg font-semibold text-gray-800">Global toggle</h2>
                <p class="text-sm text-gray-500 mt-1">Switch the entire feature on or off. When disabled, the dashboard hero card keeps its default gradient and ignores the curated images below. Each user's selected city and live forecast still work either way.</p>
            </div>

            <form method="POST" action="<?php echo htmlspecialchars($heroWeatherRoute); ?>" class="flex flex-col gap-3 md:min-w-[18rem]">
                <input type="hidden" name="action" value="save_toggle">
                <input type="hidden" name="_csrf" value="<?php echo htmlspecialchars(csrf_token('hero_weather_settings')); ?>">

                <label class="flex items-start gap-3 p-4 border border-gray-200 rounded-xl">
                    <input type="checkbox" name="hero_weather_enabled" class="mt-1 h-4 w-4 rounded border-gray-300 text-blue-600" <?php echo $enabled ? 'checked' : ''; ?>>
                    <span>
                        <span class="block text-sm font-medium text-gray-800">Enable dynamic hero backgrounds</span>
                        <span class="block text-xs text-gray-500">Required for any of the curated images to appear.</span>
                    </span>
                </label>

                <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded-lg shadow-sm hover:bg-blue-700">
                    Save toggle
                </button>
            </form>
        </div>
    </div>

    <?php if ($customCount > 0): ?>
        <div class="bg-white shadow-md rounded-xl p-6 mb-8">
            <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
                <div>
                    <h2 class="text-lg font-semibold text-gray-800">Reset all overrides</h2>
                    <p class="text-sm text-gray-500 mt-1">Remove every uploaded background and restore the defaults across all <?php echo (int) $totalSlots; ?> slots. The toggle setting above is left untouched.</p>
                </div>
                <form method="POST" action="<?php echo htmlspecialchars($heroWeatherRoute); ?>" onsubmit="return confirm('Remove every custom hero background? This deletes all uploaded files and restores the defaults.');">
                    <input type="hidden" name="action" value="reset_all">
                    <input type="hidden" name="_csrf" value="<?php echo htmlspecialchars(csrf_token('hero_weather_settings')); ?>">
                    <button type="submit" class="bg-red-600 text-white px-4 py-2 rounded-lg shadow-sm hover:bg-red-700">
                        Reset all
                    </button>
                </form>
            </div>
        </div>
    <?php endif; ?>

    <div class="space-y-8 <?php echo $enabled ? '' : 'hwbg-disabled'; ?>">
        <?php foreach ($groups as $groupKey => $group): ?>
            <section class="bg-white shadow-md rounded-xl p-6">
                <header class="flex items-start justify-between border-b border-gray-100 pb-3 mb-4">
                    <div class="flex items-center gap-3">
                        <span class="inline-flex h-10 w-10 items-center justify-center rounded-xl bg-slate-100 text-slate-700">
                            <i data-lucide="<?php echo htmlspecialchars($group['icon']); ?>" aria-hidden="true"></i>
                        </span>
                        <div>
                            <h2 class="text-lg font-semibold text-gray-800"><?php echo htmlspecialchars($group['label']); ?></h2>
                            <p class="text-xs text-gray-500"><?php echo htmlspecialchars($group['description']); ?></p>
                        </div>
                    </div>
                    <span class="text-xs uppercase tracking-wide text-gray-400 hidden md:block"><?php echo htmlspecialchars($groupKey); ?></span>
                </header>

                <div class="hwbg-grid">
                    <?php foreach ($dayparts as $daypartKey => $daypart): ?>
                        <?php
                        $slotKey = hero_weather_slot_key($groupKey, $daypartKey);
                        $hasOverride = isset($overrides[$slotKey]);
                        $relative = $hasOverride ? $overrides[$slotKey] : ($defaults[$slotKey] ?? '');
                        $previewUrl = $relative !== '' ? rtrim(BASE_URL, '/') . '/' . ltrim($relative, '/') : '';
                        ?>
                        <article class="hwbg-tile">
                            <div class="hwbg-preview">
                                <?php if ($previewUrl !== ''): ?>
                                    <img class="hwbg-cover"
                                         src="<?php echo htmlspecialchars($previewUrl); ?>"
                                         alt="<?php echo htmlspecialchars($group['label'] . ' — ' . $daypart['label']); ?>"
                                         loading="lazy">
                                <?php else: ?>
                                    <div class="hwbg-empty">No preview</div>
                                <?php endif; ?>

                                <span class="hwbg-pill hwbg-pill--daypart <?php echo $hasOverride ? 'hwbg-pill--daypart-active' : ''; ?>">
                                    <i data-lucide="<?php echo htmlspecialchars($daypart['icon']); ?>" aria-hidden="true"></i>
                                    <span><?php echo htmlspecialchars($daypart['label']); ?></span>
                                </span>

                                <span class="hwbg-pill hwbg-pill--badge <?php echo $hasOverride ? 'hwbg-pill--custom' : 'hwbg-pill--default'; ?>">
                                    <?php echo $hasOverride ? 'Custom' : 'Default'; ?>
                                </span>
                            </div>

                            <div class="hwbg-tile-body">
                                <div>
                                    <p class="text-sm font-semibold text-gray-800"><?php echo htmlspecialchars($daypart['label']); ?></p>
                                    <p class="text-xs text-gray-500"><?php echo htmlspecialchars($daypart['range']); ?></p>
                                </div>

                                <form method="POST" action="<?php echo htmlspecialchars($heroWeatherRoute); ?>" enctype="multipart/form-data" class="flex flex-col gap-2 mt-auto">
                                    <input type="hidden" name="action" value="upload_slot">
                                    <input type="hidden" name="_csrf" value="<?php echo htmlspecialchars(csrf_token('hero_weather_settings')); ?>">
                                    <input type="hidden" name="group" value="<?php echo htmlspecialchars($groupKey); ?>">
                                    <input type="hidden" name="daypart" value="<?php echo htmlspecialchars($daypartKey); ?>">

                                    <label class="block">
                                        <span class="sr-only">Choose image for <?php echo htmlspecialchars($daypart['label']); ?></span>
                                        <input type="file" name="hero_image" accept="image/jpeg,image/png,image/webp" required class="hwbg-file-input">
                                    </label>

                                    <button type="submit" class="inline-flex items-center justify-center gap-1 bg-blue-600 text-white text-xs font-semibold px-3 py-2 rounded-md hover:bg-blue-700">
                                        <i data-lucide="upload" aria-hidden="true" style="width:0.85rem;height:0.85rem;"></i>
                                        Upload
                                    </button>
                                </form>

                                <?php if ($hasOverride): ?>
                                    <form method="POST" action="<?php echo htmlspecialchars($heroWeatherRoute); ?>" onsubmit="return confirm('Revert this slot to the default illustration?');">
                                        <input type="hidden" name="action" value="remove_slot">
                                        <input type="hidden" name="_csrf" value="<?php echo htmlspecialchars(csrf_token('hero_weather_settings')); ?>">
                                        <input type="hidden" name="group" value="<?php echo htmlspecialchars($groupKey); ?>">
                                        <input type="hidden" name="daypart" value="<?php echo htmlspecialchars($daypartKey); ?>">

                                        <button type="submit" class="w-full inline-flex items-center justify-center gap-1 bg-slate-100 text-slate-700 text-xs font-semibold px-3 py-2 rounded-md hover:bg-slate-200">
                                            <i data-lucide="rotate-ccw" aria-hidden="true" style="width:0.85rem;height:0.85rem;"></i>
                                            Restore default
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endforeach; ?>
    </div>

    <div class="bg-blue-50 border border-blue-100 rounded-xl p-5 mt-8 text-sm text-blue-900">
        <p class="font-semibold mb-1">Tips for great hero artwork</p>
        <ul class="list-disc pl-5 space-y-1">
            <li>Use 1920×1080 (or wider) JPG/PNG/WEBP files. Keep individual files under 8 MB so the dashboard stays snappy.</li>
            <li>Pick photos with empty space on the left — the hero card overlays a bold title in that area.</li>
            <li>Match the daypart cue: brighter for morning/midday, warm tones for sunset, deeper hues for night.</li>
        </ul>
    </div>
</div>

<script>
    // Lucide ships as a UMD bundle that exposes a global `lucide` object.
    // Call createIcons() now to swap every <i data-lucide="..."> placeholder
    // with its SVG; if Lucide is still loading we retry shortly after.
    (function () {
        function paintIcons() {
            if (typeof window.refreshAppShellIcons === 'function') {
                window.refreshAppShellIcons();
                return true;
            }
            if (typeof lucide !== 'undefined' && typeof lucide.createIcons === 'function') {
                lucide.createIcons();
                return true;
            }
            return false;
        }

        if (paintIcons()) return;

        var attempts = 0;
        var timer = setInterval(function () {
            attempts += 1;
            if (paintIcons() || attempts > 20) {
                clearInterval(timer);
            }
        }, 100);
    })();
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
