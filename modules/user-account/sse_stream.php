<?php
require_once __DIR__ . '/../../config/app.php';
checkAuth();
require_once __DIR__ . '/../../config/database.php';

// Capture user id before releasing session lock
$userId = (int) $_SESSION['user_id'];

// Release the PHP session file lock immediately so other requests from this
// user are not blocked while this long-lived connection is open.
session_write_close();

// Disable all output buffering so events are flushed to the client instantly.
while (ob_get_level()) {
    ob_end_clean();
}
ob_implicit_flush(true);

// SSE headers
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('X-Accel-Buffering: no'); // Prevent nginx/Apache reverse-proxy buffering

set_time_limit(0);

$lastNotifCount  = -1;
$lastMsgCount    = -1;
$startTime       = time();
$maxRuntime      = 300; // 5 minutes — browser EventSource auto-reconnects after
$heartbeatEvery  = 30;  // seconds between keepalive comments
$lastHeartbeat   = time();

$notifStmt = $pdo->prepare(
    'SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = FALSE'
);
$msgStmt = $pdo->prepare(
    'SELECT COUNT(*) FROM messages WHERE recipient_id = ? AND is_read = FALSE'
);

while (!connection_aborted() && (time() - $startTime) < $maxRuntime) {
    try {
        $notifStmt->execute([$userId]);
        $notifCount = (int) $notifStmt->fetchColumn();

        $msgStmt->execute([$userId]);
        $msgCount = (int) $msgStmt->fetchColumn();
    } catch (Exception $e) {
        // DB hiccup — send a heartbeat and retry next cycle
        echo ": db-error\n\n";
        sleep(2);
        continue;
    }

    if ($notifCount !== $lastNotifCount) {
        echo "event: notification\n";
        echo 'data: ' . json_encode(['count' => $notifCount]) . "\n\n";
        $lastNotifCount = $notifCount;
    }

    if ($msgCount !== $lastMsgCount) {
        echo "event: message\n";
        echo 'data: ' . json_encode(['count' => $msgCount]) . "\n\n";
        $lastMsgCount = $msgCount;
    }

    // Heartbeat comment keeps the connection alive through proxies/load-balancers
    if (time() - $lastHeartbeat >= $heartbeatEvery) {
        echo ": ping\n\n";
        $lastHeartbeat = time();
    }

    sleep(2);
}
