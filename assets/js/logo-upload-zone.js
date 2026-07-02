/**
 * Drag-and-drop image upload zones with live preview (system branding settings).
 */
(function () {
    function preventDefaults(e) {
        e.preventDefault();
        e.stopPropagation();
    }

    function formatSize(bytes) {
        if (bytes < 1024) {
            return bytes + ' B';
        }
        if (bytes < 1024 * 1024) {
            return (bytes / 1024).toFixed(1) + ' KB';
        }
        return (bytes / (1024 * 1024)).toFixed(2) + ' MB';
    }

    function initZone(zone) {
        const drop = zone.querySelector('[data-branding-drop]');
        const fileInput = zone.querySelector('[data-branding-file]');
        const previewImg = zone.querySelector('[data-branding-preview-img]');
        const previewEmpty = zone.querySelector('[data-branding-preview-empty]');
        const removeFlag = zone.querySelector('[data-branding-remove-flag]');
        const removeBtn = zone.querySelector('[data-branding-remove]');
        const browseBtn = zone.querySelector('[data-branding-browse]');
        const clearPendingBtn = zone.querySelector('[data-branding-clear-pending]');
        const fileMeta = zone.querySelector('[data-branding-file-meta]');

        if (!drop || !fileInput || !previewImg) {
            return;
        }

        const defaultSrc = previewImg.getAttribute('src') || '';
        let pendingObjectUrl = null;

        function revokePendingUrl() {
            if (pendingObjectUrl) {
                URL.revokeObjectURL(pendingObjectUrl);
                pendingObjectUrl = null;
            }
        }

        function setPreviewSrc(src) {
            if (!src) {
                previewImg.classList.add('hidden');
                previewEmpty?.classList.remove('hidden');
                return;
            }
            previewImg.src = src;
            previewImg.classList.remove('hidden');
            previewEmpty?.classList.add('hidden');
        }

        function setRemoveFlag(active) {
            if (removeFlag) {
                removeFlag.value = active ? '1' : '0';
            }
        }

        function showPendingControls(show) {
            if (clearPendingBtn) {
                clearPendingBtn.classList.toggle('hidden', !show);
            }
        }

        function applyFile(file) {
            if (!file || !file.type.startsWith('image/')) {
                return;
            }

            const dt = new DataTransfer();
            dt.items.add(file);
            fileInput.files = dt.files;

            revokePendingUrl();
            pendingObjectUrl = URL.createObjectURL(file);
            setPreviewSrc(pendingObjectUrl);
            setRemoveFlag(false);
            showPendingControls(true);

            if (fileMeta) {
                fileMeta.textContent = 'Selected: ' + file.name + ' (' + formatSize(file.size) + ') — save settings to apply';
            }
        }

        function clearPendingSelection() {
            fileInput.value = '';
            revokePendingUrl();
            setPreviewSrc(defaultSrc);
            showPendingControls(false);
            if (fileMeta) {
                fileMeta.textContent = 'JPG, PNG, GIF, or WEBP';
            }
        }

        ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(function (eventName) {
            drop.addEventListener(eventName, preventDefaults, false);
        });

        ['dragenter', 'dragover'].forEach(function (eventName) {
            drop.addEventListener(eventName, function () {
                drop.classList.add('border-blue-500', 'bg-blue-50');
            }, false);
        });

        ['dragleave', 'drop'].forEach(function (eventName) {
            drop.addEventListener(eventName, function () {
                drop.classList.remove('border-blue-500', 'bg-blue-50');
            }, false);
        });

        drop.addEventListener('drop', function (e) {
            const files = e.dataTransfer?.files;
            if (files && files.length > 0) {
                applyFile(files[0]);
            }
        }, false);

        drop.addEventListener('click', function () {
            fileInput.click();
        });

        drop.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                fileInput.click();
            }
        });

        browseBtn?.addEventListener('click', function (e) {
            e.preventDefault();
            fileInput.click();
        });

        fileInput.addEventListener('change', function () {
            if (fileInput.files && fileInput.files[0]) {
                applyFile(fileInput.files[0]);
            }
        });

        clearPendingBtn?.addEventListener('click', function (e) {
            e.preventDefault();
            clearPendingSelection();
        });

        removeBtn?.addEventListener('click', function (e) {
            e.preventDefault();
            clearPendingSelection();
            setRemoveFlag(true);
            removeBtn.classList.add('hidden');
            if (fileMeta) {
                fileMeta.textContent = 'Custom image will be removed when you save — default restored';
            }
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('[data-branding-zone]').forEach(initZone);
    });
})();
