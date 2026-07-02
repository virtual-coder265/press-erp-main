<?php
$taskIdValue = (int) ($current_task_data['id'] ?? 0);
$selectedProjectId = (int) ($current_task_data['project_id'] ?? 0);
$taskRequireDocument = !empty($current_task_data['require_document_submission']);
$taskRequireProcedure = !empty($current_task_data['require_procedure_tracking']);
$taskStatusValue = $current_task_data['status'] ?? 'Not Started';
$taskPriorityValue = $current_task_data['priority'] ?? 'Medium';
$taskDescriptionValue = $current_task_data['description'] ?? '';
$taskDueDateValue = $current_task_data['due_date'] ?? '';
$taskNameValue = $current_task_data['name'] ?? '';
$procedure_steps = !empty($procedure_steps) ? $procedure_steps : [];
?>

<div class="grid grid-cols-1 gap-6">
    <div>
        <label class="block text-gray-700 font-bold mb-2">Task Name *</label>
        <input type="text" name="name" required
               value="<?php echo htmlspecialchars($taskNameValue); ?>"
               class="w-full px-4 py-3 border border-gray-300 rounded-lg"
               placeholder="Enter task name">
    </div>

    <div>
        <label class="block text-gray-700 font-bold mb-2">Project *</label>
        <select name="project_id" id="projectSelect" required
                class="w-full px-4 py-3 border border-gray-300 rounded-lg">
            <option value="">Select Project</option>
            <?php foreach ($projects as $project): ?>
                <option value="<?php echo (int) $project['id']; ?>" <?php echo $selectedProjectId === (int) $project['id'] ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($project['name']); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <p id="projectDefaultsHint" class="text-xs text-gray-500 mt-2">
            Task requirement toggles below start from the selected project's defaults and can be adjusted for this task.
        </p>
    </div>

    <div>
        <label class="block text-gray-700 font-bold mb-2">Description</label>
        <textarea name="description" rows="4"
                  class="w-full px-4 py-3 border border-gray-300 rounded-lg"
                  placeholder="Describe the task details and expected output"><?php echo htmlspecialchars($taskDescriptionValue); ?></textarea>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
        <div>
            <label class="block text-gray-700 font-bold mb-2">Status *</label>
            <select name="status" id="taskStatus" required
                    class="w-full px-4 py-3 border border-gray-300 rounded-lg">
                <option value="Not Started" <?php echo $taskStatusValue === 'Not Started' ? 'selected' : ''; ?>>Not Started</option>
                <option value="In Progress" <?php echo $taskStatusValue === 'In Progress' ? 'selected' : ''; ?>>In Progress</option>
                <option value="In Review" <?php echo $taskStatusValue === 'In Review' ? 'selected' : ''; ?>>In Review</option>
                <option id="taskStatusCompletedOption" value="Completed" <?php echo $taskStatusValue === 'Completed' ? 'selected' : ''; ?> <?php echo !empty($can_complete_directly) ? '' : 'disabled style="color: grey;"'; ?>>
                    Completed<?php echo !empty($can_complete_directly) ? '' : ' (PM Review Required)'; ?>
                </option>
                <option value="Cancelled" <?php echo $taskStatusValue === 'Cancelled' ? 'selected' : ''; ?>>Cancelled</option>
            </select>
        </div>

        <div>
            <label class="block text-gray-700 font-bold mb-2">Priority *</label>
            <select name="priority" required
                    class="w-full px-4 py-3 border border-gray-300 rounded-lg">
                <option value="Low" <?php echo $taskPriorityValue === 'Low' ? 'selected' : ''; ?>>Low</option>
                <option value="Medium" <?php echo $taskPriorityValue === 'Medium' ? 'selected' : ''; ?>>Medium</option>
                <option value="High" <?php echo $taskPriorityValue === 'High' ? 'selected' : ''; ?>>High</option>
                <option value="Urgent" <?php echo $taskPriorityValue === 'Urgent' ? 'selected' : ''; ?>>Urgent</option>
            </select>
        </div>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
        <div class="space-y-3">
            <label class="block text-gray-700 font-bold">Assign Team Members</label>
            <p class="text-sm text-gray-500">Type to search and select one or more people. Mark one person as the primary owner.</p>
            <div class="relative">
                <input type="text" id="assigneeSearch" autocomplete="off"
                       class="w-full px-4 py-3 border border-gray-300 rounded-lg"
                       placeholder="Search by name or email">
                <div id="assigneeSearchResults" class="hidden absolute z-20 mt-2 w-full rounded-xl border border-gray-200 bg-white shadow-xl max-h-64 overflow-y-auto"></div>
            </div>
            <input type="hidden" name="primary_assignee_id" id="primaryAssigneeId" value="<?php echo (int) ($current_primary_assignee_id ?? 0); ?>">
            <input type="hidden" name="assigned_to" id="legacyAssignedTo" value="<?php echo (int) ($current_primary_assignee_id ?? 0); ?>">
            <div id="assigneeHiddenInputs"></div>
            <div id="selectedAssignees" class="space-y-3"></div>
        </div>

        <div>
            <label class="block text-gray-700 font-bold mb-2" for="taskCreateDueDate">Due Date</label>
            <?php if (!empty($use_native_datetime_fields)): ?>
            <?php echo press_native_datetime_field([
                'name' => 'due_date',
                'id' => 'taskCreateDueDate',
                'value' => (string) $taskDueDateValue,
                'mode' => 'date',
                'disable_past' => $taskIdValue === 0,
                'class' => 'w-full px-4 py-3 border border-gray-300 rounded-lg',
            ]); ?>
            <?php else: ?>
            <?php echo press_datetime_picker_field([
                'name' => 'due_date',
                'value' => (string) $taskDueDateValue,
                'mode' => 'date',
                'disable_past' => $taskIdValue === 0,
                'class' => 'w-full px-4 py-3 border border-gray-300 rounded-lg',
            ]); ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Personal Reminder Settings -->
    <?php
    $reminderModuleAvailable = function_exists('reminder_module_ready') ? reminder_module_ready($pdo) : false;
    if ($reminderModuleAvailable && isset($_SESSION['user_id'])):
        require_once __DIR__ . '/../../includes/reminder_helper.php';
        $alarmOffsetOptions = function_exists('reminder_alarm_offset_options') ? reminder_alarm_offset_options() : ['30' => '30 minutes before'];
        $currentAlarmEnabled = 1; // Default
        $currentAlarmOffset = 30; // Default
        
        if ($taskIdValue > 0) {
            $existingReminder = fetch_task_linked_reminder_for_user($pdo, (int)$_SESSION['user_id'], $taskIdValue);
            if ($existingReminder) {
                $currentAlarmEnabled = (int) ($existingReminder['alarm_enabled'] ?? 1);
                $currentAlarmOffset = (int) ($existingReminder['alarm_offset_minutes'] ?? 30);
            }
        }
    ?>
    <div class="workspace-panel p-4 sm:p-5 bg-indigo-50 border-indigo-200">
        <div class="flex items-start justify-between gap-4 mb-4">
            <div>
                <label class="block text-indigo-800 font-bold mb-1">Personal Reminder Settings</label>
                <p class="text-sm text-indigo-600">Configure how you want to be reminded about this task. This only affects your notifications.</p>
            </div>
            <i data-lucide="bell-ring" class="text-indigo-500" aria-hidden="true"></i>
        </div>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 items-center">
            <label class="flex items-center gap-3 cursor-pointer">
                <input type="hidden" name="my_alarm_enabled" value="0">
                <input type="checkbox" name="my_alarm_enabled" value="1"
                       class="h-5 w-5 text-indigo-600 rounded border-gray-300 focus:ring-indigo-500"
                       <?php echo $currentAlarmEnabled ? 'checked' : ''; ?>>
                <span class="font-semibold text-gray-800">Enable Alarm for this Task</span>
            </label>
            
            <div class="flex items-center gap-3">
                <label class="text-gray-700 font-semibold whitespace-nowrap">Remind me</label>
                <select name="my_alarm_offset_minutes" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:border-indigo-500 focus:ring focus:ring-indigo-200">
                    <?php foreach ($alarmOffsetOptions as $val => $label): ?>
                        <option value="<?php echo htmlspecialchars($val); ?>" <?php echo $currentAlarmOffset == $val ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($label); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="workspace-panel p-4 sm:p-5 bg-slate-50">
        <div class="flex items-start justify-between gap-4 mb-4">
            <div>
                <label class="block text-gray-700 font-bold mb-1">Task Workflow Requirements</label>
                <p class="text-sm text-gray-500">These apply to this task specifically, even if the project has broader defaults.</p>
            </div>
            <span class="text-xs font-semibold uppercase tracking-wide text-slate-500">Task Level</span>
        </div>
        <div class="grid grid-cols-1 gap-3">
            <label class="flex items-start gap-3 p-4 border border-gray-200 rounded-xl bg-white">
                <input type="hidden" name="require_document_submission" value="0">
                <input type="checkbox" name="require_document_submission" id="requireDocumentSubmission" value="1"
                       class="mt-1 h-4 w-4 text-green-600 rounded border-gray-300 focus:ring-green-500"
                       <?php echo $taskRequireDocument ? 'checked' : ''; ?>>
                <div class="min-w-0">
                    <div class="font-semibold text-gray-800">Require supporting document</div>
                    <div class="text-sm text-gray-500">The task must include a status evidence document before progress changes are saved.</div>
                </div>
            </label>
            <label class="flex items-start gap-3 p-4 border border-gray-200 rounded-xl bg-white">
                <input type="hidden" name="require_procedure_tracking" value="0">
                <input type="checkbox" name="require_procedure_tracking" id="requireProcedureTracking" value="1"
                       class="mt-1 h-4 w-4 text-blue-600 rounded border-gray-300 focus:ring-blue-500"
                       <?php echo $taskRequireProcedure ? 'checked' : ''; ?>>
                <div class="min-w-0">
                    <div class="font-semibold text-gray-800">Require structured procedure steps</div>
                    <div class="text-sm text-gray-500">Capture the process as step-by-step instructions instead of a long paragraph.</div>
                </div>
            </label>
        </div>
    </div>

    <div id="requirementNotice" class="hidden workspace-panel p-4 sm:p-5 bg-gray-50">
        <div class="font-semibold text-gray-800 mb-2">Status evidence rules</div>
        <ul class="list-disc list-inside text-sm text-gray-600 space-y-1">
            <li id="docRequirementNotice" class="hidden">A supporting document will be required when this save includes a progress change.</li>
            <li id="procedureRequirementNotice" class="hidden">Current procedure steps will be logged into documentation history when this save includes a progress change.</li>
        </ul>
    </div>

    <div id="procedureBuilderContainer" class="<?php echo $taskRequireProcedure ? '' : 'hidden '; ?>workspace-panel bg-blue-50 border-blue-200 p-4 rounded-lg">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between mb-4">
            <div>
                <label class="block text-blue-800 font-bold">Procedure Steps *</label>
                <p class="text-sm text-blue-600">Build the workflow line by line for cleaner execution and PDF-ready documentation.</p>
            </div>
            <button type="button" id="addProcedureStep" class="inline-flex items-center justify-center gap-1 px-3 py-2 rounded-lg bg-blue-600 text-white hover:bg-blue-700 transition">
                <i data-lucide="plus" class="text-sm" aria-hidden="true"></i>
                <span>Add Step</span>
            </button>
        </div>
        <div id="procedureStepsList" class="space-y-3">
            <?php if (!empty($procedure_steps)): ?>
                <?php foreach ($procedure_steps as $index => $step): ?>
                    <div class="procedure-step-item bg-white border border-blue-100 rounded-xl p-4" data-step-index="<?php echo $index + 1; ?>">
                        <div class="flex flex-wrap items-center justify-between gap-3 mb-3">
                            <div class="inline-flex items-center gap-2">
                                <span class="procedure-step-badge inline-flex items-center px-3 py-1 rounded-full bg-blue-100 text-blue-800 text-sm font-semibold">
                                    Step <?php echo $index + 1; ?>
                                </span>
                                <span class="text-xs text-gray-500">Procedural instruction</span>
                            </div>
                            <div class="flex items-center gap-2">
                                <button type="button" class="procedure-move-up inline-flex items-center justify-center h-9 w-9 rounded-lg border border-gray-200 text-gray-600 hover:bg-gray-50" title="Move step up">
                                    <i data-lucide="arrow-up" class="text-sm" aria-hidden="true"></i>
                                </button>
                                <button type="button" class="procedure-move-down inline-flex items-center justify-center h-9 w-9 rounded-lg border border-gray-200 text-gray-600 hover:bg-gray-50" title="Move step down">
                                    <i data-lucide="arrow-down" class="text-sm" aria-hidden="true"></i>
                                </button>
                                <button type="button" class="procedure-remove-step inline-flex items-center justify-center h-9 w-9 rounded-lg border border-red-200 text-red-600 hover:bg-red-50" title="Remove step">
                                    <i data-lucide="trash-2" class="text-sm" aria-hidden="true"></i>
                                </button>
                            </div>
                        </div>
                        <div class="space-y-3">
                            <input type="text" name="procedure_step_instruction[]"
                                   value="<?php echo htmlspecialchars($step['instruction'] ?? ''); ?>"
                                   class="w-full px-4 py-3 border border-gray-300 rounded-lg"
                                   placeholder="Describe this step clearly">
                            <textarea name="procedure_step_note[]" rows="2"
                                      class="w-full px-4 py-3 border border-gray-300 rounded-lg"
                                      placeholder="Optional note, checklist, or evidence expectation"><?php echo htmlspecialchars($step['note'] ?? ''); ?></textarea>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <div id="statusEvidenceContainer" class="hidden space-y-4">
        <div id="procedureEvidenceNotice" class="hidden workspace-panel bg-blue-50 border-blue-200 p-4 rounded-lg">
            <div class="font-bold text-blue-800 mb-1">Procedure history ready</div>
            <p class="text-sm text-blue-600">The current procedure steps will be captured in the task documentation history when you save this status change.</p>
        </div>

        <div id="documentSubmissionContainer" class="hidden workspace-panel bg-green-50 border-green-200 p-4 rounded-lg">
            <label class="block text-green-800 font-bold mb-2">Status Evidence Document *</label>
            <p class="text-sm text-green-600 mb-2">Upload a JPG, PNG, GIF, WEBP, PDF, DOC, or DOCX file that proves this status update.</p>
            <input type="file" name="documentation_file" id="docFile"
                   accept=".jpg,.jpeg,.png,.gif,.webp,.pdf,.doc,.docx"
                   class="w-full px-4 py-3 border border-gray-300 bg-white rounded-lg">
        </div>
    </div>

    <div class="workspace-panel bg-green-50 border-green-200 p-4 rounded-lg">
        <label class="block text-green-800 font-bold mb-2">Task Attachments</label>
        <p class="text-sm text-green-600 mb-3">Upload any relevant working files, references, or proofs for this task. You can attach multiple files.</p>
        <input type="file" name="task_attachments[]" multiple
               accept=".jpg,.jpeg,.png,.gif,.webp,.pdf,.doc,.docx"
               class="w-full px-4 py-3 border border-gray-300 bg-white rounded-lg">
        <?php if (!empty($general_attachments)): ?>
            <div class="mt-4 space-y-3">
                <?php foreach ($general_attachments as $attachment): ?>
                    <div class="flex flex-col gap-2 rounded-xl border border-green-100 bg-white p-3 sm:flex-row sm:items-center sm:justify-between">
                        <div class="min-w-0">
                            <div class="font-semibold text-gray-800 truncate"><?php echo htmlspecialchars($attachment['original_name']); ?></div>
                            <div class="text-xs text-gray-500">
                                Uploaded by <?php echo htmlspecialchars($attachment['uploader_name']); ?>
                                on <?php echo date('M d, Y H:i', strtotime($attachment['created_at'])); ?>
                            </div>
                        </div>
                        <a href="<?php echo BASE_URL . htmlspecialchars($attachment['file_path']); ?>" target="_blank"
                           class="inline-flex items-center justify-center gap-1 text-sm font-semibold text-blue-600 hover:text-blue-800">
                            <i data-lucide="paperclip" class="text-sm" aria-hidden="true"></i>
                            <span>Open File</span>
                        </a>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>
