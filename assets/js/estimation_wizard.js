$(document).ready(function () {
    function refreshLucide() {
        if (typeof window.refreshAppShellIcons === 'function') {
            window.refreshAppShellIcons();
        }
    }

    let currentStep = 1;
    const totalSteps = 8;
    const defaultPaperTypes = ['Cover', 'Original', 'Duplicate', 'Extra'];
    const defaultColours = ['C', 'M', 'Y', 'K', 'Varnish'];

    // =====================
    // DRAFT AUTO-SAVE CONFIGURATION
    // =====================
    const draftMode = window.draftMode || false;
    const draftEstId = window.draftEstId || null;
    const autoSaveInterval = 30000; // Auto-save every 30 seconds
    let autoSaveTimer = null;
    let lastAutoSaveTime = new Date();

    /**
     * Auto-save the current form data as a draft to the database
     */
    function autosaveDraft() {
        const formData = $('form#estimationForm').serializeArray();
        const payload = new FormData();

        // Add all form fields
        formData.forEach(field => {
            payload.append(field.name, field.value);
        });

        // Add draft tracking fields
        payload.append('est_id', draftEstId);
        payload.append('current_step', currentStep);
        payload.append('action', 'autosave');

        fetch('save_draft.php', {
            method: 'POST',
            body: payload
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // Update the draft ID if this was the first save
                if (!draftEstId && data.est_id) {
                    window.draftEstId = data.est_id;
                    $('#est_id').val(data.est_id);
                }
                lastAutoSaveTime = new Date();
                showAutoSaveNotification('Draft saved at ' + lastAutoSaveTime.toLocaleTimeString());
            } else {
                console.warn('Auto-save failed:', data.message);
            }
        })
        .catch(err => {
            console.error('Auto-save error:', err);
        });
    }

    /**
     * Display a temporary auto-save notification
     */
    function showAutoSaveNotification(message) {
        const existing = $('#auto-save-notification');
        if (existing.length) {
            existing.remove();
        }
        
        const notification = $(`
            <div id="auto-save-notification" class="fixed bottom-4 right-4 bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded-lg shadow-lg text-sm flex items-center gap-2" style="animation: fadeInOut 3s ease-in-out;">
                <i data-lucide="check-circle" class="h-4 w-4"></i>
                <span>${message}</span>
            </div>
        `);
        
        $('body').append(notification);
        if (typeof window.refreshAppShellIcons === 'function') {
            window.refreshAppShellIcons();
        }
        
        // Auto remove after 3 seconds
        setTimeout(() => notification.fadeOut(() => notification.remove()), 3000);
    }

    // =====================
    // CURRENCY FORMATTING
    // =====================
    /**
     * Format a number as Malawi Kwacha with comma separators
     * Examples: formatCurrency(0) => "0", formatCurrency(2498780.34) => "MK2,498,780"
     */
    function formatCurrency(value) {
        const num = parseFloat(value) || 0;
        // Format with comma separators
        const formatted = num.toLocaleString('en-US', {
            minimumFractionDigits: 0,
            maximumFractionDigits: 2
        });
        // Add currency prefix
        return 'MK' + formatted;
    }

    // Initialize
    showStep(currentStep);
    initPaperEntries();
    initInkColourRows();
    initMachineRows();

    if ($('#material-rows .material-row').length === 0) {
        addMaterialRow();
    }
    if ($('#binding-rows .binding-row').length === 0) {
        addBindingRow();
    }

    loadFormData();

    // Start auto-save timer if in draft mode
    if (draftMode) {
        autoSaveTimer = setInterval(autosaveDraft, autoSaveInterval);
        showAutoSaveNotification('Draft mode active - auto-saving enabled');
    }

    // Navigation
    $('.next-step').click(function () {
        if (validateStep(currentStep)) {
            // In draft mode, save to database before moving
            if (draftMode) {
                autosaveDraft();
            } else {
                saveFormData(); // Fall back to localStorage
            }
            currentStep++;
            showStep(currentStep);
        }
    });

    $('.prev-step').click(function () {
        if (draftMode) {
            autosaveDraft();
        } else {
            saveFormData();
        }
        currentStep--;
        showStep(currentStep);
    });

    $('form#estimationForm').on('change input', function () {
        calculateTotals();
    });

    // Clean up auto-save timer on page unload
    $(window).on('beforeunload', function() {
        if (autoSaveTimer) {
            clearInterval(autoSaveTimer);
        }
    });

    // Handle "Save as Draft" button (manual save)
    $(document).on('click', 'button[name="save_draft"]', function(e) {
        e.preventDefault();
        if (draftMode) {
            autosaveDraft();
            showAutoSaveNotification('Draft saved successfully!');
        } else {
            // For non-draft mode, create new draft via save_draft.php
            autosaveDraft();
            showAutoSaveNotification('Draft created and saved!');
        }
    });

    // Handle submission on final step
    $('form#estimationForm').on('submit', function(e) {
        // If this is a "Save as Draft" submission (not final), just save and don't submit
        if ($(e.target).find('button[name="save_draft"]:focus').length > 0) {
            e.preventDefault();
            return false;
        }
    });

    // =====================
    // STEP DISPLAY
    // =====================
    function showStep(step) {
        $('.step-content').addClass('hidden');
        $('#step-' + step).removeClass('hidden');

        $('.step-indicator div[id^="step-circle"]').removeClass('bg-green-600 text-white').addClass('bg-gray-300 text-gray-600');
        $('.step-indicator span[id^="step-label"]').removeClass('text-green-600 font-bold').addClass('text-gray-500 font-semibold');
        $('.step-indicator div[id^="step-line"]').removeClass('bg-green-600').addClass('bg-gray-300');

        for (let i = 1; i <= step; i++) {
            $('#step-circle-' + i).removeClass('bg-gray-300 text-gray-600').addClass('bg-green-600 text-white');
            $('#step-label-' + i).removeClass('text-gray-500 font-semibold').addClass('text-green-600 font-bold');
            if (i < step) $('#step-line-' + i).removeClass('bg-gray-300').addClass('bg-green-600');
        }

        if (step === 1) {
            $('.prev-step').addClass('hidden');
        } else {
            $('.prev-step').removeClass('hidden');
        }

        if (step === totalSteps) {
            $('.next-step').addClass('hidden');
            $('.submit-btn').removeClass('hidden');
            calculateTotals();
        } else {
            $('.next-step').removeClass('hidden');
            $('.submit-btn').addClass('hidden');
        }
    }

    function validateStep(step) {
        const inputs = $('#step-' + step).find('input[required], select[required]');
        let valid = true;
        inputs.each(function () {
            if (!this.checkValidity()) {
                this.reportValidity();
                valid = false;
                return false;
            }
        });
        return valid;
    }

    function saveFormData() {
        const formData = $('form#estimationForm').serializeArray();
        localStorage.setItem('estimation_draft_v4', JSON.stringify(formData));
    }

    function loadFormData() {
        let savedData = null;
        
        // In draft mode, load from the global draftData variable
        if (draftMode && window.draftData && Object.keys(window.draftData).length > 0) {
            savedData = window.draftData;
        } else {
            // Fall back to localStorage
            const savedDataStr = localStorage.getItem('estimation_draft_v4');
            if (savedDataStr) {
                try {
                    savedData = JSON.parse(savedDataStr);
                } catch (e) {
                    console.warn('Failed to parse saved draft data:', e);
                }
            }
        }

        if (savedData && typeof savedData === 'object') {
            // If savedData is already an object (from draftData), iterate differently
            if (Array.isArray(savedData)) {
                // It's an array from serializeArray()
                $.each(savedData, function (i, field) {
                    let input = $('[name="' + field.name + '"]');
                    if (input.length === 1) input.val(field.value);
                });
            } else {
                // It's an object from JSON.stringify(form data)
                $.each(savedData, function (key, value) {
                    if (Array.isArray(value)) {
                        // Handle array fields (like material_qty[])
                        $('[name="' + key + '[]"]').each(function (idx) {
                            $(this).val(value[idx] || '');
                        });
                    } else {
                        let input = $('[name="' + key + '"]');
                        if (input.length >= 1) {
                            input.val(value);
                        }
                    }
                });
            }
            calculateTotals();
        }
    }

    // =====================
    // STANDARD MATERIALS
    // =====================
    $(document).on('input', '.std-calc-qty, .std-calc-rate', function () {
        const card = $(this).closest('div.bg-white');
        const qty = parseFloat(card.find('.std-calc-qty').val()) || 0;
        const rate = parseFloat(card.find('.std-calc-rate').val()) || 0;
        card.find('.std-calc-total').val(formatCurrency(qty * rate));
        calculateTotals();
    });

    // =====================
    // DYNAMIC MATERIAL ROWS
    // =====================
    $('#add-material-row').click(function () { addMaterialRow(); });

    $(document).on('click', '.remove-row', function () {
        $(this).closest('tr').remove();
        calculateTotals();
    });

    $(document).on('change', '.material-select', function () {
        const row = $(this).closest('tr');
        const rate = $(this).find(':selected').data('rate') || 0;
        row.find('.material-rate').val(rate);
        calcMaterialRow(row);
    });

    $(document).on('input', '.material-qty, .material-rate', function () {
        calcMaterialRow($(this).closest('tr'));
    });

    function addMaterialRow() {
        const tmpl = document.getElementById('material-row-template');
        $('#material-rows').append(tmpl.content.cloneNode(true));
        refreshLucide();
    }

    function calcMaterialRow(row) {
        const qty = parseFloat(row.find('.material-qty').val()) || 0;
        const rate = parseFloat(row.find('.material-rate').val()) || 0;
        row.find('.material-total').val(formatCurrency(qty * rate));
        calculateTotals();
    }

    // =====================
    // PAPER ENTRIES
    // =====================

    function initPaperEntries() {
        defaultPaperTypes.forEach(function (type, idx) {
            addPaperEntry(type, idx === 0);
        });
    }

    function addPaperEntry(paperType, isFirst) {
        paperType = paperType || '';
        const canDelete = !isFirst;
        const deleteBtn = canDelete
            ? `<button type="button" class="remove-paper-btn text-red-500 hover:text-red-700 flex items-center gap-1 text-sm">
                <i data-lucide="trash-2" class="h-4 w-4"></i> Remove
               </button>`
            : '';

        const html = `
        <div class="paper-entry border border-gray-200 rounded-xl p-5 bg-gray-50">
            <div class="flex justify-between items-center mb-4">
                <h4 class="font-bold text-gray-700">Paper Entry</h4>
                ${deleteBtn}
            </div>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div>
                    <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">Paper Type / Label</label>
                    <input type="text" name="paper_type[]" value="${paperType}"
                        class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-green-500" placeholder="e.g. Cover">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">Size (mm)</label>
                    <input type="text" name="paper_size[]"
                        class="w-full px-3 py-2 border border-gray-300 rounded-lg" placeholder="e.g. 210x297">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">Grammage (gsm)</label>
                    <input type="number" name="paper_grammage[]"
                        class="w-full px-3 py-2 border border-gray-300 rounded-lg" placeholder="e.g. 80">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">Color</label>
                    <input type="text" name="paper_color[]"
                        class="w-full px-3 py-2 border border-gray-300 rounded-lg" placeholder="e.g. Full Color, B&W">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">No. of Sheets</label>
                    <input type="number" name="paper_sheets[]"
                        class="w-full px-3 py-2 border border-gray-300 rounded-lg paper-sheets" placeholder="0">
                    <p class="text-xs text-gray-400 mt-1">Include extras for damage</p>
                </div>
                <div>
                    <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">Price / Sheet (MK)</label>
                    <input type="number" step="0.01" name="paper_rate[]" value="25"
                        class="w-full px-3 py-2 border border-gray-300 rounded-lg paper-rate">
                </div>
                <div class="md:col-span-3">
                    <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">Total (MK)</label>
                    <input type="number" step="0.01" name="paper_total[]" readonly
                        class="w-full px-3 py-2 bg-white border border-gray-300 rounded-lg font-bold text-gray-700 paper-total">
                </div>
            </div>
        </div>`;
        console.log('Adding paper entry:', paperType);
        $('#paper-entries').append(html);
        refreshLucide();
    }

    $(document).on('click', '#add-paper-btn', function () {
        console.log('Add Paper Button clicked');
        addPaperEntry('', false);
    });

    $(document).on('click', '.remove-paper-btn', function () {
        $(this).closest('.paper-entry').remove();
        updatePaperTotal();
        calculateTotals();
    });

    $(document).on('input', '.paper-sheets, .paper-rate', function () {
        const entry = $(this).closest('.paper-entry');
        const sheets = parseFloat(entry.find('.paper-sheets').val()) || 0;
        const rate = parseFloat(entry.find('.paper-rate').val()) || 0;
        entry.find('.paper-total').val((sheets * rate).toFixed(2));
        updatePaperTotal();
        calculateTotals();
    });

    // Updates #cost_paper without calling calculateTotals (avoids recursion)
    function updatePaperTotal() {
        let total = 0;
        $('.paper-total').each(function () {
            total += parseFloat($(this).val()) || 0;
        });
        $('#cost_paper').val(formatCurrency(total));
    }

    function calcPaperTotals() {
        updatePaperTotal();
        calculateTotals();
    }

    // =====================
    // INK CALCULATION
    // =====================
    $(document).on('input', '.calc-ink-listen, input[name="ink_measure_base"], input[name="ink_height"], input[name="ink_pages"], input[name="ink_quantity_copies"]', calculateInk);

    function calculateInk() {
        let base = parseFloat($('input[name="ink_measure_base"]').val()) || 0;
        let height = parseFloat($('input[name="ink_height"]').val()) || 0;
        let pages = parseFloat($('input[name="ink_pages"]').val()) || 0;
        let qty = parseFloat($('input[name="ink_quantity_copies"]').val()) || 0;

        // Formula uses mm: divide by 1000 (not 100)
        let inkKgs = (base / 1000 * height / 1000) * pages * qty * 0.5 / 0.886 / 1000;
        $('#ink_kgs').val(inkKgs.toFixed(4));

        validateInkColours();
        // Don't call calcInkColourTotals here — that would loop back via calculateTotals
    }

    // =====================
    // INK COLOUR BREAKDOWN
    // =====================

    function initInkColourRows() {
        defaultColours.forEach(function (col) {
            addInkColourRow(col);
        });
    }

    function addInkColourRow(colourName) {
        colourName = colourName || '';
        const html = `
        <tr class="ink-colour-row">
            <td class="px-3 py-2">
                <input type="text" name="ink_colour[]" value="${colourName}"
                    class="w-full border-gray-300 rounded-lg ink-colour-name" placeholder="e.g. C, M, Y, K">
            </td>
            <td class="px-3 py-2">
                <input type="number" step="0.0001" name="ink_colour_kgs[]"
                    class="w-full border-gray-300 rounded-lg ink-colour-kgs" placeholder="0.0000">
            </td>
            <td class="px-3 py-2">
                <input type="number" step="0.01" name="ink_colour_rate[]" value="15000"
                    class="w-full border-gray-300 rounded-lg ink-colour-rate" placeholder="0.00">
            </td>
            <td class="px-3 py-2">
                <input type="text" name="ink_colour_total[]" readonly
                    class="w-full border-none bg-transparent ink-colour-total font-bold text-gray-700" value="0.00">
            </td>
            <td class="px-3 py-2">
                <button type="button" class="text-red-500 hover:text-red-700 remove-ink-colour-row">
                    <i data-lucide="trash-2" class="h-4 w-4"></i>
                </button>
            </td>
        </tr>`;
        $('#ink-colour-rows').append(html);
        refreshLucide();
    }

    $(document).on('click', '#add-ink-colour-btn', function () { addInkColourRow(''); });

    $(document).on('click', '.remove-ink-colour-row', function () {
        $(this).closest('tr').remove();
        calcInkColourTotals();
    });

    $(document).on('input', '.ink-colour-kgs, .ink-colour-rate', function () {
        const row = $(this).closest('tr');
        const kgs = parseFloat(row.find('.ink-colour-kgs').val()) || 0;
        const rate = parseFloat(row.find('.ink-colour-rate').val()) || 0;
        row.find('.ink-colour-total').val(formatCurrency(kgs * rate));
        calcInkColourTotals();
    });

    function calcInkColourTotals() {
        let totalCost = 0;
        $('.ink-colour-total').each(function () {
            totalCost += parseFloat($(this).val()) || 0;
        });
        $('#cost_ink').val(formatCurrency(totalCost));
        validateInkColours();
        calculateTotals();
    }

    function validateInkColours() {
        const inkKgs = parseFloat($('#ink_kgs').val()) || 0;
        let colourKgsSum = 0;
        $('.ink-colour-kgs').each(function () {
            colourKgsSum += parseFloat($(this).val()) || 0;
        });
        if (inkKgs > 0 && colourKgsSum > inkKgs + 0.0001) {
            $('#ink-colour-warning').removeClass('hidden');
        } else {
            $('#ink-colour-warning').addClass('hidden');
        }
    }

    // =====================
    // BINDING MATERIALS
    // =====================
    $(document).on('click', '#add-binding-row', function () { addBindingRow(); });

    function addBindingRow() {
        const tmpl = document.getElementById('binding-row-template');
        $('#binding-rows').append(tmpl.content.cloneNode(true));
        refreshLucide();
    }

    $(document).on('click', '.remove-binding-row', function () {
        $(this).closest('tr').remove();
        calcBindingTotals();
    });

    $(document).on('change', '.binding-mat-select', function () {
        const row = $(this).closest('tr');
        const rate = $(this).find(':selected').data('rate') || '';
        const unit = $(this).find(':selected').data('unit') || '';
        if (rate) row.find('.binding-mat-rate').val(rate);
        if (unit) row.find('.binding-mat-unit').val(unit);
        calcBindingRow(row);
    });

    $(document).on('input', '.binding-mat-qty, .binding-mat-rate', function () {
        calcBindingRow($(this).closest('tr'));
    });

    function calcBindingRow(row) {
        const qty = parseFloat(row.find('.binding-mat-qty').val()) || 0;
        const rate = parseFloat(row.find('.binding-mat-rate').val()) || 0;
        row.find('.binding-mat-total').val(formatCurrency(qty * rate));
        calcBindingTotals();
    }

    function calcBindingTotals() {
        let total = 0;
        $('.binding-mat-total').each(function () {
            total += parseFloat($(this).val().replace('MK', '').replace(/,/g, '')) || 0;
        });
        $('#cost_binding').val(formatCurrency(total));
        calculateTotals();
    }

    // =====================
    // PRE-PRESS LABOUR
    // =====================
    $(document).on('click', '#add-prepress-row', function () {
        const tmpl = document.getElementById('prepress-row-template');
        $('#prepress-rows').append(tmpl.content.cloneNode(true));
        refreshLucide();
    });

    $(document).on('click', '.remove-prepress-row', function () {
        $(this).closest('tr').remove();
        calcPrepressTotals();
    });

    $(document).on('input', '.prepress-hrs, .prepress-rate', function () {
        const row = $(this).closest('tr');
        const hrs = parseFloat(row.find('.prepress-hrs').val()) || 0;
        const rate = parseFloat(row.find('.prepress-rate').val()) || 0;
        row.find('.prepress-total').val(formatCurrency(hrs * rate));
        calcPrepressTotals();
    });

    function calcPrepressTotals() {
        let total = 0;
        $('.prepress-total').each(function () {
            total += parseFloat($(this).val().replace('MK', '').replace(/,/g, '')) || 0;
        });
        $('#cost_prepress').val(formatCurrency(total));
        updateLabourTotal();
        calculateTotals();
    }

    // =====================
    // PRESS MACHINES
    // =====================
    function initMachineRows() {
        addMachineBlock();
    }

    function addMachineBlock() {
        const idx = $('.machine-block').length + 1;
        const html = `
        <div class="machine-block border border-gray-100 rounded-lg p-4 bg-gray-50">
            <div class="flex justify-between items-center mb-3">
                <h4 class="font-semibold text-gray-700">Machine ${idx}</h4>
                <button type="button" class="remove-machine-btn text-red-500 hover:text-red-700 text-sm inline-flex items-center gap-1">
                    <i data-lucide="trash-2" class="h-4 w-4"></i>
                </button>
            </div>
            <div class="mb-3">
                <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">Machine Name</label>
                <input type="text" name="press_machine_name[]" class="w-full border-gray-300 rounded-lg press-machine-name" placeholder="e.g. Heidelberg GTO">
            </div>
            <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-2 bg-blue-50 p-3 rounded-lg">
                <div class="md:col-span-4 text-xs font-bold text-blue-700 uppercase mb-1">Make Ready</div>
                <div>
                    <label class="block text-xs text-gray-500 mb-1">No. of Colours</label>
                    <input type="number" name="press_colours[]" class="w-full border-gray-300 rounded-lg press-colours" placeholder="0">
                </div>
                <div>
                    <label class="block text-xs text-gray-500 mb-1">Hrs</label>
                    <input type="number" step="0.01" name="press_mr_hrs[]" class="w-full border-gray-300 rounded-lg press-mr-hrs" placeholder="0">
                </div>
                <div>
                    <label class="block text-xs text-gray-500 mb-1">Rate / hr (MK)</label>
                    <input type="number" step="0.01" name="press_mr_rate[]" class="w-full border-gray-300 rounded-lg press-mr-rate" placeholder="0.00">
                </div>
                <div>
                    <label class="block text-xs text-gray-500 mb-1">Total (MK)</label>
                    <input type="text" name="press_mr_total[]" readonly class="w-full border-none bg-white rounded-lg press-mr-total font-bold text-gray-700 px-2 py-2" value="0.00">
                </div>
            </div>
            <div class="grid grid-cols-2 md:grid-cols-5 gap-3 bg-green-50 p-3 rounded-lg">
                <div class="md:col-span-5 text-xs font-bold text-green-700 uppercase mb-1">Running</div>
                <div>
                    <label class="block text-xs text-gray-500 mb-1">Impressions</label>
                    <input type="number" name="press_impressions[]" class="w-full border-gray-300 rounded-lg press-impressions" placeholder="0">
                </div>
                <div>
                    <label class="block text-xs text-gray-500 mb-1">IPH</label>
                    <input type="number" name="press_iph[]" class="w-full border-gray-300 rounded-lg press-iph" placeholder="0">
                </div>
                <div>
                    <label class="block text-xs text-gray-500 mb-1">Hrs</label>
                    <input type="number" step="0.01" name="press_run_hrs[]" class="w-full border-gray-300 rounded-lg press-run-hrs" placeholder="0">
                </div>
                <div>
                    <label class="block text-xs text-gray-500 mb-1">Rate / hr (MK)</label>
                    <input type="number" step="0.01" name="press_run_rate[]" class="w-full border-gray-300 rounded-lg press-run-rate" placeholder="0.00">
                </div>
                <div>
                    <label class="block text-xs text-gray-500 mb-1">Total (MK)</label>
                    <input type="text" name="press_run_total[]" readonly class="w-full border-none bg-white rounded-lg press-run-total font-bold text-gray-700 px-2 py-2" value="0.00">
                </div>
            </div>
        </div>`;
        $('#press-machines').append(html);
        refreshLucide();
    }

    $(document).on('click', '#add-machine-btn', function () { addMachineBlock(); });

    $(document).on('click', '.remove-machine-btn', function () {
        $(this).closest('.machine-block').remove();
        calcPressTotals();
    });

    $(document).on('input', '.press-mr-hrs, .press-mr-rate', function () {
        const block = $(this).closest('.machine-block');
        const hrs = parseFloat(block.find('.press-mr-hrs').val()) || 0;
        const rate = parseFloat(block.find('.press-mr-rate').val()) || 0;
        block.find('.press-mr-total').val(formatCurrency(hrs * rate));
        calcPressTotals();
    });

    $(document).on('input', '.press-impressions, .press-iph, .press-run-hrs, .press-run-rate', function () {
        const block = $(this).closest('.machine-block');
        const impressions = parseFloat(block.find('.press-impressions').val()) || 0;
        const iph = parseFloat(block.find('.press-iph').val()) || 0;
        const runHrs = iph > 0 ? impressions / iph : (parseFloat(block.find('.press-run-hrs').val()) || 0);
        if (iph > 0) block.find('.press-run-hrs').val(runHrs.toFixed(2));
        const rate = parseFloat(block.find('.press-run-rate').val()) || 0;
        block.find('.press-run-total').val(formatCurrency(runHrs * rate));
        calcPressTotals();
    });

    function calcPressTotals() {
        let total = 0;
        $('.press-mr-total, .press-run-total').each(function () {
            total += parseFloat($(this).val().replace('MK', '').replace(/,/g, '')) || 0;
        });
        $('#cost_press').val(formatCurrency(total));
        updateLabourTotal();
        calculateTotals();
    }

    // =====================
    // FINISHING LABOUR
    // =====================
    $(document).on('click', '#add-finishing-row', function () {
        const tmpl = document.getElementById('finishing-row-template');
        $('#finishing-rows').append(tmpl.content.cloneNode(true));
        refreshLucide();
    });

    $(document).on('click', '.remove-finishing-row', function () {
        $(this).closest('tr').remove();
        calcFinishingTotals();
    });

    $(document).on('input', '.finishing-impressions, .finishing-iph, .finishing-hrs, .finishing-rate', function () {
        const row = $(this).closest('tr');
        const impressions = parseFloat(row.find('.finishing-impressions').val()) || 0;
        const iph = parseFloat(row.find('.finishing-iph').val()) || 0;
        const rate = parseFloat(row.find('.finishing-rate').val()) || 0;
        const hrs = iph > 0 ? impressions / iph : (parseFloat(row.find('.finishing-hrs').val()) || 0);
        if (iph > 0) {
            row.find('.finishing-hrs').val(hrs.toFixed(2));
        }
        row.find('.finishing-total').val(formatCurrency(hrs * rate));
        calcFinishingTotals();
    });

    function calcFinishingTotals() {
        let total = 0;
        $('.finishing-total').each(function () {
            total += parseFloat($(this).val().replace('MK', '').replace(/,/g, '')) || 0;
        });
        $('#cost_finishing').val(formatCurrency(total));
        updateLabourTotal();
        calculateTotals();
    }

    // Updates #cost_labour_total without calling calculateTotals (avoids recursion)
    function updateLabourTotal() {
        const prepress = parseFloat($('#cost_prepress').val().replace('MK', '').replace(/,/g, '')) || 0;
        const press = parseFloat($('#cost_press').val().replace('MK', '').replace(/,/g, '')) || 0;
        const finishing = parseFloat($('#cost_finishing').val().replace('MK', '').replace(/,/g, '')) || 0;
        $('#cost_labour_total').val(formatCurrency(prepress + press + finishing));
    }

    function calcLabourTotal() {
        updateLabourTotal();
        calculateTotals();
    }

    // =====================
    // FINAL TOTALS
    // =====================
    $('.calc-final').on('input', calculateTotals);

    function calculateTotals() {
        // Paper — read directly, don't call calcPaperTotals (avoids recursion)
        updatePaperTotal();
        // Ink — update ink_kgs from formula, then read cost_ink from colour rows (no loop)
        calculateInk();

        // Materials subtotal (standard cards + dynamic rows)
        let matSubtotal = 0;
        $('.material-total').each(function () { matSubtotal += parseFloat($(this).val()) || 0; });
        $('.std-calc-total').each(function () { matSubtotal += parseFloat($(this).val()) || 0; });

        // All cost fields - parse formatted currency values
        const paper = parseFloat($('#cost_paper').val().replace('MK', '').replace(/,/g, '')) || 0;
        const ink = parseFloat($('#cost_ink').val().replace('MK', '').replace(/,/g, '')) || 0;
        const binding = parseFloat($('#cost_binding').val().replace('MK', '').replace(/,/g, '')) || 0;
        const labour = parseFloat($('#cost_labour_total').val().replace('MK', '').replace(/,/g, '')) || 0;
        const consumables = parseFloat($('input[name="cost_consumables"]').val()) || 0;
        const extraLabour = parseFloat($('input[name="cost_labour"]').val()) || 0;

        const subtotal = matSubtotal + paper + ink + binding + labour + consumables;
        $('input[name="subtotal"]').val(formatCurrency(subtotal));

        const profitPercent = parseFloat($('input[name="profit_margin"]').val()) || 0;
        const baseCost = subtotal + extraLabour;
        const profitAmount = baseCost * (profitPercent / 100);
        const taxableAmount = baseCost + profitAmount;

        const vatPercent = parseFloat($('input[name="vat_percent"]').val()) || 0;
        const vatAmount = taxableAmount * (vatPercent / 100);

        $('input[name="grand_total"]').val(formatCurrency(taxableAmount + vatAmount));
    }

    // =====================
    // QUICK ADD MODALS
    // =====================
    $('#quickAddForm').submit(function (e) {
        e.preventDefault();
        $.ajax({
            url: '../materials/save',
            method: 'POST',
            data: $(this).serialize(),
            dataType: 'json',
            success: function (response) {
                if (response.status === 'success') {
                    const newOpt = `<option value="${response.material_id}" data-rate="${response.rate}">${response.name}</option>`;
                    $('.material-select').append(newOpt);
                    const tmpl = document.getElementById('material-row-template');
                    const sel = tmpl.content.querySelector('.material-select');
                    const opt = document.createElement('option');
                    opt.value = response.material_id;
                    opt.text = response.name;
                    opt.setAttribute('data-rate', response.rate);
                    sel.add(opt);
                    alert('Material added!');
                    closeQuickAddModal();
                    $('#quickAddForm')[0].reset();
                } else {
                    alert('Error: ' + response.message);
                }
            },
            error: function () { alert('Connection error. Please try again.'); }
        });
    });

    $('#bindingAddForm').submit(function (e) {
        e.preventDefault();
        $.ajax({
            url: '../materials/save',
            method: 'POST',
            data: $(this).serialize(),
            dataType: 'json',
            success: function (response) {
                if (response.status === 'success') {
                    const newOpt = `<option value="${response.material_id}" data-rate="${response.rate}" data-unit="">${response.name}</option>`;
                    $('.binding-mat-select').append(newOpt);
                    const tmpl = document.getElementById('binding-row-template');
                    const sel = tmpl.content.querySelector('.binding-mat-select');
                    const opt = document.createElement('option');
                    opt.value = response.material_id;
                    opt.text = response.name;
                    opt.setAttribute('data-rate', response.rate);
                    sel.add(opt);
                    alert('Binding material added!');
                    closeBindingAddModal();
                    $('#bindingAddForm')[0].reset();
                } else {
                    alert('Error: ' + response.message);
                }
            },
            error: function () { alert('Connection error. Please try again.'); }
        });
    });
    refreshLucide();
});

// Global modal functions
function openQuickAddModal() { $('#quickAddModal').removeClass('hidden'); }
function closeQuickAddModal() { $('#quickAddModal').addClass('hidden'); }
function openBindingAddModal() { $('#bindingAddModal').removeClass('hidden'); }
function closeBindingAddModal() { $('#bindingAddModal').addClass('hidden'); }
