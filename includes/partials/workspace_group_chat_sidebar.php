<?php
/**
 * Workspace group-chat sidebar (requires premium-modules / Tailwind-compatible utilities).
 *
 * Expects:
 * - $workspaceGroupChatShow (bool)
 * - $workspaceGroupChatTitle (string)
 * - $workspaceGroupChatFeedId (string)
 * - $workspaceGroupChatFormAction (string)
 * - $workspaceGroupChatFormMethod (string, default POST)
 * - $workspaceGroupChatFormEnctype (?string)
 * - $workspaceGroupChatHiddenFields (array<string,string|int>)
 * - $workspaceGroupChatParticipants list of rows id, name, photo?
 * - $workspaceGroupChatMessages list of rows: user_id, user_name, user_photo?, body, created_at, attachments? array
 * - $workspaceGroupChatCurrentUserId (int)
 * - $workspaceGroupChatPlaceholder (?string)
 */
if (!function_exists('attachment_is_voice')) {
    require_once __DIR__ . '/../upload_helper.php';
}

if (empty($workspaceGroupChatShow)) {
    return;
}

$formMethod = $workspaceGroupChatFormMethod ?? 'POST';
$formEnctype = $workspaceGroupChatFormEnctype ?? '';
$multipart = ($formEnctype === 'multipart/form-data');
$feedId = $workspaceGroupChatFeedId ?? 'workspace-group-chat-feed';
$formAction = $workspaceGroupChatFormAction ?? '';
$hiddenFields = is_array($workspaceGroupChatHiddenFields ?? null) ? $workspaceGroupChatHiddenFields : [];
$participants = is_array($workspaceGroupChatParticipants ?? null) ? $workspaceGroupChatParticipants : [];
$messages = is_array($workspaceGroupChatMessages ?? null) ? $workspaceGroupChatMessages : [];
$currentUid = (int) ($workspaceGroupChatCurrentUserId ?? 0);
$placeholder = (string) ($workspaceGroupChatPlaceholder ?? 'Write a message…');

$avatarInitials = static function (string $name): string {
    $name = trim($name);
    if ($name === '') {
        return '?';
    }
    $parts = preg_split('/\s+/u', $name);
    if (count($parts) >= 2) {
        return strtoupper(mb_substr($parts[0], 0, 1) . mb_substr($parts[1], 0, 1));
    }

    return strtoupper(mb_substr($name, 0, 2));
};
?>

<aside class="workspace-chat-pane" aria-label="Group chat">
    <header class="workspace-chat-pane-head">
        <div class="min-w-0">
            <h2 class="workspace-chat-pane-title truncate" title="<?php echo htmlspecialchars((string) $workspaceGroupChatTitle, ENT_QUOTES, 'UTF-8'); ?>">
                <?php echo htmlspecialchars((string) $workspaceGroupChatTitle, ENT_QUOTES, 'UTF-8'); ?>
            </h2>
            <p class="workspace-chat-pane-sub"><?php echo number_format(count($participants)); ?> participant<?php echo count($participants) === 1 ? '' : 's'; ?></p>
        </div>
    </header>

    <section class="workspace-chat-members" aria-label="People on this project">
        <div class="workspace-chat-members-row">
            <span class="workspace-chat-members-label">Members</span>
            <span class="workspace-chat-members-count"><?php echo number_format(count($participants)); ?></span>
        </div>
        <div class="workspace-chat-avatars" role="list">
            <?php foreach ($participants as $p): ?>
                <?php
                $pid = (int) ($p['id'] ?? 0);
                $pname = (string) ($p['name'] ?? 'Member');
                $pphoto = $p['photo'] ?? null;
                ?>
                <div class="workspace-chat-avatar-wrap" role="listitem" title="<?php echo htmlspecialchars($pname, ENT_QUOTES, 'UTF-8'); ?>">
                    <?php if (!empty($pphoto) && $pphoto !== 'default.png'): ?>
                        <img src="<?php echo htmlspecialchars(BASE_URL . ltrim((string) $pphoto, '/'), ENT_QUOTES, 'UTF-8'); ?>" alt="" class="workspace-chat-avatar-img">
                    <?php else: ?>
                        <span class="workspace-chat-avatar-fallback"><?php echo htmlspecialchars($avatarInitials($pname)); ?></span>
                    <?php endif; ?>
                    <?php if ($pid === $currentUid): ?>
                        <span class="workspace-chat-you-badge">you</span>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="workspace-chat-thread" aria-label="Group chat messages">
        <div class="workspace-chat-thread-heading">Group chat</div>
        <div class="workspace-chat-feed" id="<?php echo htmlspecialchars($feedId, ENT_QUOTES, 'UTF-8'); ?>">
            <?php if ($messages === []): ?>
                <div class="workspace-chat-empty">No messages yet. Say hello.</div>
            <?php else: ?>
                <?php foreach ($messages as $msg): ?>
                    <?php
                    $senderId = (int) ($msg['user_id'] ?? 0);
                    $senderName = (string) ($msg['user_name'] ?? 'Member');
                    $senderPhoto = $msg['user_photo'] ?? null;
                    $isSelf = ($senderId > 0 && $senderId === $currentUid);
                    $bodyRaw = (string) ($msg['body'] ?? '');
                    $bubbleClass = $isSelf ? 'workspace-chat-bubble workspace-chat-bubble--self' : 'workspace-chat-bubble workspace-chat-bubble--peer';
                    $attachments = is_array($msg['attachments'] ?? null) ? $msg['attachments'] : [];
                    ?>
                    <div class="<?php echo $bubbleClass; ?>">
                        <div class="workspace-chat-bubble-meta">
                            <?php if (!$isSelf): ?>
                                <?php if (!empty($senderPhoto) && $senderPhoto !== 'default.png'): ?>
                                    <img src="<?php echo htmlspecialchars(BASE_URL . ltrim((string) $senderPhoto, '/'), ENT_QUOTES, 'UTF-8'); ?>" alt="" class="workspace-chat-bubble-avatar">
                                <?php else: ?>
                                    <span class="workspace-chat-bubble-avatar-fallback"><?php echo htmlspecialchars($avatarInitials($senderName)); ?></span>
                                <?php endif; ?>
                            <?php endif; ?>
                            <div class="workspace-chat-bubble-head">
                                <span class="workspace-chat-bubble-name"><?php echo htmlspecialchars($senderName, ENT_QUOTES, 'UTF-8'); ?></span>
                                <time class="workspace-chat-bubble-time" datetime="<?php echo htmlspecialchars(date('c', strtotime((string) ($msg['created_at'] ?? 'now'))), ENT_QUOTES, 'UTF-8'); ?>">
                                    <?php echo date('M j · g:i A', strtotime((string) ($msg['created_at'] ?? 'now'))); ?>
                                </time>
                            </div>
                        </div>
                        <div class="workspace-chat-bubble-body"><?php echo nl2br(htmlspecialchars($bodyRaw, ENT_QUOTES, 'UTF-8')); ?></div>
                        <?php if ($attachments !== []): ?>
                            <div class="workspace-chat-bubble-files">
                                <?php foreach ($attachments as $att): ?>
                                    <?php
                                    $fn = (string) ($att['file_name'] ?? 'file');
                                    $fp = (string) ($att['file_path'] ?? '');
                                    $ahref = $fp !== '' ? rtrim(BASE_URL, '/') . '/' . ltrim($fp, '/') : '#';
                                    $is_voice = attachment_is_voice($att);
                                    ?>
                                    <?php if ($is_voice): ?>
                                    <div class="workspace-chat-voice-row">
                                        <audio class="workspace-chat-voice-audio" controls preload="metadata" src="<?php echo htmlspecialchars($ahref, ENT_QUOTES, 'UTF-8'); ?>"></audio>
                                        <span class="workspace-chat-voice-meta"><?php echo htmlspecialchars($fn, ENT_QUOTES, 'UTF-8'); ?></span>
                                    </div>
                                    <?php else: ?>
                                    <a href="<?php echo htmlspecialchars($ahref, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener noreferrer" class="workspace-chat-file-chip">
                                        <i data-lucide="paperclip" class="workspace-chat-inline-icon" aria-hidden="true"></i>
                                        <span><?php echo htmlspecialchars($fn, ENT_QUOTES, 'UTF-8'); ?></span>
                                    </a>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <form
            method="<?php echo htmlspecialchars($formMethod, ENT_QUOTES, 'UTF-8'); ?>"
            action="<?php echo htmlspecialchars($formAction, ENT_QUOTES, 'UTF-8'); ?>"
            class="workspace-chat-compose"
            <?php echo $multipart ? 'enctype="' . htmlspecialchars('multipart/form-data', ENT_QUOTES, 'UTF-8') . '"' : ''; ?>
            id="<?php echo htmlspecialchars($feedId, ENT_QUOTES, 'UTF-8'); ?>-form"
            novalidate
        >
            <?php foreach ($hiddenFields as $hk => $hv): ?>
                <input type="hidden" name="<?php echo htmlspecialchars((string) $hk, ENT_QUOTES, 'UTF-8'); ?>" value="<?php echo htmlspecialchars((string) $hv, ENT_QUOTES, 'UTF-8'); ?>">
            <?php endforeach; ?>
            <?php if ($multipart): ?>
                <input type="hidden" name="voice_note_sent" value="0" id="<?php echo htmlspecialchars($feedId, ENT_QUOTES, 'UTF-8'); ?>-voice-flag">
            <?php endif; ?>
            <label class="sr-only" for="<?php echo htmlspecialchars($feedId, ENT_QUOTES, 'UTF-8'); ?>-input">Message</label>
            <textarea
                id="<?php echo htmlspecialchars($feedId, ENT_QUOTES, 'UTF-8'); ?>-input"
                name="comment"
                rows="2"
                class="workspace-chat-input"
                placeholder="<?php echo htmlspecialchars($placeholder, ENT_QUOTES, 'UTF-8'); ?>"
            ></textarea>
            <?php if ($multipart): ?>
                <p class="workspace-chat-voice-hint text-xs text-slate-500 mt-1 min-h-[1rem]" id="<?php echo htmlspecialchars($feedId, ENT_QUOTES, 'UTF-8'); ?>-voice-hint" aria-live="polite"></p>
            <?php endif; ?>
            <?php if ($multipart): ?>
                <div class="workspace-chat-compose-actions">
                    <label class="workspace-chat-file-btn">
                        <i data-lucide="paperclip" class="workspace-chat-inline-icon workspace-chat-inline-icon--lg" aria-hidden="true"></i>
                        <input type="file" id="<?php echo htmlspecialchars($feedId, ENT_QUOTES, 'UTF-8'); ?>-files" name="comment_files[]" multiple accept=".jpg,.jpeg,.png,.gif,.webp,.pdf,.doc,.docx,.webm,.ogg,.opus,.mp3,.wav,.m4a,.aac,audio/*" class="hidden">
                    </label>
                    <button type="button" class="workspace-chat-file-btn press-voice-btn" title="Voice note" aria-label="Record voice note" id="<?php echo htmlspecialchars($feedId, ENT_QUOTES, 'UTF-8'); ?>-voice-btn">
                        <i data-lucide="mic" class="workspace-chat-inline-icon workspace-chat-inline-icon--lg" aria-hidden="true"></i>
                    </button>
                    <button type="submit" class="workspace-chat-send">
                        <i data-lucide="send" class="workspace-chat-inline-icon workspace-chat-inline-icon--send" aria-hidden="true"></i>
                        <span>Send</span>
                    </button>
                </div>
            <?php else: ?>
                <div class="workspace-chat-compose-actions workspace-chat-compose-actions--end">
                    <button type="submit" class="workspace-chat-send workspace-chat-send--full">
                        <i data-lucide="send" class="workspace-chat-inline-icon workspace-chat-inline-icon--send" aria-hidden="true"></i>
                        <span>Send</span>
                    </button>
                </div>
            <?php endif; ?>
        </form>
    </section>
</aside>

<script>
(function(){
    document.addEventListener('DOMContentLoaded', function(){
    var id = <?php echo json_encode($feedId); ?>;
    var multipart = <?php echo $multipart ? 'true' : 'false'; ?>;
    var el = document.getElementById(id);
    if (el) { el.scrollTop = el.scrollHeight; }
    if (typeof window.refreshAppShellIcons === 'function') {
        window.refreshAppShellIcons();
    }
    if (multipart && window.PressVoiceNote) {
        var form = document.getElementById(id + '-form');
        var ta = document.getElementById(id + '-input');
        var fileInp = document.getElementById(id + '-files');
        var hint = document.getElementById(id + '-voice-hint');
        var flag = document.getElementById(id + '-voice-flag');
        if (form && ta && fileInp) {
            fileInp.addEventListener('change', function () {
                if (flag) { flag.value = '0'; }
            });
            form.addEventListener('submit', function (e) {
                var msg = String(ta.value || '').trim();
                var files = fileInp.files && fileInp.files.length;
                if (!msg && !files) {
                    e.preventDefault();
                    if (typeof window.showToast === 'function') {
                        window.showToast('Add a message, attach a file, or record a voice note.', 'error');
                    } else {
                        alert('Add a message, attach a file, or record a voice note.');
                    }
                    return false;
                }
                return true;
            });
            window.PressVoiceNote.bindToggle({
                button: document.getElementById(id + '-voice-btn'),
                fileInput: fileInp,
                hiddenVoiceInput: flag,
                statusEl: hint,
                maxSeconds: 180,
            });
        }
    }
    });
})();
</script>
