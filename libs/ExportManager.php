<?php
/**
 * Export Manager
 * Handles PDF and Excel exports for the ERP system
 */

require_once __DIR__ . '/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Writer\Csv as CsvWriter;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Alignment;

class ExportManager {
    
    /**
     * Export data to PDF
     * 
     * @param array $data Array of records to export
     * @param array $columns Column definitions ['key' => 'Label']
     * @param string $title Document title
     * @param string $filename Output filename (without extension)
     * @param array $options Additional options (orientation, pageSize, etc)
     */
    public static function exportToPDF($data, $columns, $title, $filename, $options = []) {
        // Default options
        $orientation = $options['orientation'] ?? 'L'; // L=Landscape, P=Portrait
        $pageSize = $options['pageSize'] ?? 'A4';
        $fontSize = $options['fontSize'] ?? 9;
        $includeDate = $options['includeDate'] ?? true;
        
        // Create new PDF document
        $pdf = new TCPDF($orientation, PDF_UNIT, $pageSize, true, 'UTF-8', false);
        
        // Set document information
        $pdf->SetCreator('Press ERP');
        $pdf->SetAuthor('Press ERP System');
        $pdf->SetTitle($title);
        $pdf->SetSubject($title);
        
        // Set margins
        $pdf->SetMargins(10, 15, 10);
        $pdf->SetHeaderMargin(5);
        $pdf->SetFooterMargin(10);
        
        // Set auto page breaks
        $pdf->SetAutoPageBreak(TRUE, 15);
        
        // Add a page
        $pdf->AddPage();
        
        // Set font
        $pdf->SetFont('helvetica', 'B', 16);
        
        // Title
        $pdf->Cell(0, 10, $title, 0, 1, 'C');
        
        // Date
        if ($includeDate) {
            $pdf->SetFont('helvetica', '', 10);
            $pdf->Cell(0, 6, 'Generated: ' . date('F d, Y H:i:s'), 0, 1, 'C');
        }
        
        $pdf->Ln(5);
        
        // Table header
        $pdf->SetFont('helvetica', 'B', $fontSize);
        $pdf->SetFillColor(41, 128, 185);
        $pdf->SetTextColor(255, 255, 255);
        
        // Calculate column widths
        $tableWidth = $pdf->getPageWidth() - 20; // Minus margins
        $columnCount = count($columns);
        $columnWidth = $tableWidth / $columnCount;
        
        // Header row
        foreach ($columns as $key => $label) {
            $pdf->Cell($columnWidth, 8, $label, 1, 0, 'L', 1);
        }
        $pdf->Ln();
        
        // Table data
        $pdf->SetFont('helvetica', '', $fontSize);
        $pdf->SetTextColor(0, 0, 0);
        $fill = 0;
        
        foreach ($data as $row) {
            $pdf->SetFillColor(245, 245, 245);
            foreach ($columns as $key => $label) {
                $value = $row[$key] ?? '-';
                // Format dates
                if (strpos($key, 'date') !== false && !empty($value) && $value !== '-') {
                    $value = date('M d, Y', strtotime($value));
                }
                $pdf->Cell($columnWidth, 7, $value, 1, 0, 'L', $fill);
            }
            $pdf->Ln();
            $fill = !$fill;
        }
        
        // Output PDF
        if (ob_get_length()) ob_end_clean();
        $pdf->Output($filename . '.pdf', 'D'); // D = Download
    }
    
    /**
     * Export data to Excel (XLSX)
     * 
     * @param array $data Array of records to export
     * @param array $columns Column definitions ['key' => 'Label']
     * @param string $title Sheet title
     * @param string $filename Output filename (without extension)
     * @param array $options Additional options
     */
    public static function exportToExcel($data, $columns, $title, $filename, $options = []) {
        $includeDate = $options['includeDate'] ?? true;
        
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle(substr($title, 0, 31)); // Excel sheet name limit
        
        $row = 1;
        
        // Add title
        $sheet->setCellValue('A' . $row, $title);
        $sheet->mergeCells('A' . $row . ':' . self::getColumnLetter(count($columns)) . $row);
        $sheet->getStyle('A' . $row)->getFont()->setBold(true)->setSize(14);
        $sheet->getStyle('A' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $row++;
        
        // Add date
        if ($includeDate) {
            $sheet->setCellValue('A' . $row, 'Generated: ' . date('F d, Y H:i:s'));
            $sheet->mergeCells('A' . $row . ':' . self::getColumnLetter(count($columns)) . $row);
            $sheet->getStyle('A' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $row++;
        }
        
        $row++; // Empty row
        
        // Header row
        $col = 'A';
        foreach ($columns as $key => $label) {
            $sheet->setCellValue($col . $row, $label);
            $sheet->getStyle($col . $row)->getFont()->setBold(true);
            $sheet->getStyle($col . $row)->getFill()
                ->setFillType(Fill::FILL_SOLID)
                ->getStartColor()->setRGB('2980B9');
            $sheet->getStyle($col . $row)->getFont()->getColor()->setRGB('FFFFFF');
            $sheet->getStyle($col . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
            $col++;
        }
        $row++;
        
        // Data rows
        foreach ($data as $record) {
            $col = 'A';
            foreach ($columns as $key => $label) {
                $value = $record[$key] ?? '';
                
                // Format dates
                if (strpos($key, 'date') !== false && !empty($value)) {
                    $sheet->setCellValue($col . $row, date('M d, Y', strtotime($value)));
                } else {
                    $sheet->setCellValue($col . $row, $value);
                }
                
                $col++;
            }
            $row++;
        }
        
        // Auto-size columns
        foreach (range('A', self::getColumnLetter(count($columns))) as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
        
        // Add borders
        $lastCol = self::getColumnLetter(count($columns));
        $lastRow = $row - 1;
        $sheet->getStyle('A4:' . $lastCol . $lastRow)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        
        // Output file
        if (ob_get_length()) ob_end_clean();
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment;filename="' . $filename . '.xlsx"');
        header('Cache-Control: max-age=0');
        
        $writer = new Xlsx($spreadsheet);
        $writer->save('php://output');
        exit;
    }
    
    /**
     * Export data to CSV
     * 
     * @param array $data Array of records to export
     * @param array $columns Column definitions ['key' => 'Label']
     * @param string $filename Output filename (without extension)
     */
    public static function exportToCSV($data, $columns, $filename) {
        if (ob_get_length()) ob_end_clean();
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment;filename="' . $filename . '.csv"');
        header('Cache-Control: max-age=0');
        
        $output = fopen('php://output', 'w');
        
        // Header row
        fputcsv($output, array_values($columns));
        
        // Data rows
        foreach ($data as $record) {
            $row = [];
            foreach ($columns as $key => $label) {
                $value = $record[$key] ?? '';
                // Format dates
                if (strpos($key, 'date') !== false && !empty($value)) {
                    $value = date('M d, Y', strtotime($value));
                }
                $row[] = $value;
            }
            fputcsv($output, $row);
        }
        
        fclose($output);
        exit;
    }
    
    /**
     * Get Excel column letter from index (0-based)
     */
    private static function getColumnLetter($index) {
        $letter = '';
        while ($index > 0) {
            $index--;
            $letter = chr($index % 26 + 65) . $letter;
            $index = floor($index / 26);
        }
        return $letter ?: 'A';
    }
}
