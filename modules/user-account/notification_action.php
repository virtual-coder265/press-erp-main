<?php
require_once __DIR__ . '/../../config/app.php';
checkAuth();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../libs/NotificationManager.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $user_id = $_SESSION['user_id'];
    $notifManager = new NotificationManager($pdo);

    if ($action === 'mark_as_read') {
        $notif_id = $_POST['id'] ?? 0;
        if ($notifManager->markAsRead($notif_id, $user_id)) {
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to mark as read']);
        }
    } elseif ($action === 'mark_all_read') {
        $stmt = $pdo->prepare("UPDATE notifications SET is_read = TRUE WHERE user_id = ?");
        if ($stmt->execute([$user_id])) {
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to mark all as read']);
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid action received: ' . $action]);
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $action = $_GET['action'] ?? '';
    $user_id = $_SESSION['user_id'];
    $notifManager = new NotificationManager($pdo);

    if ($action === 'get_unread_count') {
        $count = $notifManager->getUnreadCount($user_id);
        echo json_encode(['success' => true, 'count' => (int)$count]);
    } elseif ($action === 'get_latest') {
        $count = $notifManager->getUnreadCount($user_id);
        $notifications = $notifManager->getUnread($user_id, 5);
        echo json_encode(['success' => true, 'count' => (int)$count, 'notifications' => $notifications]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid request method: ' . $_SERVER['REQUEST_METHOD']]);
}
