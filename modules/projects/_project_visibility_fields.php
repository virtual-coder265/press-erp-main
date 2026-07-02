<?php

declare(strict_types=1);

/**
 * Shared visibility + owning department fields for project create/edit.
 *
 * Expects: $pdo, $project (array with optional visibility_scope, department_id)
 */
if (!isset($pdo) || !isset($project) || !project_visibility_projects_table_ready($pdo)) {
    return;
}

$pv_depts = $pdo->query('SELECT id, name FROM departments ORDER BY name ASC')->fetchAll(PDO::FETCH_ASSOC);
$pv_scope = project_visibility_normalized_scope($project['visibility_scope'] ?? 'department');
$pv_dept_id = (int) ($project['department_id'] ?? 0);
$pv_can_set_public = hasPermission('manage_projects');

?>
<div class="workspace-panel p-4 sm:p-5 bg-slate-50 border border-slate-200 rounded-xl" id="projectVisibilityPanel">
    <label class="block text-gray-700 font-bold mb-2">Visibility &amp; scope</label>
    <p class="text-sm text-gray-500 mb-4">Control who can discover this portfolio record. Public projects are readable org-wide but only the creator edits them. Department/Section projects stay inside the section (including the section head). Private projects are limited to the project team and task participants.</p>
    <div class="grid grid-cols-1 gap-3">
        <label class="flex items-start gap-3 p-4 border border-gray-200 rounded-xl bg-white">
            <input type="radio" name="visibility_scope" value="public" class="mt-1 h-4 w-4 text-green-600"
                   <?php echo $pv_scope === 'public' ? 'checked' : ''; ?>
                   <?php echo $pv_can_set_public ? '' : 'disabled'; ?>>
            <div class="min-w-0">
                <div class="font-semibold text-gray-800">Public</div>
                <div class="text-sm text-gray-500">Anyone with project access can follow along. Changes stay with the project manager or administrators.</div>
            </div>
        </label>
        <label class="flex items-start gap-3 p-4 border border-gray-200 rounded-xl bg-white">
            <input type="radio" name="visibility_scope" value="department" class="mt-1 h-4 w-4 text-green-600 project-visibility-scope-input"
                   <?php echo $pv_scope === 'department' ? 'checked' : ''; ?>>
            <div class="min-w-0">
                <div class="font-semibold text-gray-800">Department / Section</div>
                <div class="text-sm text-gray-500">Visible to people in the selected section. Central management outside the section cannot browse it.</div>
            </div>
        </label>
        <label class="flex items-start gap-3 p-4 border border-gray-200 rounded-xl bg-white">
            <input type="radio" name="visibility_scope" value="private" class="mt-1 h-4 w-4 text-green-600 project-visibility-scope-input"
                   <?php echo $pv_scope === 'private' ? 'checked' : ''; ?>>
            <div class="min-w-0">
                <div class="font-semibold text-gray-800">Private</div>
                <div class="text-sm text-gray-500">Only the team you invite and people assigned to tasks can see or manage this project.</div>
            </div>
        </label>
    </div>
    <div class="mt-4 project-visibility-dept-wrap">
        <label class="block text-gray-700 font-bold mb-2">Owning section / department</label>
        <select name="project_department_id" class="w-full px-4 py-3 border border-gray-300 rounded-lg project-visibility-dept-select">
            <option value="">Select department…</option>
            <?php foreach ($pv_depts as $d): ?>
                <option value="<?php echo (int) $d['id']; ?>" <?php echo $pv_dept_id === (int) $d['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($d['name']); ?></option>
            <?php endforeach; ?>
        </select>
        <p class="text-xs text-gray-500 mt-2">Required for departmental projects. Also used as the organisational anchor for public and private projects.</p>
    </div>
    <?php if (!$pv_can_set_public): ?>
        <p class="text-xs text-amber-700 mt-3">Organisation-wide public projects can only be created by users with the global “Manage projects” permission.</p>
    <?php endif; ?>
</div>
<script>
(function () {
    var panel = document.getElementById('projectVisibilityPanel');
    if (!panel) return;
    function sync() {
        var scopeDept = panel.querySelector('input[name="visibility_scope"][value="department"]');
        var sel = panel.querySelector('.project-visibility-dept-select');
        if (!sel) return;
        sel.required = !!(scopeDept && scopeDept.checked);
    }
    panel.querySelectorAll('input[name="visibility_scope"]').forEach(function (r) {
        r.addEventListener('change', sync);
    });
    sync();
})();
</script>
