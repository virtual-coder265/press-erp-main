<?php
/**
 * Legacy login entry point — canonical login is modules/auth/login.
 */
require_once __DIR__ . '/config/app.php';

$query = $_SERVER['QUERY_STRING'] ?? '';
$target = 'modules/auth/login';
if ($query !== '') {
    $target .= '?' . $query;
}

redirect($target);
