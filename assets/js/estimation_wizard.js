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
    // Ink mode constants must live above init/restore — used by applySnapshotToForm.
    const INK_MODE_FORMULA = 'formula';
    const INK_MODE_FORMULA_BREAKDOWN = 'formula_breakdown';
    const INK_MODE_BREAKDOWN = 'breakdown';
    const DEFAULT_INK_COLOUR_PCT = { C: 25, M: 25, Y: 25, K: 25, Varnish: 0 };

    // =====================
    // DRAFT AUTO-SAVE CONFIGURATION
    // =====================
    const wizardConfig = window.estimationWizardConfig || {};
    const draftMode = wizardConfig.draftMode || window.draftMode || false;
    const freshStart = wizardConfig.freshStart === true;
    const autoSaveInterval = 15000;
    let autoSaveTimer = null;
    let lastAutoSaveTime = null;
    let localSaveTimer = null;
    let serverSaveTimer = null;
    let fieldAutosaveTimer = null;
    let pendingServerSync = false;
    let syncAfterRestore = false;
    let exitFlushDone = false;
    let localBaseRevision = parseInt(wizardConfig.serverDraftRevision, 10) || 0;
    let lastSyncedContentHash = wizardConfig.serverDraftContentHash || null;
    let lastSyncedFormFingerprint = null;
    let conflictPayload = null;
    let conflictModalOpen = false;
    let logoutFlushInProgress = false;
    let autosaveInFlight = null;
    const DEFAULT_PREPRESS_ROWS = 1;
    const DEFAULT_FINISHING_ROWS = 1;
    const WIZARD_STEP_LABELS = {
        1: 'Client & Job',
        2: 'Materials',
        3: 'Paper',
        4: 'Ink',
        5: 'Binding',
        6: 'Labour',
        7: 'Consumables',
        8: 'Totals',
    };
    const endpoints = wizardConfig.endpoints || {
        saveDraft: 'save_draft',
        discardDraft: 'discard_draft',
        sessionPing: (wizardConfig.baseUrl || '') + 'modules/auth/session_ping',
        reauth: (wizardConfig.baseUrl || '') + 'modules/auth/reauth',
    };
    const materialSearchUrl = endpoints.materialSearch || '../materials/search.php';
    const materialSaveUrl = endpoints.materialSave || '../materials/save.php';
    const stdMaterialSlots = wizardConfig.stdMaterialSlots || [];
    const INK_COLOR_MAP = { C: 'Cyan', M: 'Magenta', Y: 'Yellow', K: 'Black', Varnish: 'Varnish' };
    let paperQuickAddTargetEntry = null;
    let bindingQuickAddTargetRow = null;

    function materialApiRequest(params) {
        return $.getJSON(materialSearchUrl, params || {});
    }

    function materialFetchDistinct(field, filters) {
        return materialApiRequest(Object.assign({ action: 'distinct', field: field }, filters || {}));
    }

    function materialFetchMatch(filters) {
        return materialApiRequest(Object.assign({ action: 'match' }, filters || {}));
    }

    function materialFetchSearch(filters) {
        return materialApiRequest(filters || {});
    }

    function populateSelectOptions($select, values, placeholder, selectedValue) {
        const current = selectedValue != null ? String(selectedValue) : String($select.val() || '');
        $select.empty();
        $select.append($('<option>', { value: '', text: placeholder || 'Select…' }));
        (values || []).forEach(function (val) {
            const text = val == null ? '' : String(val);
            if (text === '') {
                return;
            }
            $select.append($('<option>', { value: text, text: text }));
        });
        if (current && $select.find('option[value="' + current.replace(/"/g, '\\"') + '"]').length) {
            $select.val(current);
        }
    }

    function initStdMaterialCards() {
        $('.std-material-card').each(function () {
            const card = $(this);
            const kind = card.data('material-kind');
            const stockType = card.data('stock-type') || '';
            const filters = { material_kind: kind };
            if (stockType) {
                filters.stock_type = stockType;
            }
            materialFetchDistinct('dimensions', filters).done(function (resp) {
                if (resp.status !== 'success') {
                    return;
                }
                populateSelectOptions(card.find('.std-mat-dimensions'), resp.values, 'Select size…');
            });
        });
    }

    function resolveStdMaterialCard(card) {
        const kind = card.data('material-kind');
        const stockType = card.data('stock-type') || '';
        const dimensions = card.find('.std-mat-dimensions').val();
        const filters = { material_kind: kind };
        if (stockType) {
            filters.stock_type = stockType;
        }
        if (dimensions) {
            filters.dimensions = dimensions;
        }
        materialFetchMatch(filters).done(function (resp) {
            const match = resp.match;
            if (!match) {
                card.find('.std-mat-id').val('');
                card.find('.std-mat-selected-name').text('No catalog match — enter rate manually.');
                return;
            }
            card.find('.std-mat-id').val(match.id);
            card.find('.std-mat-selected-name').text(match.name);
            card.find('.std-calc-rate').val(match.rate || 0);
            const qty = parseFloat(card.find('.std-calc-qty').val()) || 0;
            card.find('.std-calc-total').val(formatCurrency(qty * (parseFloat(match.rate) || 0)));
            calculateTotals();
        });
    }

    function ensureSelectOption($select, value) {
        if (!value || !$select.length) {
            return;
        }
        const strVal = String(value);
        if (!$select.find('option').filter(function () { return String($(this).val()) === strVal; }).length) {
            $select.append($('<option>', { value: strVal, text: strVal }));
        }
        $select.val(strVal);
    }

    function setPaperMatchLabel(entry, message, matched) {
        const label = entry.find('.paper-match-label');
        if (!message) {
            label.empty();
            return;
        }
        const cls = matched
            ? 'inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-medium bg-green-100 text-green-800 border border-green-200'
            : 'inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-medium bg-amber-50 text-amber-800 border border-amber-200';
        label.html('<span class="' + cls + '">' + (matched ? '✓ ' : '! ') + message + '</span>');
    }

    function updatePaperEntryTotal(entry) {
        const sheets = parseFloat(entry.find('.paper-sheets').val()) || 0;
        const rate = parseFloat(entry.find('.paper-rate').val()) || 0;
        entry.find('.paper-total').val(formatCurrency(sheets * rate));
        updatePaperTotal();
        calculateTotals();
    }

    function refreshAllPaperStockTypeOptions() {
        return materialFetchDistinct('stock_type', { category: 'Printing Papers' }).done(function (resp) {
            if (resp.status !== 'success') {
                return;
            }
            $('.paper-stock-type').each(function () {
                populateSelectOptions($(this), resp.values || [], 'Select stock type…', $(this).val());
            });
        });
    }

    function applyPaperMaterialToEntry(entry, material) {
        if (!entry || !entry.length || !material) {
            return;
        }
        if (material.stock_type) {
            ensureSelectOption(entry.find('.paper-stock-type'), material.stock_type);
            entry.find('.paper-stock-type-hidden').val(material.stock_type);
        }
        if (material.color) {
            ensureSelectOption(entry.find('.paper-color-select'), material.color);
            entry.find('.paper-color-hidden').val(material.color);
        }
        if (material.grammage != null && material.grammage !== '') {
            const gsm = String(material.grammage);
            ensureSelectOption(entry.find('.paper-grammage-select'), gsm);
            entry.find('.paper-grammage-hidden').val(gsm);
        }
        if (material.dimensions) {
            ensureSelectOption(entry.find('.paper-dimensions-select'), material.dimensions);
            entry.find('.paper-size-hidden').val(material.dimensions);
        }
        entry.find('.paper-material-id').val(material.material_id || material.id || '');
        entry.find('.paper-rate').val(material.rate || 0);
        setPaperMatchLabel(entry, material.name || 'Catalog match', true);
        updatePaperEntryTotal(entry);
    }

    function paperSelectField(label, selectClass, hiddenName, hiddenClass, fieldKey, placeholder) {
        return `
            <div class="paper-spec-field" data-spec-field="${fieldKey}">
                <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1.5">${label}</label>
                <div class="flex items-stretch gap-2">
                    <select class="${selectClass} flex-1 min-w-0 px-3 py-2.5 border border-gray-300 rounded-lg bg-white text-sm focus:outline-none focus:border-green-500 focus:ring-2 focus:ring-green-100">
                        <option value="">${placeholder}</option>
                    </select>
                    <button type="button"
                        class="paper-spec-quick-add inline-flex items-center justify-center w-10 shrink-0 rounded-lg border border-green-200 bg-green-50 text-green-700 hover:bg-green-100 hover:border-green-300 transition"
                        data-spec-field="${fieldKey}" title="Add to catalog">
                        <i data-lucide="plus" class="h-4 w-4" aria-hidden="true"></i>
                    </button>
                </div>
                <input type="hidden" name="${hiddenName}" class="${hiddenClass}" value="">
            </div>`;
    }

    function buildPaperCatalogName(stockType, color, grammage, dimensions) {
        const parts = [];
        if (grammage) {
            parts.push(String(grammage).replace(/\.?0+$/, '') + 'gsm');
        }
        if (color) {
            parts.push(color);
        }
        if (dimensions) {
            parts.push(dimensions);
        }
        if (stockType) {
            parts.push(stockType);
        }
        return parts.join(' ').trim();
    }

    function updatePaperAddNamePreview() {
        const name = buildPaperCatalogName(
            $('#paper_add_stock_type').val(),
            $('#paper_add_color').val(),
            $('#paper_add_grammage').val(),
            $('#paper_add_dimensions').val()
        );
        $('#paper_add_name_preview').text(name || '—');
        $('#paper_add_generated_name').val(name);
    }

    function resolvePaperEntry(entry) {
        const stockType = entry.find('.paper-stock-type').val();
        const color = entry.find('.paper-color-select').val();
        const grammage = entry.find('.paper-grammage-select').val();
        const dimensions = entry.find('.paper-dimensions-select').val();
        const filters = { category: 'Printing Papers' };
        if (stockType) {
            filters.stock_type = stockType;
        }
        if (color) {
            filters.color = color;
        }
        if (grammage) {
            filters.grammage = grammage;
        }
        if (dimensions) {
            filters.dimensions = dimensions;
        }
        materialFetchMatch(filters).done(function (resp) {
            const match = resp.match;
            if (!match) {
                entry.find('.paper-material-id').val('');
                setPaperMatchLabel(entry, 'No catalog match — enter rate manually', false);
                return;
            }
            entry.find('.paper-material-id').val(match.id);
            setPaperMatchLabel(entry, match.name, true);
            entry.find('.paper-rate').val(match.rate || 0);
            if (match.grammage) {
                ensureSelectOption(entry.find('.paper-grammage-select'), String(match.grammage));
                entry.find('.paper-grammage-hidden').val(String(match.grammage));
            }
            if (match.color) {
                ensureSelectOption(entry.find('.paper-color-select'), match.color);
                entry.find('.paper-color-hidden').val(match.color);
            }
            if (match.dimensions) {
                ensureSelectOption(entry.find('.paper-dimensions-select'), match.dimensions);
                entry.find('.paper-size-hidden').val(match.dimensions);
            }
            updatePaperEntryTotal(entry);
        });
    }

    function refreshPaperSpecSelects(entry, changedField) {
        const stockType = entry.find('.paper-stock-type').val() || entry.find('.paper-stock-type-hidden').val();
        const color = entry.find('.paper-color-select').val() || entry.find('.paper-color-hidden').val();
        const grammage = entry.find('.paper-grammage-select').val() || entry.find('.paper-grammage-hidden').val();
        const dimensions = entry.find('.paper-dimensions-select').val() || entry.find('.paper-size-hidden').val();
        const baseFilters = { category: 'Printing Papers' };
        if (stockType) {
            baseFilters.stock_type = stockType;
        }

        const tasks = [];
        if (changedField === 'stock_type' || !changedField) {
            tasks.push(materialFetchDistinct('color', baseFilters).then(function (resp) {
                populateSelectOptions(entry.find('.paper-color-select'), resp.values || [], 'Select colour…', color);
                if (color) {
                    entry.find('.paper-color-hidden').val(color);
                }
            }));
        }
        const colorFilters = Object.assign({}, baseFilters);
        const activeColor = entry.find('.paper-color-select').val() || color;
        if (activeColor) {
            colorFilters.color = activeColor;
        }
        if (changedField === 'stock_type' || changedField === 'color' || !changedField) {
            tasks.push(materialFetchDistinct('grammage', colorFilters).then(function (resp) {
                populateSelectOptions(entry.find('.paper-grammage-select'), resp.values || [], 'Select gsm…', grammage);
                if (grammage) {
                    entry.find('.paper-grammage-hidden').val(grammage);
                }
            }));
        }
        const gramFilters = Object.assign({}, colorFilters);
        const activeGram = entry.find('.paper-grammage-select').val() || grammage;
        if (activeGram) {
            gramFilters.grammage = activeGram;
        }
        tasks.push(materialFetchDistinct('dimensions', gramFilters).then(function (resp) {
            populateSelectOptions(entry.find('.paper-dimensions-select'), resp.values || [], 'Size (optional)…', dimensions);
            if (dimensions) {
                entry.find('.paper-size-hidden').val(dimensions);
            }
        }));

        $.when.apply($, tasks).always(function () {
            resolvePaperEntry(entry);
        });
    }

    function lookupInkRateForColour(colourName, callback) {
        const mapped = INK_COLOR_MAP[colourName] || colourName;
        const filters = { category: 'Printing Inks', color: mapped };
        const brand = $('#ink-brand-filter').val();
        if (brand) {
            filters.brand = brand;
        }
        materialFetchMatch(filters).done(function (resp) {
            callback(resp.match || null);
        });
    }

    function initInkBrandFilter() {
        materialFetchDistinct('brand', { category: 'Printing Inks' }).done(function (resp) {
            populateSelectOptions($('#ink-brand-filter'), resp.values || [], 'All brands / types');
        });
    }

    $(document).on('change', '#ink-brand-filter', function () {
        $('.ink-colour-row').each(function () {
            const row = $(this);
            lookupInkRateForColour(row.find('.ink-colour-name').val(), function (match) {
                if (match) {
                    row.find('.ink-material-id').val(match.id);
                    row.find('.ink-colour-rate').val(match.rate || 0);
                }
            });
        });
        refreshInkCosts(true);
    });

    function applyBindingFilters(row) {
        const stockFilter = row.find('.binding-filter-stock').val();
        const colorFilter = row.find('.binding-filter-color').val();
        row.find('.binding-mat-select option').each(function () {
            const opt = $(this);
            if (!opt.val()) {
                return;
            }
            const stock = String(opt.data('stock-type') || '');
            const color = String(opt.data('color') || '');
            let visible = true;
            if (stockFilter && stock.toLowerCase() !== String(stockFilter).toLowerCase()) {
                visible = false;
            }
            if (colorFilter && color.toLowerCase() !== String(colorFilter).toLowerCase()) {
                visible = false;
            }
            opt.prop('hidden', !visible);
        });
    }

    function initBindingFilterSelects(row) {
        materialFetchDistinct('stock_type', { category: 'Binding Materials' }).done(function (resp) {
            populateSelectOptions(row.find('.binding-filter-stock'), resp.values || [], 'All types');
        });
        materialFetchDistinct('color', { category: 'Binding Materials' }).done(function (resp) {
            populateSelectOptions(row.find('.binding-filter-color'), resp.values || [], 'All colours');
        });
    }

    function addConsumableRow() {
        const html = `
        <tr class="consumable-row">
            <td class="px-3 py-2">
                <select class="consumable-stock-type w-full border-gray-300 rounded-lg text-sm">
                    <option value="">All types</option>
                </select>
            </td>
            <td class="px-3 py-2">
                <select name="consumable_mat_id[]" class="consumable-mat-select w-full border-gray-300 rounded-lg">
                    <option value="">Select consumable…</option>
                </select>
            </td>
            <td class="px-3 py-2">
                <input type="text" name="consumable_mat_unit[]" readonly class="consumable-mat-unit w-full border-gray-300 rounded-lg bg-gray-50">
            </td>
            <td class="px-3 py-2">
                <input type="number" step="0.01" name="consumable_mat_qty[]" class="consumable-mat-qty w-full border-gray-300 rounded-lg" placeholder="0">
            </td>
            <td class="px-3 py-2">
                <input type="number" step="0.01" name="consumable_mat_rate[]" class="consumable-mat-rate w-full border-gray-300 rounded-lg" placeholder="0.00">
            </td>
            <td class="px-3 py-2">
                <input type="text" name="consumable_mat_total[]" readonly class="consumable-mat-total w-full border-none bg-transparent font-bold text-gray-700" value="0.00">
            </td>
            <td class="px-3 py-2 text-right">
                <button type="button" class="text-red-500 hover:text-red-700 remove-consumable-row">
                    <i data-lucide="trash-2" class="h-4 w-4"></i>
                </button>
            </td>
        </tr>`;
        $('#consumable-rows').append(html);
        const row = $('#consumable-rows .consumable-row').last();
        refreshConsumableRowOptions(row);
        refreshLucide();
    }

    function refreshConsumableRowOptions(row) {
        const stockType = row.find('.consumable-stock-type').val();
        const filters = { category: 'Printing Consumables' };
        if (stockType) {
            filters.stock_type = stockType;
        }
        materialFetchSearch(filters).done(function (resp) {
            const select = row.find('.consumable-mat-select');
            const current = select.val();
            select.empty().append($('<option>', { value: '', text: 'Select consumable…' }));
            (resp.materials || []).forEach(function (mat) {
                select.append($('<option>', {
                    value: mat.id,
                    text: mat.name + ' (' + mat.unit + ')',
                }).attr('data-rate', mat.rate).attr('data-unit', mat.unit));
            });
            if (current) {
                select.val(current);
            }
        });
        materialFetchDistinct('stock_type', { category: 'Printing Consumables' }).done(function (resp) {
            populateSelectOptions(row.find('.consumable-stock-type'), resp.values || [], 'All types', stockType);
        });
    }

    function updateConsumablesTotal() {
        let total = parseFloat($('#cost_consumables_misc').val()) || 0;
        $('.consumable-mat-total').each(function () {
            total += parseInkMoney($(this).val());
        });
        $('#cost_consumables').val(formatCurrency(total));
        calculateTotals();
    }

    function getDraftEstId() {
        const fromWindow = window.draftEstId || wizardConfig.draftEstId;
        const fromInput = $('#est_id').val();
        return fromWindow || fromInput || null;
    }

    function getDraftStorageKey(estId) {
        const userId = wizardConfig.userId || 'anonymous';
        const id = estId !== undefined ? estId : getDraftEstId();
        if (id) {
            return 'estimation_draft:' + userId + ':' + id;
        }
        if (freshStart) {
            return 'estimation_draft:' + userId + ':session';
        }
        if (estId === null) {
            return 'estimation_draft:' + userId + ':active';
        }
        return 'estimation_draft:' + userId + ':active';
    }

    function shouldSetDraftPointer() {
        return !!getDraftEstId() || !freshStart;
    }

    function persistToLocalStore(key, snapshot, pendingSync) {
        const estId = getDraftEstId();
        if (freshStart && !estId) {
            return FormDraftStore.saveSession(key, snapshot).then(function () {
                if (shouldSetDraftPointer()) {
                    FormDraftStore.setPointer({
                        estId: snapshot.meta.estId,
                        step: snapshot.meta.step,
                        updatedAt: snapshot.meta.updatedAt,
                        userId: wizardConfig.userId,
                    });
                }
                updateLocalDraftStatus(pendingSync);
            });
        }
        const saves = [FormDraftStore.save(key, snapshot)];
        if (estId) {
            saves.push(FormDraftStore.save(getDraftStorageKey(estId), snapshot));
        }
        return Promise.all(saves).then(function () {
            if (shouldSetDraftPointer()) {
                FormDraftStore.setPointer({
                    estId: snapshot.meta.estId,
                    step: snapshot.meta.step,
                    updatedAt: snapshot.meta.updatedAt,
                    userId: wizardConfig.userId,
                });
            }
            updateLocalDraftStatus(pendingSync);
        });
    }

    function updateLocalDraftStatus(pendingSync) {
        if (pendingSync || !navigator.onLine) {
            updateDraftStatus('Saved on this device · waiting to sync', 'warn');
        } else if (localBaseRevision > 0) {
            updateDraftStatus('Synced · rev ' + localBaseRevision, 'ok');
        } else {
            updateDraftStatus('Saved on this device', 'ok');
        }
    }

    function getDraftStorageKeys() {
        const userId = wizardConfig.userId || 'anonymous';
        const estId = getDraftEstId();
        if (estId && (draftMode || wizardConfig.draftEstId)) {
            return [getDraftStorageKey(estId)];
        }
        if (freshStart && !estId) {
            return [getDraftStorageKey()];
        }
        const keys = [
            getDraftStorageKey(null),
            'estimation_draft:' + userId,
        ];
        if (estId) {
            keys.unshift(getDraftStorageKey(estId));
        }
        return keys.filter(function (k, i, arr) { return arr.indexOf(k) === i; });
    }

    function clearAllDraftStorage() {
        if (!window.FormDraftStore || !wizardConfig.userId) {
            return Promise.resolve();
        }
        const removals = getDraftStorageKeys().map(function (key) {
            if (freshStart && !getDraftEstId() && key.indexOf(':session') !== -1) {
                return FormDraftStore.removeSession(key);
            }
            return FormDraftStore.remove(key);
        });
        if (shouldSetDraftPointer()) {
            FormDraftStore.clearPointer();
        }
        return Promise.all(removals);
    }

    function setDraftEstId(id) {
        const previousId = window.draftEstId || wizardConfig.draftEstId || $('#est_id').val() || null;
        window.draftEstId = id;
        wizardConfig.draftEstId = id;
        if (id) {
            $('#est_id').val(id);
        }
        if (id && window.FormDraftStore && String(previousId) !== String(id)) {
            const snapshot = buildSnapshot(pendingServerSync);
            FormDraftStore.saveSync(getDraftStorageKey(id), snapshot);
            if (freshStart && previousId == null) {
                FormDraftStore.removeSession(getDraftStorageKey()).catch(function () { /* best-effort */ });
            }
        }
    }

    function updateDraftStatus(message, tone) {
        const el = document.getElementById('estimation-draft-status');
        if (!el) {
            return;
        }
        el.textContent = message || '';
        el.classList.remove('hidden', 'text-gray-600', 'text-amber-700', 'text-red-700', 'text-green-700');
        if (!message) {
            el.classList.add('hidden');
            return;
        }
        el.classList.add(tone === 'warn' ? 'text-amber-700' : tone === 'error' ? 'text-red-700' : tone === 'ok' ? 'text-green-700' : 'text-gray-600');
    }

    function captureStructure() {
        return {
            paperTypes: $('input[name="paper_type[]"]').map(function () { return $(this).val(); }).get(),
            inkColours: $('input[name="ink_colour[]"]').map(function () { return $(this).val(); }).get(),
            bindingRowCount: $('#binding-rows .binding-row').length,
            consumableRowCount: $('#consumable-rows .consumable-row').length,
            machineBlockCount: $('.machine-block').length,
            prepressRowCount: $('#prepress-rows .prepress-row').length,
            finishingRowCount: $('#finishing-rows .finishing-row').length,
        };
    }

    function captureFields() {
        const formData = $('form#estimationForm').serializeArray();
        const fields = {};
        formData.forEach(function (field) {
            const isArray = field.name.endsWith('[]');
            if (isArray) {
                const baseKey = field.name.slice(0, -2);
                if (!fields[baseKey]) {
                    fields[baseKey] = [];
                }
                fields[baseKey].push(field.value);
            } else if (fields[field.name] !== undefined) {
                if (!Array.isArray(fields[field.name])) {
                    fields[field.name] = [fields[field.name]];
                }
                fields[field.name].push(field.value);
            } else {
                fields[field.name] = field.value;
            }
        });
        return fields;
    }

    function setFieldValue(name, value) {
        const arrayName = name.endsWith('[]') ? name : name + '[]';
        if (Array.isArray(value)) {
            const $arrayInputs = $('[name="' + arrayName + '"]');
            if ($arrayInputs.length) {
                $arrayInputs.each(function (idx) {
                    $(this).val(value[idx] !== undefined ? value[idx] : '');
                });
                return;
            }
        }
        const $inputs = $('[name="' + name + '"]');
        if ($inputs.length === 1) {
            $inputs.val(value);
        } else if ($inputs.length > 1 && Array.isArray(value)) {
            $inputs.each(function (idx) {
                $(this).val(value[idx] !== undefined ? value[idx] : '');
            });
        }
    }

    function migrateExtraMaterialsToBinding(fields) {
        if (!fields || !Array.isArray(fields.material_id) || fields.material_id.length <= 4) {
            return fields;
        }

        const stdCount = 4;
        const extraIds = fields.material_id.slice(stdCount);
        const extraQty = (Array.isArray(fields.material_qty) ? fields.material_qty : []).slice(stdCount);
        const extraRate = (Array.isArray(fields.material_rate) ? fields.material_rate : []).slice(stdCount);
        const extraTotal = (Array.isArray(fields.material_total) ? fields.material_total : []).slice(stdCount);

        fields.material_id = fields.material_id.slice(0, stdCount);
        if (Array.isArray(fields.material_qty)) {
            fields.material_qty = fields.material_qty.slice(0, stdCount);
        }
        if (Array.isArray(fields.material_rate)) {
            fields.material_rate = fields.material_rate.slice(0, stdCount);
        }
        if (Array.isArray(fields.material_total)) {
            fields.material_total = fields.material_total.slice(0, stdCount);
        }

        ['binding_mat_id', 'binding_mat_unit', 'binding_mat_qty', 'binding_mat_rate', 'binding_mat_total'].forEach(function (key) {
            if (!Array.isArray(fields[key])) {
                fields[key] = fields[key] !== undefined && fields[key] !== '' ? [String(fields[key])] : [];
            }
        });

        extraIds.forEach(function (id, index) {
            if (!id) {
                return;
            }
            fields.binding_mat_id.push(String(id));
            fields.binding_mat_unit.push('');
            fields.binding_mat_qty.push(extraQty[index] !== undefined ? String(extraQty[index]) : '');
            fields.binding_mat_rate.push(extraRate[index] !== undefined ? String(extraRate[index]) : '');
            fields.binding_mat_total.push(extraTotal[index] !== undefined ? String(extraTotal[index]) : '0.00');
        });

        return fields;
    }

    function applyFields(fields) {
        if (!fields || typeof fields !== 'object') {
            return;
        }
        // Legacy drafts stored miscellaneous under cost_miscellaneous.
        if (fields.cost_miscellaneous !== undefined && fields.cost_consumables === undefined) {
            fields.cost_consumables = fields.cost_miscellaneous;
        }
        if (fields.cost_consumables !== undefined && fields.cost_consumables_misc === undefined) {
            const parsedMisc = parseInkMoney(fields.cost_consumables);
            fields.cost_consumables_misc = parsedMisc > 0 ? String(parsedMisc) : fields.cost_consumables;
        }
        if (Array.isArray(fields)) {
            fields.forEach(function (field) {
                setFieldValue(field.name, field.value);
            });
            return;
        }
        Object.keys(fields).forEach(function (key) {
            setFieldValue(key, fields[key]);
        });
    }

    function rebuildFromStructure(structure) {
        if (!structure) {
            return;
        }

        $('#paper-entries').empty();
        const paperTypes = structure.paperTypes && structure.paperTypes.length
            ? structure.paperTypes
            : defaultPaperTypes;
        const paperCount = Math.max(
            paperTypes.length,
            structure.paperRowCount || 0,
            defaultPaperTypes.length
        );
        for (let idx = 0; idx < paperCount; idx++) {
            addPaperEntry(paperTypes[idx] || defaultPaperTypes[idx] || '', idx === 0);
        }

        $('#ink-colour-rows').empty();
        const inkColours = structure.inkColours && structure.inkColours.length
            ? structure.inkColours
            : defaultColours;
        const inkCount = Math.max(
            inkColours.length,
            structure.inkRowCount || 0,
            defaultColours.length
        );
        for (let idx = 0; idx < inkCount; idx++) {
            addInkColourRow(inkColours[idx] || defaultColours[idx] || '');
        }

        $('#binding-rows').empty();
        for (let i = 0; i < (structure.bindingRowCount || 1); i++) {
            addBindingRow();
        }

        $('#press-machines').empty();
        for (let i = 0; i < (structure.machineBlockCount || 1); i++) {
            addMachineBlock();
        }

        $('#prepress-rows').empty();
        const prepressTarget = structure.prepressRowCount || DEFAULT_PREPRESS_ROWS;
        for (let i = 0; i < prepressTarget; i++) {
            addPrepressRow();
        }

        $('#finishing-rows').empty();
        const finishingTarget = structure.finishingRowCount || DEFAULT_FINISHING_ROWS;
        for (let i = 0; i < finishingTarget; i++) {
            addFinishingRow();
        }

        $('#consumable-rows').empty();
        for (let i = 0; i < (structure.consumableRowCount || 1); i++) {
            addConsumableRow();
        }

        refreshLucide();
    }

    function restoreMaterialLinkedFields(fields) {
        if (!fields) {
            return;
        }

        if (Array.isArray(fields.std_mat_dimensions)) {
            $('.std-material-card').each(function (index) {
                const card = $(this);
                const dim = fields.std_mat_dimensions[index];
                if (dim) {
                    card.find('.std-mat-dimensions').val(dim);
                    resolveStdMaterialCard(card);
                } else if (Array.isArray(fields.material_id) && fields.material_id[index]) {
                    card.find('.std-mat-id').val(fields.material_id[index]);
                }
            });
        }

        $('.paper-entry').each(function (index) {
            const entry = $(this);
            const stockType = Array.isArray(fields.paper_stock_type) ? fields.paper_stock_type[index] : '';
            if (stockType) {
                entry.find('.paper-stock-type').val(stockType);
                entry.find('.paper-stock-type-hidden').val(stockType);
            }
            if (Array.isArray(fields.paper_color) && fields.paper_color[index]) {
                entry.find('.paper-color-hidden').val(fields.paper_color[index]);
            }
            if (Array.isArray(fields.paper_grammage) && fields.paper_grammage[index]) {
                entry.find('.paper-grammage-hidden').val(fields.paper_grammage[index]);
            }
            if (Array.isArray(fields.paper_size) && fields.paper_size[index]) {
                entry.find('.paper-size-hidden').val(fields.paper_size[index]);
            }
            if (Array.isArray(fields.paper_material_id) && fields.paper_material_id[index]) {
                entry.find('.paper-material-id').val(fields.paper_material_id[index]);
            }
            refreshPaperSpecSelects(entry);
        });

        $('.ink-colour-row').each(function (index) {
            const row = $(this);
            if (Array.isArray(fields.ink_material_id) && fields.ink_material_id[index]) {
                row.find('.ink-material-id').val(fields.ink_material_id[index]);
            } else if (Array.isArray(fields.ink_colour) && fields.ink_colour[index]) {
                lookupInkRateForColour(fields.ink_colour[index], function (match) {
                    if (match) {
                        row.find('.ink-material-id').val(match.id);
                        if (!row.find('.ink-colour-rate').val() || row.find('.ink-colour-rate').val() === '0') {
                            row.find('.ink-colour-rate').val(match.rate || 0);
                        }
                    }
                });
            }
        });

        if (Array.isArray(fields.consumable_mat_id) && fields.consumable_mat_id.length) {
            $('.consumable-row').each(function (index) {
                const row = $(this);
                const matId = fields.consumable_mat_id[index];
                if (!matId) {
                    return;
                }
                row.find('.consumable-mat-select').val(String(matId));
                if (Array.isArray(fields.consumable_mat_unit) && fields.consumable_mat_unit[index]) {
                    row.find('.consumable-mat-unit').val(fields.consumable_mat_unit[index]);
                }
                if (Array.isArray(fields.consumable_mat_qty) && fields.consumable_mat_qty[index]) {
                    row.find('.consumable-mat-qty').val(fields.consumable_mat_qty[index]);
                }
                if (Array.isArray(fields.consumable_mat_rate) && fields.consumable_mat_rate[index]) {
                    row.find('.consumable-mat-rate').val(fields.consumable_mat_rate[index]);
                }
                if (Array.isArray(fields.consumable_mat_total) && fields.consumable_mat_total[index]) {
                    row.find('.consumable-mat-total').val(fields.consumable_mat_total[index]);
                } else {
                    refreshConsumableRowOptions(row);
                }
            });
        }

        if (Array.isArray(fields.binding_mat_id) && fields.binding_mat_id.length) {
            $('.binding-row').each(function (index) {
                const row = $(this);
                const matId = fields.binding_mat_id[index];
                if (matId) {
                    row.find('.binding-mat-select').val(String(matId));
                }
                if (Array.isArray(fields.binding_mat_unit) && fields.binding_mat_unit[index]) {
                    row.find('.binding-mat-unit').val(fields.binding_mat_unit[index]);
                }
            });
        }

        updateConsumablesTotal();
    }

    /**
     * Recompute row-level and section totals from restored field values (draft resume).
     */
    function recalculateAllSectionTotals() {
        $('#prepress-rows .prepress-row').each(function () {
            const row = $(this);
            const hrs = parseFloat(row.find('.prepress-hrs').val()) || 0;
            const rate = parseFloat(row.find('.prepress-rate').val()) || 0;
            row.find('.prepress-total').val(formatCurrency(hrs * rate));
        });
        let prepressSum = 0;
        $('.prepress-total').each(function () {
            prepressSum += parseInkMoney($(this).val());
        });
        $('#cost_prepress').val(formatCurrency(prepressSum));

        $('.machine-block').each(function () {
            const block = $(this);
            const mrHrs = parseFloat(block.find('.press-mr-hrs').val()) || 0;
            const mrRate = parseFloat(block.find('.press-mr-rate').val()) || 0;
            block.find('.press-mr-total').val(formatCurrency(mrHrs * mrRate));

            const impressions = parseFloat(block.find('.press-impressions').val()) || 0;
            const iph = parseFloat(block.find('.press-iph').val()) || 0;
            let runHrs = iph > 0 ? impressions / iph : (parseFloat(block.find('.press-run-hrs').val()) || 0);
            if (iph > 0) {
                block.find('.press-run-hrs').val(runHrs.toFixed(2));
            }
            const runRate = parseFloat(block.find('.press-run-rate').val()) || 0;
            block.find('.press-run-total').val(formatCurrency(runHrs * runRate));
        });
        let pressSum = 0;
        $('.press-mr-total, .press-run-total').each(function () {
            pressSum += parseInkMoney($(this).val());
        });
        $('#cost_press').val(formatCurrency(pressSum));

        $('#finishing-rows .finishing-row').each(function () {
            const row = $(this);
            const impressions = parseFloat(row.find('.finishing-impressions').val()) || 0;
            const iph = parseFloat(row.find('.finishing-iph').val()) || 0;
            const rate = parseFloat(row.find('.finishing-rate').val()) || 0;
            let hrs = iph > 0 ? impressions / iph : (parseFloat(row.find('.finishing-hrs').val()) || 0);
            if (iph > 0) {
                row.find('.finishing-hrs').val(hrs.toFixed(2));
            }
            row.find('.finishing-total').val(formatCurrency(hrs * rate));
        });
        let finishingSum = 0;
        $('.finishing-total').each(function () {
            finishingSum += parseInkMoney($(this).val());
        });
        $('#cost_finishing').val(formatCurrency(finishingSum));

        $('#binding-rows .binding-row').each(function () {
            const row = $(this);
            const qty = parseFloat(row.find('.binding-mat-qty').val()) || 0;
            const rate = parseFloat(row.find('.binding-mat-rate').val()) || 0;
            row.find('.binding-mat-total').val(formatCurrency(qty * rate));
        });
        let bindingSum = 0;
        $('.binding-mat-total').each(function () {
            bindingSum += parseInkMoney($(this).val());
        });
        $('#cost_binding').val(formatCurrency(bindingSum));

        $('.std-calc-qty').each(function () {
            const grid = $(this).closest('.grid');
            const qty = parseFloat($(this).val()) || 0;
            const rate = parseFloat(grid.find('.std-calc-rate').val()) || 0;
            grid.find('.std-calc-total').val(formatCurrency(qty * rate));
        });

        updatePaperTotal();
        refreshInkCosts(false);
        updateLabourTotal();
        calculateTotals();
    }

    function inferStructureFromFields(fields) {
        const count = function (key) {
            return Array.isArray(fields[key]) ? fields[key].length : (fields[key] ? 1 : 0);
        };
        const maxCount = function (keys) {
            let max = 0;
            keys.forEach(function (key) {
                max = Math.max(max, count(key));
            });
            return max;
        };
        return {
            paperTypes: Array.isArray(fields.paper_type) && fields.paper_type.length
                ? fields.paper_type
                : defaultPaperTypes,
            paperRowCount: Math.max(
                defaultPaperTypes.length,
                maxCount(['paper_type', 'paper_sheets', 'paper_material_id', 'paper_rate', 'paper_total'])
            ),
            inkColours: Array.isArray(fields.ink_colour) && fields.ink_colour.length
                ? fields.ink_colour
                : defaultColours,
            inkRowCount: Math.max(
                defaultColours.length,
                maxCount(['ink_colour', 'ink_material_id', 'ink_colour_kgs', 'ink_colour_rate', 'ink_colour_pct'])
            ),
            bindingRowCount: Math.max(1, maxCount(['binding_mat_id', 'binding_mat_qty', 'binding_mat_rate'])),
            consumableRowCount: Math.max(1, maxCount(['consumable_mat_id', 'consumable_mat_qty'])),
            machineBlockCount: Math.max(1, maxCount(['press_machine_name', 'press_task_id', 'press_mr_hrs', 'press_impressions'])),
            prepressRowCount: Math.max(
                DEFAULT_PREPRESS_ROWS,
                maxCount(['prepress_name', 'prepress_task_id', 'prepress_hrs', 'prepress_rate'])
            ),
            finishingRowCount: Math.max(
                DEFAULT_FINISHING_ROWS,
                maxCount(['finishing_name', 'finishing_task_id', 'finishing_hrs', 'finishing_rate', 'finishing_impressions'])
            ),
        };
    }

    function canonicalizeDraftValue(value) {
        if (Array.isArray(value)) {
            return value.map(canonicalizeDraftValue);
        }
        if (value && typeof value === 'object') {
            const keys = Object.keys(value).sort();
            const out = {};
            keys.forEach(function (key) {
                out[key] = canonicalizeDraftValue(value[key]);
            });
            return out;
        }
        return value;
    }

    function formFingerprint(fields) {
        try {
            return JSON.stringify(canonicalizeDraftValue(fields || captureFields()));
        } catch (err) {
            return '';
        }
    }

    function buildSnapshot(pendingSync) {
        const fields = captureFields();
        return {
            meta: {
                schemaVersion: window.FormDraftStore ? window.FormDraftStore.SCHEMA_VERSION : 1,
                updatedAt: new Date().toISOString(),
                step: currentStep,
                estId: getDraftEstId(),
                userId: wizardConfig.userId || null,
                pendingSync: !!pendingSync,
                revision: localBaseRevision,
                baseRevision: localBaseRevision,
                contentHash: lastSyncedContentHash,
            },
            fields: fields,
            structure: captureStructure(),
        };
    }

    function persistLocallyImmediate(pendingSync) {
        if (!window.FormDraftStore || !wizardConfig.userId) {
            return Promise.resolve();
        }
        const snapshot = buildSnapshot(pendingSync);
        const primaryKey = getDraftStorageKey();
        return persistToLocalStore(primaryKey, snapshot, pendingSync);
    }

    function persistLocallySync(pendingSync) {
        if (!window.FormDraftStore || !wizardConfig.userId) {
            return null;
        }
        const snapshot = buildSnapshot(pendingSync);
        const primaryKey = getDraftStorageKey();
        const estId = getDraftEstId();
        if (freshStart && !estId) {
            FormDraftStore.saveSessionSync(primaryKey, snapshot);
        } else {
            FormDraftStore.saveSync(primaryKey, snapshot);
            if (estId) {
                FormDraftStore.saveSync(getDraftStorageKey(estId), snapshot);
            }
        }
        if (shouldSetDraftPointer()) {
            FormDraftStore.setPointer({
                estId: snapshot.meta.estId,
                step: snapshot.meta.step,
                updatedAt: snapshot.meta.updatedAt,
                userId: wizardConfig.userId,
            });
        }
        return snapshot;
    }

    function persistLocallyDebounced() {
        clearTimeout(localSaveTimer);
        localSaveTimer = setTimeout(function () {
            persistLocallyImmediate(!navigator.onLine || pendingServerSync);
        }, 500);
        clearTimeout(serverSaveTimer);
        serverSaveTimer = setTimeout(function () {
            autosaveDraft(false);
        }, 1200);
    }

    function scheduleFieldAutosave() {
        clearTimeout(fieldAutosaveTimer);
        fieldAutosaveTimer = setTimeout(function () {
            persistLocallyImmediate(!navigator.onLine || pendingServerSync);
            autosaveDraft(false, 'autosave');
        }, 400);
    }

    function buildServerDraftPayload(saveAction, checkpointStep) {
        const formData = $('form#estimationForm').serializeArray();
        const payload = new FormData();
        formData.forEach(function (field) {
            payload.append(field.name, field.value);
        });
        // Clone creates a new row; still send current est_id for audit context but server ignores it.
        if (saveAction !== 'clone') {
            payload.append('est_id', getDraftEstId() || '');
        } else {
            payload.append('est_id', '');
        }
        payload.append('current_step', currentStep);
        payload.append('action', saveAction || 'autosave');
        payload.append('base_revision', String(localBaseRevision || 0));
        if (saveAction === 'step_checkpoint' && checkpointStep) {
            payload.append('checkpoint_step', String(checkpointStep));
        }
        return payload;
    }

    function flushOnPageExit() {
        if (exitFlushDone) {
            return;
        }
        exitFlushDone = true;
        clearTimeout(localSaveTimer);
        clearTimeout(serverSaveTimer);
        persistLocallySync(!navigator.onLine || pendingServerSync);
        if (!navigator.onLine) {
            return;
        }
        const payload = buildServerDraftPayload('autosave');
        try {
            if (typeof fetch === 'function') {
                fetch(endpoints.saveDraft, {
                    method: 'POST',
                    body: payload,
                    credentials: 'same-origin',
                    keepalive: true,
                }).catch(function () { /* best-effort */ });
                return;
            }
        } catch (fetchErr) {
            /* fall through to beacon */
        }
        if (typeof navigator.sendBeacon === 'function') {
            try {
                navigator.sendBeacon(endpoints.saveDraft, payload);
            } catch (beaconErr) {
                /* best-effort */
            }
        }
    }

    function parseTimestamp(value) {
        if (!value) {
            return 0;
        }
        const ts = Date.parse(String(value).replace(' ', 'T'));
        return Number.isNaN(ts) ? 0 : ts;
    }

    function snapshotFromServerData(serverData, metaOverrides) {
        if (!serverData || typeof serverData !== 'object') {
            return null;
        }
        const revision = (metaOverrides && metaOverrides.revision != null)
            ? metaOverrides.revision
            : (parseInt(wizardConfig.serverDraftRevision, 10) || 0);
        return {
            meta: {
                updatedAt: (metaOverrides && metaOverrides.updatedAt) || wizardConfig.serverDraftUpdatedAt || null,
                step: (metaOverrides && metaOverrides.step) || wizardConfig.serverDraftStep || 1,
                estId: (metaOverrides && metaOverrides.estId) || getDraftEstId(),
                userId: wizardConfig.userId || null,
                pendingSync: false,
                revision: revision,
                baseRevision: revision,
                contentHash: (metaOverrides && metaOverrides.contentHash) || wizardConfig.serverDraftContentHash || null,
            },
            fields: serverData,
            structure: inferStructureFromFields(serverData),
        };
    }

    function migrateLegacyLocalStorage() {
        try {
            const legacy = localStorage.getItem('estimation_draft_v4');
            if (!legacy) {
                return null;
            }
            const parsed = JSON.parse(legacy);
            if (!parsed) {
                return null;
            }
            const fields = {};
            if (Array.isArray(parsed)) {
                parsed.forEach(function (field) {
                    if (field.name.endsWith('[]')) {
                        const baseKey = field.name.slice(0, -2);
                        if (!fields[baseKey]) {
                            fields[baseKey] = [];
                        }
                        fields[baseKey].push(field.value);
                    } else {
                        fields[field.name] = field.value;
                    }
                });
            }
            localStorage.removeItem('estimation_draft_v4');
            return {
                meta: { updatedAt: new Date().toISOString(), step: 1, estId: getDraftEstId(), pendingSync: true },
                fields: fields,
                structure: inferStructureFromFields(fields),
            };
        } catch (err) {
            return null;
        }
    }

    function applySnapshotToForm(chosen) {
        if (!chosen) {
            return;
        }
        if (chosen.fields) {
            chosen.fields = migrateExtraMaterialsToBinding(chosen.fields);
        }
        const structure = chosen.fields
            ? inferStructureFromFields(chosen.fields)
            : chosen.structure;
        if (structure) {
            rebuildFromStructure(structure);
        }
        applyFields(chosen.fields);
        restoreMaterialLinkedFields(chosen.fields);
        syncLabourTaskSelectsFromNames();
        // Legacy drafts without ink_calc_mode used manual kgs — keep breakdown mode.
        try {
            var restoredInkMode = chosen.fields && chosen.fields.ink_calc_mode
                ? chosen.fields.ink_calc_mode
                : (chosen.fields && chosen.fields.ink_colour ? INK_MODE_BREAKDOWN : getInkCalcMode());
            setInkCalcMode(restoredInkMode, { skipRecalc: true });
        } catch (inkModeErr) {
            console.warn('Ink mode restore skipped:', inkModeErr);
        }
        restoreInkFromSavedFields(chosen.fields);
        if (chosen.meta && chosen.meta.estId) {
            setDraftEstId(chosen.meta.estId);
        }
        if (chosen.meta && chosen.meta.step) {
            currentStep = parseInt(chosen.meta.step, 10) || 1;
        }
        if (chosen.meta && chosen.meta.revision != null) {
            localBaseRevision = parseInt(chosen.meta.revision, 10) || 0;
            wizardConfig.serverDraftRevision = localBaseRevision;
        }
        if (chosen.meta && chosen.meta.contentHash) {
            lastSyncedContentHash = chosen.meta.contentHash;
            wizardConfig.serverDraftContentHash = lastSyncedContentHash;
        }
        if (chosen.meta && chosen.meta.pendingSync) {
            pendingServerSync = true;
        }
        lastSyncedFormFingerprint = formFingerprint(chosen.fields || captureFields());
        recalculateAllSectionTotals();
        preserveRestoredSectionTotals(chosen.fields);
    }

    /**
     * Re-apply saved ink row values after dynamic rows are rebuilt (step 4 resume).
     */
    function restoreInkFromSavedFields(fields) {
        if (!fields || typeof fields !== 'object') {
            return;
        }

        if (fields.ink_kgs) {
            $('#ink_kgs').val(fields.ink_kgs);
        }

        const formulaKgs = parseFloat($('#ink_kgs').val()) || parseFloat(fields.ink_kgs) || computeFormulaInkKgs();

        $('.ink-colour-row').each(function (index) {
            const row = $(this);

            if (Array.isArray(fields.ink_colour_pct) && fields.ink_colour_pct[index]) {
                row.find('.ink-colour-pct').val(fields.ink_colour_pct[index]);
            } else if (Array.isArray(fields.ink_colour_kgs) && fields.ink_colour_kgs[index] && formulaKgs > 0) {
                const kgs = parseFloat(fields.ink_colour_kgs[index]) || 0;
                if (kgs > 0) {
                    row.find('.ink-colour-pct').val((kgs / formulaKgs * 100).toFixed(2));
                }
            }

            if (Array.isArray(fields.ink_colour_kgs) && fields.ink_colour_kgs[index]) {
                row.find('.ink-colour-kgs').val(fields.ink_colour_kgs[index]);
            }
            if (Array.isArray(fields.ink_colour_rate) && fields.ink_colour_rate[index]) {
                row.find('.ink-colour-rate').val(fields.ink_colour_rate[index]);
            }
            if (Array.isArray(fields.ink_colour_total) && fields.ink_colour_total[index]) {
                const savedTotal = fields.ink_colour_total[index];
                row.find('.ink-colour-total').val(
                    String(savedTotal).indexOf('MK') >= 0 ? savedTotal : formatCurrency(parseInkMoney(savedTotal))
                );
            }
        });

        if (fields.cost_ink && parseInkMoney(fields.cost_ink) > 0) {
            const savedCost = fields.cost_ink;
            $('#cost_ink').val(String(savedCost).indexOf('MK') >= 0 ? savedCost : formatCurrency(parseInkMoney(savedCost)));
        }
    }

    /**
     * When row-level inputs are missing but saved section totals exist, keep the saved totals
     * instead of overwriting with zero after recalculateAllSectionTotals().
     */
    function preserveRestoredSectionTotals(savedFields) {
        if (!savedFields || typeof savedFields !== 'object') {
            return;
        }
        const costKeys = [
            'cost_paper', 'cost_ink', 'cost_binding', 'cost_prepress', 'cost_press',
            'cost_finishing', 'cost_labour_total', 'cost_consumables', 'subtotal', 'grand_total',
        ];
        costKeys.forEach(function (key) {
            const saved = savedFields[key];
            if (!saved) {
                return;
            }
            const savedAmt = parseInkMoney(saved);
            if (savedAmt <= 0) {
                return;
            }
            const $el = $('#' + key);
            if (!$el.length) {
                return;
            }
            const currentAmt = parseInkMoney($el.val());
            if (currentAmt <= 0) {
                $el.val(String(saved).indexOf('MK') >= 0 ? saved : formatCurrency(savedAmt));
            }
        });
        calculateTotals({ skipInkRefresh: true });
    }

    function openDraftConflictModal(payload) {
        conflictPayload = payload || null;
        conflictModalOpen = true;
        pendingServerSync = true;
        updateDraftStatus('Conflict — choose which version to keep', 'error');
        const modal = document.getElementById('draftConflictModal');
        if (modal) {
            modal.classList.remove('hidden');
            if (typeof window.refreshAppShellIcons === 'function') {
                window.refreshAppShellIcons();
            }
        } else {
            console.warn('Draft conflict modal missing', payload);
        }
    }

    function closeDraftConflictModal() {
        conflictModalOpen = false;
        const modal = document.getElementById('draftConflictModal');
        if (modal) {
            modal.classList.add('hidden');
        }
    }

    function applyServerConflictVersion(payload) {
        if (!payload || !payload.draft_data) {
            return;
        }
        const revision = parseInt(payload.draft_revision, 10) || 0;
        const snapshot = snapshotFromServerData(payload.draft_data, {
            revision: revision,
            updatedAt: payload.last_auto_saved || payload.timestamp || null,
            step: payload.draft_step || wizardConfig.serverDraftStep || 1,
            contentHash: payload.draft_content_hash || null,
            estId: payload.est_id || getDraftEstId(),
        });
        wizardConfig.draftData = payload.draft_data;
        wizardConfig.serverDraftUpdatedAt = payload.last_auto_saved || payload.timestamp || null;
        wizardConfig.serverDraftRevision = revision;
        wizardConfig.serverDraftContentHash = payload.draft_content_hash || null;
        pendingServerSync = false;
        syncAfterRestore = false;
        applySnapshotToForm(snapshot);
        persistLocallyImmediate(false);
        syncUnsavedBaseline();
        closeDraftConflictModal();
        conflictPayload = null;
        updateDraftStatus('Synced · rev ' + localBaseRevision, 'ok');
    }

    function autosaveDraft(forceSync, saveAction, checkpointStep) {
        if (conflictModalOpen && saveAction !== 'override' && saveAction !== 'clone') {
            return Promise.resolve();
        }

        saveAction = saveAction || 'autosave';
        if (syncAfterRestore && saveAction === 'autosave') {
            saveAction = 'recovered';
            syncAfterRestore = false;
        }

        const isStepCheckpoint = saveAction === 'step_checkpoint';

        if (
            !forceSync
            && !isStepCheckpoint
            && saveAction !== 'override'
            && saveAction !== 'clone'
            && !snapshotHasMeaningfulFields({ fields: captureFields() })
        ) {
            return Promise.resolve();
        }

        if (!navigator.onLine && !forceSync) {
            pendingServerSync = true;
            return persistLocallyImmediate(true);
        }

        const currentFingerprint = formFingerprint();
        if (
            !forceSync
            && !isStepCheckpoint
            && saveAction === 'autosave'
            && lastSyncedFormFingerprint
            && currentFingerprint === lastSyncedFormFingerprint
            && !pendingServerSync
        ) {
            return Promise.resolve();
        }

        if (autosaveInFlight && saveAction === 'autosave' && !forceSync) {
            return autosaveInFlight;
        }

        const payload = buildServerDraftPayload(saveAction, checkpointStep);
        const fetchFn = window.SessionGuard ? SessionGuard.authFetch.bind(SessionGuard) : fetch;
        const originalEstId = getDraftEstId();

        const loaderMessage = (saveAction === 'manual' || isStepCheckpoint || forceSync) ? 'Saving draft…' : null;
        const request = fetchFn(endpoints.saveDraft, {
            method: 'POST',
            body: payload,
            credentials: 'same-origin',
            skipGlobalLoader: saveAction === 'autosave' && !forceSync,
            loaderMessage: loaderMessage,
        })
            .then(function (response) {
                return response.json().then(function (data) {
                    return { response: response, data: data };
                }).catch(function () {
                    return { response: response, data: { success: false, message: 'Invalid server response' } };
                });
            })
            .then(function (result) {
                if (result.response.status === 409 || (result.data && result.data.conflict)) {
                    openDraftConflictModal(result.data);
                    return persistLocallyImmediate(true);
                }

                if (!result.response.ok || !result.data.success) {
                    throw new Error((result.data && result.data.message) || 'Auto-save failed');
                }

                if (saveAction === 'clone') {
                    showAutoSaveNotification('Saved a copy as a new draft (#' + result.data.est_id + ')');
                    if (originalEstId) {
                        setDraftEstId(originalEstId);
                    }
                    // Do not adopt the clone's revision/hash onto the open draft.
                    return null;
                }

                if (result.data.est_id) {
                    setDraftEstId(result.data.est_id);
                }
                if (result.data.estimation_number) {
                    updateFreshStartSubtitle(result.data.estimation_number);
                }

                if (result.data.draft_revision != null) {
                    localBaseRevision = parseInt(result.data.draft_revision, 10) || 0;
                    wizardConfig.serverDraftRevision = localBaseRevision;
                }
                if (result.data.draft_content_hash) {
                    lastSyncedContentHash = result.data.draft_content_hash;
                    wizardConfig.serverDraftContentHash = lastSyncedContentHash;
                }
                if (result.data.timestamp) {
                    wizardConfig.serverDraftUpdatedAt = result.data.timestamp;
                }

                pendingServerSync = false;
                lastAutoSaveTime = new Date();
                lastSyncedFormFingerprint = formFingerprint();

                return persistLocallyImmediate(false).then(function () {
                    if (result.data.noop) {
                        updateDraftStatus('Synced · rev ' + localBaseRevision, 'ok');
                    } else if (isStepCheckpoint) {
                        const stepLabel = WIZARD_STEP_LABELS[checkpointStep] || ('Step ' + checkpointStep);
                        updateDraftStatus(
                            'Step saved · ' + stepLabel + ' at ' + lastAutoSaveTime.toLocaleTimeString(),
                            'ok'
                        );
                    } else {
                        updateDraftStatus(
                            'Synced · rev ' + localBaseRevision + ' at ' + lastAutoSaveTime.toLocaleTimeString(),
                            'ok'
                        );
                    }
                    var estimationForm = document.getElementById('estimationForm');
                    if (estimationForm && window.FormUnsavedGuard) {
                        window.FormUnsavedGuard.resetBaseline(estimationForm);
                    }
                    if (typeof window.refreshDraftVersionHistory === 'function') {
                        window.refreshDraftVersionHistory();
                    }
                });
            })
            .catch(function (err) {
                pendingServerSync = true;
                persistLocallyImmediate(true);
                if (window.SessionGuard && SessionGuard.isSessionExpired()) {
                    updateDraftStatus('Session expired — sign in to sync', 'error');
                } else if (!navigator.onLine) {
                    updateDraftStatus('Saved on this device · waiting to sync', 'warn');
                } else {
                    updateDraftStatus('Saved on this device · waiting to sync', 'warn');
                }
                console.warn('Auto-save error:', err);
            })
            .finally(function () {
                if (autosaveInFlight === request) {
                    autosaveInFlight = null;
                }
            });

        autosaveInFlight = request;
        return request;
    }

    function updateFreshStartSubtitle(estimationNumber) {
        if (!freshStart || !estimationNumber) {
            return;
        }
        const subtitle = document.getElementById('estimation-page-subtitle');
        if (subtitle) {
            subtitle.textContent = 'Draft #' + estimationNumber + ' — autosaving as you work.';
        }
    }

    function flushPendingServerSync() {
        if (conflictModalOpen) {
            return Promise.resolve();
        }
        if (!pendingServerSync && lastSyncedFormFingerprint && formFingerprint() === lastSyncedFormFingerprint) {
            return Promise.resolve();
        }
        return autosaveDraft(true);
    }

    function snapshotMatchesEstId(snapshot, estId) {
        if (!snapshot) {
            return false;
        }
        if (!estId) {
            return true;
        }
        const snapEstId = snapshot.meta && snapshot.meta.estId;
        if (!snapEstId) {
            return false;
        }
        return String(snapEstId) === String(estId);
    }

    function snapshotHasMeaningfulFields(snapshot) {
        if (!snapshot || !snapshot.fields || typeof snapshot.fields !== 'object') {
            return false;
        }
        const fields = snapshot.fields;
        const scalarKeys = ['customer_name', 'job_title', 'job_description', 'grand_total', 'subtotal'];
        for (let i = 0; i < scalarKeys.length; i++) {
            const value = String(fields[scalarKeys[i]] ?? '').trim();
            if (value !== '' && value !== 'MK0' && value !== '0' && value !== '0.00') {
                return true;
            }
        }
        const numericArrays = ['material_qty', 'paper_sheets', 'binding_mat_qty', 'press_impressions', 'finishing_hrs'];
        for (let j = 0; j < numericArrays.length; j++) {
            const arr = fields[numericArrays[j]];
            if (!Array.isArray(arr)) {
                continue;
            }
            if (arr.some(function (item) {
                const value = String(item ?? '').trim();
                return value !== '' && parseFloat(value) !== 0;
            })) {
                return true;
            }
        }
        if (fields.ink_measure_base || fields.ink_pages || fields.ink_quantity_copies) {
            return true;
        }
        if (Array.isArray(fields.ink_colour_kgs) && fields.ink_colour_kgs.some(function (kgs) {
            return parseFloat(kgs) > 0;
        })) {
            return true;
        }
        return false;
    }

    function resolveAndLoadDraft() {
        const targetEstId = getDraftEstId();
        const legacySnapshot = migrateLegacyLocalStorage();
        const serverRevision = parseInt(wizardConfig.serverDraftRevision, 10) || 0;
        const serverSnapshot = snapshotFromServerData(wizardConfig.draftData || window.draftData);

        if (wizardConfig.draftHydratedFromDb && serverSnapshot) {
            applySnapshotToForm(serverSnapshot);
            lastSyncedFormFingerprint = formFingerprint(serverSnapshot.fields || captureFields());
            return clearAllDraftStorage().then(function () {
                return persistLocallyImmediate(false);
            });
        }

        const serverFromDatabase = wizardConfig.draftSource
            && String(wizardConfig.draftSource).indexOf('database') >= 0;
        if (serverFromDatabase && serverSnapshot && snapshotHasMeaningfulFields(serverSnapshot)) {
            applySnapshotToForm(serverSnapshot);
            lastSyncedFormFingerprint = formFingerprint(serverSnapshot.fields || captureFields());
            return clearAllDraftStorage().then(function () {
                return persistLocallyImmediate(false);
            });
        }

        // Fresh create: never restore from :active or legacy keys; same-tab session only.
        if (freshStart) {
            localBaseRevision = 0;
            const sessionKey = getDraftStorageKey();
            const sessionLoad = window.FormDraftStore
                ? FormDraftStore.loadSession(sessionKey)
                : Promise.resolve(null);

            return sessionLoad.then(function (clientSnapshot) {
                if (!clientSnapshot || !snapshotHasMeaningfulFields(clientSnapshot)) {
                    return;
                }
                applySnapshotToForm(clientSnapshot);
                if (clientSnapshot.meta && clientSnapshot.meta.estId) {
                    setDraftEstId(clientSnapshot.meta.estId);
                }
                if (clientSnapshot.meta && clientSnapshot.meta.revision != null) {
                    localBaseRevision = parseInt(clientSnapshot.meta.revision, 10) || 0;
                }
                if (clientSnapshot.meta && clientSnapshot.meta.step) {
                    currentStep = parseInt(clientSnapshot.meta.step, 10) || 1;
                }
                if (clientSnapshot.meta && clientSnapshot.meta.pendingSync) {
                    pendingServerSync = true;
                    syncAfterRestore = true;
                }
                lastSyncedFormFingerprint = formFingerprint(clientSnapshot.fields || captureFields());
            });
        }

        const loadPromise = window.FormDraftStore
            ? FormDraftStore.loadNewest(getDraftStorageKeys())
            : Promise.resolve(null);

        return loadPromise.then(function (clientSnapshot) {
            if (legacySnapshot && (!clientSnapshot || parseTimestamp(legacySnapshot.meta.updatedAt) > parseTimestamp(clientSnapshot.meta && clientSnapshot.meta.updatedAt))) {
                clientSnapshot = legacySnapshot;
            }

            if (clientSnapshot && !snapshotMatchesEstId(clientSnapshot, targetEstId)) {
                clientSnapshot = null;
            }

            if (
                targetEstId
                && serverSnapshot
                && clientSnapshot
                && snapshotHasMeaningfulFields(serverSnapshot)
                && !snapshotHasMeaningfulFields(clientSnapshot)
            ) {
                clientSnapshot = null;
            }

            let chosen = null;
            let openConflict = false;

            if (clientSnapshot && serverSnapshot) {
                const clientPending = !!(clientSnapshot.meta && clientSnapshot.meta.pendingSync);
                const hasRevisionMeta = !!(clientSnapshot.meta
                    && (clientSnapshot.meta.baseRevision != null || clientSnapshot.meta.revision != null));
                let clientBase = parseInt(
                    (clientSnapshot.meta && (clientSnapshot.meta.baseRevision != null
                        ? clientSnapshot.meta.baseRevision
                        : clientSnapshot.meta.revision)) || 0,
                    10
                ) || 0;
                const clientHash = clientSnapshot.meta && clientSnapshot.meta.contentHash;
                const serverHash = wizardConfig.serverDraftContentHash || null;

                // Pre-revision local drafts: adopt server revision as base and try a recovered sync.
                if (clientPending && !hasRevisionMeta) {
                    clientBase = serverRevision;
                    localBaseRevision = serverRevision;
                }

                if (clientPending && serverRevision === clientBase) {
                    chosen = clientSnapshot;
                    pendingServerSync = true;
                    syncAfterRestore = true;
                } else if (clientPending && serverRevision > clientBase) {
                    chosen = clientSnapshot;
                    openConflict = true;
                } else if (
                    clientPending
                    && hasRevisionMeta
                    && serverHash
                    && clientHash
                    && serverHash !== clientHash
                    && serverRevision >= clientBase
                ) {
                    chosen = clientSnapshot;
                    openConflict = true;
                } else if (!clientPending) {
                    chosen = serverSnapshot;
                } else {
                    chosen = clientSnapshot;
                    pendingServerSync = true;
                    syncAfterRestore = true;
                }
            } else if (clientSnapshot) {
                if (serverSnapshot && snapshotHasMeaningfulFields(serverSnapshot) && !snapshotHasMeaningfulFields(clientSnapshot)) {
                    chosen = serverSnapshot;
                } else {
                    chosen = clientSnapshot;
                    if (clientSnapshot.meta && clientSnapshot.meta.pendingSync) {
                        pendingServerSync = true;
                        syncAfterRestore = true;
                    }
                }
            } else if (serverSnapshot) {
                chosen = serverSnapshot;
            }

            if (!chosen) {
                localBaseRevision = serverRevision;
                return;
            }

            applySnapshotToForm(chosen);

            if (targetEstId && serverSnapshot && window.FormDraftStore && wizardConfig.userId) {
                persistLocallyImmediate(false);
            }

            if (openConflict) {
                openDraftConflictModal({
                    conflict: true,
                    est_id: targetEstId,
                    draft_data: wizardConfig.draftData || window.draftData,
                    draft_revision: serverRevision,
                    draft_content_hash: wizardConfig.serverDraftContentHash || null,
                    last_auto_saved: wizardConfig.serverDraftUpdatedAt || null,
                    draft_step: wizardConfig.serverDraftStep || 1,
                    message: 'Draft was updated elsewhere. Choose which version to keep.',
                });
            }
        });
    }

    function bindDraftConflictActions() {
        const useServerBtn = document.getElementById('draftConflictUseServer');
        const keepLocalBtn = document.getElementById('draftConflictKeepLocal');
        const keepBothBtn = document.getElementById('draftConflictKeepBoth');

        if (useServerBtn) {
            useServerBtn.addEventListener('click', function () {
                if (!conflictPayload) {
                    closeDraftConflictModal();
                    return;
                }
                applyServerConflictVersion(conflictPayload);
            });
        }

        if (keepLocalBtn) {
            keepLocalBtn.addEventListener('click', function () {
                updateDraftStatus('Overwriting server with this device…', 'warn');
                autosaveDraft(true, 'override').then(function () {
                    if (!pendingServerSync) {
                        closeDraftConflictModal();
                        conflictPayload = null;
                        showAutoSaveNotification('This device’s version is now on the server');
                    }
                });
            });
        }

        if (keepBothBtn) {
            keepBothBtn.addEventListener('click', function () {
                const payload = conflictPayload;
                updateDraftStatus('Saving a copy, then loading server version…', 'warn');
                autosaveDraft(true, 'clone').then(function () {
                    if (payload) {
                        applyServerConflictVersion(payload);
                    } else {
                        closeDraftConflictModal();
                    }
                });
            });
        }
    }

    function formatDraftVersionTime(savedAt) {
        if (!savedAt) {
            return 'Unknown time';
        }
        const dt = new Date(String(savedAt).replace(' ', 'T'));
        if (isNaN(dt.getTime())) {
            return savedAt;
        }
        return dt.toLocaleString(undefined, {
            month: 'short',
            day: 'numeric',
            hour: '2-digit',
            minute: '2-digit',
        });
    }

    function bindDraftVersionHistory() {
        const toggle = document.getElementById('draftHistoryToggle');
        const panel = document.getElementById('draftHistoryPanel');
        const listEl = document.getElementById('draftHistoryList');
        const restoreModal = document.getElementById('draftVersionRestoreModal');
        const restoreMessage = document.getElementById('draftVersionRestoreMessage');
        const restoreCancel = document.getElementById('draftVersionRestoreCancel');
        const restoreConfirm = document.getElementById('draftVersionRestoreConfirm');
        const versionsEndpoint = endpoints.draftVersions;

        if (!toggle || !panel || !listEl || !versionsEndpoint || !draftMode) {
            return;
        }

        let pendingRestoreStep = null;
        let versionsLoaded = false;

        function closeHistoryPanel() {
            panel.classList.add('hidden');
        }

        function closeVersionRestoreModal() {
            if (restoreModal) {
                restoreModal.classList.add('hidden');
            }
            pendingRestoreStep = null;
        }

        function renderVersionList(versions) {
            if (!versions || !versions.length) {
                listEl.innerHTML = '<p class="px-3 py-4 text-sm text-gray-500">No step checkpoints yet. Complete a wizard step to create one.</p>';
                return;
            }

            listEl.innerHTML = versions.map(function (item) {
                const label = item.label || ('Step ' + (item.draft_step || 1));
                const time = item.saved_at ? formatDraftVersionTime(item.saved_at) : 'Not saved yet';
                const step = item.draft_step || 1;
                let actionCell;
                if (item.is_current) {
                    actionCell = '<span class="text-xs text-gray-400">Active</span>';
                } else if (item.has_checkpoint) {
                    actionCell = '<button type="button" class="draft-version-restore text-xs font-semibold text-amber-700 hover:text-amber-900" data-step="' + step + '" data-time="' + time.replace(/"/g, '&quot;') + '">Restore</button>';
                } else {
                    actionCell = '<span class="text-xs text-gray-400">No checkpoint</span>';
                }

                return '<div class="flex items-center justify-between gap-2 px-3 py-2.5 border-b border-gray-100 last:border-b-0 hover:bg-gray-50">' +
                    '<div class="min-w-0">' +
                    '<p class="text-sm font-semibold text-gray-800">' + label + '</p>' +
                    '<p class="text-xs text-gray-500">' + time + '</p>' +
                    '</div>' +
                    actionCell +
                    '</div>';
            }).join('');

            listEl.querySelectorAll('.draft-version-restore').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    pendingRestoreStep = parseInt(btn.getAttribute('data-step'), 10) || null;
                    const timeLabel = btn.getAttribute('data-time') || 'this step';
                    if (restoreMessage) {
                        restoreMessage.textContent = 'Replace the current form with the saved checkpoint from ' + timeLabel + '? Unsaved changes will be lost.';
                    }
                    closeHistoryPanel();
                    if (restoreModal) {
                        restoreModal.classList.remove('hidden');
                    }
                });
            });
        }

        function loadVersionList(force) {
            const estId = getDraftEstId();
            if (!estId) {
                return Promise.resolve();
            }
            if (versionsLoaded && !force) {
                return Promise.resolve();
            }
            listEl.innerHTML = '<p class="px-3 py-4 text-sm text-gray-500">Loading…</p>';
            return fetch(versionsEndpoint + '?est_id=' + encodeURIComponent(estId), {
                credentials: 'same-origin',
            })
                .then(function (response) { return response.json(); })
                .then(function (data) {
                    if (!data.success) {
                        throw new Error(data.message || 'Failed to load versions');
                    }
                    versionsLoaded = true;
                    renderVersionList(data.versions || []);
                })
                .catch(function (err) {
                    listEl.innerHTML = '<p class="px-3 py-4 text-sm text-red-600">' + (err.message || 'Could not load history') + '</p>';
                });
        }

        toggle.addEventListener('click', function (event) {
            event.stopPropagation();
            const willOpen = panel.classList.contains('hidden');
            if (willOpen) {
                panel.classList.remove('hidden');
                loadVersionList(false);
            } else {
                closeHistoryPanel();
            }
        });

        document.addEventListener('click', function (event) {
            const wrap = document.getElementById('draftHistoryWrap');
            if (wrap && !wrap.contains(event.target)) {
                closeHistoryPanel();
            }
        });

        if (restoreCancel) {
            restoreCancel.addEventListener('click', closeVersionRestoreModal);
        }

        if (restoreConfirm) {
            restoreConfirm.addEventListener('click', function () {
                const estId = getDraftEstId();
                const step = pendingRestoreStep;
                if (!estId || !step) {
                    closeVersionRestoreModal();
                    return;
                }

                const body = new URLSearchParams();
                body.append('action', 'restore');
                body.append('est_id', String(estId));
                body.append('step', String(step));

                restoreConfirm.disabled = true;
                fetch(versionsEndpoint, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: body.toString(),
                    credentials: 'same-origin',
                    loaderMessage: 'Restoring version…',
                })
                    .then(function (response) { return response.json(); })
                    .then(function (data) {
                        if (!data.success) {
                            throw new Error(data.message || 'Restore failed');
                        }
                        const snapshot = snapshotFromServerData(data.draft_data, {
                            revision: data.draft_revision,
                            contentHash: data.draft_content_hash,
                            step: data.draft_step,
                            updatedAt: data.timestamp,
                        });
                        applySnapshotToForm(snapshot);
                        localBaseRevision = parseInt(data.draft_revision, 10) || localBaseRevision;
                        wizardConfig.serverDraftRevision = localBaseRevision;
                        wizardConfig.serverDraftContentHash = data.draft_content_hash || null;
                        if (data.draft_step) {
                            currentStep = parseInt(data.draft_step, 10) || currentStep;
                            showStep(currentStep);
                        }
                        pendingServerSync = false;
                        lastSyncedFormFingerprint = formFingerprint();
                        persistLocallyImmediate(false);
                        syncUnsavedBaseline();
                        versionsLoaded = false;
                        showAutoSaveNotification('Step checkpoint restored');
                        updateDraftStatus('Restored · Step ' + step, 'ok');
                    })
                    .catch(function (err) {
                        alert('Could not restore version: ' + err.message);
                    })
                    .finally(function () {
                        restoreConfirm.disabled = false;
                        closeVersionRestoreModal();
                    });
            });
        }

        window.refreshDraftVersionHistory = function () {
            versionsLoaded = false;
            if (!panel.classList.contains('hidden')) {
                loadVersionList(true);
            }
        };
    }

    function bindLogoutDraftFlush() {
        const logoutLinks = document.querySelectorAll('a[href*="modules/auth/logout"]');
        logoutLinks.forEach(function (link) {
            link.addEventListener('click', function (event) {
                if (logoutFlushInProgress) {
                    return;
                }
                const href = link.href;
                if (!href) {
                    return;
                }
                event.preventDefault();
                logoutFlushInProgress = true;
                exitFlushDone = false;
                updateDraftStatus('Saving draft before sign-out…', 'warn');

                const savePromise = autosaveDraft(true).catch(function () { /* best-effort */ });
                const timeoutPromise = new Promise(function (resolve) {
                    setTimeout(resolve, 3000);
                });

                Promise.race([savePromise, timeoutPromise]).finally(function () {
                    persistLocallySync(pendingServerSync);
                    window.location.href = href;
                });
            });
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

    // Navigation — checkpoint the completed step before changing steps.
    $('.next-step').click(function () {
        if (!validateStep(currentStep)) {
            return;
        }
        const stepToCheckpoint = currentStep;
        persistLocallyImmediate(!navigator.onLine || pendingServerSync);
        autosaveDraft(true, 'step_checkpoint', stepToCheckpoint).finally(function () {
            currentStep++;
            showStep(currentStep);
        });
    });

    $('.prev-step').click(function () {
        const stepToCheckpoint = currentStep;
        persistLocallyImmediate(!navigator.onLine || pendingServerSync);
        autosaveDraft(true, 'step_checkpoint', stepToCheckpoint).finally(function () {
            currentStep--;
            showStep(currentStep);
        });
    });

    $('form#estimationForm').on('change input', function () {
        calculateTotals();
        persistLocallyDebounced();
    });

    $('form#estimationForm').on('blur', 'input, select, textarea', function () {
        scheduleFieldAutosave();
    });

    // Clean up auto-save timer on page unload
    $(window).on('beforeunload', function() {
        flushOnPageExit();
        if (autoSaveTimer) {
            clearInterval(autoSaveTimer);
        }
    });

    // Handle "Save as Draft" button (manual save)
    $(document).on('click', 'button[name="save_draft"]', function(e) {
        e.preventDefault();
        persistLocallyImmediate(false);
        autosaveDraft(true, 'manual').then(function () {
            showAutoSaveNotification('Draft saved successfully!');
        });
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
        <div class="paper-entry bg-white border border-gray-200 rounded-xl shadow-sm overflow-hidden">
            <div class="flex justify-between items-center px-5 py-4 bg-gray-50 border-b border-gray-200">
                <div>
                    <h4 class="font-bold text-gray-800">${paperType ? paperType + ' Paper' : 'Paper Entry'}</h4>
                    <p class="text-xs text-gray-500 mt-0.5">Label for this run (Cover, Original, etc.)</p>
                </div>
                ${deleteBtn}
            </div>
            <input type="hidden" name="paper_material_id[]" class="paper-material-id" value="">
            <div class="p-5 space-y-5">
                <div>
                    <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1.5">Paper Type / Label</label>
                    <input type="text" name="paper_type[]" value="${paperType}"
                        class="w-full px-3 py-2.5 border border-gray-300 rounded-lg focus:outline-none focus:border-green-500 focus:ring-2 focus:ring-green-100"
                        placeholder="e.g. Cover, Original, Duplicate">
                </div>
                <div class="rounded-xl border border-blue-100 bg-blue-50/40 p-4">
                    <div class="flex items-center justify-between gap-3 mb-4">
                        <div>
                            <p class="text-sm font-semibold text-gray-800">Catalog Specification</p>
                            <p class="text-xs text-gray-500">Select specs or use <span class="font-medium text-green-700">+</span> to add missing items.</p>
                        </div>
                        <button type="button"
                            class="paper-row-quick-add inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg border border-green-200 bg-white text-green-700 text-xs font-semibold hover:bg-green-50 transition">
                            <i data-lucide="plus" class="h-4 w-4" aria-hidden="true"></i> New paper
                        </button>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        ${paperSelectField('Stock Type', 'paper-stock-type', 'paper_stock_type[]', 'paper-stock-type-hidden', 'stock_type', 'Select stock type…')}
                        ${paperSelectField('Colour', 'paper-color-select', 'paper_color[]', 'paper-color-hidden', 'color', 'Select colour…')}
                        ${paperSelectField('Grammage (gsm)', 'paper-grammage-select', 'paper_grammage[]', 'paper-grammage-hidden', 'grammage', 'Select gsm…')}
                        <div class="paper-spec-field" data-spec-field="dimensions">
                            <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1.5">Size</label>
                            <div class="flex items-stretch gap-2">
                                <select class="paper-dimensions-select flex-1 min-w-0 px-3 py-2.5 border border-gray-300 rounded-lg bg-white text-sm focus:outline-none focus:border-green-500 focus:ring-2 focus:ring-green-100">
                                    <option value="">Size (optional)…</option>
                                </select>
                                <button type="button"
                                    class="paper-spec-quick-add inline-flex items-center justify-center w-10 shrink-0 rounded-lg border border-green-200 bg-green-50 text-green-700 hover:bg-green-100 hover:border-green-300 transition"
                                    data-spec-field="dimensions" title="Add to catalog">
                                    <i data-lucide="plus" class="h-4 w-4" aria-hidden="true"></i>
                                </button>
                            </div>
                            <input type="text" name="paper_size[]" class="paper-size-hidden w-full px-3 py-2 mt-2 border border-gray-300 rounded-lg text-sm" placeholder="Custom size e.g. 210x297">
                        </div>
                    </div>
                    <div class="mt-4 paper-match-label"></div>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 pt-1 border-t border-gray-100">
                    <div>
                        <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1.5">No. of Sheets</label>
                        <input type="number" name="paper_sheets[]"
                            class="w-full px-3 py-2.5 border border-gray-300 rounded-lg paper-sheets focus:outline-none focus:border-green-500" placeholder="0">
                        <p class="text-xs text-gray-400 mt-1">Include extras for damage</p>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1.5">Price / Sheet (MK)</label>
                        <input type="number" step="0.01" name="paper_rate[]" value="0"
                            class="w-full px-3 py-2.5 border border-gray-300 rounded-lg paper-rate focus:outline-none focus:border-green-500">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1.5">Line Total (MK)</label>
                        <input type="text" name="paper_total[]" readonly
                            class="w-full px-3 py-2.5 bg-gray-50 border border-gray-200 rounded-lg font-bold text-gray-800 paper-total">
                    </div>
                </div>
            </div>
        </div>`;
        $('#paper-entries').append(html);
        const entry = $('#paper-entries .paper-entry').last();
        materialFetchDistinct('stock_type', { category: 'Printing Papers' }).done(function (resp) {
            populateSelectOptions(entry.find('.paper-stock-type'), resp.values || [], 'Select stock type…');
        });
        refreshLucide();
    }

    $(document).on('click', '#add-paper-btn', function () {
        addPaperEntry('', false);
    });

    $(document).on('click', '.paper-spec-quick-add, .paper-row-quick-add', function () {
        const entry = $(this).closest('.paper-entry');
        const field = $(this).data('spec-field') || 'stock_type';
        if (typeof window.openPaperQuickAddModal === 'function') {
            window.openPaperQuickAddModal(entry, field);
        }
    });

    $(document).on('input', '#paper_add_stock_type, #paper_add_color, #paper_add_grammage, #paper_add_dimensions', updatePaperAddNamePreview);

    $('#paperAddForm').submit(function (e) {
        e.preventDefault();
        updatePaperAddNamePreview();
        $.ajax({
            url: materialSaveUrl,
            method: 'POST',
            data: $(this).serialize(),
            dataType: 'json',
            loaderMessage: 'Saving catalog paper…',
            success: function (response) {
                if (response.status !== 'success') {
                    alert('Error: ' + (response.message || 'Could not save paper'));
                    return;
                }
                refreshAllPaperStockTypeOptions().always(function () {
                    const entry = paperQuickAddTargetEntry && paperQuickAddTargetEntry.length
                        ? paperQuickAddTargetEntry
                        : $('.paper-entry').first();
                    applyPaperMaterialToEntry(entry, response);
                    updateDraftStatus('Paper saved to catalog and applied', 'ok');
                    if (typeof window.closePaperQuickAddModal === 'function') {
                        window.closePaperQuickAddModal();
                    }
                    $('#paperAddForm')[0].reset();
                    updatePaperAddNamePreview();
                });
            },
            error: function () {
                alert('Connection error. Please try again.');
            }
        });
    });

    $(document).on('change', '.paper-stock-type', function () {
        const entry = $(this).closest('.paper-entry');
        entry.find('.paper-stock-type-hidden').val($(this).val() || '');
        refreshPaperSpecSelects(entry, 'stock_type');
    });
    $(document).on('change', '.paper-color-select', function () {
        const entry = $(this).closest('.paper-entry');
        entry.find('.paper-color-hidden').val($(this).val() || '');
        refreshPaperSpecSelects(entry, 'color');
    });
    $(document).on('change', '.paper-grammage-select', function () {
        const entry = $(this).closest('.paper-entry');
        entry.find('.paper-grammage-hidden').val($(this).val() || '');
        refreshPaperSpecSelects(entry, 'grammage');
    });
    $(document).on('change', '.paper-dimensions-select, .paper-size-hidden', function () {
        const entry = $(this).closest('.paper-entry');
        const dim = entry.find('.paper-dimensions-select').val() || entry.find('.paper-size-hidden').val();
        if (entry.find('.paper-dimensions-select').val()) {
            entry.find('.paper-size-hidden').val(entry.find('.paper-dimensions-select').val());
        }
        if (dim) {
            resolvePaperEntry(entry);
        }
    });

    $(document).on('change', '.std-mat-dimensions', function () {
        resolveStdMaterialCard($(this).closest('.std-material-card'));
    });

    $(document).on('click', '.remove-paper-btn', function () {
        $(this).closest('.paper-entry').remove();
        updatePaperTotal();
        calculateTotals();
    });

    $(document).on('input', '.paper-sheets, .paper-rate', function () {
        updatePaperEntryTotal($(this).closest('.paper-entry'));
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
    function getInkCalcMode() {
        const mode = ($('#ink_calc_mode').val() || INK_MODE_FORMULA_BREAKDOWN).toString();
        if (mode === INK_MODE_FORMULA || mode === INK_MODE_BREAKDOWN || mode === INK_MODE_FORMULA_BREAKDOWN) {
            return mode;
        }
        return INK_MODE_FORMULA_BREAKDOWN;
    }

    function parseInkMoney(value) {
        return parseFloat(String(value || '').replace(/MK/gi, '').replace(/,/g, '').trim()) || 0;
    }

    function setInkCalcMode(mode, opts) {
        opts = opts || {};
        const next = (mode === INK_MODE_FORMULA || mode === INK_MODE_BREAKDOWN)
            ? mode
            : INK_MODE_FORMULA_BREAKDOWN;
        $('#ink_calc_mode').val(next);

        $('.ink-mode-btn').each(function () {
            const active = $(this).data('ink-mode') === next;
            $(this).toggleClass('border-green-500 bg-green-50', active);
            $(this).toggleClass('border-gray-200', !active);
        });

        const useFormula = next !== INK_MODE_BREAKDOWN;
        const useBreakdown = next !== INK_MODE_FORMULA;

        $('#ink-formula-panel, #ink-formula-fields').toggleClass('hidden', !useFormula);
        $('#ink-formula-rate-wrap').toggleClass('hidden', next !== INK_MODE_FORMULA);
        $('#ink-breakdown-panel').toggleClass('hidden', !useBreakdown);
        $('.ink-col-pct').toggleClass('hidden', next !== INK_MODE_FORMULA_BREAKDOWN);
        $('.ink-colour-pct-cell').toggleClass('hidden', next !== INK_MODE_FORMULA_BREAKDOWN);

        if (next === INK_MODE_FORMULA_BREAKDOWN) {
            $('#ink-breakdown-hint').text('Enter colour percentages and rates; kgs are taken from the formula total.');
            $('.ink-colour-kgs').prop('readonly', true).addClass('bg-gray-50');
        } else if (next === INK_MODE_BREAKDOWN) {
            $('#ink-breakdown-hint').text('Enter colour kgs and rates. Formula is not required — Total Ink Cost updates from the breakdown.');
            $('.ink-colour-kgs').prop('readonly', false).removeClass('bg-gray-50');
        } else {
            $('#ink-breakdown-hint').text('');
        }

        if (!opts.skipRecalc) {
            refreshInkCosts(true);
        }
    }

    function computeFormulaInkKgs() {
        const base = parseFloat($('input[name="ink_measure_base"]').val()) || 0;
        const height = parseFloat($('input[name="ink_height"]').val()) || 0;
        const pages = parseFloat($('input[name="ink_pages"]').val()) || 0;
        const qty = parseFloat($('input[name="ink_quantity_copies"]').val()) || 0;
        if (window.PressCalculations && typeof window.PressCalculations.formulaInkKgs === 'function') {
            return window.PressCalculations.formulaInkKgs({
                baseMm: base,
                heightMm: height,
                pages: pages,
                quantity: qty,
            });
        }
        return (base / 1000 * height / 1000) * pages * qty * 0.5 / 0.886 / 1000;
    }

    function calculateInk() {
        const mode = getInkCalcMode();
        if (mode !== INK_MODE_BREAKDOWN) {
            $('#ink_kgs').val(computeFormulaInkKgs().toFixed(4));
        }
    }

    /**
     * Always populate #cost_ink from the active method.
     * Breakdown-only works with no formula inputs.
     */
    function refreshInkCosts(triggerTotals) {
        const mode = getInkCalcMode();
        const formulaKgs = computeFormulaInkKgs();
        const savedFormulaKgs = parseFloat($('#ink_kgs').val()) || 0;
        const effectiveFormulaKgs = formulaKgs > 0 ? formulaKgs : savedFormulaKgs;

        if (mode !== INK_MODE_BREAKDOWN) {
            $('#ink_kgs').val((effectiveFormulaKgs > 0 ? effectiveFormulaKgs : 0).toFixed(4));
        }

        let totalCost = 0;

        if (mode === INK_MODE_FORMULA) {
            const rate = parseFloat($('#ink_overall_rate').val()) || 0;
            totalCost = effectiveFormulaKgs * rate;
            $('#ink-colour-warning').addClass('hidden');
        } else {
            $('.ink-colour-row').each(function () {
                const row = $(this);
                const rate = parseFloat(row.find('.ink-colour-rate').val()) || 0;
                let kgs = parseFloat(row.find('.ink-colour-kgs').val()) || 0;
                const pct = parseFloat(row.find('.ink-colour-pct').val()) || 0;
                const savedRowTotal = parseInkMoney(row.find('.ink-colour-total').val());

                if (mode === INK_MODE_FORMULA_BREAKDOWN) {
                    if (pct > 0 && effectiveFormulaKgs > 0) {
                        kgs = effectiveFormulaKgs * (pct / 100);
                        row.find('.ink-colour-kgs').val(kgs > 0 ? kgs.toFixed(4) : '');
                    } else if (kgs > 0 && pct <= 0 && effectiveFormulaKgs > 0) {
                        row.find('.ink-colour-pct').val((kgs / effectiveFormulaKgs * 100).toFixed(2));
                    } else if (kgs <= 0 && savedRowTotal > 0 && rate > 0) {
                        kgs = savedRowTotal / rate;
                        row.find('.ink-colour-kgs').val(kgs > 0 ? kgs.toFixed(4) : '');
                        if (effectiveFormulaKgs > 0) {
                            row.find('.ink-colour-pct').val((kgs / effectiveFormulaKgs * 100).toFixed(2));
                        }
                    }
                }

                let rowTotal = kgs * rate;
                if (rowTotal <= 0 && savedRowTotal > 0) {
                    rowTotal = savedRowTotal;
                }
                row.find('.ink-colour-total').val(formatCurrency(rowTotal));
                totalCost += rowTotal;
            });
            validateInkColours();
        }

        if (totalCost <= 0) {
            const savedSectionTotal = parseInkMoney($('#cost_ink').val());
            if (savedSectionTotal > 0) {
                totalCost = savedSectionTotal;
            }
        }

        $('#cost_ink').val(formatCurrency(totalCost));

        if (triggerTotals !== false) {
            calculateTotals({ skipInkRefresh: true });
        }
    }

    $(document).on('input', '.calc-ink-listen, input[name="ink_measure_base"], input[name="ink_height"], input[name="ink_pages"], input[name="ink_quantity_copies"], #ink_overall_rate', function () {
        refreshInkCosts(true);
    });

    $(document).on('click', '.ink-mode-btn', function () {
        setInkCalcMode($(this).data('ink-mode'));
    });

    // =====================
    // INK COLOUR BREAKDOWN
    // =====================

    function initInkColourRows() {
        defaultColours.forEach(function (col) {
            addInkColourRow(col, DEFAULT_INK_COLOUR_PCT[col]);
        });
        setInkCalcMode(getInkCalcMode(), { skipRecalc: true });
    }

    function addInkColourRow(colourName, pct) {
        colourName = colourName || '';
        const pctVal = pct != null ? pct : '';
        const mode = getInkCalcMode();
        const pctHidden = mode === INK_MODE_FORMULA_BREAKDOWN ? '' : 'hidden';
        const kgsReadonly = mode === INK_MODE_FORMULA_BREAKDOWN ? 'readonly' : '';
        const kgsBg = mode === INK_MODE_FORMULA_BREAKDOWN ? 'bg-gray-50' : '';
        const html = `
        <tr class="ink-colour-row">
            <td class="px-3 py-2">
                <input type="text" name="ink_colour[]" value="${colourName}"
                    class="w-full border-gray-300 rounded-lg ink-colour-name" placeholder="e.g. C, M, Y, K">
                <input type="hidden" name="ink_material_id[]" class="ink-material-id" value="">
            </td>
            <td class="px-3 py-2 ink-colour-pct-cell ${pctHidden}">
                <input type="number" step="0.01" min="0" name="ink_colour_pct[]" value="${pctVal}"
                    class="w-full border-gray-300 rounded-lg ink-colour-pct" placeholder="0">
            </td>
            <td class="px-3 py-2">
                <input type="number" step="0.0001" name="ink_colour_kgs[]" ${kgsReadonly}
                    class="w-full border-gray-300 rounded-lg ink-colour-kgs ${kgsBg}" placeholder="0.0000">
            </td>
            <td class="px-3 py-2">
                <input type="number" step="0.01" name="ink_colour_rate[]" value="0"
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
        const row = $('#ink-colour-rows .ink-colour-row').last();
        if (colourName) {
            lookupInkRateForColour(colourName, function (match) {
                if (match) {
                    row.find('.ink-material-id').val(match.id);
                    row.find('.ink-colour-rate').val(match.rate || 0);
                    refreshInkCosts(true);
                }
            });
        }
        refreshLucide();
    }

    $(document).on('change blur', '.ink-colour-name', function () {
        const row = $(this).closest('.ink-colour-row');
        const colourName = row.find('.ink-colour-name').val();
        lookupInkRateForColour(colourName, function (match) {
            if (match) {
                row.find('.ink-material-id').val(match.id);
                row.find('.ink-colour-rate').val(match.rate || 0);
                refreshInkCosts(true);
            }
        });
    });

    $(document).on('click', '#add-ink-colour-btn', function () { addInkColourRow(''); });

    $(document).on('click', '.remove-ink-colour-row', function () {
        $(this).closest('tr').remove();
        refreshInkCosts(true);
    });

    $(document).on('input', '.ink-colour-kgs, .ink-colour-rate, .ink-colour-pct', function () {
        refreshInkCosts(true);
    });

    function calcInkColourTotals() {
        refreshInkCosts(true);
    }

    function validateInkColours() {
        const mode = getInkCalcMode();
        const warning = $('#ink-colour-warning');
        if (mode === INK_MODE_FORMULA || mode === INK_MODE_BREAKDOWN) {
            warning.addClass('hidden');
            return;
        }

        let pctSum = 0;
        $('.ink-colour-pct').each(function () {
            pctSum += parseFloat($(this).val()) || 0;
        });
        if (pctSum > 100.01) {
            warning.text('Total colour percentages exceed 100%.').removeClass('hidden');
        } else {
            warning.addClass('hidden');
        }
    }

    // =====================
    // BINDING MATERIALS
    // =====================
    $(document).on('click', '#add-binding-row', function () { addBindingRow(); });

    $(document).on('click', '.binding-quick-add', function () {
        bindingQuickAddTargetRow = $(this).closest('.binding-row');
        if (typeof window.openBindingAddModal === 'function') {
            window.openBindingAddModal();
        }
    });

    function bindingMaterialOptionLabel(name, unit) {
        return unit ? name + ' (' + unit + ')' : name;
    }

    function appendBindingMaterialOption(materialId, name, rate, unit) {
        const label = bindingMaterialOptionLabel(name, unit);
        const optHtml = '<option value="' + materialId + '" data-rate="' + rate + '" data-unit="' + (unit || '') + '">' + label + '</option>';
        $('.binding-mat-select').append(optHtml);
        const tmpl = document.getElementById('binding-row-template');
        if (tmpl) {
            const sel = tmpl.content.querySelector('.binding-mat-select');
            const opt = document.createElement('option');
            opt.value = materialId;
            opt.textContent = label;
            opt.setAttribute('data-rate', rate);
            opt.setAttribute('data-unit', unit || '');
            sel.appendChild(opt);
        }
    }

    function selectBindingMaterialInForm(materialId, rate, unit) {
        let row = bindingQuickAddTargetRow && bindingQuickAddTargetRow.length
            ? bindingQuickAddTargetRow
            : $('#binding-rows .binding-row').filter(function () {
                return !$(this).find('.binding-mat-select').val();
            }).first();
        if (!row.length) {
            addBindingRow();
            row = $('#binding-rows .binding-row').last();
        }
        row.find('.binding-mat-select').val(String(materialId));
        if (rate !== undefined && rate !== null && rate !== '') {
            row.find('.binding-mat-rate').val(rate);
        }
        if (unit) {
            row.find('.binding-mat-unit').val(unit);
        }
        calcBindingRow(row);
        bindingQuickAddTargetRow = null;
    }

    function addBindingRow() {
        const tmpl = document.getElementById('binding-row-template');
        $('#binding-rows').append(tmpl.content.cloneNode(true));
        const row = $('#binding-rows .binding-row').last();
        initBindingFilterSelects(row);
        refreshLucide();
    }

    $(document).on('change', '.binding-filter-stock, .binding-filter-color', function () {
        applyBindingFilters($(this).closest('.binding-row'));
    });

    $(document).on('click', '.remove-binding-row', function () {
        $(this).closest('tr').remove();
        calcBindingTotals();
    });

    $(document).on('change', '.binding-mat-select', function () {
        const row = $(this).closest('tr');
        const selected = $(this).find(':selected');
        const rate = selected.data('rate') || '';
        const unit = selected.data('unit') || '';
        row.find('.binding-mat-rate').val(rate);
        row.find('.binding-mat-unit').val(unit);
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

    $(document).on('click', '#add-consumable-row', function () { addConsumableRow(); });
    $(document).on('click', '.remove-consumable-row', function () {
        $(this).closest('.consumable-row').remove();
        updateConsumablesTotal();
    });
    $(document).on('change', '.consumable-stock-type', function () {
        refreshConsumableRowOptions($(this).closest('.consumable-row'));
    });
    $(document).on('change', '.consumable-mat-select', function () {
        const row = $(this).closest('.consumable-row');
        const opt = row.find('.consumable-mat-select option:selected');
        row.find('.consumable-mat-rate').val(opt.data('rate') || 0);
        row.find('.consumable-mat-unit').val(opt.data('unit') || '');
        const qty = parseFloat(row.find('.consumable-mat-qty').val()) || 0;
        row.find('.consumable-mat-total').val(formatCurrency(qty * (parseFloat(opt.data('rate')) || 0)));
        updateConsumablesTotal();
    });
    $(document).on('input', '.consumable-mat-qty, .consumable-mat-rate, #cost_consumables_misc', function () {
        const row = $(this).closest('.consumable-row');
        if (row.length) {
            const qty = parseFloat(row.find('.consumable-mat-qty').val()) || 0;
            const rate = parseFloat(row.find('.consumable-mat-rate').val()) || 0;
            row.find('.consumable-mat-total').val(formatCurrency(qty * rate));
        }
        updateConsumablesTotal();
    });

    // =====================
    // PRE-PRESS LABOUR
    // =====================
    function addPrepressRow() {
        const tmpl = document.getElementById('prepress-row-template');
        if (!tmpl) {
            return;
        }
        $('#prepress-rows').append(tmpl.content.cloneNode(true));
        refreshLucide();
    }

    function appendPrepressTaskOption(taskId, name, rate, unit) {
        const label = unit ? name + ' (' + unit + ')' : name;
        const optHtml = '<option value="' + taskId + '" data-name="' + name + '" data-rate="' + (rate || '') + '" data-unit="' + (unit || 'hrs') + '">' + label + '</option>';
        $('.prepress-task-select').append(optHtml);
        const tmpl = document.getElementById('prepress-row-template');
        if (tmpl) {
            const sel = tmpl.content.querySelector('.prepress-task-select');
            const opt = document.createElement('option');
            opt.value = taskId;
            opt.textContent = label;
            opt.setAttribute('data-name', name);
            opt.setAttribute('data-rate', rate || '');
            opt.setAttribute('data-unit', unit || 'hrs');
            sel.appendChild(opt);
        }
    }

    function selectPrepressTaskInForm(taskId, name, rate, unit) {
        let row = $('#prepress-rows .prepress-row').filter(function () {
            return !$(this).find('.prepress-task-select').val();
        }).first();
        if (!row.length) {
            addPrepressRow();
            row = $('#prepress-rows .prepress-row').last();
        }
        row.find('.prepress-task-select').val(String(taskId));
        row.find('.prepress-name').val(name || '');
        row.find('.prepress-unit').val(unit || 'hrs');
        if (rate !== undefined && rate !== null && rate !== '') {
            row.find('.prepress-rate').val(rate);
        }
        calcPrepressRow(row);
    }

    function calcPrepressRow(row) {
        const hrs = parseFloat(row.find('.prepress-hrs').val()) || 0;
        const rate = parseFloat(row.find('.prepress-rate').val()) || 0;
        row.find('.prepress-total').val(formatCurrency(hrs * rate));
        calcPrepressTotals();
    }

    $(document).on('click', '#add-prepress-row', function () {
        addPrepressRow();
    });

    $(document).on('click', '.remove-prepress-row', function () {
        $(this).closest('tr').remove();
        calcPrepressTotals();
    });

    $(document).on('change', '.prepress-task-select', function () {
        const row = $(this).closest('tr');
        const selected = $(this).find(':selected');
        row.find('.prepress-name').val(selected.data('name') || '');
        row.find('.prepress-unit').val(selected.data('unit') || 'hrs');
        row.find('.prepress-rate').val(selected.data('rate') || '');
        calcPrepressRow(row);
    });

    $(document).on('input', '.prepress-hrs, .prepress-rate', function () {
        calcPrepressRow($(this).closest('tr'));
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

    function pressTaskOptionsHtml() {
        const tmpl = document.getElementById('press-task-options');
        if (!tmpl) {
            return '<option value="">Select Machine</option>';
        }
        return tmpl.innerHTML;
    }

    function appendPressTaskOption(taskId, name, makeReadyRate, runningRate) {
        const optHtml = '<option value="' + taskId + '" data-name="' + name + '" data-mr-rate="' + (makeReadyRate || '') + '" data-run-rate="' + (runningRate || '') + '">' + name + '</option>';
        $('.press-task-select').append(optHtml);
        const tmpl = document.getElementById('press-task-options');
        if (tmpl) {
            const opt = document.createElement('option');
            opt.value = taskId;
            opt.textContent = name;
            opt.setAttribute('data-name', name);
            opt.setAttribute('data-mr-rate', makeReadyRate || '');
            opt.setAttribute('data-run-rate', runningRate || '');
            tmpl.content.appendChild(opt);
        }
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
                <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">Machine</label>
                <select name="press_task_id[]" class="w-full border-gray-300 rounded-lg press-task-select">
                    ${pressTaskOptionsHtml()}
                </select>
                <input type="hidden" name="press_machine_name[]" class="press-machine-name" value="">
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

    $(document).on('change', '.press-task-select', function () {
        const block = $(this).closest('.machine-block');
        const selected = $(this).find(':selected');
        block.find('.press-machine-name').val(selected.data('name') || '');
        block.find('.press-mr-rate').val(selected.data('mr-rate') || '');
        block.find('.press-run-rate').val(selected.data('run-rate') || '');
        calcPressBlock(block);
    });

    function calcPressBlock(block) {
        const mrHrs = parseFloat(block.find('.press-mr-hrs').val()) || 0;
        const mrRate = parseFloat(block.find('.press-mr-rate').val()) || 0;
        block.find('.press-mr-total').val(formatCurrency(mrHrs * mrRate));

        const impressions = parseFloat(block.find('.press-impressions').val()) || 0;
        const iph = parseFloat(block.find('.press-iph').val()) || 0;
        const runHrs = iph > 0 ? impressions / iph : (parseFloat(block.find('.press-run-hrs').val()) || 0);
        if (iph > 0) {
            block.find('.press-run-hrs').val(runHrs.toFixed(2));
        }
        const runRate = parseFloat(block.find('.press-run-rate').val()) || 0;
        block.find('.press-run-total').val(formatCurrency(runHrs * runRate));
        calcPressTotals();
    }

    $(document).on('click', '.remove-machine-btn', function () {
        $(this).closest('.machine-block').remove();
        calcPressTotals();
    });

    $(document).on('input', '.press-mr-hrs, .press-mr-rate', function () {
        calcPressBlock($(this).closest('.machine-block'));
    });

    $(document).on('input', '.press-impressions, .press-iph, .press-run-hrs, .press-run-rate', function () {
        calcPressBlock($(this).closest('.machine-block'));
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
    function addFinishingRow() {
        const tmpl = document.getElementById('finishing-row-template');
        if (!tmpl) {
            return;
        }
        $('#finishing-rows').append(tmpl.content.cloneNode(true));
        refreshLucide();
    }

    function appendFinishingTaskOption(taskId, name, rate, measure, defaultIph) {
        const optHtml = '<option value="' + taskId + '" data-name="' + name + '" data-rate="' + (rate || '') + '" data-measure="' + (measure || 'items') + '" data-iph="' + (defaultIph || '') + '">' + name + '</option>';
        $('.finishing-task-select').append(optHtml);
        const tmpl = document.getElementById('finishing-row-template');
        if (tmpl) {
            const sel = tmpl.content.querySelector('.finishing-task-select');
            const opt = document.createElement('option');
            opt.value = taskId;
            opt.textContent = name;
            opt.setAttribute('data-name', name);
            opt.setAttribute('data-rate', rate || '');
            opt.setAttribute('data-measure', measure || 'items');
            opt.setAttribute('data-iph', defaultIph || '');
            sel.appendChild(opt);
        }
    }

    function selectFinishingTaskInForm(taskId, name, rate, measure, defaultIph) {
        let row = $('#finishing-rows .finishing-row').filter(function () {
            return !$(this).find('.finishing-task-select').val();
        }).first();
        if (!row.length) {
            addFinishingRow();
            row = $('#finishing-rows .finishing-row').last();
        }
        row.find('.finishing-task-select').val(String(taskId));
        row.find('.finishing-name').val(name || '');
        if (measure) {
            row.find('.finishing-measure').val(measure);
        }
        if (defaultIph) {
            row.find('.finishing-iph').val(defaultIph);
        }
        if (rate !== undefined && rate !== null && rate !== '') {
            row.find('.finishing-rate').val(rate);
        }
        calcFinishingRow(row);
    }

    function calcFinishingRow(row) {
        const impressions = parseFloat(row.find('.finishing-impressions').val()) || 0;
        const iph = parseFloat(row.find('.finishing-iph').val()) || 0;
        const rate = parseFloat(row.find('.finishing-rate').val()) || 0;
        const hrs = iph > 0 ? impressions / iph : (parseFloat(row.find('.finishing-hrs').val()) || 0);
        if (iph > 0) {
            row.find('.finishing-hrs').val(hrs.toFixed(2));
        }
        row.find('.finishing-total').val(formatCurrency(hrs * rate));
        calcFinishingTotals();
    }

    $(document).on('click', '#add-finishing-row', function () {
        addFinishingRow();
    });

    $(document).on('click', '.remove-finishing-row', function () {
        $(this).closest('tr').remove();
        calcFinishingTotals();
    });

    $(document).on('change', '.finishing-task-select', function () {
        const row = $(this).closest('tr');
        const selected = $(this).find(':selected');
        row.find('.finishing-name').val(selected.data('name') || '');
        row.find('.finishing-measure').val(selected.data('measure') || 'items');
        if (selected.data('iph')) {
            row.find('.finishing-iph').val(selected.data('iph'));
        }
        row.find('.finishing-rate').val(selected.data('rate') || '');
        calcFinishingRow(row);
    });

    $(document).on('input', '.finishing-impressions, .finishing-iph, .finishing-hrs, .finishing-rate', function () {
        calcFinishingRow($(this).closest('tr'));
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

    function calculateTotals(opts) {
        opts = opts || {};
        // Paper — read directly, don't call calcPaperTotals (avoids recursion)
        updatePaperTotal();
        // Ink — recompute section total unless caller just finished ink refresh
        if (!opts.skipInkRefresh) {
            refreshInkCosts(false);
        }

        // Materials subtotal (standard cards only)
        let matSubtotal = 0;
        $('.std-calc-total').each(function () { matSubtotal += parseFloat($(this).val()) || 0; });

        // All cost fields - parse formatted currency values
        const paper = parseInkMoney($('#cost_paper').val());
        const ink = parseInkMoney($('#cost_ink').val());
        const binding = parseInkMoney($('#cost_binding').val());
        const labour = parseInkMoney($('#cost_labour_total').val());
        const consumables = parseInkMoney($('#cost_consumables').val()) || parseFloat($('#cost_consumables_misc').val()) || 0;
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

    $('#bindingAddForm').submit(function (e) {
        e.preventDefault();
        $.ajax({
            url: materialSaveUrl,
            method: 'POST',
            data: $(this).serialize(),
            dataType: 'json',
            loaderMessage: 'Saving binding material…',
            success: function (response) {
                if (response.status === 'success') {
                    appendBindingMaterialOption(response.material_id, response.name, response.rate, response.unit);
                    selectBindingMaterialInForm(response.material_id, response.rate, response.unit);
                    updateDraftStatus('Binding material saved to catalog and applied', 'ok');
                    if (typeof window.closeBindingAddModal === 'function') {
                        window.closeBindingAddModal();
                    }
                    $('#bindingAddForm')[0].reset();
                } else {
                    alert('Error: ' + (response.message || 'Could not save material'));
                }
            },
            error: function () { alert('Connection error. Please try again.'); }
        });
    });

    $('#labourAddForm').submit(function (e) {
        e.preventDefault();
        const section = $('#labour_add_section').val();
        $.ajax({
            url: '../labour/save',
            method: 'POST',
            data: $(this).serialize(),
            dataType: 'json',
            loaderMessage: 'Saving labour task…',
            success: function (response) {
                if (response.status === 'success') {
                    if (response.section === 'prepress') {
                        appendPrepressTaskOption(response.task_id, response.name, response.rate, response.unit);
                        selectPrepressTaskInForm(response.task_id, response.name, response.rate, response.unit);
                    } else if (response.section === 'finishing') {
                        appendFinishingTaskOption(
                            response.task_id,
                            response.name,
                            response.rate,
                            response.measure_type,
                            response.default_iph
                        );
                        selectFinishingTaskInForm(
                            response.task_id,
                            response.name,
                            response.rate,
                            response.measure_type,
                            response.default_iph
                        );
                    } else if (response.section === 'press') {
                        appendPressTaskOption(
                            response.task_id,
                            response.name,
                            response.make_ready_rate,
                            response.running_rate
                        );
                        selectPressTaskInForm(
                            response.task_id,
                            response.name,
                            response.make_ready_rate,
                            response.running_rate
                        );
                    }
                    updateDraftStatus('Labour task saved and added to list', 'ok');
                    closeLabourAddModal();
                    $('#labourAddForm')[0].reset();
                    configureLabourAddModal(section);
                } else {
                    alert('Error: ' + response.message);
                }
            },
            error: function () { alert('Connection error. Please try again.'); }
        });
    });

    function syncLabourTaskSelectsFromNames() {
        $('#prepress-rows .prepress-row').each(function () {
            const row = $(this);
            const name = (row.find('.prepress-name').val() || '').trim();
            if (!name) {
                return;
            }
            const match = row.find('.prepress-task-select option').filter(function () {
                return ($(this).data('name') || '') === name;
            }).first();
            if (match.length) {
                row.find('.prepress-task-select').val(match.val());
            }
        });

        $('#finishing-rows .finishing-row').each(function () {
            const row = $(this);
            const name = (row.find('.finishing-name').val() || '').trim();
            if (!name) {
                return;
            }
            const match = row.find('.finishing-task-select option').filter(function () {
                return ($(this).data('name') || '') === name;
            }).first();
            if (match.length) {
                row.find('.finishing-task-select').val(match.val());
            }
        });

        $('.machine-block').each(function () {
            const block = $(this);
            const name = (block.find('.press-machine-name').val() || '').trim();
            if (!name) {
                return;
            }
            const match = block.find('.press-task-select option').filter(function () {
                return ($(this).data('name') || '') === name;
            }).first();
            if (match.length) {
                block.find('.press-task-select').val(match.val());
            }
        });
    }

    function selectPressTaskInForm(taskId, name, makeReadyRate, runningRate) {
        let block = $('.machine-block').filter(function () {
            return !$(this).find('.press-task-select').val();
        }).first();
        if (!block.length) {
            addMachineBlock();
            block = $('.machine-block').last();
        }
        block.find('.press-task-select').val(String(taskId));
        block.find('.press-machine-name').val(name || '');
        if (makeReadyRate !== undefined && makeReadyRate !== null && makeReadyRate !== '') {
            block.find('.press-mr-rate').val(makeReadyRate);
        }
        if (runningRate !== undefined && runningRate !== null && runningRate !== '') {
            block.find('.press-run-rate').val(runningRate);
        }
        calcPressBlock(block);
    }

    function syncUnsavedBaseline() {
        var estimationForm = document.getElementById('estimationForm');
        if (estimationForm && window.FormUnsavedGuard) {
            window.FormUnsavedGuard.resetBaseline(estimationForm);
        }
    }

    document.addEventListener('form-unsaved-discarded', function (event) {
        if (event.detail && event.detail.action === 'reload') {
            clearAllDraftStorage();
            try {
                localStorage.removeItem('estimation_draft_v4');
            } catch (storageError) {
                /* best-effort */
            }
            return;
        }
        calculateTotals();
    });

    window.addEventListener('online', function () {
        updateDraftStatus('Back online — syncing draft…', 'ok');
        flushPendingServerSync();
        if (window.SessionGuard) {
            SessionGuard.ping();
        }
    });

    window.addEventListener('offline', function () {
        updateDraftStatus('Offline — saved on this device', 'warn');
        persistLocallyImmediate(true);
    });

    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'hidden') {
            flushOnPageExit();
        }
    });

    window.addEventListener('pagehide', function () {
        flushOnPageExit();
    });

    // Initialize after all helpers exist (avoids TDZ crashes that blank restore).
    initStdMaterialCards();
    initInkBrandFilter();
    initPaperEntries();
    initInkColourRows();
    initMachineRows();

    if ($('#binding-rows .binding-row').length === 0) {
        addBindingRow();
    }
    if ($('#consumable-rows .consumable-row').length === 0) {
        addConsumableRow();
    }
    if ($('#prepress-rows .prepress-row').length === 0) {
        addPrepressRow();
    }
    if ($('#finishing-rows .finishing-row').length === 0) {
        addFinishingRow();
    }

    bindDraftConflictActions();
    bindDraftVersionHistory();
    bindLogoutDraftFlush();

    resolveAndLoadDraft().then(function () {
        showStep(currentStep);
        syncUnsavedBaseline();
        setTimeout(syncUnsavedBaseline, 0);

        autoSaveTimer = setInterval(function () {
            autosaveDraft(false);
        }, autoSaveInterval);

        if (conflictModalOpen) {
            // Wait for the user to resolve the conflict before syncing.
        } else if (pendingServerSync || syncAfterRestore) {
            flushPendingServerSync();
        }

        if (window.SessionGuard && wizardConfig.endpoints) {
            SessionGuard.init({
                pingUrl: endpoints.sessionPing,
                reauthUrl: endpoints.reauth,
                userEmail: wizardConfig.userEmail || '',
                pingInterval: 150000,
                onSessionExpired: function () {
                    updateDraftStatus('Session expired — sign in to sync', 'error');
                },
                onSessionRestored: function () {
                    updateDraftStatus('Signed in — syncing draft…', 'ok');
                    flushPendingServerSync();
                },
            });
        }
    }).catch(function (err) {
        console.error('Draft restore failed:', err);
        showStep(currentStep);
        updateDraftStatus('Could not restore draft — start fresh or reload', 'error');
    });

    window.estimationWizardApply = {
        setImpressions: function (impressions) {
            let field = $('.press-impressions').first();
            if (!field.length) {
                addMachineBlock();
                field = $('.press-impressions').first();
            }
            field.val(Math.round(toNum(impressions)));
            field.trigger('input');
            showStep(6);
            field[0].scrollIntoView({ behavior: 'smooth', block: 'center' });
        },
        setSheets: function (sheets) {
            let field = $('input[name="paper_sheets[]"]').first();
            if (!field.length) {
                addPaperEntry('', true);
                field = $('input[name="paper_sheets[]"]').first();
            }
            field.val(Math.round(toNum(sheets)));
            field.trigger('input');
            showStep(3);
            field[0].scrollIntoView({ behavior: 'smooth', block: 'center' });
        },
        setInkInputs: function (data) {
            data = data || {};
            if (data.baseMm != null) {
                $('input[name="ink_measure_base"]').val(data.baseMm);
            }
            if (data.heightMm != null) {
                $('input[name="ink_height"]').val(data.heightMm);
            }
            if (data.pages != null) {
                $('input[name="ink_pages"]').val(data.pages);
            }
            if (data.quantity != null) {
                $('input[name="ink_quantity_copies"]').val(data.quantity);
            }
            refreshInkCosts(true);
            showStep(4);
            const anchor = document.querySelector('input[name="ink_measure_base"]');
            if (anchor) {
                anchor.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
        },
        setPressHours: function (data) {
            data = data || {};
            let block = $('.machine-block').first();
            if (!block.length) {
                addMachineBlock();
                block = $('.machine-block').first();
            }
            if (data.impressions != null) {
                block.find('.press-impressions').val(Math.round(toNum(data.impressions)));
            }
            if (data.iph != null) {
                block.find('.press-iph').val(data.iph);
            }
            if (data.makeReadyHrs != null) {
                block.find('.press-mr-hrs').val(data.makeReadyHrs);
                block.find('.press-mr-hrs').trigger('input');
            }
            block.find('.press-impressions').trigger('input');
            showStep(6);
            block[0].scrollIntoView({ behavior: 'smooth', block: 'center' });
        },
        goToStep: showStep,
    };

    function toNum(value) {
        const n = parseFloat(value);
        return Number.isFinite(n) ? n : 0;
    }

    refreshLucide();

    window.openPaperQuickAddModal = function (entry, focusField) {
        paperQuickAddTargetEntry = entry && entry.length ? entry : $('.paper-entry').first();
        const target = paperQuickAddTargetEntry;
        $('#paper_add_stock_type').val(target.find('.paper-stock-type').val() || target.find('.paper-stock-type-hidden').val() || '');
        $('#paper_add_color').val(target.find('.paper-color-select').val() || target.find('.paper-color-hidden').val() || '');
        $('#paper_add_grammage').val(target.find('.paper-grammage-select').val() || target.find('.paper-grammage-hidden').val() || '');
        $('#paper_add_dimensions').val(target.find('.paper-dimensions-select').val() || target.find('.paper-size-hidden').val() || '');
        $('#paper_add_rate').val(target.find('.paper-rate').val() || '');
        updatePaperAddNamePreview();
        $('#paperAddModal').removeClass('hidden');
        const focusMap = {
            stock_type: '#paper_add_stock_type',
            color: '#paper_add_color',
            grammage: '#paper_add_grammage',
            dimensions: '#paper_add_dimensions',
        };
        if (focusField && focusMap[focusField]) {
            setTimeout(function () {
                $(focusMap[focusField]).trigger('focus');
            }, 50);
        }
        refreshLucide();
    };

    window.closePaperQuickAddModal = function () {
        $('#paperAddModal').addClass('hidden');
        paperQuickAddTargetEntry = null;
    };

    window.openBindingAddModal = function (resetTarget) {
        if (resetTarget) {
            bindingQuickAddTargetRow = null;
        }
        if (!bindingQuickAddTargetRow || !bindingQuickAddTargetRow.length) {
            bindingQuickAddTargetRow = $('#binding-rows .binding-row').filter(function () {
                return !$(this).find('.binding-mat-select').val();
            }).first();
            if (!bindingQuickAddTargetRow.length) {
                bindingQuickAddTargetRow = $('#binding-rows .binding-row').last();
            }
        }
        $('#bindingAddModal').removeClass('hidden');
        refreshLucide();
    };

    window.closeBindingAddModal = function () {
        $('#bindingAddModal').addClass('hidden');
        bindingQuickAddTargetRow = null;
    };
});

// Global modal functions
function configureLabourAddModal(section) {
    section = section || 'prepress';
    $('#labour_add_section').val(section);

    const titles = {
        prepress: 'New Pre-press Task',
        press: 'New Press Machine',
        finishing: 'New Finishing Task',
    };
    $('#labourAddModalTitle').text(titles[section] || 'New Labour Task');
    $('#labour_add_name_label').text(section === 'press' ? 'Machine Name *' : 'Task Name *');

    const isFinishing = section === 'finishing';
    const isPress = section === 'press';
    $('#labour_add_measure_wrap').toggleClass('hidden', !isFinishing);
    $('#labour_add_iph_wrap').toggleClass('hidden', !isFinishing);
    $('#labour_add_rate_wrap').toggleClass('hidden', isPress);
    $('#labour_add_press_rates_wrap').toggleClass('hidden', !isPress);
    $('#labour_add_rate_wrap input[name="rate"]').prop('required', !isPress);
}

function openLabourAddModal(section) {
    configureLabourAddModal(section);
    $('#labourAddModal').removeClass('hidden');
    if (typeof window.refreshAppShellIcons === 'function') {
        window.refreshAppShellIcons();
    }
}

function closeLabourAddModal() {
    $('#labourAddModal').addClass('hidden');
}
