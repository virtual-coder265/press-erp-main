<?php
require_once __DIR__ . '/../../config/app.php';
checkAuth();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/upload_helper.php';

// Check if PhpSpreadsheet is available, if not, use basic CSV handling
$use_phpspreadsheet = false;
if (file_exists(__DIR__ . '/../../vendor/autoload.php')) {
    require_once __DIR__ . '/../../vendor/autoload.php';
    $use_phpspreadsheet = true;
}

$errors = [];
$success_count = 0;
$skip_count = 0;
$error_rows = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['import_file'])) {
    $file = $_FILES['import_file'];
    $skip_duplicates = isset($_POST['skip_duplicates']) && $_POST['skip_duplicates'] == '1';
    $user_id = $_SESSION['user_id'];
    
    // Validate file
    try {
        $validatedUpload = validate_uploaded_file($file, 'dispatch_import');
        $file_extension = $validatedUpload['extension'];

        if (in_array($file_extension, ['xlsx', 'xls'], true) && !$use_phpspreadsheet) {
            $errors[] = 'Excel file support requires PhpSpreadsheet library. Please install it using: composer require phpoffice/phpspreadsheet. Alternatively, convert your Excel file to CSV format.';
        } else {
            $tmp_file = $file['tmp_name'];
            $rows = [];
            
            try {
                // Handle PDF files (basic support - suggests conversion)
                if ($file_extension === 'pdf') {
                    // For PDF, we'll try to extract text and parse if possible
                    // Note: Full PDF table extraction requires additional libraries
                    // For now, we'll show a message suggesting conversion to CSV/Excel
                    $errors[] = 'PDF files need to be converted to CSV or Excel format first. Please export your PDF table to CSV/Excel and try again.';
                } 
                // Handle Excel files
                elseif (in_array($file_extension, ['xlsx', 'xls']) && $use_phpspreadsheet) {
                    $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($tmp_file);
                    $worksheet = $spreadsheet->getActiveSheet();
                    $rows = $worksheet->toArray();
                }
                // Handle CSV files or Excel without PhpSpreadsheet
                else {
                    $handle = fopen($tmp_file, 'r');
                    if ($handle !== false) {
                        while (($row = fgetcsv($handle)) !== false) {
                            $rows[] = $row;
                        }
                        fclose($handle);
                    } else {
                        $errors[] = 'Could not read the file.';
                    }
                }
                
                if (!empty($rows) && empty($errors)) {
                    // Get header row
                    $headers = array_map('trim', array_map('strtolower', $rows[0]));
                    
                    // Map column names to database fields
                    $column_mapping = [
                        'work_order_number' => ['work order', 'work order number', 'work_order', 'work_order_number', 'wo number', 'wo'],
                        'date_in' => ['date in', 'date_in', 'date received', 'received date', 'in date'],
                        'ministry_department' => ['ministry', 'department', 'ministry/department', 'ministry_department', 'ministry or department', 'ministry or dept'],
                        'job_description' => ['description', 'job description', 'job_description', 'job', 'description of job'],
                        'quantity' => ['quantity', 'qty', 'qty.', 'amount'],
                        'remarks' => ['remarks', 'remark', 'notes', 'status note', 'status notes', 'comments'],
                        'date_out' => ['date out', 'date_out', 'date dispatched', 'dispatched date', 'out date'],
                        'delivery_note_number' => ['delivery note', 'delivery note number', 'delivery_note', 'delivery_note_number', 'dn number', 'dn'],
                        'authorised_dispatcher' => ['authorised dispatcher', 'dispatcher', 'authorised by', 'authorized dispatcher', 'authorized by', 'dispatcher name']
                    ];
                    
                    // Find column indices
                    $column_indices = [];
                    foreach ($column_mapping as $db_field => $possible_names) {
                        foreach ($possible_names as $name) {
                            $index = array_search($name, $headers);
                            if ($index !== false) {
                                $column_indices[$db_field] = $index;
                                break;
                            }
                        }
                    }
                    
                    // Process data rows
                    $pdo->beginTransaction();
                    
                    for ($i = 1; $i < count($rows); $i++) {
                        $row = $rows[$i];
                        
                        // Skip empty rows
                        if (empty(array_filter($row))) {
                            continue;
                        }
                        
                        // Extract values
                        $work_order_number = isset($column_indices['work_order_number']) ? trim($row[$column_indices['work_order_number']]) : null;
                        $date_in = isset($column_indices['date_in']) ? trim($row[$column_indices['date_in']]) : '';
                        $ministry_department = isset($column_indices['ministry_department']) ? trim($row[$column_indices['ministry_department']]) : '';
                        $job_description = isset($column_indices['job_description']) ? trim($row[$column_indices['job_description']]) : '';
                        $quantity = isset($column_indices['quantity']) ? intval($row[$column_indices['quantity']]) : 0;
                        $remark_value = isset($column_indices['remarks']) ? trim($row[$column_indices['remarks']]) : '';
                        $date_out = isset($column_indices['date_out']) ? trim($row[$column_indices['date_out']]) : null;
                        $delivery_note_number = isset($column_indices['delivery_note_number']) ? trim($row[$column_indices['delivery_note_number']]) : null;
                        $authorised_dispatcher = isset($column_indices['authorised_dispatcher']) ? trim($row[$column_indices['authorised_dispatcher']]) : null;
                        
                        // Validate required fields
                        if (empty($date_in) || empty($ministry_department)) {
                            $error_rows[] = "Row " . ($i + 1) . ": Missing required fields (Date In or Ministry/Department)";
                            continue;
                        }
                        
                        // Parse dates
                        $date_in_parsed = parseDate($date_in);
                        $date_out_parsed = $date_out ? parseDate($date_out) : null;
                        
                        if (!$date_in_parsed) {
                            $error_rows[] = "Row " . ($i + 1) . ": Invalid Date In format: " . $date_in;
                            continue;
                        }
                        
                        // Check for duplicates if enabled
                        if ($skip_duplicates) {
                            $check_stmt = $pdo->prepare("SELECT id FROM dispatch_register WHERE work_order_number = ? AND date_in = ?");
                            $check_stmt->execute([$work_order_number ?: '', $date_in_parsed]);
                            if ($check_stmt->fetch()) {
                                $skip_count++;
                                continue;
                            }
                        }
                        
                        // Find authorised dispatcher user ID if provided
                        $authorised_dispatcher_id = null;
                        if ($authorised_dispatcher) {
                            // Try to find by name or email
                            $user_stmt = $pdo->prepare("SELECT id FROM users WHERE name LIKE ? OR email LIKE ? LIMIT 1");
                            $user_stmt->execute(['%' . $authorised_dispatcher . '%', '%' . $authorised_dispatcher . '%']);
                            $user = $user_stmt->fetch();
                            if ($user) {
                                $authorised_dispatcher_id = $user['id'];
                            }
                        }
                        
                        // Insert record
                        try {
                            $stmt = $pdo->prepare("INSERT INTO dispatch_register (work_order_number, date_in, ministry_department, job_description, remarks, quantity, date_out, delivery_note_number, authorised_dispatcher_id, created_by) 
                                                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                            $stmt->execute([
                                $work_order_number ?: null,
                                $date_in_parsed,
                                $ministry_department,
                                $job_description ?: null,
                                $remark_value ?: null,
                                $quantity,
                                $date_out_parsed,
                                $delivery_note_number ?: null,
                                $authorised_dispatcher_id,
                                $user_id
                            ]);
                            $success_count++;
                        } catch (Exception $e) {
                            $error_rows[] = "Row " . ($i + 1) . ": " . $e->getMessage();
                        }
                    }
                    
                    $pdo->commit();
                }
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $errors[] = 'Error processing file: ' . $e->getMessage();
            }
        }
    } catch (RuntimeException $e) {
        $errors[] = $e->getMessage();
    }
    
    // Prepare response
    $message = '';
    $message_type = 'success';
    
    if (!empty($errors)) {
        $message = implode('<br>', $errors);
        $message_type = 'error';
    } else {
        $message_parts = [];
        if ($success_count > 0) {
            $message_parts[] = "Successfully imported {$success_count} record(s)";
        }
        if ($skip_count > 0) {
            $message_parts[] = "Skipped {$skip_count} duplicate(s)";
        }
        if (!empty($error_rows)) {
            $message_parts[] = count($error_rows) . " row(s) had errors";
            $message_type = 'warning';
        }
        $message = implode('. ', $message_parts);
    }
    
    // Redirect with results
    $redirect_url = 'modules/dispatch/list?';
    if ($message_type === 'success') {
        $redirect_url .= 'success=' . urlencode($message);
    } else {
        $redirect_url .= 'error=' . urlencode($message);
    }
    
    if (!empty($error_rows)) {
        // Store error details in session for display
        $_SESSION['import_errors'] = $error_rows;
    }
    
    redirect($redirect_url);
} else {
    redirect('modules/dispatch/import?error=no_file_uploaded');
}

// Helper function to parse various date formats
function parseDate($date_string) {
    if (empty($date_string)) {
        return null;
    }
    
    // Try different date formats
    $formats = [
        'Y-m-d',           // 2024-01-15
        'd/m/Y',           // 15/01/2024
        'm/d/Y',           // 01/15/2024
        'd-m-Y',           // 15-01-2024
        'Y/m/d',           // 2024/01/15
        'd M Y',           // 15 Jan 2024
        'd F Y',           // 15 January 2024
    ];
    
    foreach ($formats as $format) {
        $parsed = DateTime::createFromFormat($format, trim($date_string));
        if ($parsed !== false) {
            return $parsed->format('Y-m-d');
        }
    }
    
    // Try strtotime as fallback
    $timestamp = strtotime($date_string);
    if ($timestamp !== false) {
        return date('Y-m-d', $timestamp);
    }
    
    return null;
}
?>
