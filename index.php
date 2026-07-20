<?php
require_once __DIR__ . '/config/app.php';

if (isset($_SESSION['user_id'])) {
    require_once __DIR__ . '/includes/dashboard_landing_helper.php';
    redirect(dashboard_default_landing_path());
}

redirect('modules/auth/login');
