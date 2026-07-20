<?php
require_once __DIR__ . '/../../config/app.php';
checkAuth();
redirect('modules/services/index');
