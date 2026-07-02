<?php
require_once __DIR__ . '/../../config/app.php';
checkAuth();
require_once __DIR__ . '/../../config/database.php';

include '../../includes/header.php';

$user_id = $_SESSION['user_id'];

// Get filter
$filter = $_GET['filter'] ?? 'unread'; // unread, all

$query = "SELECT n.*, u.name as from_user_name 
          FROM notifications n 
          LEFT JOIN messages m ON n.message_id = m.id 
          LEFT JOIN users u ON m.sender_id = u.id 
          WHERE n.user_id = :user_id";

$params = ['user_id' => $user_id];

if ($filter == 'unread') {
    $query .= " AND n.is_read = FALSE";
}

$query .= " ORDER BY n.created_at DESC";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$notifications = $stmt->fetchAll();

// Handle mark as read
if ($_GET['action'] == 'mark_read' && !empty($_GET['notification_id'])) {
    $pdo->prepare("UPDATE notifications SET is_read = TRUE WHERE id = :id AND user_id = :user_id")
       ->execute(['id' => $_GET['notification_id'], 'user_id' => $user_id]);
    header('Location: notifications?filter=' . $filter);
    exit;
}

// Handle mark all as read
if ($_GET['action'] == 'mark_all_read') {
    $pdo->prepare("UPDATE notifications SET is_read = TRUE WHERE user_id = :user_id AND is_read = FALSE")
       ->execute(['user_id' => $user_id]);
    header('Location: notifications?filter=all');
    exit;
}
?>

<div class="flex justify-between items-center mb-6">
    <h1 class="text-3xl font-bold text-gray-800">Notifications</h1>
    <?php if ($filter == 'unread' && count($notifications) > 0): ?>
    <a href="notifications?action=mark_all_read" class="bg-green-600 text-white px-4 py-2 rounded hover:bg-green-700 transition">
        Mark All as Read
    </a>
    <?php endif; ?>
</div>

<!-- Filter Tabs -->
<div class="mb-6 flex gap-2 border-b border-gray-300">
    <a href="notifications?filter=unread" 
       class="px-4 py-2 font-bold <?php echo $filter == 'unread' ? 'border-b-2 border-green-500 text-green-600' : 'text-gray-600'; ?>">
        Unread
    </a>
    <a href="notifications?filter=all" 
       class="px-4 py-2 font-bold <?php echo $filter == 'all' ? 'border-b-2 border-green-500 text-green-600' : 'text-gray-600'; ?>">
        All
    </a>
</div>

<!-- Notifications List -->
<div class="space-y-3">
    <?php foreach ($notifications as $notif): ?>
    <div class="bg-white p-4 rounded-lg shadow hover:shadow-lg transition <?php echo !$notif['is_read'] ? 'border-l-4 border-blue-500 bg-blue-50' : ''; ?>">
        <div class="flex items-start justify-between">
            <div class="flex-1">
                <h3 class="font-bold text-gray-800"><?php echo htmlspecialchars($notif['title']); ?></h3>
                <p class="text-sm text-gray-600 mt-1"><?php echo htmlspecialchars($notif['description']); ?></p>
                <p class="text-xs text-gray-500 mt-2"><?php echo date('M j, Y H:i', strtotime($notif['created_at'])); ?></p>
            </div>
            <div class="ml-4 flex gap-2">
                <?php if ($notif['type'] == 'message' && $notif['message_id']): ?>
                <a href="view?id=<?php echo $notif['message_id']; ?>" class="text-green-600 hover:text-green-700 text-sm font-semibold">
                    View
                </a>
                <?php endif; ?>
                
                <?php if (!$notif['is_read']): ?>
                <a href="notifications?action=mark_read&notification_id=<?php echo $notif['id']; ?>&filter=<?php echo $filter; ?>" 
                   class="text-gray-600 hover:text-gray-700 text-sm font-semibold">
                    Mark Read
                </a>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endforeach; ?>

    <?php if (empty($notifications)): ?>
    <div class="bg-white p-6 rounded-lg shadow text-center">
        <i class="material-icons text-6xl text-gray-300 mb-4 block">notifications_none</i>
        <p class="text-gray-500 text-lg">No notifications</p>
    </div>
    <?php endif; ?>
</div>

<?php include '../../includes/footer.php'; ?>
