<?php
/**
 * PDF Helper Functions
 * 
 * This file contains helper functions for generating PDFs
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * Resolve an image path or URL to a src Dompdf can embed reliably.
 * Local files are returned as base64 data URIs.
 *
 * @param string $path Web-relative path, filesystem path, or http(s) URL
 * @return string|null Embeddable src, or null when the image cannot be resolved
 */
function resolve_pdf_embed_image_src(string $path): ?string
{
    $path = trim($path);
    if ($path === '') {
        return null;
    }

    if (preg_match('#^https?://#i', $path)) {
        return $path;
    }

    $relative = ltrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path), DIRECTORY_SEPARATOR);
    $candidates = [];

    if (defined('ROOT_PATH')) {
        $candidates[] = ROOT_PATH . $relative;
    }
    $candidates[] = dirname(__DIR__) . DIRECTORY_SEPARATOR . $relative;
    if (!empty($_SERVER['DOCUMENT_ROOT'])) {
        $candidates[] = rtrim((string) $_SERVER['DOCUMENT_ROOT'], '/\\') . DIRECTORY_SEPARATOR . $relative;
    }

    foreach ($candidates as $candidate) {
        $resolved = realpath($candidate);
        if (!$resolved || !is_file($resolved)) {
            continue;
        }

        $mime = 'application/octet-stream';
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo !== false) {
                $detected = finfo_file($finfo, $resolved);
                finfo_close($finfo);
                if (is_string($detected) && $detected !== '') {
                    $mime = $detected;
                }
            }
        } elseif (function_exists('mime_content_type')) {
            $detected = @mime_content_type($resolved);
            if (is_string($detected) && $detected !== '') {
                $mime = $detected;
            }
        } else {
            $ext = strtolower(pathinfo($resolved, PATHINFO_EXTENSION));
            $mimeMap = [
                'jpg' => 'image/jpeg',
                'jpeg' => 'image/jpeg',
                'png' => 'image/png',
                'gif' => 'image/gif',
                'webp' => 'image/webp',
            ];
            $mime = $mimeMap[$ext] ?? $mime;
        }

        $data = base64_encode((string) file_get_contents($resolved));
        if ($data === '') {
            continue;
        }

        return 'data:' . $mime . ';base64,' . $data;
    }

    return null;
}

/**
 * Generate a PDF from HTML content
 * 
 * @param string $html The HTML content to convert to PDF
 * @param string $filename The name of the output file (without extension)
 * @param bool $download Whether to force download the PDF (true) or output to browser (false)
 * @return void
 */
function generatePdf($html, $filename = 'document', $download = true) {
    // Set up Dompdf options
    $options = new Options();
    $options->set('isHtml5ParserEnabled', true);
    $options->set('isRemoteEnabled', true);
    $options->set('defaultFont', 'DejaVu Sans');
    
    // Initialize Dompdf
    $dompdf = new Dompdf($options);
    
    // Load HTML content
    $dompdf->loadHtml($html);
    
    // Set paper size and orientation
    $dompdf->setPaper('A4', 'portrait');
    
    // Render the HTML as PDF
    $dompdf->render();
    
    // Output the generated PDF
    if ($download) {
        $dompdf->stream("$filename.pdf", ["Attachment" => true]);
    } else {
        $dompdf->stream("$filename.pdf", ["Attachment" => false]);
    }
}

/**
 * Generate an invoice PDF
 * 
 * @param array $invoice The invoice data
 * @param array $business Business information
 * @param bool $download Whether to force download the PDF (true) or output to browser (false)
 * @param string|null $billing_layout_variant_override Optional variant id for billing previews
 * @return void
 */
function generateInvoicePdf($invoice, $business = [], $download = true, $billing_layout_variant_override = null) {
    // Start output buffering
    ob_start();
    
    // Include the template file
    include __DIR__ . '/../templates/invoice_template.php';
    
    // Get the HTML content
    $html = ob_get_clean();
    
    // Generate PDF
    generatePdf($html, 'invoice_' . $invoice['invoice_number'], $download);
}

/**
 * Generate a sales receipt PDF (one or more payment rows on an invoice).
 *
 * @param array $invoice Invoice row (must include invoice_number)
 * @param array $payments Non-empty list of payment rows
 * @param array $business Unused; settings read in template
 * @param bool $download Attachment disposition
 * @param string|null $billing_layout_variant_override Optional variant id for billing previews
 */
function generateSalesReceiptPdf($invoice, array $payments, $business = [], $download = true, $billing_layout_variant_override = null) {
    if (empty($payments)) {
        throw new InvalidArgumentException('No payments to print on receipt.');
    }
    ob_start();
    include __DIR__ . '/../templates/sales_receipt_template.php';
    $html = ob_get_clean();
    $safeInv = preg_replace('/[^A-Za-z0-9_\-]/', '_', (string) ($invoice['invoice_number'] ?? 'invoice'));
    $suffix = count($payments) === 1 ? '_p' . (int) ($payments[0]['id'] ?? 0) : '_summary';
    generatePdf($html, 'receipt_' . $safeInv . $suffix, $download);
}

/**
 * Get default business information
 * 
 * @return array Business information
 */
function getDefaultBusinessInfo() {
    return [
        'name' => 'Your Company Name',
        'address' => "123 Business Street\nCity, State, ZIP\nCountry",
        'phone' => '+1 (123) 456-7890',
        'email' => 'billing@company.com',
        'website' => 'www.company.com',
        'tax_id' => 'XX-XXXXXXX',
        'terms' => 'Payment is due within 30 days of invoice date. Please include the invoice number in your payment. Late payments are subject to fees of 5% per month on the outstanding balance.'
    ];
}

/**
 * Generate a project documentation PDF
 * 
 * @param array $project The project data
 * @param array $tasks The tasks data
 * @param array $documentation The documentation logs data
 * @param array $business Business information
 * @param bool $download Whether to force download the PDF (true) or output to browser (false)
 * @return void
 */
function generateProjectDocumentationPdf($project, $tasks, $documentation, $business = [], $download = true) {
    // Start output buffering
    ob_start();
    
    // Include the template file
    include __DIR__ . '/../templates/project_docs_template.php';
    
    // Get the HTML content
    $html = ob_get_clean();
    
    // Generate PDF
    $safeName = preg_replace('/[^a-z0-9]/i', '_', $project['name']);
    generatePdf($html, 'project_report_' . $safeName, $download);
}
?>
