<script>
document.addEventListener('DOMContentLoaded', function() {
    function refreshLucide() {
        if (typeof window.refreshAppShellIcons === 'function') {
            window.refreshAppShellIcons();
        }
    }

    const projectSelect = document.getElementById('projectSelect');
    const statusSelect = document.getElementById('taskStatus');
    const requireDocumentCheckbox = document.getElementById('requireDocumentSubmission');
    const requireProcedureCheckbox = document.getElementById('requireProcedureTracking');
    const requirementNotice = document.getElementById('requirementNotice');
    const docRequirementNotice = document.getElementById('docRequirementNotice');
    const procedureRequirementNotice = document.getElementById('procedureRequirementNotice');
    const procedureBuilderContainer = document.getElementById('procedureBuilderContainer');
    const statusEvidenceContainer = document.getElementById('statusEvidenceContainer');
    const procedureEvidenceNotice = document.getElementById('procedureEvidenceNotice');
    const documentSubmissionContainer = document.getElementById('documentSubmissionContainer');
    const docFile = document.getElementById('docFile');
    const assigneeSearch = document.getElementById('assigneeSearch');
    const assigneeSearchResults = document.getElementById('assigneeSearchResults');
    const selectedAssigneesContainer = document.getElementById('selectedAssignees');
    const assigneeHiddenInputs = document.getElementById('assigneeHiddenInputs');
    const primaryAssigneeInput = document.getElementById('primaryAssigneeId');
    const legacyAssignedToInput = document.getElementById('legacyAssignedTo');
    const addProcedureStepButton = document.getElementById('addProcedureStep');
    const procedureStepsList = document.getElementById('procedureStepsList');
    const projectDefaultsHint = document.getElementById('projectDefaultsHint');
    const completedStatusOption = document.getElementById('taskStatusCompletedOption');
    const dirtyLockButton = document.querySelector('[data-lock-on-pristine]');
    const taskForm = dirtyLockButton ? dirtyLockButton.closest('form') : null;
    const projectRequirements = <?php echo json_encode($project_requirement_map, JSON_UNESCAPED_SLASHES); ?>;
    const userPickerOptions = <?php echo json_encode($user_picker_options, JSON_UNESCAPED_SLASHES); ?>;
    const mode = <?php echo json_encode($task_form_mode); ?>;
    const originalStatus = <?php echo json_encode($original_status_value ?? 'Not Started'); ?>;
    const currentUserId = Number(<?php echo json_encode((int) ($_SESSION['user_id'] ?? 0)); ?> || 0);
    const lockActionUntilChanged = dirtyLockButton && dirtyLockButton.dataset.lockOnPristine === '1';
    let selectedAssignees = <?php echo json_encode($selected_assignees, JSON_UNESCAPED_SLASHES); ?>;
    let primaryAssigneeId = Number(<?php echo json_encode((int) ($current_primary_assignee_id ?? 0)); ?> || 0);
    let initialTaskFormState = null;

    function getProjectDefaults() {
        return projectRequirements[projectSelect.value] || {
            require_document_submission: 0,
            require_procedure_tracking: 0
        };
    }

    function isActiveStatus(status) {
        return status && status !== 'Not Started';
    }

    function shouldRequireStatusEvidence() {
        if (!statusSelect) {
            return false;
        }

        if (mode === 'edit') {
            return statusSelect.value !== originalStatus;
        }

        return isActiveStatus(statusSelect.value);
    }

    function renderSelectedAssignees() {
        selectedAssigneesContainer.innerHTML = '';
        assigneeHiddenInputs.innerHTML = '';

        if (!selectedAssignees.length) {
            selectedAssigneesContainer.innerHTML = '<div class="rounded-xl border border-dashed border-gray-300 bg-gray-50 px-4 py-3 text-sm text-gray-500">No team members selected yet. Add one or more people above.</div>';
            primaryAssigneeId = 0;
            primaryAssigneeInput.value = '';
            legacyAssignedToInput.value = '';
            syncDirtyLockButton();
            refreshLucide();
            return;
        }

        if (!selectedAssignees.some(function(user) { return Number(user.id) === Number(primaryAssigneeId); })) {
            primaryAssigneeId = Number(selectedAssignees[0].id);
        }

        selectedAssignees.forEach(function(user) {
            const wrapper = document.createElement('div');
            const isPrimary = Number(user.id) === Number(primaryAssigneeId);
            wrapper.className = 'rounded-xl border ' + (isPrimary ? 'border-blue-300 bg-blue-50' : 'border-gray-200 bg-white') + ' p-4';
            wrapper.innerHTML =
                '<div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">' +
                    '<div class="min-w-0">' +
                        '<div class="font-semibold text-gray-800 truncate">' + escapeHtml(user.name) + '</div>' +
                        '<div class="text-xs text-gray-500 truncate">' + escapeHtml(user.email || '') + '</div>' +
                        '<div class="text-xs mt-1 ' + (isPrimary ? 'text-blue-700' : 'text-gray-500') + '">' +
                            (isPrimary ? 'Primary owner' : 'Collaborator') +
                            (typeof user.open_tasks !== 'undefined' ? ' · ' + Number(user.open_tasks) + ' open tasks' : '') +
                        '</div>' +
                    '</div>' +
                    '<div class="flex items-center gap-2">' +
                        '<button type="button" class="mark-primary inline-flex items-center gap-1 rounded-lg px-3 py-2 text-sm font-semibold ' + (isPrimary ? 'bg-blue-600 text-white' : 'border border-gray-200 text-gray-600 hover:bg-gray-50') + '" data-user-id="' + Number(user.id) + '">' +
                            '<i data-lucide="star" class="text-sm" aria-hidden="true"></i>' +
                            '<span>' + (isPrimary ? 'Primary' : 'Make Primary') + '</span>' +
                        '</button>' +
                        '<button type="button" class="remove-assignee inline-flex items-center justify-center h-10 w-10 rounded-lg border border-red-200 text-red-600 hover:bg-red-50" data-user-id="' + Number(user.id) + '" title="Remove assignee">' +
                            '<i data-lucide="x" class="text-sm" aria-hidden="true"></i>' +
                        '</button>' +
                    '</div>' +
                '</div>';
            selectedAssigneesContainer.appendChild(wrapper);

            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'assignee_ids[]';
            input.value = Number(user.id);
            assigneeHiddenInputs.appendChild(input);
        });

        primaryAssigneeInput.value = primaryAssigneeId || '';
        legacyAssignedToInput.value = primaryAssigneeId || '';
        syncDirtyLockButton();
        refreshLucide();
    }

    function escapeHtml(value) {
        return String(value || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function captureFormState(form) {
        if (!form) {
            return '';
        }

        const parts = [];
        Array.prototype.forEach.call(form.elements, function(element) {
            if (!element.name || element.disabled || element.type === 'submit' || element.type === 'button') {
                return;
            }

            if (element.type === 'file') {
                parts.push(element.name + '=' + (element.files ? element.files.length : 0));
                return;
            }

            if ((element.type === 'checkbox' || element.type === 'radio') && !element.checked) {
                return;
            }

            parts.push(element.name + '=' + element.value);
        });

        return parts.join('||');
    }

    function syncDirtyLockButton() {
        if (!lockActionUntilChanged || !dirtyLockButton || !taskForm) {
            return;
        }

        if (initialTaskFormState === null) {
            return;
        }

        const isDirty = captureFormState(taskForm) !== initialTaskFormState;
        dirtyLockButton.disabled = !isDirty;
        dirtyLockButton.setAttribute('aria-disabled', isDirty ? 'false' : 'true');
        dirtyLockButton.classList.toggle('opacity-60', !isDirty);
        dirtyLockButton.classList.toggle('cursor-not-allowed', !isDirty);

        const label = dirtyLockButton.querySelector('span');
        if (label) {
            label.textContent = isDirty
                ? (dirtyLockButton.dataset.defaultLabel || 'Save')
                : (dirtyLockButton.dataset.lockedLabel || 'Save After Changes');
        }
    }

    function renderSearchResults(query) {
        const normalizedQuery = (query || '').trim().toLowerCase();
        if (!normalizedQuery) {
            assigneeSearchResults.innerHTML = '';
            assigneeSearchResults.classList.add('hidden');
            return;
        }

        const selectedIds = selectedAssignees.map(function(user) { return Number(user.id); });
        const matches = userPickerOptions.filter(function(user) {
            if (selectedIds.includes(Number(user.id))) {
                return false;
            }

            const haystack = (user.name + ' ' + (user.email || '')).toLowerCase();
            return haystack.indexOf(normalizedQuery) !== -1;
        }).slice(0, 8);

        if (!matches.length) {
            assigneeSearchResults.innerHTML = '<div class="px-4 py-3 text-sm text-gray-500">No matching users found.</div>';
            assigneeSearchResults.classList.remove('hidden');
            return;
        }

        assigneeSearchResults.innerHTML = matches.map(function(user) {
            return '' +
                '<button type="button" class="assignee-result flex w-full items-start justify-between gap-3 px-4 py-3 text-left hover:bg-gray-50" data-user-id="' + Number(user.id) + '">' +
                    '<div class="min-w-0">' +
                        '<div class="font-semibold text-gray-800 truncate">' + escapeHtml(user.name) + '</div>' +
                        '<div class="text-xs text-gray-500 truncate">' + escapeHtml(user.email || '') + '</div>' +
                    '</div>' +
                    '<div class="text-xs font-semibold text-gray-500 whitespace-nowrap">' + Number(user.open_tasks || 0) + ' open</div>' +
                '</button>';
        }).join('');
        assigneeSearchResults.classList.remove('hidden');
    }

    function addAssignee(userId) {
        const selectedUser = userPickerOptions.find(function(user) {
            return Number(user.id) === Number(userId);
        });

        if (!selectedUser || selectedAssignees.some(function(user) { return Number(user.id) === Number(userId); })) {
            return;
        }

        selectedAssignees.push(selectedUser);
        if (!primaryAssigneeId) {
            primaryAssigneeId = Number(selectedUser.id);
        }

        assigneeSearch.value = '';
        assigneeSearchResults.innerHTML = '';
        assigneeSearchResults.classList.add('hidden');
        renderSelectedAssignees();
    }

    function updateRequirementUI() {
        const projectDefaults = getProjectDefaults();
        const docEnabled = !!requireDocumentCheckbox.checked;
        const procedureEnabled = !!requireProcedureCheckbox.checked;
        const shouldRequireEvidence = shouldRequireStatusEvidence();
        const canCompleteDirectly = Number(projectDefaults.pm_id || 0) === currentUserId;

        projectDefaultsHint.textContent = 'Project defaults: ' +
            (projectDefaults.require_document_submission ? 'document required' : 'document optional') + ', ' +
            (projectDefaults.require_procedure_tracking ? 'procedure steps required' : 'procedure steps optional') + '. You can override them for this task.';

        if (completedStatusOption) {
            completedStatusOption.disabled = !canCompleteDirectly;
            completedStatusOption.textContent = canCompleteDirectly ? 'Completed' : 'Completed (PM Review Required)';
            if (!canCompleteDirectly && statusSelect && statusSelect.value === 'Completed') {
                statusSelect.value = mode === 'edit' && originalStatus === 'Completed' ? 'Completed' : 'In Review';
            }
        }

        requirementNotice.classList.toggle('hidden', !(docEnabled || procedureEnabled));
        docRequirementNotice.classList.toggle('hidden', !docEnabled);
        procedureRequirementNotice.classList.toggle('hidden', !procedureEnabled);

        procedureBuilderContainer.classList.toggle('hidden', !procedureEnabled);
        statusEvidenceContainer.classList.toggle('hidden', !(shouldRequireEvidence && (docEnabled || procedureEnabled)));
        procedureEvidenceNotice.classList.toggle('hidden', !(shouldRequireEvidence && procedureEnabled));
        documentSubmissionContainer.classList.toggle('hidden', !(shouldRequireEvidence && docEnabled));

        if (docFile) {
            docFile.required = shouldRequireEvidence && docEnabled;
        }

        if (procedureEnabled && !procedureStepsList.children.length) {
            addProcedureStep();
        }

        syncDirtyLockButton();
    }

    function updateProcedureStepLabels() {
        Array.prototype.forEach.call(procedureStepsList.querySelectorAll('.procedure-step-item'), function(stepItem, index) {
            stepItem.dataset.stepIndex = index + 1;
            const badge = stepItem.querySelector('.procedure-step-badge');
            if (badge) {
                badge.textContent = 'Step ' + (index + 1);
            }
        });
    }

    function buildProcedureStepRow(values) {
        const wrapper = document.createElement('div');
        wrapper.className = 'procedure-step-item bg-white border border-blue-100 rounded-xl p-4';
        wrapper.innerHTML =
            '<div class="flex flex-wrap items-center justify-between gap-3 mb-3">' +
                '<div class="inline-flex items-center gap-2">' +
                    '<span class="procedure-step-badge inline-flex items-center px-3 py-1 rounded-full bg-blue-100 text-blue-800 text-sm font-semibold">Step</span>' +
                    '<span class="text-xs text-gray-500">Procedural instruction</span>' +
                '</div>' +
                '<div class="flex items-center gap-2">' +
                    '<button type="button" class="procedure-move-up inline-flex items-center justify-center h-9 w-9 rounded-lg border border-gray-200 text-gray-600 hover:bg-gray-50" title="Move step up">' +
                        '<i data-lucide="arrow-up" class="text-sm" aria-hidden="true"></i>' +
                    '</button>' +
                    '<button type="button" class="procedure-move-down inline-flex items-center justify-center h-9 w-9 rounded-lg border border-gray-200 text-gray-600 hover:bg-gray-50" title="Move step down">' +
                        '<i data-lucide="arrow-down" class="text-sm" aria-hidden="true"></i>' +
                    '</button>' +
                    '<button type="button" class="procedure-remove-step inline-flex items-center justify-center h-9 w-9 rounded-lg border border-red-200 text-red-600 hover:bg-red-50" title="Remove step">' +
                        '<i data-lucide="trash-2" class="text-sm" aria-hidden="true"></i>' +
                    '</button>' +
                '</div>' +
            '</div>' +
            '<div class="space-y-3">' +
                '<input type="text" name="procedure_step_instruction[]" class="w-full px-4 py-3 border border-gray-300 rounded-lg" placeholder="Describe this step clearly" value="' + escapeHtml(values && values.instruction ? values.instruction : '') + '">' +
                '<textarea name="procedure_step_note[]" rows="2" class="w-full px-4 py-3 border border-gray-300 rounded-lg" placeholder="Optional note, checklist, or evidence expectation">' + escapeHtml(values && values.note ? values.note : '') + '</textarea>' +
            '</div>';
        return wrapper;
    }

    function addProcedureStep(values) {
        procedureStepsList.appendChild(buildProcedureStepRow(values || {}));
        updateProcedureStepLabels();
        syncDirtyLockButton();
        refreshLucide();
    }

    if (addProcedureStepButton) {
        addProcedureStepButton.addEventListener('click', function() {
            addProcedureStep();
        });
    }

    if (procedureStepsList) {
        procedureStepsList.addEventListener('click', function(event) {
            const removeButton = event.target.closest('.procedure-remove-step');
            const moveUpButton = event.target.closest('.procedure-move-up');
            const moveDownButton = event.target.closest('.procedure-move-down');
            const stepItem = event.target.closest('.procedure-step-item');

            if (!stepItem) {
                return;
            }

            if (removeButton) {
                stepItem.remove();
                updateProcedureStepLabels();
                syncDirtyLockButton();
                return;
            }

            if (moveUpButton && stepItem.previousElementSibling) {
                procedureStepsList.insertBefore(stepItem, stepItem.previousElementSibling);
                updateProcedureStepLabels();
                syncDirtyLockButton();
                return;
            }

            if (moveDownButton && stepItem.nextElementSibling) {
                procedureStepsList.insertBefore(stepItem.nextElementSibling, stepItem);
                updateProcedureStepLabels();
                syncDirtyLockButton();
            }
        });
    }

    if (selectedAssigneesContainer) {
        selectedAssigneesContainer.addEventListener('click', function(event) {
            const removeButton = event.target.closest('.remove-assignee');
            const primaryButton = event.target.closest('.mark-primary');

            if (removeButton) {
                const userId = Number(removeButton.getAttribute('data-user-id'));
                selectedAssignees = selectedAssignees.filter(function(user) {
                    return Number(user.id) !== userId;
                });

                if (primaryAssigneeId === userId) {
                    primaryAssigneeId = selectedAssignees.length ? Number(selectedAssignees[0].id) : 0;
                }

                renderSelectedAssignees();
                return;
            }

            if (primaryButton) {
                primaryAssigneeId = Number(primaryButton.getAttribute('data-user-id'));
                renderSelectedAssignees();
            }
        });
    }

    if (assigneeSearch) {
        assigneeSearch.addEventListener('input', function() {
            renderSearchResults(assigneeSearch.value);
        });

        assigneeSearch.addEventListener('focus', function() {
            renderSearchResults(assigneeSearch.value);
        });
    }

    if (assigneeSearchResults) {
        assigneeSearchResults.addEventListener('click', function(event) {
            const resultButton = event.target.closest('.assignee-result');
            if (!resultButton) {
                return;
            }

            addAssignee(resultButton.getAttribute('data-user-id'));
        });
    }

    document.addEventListener('click', function(event) {
        if (!event.target.closest('#assigneeSearch') && !event.target.closest('#assigneeSearchResults')) {
            assigneeSearchResults.classList.add('hidden');
        }
    });

    if (projectSelect) {
        projectSelect.addEventListener('change', function() {
            const defaults = getProjectDefaults();
            requireDocumentCheckbox.checked = defaults.require_document_submission === 1;
            requireProcedureCheckbox.checked = defaults.require_procedure_tracking === 1;
            updateRequirementUI();
        });
    }

    if (statusSelect) {
        statusSelect.addEventListener('change', updateRequirementUI);
    }

    if (requireDocumentCheckbox) {
        requireDocumentCheckbox.addEventListener('change', updateRequirementUI);
    }

    if (requireProcedureCheckbox) {
        requireProcedureCheckbox.addEventListener('change', updateRequirementUI);
    }

    if (taskForm) {
        initialTaskFormState = captureFormState(taskForm);
        taskForm.addEventListener('input', syncDirtyLockButton);
        taskForm.addEventListener('change', syncDirtyLockButton);
    }

    renderSelectedAssignees();
    updateProcedureStepLabels();
    updateRequirementUI();
    syncDirtyLockButton();
    refreshLucide();
});
</script>
