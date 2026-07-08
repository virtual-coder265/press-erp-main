<?php
/**
 * Estimation Download Endpoint
 *
 * Builds a PDF using the existing estimation print template and streams it
 * back with a `Content-Disposition: attachment` header so browsers always
 * trigger a real download instead of an inline view.
 *
 * Use the extensionless URL (`.../estimations/download?id=X`) so the
 * .htaccess rewrite handles routing correctly.
 */

require_once __DIR__ . '/../../config/app.php';
checkAuth();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/permissions_helper.php';
permissions_require_one_of(['view_estimations']);
require_once __DIR__ . '/../../vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($id <= 0) {
    redirect('modules/estimations/list');
}

$stmt = $pdo->prepare("SELECT * FROM estimations WHERE id = :id");
$stmt->execute(['id' => $id]);
$est = $stmt->fetch();

if (!$est) {
    http_response_code(404);
    die('Estimation not found.');
}

$stmtItems = $pdo->prepare("SELECT * FROM estimation_items WHERE estimation_id = :id ORDER BY id");
$stmtItems->execute(['id' => $id]);
$items = $stmtItems->fetchAll();

// Render the shared print template into an HTML string. The template wraps
// browser-only controls (Print / Close buttons) in a `.no-print` block so we
// strip that out before handing the markup to dompdf.
ob_start();
require __DIR__ . '/../../templates/estimation_print_template.php';
$html = ob_get_clean();
$html = preg_replace('/<div\s+class="no-print"[^>]*>.*?<\/div>/is', '', (string) $html);

$options = new Options();
$options->set('isHtml5ParserEnabled', true);
$options->set('isRemoteEnabled', true);
$options->set('defaultFont', 'DejaVu Sans');
$options->set('defaultMediaType', 'print');

$dompdf = new Dompdf($options);
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();

$filename = 'estimation_' . preg_replace('/[^A-Za-z0-9_\-]/', '_', (string) $est['estimation_number']) . '.pdf';
$dompdf->stream($filename, ['Attachment' => true]);
exit;
