<?php
/**
 * Automated Reminders Cron Script
 * 
 * Fetches invoices with outstanding balances and sends email reminders.
 * Should be set up as a cron job to run daily.
 */

require_once __DIR__ . '/../includes/cron_http_guard.php';
cron_require_cli_or_secret();

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/mail.php';
require_once __DIR__ . '/../libs/MailManager.php';

echo "Starting automated balance reminders...\n";

try {
    // 1. Fetch overdue/unpaid invoices with customer email
    // status: Unpaid, Partially Paid, Overdue
    $query = "
        SELECT i.*, 
               (SELECT estimation_number FROM estimations e WHERE e.id = i.estimation_id) as est_num
        FROM invoices i
        WHERE i.balance > 0 
        AND i.status IN ('Unpaid', 'Partially Paid', 'Overdue')
        AND i.customer_email IS NOT NULL AND i.customer_email != ''
        AND (i.due_date IS NULL OR i.due_date <= CURDATE())
    ";

    $stmt = $pdo->query($query);
    $invoices = $stmt->fetchAll();

    if (empty($invoices)) {
        echo "No invoices requiring reminders found.\n";
        exit;
    }

    echo "Found " . count($invoices) . " invoices to process.\n";

    // 2. Initialize Mail Manager
    $mailSettings = getMailSettings();
    $mailer = new MailManager($pdo, $mailSettings);

    $successCount = 0;
    $failCount = 0;

    foreach ($invoices as $inv) {
        $customerName = $inv['customer_name'] ?: 'Valued Customer';
        $invoiceNum = $inv['invoice_number'];
        $balance = number_format($inv['balance'], 2);
        $total = number_format($inv['total_amount'], 2);

        $subject = "Payment Reminder: Invoice #{$invoiceNum} - Gov Press ERP";

        $message = "
            <p>Dear {$customerName},</p>
            <p>This is a friendly reminder regarding your outstanding balance on <strong>Invoice #{$invoiceNum}</strong>.</p>
            <div style='background: #f7fafc; padding: 15px; border-radius: 5px; margin: 20px 0;'>
                <p style='margin: 5px 0;'><strong>Invoice Date:</strong> " . date('M d, Y', strtotime($inv['generated_date'])) . "</p>
                <p style='margin: 5px 0;'><strong>Total Amount:</strong> MK {$total}</p>
                <p style='margin: 5px 0; color: #e53e3e;'><strong>Remaining Balance:</strong> MK {$balance}</p>
            </div>
            <p>Please arrange for the settlement of this balance at your earliest convenience. If you have already made the payment, please disregard this message or send us the proof of payment.</p>
            <p>Should you have any questions, feel free to contact our billing department.</p>
            <p>Thank you for your business!</p>
        ";

        $result = $mailer->sendNotificationEmail($inv['customer_email'], $customerName, "Standing Balance Reminder", $message);

        if ($result['success']) {
            echo "Successfully sent reminder for Invoice #{$invoiceNum} to {$inv['customer_email']}\n";
            $successCount++;
        } else {
            echo "Failed to send reminder for Invoice #{$invoiceNum}: " . $result['error'] . "\n";
            $failCount++;
        }
    }

    echo "\nProcessing complete.\n";
    echo "Sent: {$successCount}\n";
    echo "Failed: {$failCount}\n";

} catch (Exception $e) {
    echo "CRITICAL ERROR: " . $e->getMessage() . "\n";
}
?>