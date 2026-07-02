<?php
/**
 * EstimationStatusManager - Usage Examples
 * 
 * This file demonstrates various ways to use the EstimationStatusManager class
 * in your Press ERP application.
 * 
 * DO NOT use this as a real page - it's for reference/copy-paste examples only.
 */

// ========================================================================
// EXAMPLE 1: Basic Setup and Initialization
// ========================================================================
require_once 'config/database.php';
require_once 'libs/EstimationStatusManager.php';

$manager = new EstimationStatusManager($pdo);


// ========================================================================
// EXAMPLE 2: Change a Single Estimation's Status
// ========================================================================

// Change estimation #123 to "Approved"
$result = $manager->changeStatus(
    estimation_id: 123,
    new_status: 'Approved',
    changed_by: $_SESSION['user_id'],
    reason: 'Approved by customer via email'
);

if ($result['success']) {
    echo "✓ " . $result['message'];
    // Output: ✓ Status updated from 'Draft' to 'Approved' successfully
} else {
    echo "✗ " . $result['message'];
    // Output: ✗ Cannot change from 'Draft' to 'Invoiced'. Allowed transitions: Performer Invoiced, Approved
}


// ========================================================================
// EXAMPLE 3: Check If a Transition is Allowed
// ========================================================================

$from = 'Draft';
$to = 'Performer Invoiced';

if (EstimationStatusManager::isTransitionAllowed($from, $to)) {
    echo "Status change from {$from} to {$to} is allowed";
} else {
    echo "Status change from {$from} to {$to} is NOT allowed";
}


// ========================================================================
// EXAMPLE 4: Get All Valid Statuses
// ========================================================================

$statuses = EstimationStatusManager::getAllStatuses();
// Returns: ['Draft', 'Performer Invoiced', 'Approved', 'Invoiced']

foreach ($statuses as $status) {
    echo "- {$status}\n";
}


// ========================================================================
// EXAMPLE 5: Get Allowed Transitions for a Status
// ========================================================================

$current_status = 'Performer Invoiced';
$allowed = EstimationStatusManager::getAllowedTransitions($current_status);
// Returns: ['Approved', 'Invoiced']

echo "From '{$current_status}' you can change to: " . implode(', ', $allowed);
// Output: From 'Performer Invoiced' you can change to: Approved, Invoiced


// ========================================================================
// EXAMPLE 6: Get Status Details with Display Information
// ========================================================================

$details = EstimationStatusManager::getStatusDetails('Approved');

echo $details['label'];           // Output: Approved
echo $details['description'];     // Output: Approved by customer or costing department
echo $details['color'];           // Output: green
echo $details['bg_class'];        // Output: bg-green-100
echo $details['text_class'];      // Output: text-green-800
echo $details['icon'];            // Output: circle-check


// ========================================================================
// EXAMPLE 7: Display Status Badge HTML
// ========================================================================

// In a template/PHP file:
echo EstimationStatusManager::getStatusBadgeHtml('Invoiced');
// Output: <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-purple-100 text-purple-800" title="Official invoice sent to customer for payment">Invoiced</span>


// ========================================================================
// EXAMPLE 8: Get Status History for an Estimation
// ========================================================================

$estimation_id = 123;
$history = $manager->getStatusHistory($estimation_id);

foreach ($history as $entry) {
    $date = date('M j, Y g:i A', strtotime($entry['changed_at']));
    $from = $entry['old_status'] ?? 'N/A';
    $to = $entry['new_status'];
    $by = $entry['changed_by_name'] ?? 'System';
    $reason = $entry['change_reason'] ?? 'No reason provided';
    
    echo "{$date}: {$from} → {$to} by {$by} ({$reason})\n";
}


// ========================================================================
// EXAMPLE 9: Get Estimations by Status
// ========================================================================

// Get all "Approved" estimations, limited to 10 per page
$page = 1;
$per_page = 10;
$offset = ($page - 1) * $per_page;

$estimations = $manager->getEstimationsByStatus(
    status: 'Approved',
    limit: $per_page,
    offset: $offset
);

foreach ($estimations as $est) {
    echo "{$est['estimation_number']}: {$est['customer_name']} - MTW {$est['total_amount']}\n";
}


// ========================================================================
// EXAMPLE 10: Get Statistics by Status
// ========================================================================

$stats = $manager->getStatisticsByStatus();

foreach ($stats as $stat) {
    $status = $stat['status'];
    $count = $stat['count'];
    $total = $stat['total_amount'];
    $avg = $count > 0 ? $total / $count : 0;
    
    echo "{$status}: {$count} items, Total: MTW {$total}, Average: MTW {$avg}\n";
}

// Example output:
// Draft: 5 items, Total: MTW 15000.00, Average: MTW 3000.00
// Performer Invoiced: 8 items, Total: MTW 24000.00, Average: MTW 3000.00
// Approved: 12 items, Total: MTW 48000.00, Average: MTW 4000.00
// Invoiced: 34 items, Total: MTW 170000.00, Average: MTW 5000.00


// ========================================================================
// EXAMPLE 11: Bulk Change Status
// ========================================================================

// Change multiple estimations at once (use carefully!)
$result = $manager->bulkChangeStatus(
    estimation_ids: [10, 11, 12, 13, 14],
    new_status: 'Approved',
    changed_by: $_SESSION['user_id'],
    reason: 'Bulk approval - Q1 2026 batch'
);

echo "Successfully changed: " . $result['successful'] . " estimations\n";
echo "Failed: " . $result['failed'] . "\n";
if (!empty($result['errors'])) {
    foreach ($result['errors'] as $error) {
        echo "  Error: {$error}\n";
    }
}


// ========================================================================
// EXAMPLE 12: Build a Status Transition Dropdown in Forms
// ========================================================================

// In a form/template:
$current_status = 'Draft';
$allowed_transitions = EstimationStatusManager::getAllowedTransitions($current_status);

echo '<select name="new_status" required>';
echo '<option value="">Select new status...</option>';

foreach ($allowed_transitions as $status) {
    $details = EstimationStatusManager::getStatusDetails($status);
    echo '<option value="' . htmlspecialchars($status) . '">';
    echo htmlspecialchars($details['label']) . ' - ' . htmlspecialchars($details['description']);
    echo '</option>';
}

echo '</select>';


// ========================================================================
// EXAMPLE 13: Error Handling and Try-Catch
// ========================================================================

try {
    $result = $manager->changeStatus(
        estimation_id: 999,  // Non-existent
        new_status: 'Approved',
        changed_by: $_SESSION['user_id']
    );
    
    if (!$result['success']) {
        echo "Change failed: " . $result['message'];
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}


// ========================================================================
// EXAMPLE 14: Create Status Report
// ========================================================================

function generateStatusReport($pdo)
{
    $manager = new EstimationStatusManager($pdo);
    $stats = $manager->getStatisticsByStatus();
    
    $report = "ESTIMATION STATUS REPORT\n";
    $report .= str_repeat("=", 50) . "\n";
    
    $total_count = 0;
    $total_amount = 0;
    
    foreach ($stats as $stat) {
        $report .= "\n{$stat['status']}:\n";
        $report .= "  Count: {$stat['count']}\n";
        $report .= "  Total Amount: MTW " . number_format($stat['total_amount'], 2) . "\n";
        
        $total_count += $stat['count'];
        $total_amount += $stat['total_amount'];
    }
    
    $report .= "\n" . str_repeat("=", 50) . "\n";
    $report .= "TOTAL: {$total_count} estimations worth MTW " . number_format($total_amount, 2) . "\n";
    
    return $report;
}

echo generateStatusReport($pdo);


// ========================================================================
// EXAMPLE 15: Logging Status Changes to File
// ========================================================================

function logStatusChange($estimation_id, $old_status, $new_status, $user_id)
{
    $log_entry = sprintf(
        "[%s] Estimation #%d: %s → %s (User: %d)\n",
        date('Y-m-d H:i:s'),
        $estimation_id,
        $old_status,
        $new_status,
        $user_id
    );
    
    file_put_contents(
        'logs/estimation_status_changes.log',
        $log_entry,
        FILE_APPEND
    );
}

// Use it:
$result = $manager->changeStatus(123, 'Approved', 5);
if ($result['success']) {
    logStatusChange(123, 'Draft', 'Approved', 5);
}


// ========================================================================
// EXAMPLE 16: Dashboard Data for Templates
// ========================================================================

function getDashboardData($pdo)
{
    $manager = new EstimationStatusManager($pdo);
    
    return [
        'stats' => $manager->getStatisticsByStatus(),
        'recent_drafts' => $manager->getEstimationsByStatus('Draft', limit: 5),
        'pending_approval' => $manager->getEstimationsByStatus('Performer Invoiced', limit: 5),
        'recently_invoiced' => $manager->getEstimationsByStatus('Invoiced', limit: 10),
    ];
}

$dashboard = getDashboardData($pdo);
// Use: $dashboard['stats'] in template


// ========================================================================
// EXAMPLE 17: Validation Before Display
// ========================================================================

function canChangeStatus($current_status, $target_status)
{
    if ($current_status === $target_status) {
        return ['valid' => false, 'message' => 'Same status selected'];
    }
    
    if (!EstimationStatusManager::isTransitionAllowed($current_status, $target_status)) {
        $allowed = EstimationStatusManager::getAllowedTransitions($current_status);
        return [
            'valid' => false,
            'message' => 'Invalid transition. Allowed: ' . implode(', ', $allowed)
        ];
    }
    
    return ['valid' => true, 'message' => 'Change allowed'];
}

$check = canChangeStatus('Draft', 'Invoiced');
if ($check['valid']) {
    // Proceed with change
} else {
    echo "Error: " . $check['message'];
}


// ========================================================================
// EXAMPLE 18: Email Notification on Status Change
// ========================================================================

function notifyOnStatusChange($estimation_id, $new_status, $pdo)
{
    $stmt = $pdo->prepare("
        SELECT c.email, e.estimation_number, e.customer_name
        FROM estimations e
        LEFT JOIN customers c ON e.customer_name = c.name
        WHERE e.id = :id
    ");
    $stmt->execute(['id' => $estimation_id]);
    $est = $stmt->fetch();
    
    if (!$est || !$est['email']) {
        return;
    }
    
    $details = EstimationStatusManager::getStatusDetails($new_status);
    
    $subject = "Estimation {$est['estimation_number']} Status: {$details['label']}";
    $message = "Dear {$est['customer_name']},\n\n";
    $message .= "Your estimation has been updated to: {$details['description']}\n";
    $message .= "Please log in to check the latest details.\n";
    
    // mail($est['email'], $subject, $message);
}


// ========================================================================
// EXAMPLE 19: Custom Status Manager Extension
// ========================================================================

class CustomEstimationStatusManager extends EstimationStatusManager
{
    /**
     * Only allow certain roles to make transitions
     */
    public function changeStatus($est_id, $new_status, $user_id, $reason = '')
    {
        // Get user role
        $stmt = $this->pdo->prepare("SELECT role_id FROM users WHERE id = :id");
        $stmt->execute(['id' => $user_id]);
        $user = $stmt->fetch();
        
        // Only admins can revert from Invoiced
        $stmt = $this->pdo->prepare("SELECT status FROM estimations WHERE id = :id");
        $stmt->execute(['id' => $est_id]);
        $est = $stmt->fetch();
        
        if ($est['status'] === 'Invoiced' && $new_status === 'Draft') {
            if ($user['role_id'] !== 1) { // 1 = Admin
                return ['success' => false, 'message' => 'Only admins can revert from Invoiced'];
            }
        }
        
        return parent::changeStatus($est_id, $new_status, $user_id, $reason);
    }
}


// ========================================================================
// EXAMPLE 20: Query Recent Changes
// ========================================================================

// Get all status changes from the last 7 days
$query = "
    SELECT 
        e.estimation_number,
        esh.old_status,
        esh.new_status,
        u.name as changed_by,
        esh.changed_at,
        esh.change_reason
    FROM estimation_status_history esh
    JOIN estimations e ON esh.estimation_id = e.id
    LEFT JOIN users u ON esh.changed_by = u.id
    WHERE esh.changed_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
    ORDER BY esh.changed_at DESC
";

$stmt = $pdo->query($query);
$recent_changes = $stmt->fetchAll();

foreach ($recent_changes as $change) {
    echo "{$change['estimation_number']}: {$change['old_status']} → {$change['new_status']} ";
    echo "by {$change['changed_by']} on " . date('M j, Y', strtotime($change['changed_at'])) . "\n";
}


// ========================================================================
// END OF EXAMPLES
// ========================================================================

?>
