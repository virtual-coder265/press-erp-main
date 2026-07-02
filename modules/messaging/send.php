<?php
require_once __DIR__ . '/../../config/app.php';
checkAuth();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/mail.php';
require_once __DIR__ . '/../../libs/MailManager.php';

include '../../includes/header.php';

$user_id = $_SESSION['user_id'];
$error = '';
$success = '';

// Get all users except current user
$users = $pdo->query("SELECT id, name, email FROM users WHERE id != $user_id ORDER BY name")->fetchAll();

// Get current user info
$current_user = $pdo->prepare("SELECT name, email FROM users WHERE id = :id");
$current_user->execute(['id' => $user_id]);
$current_user = $current_user->fetch();

// Handle message sending
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $recipient_id = $_POST['recipient_id'] ?? '';
    $subject = $_POST['subject'] ?? '';
    $body = $_POST['body'] ?? '';

    if (empty($recipient_id) || empty($subject) || empty($body)) {
        $error = 'Please fill in all fields.';
    } else {
        try {
            // Insert message
            $stmt = $pdo->prepare("INSERT INTO messages (sender_id, recipient_id, subject, body) 
                                  VALUES (:sender_id, :recipient_id, :subject, :body)");
            $stmt->execute([
                'sender_id' => $user_id,
                'recipient_id' => $recipient_id,
                'subject' => $subject,
                'body' => $body
            ]);
            $message_id = $pdo->lastInsertId();

            // Update or create conversation
            $r_id = (int)$recipient_id;
            $u_id = (int)$user_id;
            $check_conv = $pdo->prepare("SELECT id FROM conversations 
                                        WHERE (participant1_id = $u_id AND participant2_id = $r_id) 
                                           OR (participant1_id = $r_id AND participant2_id = $u_id)");
            $check_conv->execute();
            $conv = $check_conv->fetch();

            if ($conv) {
                $pdo->prepare("UPDATE conversations 
                             SET last_message_id = :msg_id, last_message_at = NOW() 
                             WHERE id = :conv_id")
                   ->execute(['msg_id' => $message_id, 'conv_id' => $conv['id']]);
            } else {
                $pdo->prepare("INSERT INTO conversations (participant1_id, participant2_id, last_message_id, last_message_at) 
                             VALUES (:p1, :p2, :msg_id, NOW())")
                   ->execute([
                       'p1' => $user_id,
                       'p2' => $recipient_id,
                       'msg_id' => $message_id
                   ]);
            }

            // Insert notification and send email via NotificationManager
            require_once __DIR__ . '/../../libs/NotificationManager.php';
            $notifManager = new NotificationManager($pdo);
            
            $notifTitle = 'New Message: ' . $subject;
            $notifDesc = $current_user['name'] . ' has sent you a new message: "' . $subject . '"';

            // Link to the conversation
            $conv_id = $conv ? $conv['id'] : $pdo->lastInsertId(); // If new conv, get last ID from its insert
            $notifLink = 'modules/messaging/view?id=' . $conv_id;

            // Get recipient name for email personalization
            $recipientStmt = $pdo->prepare("SELECT name FROM users WHERE id = :id");
            $recipientStmt->execute(['id' => $recipient_id]);
            $recipientName = $recipientStmt->fetchColumn();

            $notifManager->notify(
                $recipient_id,
                'message',
                $notifTitle,
                $notifDesc,
                $notifLink,
                $message_id,
                false,
                false,
                [
                    'fromUser' => $current_user['name'],
                    'subject' => $subject,
                    'messagePreview' => $body,
                    'inboxUrl' => BASE_URL . 'modules/messaging/view?id=' . $conv_id,
                ]
            );

            $success = 'Message sent successfully!';
            // Clear form
            $_POST = [];
        } catch (Exception $e) {
            $error = 'Error sending message: ' . $e->getMessage();
        }
    }
}

$reply_to_id = $_GET['reply_to'] ?? '';
$reply_user = null;

if (!empty($reply_to_id)) {
    $stmt = $pdo->prepare("SELECT id, name FROM users WHERE id = :id");
    $stmt->execute(['id' => $reply_to_id]);
    $reply_user = $stmt->fetch();
}

$prefill_subject = trim((string) ($_GET['subject'] ?? ''));
$prefill_body = (string) ($_GET['body'] ?? '');
?>

<div class="mb-6">
    <h1 class="text-3xl font-bold text-gray-800">Send Message</h1>
</div>

<?php if (!empty($error)): ?>
<div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-6">
    <?php echo htmlspecialchars($error); ?>
</div>
<?php endif; ?>

<?php if (!empty($success)): ?>
<div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-6">
    <?php echo htmlspecialchars($success); ?>
    <a href="inbox" class="underline">Back to Inbox</a>
</div>
<?php endif; ?>

<div class="bg-white shadow rounded-lg p-8 max-w-2xl">
    <form method="POST" action="send<?php echo !empty($reply_to_id) ? '?reply_to=' . (int) $reply_to_id : ''; ?>">
        <div class="mb-6">
            <label for="recipient_id" class="block text-gray-700 text-sm font-bold mb-2">
                Recipient <span class="text-red-500">*</span>
            </label>
            <select id="recipient_id" name="recipient_id" required 
                    class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500">
                <option value="">-- Select Recipient --</option>
                <?php foreach ($users as $u): ?>
                <option value="<?php echo $u['id']; ?>" 
                        <?php echo (isset($_POST['recipient_id']) && $_POST['recipient_id'] == $u['id']) || ($reply_user && $reply_user['id'] == $u['id']) ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($u['name']); ?> (<?php echo htmlspecialchars($u['email']); ?>)
                </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="mb-6">
            <label for="subject" class="block text-gray-700 text-sm font-bold mb-2">
                Subject <span class="text-red-500">*</span>
            </label>
            <input type="text" id="subject" name="subject" required placeholder="Enter message subject"
                   class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500"
                   value="<?php echo htmlspecialchars($_POST['subject'] ?? $prefill_subject); ?>">
        </div>

        <div class="mb-6">
            <label for="body" class="block text-gray-700 text-sm font-bold mb-2">
                Message <span class="text-red-500">*</span>
            </label>
            <textarea id="body" name="body" required placeholder="Enter your message..." rows="12"
                      class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500"><?php echo htmlspecialchars($_POST['body'] ?? $prefill_body); ?></textarea>
        </div>

        <div class="flex gap-4">
            <button type="submit" class="bg-green-600 text-white px-6 py-2 rounded hover:bg-green-700 transition flex items-center">
                <i class="material-icons mr-2">send</i> Send Message
            </button>
            <a href="inbox" class="bg-gray-300 text-gray-700 px-6 py-2 rounded hover:bg-gray-400 transition">Cancel</a>
        </div>
    </form>
</div>

<?php include '../../includes/footer.php'; ?>
