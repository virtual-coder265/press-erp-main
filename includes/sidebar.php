<?php
require_once __DIR__ . '/branding_helper.php';
$currentPath = $_SERVER['PHP_SELF'] ?? '';
$role = $_SESSION['role'] ?? '';
$userName = $_SESSION['user_name'] ?? 'User';
$userInitial = strtoupper(substr($userName, 0, 1));
$userPhoto = $_SESSION['user_photo'] ?? null;
$departmentName = $_SESSION['department'] ?? 'Government Press';
$appDisplayName = function_exists('get_setting') ? (string) get_setting('system_app_name', APP_NAME) : APP_NAME;
$appTagline = function_exists('get_setting') ? (string) get_setting('system_tagline', 'Operations Suite') : 'Operations Suite';

$isCurrentPath = static function (string $needle) use ($currentPath): bool {
    return strpos($currentPath, $needle) !== false;
};

$linkClass = static function (bool $active = false): string {
    return 'sidebar-link' . ($active ? ' is-active' : '');
};

$toggleClass = static function (bool $active = false): string {
    return 'sidebar-toggle' . ($active ? ' is-active' : '');
};

$sublinkClass = static function (bool $active = false): string {
    return 'sidebar-sublink' . ($active ? ' is-active' : '');
};

$isDashboard = $isCurrentPath('modules/dashboard');
$isEstimations = $isCurrentPath('modules/estimations');
$isSales = $isCurrentPath('modules/sales');
$isInvoices = $isCurrentPath('modules/invoices');
$isMaterials = $isCurrentPath('modules/materials');
$isHr = $isCurrentPath('modules/hr');
$isProductsServices = $isCurrentPath('modules/products') || $isCurrentPath('modules/services');
$isCollaboration = $isCurrentPath('modules/collaboration');
$isProjectsTasks = $isCurrentPath('modules/projects') || $isCurrentPath('modules/tasks') || $isCurrentPath('modules/files') || $isCollaboration;
$isSettings = $isCurrentPath('modules/settings');
$isDispatch = $isCurrentPath('modules/dispatch');
$isAdmin = $isCurrentPath('modules/admin');
$isAdminAudit = $isCurrentPath('modules/admin/audit_center');
$isAdminLoginSlides = $isCurrentPath('modules/admin/login_slides');
?>

<aside id="sidebar" class="app-sidebar flex flex-col h-screen fixed md:sticky top-0 left-0 z-40 md:z-auto">
    <div class="sidebar-brand">
        <a href="<?php echo BASE_URL; ?>modules/dashboard/index" class="sidebar-brand-link">
            <span class="sidebar-brand-mark">
                <img id="sidebar-logo" src="<?php echo htmlspecialchars(system_branding_resolved_url('logo')); ?>" alt="<?php echo htmlspecialchars($appDisplayName); ?>" class="h-9 w-auto object-contain">
            </span>
            <span class="sidebar-brand-title truncate"><?php echo htmlspecialchars($appDisplayName); ?></span>
        </a>
    </div>

    <div class="sidebar-profile-card display-none">
        <?php if (!empty($userPhoto) && $userPhoto !== 'default.png'): ?>
            <img src="<?php echo htmlspecialchars(BASE_URL . ltrim($userPhoto, '/')); ?>" alt="<?php echo htmlspecialchars($userName); ?>" class="sidebar-avatar" style="object-fit:cover;border-radius:1rem;">
        <?php else: ?>
            <span class="sidebar-avatar"><?php echo htmlspecialchars($userInitial); ?></span>
        <?php endif; ?>
        <div class="sidebar-profile-copy min-w-0">
            <p class="text-sm font-bold text-gray-900 truncate"><?php echo htmlspecialchars($userName); ?></p>
            <p class="text-xs text-gray-500 truncate"><?php echo htmlspecialchars($departmentName); ?></p>
            <span class="sidebar-role-pill mt-2"><?php echo htmlspecialchars($role ?: 'User'); ?></span>
        </div>
    </div>

    <nav class="sidebar-nav-scroll flex-1">
        <div class="nav-group">
            <p class="nav-group-title">Overview</p>
            <a href="<?php echo BASE_URL; ?>modules/dashboard/index" class="<?php echo $linkClass($isDashboard); ?>">
                <span class="sidebar-link-group">
                    <span class="sidebar-icon-wrap"><i data-lucide="layout-dashboard" aria-hidden="true"></i></span>
                    <span class="nav-text">Dashboard</span>
                </span>
            </a>
        </div>

        <?php if (hasPermission('view_estimations') || hasPermission('view_invoices') || hasPermission('view_dashboard_revenue')): ?>
            <div class="nav-group">
                <p class="nav-group-title">Commercial</p>

                <?php if (hasPermission('view_estimations')): ?>
                    <button type="button" data-sidebar-toggle="estimations-sub" aria-expanded="<?php echo $isEstimations ? 'true' : 'false'; ?>" class="<?php echo $toggleClass($isEstimations); ?>">
                        <span class="sidebar-link-group">
                            <span class="sidebar-icon-wrap"><i data-lucide="calculator" aria-hidden="true"></i></span>
                            <span class="nav-text">Estimations</span>
                        </span>
                        <i class="text-sm nav-chevron transition-transform duration-200 <?php echo $isEstimations ? 'rotate-180' : ''; ?>" data-lucide="chevron-down" aria-hidden="true"></i>
                    </button>
                    <div id="estimations-sub" class="sidebar-submenu <?php echo $isEstimations ? '' : 'hidden'; ?>">
                        <a href="<?php echo BASE_URL; ?>modules/estimations/create" class="<?php echo $sublinkClass($isCurrentPath('modules/estimations/create')); ?>">New estimation</a>
                        <a href="<?php echo BASE_URL; ?>modules/estimations/list" class="<?php echo $sublinkClass($isCurrentPath('modules/estimations/list') || $isCurrentPath('modules/estimations/view') || $isCurrentPath('modules/estimations/status_dashboard')); ?>">All estimations</a>
                    </div>
                <?php endif; ?>

                <?php if (hasPermission('view_invoices') || hasPermission('view_dashboard_revenue')): ?>
                    <button type="button" data-sidebar-toggle="sales-sub" aria-expanded="<?php echo $isSales ? 'true' : 'false'; ?>" class="<?php echo $toggleClass($isSales); ?>">
                        <span class="sidebar-link-group">
                            <span class="sidebar-icon-wrap"><i data-lucide="wallet" aria-hidden="true"></i></span>
                            <span class="nav-text">Sales and revenue</span>
                        </span>
                        <i class="text-sm nav-chevron transition-transform duration-200 <?php echo $isSales ? 'rotate-180' : ''; ?>" data-lucide="chevron-down" aria-hidden="true"></i>
                    </button>
                    <div id="sales-sub" class="sidebar-submenu <?php echo $isSales ? '' : 'hidden'; ?>">
                        <a href="<?php echo BASE_URL; ?>modules/sales/index" class="<?php echo $sublinkClass($isCurrentPath('modules/sales/index')); ?>">Overview</a>
                        <a href="<?php echo BASE_URL; ?>modules/sales/record_sale" class="<?php echo $sublinkClass($isCurrentPath('modules/sales/record_sale')); ?>">Record sale</a>
                    </div>

                    <button type="button" data-sidebar-toggle="invoices-sub" aria-expanded="<?php echo $isInvoices ? 'true' : 'false'; ?>" class="<?php echo $toggleClass($isInvoices); ?>">
                        <span class="sidebar-link-group">
                            <span class="sidebar-icon-wrap"><i data-lucide="receipt" aria-hidden="true"></i></span>
                            <span class="nav-text">Invoicing</span>
                        </span>
                        <i class="text-sm nav-chevron transition-transform duration-200 <?php echo $isInvoices ? 'rotate-180' : ''; ?>" data-lucide="chevron-down" aria-hidden="true"></i>
                    </button>
                    <div id="invoices-sub" class="sidebar-submenu <?php echo $isInvoices ? '' : 'hidden'; ?>">
                        <a href="<?php echo BASE_URL; ?>modules/invoices/create" class="<?php echo $sublinkClass($isCurrentPath('modules/invoices/create')); ?>">Create invoice</a>
                        <a href="<?php echo BASE_URL; ?>modules/invoices/list" class="<?php echo $sublinkClass($isCurrentPath('modules/invoices/list') || $isCurrentPath('modules/invoices/view')); ?>">Invoice library</a>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <div class="nav-group">
            <p class="nav-group-title">Operations</p>

            <?php if (hasPermission('view_materials')): ?>
                <button type="button" data-sidebar-toggle="materials-sub" aria-expanded="<?php echo $isMaterials ? 'true' : 'false'; ?>" class="<?php echo $toggleClass($isMaterials); ?>">
                    <span class="sidebar-link-group">
                        <span class="sidebar-icon-wrap"><i data-lucide="package" aria-hidden="true"></i></span>
                        <span class="nav-text">Materials</span>
                    </span>
                    <i class="text-sm nav-chevron transition-transform duration-200 <?php echo $isMaterials ? 'rotate-180' : ''; ?>" data-lucide="chevron-down" aria-hidden="true"></i>
                </button>
                <div id="materials-sub" class="sidebar-submenu <?php echo $isMaterials ? '' : 'hidden'; ?>">
                    <a href="<?php echo BASE_URL; ?>modules/materials/list" class="<?php echo $sublinkClass($isCurrentPath('modules/materials/list') || $isCurrentPath('modules/materials/create') || $isCurrentPath('modules/materials/edit')); ?>">Inventory</a>
                    <a href="<?php echo BASE_URL; ?>modules/materials/categories/list" class="<?php echo $sublinkClass($isCurrentPath('modules/materials/categories')); ?>">Categories</a>
                </div>
            <?php endif; ?>

            <button type="button" data-sidebar-toggle="products-services-sub" aria-expanded="<?php echo $isProductsServices ? 'true' : 'false'; ?>" class="<?php echo $toggleClass($isProductsServices); ?>">
                <span class="sidebar-link-group">
                    <span class="sidebar-icon-wrap"><i data-lucide="layers" aria-hidden="true"></i></span>
                    <span class="nav-text">Products and services</span>
                </span>
                <i class="text-sm nav-chevron transition-transform duration-200 <?php echo $isProductsServices ? 'rotate-180' : ''; ?>" data-lucide="chevron-down" aria-hidden="true"></i>
            </button>
            <div id="products-services-sub" class="sidebar-submenu <?php echo $isProductsServices ? '' : 'hidden'; ?>">
                <a href="<?php echo BASE_URL; ?>modules/products/index" class="<?php echo $sublinkClass($isCurrentPath('modules/products')); ?>">Products</a>
                <a href="<?php echo BASE_URL; ?>modules/services/index" class="<?php echo $sublinkClass($isCurrentPath('modules/services')); ?>">Services</a>
            </div>

            <button type="button" data-sidebar-toggle="projects-sub" aria-expanded="<?php echo $isProjectsTasks ? 'true' : 'false'; ?>" class="<?php echo $toggleClass($isProjectsTasks); ?>">
                <span class="sidebar-link-group">
                    <span class="sidebar-icon-wrap"><i data-lucide="folder" aria-hidden="true"></i></span>
                    <span class="nav-text">Projects and tasks</span>
                </span>
                <i class="text-sm nav-chevron transition-transform duration-200 <?php echo $isProjectsTasks ? 'rotate-180' : ''; ?>" data-lucide="chevron-down" aria-hidden="true"></i>
            </button>
            <div id="projects-sub" class="sidebar-submenu <?php echo $isProjectsTasks ? '' : 'hidden'; ?>">
                <a href="<?php echo BASE_URL; ?>modules/projects/list" class="<?php echo $sublinkClass($isCurrentPath('modules/projects')); ?>">Projects</a>
                <a href="<?php echo BASE_URL; ?>modules/tasks/list" class="<?php echo $sublinkClass($isCurrentPath('modules/tasks')); ?>">Tasks</a>
                <a href="<?php echo BASE_URL; ?>modules/collaboration/invitations.php" class="<?php echo $sublinkClass($isCollaboration); ?>">Team invitations</a>
                <?php
                require_once __DIR__ . '/file_management_helper.php';
                if (file_hub_user_can_view()):
                ?>
                    <a href="<?php echo BASE_URL; ?>modules/files/index" class="<?php echo $sublinkClass($isCurrentPath('modules/files')); ?>">Files</a>
                <?php endif; ?>
            </div>

            <a href="<?php echo BASE_URL; ?>modules/dispatch/list" class="<?php echo $linkClass($isDispatch); ?>">
                <span class="sidebar-link-group">
                    <span class="sidebar-icon-wrap"><i data-lucide="truck" aria-hidden="true"></i></span>
                    <span class="nav-text">Dispatch</span>
                </span>
            </a>

            <button type="button" class="sidebar-placeholder" onclick="showToast('Work orders module is coming soon.', 'info');">
                <span class="sidebar-link-group">
                    <span class="sidebar-icon-wrap"><i data-lucide="clipboard-list" aria-hidden="true"></i></span>
                    <span class="nav-text">Work orders</span>
                </span>
            </button>
        </div>

        <?php if (hasPermission('view_users') || hasPermission('manage_settings')): ?>
            <div class="nav-group">
                <p class="nav-group-title">Administration</p>

                <button type="button" data-sidebar-toggle="hr-sub" aria-expanded="<?php echo $isHr ? 'true' : 'false'; ?>" class="<?php echo $toggleClass($isHr); ?>">
                    <span class="sidebar-link-group">
                        <span class="sidebar-icon-wrap"><i data-lucide="id-card" aria-hidden="true"></i></span>
                        <span class="nav-text">HR management</span>
                    </span>
                    <i class="text-sm nav-chevron transition-transform duration-200 <?php echo $isHr ? 'rotate-180' : ''; ?>" data-lucide="chevron-down" aria-hidden="true"></i>
                </button>
                <div id="hr-sub" class="sidebar-submenu <?php echo $isHr ? '' : 'hidden'; ?>">
                    <a href="<?php echo BASE_URL; ?>modules/hr/users/list" class="<?php echo $sublinkClass($isCurrentPath('modules/hr/users')); ?>">Users</a>
                    <a href="<?php echo BASE_URL; ?>modules/hr/departments/list" class="<?php echo $sublinkClass($isCurrentPath('modules/hr/departments')); ?>">Departments</a>
                    <a href="<?php echo BASE_URL; ?>modules/hr/branches/list" class="<?php echo $sublinkClass($isCurrentPath('modules/hr/branches')); ?>">Branches</a>
                    <a href="<?php echo BASE_URL; ?>modules/hr/roles/list" class="<?php echo $sublinkClass($isCurrentPath('modules/hr/roles')); ?>">Roles and permissions</a>
                </div>

                <button type="button" data-sidebar-toggle="settings-sub" aria-expanded="<?php echo $isSettings ? 'true' : 'false'; ?>" class="<?php echo $toggleClass($isSettings); ?>">
                    <span class="sidebar-link-group">
                        <span class="sidebar-icon-wrap"><i data-lucide="settings" aria-hidden="true"></i></span>
                        <span class="nav-text">Settings</span>
                    </span>
                    <i class="text-sm nav-chevron transition-transform duration-200 <?php echo $isSettings ? 'rotate-180' : ''; ?>" data-lucide="chevron-down" aria-hidden="true"></i>
                </button>
                <div id="settings-sub" class="sidebar-submenu <?php echo $isSettings ? '' : 'hidden'; ?>">
                    <a href="<?php echo BASE_URL; ?>modules/settings/index" class="<?php echo $sublinkClass($isCurrentPath('modules/settings/index')); ?>">System and operations</a>
                    <a href="<?php echo BASE_URL; ?>modules/settings/ai" class="<?php echo $sublinkClass($isCurrentPath('modules/settings/ai')); ?>">AI assistant</a>
                    <a href="<?php echo BASE_URL; ?>modules/settings/business" class="<?php echo $sublinkClass($isCurrentPath('modules/settings/business')); ?>">Business profile</a>
                    <a href="<?php echo BASE_URL; ?>modules/settings/mail" class="<?php echo $sublinkClass($isCurrentPath('modules/settings/mail')); ?>">Mail configuration</a>
                    <a href="<?php echo BASE_URL; ?>modules/settings/sms" class="<?php echo $sublinkClass($isCurrentPath('modules/settings/sms')); ?>">SMS configuration</a>
                    <a href="<?php echo BASE_URL; ?>modules/settings/notifications" class="<?php echo $sublinkClass($isCurrentPath('modules/settings/notifications')); ?>">Notification configuration</a>
                    <a href="<?php echo BASE_URL; ?>modules/settings/hero_weather" class="<?php echo $sublinkClass($isCurrentPath('modules/settings/hero_weather')); ?>">Hero weather backgrounds</a>
                </div>

                <?php if (($_SESSION['role'] ?? '') === 'System Admin' || hasPermission('manage_settings') || hasPermission('view_audit_logs') || hasPermission('view_system_health')): ?>
                    <button type="button" data-sidebar-toggle="admin-sub" aria-expanded="<?php echo $isAdmin ? 'true' : 'false'; ?>" class="<?php echo $toggleClass($isAdmin); ?>">
                        <span class="sidebar-link-group">
                            <span class="sidebar-icon-wrap"><i data-lucide="shield" aria-hidden="true"></i></span>
                            <span class="nav-text">Administration</span>
                        </span>
                        <i class="text-sm nav-chevron transition-transform duration-200 <?php echo $isAdmin ? 'rotate-180' : ''; ?>" data-lucide="chevron-down" aria-hidden="true"></i>
                    </button>
                    <div id="admin-sub" class="sidebar-submenu <?php echo $isAdmin ? '' : 'hidden'; ?>">
                        <a href="<?php echo BASE_URL; ?>modules/admin/audit_center" class="<?php echo $sublinkClass($isAdminAudit); ?>">Audit and security</a>
                        <?php if (($_SESSION['role'] ?? '') === 'System Admin' || hasPermission('manage_settings')): ?>
                            <a href="<?php echo BASE_URL; ?>modules/admin/login_slides" class="<?php echo $sublinkClass($isAdminLoginSlides); ?>">Login background slides</a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </nav>

    <div class="sidebar-bottom">
        <div class="nav-group">
            <a href="<?php echo BASE_URL; ?>modules/auth/logout" class="sidebar-logout">
                <span class="sidebar-link-group">
                    <span class="sidebar-icon-wrap"><i data-lucide="log-out" aria-hidden="true"></i></span>
                    <span class="nav-text">Log out</span>
                </span>
            </a>
        </div>
    </div>
</aside>
