<?php

require_once __DIR__ . '/../../config/app.php';
checkAuth();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/permissions_helper.php';
permissions_require_one_of(['view_projects', 'view_tasks']);
require_once __DIR__ . '/../../includes/team_invitation_helper.php';

$userId = (int) ($_SESSION['user_id'] ?? 0);
$pending = team_invitation_fetch_pending_for_user($pdo, $userId);
$pendingCount = count($pending);

include __DIR__ . '/../../includes/header.php';

$invListError = (string) ($_GET['error'] ?? '');
$invListSuccess = (string) ($_GET['success'] ?? '');
?>

<link href="<?php echo asset('css/premium-modules.css'); ?>" rel="stylesheet">

<div class="workspace-stack collab-hub">
    <div class="collab-hub-inner collab-hub-inner--wide">
        <?php if ($invListError !== ''): ?>
            <div class="collab-alert collab-alert--error mb-6" role="alert">
                <i data-lucide="alert-triangle" class="collab-alert__icon" aria-hidden="true"></i>
                <p class="collab-alert__text"><?php echo htmlspecialchars($invListError); ?></p>
            </div>
        <?php endif; ?>
        <?php if ($invListSuccess === 'invitation_declined'): ?>
            <div class="collab-alert collab-alert--success mb-6" role="status">
                <i data-lucide="check" class="collab-alert__icon" aria-hidden="true"></i>
                <p class="collab-alert__text">You declined the invitation. The sender has not been notified beyond your inbox history.</p>
            </div>
        <?php endif; ?>

        <header class="collab-list-header premium-card">
            <div class="collab-list-header__main">
                <div class="collab-list-header__icon" aria-hidden="true">
                    <i data-lucide="mail-plus"></i>
                </div>
                <div>
                    <h1 class="collab-list-header__title">Team invitations</h1>
                    <p class="collab-list-header__sub">Accept to join a project or task team. Invitations expire after 14 days.</p>
                </div>
            </div>
            <?php if ($pendingCount > 0): ?>
                <span class="collab-pending-badge"><?php echo (int) $pendingCount; ?> pending</span>
            <?php endif; ?>
        </header>

        <?php if (empty($pending)): ?>
            <div class="collab-empty premium-card">
                <div class="collab-empty__icon" aria-hidden="true">
                    <i data-lucide="inbox"></i>
                </div>
                <h2 class="collab-empty__title">You&rsquo;re all caught up</h2>
                <p class="collab-empty__text">No pending invitations. When someone invites you to a project or task, it will appear here and in your notifications.</p>
            </div>
        <?php else: ?>
            <ul class="collab-invite-list">
                <?php foreach ($pending as $inv): ?>
                    <?php
                    $expTs = strtotime((string) ($inv['expires_at'] ?? ''));
                    $expLabel = $expTs ? date('M j, Y', $expTs) : '';
                    $daysLeft = ($expTs && $expTs > time()) ? (int) ceil(($expTs - time()) / 86400) : 0;
                    $isTask = $inv['invitation_type'] === 'task';
                    ?>
                    <li class="collab-invite-row premium-card">
                        <div class="collab-invite-row__accent collab-invite-row__accent--<?php echo $isTask ? 'task' : 'project'; ?>" aria-hidden="true"></div>
                        <div class="collab-invite-row__body">
                            <div class="collab-invite-row__top">
                                <span class="collab-type-pill collab-type-pill--<?php echo $isTask ? 'task' : 'project'; ?>"><?php echo $isTask ? 'Task' : 'Project'; ?></span>
                                <?php if ($daysLeft > 0): ?>
                                    <span class="collab-expiry-pill" title="Expires <?php echo htmlspecialchars($expLabel); ?>">
                                        <i data-lucide="hourglass" class="w-3.5 h-3.5" aria-hidden="true"></i>
                                        <?php echo $daysLeft === 1 ? '1 day left' : $daysLeft . ' days left'; ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                            <h2 class="collab-invite-row__title"><?php echo htmlspecialchars((string) $inv['context_label']); ?></h2>
                            <?php if (!empty($inv['context_name_extra'])): ?>
                                <p class="collab-invite-row__project">
                                    <i data-lucide="folder" class="w-4 h-4 opacity-60" aria-hidden="true"></i>
                                    <?php echo htmlspecialchars((string) $inv['context_name_extra']); ?>
                                </p>
                            <?php endif; ?>
                            <div class="collab-invite-row__meta">
                                <span class="collab-invite-row__from">
                                    <i data-lucide="user" class="w-4 h-4 opacity-60" aria-hidden="true"></i>
                                    <?php echo htmlspecialchars((string) $inv['invited_by_name']); ?>
                                </span>
                                <span class="collab-invite-row__dot" aria-hidden="true">·</span>
                                <span><?php echo htmlspecialchars(date('M j, Y', strtotime((string) $inv['created_at']))); ?></span>
                            </div>
                            <?php if (!empty($inv['note'])): ?>
                                <p class="collab-invite-row__note"><?php echo htmlspecialchars((string) $inv['note']); ?></p>
                            <?php endif; ?>
                        </div>
                        <div class="collab-invite-row__cta">
                            <a class="premium-btn premium-btn-primary collab-respond-btn" href="<?php echo htmlspecialchars(BASE_URL); ?>modules/collaboration/invitation_respond?token=<?php echo rawurlencode((string) $inv['token']); ?>">
                                <span>Review &amp; respond</span>
                                <i data-lucide="arrow-right" class="w-4 h-4" aria-hidden="true"></i>
                            </a>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
</div>

<script>
if (typeof window.refreshAppShellIcons === 'function') {
    window.refreshAppShellIcons();
}
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
