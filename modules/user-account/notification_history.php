<?php
require_once dirname(dirname(__DIR__)) . '/config/app.php';
checkAuth();
require_once ROOT_PATH . 'config/database.php';
require_once ROOT_PATH . 'libs/NotificationManager.php';

$user_id = intval($_SESSION['user_id']);
$notifManager = new NotificationManager($pdo);

// Handle individual mark as read if requested via GET
if (isset($_GET['mark_read'])) {
    $notifManager->markAsRead(intval($_GET['mark_read']), $user_id);
    if (isset($_GET['redirect'])) {
        header("Location: " . BASE_URL . $_GET['redirect']);
        exit;
    }
}

// Pagination
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$limit = 20;
$offset = ($page - 1) * $limit;

$notifications = $notifManager->getAll($user_id, $limit, $offset);

include ROOT_PATH . 'includes/header.php';
?>

<div class="max-w-4xl mx-auto">
    <div class="mb-8 flex justify-between items-center">
        <div>
            <h1 class="text-3xl font-extrabold text-gray-900 tracking-tight">Notification History</h1>
            <p class="text-gray-500 mt-1">Review all your recent activity and alerts.</p>
        </div>
        <button id="mark-all-read-page" class="bg-white border border-gray-200 text-gray-600 px-4 py-2 rounded-xl hover:bg-gray-50 transition shadow-sm font-medium flex items-center">
            <i class="material-icons text-sm mr-2">done_all</i> Mark All as Read
        </button>
    </div>

    <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
        <?php if (empty($notifications)): ?>
            <div class="p-20 text-center">
                <div class="w-20 h-20 bg-gray-50 rounded-full flex items-center justify-center mx-auto mb-4 text-gray-300">
                    <i class="material-icons text-5xl">notifications_none</i>
                </div>
                <h3 class="text-lg font-bold text-gray-800">No Notifications</h3>
                <p class="text-gray-500 mt-1">You're all caught up! New alerts will appear here.</p>
            </div>
        <?php else: ?>
            <div class="divide-y divide-gray-50">
                <?php foreach ($notifications as $notif): ?>
                    <div class="p-6 hover:bg-gray-50 transition flex items-start group <?php echo !$notif['is_read'] ? 'bg-blue-50/30' : ''; ?>">
                        <div class="flex-shrink-0 mr-4 mt-1">
                            <div class="w-10 h-10 rounded-full flex items-center justify-center 
                                <?php 
                                switch($notif['type']) {
                                    case 'message': echo 'bg-blue-100 text-blue-600'; break;
                                    case 'task': echo 'bg-purple-100 text-purple-600'; break;
                                    case 'security': echo 'bg-red-100 text-red-600'; break;
                                    default: echo 'bg-gray-100 text-gray-600'; break;
                                }
                                ?>">
                                <i class="material-icons text-xl">
                                    <?php 
                                    switch($notif['type']) {
                                        case 'message': echo 'email'; break;
                                        case 'task': echo 'assignment'; break;
                                        case 'security': echo 'security'; break;
                                        default: echo 'notifications'; break;
                                    }
                                    ?>
                                </i>
                            </div>
                        </div>
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center justify-between mb-1">
                                <h3 class="text-sm font-bold text-gray-900 truncate">
                                    <?php if (!$notif['is_read']): ?>
                                        <span class="inline-block w-2 h-2 bg-blue-600 rounded-full mr-2"></span>
                                    <?php endif; ?>
                                    <?php echo htmlspecialchars($notif['title']); ?>
                                </h3>
                                <span class="text-xs text-gray-400 font-medium"><?php echo date('M d, Y H:i', strtotime($notif['created_at'])); ?></span>
                            </div>
                            <p class="text-sm text-gray-600 leading-relaxed mb-3"><?php echo htmlspecialchars($notif['description']); ?></p>
                            
                            <?php if (!empty($notif['link'])): ?>
                                <a href="notification_history?mark_read=<?php echo $notif['id']; ?>&redirect=<?php echo urlencode($notif['link']); ?>" 
                                   class="inline-flex items-center text-xs font-bold text-blue-600 hover:text-blue-700 transition">
                                    View Details <i class="material-icons text-xs ml-1">arrow_forward</i>
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Pagination -->
    <?php if (count($notifications) >= $limit || $page > 1): ?>
    <div class="mt-8 flex justify-center space-x-2">
        <?php if ($page > 1): ?>
            <a href="?page=<?php echo $page - 1; ?>" class="px-4 py-2 border border-gray-200 rounded-xl bg-white text-gray-600 hover:bg-gray-50 transition">Previous</a>
        <?php endif; ?>
        <?php if (count($notifications) == $limit): ?>
            <a href="?page=<?php echo $page + 1; ?>" class="px-4 py-2 border border-gray-200 rounded-xl bg-white text-gray-600 hover:bg-gray-50 transition">Next</a>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<script>
$(document).ready(function() {
    $('#mark-all-read-page').click(function() {
        $.post('<?php echo BASE_URL; ?>modules/user-account/notification_action', {
            action: 'mark_all_read'
        }, function(response) {
            if (response.success) {
                location.reload();
            } else {
                alert('Error: ' + response.message);
            }
        }, 'json');
    });
});
</script>

<?php include ROOT_PATH . 'includes/footer.php'; ?>
