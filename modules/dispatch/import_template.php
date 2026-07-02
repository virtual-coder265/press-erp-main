<?php
require_once __DIR__ . '/../../config/app.php';
checkAuth();

// Set headers for CSV download
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="dispatch_register_template.csv"');
header('Pragma: no-cache');
header('Expires: 0');

// Create output stream
$output = fopen('php://output', 'w');

// Add BOM for UTF-8 (helps Excel recognize UTF-8)
fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

// Write header row
$headers = [
    'Work Order Number',
    'Date In',
    'Ministry/Department',
    'Job Description',
    'Remarks',
    'Quantity',
    'Date Out',
    'Delivery Note Number',
    'Authorised Dispatcher'
];
fputcsv($output, $headers);

// Write sample data rows
$sample_rows = [
    [
        'WO-2024-001',
        date('Y-m-d'),
        'Ministry of Education',
        'Printing of examination papers',
        'Work Order complete',
        '500',
        date('Y-m-d', strtotime('+7 days')),
        'DN-2024-001',
        'John Doe'
    ],
    [
        'WO-2024-002',
        date('Y-m-d', strtotime('+1 day')),
        'Department of Health',
        'Printing of health brochures',
        'Held',
        '1000',
        '',
        '',
        'Jane Smith'
    ],
    [
        '',
        date('Y-m-d', strtotime('+2 days')),
        'Ministry of Finance',
        'Annual report printing',
        'Need attention',
        '200',
        date('Y-m-d', strtotime('+10 days')),
        'DN-2024-002',
        ''
    ]
];

foreach ($sample_rows as $row) {
    fputcsv($output, $row);
}

fclose($output);
exit;
?>

