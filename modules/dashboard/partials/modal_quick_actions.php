<?php
/**
 * Dashboard partial - Quick Actions modal body.
 *
 * Component id: dashboard.modal.quick_actions
 * The links here are largely permission-gated but otherwise static. Refresh
 * is hooked to focus / modal-open so role/permission changes propagate.
 */
?>
<div class="todo-modal-body"
     data-ajax-component="dashboard.modal.quick_actions"
     data-ajax-refresh-on="modal-open:wsModalQuickActions"
     data-ajax-stale="60000">
    <p class="dashboard-action-intro">
        Shortcuts for the most common actions in the workspace.
    </p>
    <div class="dashboard-action-grid">
    <?php if (hasPermission('view_estimations')): ?>
        <a href="<?php echo BASE_URL; ?>modules/estimations/create"
            class="dashboard-action-link"
            style="--card-accent:#0f766e; --card-surface:rgba(15, 118, 110, 0.12);">
            <span class="dashboard-action-top">
                <span class="dashboard-action-icon"><i data-lucide="plus" aria-hidden="true"></i></span>
                <span class="dashboard-action-pill">Create</span>
            </span>
            <span class="dashboard-action-copy">
                <span class="dashboard-action-title">New Estimation</span>
                <span class="dashboard-action-desc">Start pricing and scope work for a new request.</span>
            </span>
            <span class="dashboard-action-arrow">Create estimation <i data-lucide="arrow-right" class="text-sm w-4 h-4" aria-hidden="true"></i></span>
        </a>
    <?php endif; ?>

    <?php if (hasPermission('view_invoices')): ?>
        <a href="<?php echo BASE_URL; ?>modules/invoices/create"
            class="dashboard-action-link"
            style="--card-accent:#2563eb; --card-surface:rgba(37, 99, 235, 0.12);">
            <span class="dashboard-action-top">
                <span class="dashboard-action-icon"><i data-lucide="receipt" aria-hidden="true"></i></span>
                <span class="dashboard-action-pill">Create</span>
            </span>
            <span class="dashboard-action-copy">
                <span class="dashboard-action-title">Create Invoice</span>
                <span class="dashboard-action-desc">Convert approved work into a billable document.</span>
            </span>
            <span class="dashboard-action-arrow">Create invoice <i data-lucide="arrow-right" class="text-sm w-4 h-4" aria-hidden="true"></i></span>
        </a>
    <?php endif; ?>

    <?php if (hasPermission('manage_projects') || hasPermission('view_projects')): ?>
        <a href="<?php echo BASE_URL; ?>modules/projects/create"
            data-action-modal="project.create"
            class="dashboard-action-link"
            style="--card-accent:#0f766e; --card-surface:rgba(15, 118, 110, 0.12);">
            <span class="dashboard-action-top">
                <span class="dashboard-action-icon"><i data-lucide="folder-plus" aria-hidden="true"></i></span>
                <span class="dashboard-action-pill">Quick modal</span>
            </span>
            <span class="dashboard-action-copy">
                <span class="dashboard-action-title">New Project</span>
                <span class="dashboard-action-desc">Open a structured delivery workspace with milestones.</span>
            </span>
            <span class="dashboard-action-arrow">Open quick form <i data-lucide="arrow-right" class="text-sm w-4 h-4" aria-hidden="true"></i></span>
        </a>
    <?php endif; ?>

    <?php if (hasPermission('manage_tasks') || hasPermission('view_tasks')): ?>
        <a href="<?php echo BASE_URL; ?>modules/tasks/create"
            data-action-modal="task.create"
            class="dashboard-action-link"
            style="--card-accent:#0d9488; --card-surface:rgba(13, 148, 136, 0.12);">
            <span class="dashboard-action-top">
                <span class="dashboard-action-icon"><i data-lucide="list-plus" aria-hidden="true"></i></span>
                <span class="dashboard-action-pill">Quick modal</span>
            </span>
            <span class="dashboard-action-copy">
                <span class="dashboard-action-title">New Task</span>
                <span class="dashboard-action-desc">Break active work into trackable assignments.</span>
            </span>
            <span class="dashboard-action-arrow">Open quick form <i data-lucide="arrow-right" class="text-sm w-4 h-4" aria-hidden="true"></i></span>
        </a>
    <?php endif; ?>

    <a href="<?php echo BASE_URL; ?>modules/reminders/index?hub=calendar"
        data-action-modal="reminder.create"
        class="dashboard-action-link"
        style="--card-accent:#7c3aed; --card-surface:rgba(124, 58, 237, 0.12);">
        <span class="dashboard-action-top">
            <span class="dashboard-action-icon"><i data-lucide="calendar-plus" aria-hidden="true"></i></span>
            <span class="dashboard-action-pill">Quick modal</span>
        </span>
        <span class="dashboard-action-copy">
            <span class="dashboard-action-title">New Reminder</span>
            <span class="dashboard-action-desc">Schedule a personal reminder without opening the calendar hub.</span>
        </span>
        <span class="dashboard-action-arrow">Open quick form <i data-lucide="arrow-right" class="text-sm w-4 h-4" aria-hidden="true"></i></span>
    </a>

    <?php if (hasPermission('view_materials')): ?>
        <a href="<?php echo BASE_URL; ?>modules/materials/list"
            class="dashboard-action-link"
            style="--card-accent:#15803d; --card-surface:rgba(21, 128, 61, 0.12);">
            <span class="dashboard-action-top">
                <span class="dashboard-action-icon"><i data-lucide="package" aria-hidden="true"></i></span>
                <span class="dashboard-action-pill">Review</span>
            </span>
            <span class="dashboard-action-copy">
                <span class="dashboard-action-title">Manage Materials</span>
                <span class="dashboard-action-desc">Review stock levels and material movement fast.</span>
            </span>
            <span class="dashboard-action-arrow">Open materials <i data-lucide="arrow-right" class="text-sm w-4 h-4" aria-hidden="true"></i></span>
        </a>
    <?php endif; ?>

    <?php if (hasPermission('view_projects')): ?>
        <a href="<?php echo BASE_URL; ?>modules/projects/list"
            class="dashboard-action-link"
            style="--card-accent:#ea580c; --card-surface:rgba(234, 88, 12, 0.12);">
            <span class="dashboard-action-top">
                <span class="dashboard-action-icon"><i data-lucide="folder-open" aria-hidden="true"></i></span>
                <span class="dashboard-action-pill">Browse</span>
            </span>
            <span class="dashboard-action-copy">
                <span class="dashboard-action-title">All Projects</span>
                <span class="dashboard-action-desc">Review timelines, status and project health in one place.</span>
            </span>
            <span class="dashboard-action-arrow">View projects <i data-lucide="arrow-right" class="text-sm w-4 h-4" aria-hidden="true"></i></span>
        </a>
    <?php endif; ?>

    <?php if (hasPermission('view_tasks')): ?>
        <a href="<?php echo BASE_URL; ?>modules/tasks/list?my_tasks=1"
            class="dashboard-action-link"
            style="--card-accent:#0891b2; --card-surface:rgba(8, 145, 178, 0.12);">
            <span class="dashboard-action-top">
                <span class="dashboard-action-icon"><i data-lucide="user-round" aria-hidden="true"></i></span>
                <span class="dashboard-action-pill">Personal</span>
            </span>
            <span class="dashboard-action-copy">
                <span class="dashboard-action-title">My Tasks</span>
                <span class="dashboard-action-desc">Jump straight into your active assignments.</span>
            </span>
            <span class="dashboard-action-arrow">View my tasks <i data-lucide="arrow-right" class="text-sm w-4 h-4" aria-hidden="true"></i></span>
        </a>
    <?php endif; ?>
    </div>
</div>
