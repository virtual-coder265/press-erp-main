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
    const autoSaveInterval = 15000;
    let autoSaveTimer = null;
    let lastAutoSaveTime = null;
    let localSaveTimer = null;
    let serverSaveTimer = null;
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
    const DEFAULT_PREPRESS_ROWS = 7;
    const DEFAULT_FINISHING_ROWS = 8;
    const endpoints = wizardConfig.endpoints || {
        saveDraft: 'save_draft',
        discardDraft: 'discard_draft',
        sessionPing: (wizardConfig.baseUrl || '') + 'modules/auth/session_ping',
        reauth: (wizardConfig.baseUrl || '') + 'modules/auth/reauth',
    };

    function getDraftEstId() {
        const fromWindow = window.draftEstId || wizardConfig.draftEstId;
        const fromInput = $('#est_id').val();
        return fromWindow || fromInput || null;
    }

    function getDraftStorageKey(estId) {
        const userId = wizardConfig.userId || 'anonymous';
        if (estId === null) {
            return 'estimation_draft:' + userId + ':active';
        }
        const id = estId !== undefined ? estId : getDraftEstId();
        if (id) {
            return 'estimation_draft:' + userId + ':' + id;
        }
        return 'estimation_draft:' + userId + ':active';
    }

    function getDraftStorageKeys() {
        const userId = wizardConfig.userId || 'anonymous';
        const estId = getDraftEstId();
        if (estId && (draftMode || wizardConfig.draftEstId)) {
            return [getDraftStorageKey(estId)];
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
            return FormDraftStore.remove(key);
        });
        FormDraftStore.clearPointer();
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
            materialRowCount: $('#material-rows .material-row').length,
            bindingRowCount: $('#binding-rows .binding-row').length,
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

    function applyFields(fields) {
        if (!fields || typeof fields !== 'object') {
            return;
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
        (structure.paperTypes && structure.paperTypes.length ? structure.paperTypes : defaultPaperTypes)
            .forEach(function (type, idx) {
                addPaperEntry(type, idx === 0);
            });

        $('#ink-colour-rows').empty();
        (structure.inkColours && structure.inkColours.length ? structure.inkColours : defaultColours)
            .forEach(function (colour) {
                addInkColourRow(colour);
            });

        $('#material-rows').empty();
        for (let i = 0; i < (structure.materialRowCount || 1); i++) {
            addMaterialRow();
        }

        $('#binding-rows').empty();
        for (let i = 0; i < (structure.bindingRowCount || 1); i++) {
            addBindingRow();
        }

        $('#press-machines').empty();
        for (let i = 0; i < (structure.machineBlockCount || 1); i++) {
            addMachineBlock();
        }

        const prepressTarget = structure.prepressRowCount || DEFAULT_PREPRESS_ROWS;
        while ($('#prepress-rows .prepress-row').length < prepressTarget) {
            const tmpl = document.getElementById('prepress-row-template');
            if (tmpl) {
                $('#prepress-rows').append(tmpl.content.cloneNode(true));
            } else {
                break;
            }
        }

        const finishingTarget = structure.finishingRowCount || DEFAULT_FINISHING_ROWS;
        while ($('#finishing-rows .finishing-row').length < finishingTarget) {
            const tmpl = document.getElementById('finishing-row-template');
            if (tmpl) {
                $('#finishing-rows').append(tmpl.content.cloneNode(true));
            } else {
                break;
            }
        }

        refreshLucide();
    }

    function inferStructureFromFields(fields) {
        const count = function (key) {
            return Array.isArray(fields[key]) ? fields[key].length : (fields[key] ? 1 : 0);
        };
        return {
            paperTypes: Array.isArray(fields.paper_type) ? fields.paper_type : defaultPaperTypes,
            inkColours: Array.isArray(fields.ink_colour) ? fields.ink_colour : defaultColours,
            materialRowCount: Math.max(1, count('material_id') - 4),
            bindingRowCount: Math.max(1, count('binding_mat_id')),
            machineBlockCount: Math.max(1, count('press_machine_name')),
            prepressRowCount: Math.max(DEFAULT_PREPRESS_ROWS, count('prepress_name')),
            finishingRowCount: Math.max(DEFAULT_FINISHING_ROWS, count('finishing_name')),
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
        const saves = [FormDraftStore.save(primaryKey, snapshot)];
        const estId = getDraftEstId();
        if (estId) {
            saves.push(FormDraftStore.save(getDraftStorageKey(estId), snapshot));
        }
        return Promise.all(saves).then(function () {
            FormDraftStore.setPointer({
                estId: snapshot.meta.estId,
                step: snapshot.meta.step,
                updatedAt: snapshot.meta.updatedAt,
                userId: wizardConfig.userId,
            });
            if (pendingSync || !navigator.onLine) {
                updateDraftStatus('Saved on this device · waiting to sync', 'warn');
            } else if (localBaseRevision > 0) {
                updateDraftStatus('Synced · rev ' + localBaseRevision, 'ok');
            } else {
                updateDraftStatus('Saved on this device', 'ok');
            }
        });
    }

    function persistLocallySync(pendingSync) {
        if (!window.FormDraftStore || !wizardConfig.userId) {
            return null;
        }
        const snapshot = buildSnapshot(pendingSync);
        FormDraftStore.saveSync(getDraftStorageKey(), snapshot);
        const estId = getDraftEstId();
        if (estId) {
            FormDraftStore.saveSync(getDraftStorageKey(estId), snapshot);
        }
        FormDraftStore.setPointer({
            estId: snapshot.meta.estId,
            step: snapshot.meta.step,
            updatedAt: snapshot.meta.updatedAt,
            userId: wizardConfig.userId,
        });
        return snapshot;
    }

    function persistLocallyDebounced() {
        clearTimeout(localSaveTimer);
        localSaveTimer = setTimeout(function () {
            persistLocallyImmediate(!navigator.onLine || pendingServerSync);
        }, 800);
        clearTimeout(serverSaveTimer);
        serverSaveTimer = setTimeout(function () {
            autosaveDraft(false);
        }, 4000);
    }

    function buildServerDraftPayload(saveAction) {
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
        if (chosen.structure) {
            rebuildFromStructure(chosen.structure);
        } else if (chosen.fields) {
            rebuildFromStructure(inferStructureFromFields(chosen.fields));
        }
        applyFields(chosen.fields);
        // Legacy drafts without ink_calc_mode used manual kgs — keep breakdown mode.
        try {
            var restoredInkMode = chosen.fields && chosen.fields.ink_calc_mode
                ? chosen.fields.ink_calc_mode
                : (chosen.fields && chosen.fields.ink_colour ? INK_MODE_BREAKDOWN : getInkCalcMode());
            setInkCalcMode(restoredInkMode, { skipRecalc: true });
        } catch (inkModeErr) {
            console.warn('Ink mode restore skipped:', inkModeErr);
        }
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
        calculateTotals();
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

    function autosaveDraft(forceSync, saveAction) {
        if (conflictModalOpen && saveAction !== 'override' && saveAction !== 'clone') {
            return Promise.resolve();
        }

        if (
            !forceSync
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

        if (!saveAction && syncAfterRestore) {
            saveAction = 'recovered';
            syncAfterRestore = false;
        }
        saveAction = saveAction || 'autosave';

        const currentFingerprint = formFingerprint();
        if (
            !forceSync
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

        const payload = buildServerDraftPayload(saveAction);
        const fetchFn = window.SessionGuard ? SessionGuard.authFetch.bind(SessionGuard) : fetch;
        const originalEstId = getDraftEstId();

        const request = fetchFn(endpoints.saveDraft, {
            method: 'POST',
            body: payload,
            credentials: 'same-origin',
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

    // Navigation
    $('.next-step').click(function () {
        if (validateStep(currentStep)) {
            persistLocallyImmediate(!navigator.onLine || pendingServerSync);
            autosaveDraft(false);
            currentStep++;
            showStep(currentStep);
        }
    });

    $('.prev-step').click(function () {
        persistLocallyImmediate(!navigator.onLine || pendingServerSync);
        autosaveDraft(false);
        currentStep--;
        showStep(currentStep);
    });

    $('form#estimationForm').on('change input', function () {
        calculateTotals();
        persistLocallyDebounced();
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

        if (mode !== INK_MODE_BREAKDOWN) {
            $('#ink_kgs').val(formulaKgs.toFixed(4));
        }

        let totalCost = 0;

        if (mode === INK_MODE_FORMULA) {
            const rate = parseFloat($('#ink_overall_rate').val()) || 0;
            totalCost = formulaKgs * rate;
            $('#ink-colour-warning').addClass('hidden');
        } else {
            $('.ink-colour-row').each(function () {
                const row = $(this);
                const rate = parseFloat(row.find('.ink-colour-rate').val()) || 0;
                let kgs = parseFloat(row.find('.ink-colour-kgs').val()) || 0;

                if (mode === INK_MODE_FORMULA_BREAKDOWN) {
                    const pct = parseFloat(row.find('.ink-colour-pct').val()) || 0;
                    kgs = formulaKgs * (pct / 100);
                    row.find('.ink-colour-kgs').val(kgs > 0 ? kgs.toFixed(4) : '');
                }

                const rowTotal = kgs * rate;
                row.find('.ink-colour-total').val(formatCurrency(rowTotal));
                totalCost += rowTotal;
            });
            validateInkColours();
        }

        $('#cost_ink').val(formatCurrency(totalCost));

        if (triggerTotals !== false) {
            calculateTotals();
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
        // Ink — always refresh cost_ink from formula and/or breakdown (no recursion)
        refreshInkCosts(false);

        // Materials subtotal (standard cards + dynamic rows)
        let matSubtotal = 0;
        $('.material-total').each(function () { matSubtotal += parseFloat($(this).val()) || 0; });
        $('.std-calc-total').each(function () { matSubtotal += parseFloat($(this).val()) || 0; });

        // All cost fields - parse formatted currency values
        const paper = parseInkMoney($('#cost_paper').val());
        const ink = parseInkMoney($('#cost_ink').val());
        const binding = parseInkMoney($('#cost_binding').val());
        const labour = parseInkMoney($('#cost_labour_total').val());
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
    initPaperEntries();
    initInkColourRows();
    initMachineRows();

    if ($('#material-rows .material-row').length === 0) {
        addMaterialRow();
    }
    if ($('#binding-rows .binding-row').length === 0) {
        addBindingRow();
    }

    bindDraftConflictActions();
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
});

// Global modal functions
function openQuickAddModal() { $('#quickAddModal').removeClass('hidden'); }
function closeQuickAddModal() { $('#quickAddModal').addClass('hidden'); }
function openBindingAddModal() { $('#bindingAddModal').removeClass('hidden'); }
function closeBindingAddModal() { $('#bindingAddModal').addClass('hidden'); }
