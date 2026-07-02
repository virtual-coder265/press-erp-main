<?php
/**
 * Restrict cron scripts to CLI or a secret key from CRON_HTTP_SECRET in .env.
 */

if (!function_exists('cron_require_cli_or_secret')) {
    function cron_require_cli_or_secret(): void
    {
        if (PHP_SAPI === 'cli') {
            return;
        }

        if (!function_exists('env')) {
            require_once dirname(__DIR__) . '/config/env.php';
            loadEnv(dirname(__DIR__) . '/.env');
        }

        $expected = trim((string) env('CRON_HTTP_SECRET', ''));
        if ($expected === '') {
            http_response_code(403);
            header('Content-Type: text/plain; charset=utf-8');
            echo "Access Denied.";
            exit;
        }

        $provided = isset($_GET['key']) ? (string) $_GET['key'] : '';
        if ($provided === '' || !hash_equals($expected, $provided)) {
            http_response_code(403);
            header('Content-Type: text/plain; charset=utf-8');
            echo "Access Denied.";
            exit;
        }
    }
}
