<?php
require_once __DIR__ . '/../../config/app.php';
checkAuth();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/upload_helper.php';
require_once __DIR__ . '/../../libs/NotificationManager.php';

$user_id         = (int) $_SESSION['user_id'];
$conversation_id = (int) ($_GET['id'] ?? 0);

if (!$conversation_id) {
    redirect('modules/messaging/inbox');
}

// Verify conversation membership
$conv_stmt = $pdo->prepare(
    "SELECT * FROM conversations
     WHERE id = :id AND (participant1_id = $user_id OR participant2_id = $user_id)"
);
$conv_stmt->execute(['id' => $conversation_id]);
$conversation = $conv_stmt->fetch();
if (!$conversation) {
    redirect('modules/messaging/inbox');
}

$other_user_id = ($conversation['participant1_id'] == $user_id)
    ? $conversation['participant2_id']
    : $conversation['participant1_id'];

$user_stmt = $pdo->prepare("SELECT id, name, email, photo FROM users WHERE id = :id");
$user_stmt->execute(['id' => $other_user_id]);
$other_user = $user_stmt->fetch();

// All users for @mention / forward dropdown
$all_users = $pdo->query("SELECT id, name, photo FROM users ORDER BY name")->fetchAll();
$notifManager = new NotificationManager($pdo);

// Mark messages as read
$pdo->prepare(
    "UPDATE messages SET is_read = TRUE
     WHERE recipient_id = :uid AND sender_id = :oid AND is_read = FALSE"
)->execute(['uid' => $user_id, 'oid' => $other_user_id]);

// ─── Handle POST ──────────────────────────────────────────────────────────────
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'reply';

    // ── FORWARD ─────────────────────────────────────────────────────────────
    if ($action === 'forward') {
        $fwd_to   = (int) ($_POST['forward_to']   ?? 0);
        $fwd_body = trim($_POST['forward_body']   ?? '');
        $fwd_orig = (int) ($_POST['forward_msg_id'] ?? 0);

        if ($fwd_to && $fwd_body && $fwd_orig) {
            $stmt = $pdo->prepare(
                "INSERT INTO messages (sender_id, recipient_id, subject, body, forwarded_from_id)
                 VALUES (:sid, :rid, :subj, :body, :fwd)"
            );
            $stmt->execute([
                'sid'  => $user_id,
                'rid'  => $fwd_to,
                'subj' => 'Forwarded message',
                'body' => $fwd_body,
                'fwd'  => $fwd_orig,
            ]);
            $fwd_msg_id = $pdo->lastInsertId();

            // Upsert conversation with forward target
            $chk = $pdo->prepare(
                "SELECT id FROM conversations
                 WHERE (participant1_id = $user_id AND participant2_id = $fwd_to)
                    OR (participant1_id = $fwd_to AND participant2_id = $user_id)"
            );
            $chk->execute();
            $fwd_conv = $chk->fetch();
            if ($fwd_conv) {
                $pdo->prepare(
                    "UPDATE conversations SET last_message_id = :m, last_message_at = NOW() WHERE id = :c"
                )->execute(['m' => $fwd_msg_id, 'c' => $fwd_conv['id']]);
            } else {
                $pdo->prepare(
                    "INSERT INTO conversations (participant1_id, participant2_id, last_message_id, last_message_at)
                     VALUES (:a, :b, :m, NOW())"
                )->execute(['a' => $user_id, 'b' => $fwd_to, 'm' => $fwd_msg_id]);
            }

            $forwardConvId = $fwd_conv ? (int) $fwd_conv['id'] : (int) $pdo->lastInsertId();
            $notifManager->notify(
                $fwd_to,
                'message',
                'Forwarded Message',
                $_SESSION['user_name'] . ' forwarded you a message.',
                'modules/messaging/view?id=' . $forwardConvId,
                $fwd_msg_id,
                false,
                false,
                [
                    'fromUser' => $_SESSION['user_name'] ?? 'A colleague',
                    'subject' => 'Forwarded message',
                    'messagePreview' => $fwd_body,
                    'inboxUrl' => BASE_URL . 'modules/messaging/view?id=' . $forwardConvId,
                ]
            );

            header('Location: view?id=' . $conversation_id . '&success=message_forwarded');
            exit;
        }
        $error = 'Could not forward message — missing data.';

    // ── REPLY / SEND ─────────────────────────────────────────────────────────
    } else {
        $reply_body    = trim($_POST['reply'] ?? '');
        $reply_to_id   = (int) ($_POST['reply_to_id'] ?? 0) ?: null;
        $tagged_users  = trim($_POST['tagged_users'] ?? '') ?: null;
        $voice_note_sent = !empty($_POST['voice_note_sent']);

        $attachment_names = $_FILES['attachments']['name'] ?? null;
        $has_attachment_request = false;
        if (is_array($attachment_names)) {
            foreach ($attachment_names as $fn) {
                if ($fn !== '' && $fn !== null) {
                    $has_attachment_request = true;
                    break;
                }
            }
        } elseif (!empty($attachment_names)) {
            $has_attachment_request = true;
        }

        if ($reply_body === '' && !$has_attachment_request) {
            $error = 'Please enter a message or add a voice note or attachment.';
        } else {
            try {
                if ($reply_body === '') {
                    $reply_body = $voice_note_sent ? '🎤 Voice message' : '📎 Attachment';
                }
                // Insert message
                $stmt = $pdo->prepare(
                    "INSERT INTO messages (sender_id, recipient_id, subject, body, reply_to_id, tagged_users)
                     VALUES (:sid, :rid, :subj, :body, :rt, :tu)"
                );
                $stmt->execute([
                    'sid'  => $user_id,
                    'rid'  => $other_user_id,
                    'subj' => 'RE: conversation',
                    'body' => $reply_body,
                    'rt'   => $reply_to_id,
                    'tu'   => $tagged_users,
                ]);
                $message_id = (int) $pdo->lastInsertId();

                // Handle file attachments
                $upload_dir    = __DIR__ . '/../../uploads/messages/';
                $upload_prefix = 'uploads/messages';
                if (!is_dir($upload_dir)) {
                    mkdir($upload_dir, 0755, true);
                }

                if (!empty($_FILES['attachments']['name'][0])) {
                    $file_count = count($_FILES['attachments']['name']);
                    for ($i = 0; $i < $file_count; $i++) {
                        if ($_FILES['attachments']['error'][$i] !== UPLOAD_ERR_OK) {
                            continue;
                        }
                        $single_file = [
                            'name'     => $_FILES['attachments']['name'][$i],
                            'type'     => $_FILES['attachments']['type'][$i],
                            'tmp_name' => $_FILES['attachments']['tmp_name'][$i],
                            'error'    => $_FILES['attachments']['error'][$i],
                            'size'     => $_FILES['attachments']['size'][$i],
                        ];
                        try {
                            $path = store_validated_uploaded_file(
                                $single_file,
                                'message_attachment',
                                $upload_dir,
                                $upload_prefix,
                                'msg-'
                            );
                            $pdo->prepare(
                                "INSERT INTO message_attachments
                                    (message_id, file_name, original_name, file_path, file_type, file_size)
                                 VALUES (:mid, :fn, :on, :fp, :ft, :fs)"
                            )->execute([
                                'mid' => $message_id,
                                'fn'  => basename($path),
                                'on'  => htmlspecialchars($single_file['name']),
                                'fp'  => $path,
                                'ft'  => $single_file['type'],
                                'fs'  => $single_file['size'],
                            ]);
                        } catch (Exception $e) {
                            // Skip invalid files silently; message still sent
                        }
                    }
                }

                // Update conversation
                $pdo->prepare(
                    "UPDATE conversations SET last_message_id = :m, last_message_at = NOW() WHERE id = :c"
                )->execute(['m' => $message_id, 'c' => $conversation_id]);

                $notifManager->notify(
                    $other_user_id,
                    'message',
                    'New Reply',
                    'You have a new reply from ' . ($_SESSION['user_name'] ?? 'A colleague'),
                    'modules/messaging/view?id=' . $conversation_id,
                    $message_id,
                    false,
                    false,
                    [
                        'fromUser' => $_SESSION['user_name'] ?? 'A colleague',
                        'subject' => 'RE: conversation',
                        'messagePreview' => $reply_body,
                        'inboxUrl' => BASE_URL . 'modules/messaging/view?id=' . $conversation_id,
                    ]
                );

                header('Location: view?id=' . $conversation_id . '#bottom');
                exit;

            } catch (Exception $e) {
                $error = 'Error sending message: ' . $e->getMessage();
            }
        }
    }
}

// ─── Fetch messages with reply context ───────────────────────────────────────
$msg_stmt = $pdo->prepare(
    "SELECT m.*,
            u.name    AS sender_name,
            u.photo   AS sender_photo,
            rm.body   AS reply_to_body,
            rm.id     AS reply_to_msg_id,
            ru.name   AS reply_to_sender_name,
            fm.body   AS forwarded_body,
            fu.name   AS forwarded_from_name
     FROM messages m
     JOIN users u ON m.sender_id = u.id
     LEFT JOIN messages rm  ON m.reply_to_id = rm.id
     LEFT JOIN users ru    ON rm.sender_id = ru.id
     LEFT JOIN messages fm ON m.forwarded_from_id = fm.id
     LEFT JOIN users fu    ON fm.sender_id = fu.id
     WHERE (m.sender_id = $user_id AND m.recipient_id = $other_user_id)
        OR (m.sender_id = $other_user_id AND m.recipient_id = $user_id)
     ORDER BY m.created_at ASC"
);
$msg_stmt->execute();
$messages = $msg_stmt->fetchAll();

// ─── Fetch attachments indexed by message_id ─────────────────────────────────
$attachments_by_msg = [];
if ($messages) {
    $msg_ids = implode(',', array_map('intval', array_column($messages, 'id')));
    $att_rows = $pdo->query(
        "SELECT * FROM message_attachments WHERE message_id IN ($msg_ids) ORDER BY created_at ASC"
    )->fetchAll();
    foreach ($att_rows as $att) {
        $attachments_by_msg[(int)$att['message_id']][] = $att;
    }
}

// ─── Helper: render file attachment ──────────────────────────────────────────
function render_attachment(array $att): string {
    $path = htmlspecialchars($att['file_path']);
    $name = htmlspecialchars($att['original_name'] ?? $att['file_name']);
    $type = strtolower($att['file_type'] ?? '');
    $url  = htmlspecialchars(BASE_URL . ltrim((string) $att['file_path'], '/'));
    $is_img = strpos($type, 'image/') === 0;
    if ($is_img) {
        return '<a href="' . $url . '" target="_blank" rel="noopener noreferrer" class="chat-img-attach block mt-2">'
             . '<img src="' . $url . '" alt="' . $name . '" class="max-w-[220px] max-h-[180px] rounded-lg object-cover border border-white/30">'
             . '</a>';
    }
    if (function_exists('attachment_is_voice') && attachment_is_voice($att)) {
        return '<div class="chat-voice-note mt-2">'
             . '<audio controls preload="metadata" controlsList="nodownload" src="' . $url . '"></audio>'
             . '<span class="chat-voice-note-label">' . $name . '</span>'
             . '</div>';
    }
    $icon = (strpos($type, 'pdf') !== false) ? 'picture_as_pdf' : 'attach_file';
    return '<a href="' . $url . '" target="_blank" rel="noopener noreferrer" '
         . 'class="flex items-center gap-2 mt-2 text-xs bg-black/10 hover:bg-black/20 rounded-lg px-3 py-2 transition">'
         . '<i class="material-icons text-sm">' . $icon . '</i>'
         . '<span class="truncate max-w-[160px]">' . $name . '</span>'
         . '<i class="material-icons text-xs ml-auto">download</i>'
         . '</a>';
}

include '../../includes/header.php';
?>

<style>
/* ── Chat layout ─────────────────────────────────────────────── */
.chat-page          { display:flex; flex-direction:column; height:calc(100vh - 56px); max-height:calc(100vh - 56px); overflow:hidden; }
@media(min-width:768px){ .chat-page { height:calc(100vh - 0px); } }

.chat-header        { flex-shrink:0; background:#fff; border-bottom:1px solid #e5e7eb; padding:.75rem 1rem; display:flex; align-items:center; gap:.75rem; z-index:10; }
.chat-header-avatar { width:40px; height:40px; border-radius:50%; background:linear-gradient(135deg,#4f46e5,#7c3aed); color:#fff; display:flex; align-items:center; justify-content:center; font-weight:700; font-size:1rem; flex-shrink:0; }
.chat-header-info   { flex:1; min-width:0; }
.chat-header-name   { font-weight:700; font-size:.95rem; color:#111827; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.chat-header-sub    { font-size:.72rem; color:#6b7280; }

.chat-messages      { flex:1; overflow-y:auto; padding:1rem; background:#f0f2f5; display:flex; flex-direction:column; gap:.5rem; }
.chat-messages::-webkit-scrollbar { width:4px; } .chat-messages::-webkit-scrollbar-thumb { background:#d1d5db; border-radius:4px; }

/* ── Bubble ──────────────────────────────────────────────────── */
.msg-row            { display:flex; align-items:flex-end; gap:.5rem; }
.msg-row.mine       { justify-content:flex-end; }
.msg-row.theirs     { justify-content:flex-start; }

.bubble-wrap        { position:relative; max-width:72%; }
.msg-row.mine  .bubble-wrap { max-width:72%; }

.bubble             { padding:.6rem .9rem; border-radius:1rem; font-size:.875rem; line-height:1.5; word-break:break-word; position:relative; transition:transform .15s ease, box-shadow .15s ease; cursor:default; user-select:text; }
.msg-row.mine  .bubble { background:#dcf8c6; border-bottom-right-radius:.25rem; color:#111; }
.msg-row.theirs .bubble { background:#fff; border-bottom-left-radius:.25rem; color:#111; box-shadow:0 1px 2px rgba(0,0,0,.08); }

.bubble-meta        { font-size:.68rem; color:#6b7280; margin-top:.3rem; display:flex; gap:.4rem; align-items:center; }
.msg-row.mine .bubble-meta  { justify-content:flex-end; }
.msg-row.theirs .bubble-meta { justify-content:flex-start; }
.bubble-meta .sender { font-weight:600; color:#4f46e5; }

/* ── Reply quote ─────────────────────────────────────────────── */
.quote-block { background:rgba(0,0,0,.06); border-left:3px solid #4f46e5; border-radius:.4rem; padding:.35rem .6rem; margin-bottom:.4rem; font-size:.75rem; color:#374151; }
.quote-block .q-sender { font-weight:700; color:#4f46e5; font-size:.72rem; margin-bottom:.1rem; }

/* ── Forwarded label ─────────────────────────────────────────── */
.fwd-label { font-size:.7rem; color:#6b7280; display:flex; align-items:center; gap:.25rem; margin-bottom:.3rem; }

/* ── Tagged user chip ────────────────────────────────────────── */
.tag-chip { display:inline-flex; align-items:center; background:#ede9fe; color:#5b21b6; border-radius:9999px; padding:0 .45rem; font-size:.72rem; font-weight:600; }

/* ── Swipe gesture ───────────────────────────────────────────── */
.swipe-wrapper      { position:relative; overflow:hidden; border-radius:1rem; }
.swipe-action-hint  { position:absolute; top:50%; transform:translateY(-50%); width:32px; height:32px; border-radius:50%; display:flex; align-items:center; justify-content:center; opacity:0; transition:opacity .15s; pointer-events:none; font-size:1rem; }
.swipe-action-reply { left:6px;  background:#dcfce7; color:#16a34a; }
.swipe-action-fwd   { right:6px; background:#dbeafe; color:#2563eb; }
.swipe-wrapper.swiping-right .swipe-action-reply { opacity:1; }
.swipe-wrapper.swiping-left  .swipe-action-fwd   { opacity:1; }

/* ── Date divider ────────────────────────────────────────────── */
.date-divider { text-align:center; font-size:.72rem; color:#6b7280; margin:.5rem 0; display:flex; align-items:center; gap:.5rem; }
.date-divider::before,.date-divider::after { content:''; flex:1; height:1px; background:#e5e7eb; }

/* ── Reply-preview bar ───────────────────────────────────────── */
.reply-bar { display:none; background:#f9fafb; border-top:2px solid #4f46e5; padding:.5rem .75rem; gap:.75rem; align-items:flex-start; }
.reply-bar.active { display:flex; }
.reply-bar-content { flex:1; min-width:0; font-size:.8rem; color:#374151; }
.reply-bar-sender  { font-weight:700; color:#4f46e5; font-size:.75rem; }
.reply-bar-preview { white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }

/* ── Input area ──────────────────────────────────────────────── */
.chat-input-area    { flex-shrink:0; background:#fff; border-top:1px solid #e5e7eb; padding:.6rem .75rem; }
.chat-input-row     { display:flex; align-items:flex-end; gap:.5rem; }
.chat-textarea      { flex:1; resize:none; border:1px solid #d1d5db; border-radius:1.5rem; padding:.6rem 1rem; font-size:.875rem; max-height:120px; overflow-y:auto; outline:none; transition:border .2s; background:#f9fafb; }
.chat-textarea:focus { border-color:#4f46e5; background:#fff; }
.chat-send-btn      { width:40px; height:40px; border-radius:50%; background:#25d366; border:none; cursor:pointer; display:flex; align-items:center; justify-content:center; color:#fff; flex-shrink:0; transition:background .2s; }
.chat-send-btn:hover { background:#1da851; }
.chat-icon-btn      { width:36px; height:36px; border-radius:50%; background:transparent; border:none; cursor:pointer; display:flex; align-items:center; justify-content:center; color:#6b7280; flex-shrink:0; transition:background .2s; }
.chat-icon-btn:hover { background:#f3f4f6; color:#374151; }
.press-voice-mic-btn.press-voice-btn--recording { background:#fee2e2 !important; color:#b91c1c !important; }
.chat-voice-note { display:flex; flex-direction:column; gap:.2rem; align-items:stretch; max-width:min(280px,100%); }
.chat-voice-note audio { width:100%; height:36px; vertical-align:middle; }
.chat-voice-note-label { font-size:.65rem; opacity:.75; }

/* ── Attach preview strip ────────────────────────────────────── */
.attach-strip { display:flex; flex-wrap:wrap; gap:.4rem; padding:.3rem 0 .3rem .25rem; }
.attach-chip  { display:flex; align-items:center; gap:.3rem; background:#ede9fe; border-radius:.5rem; padding:.2rem .5rem; font-size:.72rem; color:#4f46e5; max-width:160px; }
.attach-chip .remove-attach { cursor:pointer; color:#7c3aed; display:flex; align-items:center; }

/* ── Forward modal ───────────────────────────────────────────── */
.modal-overlay { position:fixed; inset:0; background:rgba(0,0,0,.45); z-index:9998; display:none; }
.modal-overlay.active { display:flex; align-items:center; justify-content:center; padding:1rem; }
.fwd-modal     { background:#fff; border-radius:1rem; width:100%; max-width:400px; overflow:hidden; box-shadow:0 20px 60px rgba(0,0,0,.2); }

/* ── Mention dropdown ────────────────────────────────────────── */
.mention-list { position:absolute; bottom:100%; left:0; right:0; background:#fff; border:1px solid #e5e7eb; border-radius:.75rem; box-shadow:0 -4px 20px rgba(0,0,0,.1); max-height:160px; overflow-y:auto; z-index:100; display:none; }
.mention-list.active { display:block; }
.mention-item { padding:.5rem .85rem; cursor:pointer; font-size:.82rem; display:flex; align-items:center; gap:.5rem; }
.mention-item:hover { background:#f5f3ff; }
.mention-avatar { width:26px; height:26px; border-radius:50%; background:linear-gradient(135deg,#6366f1,#8b5cf6); color:#fff; display:flex; align-items:center; justify-content:center; font-size:.65rem; font-weight:700; flex-shrink:0; }
</style>

<?php
// Helper: avatar initials
function chat_initials(string $name): string {
    $parts = explode(' ', trim($name));
    if (count($parts) >= 2) return strtoupper(substr($parts[0],0,1) . substr($parts[1],0,1));
    return strtoupper(substr($name,0,2));
}

// Helper: format time for messages
function chat_time(string $ts): string {
    $now  = time();
    $then = strtotime($ts);
    $diff = $now - $then;
    if ($diff < 86400) return date('H:i', $then);
    if ($diff < 604800) return date('D H:i', $then);
    return date('M j, Y', $then);
}

function chat_date_label(string $ts): string {
    $d = strtotime($ts);
    $t = time();
    if (date('Y-m-d', $d) === date('Y-m-d', $t)) return 'Today';
    if (date('Y-m-d', $d) === date('Y-m-d', $t - 86400)) return 'Yesterday';
    return date('F j, Y', $d);
}

// Group messages by date
$grouped = [];
foreach ($messages as $msg) {
    $day = date('Y-m-d', strtotime($msg['created_at']));
    $grouped[$day][] = $msg;
}

// Encode all users for JS @mention
$all_users_json = json_encode(array_values(array_map(fn($u) => [
    'id'    => (int)$u['id'],
    'name'  => $u['name'],
    'photo' => (!empty($u['photo']) && $u['photo'] !== 'default.png') ? BASE_URL . ltrim($u['photo'], '/') : null,
], $all_users)));
$other_user_name_js = json_encode($other_user['name']);
?>

<div class="chat-page">

    <!-- ── Header ───────────────────────────────────────────── -->
    <div class="chat-header">
        <a href="inbox" class="chat-icon-btn" title="Back to inbox">
            <i class="material-icons" style="font-size:1.25rem">arrow_back</i>
        </a>
        <div class="chat-header-avatar" style="overflow:hidden;">
            <?php if (!empty($other_user['photo']) && $other_user['photo'] !== 'default.png'): ?>
                <img src="<?php echo htmlspecialchars(BASE_URL . ltrim($other_user['photo'], '/')); ?>" alt="<?php echo htmlspecialchars($other_user['name']); ?>" style="width:100%;height:100%;object-fit:cover;">
            <?php else: ?>
                <?php echo chat_initials($other_user['name']); ?>
            <?php endif; ?>
        </div>
        <div class="chat-header-info">
            <div class="chat-header-name"><?php echo htmlspecialchars($other_user['name']); ?></div>
            <div class="chat-header-sub"><?php echo htmlspecialchars($other_user['email']); ?></div>
        </div>
        <a href="send?reply_to=<?php echo $other_user_id; ?>" class="chat-icon-btn" title="New message">
            <i class="material-icons" style="font-size:1.2rem">edit</i>
        </a>
    </div>

    <?php if ($error): ?>
    <div class="flex-shrink-0 bg-red-50 border-b border-red-200 text-red-700 px-4 py-2 text-sm flex items-center gap-2">
        <i class="material-icons text-sm">error_outline</i> <?php echo htmlspecialchars($error); ?>
    </div>
    <?php endif; ?>

    <!-- ── Message feed ─────────────────────────────────────── -->
    <div class="chat-messages" id="chatMessages">

        <?php if (empty($messages)): ?>
        <div class="flex-1 flex flex-col items-center justify-center gap-2 text-gray-400 py-16">
            <i class="material-icons" style="font-size:3rem">chat_bubble_outline</i>
            <p class="text-sm">No messages yet — say hello!</p>
        </div>
        <?php endif; ?>

        <?php
        $prev_day = null;
        foreach ($grouped as $day => $day_messages):
            $label = chat_date_label($day_messages[0]['created_at']);
        ?>
        <div class="date-divider"><?php echo htmlspecialchars($label); ?></div>

        <?php foreach ($day_messages as $msg):
            $is_mine  = ($msg['sender_id'] == $user_id);
            $row_cls  = $is_mine ? 'mine' : 'theirs';
            $msg_json = json_encode([
                'id'     => $msg['id'],
                'body'   => $msg['body'],
                'sender' => $msg['sender_name'],
            ]);
        ?>
        <div class="msg-row <?php echo $row_cls; ?>" id="msg-<?php echo $msg['id']; ?>">

            <?php if (!$is_mine): ?>
            <div class="chat-header-avatar" style="width:30px;height:30px;font-size:.7rem;flex-shrink:0;overflow:hidden;">
                <?php if (!empty($msg['sender_photo']) && $msg['sender_photo'] !== 'default.png'): ?>
                    <img src="<?php echo htmlspecialchars(BASE_URL . ltrim($msg['sender_photo'], '/')); ?>" alt="<?php echo htmlspecialchars($msg['sender_name']); ?>" style="width:100%;height:100%;object-fit:cover;">
                <?php else: ?>
                    <?php echo chat_initials($msg['sender_name']); ?>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <div class="bubble-wrap">
                <div class="swipe-wrapper" data-msg='<?php echo htmlspecialchars($msg_json, ENT_QUOTES); ?>'>
                    <div class="swipe-action-hint swipe-action-reply"><i class="material-icons" style="font-size:1rem">reply</i></div>
                    <div class="swipe-action-hint swipe-action-fwd"><i class="material-icons" style="font-size:1rem">forward</i></div>

                    <div class="bubble">
                        <?php if ($msg['forwarded_from_id']): ?>
                        <div class="fwd-label">
                            <i class="material-icons" style="font-size:.85rem">forward</i>
                            Forwarded from <?php echo htmlspecialchars($msg['forwarded_from_name'] ?? 'someone'); ?>
                        </div>
                        <?php endif; ?>

                        <?php if ($msg['reply_to_id']): ?>
                        <div class="quote-block">
                            <div class="q-sender"><?php echo htmlspecialchars($msg['reply_to_sender_name'] ?? 'User'); ?></div>
                            <div><?php echo htmlspecialchars(mb_substr($msg['reply_to_body'] ?? '', 0, 120)); ?><?php echo strlen($msg['reply_to_body'] ?? '') > 120 ? '…' : ''; ?></div>
                        </div>
                        <?php endif; ?>

                        <p><?php
                            // Render @mention tags highlighted
                            $body_html = htmlspecialchars($msg['body']);
                            $body_html = preg_replace('/@([A-Za-z0-9 ]+?)(?=\s|$|[^A-Za-z0-9 ])/u',
                                '<span class="tag-chip">@$1</span>', $body_html);
                            echo nl2br($body_html);
                        ?></p>

                        <?php if (!empty($attachments_by_msg[$msg['id']])): ?>
                            <?php foreach ($attachments_by_msg[$msg['id']] as $att): ?>
                                <?php echo render_attachment($att); ?>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="bubble-meta">
                    <?php if (!$is_mine): ?>
                        <span class="sender"><?php echo htmlspecialchars($msg['sender_name']); ?></span> •
                    <?php endif; ?>
                    <span><?php echo chat_time($msg['created_at']); ?></span>
                    <?php if ($is_mine): ?>
                        <i class="material-icons" style="font-size:.85rem; color:<?php echo $msg['is_read'] ? '#34b7f1' : '#aaa'; ?>">done_all</i>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
        <?php endforeach; ?>

        <div id="bottom"></div>
    </div>

    <!-- ── Reply preview bar ────────────────────────────────── -->
    <div class="reply-bar" id="replyBar">
        <div style="width:3px; background:#4f46e5; border-radius:3px; flex-shrink:0;"></div>
        <div class="reply-bar-content">
            <div class="reply-bar-sender" id="replyBarSender"></div>
            <div class="reply-bar-preview" id="replyBarPreview"></div>
        </div>
        <button onclick="cancelReply()" class="chat-icon-btn" style="flex-shrink:0;">
            <i class="material-icons" style="font-size:1.1rem">close</i>
        </button>
    </div>

    <!-- ── Input area ────────────────────────────────────────── -->
    <div class="chat-input-area">
        <div id="attachStrip" class="attach-strip" style="display:none;"></div>

        <form method="POST" action="view?id=<?php echo (int) $conversation_id; ?>" enctype="multipart/form-data" id="chatForm" novalidate>
            <input type="hidden" name="action" value="reply">
            <input type="hidden" name="voice_note_sent" id="voiceNoteSentMsg" value="0">
            <input type="hidden" name="reply_to_id" id="replyToId" value="">
            <input type="hidden" name="tagged_users" id="taggedUsersInput" value="">
            <input type="file" id="fileInput" name="attachments[]" multiple accept=".jpg,.jpeg,.png,.gif,.webp,.pdf,.doc,.docx,.webm,.ogg,.opus,.mp3,.wav,.m4a,.aac,audio/*" style="display:none">

            <div class="relative">
                <div class="mention-list" id="mentionList"></div>
            </div>

            <div class="voice-note-status text-xs text-gray-500 mb-1 px-1 min-h-[1rem]" id="voiceNoteStatusMsg" aria-live="polite"></div>

            <div class="chat-input-row">
                <button type="button" class="chat-icon-btn" onclick="document.getElementById('fileInput').click()" title="Attach file">
                    <i class="material-icons" style="font-size:1.2rem">attach_file</i>
                </button>

                <button type="button" class="chat-icon-btn press-voice-mic-btn" id="voiceMicBtnMsg" title="Voice note">
                    <i class="material-icons" style="font-size:1.2rem">mic</i>
                </button>

                <textarea id="chatTextarea" name="reply" class="chat-textarea" rows="1"
                          placeholder="Type a message…"></textarea>

                <button type="submit" class="chat-send-btn" title="Send">
                    <i class="material-icons" style="font-size:1.1rem">send</i>
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ── Forward Modal ─────────────────────────────────────────── -->
<div class="modal-overlay" id="fwdOverlay">
    <div class="fwd-modal">
        <div class="p-4 border-b border-gray-100 flex items-center justify-between">
            <h3 class="font-bold text-gray-800 flex items-center gap-2">
                <i class="material-icons text-blue-600">forward</i> Forward Message
            </h3>
            <button onclick="closeFwdModal()" class="chat-icon-btn"><i class="material-icons">close</i></button>
        </div>
        <form method="POST" action="view?id=<?php echo (int) $conversation_id; ?>" class="p-4" id="fwdForm">
            <input type="hidden" name="action" value="forward">
            <input type="hidden" name="forward_msg_id" id="fwdMsgId" value="">

            <div class="mb-3">
                <label class="block text-sm font-bold text-gray-700 mb-1">Original message</label>
                <div id="fwdOrigText" class="bg-gray-50 rounded-lg p-3 text-sm text-gray-600 border border-gray-200 max-h-24 overflow-y-auto"></div>
            </div>

            <div class="mb-3">
                <label class="block text-sm font-bold text-gray-700 mb-1">Forward to</label>
                <select name="forward_to" id="fwdToSelect" required
                        class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 text-sm">
                    <option value="">— Select recipient —</option>
                    <?php foreach ($all_users as $u): ?>
                    <?php if ($u['id'] == $user_id) continue; ?>
                    <option value="<?php echo $u['id']; ?>"><?php echo htmlspecialchars($u['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="mb-4">
                <label class="block text-sm font-bold text-gray-700 mb-1">Add a note (optional)</label>
                <textarea name="forward_body" id="fwdBody" rows="2"
                          class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 text-sm"
                          placeholder="FYI — see forwarded message below…"></textarea>
            </div>

            <div class="flex gap-3">
                <button type="submit" class="flex-1 bg-blue-600 text-white py-2 rounded-lg font-bold hover:bg-blue-700 transition text-sm flex items-center justify-center gap-2">
                    <i class="material-icons text-sm">forward</i> Forward
                </button>
                <button type="button" onclick="closeFwdModal()" class="flex-1 bg-gray-100 text-gray-700 py-2 rounded-lg font-bold hover:bg-gray-200 transition text-sm">Cancel</button>
            </div>
        </form>
    </div>
</div>

<script>
(function(){
    // ── Scroll to bottom on load ───────────────────────────────
    const feed = document.getElementById('chatMessages');
    if (feed) feed.scrollTop = feed.scrollHeight;

    // ── Auto-resize textarea ───────────────────────────────────
    const ta = document.getElementById('chatTextarea');
    ta.addEventListener('input', function(){
        this.style.height = 'auto';
        this.style.height = Math.min(this.scrollHeight, 120) + 'px';
    });

    // ── Send on Ctrl/Cmd+Enter ─────────────────────────────────
    ta.addEventListener('keydown', function(e){
        if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
            e.preventDefault();
            document.getElementById('chatForm').submit();
        }
    });

    // ── Reply state ────────────────────────────────────────────
    let replyData = null;

    window.setReply = function(msgData) {
        replyData = msgData;
        document.getElementById('replyToId').value   = msgData.id;
        document.getElementById('replyBarSender').textContent  = msgData.sender;
        document.getElementById('replyBarPreview').textContent = msgData.body.substring(0, 100) + (msgData.body.length > 100 ? '…' : '');
        document.getElementById('replyBar').classList.add('active');
        ta.focus();
    };

    window.cancelReply = function() {
        replyData = null;
        document.getElementById('replyToId').value = '';
        document.getElementById('replyBar').classList.remove('active');
    };

    // ── Forward modal ──────────────────────────────────────────
    window.openFwdModal = function(msgData) {
        document.getElementById('fwdMsgId').value   = msgData.id;
        document.getElementById('fwdOrigText').textContent = msgData.body;
        document.getElementById('fwdBody').value    = '▷ ' + msgData.body.substring(0, 200);
        document.getElementById('fwdOverlay').classList.add('active');
    };

    window.closeFwdModal = function() {
        document.getElementById('fwdOverlay').classList.remove('active');
    };

    document.getElementById('fwdOverlay').addEventListener('click', function(e){
        if (e.target === this) closeFwdModal();
    });

    // ── Swipe gesture ──────────────────────────────────────────
    const SWIPE_THRESHOLD = 55;

    document.querySelectorAll('.swipe-wrapper').forEach(wrapper => {
        const msgData = JSON.parse(wrapper.dataset.msg || '{}');
        let startX = 0, startY = 0, startT = 0, dragging = false, dx = 0;

        function onStart(x, y) {
            startX = x; startY = y; startT = Date.now(); dragging = true; dx = 0;
        }
        function onMove(x, y) {
            if (!dragging) return;
            dx = x - startX;
            const dy = Math.abs(y - startY);
            if (dy > 20 && Math.abs(dx) < dy) { dragging = false; reset(); return; }
            const clamped = Math.max(-80, Math.min(80, dx));
            wrapper.querySelector('.bubble').style.transform = `translateX(${clamped}px)`;
            wrapper.classList.toggle('swiping-right', clamped > 20);
            wrapper.classList.toggle('swiping-left',  clamped < -20);
        }
        function onEnd() {
            if (!dragging) return;
            dragging = false;
            if (dx > SWIPE_THRESHOLD)  { setReply(msgData); }
            else if (dx < -SWIPE_THRESHOLD) { openFwdModal(msgData); }
            reset();
        }
        function reset() {
            wrapper.querySelector('.bubble').style.transform = '';
            wrapper.classList.remove('swiping-right','swiping-left');
        }

        // Touch
        wrapper.addEventListener('touchstart', e => { onStart(e.touches[0].clientX, e.touches[0].clientY); }, {passive:true});
        wrapper.addEventListener('touchmove',  e => { onMove(e.touches[0].clientX, e.touches[0].clientY); },  {passive:true});
        wrapper.addEventListener('touchend',   () => onEnd());

        // Mouse (desktop)
        wrapper.addEventListener('mousedown',  e => { onStart(e.clientX, e.clientY); e.preventDefault(); });
        document.addEventListener('mousemove', e => { if (dragging) onMove(e.clientX, e.clientY); });
        document.addEventListener('mouseup',   () => { if (dragging) onEnd(); });
    });

    // ── File attachment preview ────────────────────────────────
    const fileInput   = document.getElementById('fileInput');
    const attachStrip = document.getElementById('attachStrip');
    let selectedFiles = [];

    fileInput.addEventListener('change', function(){
        document.getElementById('voiceNoteSentMsg').value = '0';
        selectedFiles = Array.from(this.files);
        renderAttachStrip();
    });

    function renderAttachStrip() {
        if (!selectedFiles.length) { attachStrip.style.display = 'none'; document.getElementById('voiceNoteSentMsg').value = '0'; return; }
        attachStrip.style.display = 'flex';
        attachStrip.innerHTML = '';
        selectedFiles.forEach((f, i) => {
            const chip = document.createElement('div');
            chip.className = 'attach-chip';
            chip.innerHTML = `<i class="material-icons" style="font-size:.9rem">attach_file</i>`
                           + `<span class="truncate">${escHtml(f.name)}</span>`
                           + `<span class="remove-attach" data-i="${i}"><i class="material-icons" style="font-size:.85rem">close</i></span>`;
            attachStrip.appendChild(chip);
        });
        attachStrip.querySelectorAll('.remove-attach').forEach(btn => {
            btn.addEventListener('click', function(){
                const idx = parseInt(this.dataset.i, 10);
                selectedFiles.splice(idx, 1);
                rebuildFileInput();
                renderAttachStrip();
            });
        });
    }

    function rebuildFileInput() {
        const dt = new DataTransfer();
        selectedFiles.forEach(f => dt.items.add(f));
        fileInput.files = dt.files;
    }

    function escHtml(s) {
        return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    // ── @mention dropdown ──────────────────────────────────────
    const ALL_USERS      = <?php echo $all_users_json; ?>;
    const mentionList    = document.getElementById('mentionList');
    const taggedUsersInp = document.getElementById('taggedUsersInput');
    let taggedIds = [];

    ta.addEventListener('input', function(){
        const val    = this.value;
        const cursor = this.selectionStart;
        const before = val.substring(0, cursor);
        const match  = before.match(/@([A-Za-z0-9 ]*)$/);
        if (!match) { mentionList.classList.remove('active'); return; }
        const q = match[1].toLowerCase();
        const filtered = ALL_USERS.filter(u =>
            u.name.toLowerCase().includes(q) && !taggedIds.includes(u.id)
        ).slice(0, 6);
        if (!filtered.length) { mentionList.classList.remove('active'); return; }
        mentionList.innerHTML = filtered.map(u => {
            const avatarHtml = u.photo
                ? `<img src="${escHtml(u.photo)}" alt="" style="width:26px;height:26px;border-radius:50%;object-fit:cover;flex-shrink:0;">`
                : `<div class="mention-avatar">${escHtml(u.name.substring(0,2).toUpperCase())}</div>`;
            return `<div class="mention-item" data-id="${u.id}" data-name="${escHtml(u.name)}">${avatarHtml}<span>${escHtml(u.name)}</span></div>`;
        }).join('');
        mentionList.classList.add('active');
        mentionList.querySelectorAll('.mention-item').forEach(item => {
            item.addEventListener('mousedown', function(e){
                e.preventDefault();
                const id   = parseInt(this.dataset.id, 10);
                const name = this.dataset.name;
                // Replace partial @query with @Full Name
                const newBefore = before.replace(/@([A-Za-z0-9 ]*)$/, '@' + name + ' ');
                ta.value = newBefore + val.substring(cursor);
                ta.setSelectionRange(newBefore.length, newBefore.length);
                if (!taggedIds.includes(id)) taggedIds.push(id);
                taggedUsersInp.value = JSON.stringify(taggedIds);
                mentionList.classList.remove('active');
                ta.focus();
            });
        });
    });

    ta.addEventListener('blur', () => setTimeout(() => mentionList.classList.remove('active'), 150));

    document.addEventListener('DOMContentLoaded', function () {
        const chatFormEl = document.getElementById('chatForm');
        if (chatFormEl) {
            chatFormEl.addEventListener('submit', function (e) {
                if (!ta.value.trim() && !selectedFiles.length) {
                    e.preventDefault();
                    const m = 'Type a message, attach a file, or record a voice note.';
                    if (typeof window.showToast === 'function') {
                        window.showToast(m, 'error');
                    } else {
                        window.alert(m);
                    }
                    return false;
                }
                return true;
            });
        }

        if (window.PressVoiceNote && document.getElementById('voiceMicBtnMsg')) {
            PressVoiceNote.bindToggle({
                button: '#voiceMicBtnMsg',
                fileInput: '#fileInput',
                hiddenVoiceInput: '#voiceNoteSentMsg',
                statusEl: '#voiceNoteStatusMsg',
                maxSeconds: 180,
                onFile: function (file) {
                    selectedFiles.push(file);
                    rebuildFileInput();
                    renderAttachStrip();
                },
            });
        }
    });
})();
</script>

<?php include '../../includes/footer.php'; ?>
