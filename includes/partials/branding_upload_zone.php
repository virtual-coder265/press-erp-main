<?php
/**
 * Drag-and-drop branding upload zone.
 *
 * Expected variables:
 * - $zoneId (string) unique DOM id prefix
 * - $inputName (string) file input name, e.g. system_logo
 * - $removeFlagName (string) hidden remove flag, e.g. remove_system_logo
 * - $label (string)
 * - $hint (string)
 * - $currentUrl (string) resolved image URL for preview
 * - $hasCustom (bool) whether a custom upload is active (shows Remove)
 * - $accept (string) optional accept attribute
 * - $previewVariant (string) logo|favicon — controls preview frame styling
 */
$zoneId = (string) ($zoneId ?? 'branding');
$inputName = (string) ($inputName ?? 'system_logo');
$removeFlagName = (string) ($removeFlagName ?? 'remove_' . $inputName);
$label = (string) ($label ?? 'Logo');
$hint = (string) ($hint ?? '');
$currentUrl = (string) ($currentUrl ?? '');
$hasCustom = !empty($hasCustom);
$accept = (string) ($accept ?? '.jpg,.jpeg,.png,.gif,.webp');
$previewVariant = (string) ($previewVariant ?? 'logo');
$previewFrameClass = $previewVariant === 'favicon'
    ? 'branding-upload-preview branding-upload-preview--icon'
    : 'branding-upload-preview branding-upload-preview--logo';
?>
<div class="branding-upload-zone" data-branding-zone="<?php echo htmlspecialchars($zoneId); ?>">
    <div class="flex flex-col sm:flex-row gap-4">
        <div class="<?php echo htmlspecialchars($previewFrameClass); ?>" data-branding-preview-frame>
            <img
                src="<?php echo htmlspecialchars($currentUrl); ?>"
                alt="<?php echo htmlspecialchars($label); ?> preview"
                class="branding-upload-preview-img"
                data-branding-preview-img
            >
            <span class="branding-upload-preview-empty" data-branding-preview-empty <?php echo $currentUrl !== '' ? 'hidden' : ''; ?>>
                No image
            </span>
        </div>

        <div class="flex-1 min-w-0">
            <p class="text-sm font-medium text-gray-700"><?php echo htmlspecialchars($label); ?></p>
            <?php if ($hint !== ''): ?>
                <p class="text-xs text-gray-500 mt-0.5 mb-2"><?php echo htmlspecialchars($hint); ?></p>
            <?php endif; ?>

            <div
                class="branding-upload-drop border-2 border-dashed border-gray-300 rounded-xl px-4 py-6 text-center cursor-pointer transition-colors bg-gray-50 hover:bg-gray-100"
                data-branding-drop
                role="button"
                tabindex="0"
                aria-label="<?php echo htmlspecialchars($label); ?> upload drop zone"
            >
                <i class="material-icons text-3xl text-gray-400 mb-1" aria-hidden="true">cloud_upload</i>
                <p class="text-sm text-gray-700 font-medium">Drag and drop an image here</p>
                <p class="text-xs text-gray-500 mt-1">or click to browse</p>
                <p class="text-xs text-gray-400 mt-2" data-branding-file-meta>JPG, PNG, GIF, or WEBP</p>
            </div>

            <input
                type="file"
                id="<?php echo htmlspecialchars($zoneId); ?>_file"
                name="<?php echo htmlspecialchars($inputName); ?>"
                class="hidden"
                accept="<?php echo htmlspecialchars($accept); ?>"
                data-branding-file
            >
            <input type="hidden" name="<?php echo htmlspecialchars($removeFlagName); ?>" value="0" data-branding-remove-flag>

            <div class="flex flex-wrap items-center gap-2 mt-3">
                <button
                    type="button"
                    class="text-sm px-3 py-1.5 rounded-lg border border-gray-300 bg-white text-gray-700 hover:bg-gray-50"
                    data-branding-browse
                >
                    Choose file
                </button>
                <?php if ($hasCustom): ?>
                    <button
                        type="button"
                        class="text-sm px-3 py-1.5 rounded-lg border border-red-200 bg-red-50 text-red-700 hover:bg-red-100"
                        data-branding-remove
                    >
                        Remove custom image
                    </button>
                <?php else: ?>
                    <button
                        type="button"
                        class="text-sm px-3 py-1.5 rounded-lg border border-red-200 bg-red-50 text-red-700 hover:bg-red-100 hidden"
                        data-branding-remove
                    >
                        Remove custom image
                    </button>
                <?php endif; ?>
                <button
                    type="button"
                    class="text-sm px-3 py-1.5 rounded-lg border border-gray-200 text-gray-500 hover:bg-gray-50 hidden"
                    data-branding-clear-pending
                >
                    Clear selection
                </button>
            </div>
        </div>
    </div>
</div>
