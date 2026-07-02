<?php
require_once __DIR__ . '/../../config/app.php';
checkAuth();
require_once __DIR__ . '/../../config/database.php';

$user_id      = (int) $_SESSION['user_id'];
$search_query = trim($_GET['search'] ?? '');
$filter_type  = $_GET['filter'] ?? 'all';

$query = "SELECT DISTINCT
    c.id  AS conversation_id,
    CASE WHEN c.participant1_id = $user_id THEN c.participant2_id ELSE c.participant1_id END AS other_user_id,
    u.name  AS other_user_name,
    u.email AS other_user_email,
    u.photo AS other_user_photo,
    m.body  AS last_message,
    m.sender_id,
    m.is_read,
    c.last_message_at,
    (SELECT COUNT(*) FROM messages
     WHERE (sender_id = $user_id AND recipient_id = u.id)
        OR (sender_id = u.id AND recipient_id = $user_id)) AS total_messages,
    (SELECT COUNT(*) FROM messages
     WHERE sender_id = u.id AND recipient_id = $user_id AND is_read = FALSE) AS unread_count
FROM conversations c
JOIN messages m ON c.last_message_id = m.id
JOIN users u ON (
    (c.participant1_id = $user_id AND c.participant2_id = u.id) OR
    (c.participant2_id = $user_id AND c.participant1_id = u.id)
)
WHERE (c.participant1_id = $user_id OR c.participant2_id = $user_id)";

$params = [];

if (!empty($search_query)) {
    $query .= " AND (u.name LIKE :search_name OR m.body LIKE :search_body)";
    $params['search_name'] = '%' . $search_query . '%';
    $params['search_body'] = '%' . $search_query . '%';
}
if ($filter_type === 'unread') {
    $query .= " AND m.is_read = FALSE AND m.recipient_id = $user_id";
}
$query .= " ORDER BY c.last_message_at DESC";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$conversations = $stmt->fetchAll();

$unread_stmt = $pdo->prepare(
    "SELECT COUNT(*) FROM messages WHERE recipient_id = :uid AND is_read = FALSE"
);
$unread_stmt->execute(['uid' => $user_id]);
$unread_count = (int) $unread_stmt->fetchColumn();

function inbox_initials(string $name): string {
    $parts = explode(' ', trim($name));
    if (count($parts) >= 2) return strtoupper(substr($parts[0],0,1) . substr($parts[1],0,1));
    return strtoupper(substr($name,0,2));
}

function inbox_time(string $ts): string {
    $d = strtotime($ts);
    $t = time();
    if (date('Y-m-d', $d) === date('Y-m-d', $t))      return date('H:i', $d);
    if (date('Y-m-d', $d) === date('Y-m-d', $t-86400)) return 'Yesterday';
    if ($t - $d < 604800) return date('D', $d);
    return date('M j', $d);
}

include '../../includes/header.php';
?>

<style>
.inbox-page     { max-width:680px; margin:0 auto; }
.inbox-header   { display:flex; align-items:center; justify-content:space-between; margin-bottom:1.25rem; }
.inbox-title    { font-size:1.4rem; font-weight:800; color:#111827; }
.inbox-badge    { display:inline-flex; align-items:center; background:#ef4444; color:#fff; border-radius:9999px; font-size:.7rem; font-weight:700; padding:0 .5rem; height:1.3rem; margin-left:.4rem; }

.inbox-search   { background:#fff; border-radius:1rem; box-shadow:0 1px 4px rgba(0,0,0,.07); padding:.75rem 1rem; margin-bottom:1rem; display:flex; gap:.6rem; align-items:center; }
.inbox-search input  { flex:1; border:none; outline:none; font-size:.875rem; background:transparent; color:#374151; }
.inbox-search input::placeholder { color:#9ca3af; }
.inbox-filter-tabs  { display:flex; gap:.4rem; margin-bottom:.75rem; }
.inbox-tab      { padding:.35rem .85rem; border-radius:9999px; font-size:.78rem; font-weight:600; cursor:pointer; border:none; transition:background .15s, color .15s; }
.inbox-tab.active   { background:#4f46e5; color:#fff; }
.inbox-tab:not(.active) { background:#f3f4f6; color:#6b7280; }
.inbox-tab:not(.active):hover { background:#e5e7eb; color:#374151; }

.conv-card      { display:flex; align-items:center; gap:.85rem; padding:.85rem 1rem; background:#fff; border-radius:.85rem; box-shadow:0 1px 3px rgba(0,0,0,.06); margin-bottom:.5rem; cursor:pointer; transition:box-shadow .15s, background .15s; text-decoration:none; color:inherit; border:1.5px solid transparent; position:relative; }
.conv-card:hover { box-shadow:0 4px 12px rgba(0,0,0,.1); background:#fafafe; }
.conv-card.unread { border-color:#6366f1; background:#faf5ff; }

.conv-avatar    { width:46px; height:46px; border-radius:50%; background:linear-gradient(135deg,#4f46e5,#7c3aed); color:#fff; display:flex; align-items:center; justify-content:center; font-weight:700; font-size:1rem; flex-shrink:0; position:relative; }
.conv-avatar-badge { position:absolute; bottom:0; right:0; width:14px; height:14px; background:#22c55e; border-radius:50%; border:2px solid #fff; }

.conv-body      { flex:1; min-width:0; }
.conv-name      { font-weight:700; font-size:.9rem; color:#111827; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; display:flex; align-items:center; gap:.4rem; }
.conv-preview   { font-size:.8rem; color:#6b7280; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; margin-top:.15rem; }
.conv-preview.bold { color:#374151; font-weight:600; }

.conv-meta      { text-align:right; flex-shrink:0; }
.conv-time      { font-size:.72rem; color:#9ca3af; }
.conv-unread-badge { display:inline-flex; align-items:center; justify-content:center; background:#4f46e5; color:#fff; border-radius:9999px; font-size:.68rem; font-weight:700; min-width:1.2rem; height:1.2rem; padding:0 .35rem; margin-top:.3rem; }

.empty-state    { text-align:center; padding:3rem 1rem; color:#9ca3af; }
</style>

<div class="inbox-page">
    <div class="inbox-header">
        <div>
            <span class="inbox-title">Messages</span>
            <?php if ($unread_count > 0): ?>
            <span class="inbox-badge"><?php echo $unread_count; ?></span>
            <?php endif; ?>
        </div>
        <a href="send" class="flex items-center gap-1.5 bg-indigo-600 text-white text-sm font-bold px-4 py-2 rounded-full hover:bg-indigo-700 transition shadow">
            <i class="material-icons" style="font-size:1rem">edit</i> New
        </a>
    </div>

    <!-- Search bar -->
    <form method="GET" action="" class="inbox-search">
        <i class="material-icons text-gray-400" style="font-size:1.1rem">search</i>
        <input type="text" name="search" placeholder="Search conversations…"
               value="<?php echo htmlspecialchars($search_query); ?>" autocomplete="off">
        <?php if ($search_query): ?>
        <a href="inbox" class="text-gray-400 hover:text-gray-600" title="Clear"><i class="material-icons" style="font-size:1rem">close</i></a>
        <?php endif; ?>
        <button type="submit" style="display:none"></button>
    </form>

    <!-- Filter tabs -->
    <div class="inbox-filter-tabs">
        <a href="inbox<?php echo $search_query ? '?search='.urlencode($search_query) : ''; ?>"
           class="inbox-tab <?php echo $filter_type === 'all' ? 'active' : ''; ?>">All</a>
        <a href="inbox?filter=unread<?php echo $search_query ? '&search='.urlencode($search_query) : ''; ?>"
           class="inbox-tab <?php echo $filter_type === 'unread' ? 'active' : ''; ?>">
            Unread<?php if ($unread_count): ?> <span style="margin-left:.25rem;background:rgba(255,255,255,.3);border-radius:9999px;padding:0 .35rem;"><?php echo $unread_count; ?></span><?php endif; ?>
        </a>
    </div>

    <!-- Conversation list -->
    <div>
        <?php if (empty($conversations)): ?>
        <div class="empty-state">
            <i class="material-icons" style="font-size:3rem;margin-bottom:.5rem;display:block">mail_outline</i>
            <p class="text-base font-semibold mb-1">No conversations</p>
            <p class="text-sm mb-4"><?php echo $search_query ? 'No results for "'.htmlspecialchars($search_query).'"' : 'Start a new message to connect with a colleague.'; ?></p>
            <a href="send" class="inline-flex items-center gap-1 bg-indigo-600 text-white text-sm font-bold px-5 py-2 rounded-full hover:bg-indigo-700 transition">
                <i class="material-icons" style="font-size:.9rem">edit</i> New Message
            </a>
        </div>
        <?php else: ?>
        <?php foreach ($conversations as $conv):
            $is_unread   = ($conv['sender_id'] != $user_id && !$conv['is_read']);
            $unread_cnt  = (int) $conv['unread_count'];
            $initials    = inbox_initials($conv['other_user_name']);
            $preview     = htmlspecialchars(mb_substr($conv['last_message'], 0, 70));
            if (mb_strlen($conv['last_message']) > 70) $preview .= '…';
            $sender_pfx  = ($conv['sender_id'] == $user_id) ? 'You: ' : '';
        ?>
        <a href="view?id=<?php echo $conv['conversation_id']; ?>" class="conv-card <?php echo $is_unread ? 'unread' : ''; ?>">
            <div class="conv-avatar">
                <?php if (!empty($conv['other_user_photo']) && $conv['other_user_photo'] !== 'default.png'): ?>
                    <img src="<?php echo htmlspecialchars(BASE_URL . ltrim($conv['other_user_photo'], '/')); ?>" alt="<?php echo htmlspecialchars($conv['other_user_name']); ?>" style="width:100%;height:100%;object-fit:cover;border-radius:50%;">
                <?php else: ?>
                    <?php echo $initials; ?>
                <?php endif; ?>
            </div>
            <div class="conv-body">
                <div class="conv-name">
                    <?php echo htmlspecialchars($conv['other_user_name']); ?>
                    <span style="font-size:.68rem;color:#9ca3af;font-weight:400;"><?php echo $conv['total_messages']; ?> msgs</span>
                </div>
                <div class="conv-preview <?php echo $is_unread ? 'bold' : ''; ?>">
                    <?php echo $sender_pfx . $preview; ?>
                </div>
            </div>
            <div class="conv-meta">
                <div class="conv-time"><?php echo inbox_time($conv['last_message_at']); ?></div>
                <?php if ($unread_cnt > 0): ?>
                <div class="conv-unread-badge"><?php echo $unread_cnt; ?></div>
                <?php endif; ?>
            </div>
        </a>
        <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>
