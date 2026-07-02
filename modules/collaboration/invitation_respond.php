<?php

require_once __DIR__ . '/../../config/app.php';
checkAuth();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/team_invitation_helper.php';

$collabDisplayInitials = static function (string $name): string {
    $name = trim($name);
    if ($name === '') {
        return '?';
    }
    $parts = preg_split('/\s+/u', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $buf = '';
    foreach (array_slice($parts, 0, 2) as $p) {
        $buf .= strtoupper(substr($p, 0, 1));
    }

    return $buf !== '' ? $buf : '?';
};

$token = isset($_GET['token']) ? trim((string) $_GET['token']) : trim((string) ($_POST['token'] ?? ''));
$userId = (int) ($_SESSION['user_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['invitation_action'] ?? '');
    $foundPost = $token !== '' ? team_invitation_find_by_token($pdo, $token) : null;

    if ($token === '') {
        redirect('modules/collaboration/invitations?error=' . urlencode('Missing invitation.'));
    }

    if (!$foundPost) {
        redirect('modules/collaboration/invitations?error=' . urlencode('Invitation not found or expired.'));
    }

    $rowPost = $foundPost['row'];
    if ((int) ($rowPost['invitee_user_id'] ?? 0) !== $userId) {
        redirect('modules/collaboration/invitations?error=' . urlencode('This invitation was sent to another account.'));
    }

    if ($action === 'accept') {
        $r = team_invitation_accept($pdo, $token, $userId);
        if (!$r['ok']) {
            redirect('modules/collaboration/invitation_respond?token=' . rawurlencode($token) . '&error=' . urlencode((string) ($r['error'] ?? 'Accept failed')));
        }
        if ($foundPost['type'] === 'project') {
            redirect('modules/projects/view?id=' . (int) $rowPost['project_id'] . '&success=invitation_accepted');
        }
        redirect('modules/tasks/view?id=' . (int) $rowPost['task_id'] . '&success=invitation_accepted');
    }

    if ($action === 'decline') {
        $r = team_invitation_decline($pdo, $token, $userId);
        if (!$r['ok']) {
            redirect('modules/collaboration/invitation_respond?token=' . rawurlencode($token) . '&error=' . urlencode((string) ($r['error'] ?? 'Decline failed')));
        }
        redirect('modules/collaboration/invitations?success=invitation_declined');
    }

    redirect('modules/collaboration/invitations?error=' . urlencode('Invalid action.'));
}

$found = $token !== '' ? team_invitation_find_by_token($pdo, $token) : null;
$error = (string) ($_GET['error'] ?? '');
$row = $found['row'] ?? null;
$type = $found['type'] ?? null;
$wrongUser = false;

if ($found && $row && (int) ($row['invitee_user_id'] ?? 0) !== $userId) {
    $wrongUser = true;
    $error = $error ?: 'This invitation was sent to another account. Sign in as the invited user.';
}

if (!$found && $error === '') {
    $error = 'Invitation not found or it has expired.';
}

include __DIR__ . '/../../includes/header.php';
?>

<link href="<?php echo asset('css/premium-modules.css'); ?>" rel="stylesheet">

<div class="workspace-stack collab-hub">
    <div class="collab-hub-inner">
        <a href="<?php echo BASE_URL; ?>modules/collaboration/invitations" class="collab-hub-back">
            <i data-lucide="arrow-left" class="w-4 h-4" aria-hidden="true"></i>
            <span>All invitations</span>
        </a>

        <?php
        $collabCardKind = $type === 'task' ? 'task' : ($type === 'project' ? 'project' : 'neutral');
        $collabSpotlightKind = $type === 'task' ? 'task' : 'project';
        ?>
        <article class="collab-invite-card premium-card collab-invite-card--<?php echo htmlspecialchars($collabCardKind, ENT_QUOTES, 'UTF-8'); ?>">
            <header class="collab-invite-card__hero">
                <div class="collab-invite-card__hero-icon" aria-hidden="true">
                    <i data-lucide="user-plus"></i>
                </div>
                <div class="collab-invite-card__hero-copy">
                    <p class="collab-invite-card__eyebrow">Collaboration</p>
                    <h1 class="collab-invite-card__title">Team invitation</h1>
                    <p class="collab-invite-card__lede">
                        <?php if ($type === 'task'): ?>
                            Join this task as an assignee and work with the project team on delivery.
                        <?php elseif ($type === 'project'): ?>
                            Join this project&rsquo;s delivery team alongside assignees and the project manager.
                        <?php else: ?>
                            Review the details below, then accept or decline.
                        <?php endif; ?>
                    </p>
                </div>
            </header>

            <div class="collab-invite-card__body">
                <?php if ($error !== ''): ?>
                    <div class="collab-alert collab-alert--error" role="alert">
                        <i data-lucide="alert-triangle" class="collab-alert__icon" aria-hidden="true"></i>
                        <p class="collab-alert__text"><?php echo htmlspecialchars($error); ?></p>
                    </div>
                <?php elseif (!$wrongUser && $row): ?>
                    <?php
                    $inviterName = (string) ($row['invited_by_name'] ?? 'Team member');
                    $createdTs = strtotime((string) ($row['created_at'] ?? ''));
                    $expiresTs = strtotime((string) ($row['expires_at'] ?? ''));
                    $createdLabel = $createdTs ? date('M j, Y · g:i A', $createdTs) : '';
                    $expiresLabel = $expiresTs ? date('M j, Y', $expiresTs) : '';
                    $expiresInDays = ($expiresTs && $expiresTs > time()) ? (int) ceil(($expiresTs - time()) / 86400) : 0;
                    $contextTitle = $type === 'task'
                        ? (string) ($row['task_name'] ?? '')
                        : (string) ($row['project_name'] ?? '');
                    ?>

                    <div class="collab-spotlight collab-spotlight--<?php echo htmlspecialchars($collabSpotlightKind, ENT_QUOTES, 'UTF-8'); ?>">
                        <div class="collab-context-block">
                            <span class="collab-type-pill collab-type-pill--<?php echo $type === 'task' ? 'task' : 'project'; ?>"><?php echo $type === 'task' ? 'Task' : 'Project'; ?></span>
                            <h2 class="collab-context-title"><?php echo htmlspecialchars($contextTitle); ?></h2>
                            <?php if ($type === 'task'): ?>
                                <p class="collab-context-meta">
                                    <i data-lucide="folder" class="w-4 h-4 opacity-70" aria-hidden="true"></i>
                                    <span><?php echo htmlspecialchars((string) ($row['project_name'] ?? '')); ?></span>
                                </p>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="collab-meta-grid">
                        <div class="collab-meta-tile">
                            <span class="collab-meta-tile__icon-wrap" aria-hidden="true"><i data-lucide="user"></i></span>
                            <div class="collab-meta-tile__inner">
                                <span class="collab-meta-label">Invited by</span>
                                <div class="collab-inviter">
                                    <span class="collab-inviter__avatar"><?php echo htmlspecialchars($collabDisplayInitials($inviterName)); ?></span>
                                    <span class="collab-inviter__name"><?php echo htmlspecialchars($inviterName); ?></span>
                                </div>
                            </div>
                        </div>
                        <div class="collab-meta-tile">
                            <span class="collab-meta-tile__icon-wrap" aria-hidden="true"><i data-lucide="send"></i></span>
                            <div class="collab-meta-tile__inner">
                                <span class="collab-meta-label">Sent</span>
                                <span class="collab-meta-value"><?php echo htmlspecialchars($createdLabel ?: '—'); ?></span>
                            </div>
                        </div>
                        <div class="collab-meta-tile">
                            <span class="collab-meta-tile__icon-wrap" aria-hidden="true"><i data-lucide="calendar-clock"></i></span>
                            <div class="collab-meta-tile__inner">
                                <span class="collab-meta-label">Expires</span>
                                <span class="collab-meta-value"><?php echo htmlspecialchars($expiresLabel ?: '—'); ?></span>
                                <?php if ($expiresInDays > 0): ?>
                                    <span class="collab-meta-sub"><?php echo $expiresInDays === 1 ? '1 day left' : $expiresInDays . ' days left'; ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <?php if (!empty($row['note'])): ?>
                        <div class="collab-note-callout">
                            <span class="collab-note-callout__label">Message from <?php echo htmlspecialchars($inviterName); ?></span>
                            <div class="collab-note-callout__body"><?php echo nl2br(htmlspecialchars((string) $row['note'])); ?></div>
                        </div>
                    <?php endif; ?>

                    <?php
                    $pending = $row['status'] === 'pending' && strtotime((string) $row['expires_at']) >= time();
                    if (!$pending):
                    ?>
                        <div class="collab-alert collab-alert--warning" role="status">
                            <i data-lucide="clock" class="collab-alert__icon" aria-hidden="true"></i>
                            <p class="collab-alert__text">
                                This invitation is no longer active<?php echo $row['status'] !== 'pending' ? ' (' . htmlspecialchars((string) $row['status']) . ').' : '.'; ?>
                            </p>
                        </div>
                    <?php else: ?>
                        <div class="collab-actions-ribbon">
                            <p class="collab-actions-ribbon__hint">Accept to join the team on this <?php echo $type === 'task' ? 'task' : 'project'; ?>. Declining is fine too—you can always ask for a new invite later.</p>
                            <form method="post" action="invitation_respond" class="collab-invite-actions">
                                <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">
                                <button type="submit" name="invitation_action" value="accept" class="premium-btn premium-btn-primary collab-cta collab-cta--primary">
                                    <i data-lucide="check" class="w-5 h-5" aria-hidden="true"></i>
                                    Accept invitation
                                </button>
                                <button type="submit" name="invitation_action" value="decline" class="premium-btn premium-btn-secondary collab-cta collab-cta--secondary" onclick="return confirm('Decline this invitation?');">
                                    <i data-lucide="x" class="w-5 h-5" aria-hidden="true"></i>
                                    Decline
                                </button>
                            </form>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </article>
    </div>
</div>

<script>
if (typeof window.refreshAppShellIcons === 'function') {
    window.refreshAppShellIcons();
}
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
