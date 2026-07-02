<?php
require_once __DIR__ . '/../../config/app.php';
checkAuth();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/file_management_helper.php';

if (!file_hub_user_can_view()) {
    redirect('modules/dashboard/index?error=access_denied');
}

$manage = file_hub_user_can_manage_library();
$reqFolder = isset($_GET['folder']) ? (int) $_GET['folder'] : 0;
$currentFolderId = $reqFolder > 0 ? file_library_normalize_folder_id($pdo, $reqFolder) : null;
$flashErr = null;
if ($reqFolder > 0 && $currentFolderId === null) {
    $flashErr = 'That folder no longer exists or is invalid.';
}

$storage = file_hub_native_storage_summary($pdo);
$activity = file_hub_native_activity_last_days($pdo, 7);
$breadcrumbs = file_library_breadcrumb($pdo, $currentFolderId);
$childFolders = file_library_child_folders($pdo, $currentFolderId);
$folderFiles = file_library_files_in_folder($pdo, $currentFolderId);
$parentId = file_library_parent_id_for($pdo, $currentFolderId);

$flashOk = $_GET['ok'] ?? null;
$flashErr = $flashErr ?? ($_GET['error'] ?? null);

$folderPalette = [
    ['bg' => 'bg-sky-100', 'text' => 'text-sky-600'],
    ['bg' => 'bg-violet-100', 'text' => 'text-violet-600'],
    ['bg' => 'bg-amber-100', 'text' => 'text-amber-600'],
    ['bg' => 'bg-emerald-100', 'text' => 'text-emerald-600'],
    ['bg' => 'bg-rose-100', 'text' => 'text-rose-600'],
    ['bg' => 'bg-indigo-100', 'text' => 'text-indigo-600'],
];

$activityLabels = array_keys($activity);
$seriesMedia = [];
$seriesDocs = [];
$seriesMusic = [];
foreach ($activity as $day => $row) {
    $seriesMedia[] = (int) ($row['media'] ?? 0);
    $seriesDocs[] = (int) ($row['documents'] ?? 0);
    $seriesMusic[] = (int) ($row['music'] ?? 0);
}

$returnFolderField = $currentFolderId !== null ? (string) (int) $currentFolderId : '';

include '../../includes/header.php';
?>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<div class="file-manager max-w-[1400px] mx-auto pb-10">
<div class="rounded-2xl border border-slate-200/90 bg-gradient-to-br from-slate-50 via-white to-slate-50/80 shadow-sm p-5 md:p-6 mb-6">
    <div class="flex flex-col lg:flex-row lg:items-start lg:justify-between gap-5">
        <div class="min-w-0">
            <p class="flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wider text-slate-500 mb-1">
                <i class="material-icons text-base text-slate-400" aria-hidden="true">inventory_2</i>
                File library
            </p>
            <h1 class="text-2xl md:text-3xl font-bold text-slate-900 tracking-tight">Files</h1>
            <p class="text-sm text-slate-600 mt-2 max-w-2xl leading-relaxed">Organize folders, preview media, and open documents—everything in your shared library in one place.</p>
        </div>
        <?php if ($manage): ?>
            <div class="flex flex-wrap items-center gap-2 shrink-0">
                <button type="button" onclick="document.getElementById('modal-new-folder').classList.remove('hidden');" class="inline-flex items-center justify-center gap-1.5 rounded-xl bg-indigo-600 text-white px-4 py-2.5 text-sm font-semibold shadow-sm hover:bg-indigo-700 border border-indigo-600 transition">
                    <i class="material-icons text-lg" aria-hidden="true">create_new_folder</i>
                    <span>New folder</span>
                </button>
                <button type="button" onclick="document.getElementById('modal-upload').classList.remove('hidden');" class="inline-flex items-center justify-center gap-1.5 rounded-xl bg-white text-slate-800 px-4 py-2.5 text-sm font-semibold border border-slate-200 shadow-sm hover:bg-slate-50 transition">
                    <i class="material-icons text-lg text-slate-500" aria-hidden="true">upload_file</i>
                    <span>Upload</span>
                </button>
            </div>
        <?php endif; ?>
    </div>
    <div class="mt-5 pt-5 border-t border-slate-100 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
        <nav class="flex flex-wrap items-center gap-x-1 gap-y-1 text-sm min-w-0" aria-label="Breadcrumb">
            <?php
            $lastIdx = count($breadcrumbs) - 1;
            foreach ($breadcrumbs as $i => $crumb):
                $isLast = $i === $lastIdx;
                $cid = $crumb['id'];
                ?>
                <?php if ($i > 0): ?>
                    <span class="text-slate-300 select-none" aria-hidden="true">/</span>
                <?php endif; ?>
                <?php if (!$isLast): ?>
                    <a href="<?php echo htmlspecialchars(file_library_nav_url($cid)); ?>" class="text-indigo-600 hover:text-indigo-800 font-medium truncate max-w-[10rem] sm:max-w-xs"><?php echo htmlspecialchars($crumb['name']); ?></a>
                <?php else: ?>
                    <span class="text-slate-900 font-semibold truncate max-w-[12rem] sm:max-w-md"><?php echo htmlspecialchars($crumb['name']); ?></span>
                <?php endif; ?>
            <?php endforeach; ?>
        </nav>
        <?php if ($currentFolderId !== null): ?>
            <a href="<?php echo htmlspecialchars(file_library_nav_url($parentId)); ?>" class="inline-flex items-center gap-1 text-sm font-semibold text-indigo-600 hover:text-indigo-800 shrink-0">
                <i class="material-icons text-lg" aria-hidden="true">arrow_upward</i>
                Up one level
            </a>
        <?php endif; ?>
    </div>
</div>

<?php if ($flashOk === 'folder'): ?>
    <div class="mb-4 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900">Folder created successfully.</div>
<?php elseif ($flashOk === 'upload'): ?>
    <div class="mb-4 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900">File uploaded.</div>
<?php elseif ($flashOk === 'sync'): ?>
    <?php
    $syncAdded = (int) ($_GET['registered'] ?? 0);
    ?>
    <div class="mb-4 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900">
        <?php if ($syncAdded > 0): ?>
            Library list updated. <?php echo $syncAdded; ?> new file<?php echo $syncAdded === 1 ? '' : 's'; ?> <?php echo $syncAdded === 1 ? 'is' : 'are'; ?> now shown here.
        <?php else: ?>
            Library list updated. Everything here was already up to date.
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php if (!empty($flashErr)): ?>
    <div class="mb-4 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-900"><?php echo htmlspecialchars((string) $flashErr); ?></div>
<?php endif; ?>

<div class="grid grid-cols-1 xl:grid-cols-12 gap-6 xl:gap-8">
    <div class="xl:col-span-8 space-y-6">
        <section class="bg-white border border-slate-200 rounded-2xl p-5 md:p-6 shadow-sm">
            <div class="flex flex-wrap items-center justify-between gap-3 mb-3">
                <div class="flex items-center gap-2">
                    <span class="inline-flex h-10 w-10 items-center justify-center rounded-xl bg-amber-50 text-amber-600">
                        <i class="material-icons" aria-hidden="true">folder_open</i>
                    </span>
                    <div>
                        <h2 class="text-lg font-bold text-slate-900 leading-tight">Folders</h2>
                        <p class="text-xs text-slate-500"><?php echo count($childFolders); ?> in this location</p>
                    </div>
                </div>
                <?php if ($manage && file_hub_table_exists($pdo, 'file_library_files')): ?>
                    <form method="post" action="<?php echo BASE_URL; ?>modules/files/save" class="inline">
                        <input type="hidden" name="_csrf" value="<?php echo htmlspecialchars(csrf_token('file_hub')); ?>">
                        <input type="hidden" name="action" value="sync_disk">
                        <input type="hidden" name="return_folder" value="<?php echo htmlspecialchars($returnFolderField); ?>">
                        <button type="submit" class="text-sm font-semibold text-indigo-600 hover:text-indigo-800 px-2 py-1 rounded-lg hover:bg-indigo-50 transition">Refresh library</button>
                    </form>
                <?php endif; ?>
            </div>
            <p class="text-xs text-slate-500 mb-4 leading-relaxed">Open a folder to work inside it. New folders and uploads are saved where you are now. If something is missing from the list, try Refresh library.</p>
            <?php if ($childFolders === []): ?>
                <div class="rounded-xl border border-dashed border-slate-200 bg-slate-50/50 px-4 py-10 text-center">
                    <i class="material-icons text-4xl text-slate-300 mb-2" aria-hidden="true">folder_off</i>
                    <p class="text-sm text-slate-600 font-medium">No subfolders here</p>
                    <?php if ($manage): ?><p class="text-xs text-slate-500 mt-1">Use <strong>New folder</strong> to create one.</p><?php endif; ?>
                </div>
            <?php else: ?>
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
                    <?php foreach ($childFolders as $idx => $sf):
                        $pal = $folderPalette[$idx % count($folderPalette)];
                        $fc = (int) ($sf['file_count'] ?? 0);
                        $sc = (int) ($sf['subfolder_count'] ?? 0);
                        ?>
                        <a href="<?php echo htmlspecialchars(file_library_nav_url((int) $sf['id'])); ?>" class="group flex items-start gap-3 rounded-xl border border-slate-100 hover:border-indigo-200 hover:shadow-md transition bg-gradient-to-br from-white to-slate-50/90 p-4">
                            <span class="inline-flex h-12 w-12 shrink-0 items-center justify-center rounded-xl <?php echo $pal['bg']; ?>">
                                <i class="material-icons <?php echo $pal['text']; ?> text-2xl" aria-hidden="true">folder</i>
                            </span>
                            <div class="min-w-0 flex-1">
                                <p class="font-semibold text-slate-900 group-hover:text-indigo-700 truncate"><?php echo htmlspecialchars($sf['name']); ?></p>
                                <p class="text-xs text-slate-500 mt-0.5"><?php echo (int) $fc; ?> file<?php echo $fc === 1 ? '' : 's'; ?><?php if ($sc > 0): ?> · <?php echo (int) $sc; ?> subfolder<?php echo $sc === 1 ? '' : 's'; ?><?php endif; ?></p>
                            </div>
                            <i class="material-icons text-slate-300 group-hover:text-indigo-400 text-xl shrink-0" aria-hidden="true">chevron_right</i>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <section class="bg-white border border-slate-200 rounded-2xl p-5 md:p-6 shadow-sm" id="file-library-files-section">
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-5">
                <div class="flex items-center gap-3">
                    <span class="inline-flex h-10 w-10 items-center justify-center rounded-xl bg-indigo-50 text-indigo-600">
                        <i class="material-icons" aria-hidden="true">insert_drive_file</i>
                    </span>
                    <div>
                        <h2 class="text-lg font-bold text-slate-900 leading-tight">Files in this folder</h2>
                        <p class="text-xs text-slate-500"><?php echo count($folderFiles); ?> item<?php echo count($folderFiles) === 1 ? '' : 's'; ?></p>
                    </div>
                </div>
                <?php if ($folderFiles !== []): ?>
                <div class="inline-flex rounded-xl border border-slate-200 bg-slate-50 p-1 shadow-inner" role="group" aria-label="View mode">
                    <button type="button" id="file-view-grid" class="file-view-toggle inline-flex items-center gap-1 px-3 py-1.5 rounded-lg text-sm font-medium transition" data-mode="grid" aria-pressed="true">
                        <i class="material-icons text-lg" aria-hidden="true">grid_view</i>
                        <span class="hidden sm:inline">Grid</span>
                    </button>
                    <button type="button" id="file-view-list" class="file-view-toggle inline-flex items-center gap-1 px-3 py-1.5 rounded-lg text-sm font-medium transition" data-mode="list" aria-pressed="false">
                        <i class="material-icons text-lg" aria-hidden="true">view_list</i>
                        <span class="hidden sm:inline">List</span>
                    </button>
                </div>
                <?php endif; ?>
            </div>

            <?php if ($folderFiles === []): ?>
                <div class="rounded-xl border border-dashed border-slate-200 bg-slate-50/50 px-4 py-14 text-center">
                    <i class="material-icons text-5xl text-slate-200 mb-3" aria-hidden="true">cloud_upload</i>
                    <p class="text-slate-700 font-medium">This folder is empty</p>
                    <p class="text-sm text-slate-500 mt-1 max-w-sm mx-auto"><?php echo $manage ? 'Upload a file or add a subfolder to get started.' : 'No files have been shared here yet.'; ?></p>
                </div>
            <?php else: ?>
                <div id="file-grid-wrap" class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-3 sm:gap-4">
                    <?php foreach ($folderFiles as $f):
                        $rawUrl = BASE_URL . ltrim((string) $f['relative_path'], '/');
                        $fileUrlAttr = htmlspecialchars($rawUrl, ENT_QUOTES, 'UTF-8');
                        $nameRaw = (string) $f['original_name'];
                        $fileName = htmlspecialchars($nameRaw, ENT_QUOTES, 'UTF-8');
                        $previewCat = file_hub_preview_category($f['mime_type'] ?? null, (string) $f['designation'], $nameRaw);
                        $ts = isset($f['created_at']) ? strtotime((string) $f['created_at']) : 0;
                        $sizeStr = htmlspecialchars(file_hub_format_bytes((int) $f['file_size']));
                        $designation = htmlspecialchars((string) $f['designation']);
                        $uploader = htmlspecialchars((string) ($f['uploader_name'] ?? ''));
                        $dateStr = $ts ? htmlspecialchars(date('M j, Y', $ts)) : '—';
                        $canPreview = in_array($previewCat, ['image', 'video', 'audio', 'pdf'], true);
                        ?>
                        <article class="file-card flex flex-col rounded-xl border border-slate-100 bg-white hover:border-indigo-200 hover:shadow-md transition overflow-hidden">
                            <div class="relative aspect-[4/3] bg-gradient-to-br from-slate-100 to-slate-200/90 overflow-hidden shrink-0">
                                <?php if ($previewCat === 'image'): ?>
                                    <button type="button" class="file-preview-trigger absolute inset-0 flex items-center justify-center p-0 border-0 bg-transparent cursor-zoom-in group" data-preview-kind="image" data-preview-url="<?php echo $fileUrlAttr; ?>" data-preview-title="<?php echo $fileName; ?>" title="Preview">
                                        <img src="<?php echo $fileUrlAttr; ?>" alt="" class="w-full h-full object-cover transition duration-300 group-hover:scale-[1.02]" loading="lazy" decoding="async">
                                    </button>
                                <?php elseif ($previewCat === 'video'): ?>
                                    <button type="button" class="file-preview-trigger absolute inset-0 flex items-stretch justify-stretch p-0 border-0 bg-slate-900 cursor-pointer" data-preview-kind="video" data-preview-url="<?php echo $fileUrlAttr; ?>" data-preview-title="<?php echo $fileName; ?>" title="Play">
                                        <video muted playsinline preload="metadata" src="<?php echo $fileUrlAttr; ?>" class="w-full h-full object-cover opacity-90 pointer-events-none" aria-hidden="true"></video>
                                        <span class="absolute inset-0 flex items-center justify-center bg-black/35 pointer-events-none">
                                            <i class="material-icons text-white drop-shadow-lg" style="font-size: 2.75rem;" aria-hidden="true">play_circle_filled</i>
                                        </span>
                                    </button>
                                <?php elseif ($previewCat === 'audio'): ?>
                                    <div class="absolute inset-0 flex flex-col items-center justify-center p-3 bg-gradient-to-br from-rose-50 to-indigo-50">
                                        <i class="material-icons text-rose-400 mb-2" style="font-size: 2.5rem;" aria-hidden="true">audiotrack</i>
                                        <audio controls preload="none" src="<?php echo $fileUrlAttr; ?>" class="w-full max-w-full h-8" title="<?php echo $fileName; ?>"></audio>
                                    </div>
                                <?php elseif ($previewCat === 'pdf'): ?>
                                    <button type="button" class="file-preview-trigger absolute inset-0 flex flex-col items-center justify-center gap-1 bg-gradient-to-br from-red-50 to-orange-50 text-red-700 border-0 cursor-pointer" data-preview-kind="pdf" data-preview-url="<?php echo $fileUrlAttr; ?>" data-preview-title="<?php echo $fileName; ?>" title="Preview PDF">
                                        <i class="material-icons" style="font-size: 3rem;" aria-hidden="true">picture_as_pdf</i>
                                        <span class="text-xs font-semibold uppercase tracking-wide">PDF</span>
                                    </button>
                                <?php else: ?>
                                    <div class="absolute inset-0 flex flex-col items-center justify-center text-slate-400 p-2">
                                        <i class="material-icons" style="font-size: 2.75rem;" aria-hidden="true">description</i>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div class="p-2.5 sm:p-3 flex flex-col flex-1 min-h-0 border-t border-slate-100 bg-white">
                                <p class="text-xs font-semibold text-slate-900 truncate leading-snug" title="<?php echo $fileName; ?>"><?php echo $fileName; ?></p>
                                <p class="text-[10px] sm:text-xs text-slate-500 mt-0.5"><?php echo $sizeStr; ?> · <span class="capitalize"><?php echo $designation; ?></span></p>
                                <div class="mt-auto pt-2 flex items-center justify-end gap-0.5">
                                    <?php if ($canPreview): ?>
                                        <button type="button" class="file-preview-trigger inline-flex p-1 rounded-lg text-slate-500 hover:bg-indigo-50 hover:text-indigo-600" data-preview-kind="<?php echo htmlspecialchars($previewCat, ENT_QUOTES, 'UTF-8'); ?>" data-preview-url="<?php echo $fileUrlAttr; ?>" data-preview-title="<?php echo $fileName; ?>" title="Preview">
                                            <i class="material-icons text-lg" aria-hidden="true"><?php echo $previewCat === 'pdf' ? 'visibility' : ($previewCat === 'video' ? 'play_arrow' : 'zoom_in'); ?></i>
                                        </button>
                                    <?php endif; ?>
                                    <a href="<?php echo $fileUrlAttr; ?>" class="inline-flex p-1 rounded-lg text-slate-500 hover:bg-slate-100" title="Open in new tab" target="_blank" rel="noopener">
                                        <i class="material-icons text-lg" aria-hidden="true">open_in_new</i>
                                    </a>
                                </div>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>

                <div id="file-list-wrap" class="hidden overflow-x-auto rounded-xl border border-slate-100">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="text-left text-xs uppercase tracking-wide text-slate-400 border-b border-slate-100 bg-slate-50/80">
                                <th class="py-3 pl-3 pr-2 font-semibold w-16"> </th>
                                <th class="py-3 pr-4 font-semibold">Name</th>
                                <th class="py-3 pr-4 font-semibold hidden md:table-cell">Type</th>
                                <th class="py-3 pr-4 font-semibold">Size</th>
                                <th class="py-3 pr-4 font-semibold hidden lg:table-cell">Uploaded</th>
                                <th class="py-3 pr-4 font-semibold hidden xl:table-cell">By</th>
                                <th class="py-3 pr-3 font-semibold text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-50 bg-white">
                            <?php foreach ($folderFiles as $f):
                                $rawUrl = BASE_URL . ltrim((string) $f['relative_path'], '/');
                                $fileUrlAttr = htmlspecialchars($rawUrl, ENT_QUOTES, 'UTF-8');
                                $nameRaw = (string) $f['original_name'];
                                $fileName = htmlspecialchars($nameRaw, ENT_QUOTES, 'UTF-8');
                                $previewCat = file_hub_preview_category($f['mime_type'] ?? null, (string) $f['designation'], $nameRaw);
                                $ts = isset($f['created_at']) ? strtotime((string) $f['created_at']) : 0;
                                $sizeStr = htmlspecialchars(file_hub_format_bytes((int) $f['file_size']));
                                $designation = htmlspecialchars((string) $f['designation']);
                                $uploader = htmlspecialchars((string) ($f['uploader_name'] ?? ''));
                                $dateStr = $ts ? htmlspecialchars(date('M j, Y', $ts)) : '—';
                                $canPreview = in_array($previewCat, ['image', 'video', 'audio', 'pdf'], true);
                                ?>
                                <tr class="text-slate-700 hover:bg-slate-50/80 transition">
                                    <td class="py-2 pl-3 pr-2 align-middle w-14">
                                        <div class="h-11 w-11 rounded-lg overflow-hidden bg-slate-100 border border-slate-100 flex items-center justify-center shrink-0">
                                            <?php if ($previewCat === 'image'): ?>
                                                <img src="<?php echo $fileUrlAttr; ?>" alt="" class="w-full h-full object-cover" loading="lazy" decoding="async">
                                            <?php elseif ($previewCat === 'video'): ?>
                                                <i class="material-icons text-slate-400 text-2xl" aria-hidden="true">movie</i>
                                            <?php elseif ($previewCat === 'audio'): ?>
                                                <i class="material-icons text-rose-300 text-2xl" aria-hidden="true">audiotrack</i>
                                            <?php elseif ($previewCat === 'pdf'): ?>
                                                <i class="material-icons text-red-400 text-2xl" aria-hidden="true">picture_as_pdf</i>
                                            <?php else: ?>
                                                <i class="material-icons text-slate-400 text-2xl" aria-hidden="true">description</i>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td class="py-2 pr-4 align-middle min-w-[8rem] max-w-[14rem]">
                                        <span class="font-medium text-slate-900 truncate block" title="<?php echo $fileName; ?>"><?php echo $fileName; ?></span>
                                    </td>
                                    <td class="py-2 pr-4 align-middle whitespace-nowrap text-slate-500 capitalize hidden md:table-cell"><?php echo $designation; ?></td>
                                    <td class="py-2 pr-4 align-middle whitespace-nowrap"><?php echo $sizeStr; ?></td>
                                    <td class="py-2 pr-4 align-middle whitespace-nowrap text-slate-600 hidden lg:table-cell"><?php echo $dateStr; ?></td>
                                    <td class="py-2 pr-4 align-middle text-slate-600 truncate max-w-[8rem] hidden xl:table-cell" title="<?php echo $uploader; ?>"><?php echo $uploader; ?></td>
                                    <td class="py-2 pr-3 align-middle text-right whitespace-nowrap">
                                        <?php if ($canPreview): ?>
                                            <button type="button" class="file-preview-trigger inline-flex p-1 rounded-lg text-slate-500 hover:bg-indigo-50 hover:text-indigo-600 align-middle" data-preview-kind="<?php echo htmlspecialchars($previewCat, ENT_QUOTES, 'UTF-8'); ?>" data-preview-url="<?php echo $fileUrlAttr; ?>" data-preview-title="<?php echo $fileName; ?>" title="Preview">
                                                <i class="material-icons text-base" aria-hidden="true">visibility</i>
                                            </button>
                                        <?php endif; ?>
                                        <a href="<?php echo $fileUrlAttr; ?>" class="inline-flex p-1 rounded-lg text-slate-500 hover:bg-slate-100 align-middle" title="Open" target="_blank" rel="noopener">
                                            <i class="material-icons text-base" aria-hidden="true">open_in_new</i>
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>

        <section class="rounded-2xl border border-slate-200 bg-slate-50/80 p-4 text-sm text-slate-600">
            <span class="font-semibold text-slate-800">Elsewhere in the app</span>
            <span class="mx-2 text-slate-300">·</span>
            <?php if (hasPermission('view_tasks')): ?><a class="text-indigo-600 hover:text-indigo-800 font-medium hover:underline" href="<?php echo BASE_URL; ?>modules/tasks/list">Tasks</a><?php endif; ?>
            <?php if (hasPermission('view_projects')): ?><span class="mx-1 text-slate-300">·</span><a class="text-indigo-600 hover:text-indigo-800 font-medium hover:underline" href="<?php echo BASE_URL; ?>modules/projects/list">Projects</a><?php endif; ?>
            <span class="mx-1 text-slate-300">·</span><a class="text-indigo-600 hover:text-indigo-800 font-medium hover:underline" href="<?php echo BASE_URL; ?>modules/messaging/inbox">Messages</a>
            <?php if (hasPermission('view_invoices')): ?><span class="mx-1 text-slate-300">·</span><a class="text-indigo-600 hover:text-indigo-800 font-medium hover:underline" href="<?php echo BASE_URL; ?>modules/invoices/list">Invoices</a><?php endif; ?>
            <span class="block text-xs text-slate-500 mt-2 leading-relaxed">Attachments on those screens stay with their item. Use this library for shared files everyone can browse here.</span>
        </section>
    </div>

    <div class="xl:col-span-4 space-y-6 xl:sticky xl:top-20 xl:self-start">
        <section class="bg-white border border-slate-200 rounded-2xl p-5 shadow-sm">
            <h2 class="text-lg font-bold text-slate-900 mb-1">Library storage</h2>
            <p class="text-xs text-slate-500 mb-4 leading-relaxed">Space used by files in this library (not attachments only visible on other screens).</p>
            <div class="relative h-44 w-44 mx-auto">
                <canvas id="storageDonut"></canvas>
                <div class="absolute inset-0 flex flex-col items-center justify-center pointer-events-none">
                    <span class="text-2xl font-extrabold text-slate-900"><?php echo (int) $storage['percent_used']; ?>%</span>
                    <span class="text-xs text-slate-500">of quota</span>
                </div>
            </div>
            <p class="text-center text-xs text-slate-500 mt-2">
                <?php echo htmlspecialchars(file_hub_format_bytes($storage['used_bytes'])); ?> /
                <?php echo htmlspecialchars(file_hub_format_bytes($storage['quota_bytes'])); ?>
            </p>
            <div class="mt-5 space-y-3">
                <?php
                $bd = $storage['by_designation'] ?? [];
                $barStyles = [
                    'media' => 'bg-violet-500',
                    'documents' => 'bg-amber-500',
                    'music' => 'bg-rose-500',
                    'other' => 'bg-sky-400',
                ];
                foreach (['media' => 'Media', 'documents' => 'Documents', 'music' => 'Music', 'other' => 'Other'] as $key => $lab):
                    $bytes = (int) ($bd[$key] ?? 0);
                    $pctBar = $storage['used_bytes'] > 0 ? min(100, (int) round(($bytes / $storage['used_bytes']) * 100)) : 0;
                    ?>
                    <div>
                        <div class="flex justify-between text-xs font-medium text-slate-600 mb-1">
                            <span><?php echo htmlspecialchars($lab); ?></span>
                            <span><?php echo htmlspecialchars(file_hub_format_bytes($bytes)); ?></span>
                        </div>
                        <div class="h-2 rounded-full bg-slate-100 overflow-hidden">
                            <div class="h-full rounded-full <?php echo $barStyles[$key]; ?>" style="width: <?php echo $pctBar; ?>%"></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>

        <section class="bg-white border border-slate-200 rounded-2xl p-5 shadow-sm">
            <h2 class="text-lg font-bold text-slate-900 mb-1">Library activity</h2>
            <p class="text-xs text-slate-500 mb-4 leading-relaxed">Uploads here over the last 7 days.</p>
            <canvas id="activityBar" height="200"></canvas>
            <div class="flex flex-wrap justify-center gap-4 mt-4 text-xs text-slate-500">
                <span class="inline-flex items-center gap-1"><span class="h-2 w-2 rounded-full bg-violet-500"></span> Media</span>
                <span class="inline-flex items-center gap-1"><span class="h-2 w-2 rounded-full bg-amber-500"></span> Docs</span>
                <span class="inline-flex items-center gap-1"><span class="h-2 w-2 rounded-full bg-rose-500"></span> Music</span>
            </div>
        </section>
    </div>
</div>

</div>

<div id="file-preview-modal" class="hidden fixed inset-0 z-[60] flex items-center justify-center p-4 sm:p-6 bg-slate-900/80 backdrop-blur-sm" role="dialog" aria-modal="true" aria-labelledby="file-preview-title-text">
    <button type="button" id="file-preview-close" class="absolute top-3 right-3 sm:top-5 sm:right-5 inline-flex h-10 w-10 items-center justify-center rounded-full bg-white/10 text-white hover:bg-white/20 transition border border-white/20 z-10" aria-label="Close preview">
        <i class="material-icons text-2xl" aria-hidden="true">close</i>
    </button>
    <div class="relative w-full max-w-5xl max-h-[92vh] flex flex-col items-center gap-3 mt-8 sm:mt-0">
        <p id="file-preview-title-text" class="text-white text-sm font-medium text-center truncate max-w-full px-8 drop-shadow"></p>
        <div class="w-full rounded-xl overflow-hidden shadow-2xl bg-black flex items-center justify-center max-h-[calc(92vh-4rem)] border border-white/10">
            <img id="file-preview-img" src="" alt="" class="hidden max-w-full max-h-[min(80vh,900px)] object-contain">
            <video id="file-preview-video" controls playsinline class="hidden w-full max-h-[min(80vh,900px)]"></video>
            <div id="file-preview-audio-wrap" class="hidden w-full max-w-lg p-8 bg-gradient-to-br from-slate-800 to-slate-900">
                <audio id="file-preview-audio" controls class="w-full"></audio>
            </div>
            <iframe id="file-preview-pdf" title="PDF preview" class="hidden w-full min-h-[min(75vh,800px)] bg-white"></iframe>
        </div>
    </div>
</div>

<?php if ($manage): ?>
<div id="modal-new-folder" class="hidden fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/40" role="dialog" aria-modal="true">
    <div class="bg-white rounded-2xl max-w-md w-full p-6 shadow-xl">
        <h3 class="text-lg font-bold text-gray-900 mb-1">New folder</h3>
        <p class="text-xs text-gray-500 mb-4">This folder will appear inside: <strong><?php echo htmlspecialchars($breadcrumbs[count($breadcrumbs) - 1]['name'] ?? 'Library'); ?></strong></p>
        <form method="post" action="<?php echo BASE_URL; ?>modules/files/save" class="space-y-4">
            <input type="hidden" name="_csrf" value="<?php echo htmlspecialchars(csrf_token('file_hub')); ?>">
            <input type="hidden" name="action" value="create_folder">
            <input type="hidden" name="parent_folder_id" value="<?php echo htmlspecialchars($returnFolderField); ?>">
            <input type="hidden" name="return_folder" value="<?php echo htmlspecialchars($returnFolderField); ?>">
            <div>
                <label class="block text-xs font-semibold text-gray-600 mb-1">Folder name</label>
                <input type="text" name="folder_name" required maxlength="200" class="w-full rounded-lg border border-gray-200 px-3 py-2 text-sm" placeholder="e.g. Contract drafts">
            </div>
            <div class="flex justify-end gap-2 pt-2">
                <button type="button" class="surface-button px-4" onclick="document.getElementById('modal-new-folder').classList.add('hidden');">Cancel</button>
                <button type="submit" class="list-action-btn bg-blue-600 text-white border-blue-600">Create</button>
            </div>
        </form>
    </div>
</div>

<div id="modal-upload" class="hidden fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/40" role="dialog" aria-modal="true">
    <div class="bg-white rounded-2xl max-w-md w-full p-6 shadow-xl">
        <h3 class="text-lg font-bold text-gray-900 mb-1">Upload</h3>
        <p class="text-xs text-gray-500 mb-4">Your file will go into: <strong><?php echo htmlspecialchars($breadcrumbs[count($breadcrumbs) - 1]['name'] ?? 'Library'); ?></strong></p>
        <form method="post" action="<?php echo BASE_URL; ?>modules/files/upload" enctype="multipart/form-data" class="space-y-4">
            <input type="hidden" name="_csrf" value="<?php echo htmlspecialchars(csrf_token('file_hub')); ?>">
            <input type="hidden" name="folder_id" value="<?php echo htmlspecialchars($returnFolderField); ?>">
            <input type="hidden" name="return_folder" value="<?php echo htmlspecialchars($returnFolderField); ?>">
            <div>
                <label class="block text-xs font-semibold text-gray-600 mb-1">File</label>
                <input type="file" name="file" required class="w-full text-sm">
            </div>
            <div class="flex justify-end gap-2 pt-2">
                <button type="button" class="surface-button px-4" onclick="document.getElementById('modal-upload').classList.add('hidden');">Cancel</button>
                <button type="submit" class="list-action-btn bg-blue-600 text-white border-blue-600">Upload</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<script>
(function () {
    const used = <?php echo json_encode((int) $storage['percent_used']); ?>;
    const free = Math.max(0, 100 - used);
    const donutCtx = document.getElementById('storageDonut');
    if (donutCtx) {
        new Chart(donutCtx, {
            type: 'doughnut',
            data: {
                labels: ['Used', 'Free'],
                datasets: [{
                    data: [used, free],
                    backgroundColor: ['#4f46e5', '#e5e7eb'],
                    borderWidth: 0,
                }],
            },
            options: {
                cutout: '72%',
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: function (ctx) {
                                if (ctx.dataIndex === 0) return 'Used: ' + ctx.raw + '%';
                                return 'Available: ' + ctx.raw + '%';
                            }
                        }
                    }
                },
            },
        });
    }

    const barCtx = document.getElementById('activityBar');
    if (barCtx) {
        new Chart(barCtx, {
            type: 'bar',
            data: {
                labels: <?php echo json_encode($activityLabels); ?>,
                datasets: [
                    { label: 'Media', data: <?php echo json_encode($seriesMedia); ?>, backgroundColor: '#8b5cf6' },
                    { label: 'Docs', data: <?php echo json_encode($seriesDocs); ?>, backgroundColor: '#f59e0b' },
                    { label: 'Music', data: <?php echo json_encode($seriesMusic); ?>, backgroundColor: '#f43f5e' },
                ],
            },
            options: {
                responsive: true,
                scales: {
                    x: { stacked: true, grid: { display: false } },
                    y: { stacked: true, beginAtZero: true, ticks: { precision: 0 } },
                },
                plugins: { legend: { display: false } },
            },
        });
    }

    var VIEW_KEY = 'pressErpFileLibraryView';
    var gridWrap = document.getElementById('file-grid-wrap');
    var listWrap = document.getElementById('file-list-wrap');
    var btnGrid = document.getElementById('file-view-grid');
    var btnList = document.getElementById('file-view-list');

    function setView(mode) {
        if (!gridWrap || !listWrap || !btnGrid || !btnList) return;
        var isGrid = mode === 'grid';
        gridWrap.classList.toggle('hidden', !isGrid);
        listWrap.classList.toggle('hidden', isGrid);
        btnGrid.classList.toggle('bg-white', isGrid);
        btnGrid.classList.toggle('shadow-sm', isGrid);
        btnGrid.classList.toggle('text-indigo-700', isGrid);
        btnGrid.classList.toggle('text-slate-600', !isGrid);
        btnList.classList.toggle('bg-white', !isGrid);
        btnList.classList.toggle('shadow-sm', !isGrid);
        btnList.classList.toggle('text-indigo-700', !isGrid);
        btnList.classList.toggle('text-slate-600', isGrid);
        btnGrid.setAttribute('aria-pressed', isGrid ? 'true' : 'false');
        btnList.setAttribute('aria-pressed', (!isGrid) ? 'true' : 'false');
        try { localStorage.setItem(VIEW_KEY, mode); } catch (e) {}
    }

    if (btnGrid && btnList && gridWrap && listWrap) {
        var initial = 'grid';
        try { initial = localStorage.getItem(VIEW_KEY) || 'grid'; } catch (e) {}
        if (initial !== 'list' && initial !== 'grid') initial = 'grid';
        setView(initial);
        btnGrid.addEventListener('click', function () { setView('grid'); });
        btnList.addEventListener('click', function () { setView('list'); });
    }

    var modal = document.getElementById('file-preview-modal');
    var pelImg = document.getElementById('file-preview-img');
    var pelVid = document.getElementById('file-preview-video');
    var pelAud = document.getElementById('file-preview-audio');
    var pelAudWrap = document.getElementById('file-preview-audio-wrap');
    var pelPdf = document.getElementById('file-preview-pdf');
    var pelTitle = document.getElementById('file-preview-title-text');

    function hideAllPreviewMedia() {
        if (pelImg) {
            pelImg.classList.add('hidden');
            pelImg.removeAttribute('src');
            pelImg.removeAttribute('alt');
        }
        if (pelVid) {
            pelVid.pause();
            pelVid.removeAttribute('src');
            pelVid.load();
            pelVid.classList.add('hidden');
        }
        if (pelAud) {
            pelAud.pause();
            pelAud.removeAttribute('src');
            pelAud.load();
        }
        if (pelAudWrap) pelAudWrap.classList.add('hidden');
        if (pelPdf) {
            pelPdf.classList.add('hidden');
            pelPdf.src = 'about:blank';
        }
    }

    function closePreview() {
        if (!modal) return;
        modal.classList.add('hidden');
        document.body.classList.remove('overflow-hidden');
        hideAllPreviewMedia();
    }

    function openPreview(kind, url, title) {
        if (!modal || !url) return;
        hideAllPreviewMedia();
        if (pelTitle) pelTitle.textContent = title || '';

        if (kind === 'image' && pelImg) {
            pelImg.src = url;
            pelImg.alt = title || '';
            pelImg.classList.remove('hidden');
        } else if (kind === 'video' && pelVid) {
            pelVid.src = url;
            pelVid.classList.remove('hidden');
        } else if (kind === 'audio' && pelAud && pelAudWrap) {
            pelAud.src = url;
            pelAudWrap.classList.remove('hidden');
        } else if (kind === 'pdf' && pelPdf) {
            pelPdf.src = url;
            pelPdf.classList.remove('hidden');
        } else {
            window.open(url, '_blank', 'noopener');
            return;
        }
        modal.classList.remove('hidden');
        document.body.classList.add('overflow-hidden');
    }

    document.querySelectorAll('.file-preview-trigger').forEach(function (btn) {
        btn.addEventListener('click', function () {
            openPreview(btn.getAttribute('data-preview-kind'), btn.getAttribute('data-preview-url'), btn.getAttribute('data-preview-title'));
        });
    });

    var closeBtn = document.getElementById('file-preview-close');
    if (closeBtn) closeBtn.addEventListener('click', closePreview);
    if (modal) {
        modal.addEventListener('click', function (e) {
            if (e.target === modal) closePreview();
        });
    }
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && modal && !modal.classList.contains('hidden')) closePreview();
    });
})();
</script>

<?php include '../../includes/footer.php'; ?>
