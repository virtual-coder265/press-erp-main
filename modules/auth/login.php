<?php
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/login_slides_helper.php';
require_once __DIR__ . '/../../includes/branding_helper.php';
require_once __DIR__ . '/../../libs/AuditLogger.php';

$error = '';
$auditLogger = new AuditLogger($pdo);

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $email    = trim((string) ($_POST['email'] ?? ''));
    $password = $_POST['password'] ?? '';

    if (!empty($email) && !empty($password)) {
        $stmt = $pdo->prepare("SELECT u.*, r.name as role_name, d.name as department_name
                               FROM users u
                               LEFT JOIN roles r ON u.role_id = r.id
                               LEFT JOIN departments d ON u.department_id = d.id
                               WHERE u.email = :email");
        $stmt->execute(['email' => $email]);
        $user = $stmt->fetch();

        $activeBlock = $auditLogger->getActiveIpBlock();

        if ($activeBlock) {
            $auditLogger->registerBlockedLogin($email, $activeBlock, isset($user['id']) ? (int) $user['id'] : null, [
                'source' => 'modules/auth/login',
            ]);
            $blockedUntil = !empty($activeBlock['blocked_until']) ? ' until ' . $activeBlock['blocked_until'] : ' until an administrator clears it';
            $error = 'Access from this IP address is temporarily blocked' . $blockedUntil . '.';
        } elseif ($user && password_verify($password, $user['password'])) {
            session_regenerate_id(true);
            $_SESSION['user_id']    = $user['id'];
            $_SESSION['user_name']  = $user['name'];
            $_SESSION['role']       = $user['role_name'];
            $_SESSION['department'] = $user['department_name'];
            $_SESSION['department_id'] = isset($user['department_id']) ? (int) $user['department_id'] : null;
            if (isset($_SESSION['department_id']) && $_SESSION['department_id'] < 1) {
                $_SESSION['department_id'] = null;
            }
            $_SESSION['is_section_head'] = !empty($user['is_section_head'] ?? false) ? 1 : 0;
            $_SESSION['user_photo'] = $user['photo'] ?? null;

            // Load role permissions into session
            try {
                $permStmt = $pdo->prepare(
                    "SELECT p.slug FROM permissions p
                     INNER JOIN role_permissions rp ON rp.permission_id = p.id
                     WHERE rp.role_id = :role_id"
                );
                $permStmt->execute(['role_id' => $user['role_id']]);
                $_SESSION['permissions'] = $permStmt->fetchAll(PDO::FETCH_COLUMN);
            } catch (Exception $exception) {
                $_SESSION['permissions'] = [];
            }

            $isRecognizedDevice = $auditLogger->isRecognizedDeviceForUser((int) $user['id']);
            if (!$isRecognizedDevice) {
                require_once __DIR__ . '/../../libs/NotificationManager.php';
                $notifManager = new NotificationManager($pdo);
                $notifManager->notify(
                    $user['id'],
                    'security',
                    'New Login Detected',
                    'A new login was detected for your account at ' . date('Y-m-d H:i:s') . '.',
                    'modules/user-account/security',
                    null,
                    false,
                    false,
                    ['source' => 'modules/auth/login'],
                    [
                        'channels' => [
                            'push' => false,
                            'email' => false,
                            'sms' => false,
                            'whatsapp' => false,
                        ],
                    ]
                );
            }

            $auditLogger->registerSuccessfulLogin($user, $email, [
                'source' => 'modules/auth/login',
            ]);

            require_once __DIR__ . '/../../includes/dashboard_landing_helper.php';
            redirect(dashboard_default_landing_path());
        } else {
            $failure = $auditLogger->registerFailedLogin(
                $email,
                isset($user['id']) ? (int) $user['id'] : null,
                $user ? 'invalid_password' : 'unknown_email',
                ['source' => 'modules/auth/login']
            );

            if (!empty($failure['blocked'])) {
                $block = $failure['block'] ?? null;
                $blockedUntil = !empty($block['blocked_until']) ? ' until ' . $block['blocked_until'] : ' until an administrator clears it';
                $error = 'Too many failed login attempts. This IP address has been blocked' . $blockedUntil . '.';
            } else {
                $error = 'Invalid email or password.';
            }
        }
    } else {
        $error = 'Please fill in all fields.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign In — Gov Press ERP</title>
    <link rel="icon" type="image/png" href="<?php echo htmlspecialchars(system_branding_resolved_url('favicon')); ?>">
    <?php include __DIR__ . '/../../includes/head_assets.php'; ?>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: var(--app-font-sans);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1.25rem;
            background: #e8edf2;
        }

        .login-card {
            width: min(1140px, 100%);
            display: grid;
            grid-template-columns: minmax(380px, 46%) 1fr;
            border-radius: 1.25rem;
            overflow: hidden;
            box-shadow:
                0 24px 64px rgba(15, 23, 42, .14),
                0 4px 16px rgba(15, 23, 42, .06);
            background: #fff;
        }

        /* ── Form panel (left) ── */
        .lf-panel {
            position: relative;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2.5rem 2.75rem;
            background: #fff;
            overflow: hidden;
        }

        .lf-panel::before {
            content: '';
            position: absolute;
            left: -1.5rem;
            bottom: -1.5rem;
            width: 9rem;
            height: 9rem;
            background-image: radial-gradient(circle, #d8e0e8 1.5px, transparent 1.5px);
            background-size: 14px 14px;
            opacity: .55;
            pointer-events: none;
        }

        .lf-inner {
            position: relative;
            z-index: 1;
            width: 100%;
            max-width: 380px;
        }

        .lf-logo {
            display: flex;
            align-items: center;
            gap: .75rem;
            margin-bottom: 2rem;
        }

        .lf-logo-ring {
            width: 2.75rem;
            height: 2.75rem;
            border-radius: .75rem;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: #f1f5f9;
            border: 1px solid #e2e8f0;
            flex-shrink: 0;
        }

        .lf-logo-name {
            font-size: .95rem;
            font-weight: 800;
            color: #0f172a;
            line-height: 1.15;
        }

        .lf-logo-sub {
            font-size: .62rem;
            font-weight: 700;
            color: #94a3b8;
            text-transform: uppercase;
            letter-spacing: .12em;
            margin-top: .2rem;
        }

        .lf-eyebrow {
            display: inline-block;
            font-size: .72rem;
            font-weight: 700;
            color: #16a34a;
            margin-bottom: .5rem;
        }

        .lf-heading {
            font-size: 1.85rem;
            font-weight: 800;
            color: #0f172a;
            letter-spacing: -.03em;
            line-height: 1.15;
            margin-bottom: .55rem;
        }

        .lf-sub {
            font-size: .875rem;
            color: #64748b;
            line-height: 1.55;
            margin-bottom: 1.75rem;
        }

        .lf-alert {
            display: flex;
            align-items: center;
            gap: .55rem;
            padding: .82rem 1rem;
            border-radius: .75rem;
            font-size: .85rem;
            font-weight: 600;
            margin-bottom: 1.25rem;
        }

        .lf-alert--error {
            background: rgba(220, 38, 38, .06);
            border: 1px solid rgba(220, 38, 38, .18);
            color: #b91c1c;
        }

        .lf-alert--success {
            background: rgba(21, 128, 61, .07);
            border: 1px solid rgba(21, 128, 61, .2);
            color: #166534;
        }

        .lf-form { display: flex; flex-direction: column; gap: 1rem; }

        .lf-field { display: flex; flex-direction: column; gap: .4rem; }

        .lf-label {
            font-size: .8rem;
            font-weight: 600;
            color: #334155;
        }

        .lf-label-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .lf-forgot {
            font-size: .78rem;
            font-weight: 600;
            color: #0f766e;
            text-decoration: none;
            transition: color .15s;
        }

        .lf-forgot:hover { color: #115e59; }

        .lf-input-wrap { position: relative; display: flex; align-items: center; }

        .lf-icon {
            position: absolute;
            left: .9rem;
            font-size: 1.15rem !important;
            color: #94a3b8;
            pointer-events: none;
            z-index: 1;
        }

        .lf-input {
            width: 100%;
            padding: .82rem 2.5rem .82rem 2.65rem;
            border-radius: .75rem;
            border: 1px solid #cbd5e1;
            background: #fff;
            font-size: .875rem;
            font-family: var(--app-font-sans);
            color: #0f172a;
            transition: border-color .2s, box-shadow .2s;
        }

        .lf-input::placeholder { color: #94a3b8; }

        .lf-input:focus {
            outline: none;
            border-color: #0f766e;
            box-shadow: 0 0 0 3px rgba(15, 118, 110, .12);
        }

        .lf-pw-toggle {
            position: absolute;
            right: .65rem;
            display: flex;
            align-items: center;
            background: none;
            border: none;
            cursor: pointer;
            padding: .25rem;
            color: #94a3b8;
            border-radius: .35rem;
            transition: color .15s;
        }

        .lf-pw-toggle:hover { color: #0f766e; }
        .lf-pw-toggle .material-icons { font-size: 1.15rem; }

        .lf-btn {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: .45rem;
            width: 100%;
            margin-top: .25rem;
            padding: .88rem 1.25rem;
            border-radius: .75rem;
            border: none;
            background: linear-gradient(90deg, #0d9488 0%, #1e4a8a 100%);
            color: #fff;
            font-family: var(--app-font-sans);
            font-size: .9rem;
            font-weight: 700;
            cursor: pointer;
            box-shadow: 0 6px 20px rgba(13, 148, 136, .25);
            transition: filter .2s, transform .15s, box-shadow .2s;
        }

        .lf-btn:hover {
            filter: brightness(1.05);
            transform: translateY(-1px);
            box-shadow: 0 10px 28px rgba(13, 148, 136, .3);
        }

        .lf-btn:active { transform: translateY(0); }

        .lf-divider {
            display: flex;
            align-items: center;
            gap: .75rem;
            margin: .35rem 0;
            color: #94a3b8;
            font-size: .78rem;
            font-weight: 500;
        }

        .lf-divider::before,
        .lf-divider::after {
            content: '';
            flex: 1;
            height: 1px;
            background: #e2e8f0;
        }

        .lf-btn-secondary {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: .45rem;
            width: 100%;
            padding: .82rem 1.25rem;
            border-radius: .75rem;
            border: 1px solid #cbd5e1;
            background: #fff;
            color: #334155;
            font-family: var(--app-font-sans);
            font-size: .875rem;
            font-weight: 600;
            cursor: pointer;
            transition: border-color .2s, background .2s;
        }

        .lf-btn-secondary:hover:not(:disabled) {
            border-color: #94a3b8;
            background: #f8fafc;
        }

        .lf-btn-secondary:disabled {
            opacity: .55;
            cursor: not-allowed;
            color: #94a3b8;
            background: #f8fafc;
        }

        .lf-footer {
            display: flex;
            align-items: flex-start;
            gap: .4rem;
            margin-top: 1.75rem;
            font-size: .75rem;
            color: #94a3b8;
            font-weight: 500;
            line-height: 1.65;
        }

        .lf-footer .material-icons {
            font-size: .95rem;
            margin-top: .1rem;
            flex-shrink: 0;
            color: #94a3b8;
        }

        .lf-footer a {
            color: #0f766e;
            font-weight: 600;
            text-decoration: none;
        }

        .lf-footer a:hover { color: #115e59; text-decoration: underline; }

        /* ── Slider panel (right) ── */
        .ls-panel {
            position: relative;
            min-height: 580px;
            overflow: hidden;
            background: #0c1826;
        }

        .ls-images { position: absolute; inset: 0; }

        .ls-img {
            position: absolute;
            inset: 0;
            background-size: cover;
            background-position: center;
            opacity: 0;
            transition: opacity 1s ease;
        }

        .ls-img.active {
            opacity: 1;
            animation: kbZoom 8s ease forwards;
        }

        @keyframes kbZoom {
            from { transform: scale(1.06); }
            to   { transform: scale(1); }
        }

        .ls-overlay {
            position: absolute;
            inset: 0;
            z-index: 1;
            background: linear-gradient(
                180deg,
                rgba(8, 18, 36, .35) 0%,
                rgba(8, 18, 36, .08) 35%,
                rgba(8, 18, 36, .82) 100%
            );
        }

        .ls-badge {
            position: absolute;
            top: 1.5rem;
            right: 1.5rem;
            z-index: 4;
            display: flex;
            align-items: center;
            gap: .45rem;
            background: rgba(15, 118, 110, .88);
            border: 1px solid rgba(255, 255, 255, .15);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            padding: .45rem .85rem;
            border-radius: 999px;
            font-size: .72rem;
            font-weight: 700;
            color: #fff;
            letter-spacing: .02em;
        }

        .ls-badge .material-icons {
            font-size: .95rem;
            opacity: .95;
        }

        .ls-bottom {
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            z-index: 4;
            padding: 0 2rem 1.75rem;
        }

        .ls-text-wrap {
            position: relative;
            min-height: 5.5rem;
            margin-bottom: 1.5rem;
        }

        .ls-text {
            position: absolute;
            inset: 0;
            opacity: 0;
            transform: translateY(12px);
            transition: opacity .6s ease, transform .6s ease;
            pointer-events: none;
        }

        .ls-text.active {
            opacity: 1;
            transform: translateY(0);
            pointer-events: auto;
        }

        .ls-title {
            font-size: 1.75rem;
            font-weight: 800;
            color: #fff;
            line-height: 1.2;
            letter-spacing: -.02em;
            margin-bottom: .5rem;
            text-shadow: 0 2px 12px rgba(0, 0, 0, .35);
        }

        .ls-caption {
            font-size: .875rem;
            color: rgba(255, 255, 255, .78);
            line-height: 1.55;
            max-width: 32rem;
        }

        .ls-features {
            display: flex;
            align-items: stretch;
            gap: 0;
            padding: 1rem 0 1.25rem;
            margin-bottom: .5rem;
            border-top: 1px solid rgba(255, 255, 255, .12);
        }

        .ls-feature {
            flex: 1;
            display: flex;
            align-items: flex-start;
            gap: .55rem;
            padding: 0 1rem;
        }

        .ls-feature:first-child { padding-left: 0; }
        .ls-feature:last-child { padding-right: 0; }

        .ls-feature + .ls-feature {
            border-left: 1px solid rgba(255, 255, 255, .18);
        }

        .ls-feature-icon {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 2rem;
            height: 2rem;
            border-radius: .5rem;
            background: rgba(255, 255, 255, .12);
            flex-shrink: 0;
        }

        .ls-feature-icon .material-icons {
            font-size: 1.05rem;
            color: #fff;
        }

        .ls-feature-title {
            font-size: .72rem;
            font-weight: 700;
            color: #fff;
            line-height: 1.25;
            margin-bottom: .15rem;
        }

        .ls-feature-desc {
            font-size: .65rem;
            color: rgba(255, 255, 255, .62);
            line-height: 1.35;
        }

        .ls-dots {
            display: flex;
            gap: .45rem;
            align-items: center;
        }

        .ls-dot {
            width: 8px;
            height: 8px;
            border-radius: 999px;
            background: rgba(255, 255, 255, .35);
            border: none;
            cursor: pointer;
            padding: 0;
            transition: background .3s, width .35s, opacity .3s;
        }

        .ls-dot.active {
            background: #fff;
            width: 26px;
        }

        @media (max-width: 960px) {
            .login-card { grid-template-columns: minmax(320px, 42%) 1fr; }
            .ls-title { font-size: 1.45rem; }
        }

        @media (max-width: 767px) {
            body { padding: 0; align-items: stretch; }

            .login-card {
                grid-template-columns: 1fr;
                border-radius: 0;
                box-shadow: none;
                min-height: 100vh;
            }

            .ls-panel { min-height: 260px; order: 1; }
            .lf-panel { order: 2; padding: 2rem 1.5rem 2.5rem; align-items: flex-start; }

            .ls-bottom { padding: 0 1.25rem 1.25rem; }
            .ls-text-wrap { min-height: 4.25rem; margin-bottom: 1rem; }
            .ls-title { font-size: 1.15rem; }
            .ls-features { display: none; }
            .ls-badge { top: 1rem; right: 1rem; }
        }

        @media (max-width: 479px) {
            .lf-heading { font-size: 1.5rem; }
        }
    </style>
</head>
<body>

<div class="login-card">

    <!-- Login form (left) -->
    <section class="lf-panel">
        <div class="lf-inner">

            <!-- Logo -->
            <div class="lf-logo">
                <span class="lf-logo-ring">
                    <img src="<?php echo htmlspecialchars(system_branding_resolved_url('favicon')); ?>" alt="Logo" style="width:1.65rem;height:1.65rem;object-fit:contain;">
                </span>
                <div>
                    <div class="lf-logo-name">Gov Press ERP</div>
                    <div class="lf-logo-sub">Printing Services Portal</div>
                </div>
            </div>

            <!-- Heading -->
            <p class="lf-eyebrow">Welcome back</p>
            <h1 class="lf-heading">Sign in to your account</h1>
            <p class="lf-sub">Use your staff credentials to access your workspace.</p>

            <?php if ($error): ?>
                <div class="lf-alert lf-alert--error" role="alert">
                    <i class="material-icons" style="font-size:1rem;flex-shrink:0">error_outline</i>
                    <span><?php echo htmlspecialchars($error); ?></span>
                </div>
            <?php endif; ?>

            <?php if (isset($_GET['message']) && $_GET['message'] === 'installation_complete'): ?>
                <div class="lf-alert lf-alert--success" role="status">
                    <i class="material-icons" style="font-size:1rem;flex-shrink:0">check_circle_outline</i>
                    Installation completed successfully. Sign in with your administrator account to continue.
                </div>
            <?php endif; ?>

            <?php if (isset($_GET['message']) && $_GET['message'] === 'password_reset_success'): ?>
                <div class="lf-alert lf-alert--success" role="status">
                    <i class="material-icons" style="font-size:1rem;flex-shrink:0">check_circle_outline</i>
                    Password reset successful. Please sign in.
                </div>
            <?php endif; ?>

            <!-- Form -->
            <form method="POST" action="<?php echo htmlspecialchars(BASE_URL . 'modules/auth/login', ENT_QUOTES, 'UTF-8'); ?>" class="lf-form" novalidate>

                <div class="lf-field">
                    <label class="lf-label" for="email">Email address</label>
                    <div class="lf-input-wrap">
                        <i class="material-icons lf-icon">email</i>
                        <input
                            class="lf-input"
                            id="email"
                            name="email"
                            type="email"
                            placeholder="john@printingservices.opc.gov.mw"
                            required
                            autocomplete="email"
                        >
                    </div>
                </div>

                <div class="lf-field">
                    <div class="lf-label-row">
                        <label class="lf-label" for="password">Password</label>
                        <a class="lf-forgot" href="forgot_password">Forgot password?</a>
                    </div>
                    <div class="lf-input-wrap">
                        <i class="material-icons lf-icon">lock_outline</i>
                        <input
                            class="lf-input"
                            id="password"
                            name="password"
                            type="password"
                            placeholder="Enter your password"
                            required
                            autocomplete="current-password"
                        >
                        <button type="button" class="lf-pw-toggle" id="pwToggle" aria-label="Toggle password visibility">
                            <i class="material-icons" id="pwIcon">visibility_off</i>
                        </button>
                    </div>
                </div>

                <button class="lf-btn" type="submit">
                    <i class="material-icons" style="font-size:1.05rem">lock</i>
                    <span>Sign in</span>
                </button>

                <div class="lf-divider" aria-hidden="true">or</div>

                <button class="lf-btn-secondary" type="button" disabled aria-disabled="true">
                    <i class="material-icons" style="font-size:1.05rem">mail_outline</i>
                    <span>Sign in with email</span>
                </button>

            </form>

            <p class="lf-footer">
                <i class="material-icons">shield</i>
                <span>Need access? <a href="mailto:ict@printingservices.opc.gov.mw">Contact the system administrator</a> if you cannot log in to your workspace.</span>
            </p>

        </div>
    </section>

    <!-- Image slider (right) -->
    <section class="ls-panel">
        <div class="ls-images" id="lsImages"></div>
        <div class="ls-overlay"></div>

        <div class="ls-badge">
            <i class="material-icons">business</i>
            Department of Printing Services
        </div>

        <div class="ls-bottom">
            <div class="ls-text-wrap" id="lsTexts"></div>

            <div class="ls-features" aria-hidden="true">
                <div class="ls-feature">
                    <span class="ls-feature-icon"><i class="material-icons">verified_user</i></span>
                    <div>
                        <div class="ls-feature-title">Secure &amp; Reliable</div>
                        <div class="ls-feature-desc">Enterprise-grade security</div>
                    </div>
                </div>
                <div class="ls-feature">
                    <span class="ls-feature-icon"><i class="material-icons">account_tree</i></span>
                    <div>
                        <div class="ls-feature-title">Smart Workflows</div>
                        <div class="ls-feature-desc">Streamlined end-to-end</div>
                    </div>
                </div>
                <div class="ls-feature">
                    <span class="ls-feature-icon"><i class="material-icons">account_balance</i></span>
                    <div>
                        <div class="ls-feature-title">Trusted Service</div>
                        <div class="ls-feature-desc">Built for government</div>
                    </div>
                </div>
            </div>

            <div class="ls-dots" id="lsDots" role="tablist" aria-label="Login slides"></div>
        </div>
    </section>

</div>

<script>
(function () {
    var DURATION = 8000;
    var slides = <?php echo json_encode(login_slides_for_frontend(), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;

    var current = 0;
    var imgWrap  = document.getElementById('lsImages');
    var txtWrap  = document.getElementById('lsTexts');
    var dotWrap  = document.getElementById('lsDots');

    // Build image layers
    slides.forEach(function (s, i) {
        var d = document.createElement('div');
        d.className = 'ls-img' + (i === 0 ? ' active' : '');
        d.style.backgroundImage = "url('" + s.img + "')";
        imgWrap.appendChild(d);
    });

    // Build text blocks
    slides.forEach(function (s, i) {
        var d = document.createElement('div');
        d.className = 'ls-text' + (i === 0 ? ' active' : '');
        d.innerHTML = '<p class="ls-title">' + s.title + '</p><p class="ls-caption">' + s.caption + '</p>';
        txtWrap.appendChild(d);
    });

    // Build dots
    slides.forEach(function (_, i) {
        var b = document.createElement('button');
        b.className = 'ls-dot' + (i === 0 ? ' active' : '');
        b.setAttribute('aria-label', 'Slide ' + (i + 1));
        b.addEventListener('click', function () { goTo(i); });
        dotWrap.appendChild(b);
    });

    function goTo(idx) {
        var imgs  = imgWrap.querySelectorAll('.ls-img');
        var texts = txtWrap.querySelectorAll('.ls-text');
        var dots  = dotWrap.querySelectorAll('.ls-dot');

        imgs[current].classList.remove('active');
        texts[current].classList.remove('active');
        dots[current].classList.remove('active');

        current = idx;

        imgs[current].classList.add('active');
        texts[current].classList.add('active');
        dots[current].classList.add('active');
    }

    function next() { goTo((current + 1) % slides.length); }

    setInterval(next, DURATION);

    // Password toggle
    var pwInput = document.getElementById('password');
    var pwIcon  = document.getElementById('pwIcon');

    document.getElementById('pwToggle').addEventListener('click', function () {
        var isPass = pwInput.type === 'password';
        pwInput.type = isPass ? 'text' : 'password';
        pwIcon.textContent = isPass ? 'visibility' : 'visibility_off';
    });

})();
</script>
</body>
</html>
