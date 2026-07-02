<?php
require_once __DIR__ . '/../../config/app.php';
checkAuth();
require_once __DIR__ . '/../../config/database.php';

$id = $_GET['id'] ?? 0;

$stmt = $pdo->prepare("SELECT d.*, u1.name as authorised_dispatcher_name, u2.name as created_by_name 
                      FROM dispatch_register d 
                      LEFT JOIN users u1 ON d.authorised_dispatcher_id = u1.id 
                      LEFT JOIN users u2 ON d.created_by = u2.id 
                      WHERE d.id = ?");
$stmt->execute([$id]);
$dispatch = $stmt->fetch();

if (!$dispatch) {
    redirect('modules/dispatch/list?error=entry_not_found');
}

// Fetch remarks for this dispatch entry
include '../../includes/header.php';
?>

<div class="mb-6">
    <a href="list" class="text-green-600 hover:underline flex items-center mb-4">
        <i class="material-icons text-sm mr-1">arrow_back</i> Back to Dispatch Register
    </a>
    <div class="flex justify-between items-start">
        <div>
            <h1 class="text-3xl font-bold text-gray-800">Dispatch Entry Details</h1>
            <p class="text-gray-600 mt-1">View complete dispatch information</p>
        </div>
        <div class="flex gap-2">
            <a href="edit?id=<?php echo $dispatch['id']; ?>" class="bg-gray-600 text-white px-4 py-2 rounded hover:bg-gray-700 transition">
                <i class="material-icons align-middle mr-1">edit</i> Edit
            </a>
        </div>
    </div>
</div>

<!-- Dispatch Details Card -->
<div class="bg-white shadow rounded-lg p-8 max-w-4xl">
    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
        <div>
            <label class="block text-sm font-semibold text-gray-500 uppercase mb-1">Work Order Number</label>
            <p class="text-lg font-semibold text-gray-800 mb-4">
                <?php echo htmlspecialchars($dispatch['work_order_number'] ?: 'Not specified'); ?>
            </p>
        </div>
        
        <div>
            <label class="block text-sm font-semibold text-gray-500 uppercase mb-1">Date In</label>
            <p class="text-lg font-semibold text-gray-800 mb-4">
                <?php echo date('F d, Y', strtotime($dispatch['date_in'])); ?>
            </p>
        </div>
        
        <div class="md:col-span-2">
            <label class="block text-sm font-semibold text-gray-500 uppercase mb-1">Ministry or Department</label>
            <p class="text-lg font-semibold text-gray-800 mb-4">
                <?php echo htmlspecialchars($dispatch['ministry_department']); ?>
            </p>
        </div>
        
        <div class="md:col-span-2">
            <label class="block text-sm font-semibold text-gray-500 uppercase mb-1">Description of Job</label>
            <p class="text-gray-700 whitespace-pre-wrap mb-4">
                <?php echo htmlspecialchars($dispatch['job_description'] ?: 'No description provided'); ?>
            </p>
        </div>

        <div class="md:col-span-2">
            <label class="block text-sm font-semibold text-gray-500 uppercase mb-1">Remarks</label>
            <p class="text-gray-700 whitespace-pre-wrap mb-4">
                <?php echo htmlspecialchars($dispatch['remarks'] ?: 'No remarks provided'); ?>
            </p>
        </div>
        
        <div>
            <label class="block text-sm font-semibold text-gray-500 uppercase mb-1">Quantity</label>
            <p class="text-lg font-semibold text-gray-800 mb-4">
                <?php echo number_format($dispatch['quantity']); ?>
            </p>
        </div>
        
        <div>
            <label class="block text-sm font-semibold text-gray-500 uppercase mb-1">Date Out</label>
            <p class="text-lg font-semibold text-gray-800 mb-4">
                <?php echo $dispatch['date_out'] ? date('F d, Y', strtotime($dispatch['date_out'])) : '<span class="text-gray-400">Pending</span>'; ?>
            </p>
        </div>
        
        <div>
            <label class="block text-sm font-semibold text-gray-500 uppercase mb-1">Delivery Note Number</label>
            <p class="text-lg font-semibold text-gray-800 mb-4">
                <?php echo htmlspecialchars($dispatch['delivery_note_number'] ?: 'Not specified'); ?>
            </p>
        </div>
        
        <div>
            <label class="block text-sm font-semibold text-gray-500 uppercase mb-1">Authorised Dispatcher</label>
            <p class="text-lg font-semibold text-gray-800 mb-4">
                <?php echo htmlspecialchars($dispatch['authorised_dispatcher_name'] ?: 'Not assigned'); ?>
            </p>
        </div>
        
        <div>
            <label class="block text-sm font-semibold text-gray-500 uppercase mb-1">Created By</label>
            <p class="text-gray-700 mb-4">
                <?php echo htmlspecialchars($dispatch['created_by_name']); ?>
            </p>
        </div>
        
        <div>
            <label class="block text-sm font-semibold text-gray-500 uppercase mb-1">Created On</label>
            <p class="text-gray-700 mb-4">
                <?php echo date('F d, Y g:i A', strtotime($dispatch['created_at'])); ?>
            </p>
        </div>
        
        <?php if ($dispatch['updated_at'] != $dispatch['created_at']): ?>
        <div>
            <label class="block text-sm font-semibold text-gray-500 uppercase mb-1">Last Updated</label>
            <p class="text-gray-700 mb-4">
                <?php echo date('F d, Y g:i A', strtotime($dispatch['updated_at'])); ?>
            </p>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>

