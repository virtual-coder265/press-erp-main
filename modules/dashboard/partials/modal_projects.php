<?php
/**
 * Dashboard partial - Projects modal body.
 *
 * Component id: dashboard.modal.projects
 * Required context: $recentProjects
 */
?>
<div class="todo-modal-body"
     data-ajax-component="dashboard.modal.projects"
     data-ajax-refresh-on="modal-open:wsModalProjects,action:project.create"
     data-ajax-stale="20000">
    <p style="font-size:12px;color:#605e5c;margin:-4px 0 0;">
        Recent projects with status, progress and deadline details.
    </p>
    <?php if (hasPermission('view_projects') && !empty($recentProjects)): ?>
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <?php foreach ($recentProjects as $proj):
            $progress = $proj['task_count'] > 0 ? round(($proj['completed_tasks'] / $proj['task_count']) * 100) : 0;
            $statusColors = [
                'In Progress' => 'bg-emerald-50 text-emerald-700',
                'Completed'   => 'bg-green-100 text-green-700',
                'On Hold'     => 'bg-yellow-100 text-yellow-700',
                'Cancelled'   => 'bg-red-100 text-red-700',
                'Planning'    => 'bg-teal-50 text-teal-700',
            ];
            $statusColor = $statusColors[$proj['status']] ?? 'bg-gray-100 text-gray-700';
            $priorityColors = ['High' => 'text-red-500', 'Medium' => 'text-emerald-600', 'Low' => 'text-green-500'];
            $priorityColor = $priorityColors[$proj['priority']] ?? 'text-gray-400';
            $progressBarColor = $progress >= 100 ? 'bg-green-500' : ($progress >= 50 ? 'bg-emerald-500' : 'bg-amber-400');
        ?>
        <a href="<?php echo BASE_URL; ?>modules/projects/view?id=<?php echo $proj['id']; ?>"
           class="block border border-gray-100 rounded-lg p-4 hover:shadow-md transition-shadow hover:border-emerald-200">
            <div class="flex items-start justify-between gap-2 mb-3">
                <p class="font-semibold text-gray-800 text-sm leading-snug line-clamp-2 flex-1">
                    <?php echo htmlspecialchars($proj['name']); ?>
                </p>
                <span class="text-xs px-2 py-0.5 rounded-full font-medium whitespace-nowrap <?php echo $statusColor; ?>">
                    <?php echo htmlspecialchars($proj['status']); ?>
                </span>
            </div>
            <div class="flex items-center gap-3 text-xs text-gray-500 mb-3">
                <span class="flex items-center gap-1">
                    <i data-lucide="flag" class="text-xs <?php echo $priorityColor; ?> w-3 h-3" aria-hidden="true"></i>
                    <?php echo htmlspecialchars($proj['priority'] ?? 'Normal'); ?>
                </span>
                <span class="flex items-center gap-1">
                    <i data-lucide="circle-check" class="text-xs w-3 h-3" aria-hidden="true"></i>
                    <?php echo $proj['completed_tasks']; ?>/<?php echo $proj['task_count']; ?> tasks
                </span>
                <?php if (!empty($proj['deadline'])): ?>
                <span class="flex items-center gap-1">
                    <i data-lucide="calendar" class="text-xs w-3 h-3" aria-hidden="true"></i>
                    <?php echo date('M j', strtotime($proj['deadline'])); ?>
                </span>
                <?php endif; ?>
            </div>
            <div class="w-full bg-gray-100 rounded-full h-1.5">
                <div class="<?php echo $progressBarColor; ?> h-1.5 rounded-full transition-all"
                     style="width: <?php echo $progress; ?>%"></div>
            </div>
            <p class="text-xs text-gray-400 mt-1"><?php echo $progress; ?>% complete</p>
        </a>
        <?php endforeach; ?>
    </div>
    <?php else: ?>
        <div class="todo-empty"><i data-lucide="folder-open" class="dashboard-lucide-lg" aria-hidden="true"></i><p>No projects yet.</p></div>
    <?php endif; ?>
</div>
