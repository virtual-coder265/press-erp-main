<?php
require_once __DIR__ . '/config/app.php';

// Route to Dashboard if logged in, otherwise Login
if (isset($_SESSION['user_id'])) {
    redirect('modules/dashboard/index');
} else {
    redirect('modules/auth/login');
}
?>
