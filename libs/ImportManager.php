<?php
/**
 * Import Manager
 * Handles CSV and Excel imports for the ERP system
 */

require_once __DIR__ . '/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;
use League\Csv\Reader;

class ImportManager {
    
    /**
     * Import data from CSV file
     * 
     * @param string $filePath Path to the CSV file
     * @param array $columnMapping Map CSV columns to database fields ['csv_column' => 'db_field']
     * @param array $options Additional options
     * @return array Array of records or error information
     */
    public static function importFromCSV($filePath, $columnMapping = null, $options = []) {
        try {
            $skipHeader = $options['skipHeader'] ?? true;
            $delimiter = $options['delimiter'] ?? ',';
            
            $csv = Reader::createFromPath($filePath, 'r');
            $csv->setDelimiter($delimiter);
            
            if ($skipHeader) {
                $csv->setHeaderOffset(0);
                $records = $csv->getRecords();
            } else {
                $records = $csv->getRecords();
            }
            
            $data = [];
            $errors = [];
            $rowNum = $skipHeader ? 2 : 1; // Start from 2 if header is skipped
            
            foreach ($records as $record) {
                // If column mapping provided, remap the columns
                if ($columnMapping) {
                    $mappedRecord = [];
                    foreach ($columnMapping as $csvColumn => $dbField) {
                        $mappedRecord[$dbField] = $record[$csvColumn] ?? '';
                    }
                    $data[] = $mappedRecord;
                } else {
                    $data[] = $record;
                }
                $rowNum++;
            }
            
            return [
                'success' => true,
                'data' => $data,
                'count' => count($data),
                'errors' => $errors
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'data' => [],
                'count' => 0
            ];
        }
    }
    
    /**
     * Import data from Excel file (XLSX, XLS)
     * 
     * @param string $filePath Path to the Excel file
     * @param array $columnMapping Map Excel columns to database fields [0 => 'db_field', 1 => 'db_field2']
     * @param array $options Additional options
     * @return array Array of records or error information
     */
    public static function importFromExcel($filePath, $columnMapping = null, $options = []) {
        try {
            $skipHeader = $options['skipHeader'] ?? true;
            $sheetIndex = $options['sheetIndex'] ?? 0;
            
            $spreadsheet = IOFactory::load($filePath);
            $worksheet = $spreadsheet->getSheet($sheetIndex);
            $rows = $worksheet->toArray();
            
            $data = [];
            $errors = [];
            $startRow = $skipHeader ? 1 : 0;
            
            // Get headers if available
            $headers = $skipHeader ? $rows[0] : null;
            
            for ($i = $startRow; $i < count($rows); $i++) {
                $row = $rows[$i];
                
                // Skip empty rows
                if (empty(array_filter($row))) {
                    continue;
                }
                
                // If column mapping provided, remap the columns
                if ($columnMapping) {
                    $mappedRecord = [];
                    foreach ($columnMapping as $excelIndex => $dbField) {
                        $mappedRecord[$dbField] = $row[$excelIndex] ?? '';
                    }
                    $data[] = $mappedRecord;
                } else if ($headers) {
                    // Use headers as keys
                    $mappedRecord = [];
                    foreach ($headers as $index => $header) {
                        $mappedRecord[$header] = $row[$index] ?? '';
                    }
                    $data[] = $mappedRecord;
                } else {
                    $data[] = $row;
                }
            }
            
            return [
                'success' => true,
                'data' => $data,
                'count' => count($data),
                'errors' => $errors,
                'headers' => $headers
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'data' => [],
                'count' => 0
            ];
        }
    }
    
    /**
     * Validate imported data against rules
     * 
     * @param array $data Array of records to validate
     * @param array $rules Validation rules ['field' => ['required', 'email', etc]]
     * @return array Validation results
     */
    public static function validateData($data, $rules) {
        $errors = [];
        $validRecords = [];
        
        foreach ($data as $index => $record) {
            $rowErrors = [];
            $rowNum = $index + 2; // +2 for header row and 1-based indexing
            
            foreach ($rules as $field => $fieldRules) {
                $value = $record[$field] ?? '';
                
                foreach ($fieldRules as $rule) {
                    if ($rule === 'required' && empty($value)) {
                        $rowErrors[] = "Row $rowNum: $field is required";
                    }
                    
                    if ($rule === 'email' && !empty($value) && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                        $rowErrors[] = "Row $rowNum: $field must be a valid email";
                    }
                    
                    if ($rule === 'numeric' && !empty($value) && !is_numeric($value)) {
                        $rowErrors[] = "Row $rowNum: $field must be numeric";
                    }
                    
                    if ($rule === 'date' && !empty($value) && !strtotime($value)) {
                        $rowErrors[] = "Row $rowNum: $field must be a valid date";
                    }
                }
            }
            
            if (empty($rowErrors)) {
                $validRecords[] = $record;
            } else {
                $errors = array_merge($errors, $rowErrors);
            }
        }
        
        return [
            'valid' => empty($errors),
            'validRecords' => $validRecords,
            'errors' => $errors,
            'validCount' => count($validRecords),
            'errorCount' => count($data) - count($validRecords)
        ];
    }
    
    /**
     * Get template for import
     * Creates a template file with predefined columns
     * 
     * @param array $columns Array of column headers
     * @param string $filename Output filename
     * @param string $format 'csv' or 'excel'
     */
    public static function generateTemplate($columns, $filename, $format = 'csv') {
        if ($format === 'csv') {
            header('Content-Type: text/csv');
            header('Content-Disposition: attachment;filename="' . $filename . '.csv"');
            header('Cache-Control: max-age=0');
            
            $output = fopen('php://output', 'w');
            fputcsv($output, $columns);
            fclose($output);
            exit;
        } else {
            require_once __DIR__ . '/ExportManager.php';
            
            $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();
            
            // Add headers
            $col = 'A';
            foreach ($columns as $column) {
                $sheet->setCellValue($col . '1', $column);
                $sheet->getStyle($col . '1')->getFont()->setBold(true);
                $sheet->getStyle($col . '1')->getFill()
                    ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                    ->getStartColor()->setRGB('2980B9');
                $sheet->getStyle($col . '1')->getFont()->getColor()->setRGB('FFFFFF');
                $sheet->getColumnDimension($col)->setAutoSize(true);
                $col++;
            }
            
            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header('Content-Disposition: attachment;filename="' . $filename . '.xlsx"');
            header('Cache-Control: max-age=0');
            
            $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
            $writer->save('php://output');
            exit;
        }
    }
}
