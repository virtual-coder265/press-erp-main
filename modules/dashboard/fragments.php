<?php
/**
 * Dashboard fragment endpoint.
 *
 * Serves a single component's HTML partial so that the front-end AJAX
 * component framework (assets/js/ajax-components.js) can swap it in without
 * a full page reload.
 *
 * Request:
 *   GET <BASE_URL>modules/dashboard/fragments?id=<componentId>[&...other params]
 *
 * Behaviour:
 *   - Requires an authenticated session.
 *   - Looks up the component id in dashboard_fragment_registry().
 *   - Optionally enforces a per-component permission slug.
 *   - Builds the same dashboard context that index.php uses, so the rendered
 *     fragment matches what a fresh page render would emit.
 *   - Sends the partial as text/html with no-store caching.
 */

require_once __DIR__ . '/../../config/app.php';
checkAuth();
require_once __DIR__ . '/../../includes/dashboard_partials_helper.php';

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
header('X-Ajax-Component: 1');

$id = trim((string) ($_GET['id'] ?? ''));
$registry = dashboard_fragment_registry();

if ($id === '' || !isset($registry[$id])) {
    http_response_code(404);
    echo '<!-- ajax-component: unknown id ' . htmlspecialchars($id, ENT_QUOTES, 'UTF-8') . ' -->';
    exit;
}

$entry = $registry[$id];
if (!empty($entry['permission']) && !hasPermission($entry['permission'])) {
    http_response_code(403);
    echo '<!-- ajax-component: forbidden -->';
    exit;
}

$viewPath = __DIR__ . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $entry['view']);
if (!is_file($viewPath)) {
    http_response_code(500);
    echo '<!-- ajax-component: missing view ' . htmlspecialchars($entry['view'], ENT_QUOTES, 'UTF-8') . ' -->';
    exit;
}

try {
    $context = dashboard_collect_context($pdo, $_GET);
} catch (Throwable $e) {
    http_response_code(500);
    echo '<!-- ajax-component: context error -->';
    exit;
}

extract($context, EXTR_SKIP);

include $viewPath;
