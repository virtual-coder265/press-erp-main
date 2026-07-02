<?php
require_once __DIR__ . '/../../config/app.php';

$reinstallMode = installer_is_reinstall_mode();
$installerToken = (string) ($_SESSION['installer_reentry_token'] ?? '');
$scan = installer_run_environment_scan();
$availableSqlFiles = installer_get_available_sql_files();
$metadata = installer_metadata();
$error = '';
$errorReference = '';
$errorLogPath = installer_log_relative_path();

$form = [
    'app_name' => (string) env('APP_NAME', 'Gov Press ERP'),
    'base_url' => installer_detect_base_url(),
    'app_timezone' => (string) env('APP_TIMEZONE', 'Africa/Blantyre'),
    'db_host' => (string) env('DB_HOST', 'localhost'),
    'db_port' => (string) env('DB_PORT', '3306'),
    'db_name' => (string) env('DB_NAME', 'press_erp'),
    'db_user' => (string) env('DB_USER', 'root'),
    'db_pass' => (string) env('DB_PASS', ''),
    'sql_source' => 'filesystem',
    'existing_sql_file' => installer_default_sql_choice($availableSqlFiles),
    'existing_sql_path' => '',
    'drop_existing_tables' => $reinstallMode,
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $attemptReference = installer_generate_reference();
    $form['app_name'] = trim((string) ($_POST['app_name'] ?? $form['app_name']));
    $form['base_url'] = trim((string) ($_POST['base_url'] ?? $form['base_url']));
    $form['app_timezone'] = trim((string) ($_POST['app_timezone'] ?? $form['app_timezone']));
    $form['db_host'] = trim((string) ($_POST['db_host'] ?? $form['db_host']));
    $form['db_port'] = trim((string) ($_POST['db_port'] ?? $form['db_port']));
    $form['db_name'] = trim((string) ($_POST['db_name'] ?? $form['db_name']));
    $form['db_user'] = trim((string) ($_POST['db_user'] ?? $form['db_user']));
    $form['db_pass'] = (string) ($_POST['db_pass'] ?? $form['db_pass']);
    $form['sql_source'] = trim((string) ($_POST['sql_source'] ?? $form['sql_source']));
    $form['existing_sql_file'] = trim((string) ($_POST['existing_sql_file'] ?? $form['existing_sql_file']));
    $form['existing_sql_path'] = trim((string) ($_POST['existing_sql_path'] ?? ''));
    $form['drop_existing_tables'] = isset($_POST['drop_existing_tables']);

    installer_log_event('info', 'Installer request started.', [
        'mode' => $reinstallMode ? 'reinstall' : 'fresh',
        'sql_source' => $form['sql_source'],
        'drop_existing_tables' => $form['drop_existing_tables'],
        'base_url' => $form['base_url'],
        'db' => [
            'host' => $form['db_host'],
            'port' => $form['db_port'],
            'name' => $form['db_name'],
            'user' => $form['db_user'],
        ],
    ], $attemptReference);

    if (!verify_csrf_token($_POST['_csrf'] ?? null, 'installer_action')) {
        $error = 'The installer request could not be verified. Refresh the page and try again.';
    } elseif (($_POST['action'] ?? 'install') === 'cancel_reinstall') {
        try {
            installer_cancel_reinstallation();
            installer_log_event('info', 'Installer reinstall mode cancelled by user.', [
                'mode' => $reinstallMode ? 'reinstall' : 'fresh',
            ], $attemptReference);
            $redirectTarget = isset($_SESSION['user_id'])
                ? 'modules/settings/index?installer_notice=reinstall_cancelled'
                : 'modules/auth/login';
            redirect($redirectTarget);
        } catch (Throwable $exception) {
            installer_log_event('error', 'Installer reinstall cancellation failed.', [
                'exception_class' => get_class($exception),
                'exception_message' => $exception->getMessage(),
                'trace' => $exception->getTraceAsString(),
            ], $attemptReference);
            $errorReference = $attemptReference;
            $error = installer_public_error_message($exception, $attemptReference);
        }
    } elseif (!$scan['ready']) {
        $error = 'Resolve the failing environment checks before continuing with installation.';
    } else {
        $selection = null;
        $validation = null;
        $dbConfig = null;
        $existingTableCount = null;
        $importResult = null;
        $supportChanges = null;

        try {
            $selection = installer_resolve_sql_selection($_POST, $_FILES);
            $sqlContents = installer_load_sql_contents($selection['path']);
            $validation = installer_validate_sql_dump($sqlContents);

            installer_log_event('info', 'Installer SQL package resolved.', [
                'selection' => $selection,
                'validation' => $validation,
            ], $attemptReference);

            if (!$validation['eligible']) {
                $parts = [];
                if (!empty($validation['missing_tables'])) {
                    $parts[] = 'Missing core tables: ' . implode(', ', $validation['missing_tables']) . '.';
                }
                if (!empty($validation['forbidden_statements'])) {
                    $parts[] = 'Unsupported statements detected: ' . implode(', ', $validation['forbidden_statements']) . '.';
                }
                throw new RuntimeException('The selected SQL file is not a full installation package. ' . implode(' ', $parts));
            }

            $dbConfig = installer_extract_db_config($_POST);
            $serverPdo = installer_connect_database($dbConfig, false);
            installer_create_database_if_missing($serverPdo, $dbConfig['name']);

            $databasePdo = installer_connect_database($dbConfig, true);
            $existingTableCount = installer_count_tables($databasePdo);

            installer_log_event('info', 'Installer database connection established.', [
                'db' => [
                    'host' => $dbConfig['host'],
                    'port' => $dbConfig['port'],
                    'name' => $dbConfig['name'],
                    'user' => $dbConfig['user'],
                ],
                'existing_table_count' => $existingTableCount,
            ], $attemptReference);

            if ($existingTableCount > 0 && !$form['drop_existing_tables']) {
                throw new RuntimeException('The target database already contains tables. Enable database reset to continue with this installation.');
            }

            if ($form['drop_existing_tables']) {
                installer_drop_all_tables($databasePdo);
                installer_log_event('warning', 'Installer reset existing database tables before import.', [
                    'db_name' => $dbConfig['name'],
                ], $attemptReference);
            }

            $importResult = installer_import_sql_dump($databasePdo, $sqlContents);
            installer_log_event('info', 'Installer SQL import completed.', [
                'selection' => $selection,
                'import_result' => $importResult,
            ], $attemptReference);

            $supportChanges = installer_apply_support_schema($databasePdo);
            installer_log_event('info', 'Installer support schema alignment completed.', [
                'support_changes' => $supportChanges,
            ], $attemptReference);

            installer_finalize_installation([
                'base_url' => $form['base_url'],
                'app_name' => $form['app_name'],
                'app_timezone' => $form['app_timezone'],
                'db_host' => $dbConfig['host'],
                'db_port' => $dbConfig['port'],
                'db_name' => $dbConfig['name'],
                'db_user' => $dbConfig['user'],
                'db_pass' => $dbConfig['pass'],
            ], [
                'sql_label' => $selection['label'],
                'mode' => $reinstallMode ? 'reinstall' : 'fresh',
            ]);

            installer_log_event('info', 'Installer finalized successfully.', [
                'mode' => $reinstallMode ? 'reinstall' : 'fresh',
                'selection' => $selection,
                'import_result' => $importResult,
                'support_changes' => $supportChanges,
            ], $attemptReference);

            unset(
                $_SESSION['user_id'],
                $_SESSION['user_name'],
                $_SESSION['role'],
                $_SESSION['department'],
                $_SESSION['department_id'],
                $_SESSION['is_section_head'],
                $_SESSION['permissions'],
                $_SESSION['user_photo'],
                $_SESSION['installer_reentry_token']
            );

            redirect('modules/auth/login?message=installation_complete');
        } catch (Throwable $exception) {
            installer_log_event('error', 'Installer request failed.', [
                'exception_class' => get_class($exception),
                'exception_message' => $exception->getMessage(),
                'trace' => $exception->getTraceAsString(),
                'mode' => $reinstallMode ? 'reinstall' : 'fresh',
                'selection' => $selection,
                'validation' => $validation,
                'db' => $dbConfig ? [
                    'host' => $dbConfig['host'],
                    'port' => $dbConfig['port'],
                    'name' => $dbConfig['name'],
                    'user' => $dbConfig['user'],
                ] : null,
                'existing_table_count' => $existingTableCount,
                'import_result' => $importResult,
                'support_changes' => $supportChanges,
            ], $attemptReference);
            $errorReference = $attemptReference;
            $error = installer_public_error_message($exception, $attemptReference);
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Installer - <?php echo htmlspecialchars(APP_NAME); ?></title>
    <link rel="icon" type="image/png" href="<?php echo asset('images/favicon.png'); ?>">
    <?php $includeJquery = false; ?>
    <?php include __DIR__ . '/../../includes/head_assets.php'; ?>
    <style>
        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            min-height: 100vh;
            margin: 0;
            background:
                radial-gradient(circle at top left, rgba(15,118,110,.14), transparent 32%),
                radial-gradient(circle at bottom right, rgba(29,111,181,.12), transparent 28%),
                linear-gradient(180deg, #f5f9fc 0%, #edf3f9 100%);
            color: #102132;
        }

        .installer-shell {
            max-width: 1240px;
            margin: 0 auto;
            padding: 2rem 1.25rem 3rem;
        }

        .installer-hero {
            display: grid;
            grid-template-columns: minmax(0, 1.3fr) minmax(320px, .9fr);
            gap: 1.5rem;
            margin-bottom: 1.5rem;
        }

        .installer-panel {
            background: rgba(255,255,255,.92);
            border: 1px solid rgba(148,163,184,.16);
            border-radius: 1.5rem;
            box-shadow: 0 24px 60px rgba(15,23,42,.08);
        }

        .hero-copy {
            padding: 2rem;
            position: relative;
            overflow: hidden;
        }

        .hero-copy::after {
            content: "";
            position: absolute;
            inset: auto -4rem -5rem auto;
            width: 14rem;
            height: 14rem;
            border-radius: 999px;
            background: linear-gradient(135deg, rgba(15,118,110,.18), rgba(29,111,181,.18));
            filter: blur(4px);
        }

        .eyebrow {
            display: inline-flex;
            align-items: center;
            gap: .45rem;
            background: rgba(15,118,110,.1);
            color: #0f766e;
            font-size: .78rem;
            font-weight: 700;
            padding: .45rem .85rem;
            border-radius: 999px;
            letter-spacing: .02em;
        }

        .hero-title {
            margin: 1rem 0 .75rem;
            font-size: clamp(2rem, 4vw, 2.8rem);
            line-height: 1.08;
            letter-spacing: -.04em;
        }

        .hero-text {
            margin: 0;
            max-width: 42rem;
            font-size: 1rem;
            line-height: 1.7;
            color: #526273;
        }

        .hero-grid {
            margin-top: 1.35rem;
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: .85rem;
        }

        .stat-card {
            padding: 1rem;
            border-radius: 1rem;
            background: #fff;
            border: 1px solid rgba(148,163,184,.15);
        }

        .stat-card span {
            display: block;
            font-size: .76rem;
            text-transform: uppercase;
            letter-spacing: .08em;
            color: #7b8b9d;
            margin-bottom: .45rem;
        }

        .stat-card strong {
            font-size: 1rem;
        }

        .hero-side {
            padding: 1.5rem;
            display: flex;
            flex-direction: column;
            gap: 1rem;
            background: linear-gradient(180deg, rgba(15,118,110,.08), rgba(255,255,255,.9));
        }

        .mode-pill {
            display: inline-flex;
            align-items: center;
            gap: .45rem;
            width: fit-content;
            padding: .45rem .85rem;
            border-radius: 999px;
            background: rgba(15,23,42,.08);
            color: #122033;
            font-size: .8rem;
            font-weight: 700;
        }

        .dot {
            width: .55rem;
            height: .55rem;
            border-radius: 999px;
            background: #16a34a;
        }

        .side-list {
            margin: 0;
            padding-left: 1rem;
            color: #445463;
            line-height: 1.7;
        }

        .content-grid {
            display: grid;
            grid-template-columns: minmax(0, 1.2fr) minmax(320px, .8fr);
            gap: 1.5rem;
        }

        .panel-body {
            padding: 1.5rem;
        }

        .panel-title {
            margin: 0 0 .35rem;
            font-size: 1.15rem;
            letter-spacing: -.03em;
        }

        .panel-subtitle {
            margin: 0 0 1rem;
            color: #5a6b7b;
            line-height: 1.65;
            font-size: .95rem;
        }

        .alert {
            border-radius: 1rem;
            padding: .95rem 1rem;
            margin-bottom: 1rem;
            border: 1px solid rgba(220,38,38,.18);
            background: rgba(220,38,38,.06);
            color: #b91c1c;
            font-size: .93rem;
            line-height: 1.6;
        }

        .check-list {
            display: grid;
            gap: .75rem;
        }

        .check-item {
            display: grid;
            grid-template-columns: auto 1fr;
            gap: .85rem;
            padding: .95rem 1rem;
            border-radius: 1rem;
            background: #fff;
            border: 1px solid rgba(148,163,184,.16);
        }

        .check-badge {
            width: 2.1rem;
            height: 2.1rem;
            border-radius: .75rem;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: .74rem;
            font-weight: 800;
            text-transform: uppercase;
        }

        .check-badge.pass { background: rgba(22,163,74,.12); color: #15803d; }
        .check-badge.warn { background: rgba(217,119,6,.12); color: #b45309; }
        .check-badge.fail { background: rgba(220,38,38,.12); color: #b91c1c; }

        .installer-form {
            display: grid;
            gap: 1.1rem;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 1rem;
        }

        .field {
            display: flex;
            flex-direction: column;
            gap: .45rem;
        }

        .field label {
            font-size: .82rem;
            font-weight: 700;
            color: #334155;
        }

        .field input,
        .field select,
        .field textarea {
            border: 1px solid rgba(148,163,184,.34);
            border-radius: .95rem;
            padding: .85rem .95rem;
            font: inherit;
            background: #fff;
            color: #102132;
        }

        .field input:focus,
        .field select:focus,
        .field textarea:focus {
            outline: none;
            border-color: #0f766e;
            box-shadow: 0 0 0 4px rgba(15,118,110,.12);
        }

        .span-2 {
            grid-column: span 2;
        }

        .radio-row {
            display: flex;
            flex-wrap: wrap;
            gap: .75rem;
        }

        .radio-card {
            flex: 1 1 14rem;
            border: 1px solid rgba(148,163,184,.24);
            border-radius: 1rem;
            padding: .95rem 1rem;
            background: #fff;
        }

        .radio-card input {
            margin-right: .45rem;
        }

        .toggle-box {
            display: flex;
            align-items: flex-start;
            gap: .7rem;
            padding: 1rem;
            border-radius: 1rem;
            border: 1px solid rgba(245,158,11,.24);
            background: rgba(255,251,235,.85);
        }

        .actions {
            display: flex;
            flex-wrap: wrap;
            gap: .85rem;
            align-items: center;
        }

        .btn {
            border: none;
            border-radius: .95rem;
            padding: .95rem 1.25rem;
            font: inherit;
            font-weight: 700;
            cursor: pointer;
        }

        .btn-primary {
            background: linear-gradient(135deg, #0f766e, #1d6fb5);
            color: #fff;
            box-shadow: 0 14px 28px rgba(15,118,110,.18);
        }

        .btn-secondary {
            background: #fff;
            color: #102132;
            border: 1px solid rgba(148,163,184,.32);
        }

        .muted {
            color: #64748b;
            font-size: .88rem;
            line-height: 1.6;
        }

        @media (max-width: 980px) {
            .installer-hero,
            .content-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 700px) {
            .installer-shell {
                padding: 1rem .85rem 2rem;
            }

            .hero-copy,
            .hero-side,
            .panel-body {
                padding: 1.2rem;
            }

            .hero-grid,
            .form-grid {
                grid-template-columns: 1fr;
            }

            .span-2 {
                grid-column: auto;
            }
        }
    </style>
</head>
<body>
<div class="installer-shell">
    <section class="installer-hero">
        <div class="installer-panel hero-copy">
            <span class="eyebrow">Deployment Installer</span>
            <h1 class="hero-title"><?php echo $reinstallMode ? 'Reinstall the live system safely' : 'Prepare the ERP before first launch'; ?></h1>
            <p class="hero-text">This installer checks the Apache hosting environment, captures the deployment settings, and imports a full SQL package only when the server is ready. After completion, setup locks again until a system administrator explicitly unlocks a reinstall window.</p>

            <div class="hero-grid">
                <div class="stat-card">
                    <span>Environment</span>
                    <strong><?php echo $scan['ready'] ? 'Ready to install' : 'Action required'; ?></strong>
                </div>
                <div class="stat-card">
                    <span>Last SQL</span>
                    <strong><?php echo htmlspecialchars($metadata['last_sql_file'] !== '' ? $metadata['last_sql_file'] : 'Not recorded'); ?></strong>
                </div>
                <div class="stat-card">
                    <span>Install lock</span>
                    <strong><?php echo $reinstallMode ? 'Temporarily open' : 'Enabled after install'; ?></strong>
                </div>
            </div>
        </div>

        <aside class="installer-panel hero-side">
            <span class="mode-pill"><span class="dot"></span><?php echo $reinstallMode ? 'Reinstall window' : 'Fresh installation'; ?></span>
            <div>
                <h2 class="panel-title">Recommended server admin checklist</h2>
                <p class="panel-subtitle">These tasks should be confirmed before you press install.</p>
                <ul class="side-list">
                    <?php foreach (installer_recommended_admin_tasks() as $task): ?>
                        <li><?php echo htmlspecialchars($task); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php if ($reinstallMode): ?>
                <div class="muted">
                    This session was opened from the protected system settings utility. If you opened it by mistake, cancel reinstall below to return the application to normal service.
                </div>
            <?php endif; ?>
        </aside>
    </section>

    <section class="content-grid">
        <div class="installer-panel">
            <div class="panel-body">
                <h2 class="panel-title">Installation workflow</h2>
                <p class="panel-subtitle">Provide the deployment settings, choose the SQL source, and decide whether the target database should be cleared first. Existing filesystem SQL files and direct upload are both supported.</p>

                <?php if ($error !== ''): ?>
                    <div class="alert">
                        <div><?php echo htmlspecialchars($error); ?></div>
                        <?php if ($errorReference !== ''): ?>
                            <div style="margin-top:.45rem;font-size:.84rem;opacity:.85;">
                                Log file: <?php echo htmlspecialchars($errorLogPath); ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <form method="POST" action="index" enctype="multipart/form-data" class="installer-form">
                    <input type="hidden" name="_csrf" value="<?php echo htmlspecialchars(csrf_token('installer_action')); ?>">
                    <input type="hidden" name="installer_token" value="<?php echo htmlspecialchars($installerToken); ?>">

                    <div class="form-grid">
                        <div class="field">
                            <label for="app_name">Application name</label>
                            <input type="text" id="app_name" name="app_name" value="<?php echo htmlspecialchars($form['app_name']); ?>" required>
                        </div>
                        <div class="field">
                            <label for="app_timezone">Timezone</label>
                            <input type="text" id="app_timezone" name="app_timezone" value="<?php echo htmlspecialchars($form['app_timezone']); ?>" placeholder="Africa/Blantyre" required>
                        </div>
                        <div class="field span-2">
                            <label for="base_url">Base URL</label>
                            <input type="url" id="base_url" name="base_url" value="<?php echo htmlspecialchars($form['base_url']); ?>" required>
                        </div>
                        <div class="field">
                            <label for="db_host">Database host</label>
                            <input type="text" id="db_host" name="db_host" value="<?php echo htmlspecialchars($form['db_host']); ?>" required>
                        </div>
                        <div class="field">
                            <label for="db_port">Database port</label>
                            <input type="number" id="db_port" name="db_port" value="<?php echo htmlspecialchars($form['db_port']); ?>" min="1" required>
                        </div>
                        <div class="field">
                            <label for="db_name">Database name</label>
                            <input type="text" id="db_name" name="db_name" value="<?php echo htmlspecialchars($form['db_name']); ?>" required>
                        </div>
                        <div class="field">
                            <label for="db_user">Database user</label>
                            <input type="text" id="db_user" name="db_user" value="<?php echo htmlspecialchars($form['db_user']); ?>" required>
                        </div>
                        <div class="field span-2">
                            <label for="db_pass">Database password</label>
                            <input type="password" id="db_pass" name="db_pass" value="<?php echo htmlspecialchars($form['db_pass']); ?>">
                        </div>
                    </div>

                    <div class="field">
                        <label>SQL source</label>
                        <div class="radio-row">
                            <label class="radio-card">
                                <input type="radio" name="sql_source" value="filesystem" <?php echo $form['sql_source'] !== 'upload' ? 'checked' : ''; ?>>
                                Use an existing SQL file already on the server
                            </label>
                            <label class="radio-card">
                                <input type="radio" name="sql_source" value="upload" <?php echo $form['sql_source'] === 'upload' ? 'checked' : ''; ?>>
                                Upload a release SQL package from this browser
                            </label>
                        </div>
                    </div>

                    <div id="filesystem-fields" class="form-grid" style="<?php echo $form['sql_source'] === 'upload' ? 'display:none;' : ''; ?>">
                        <div class="field">
                            <label for="existing_sql_file">Detected SQL files</label>
                            <select id="existing_sql_file" name="existing_sql_file">
                                <option value="">Select a bundled SQL file</option>
                                <?php foreach ($availableSqlFiles as $file): ?>
                                    <option value="<?php echo htmlspecialchars($file['relative_path']); ?>" <?php echo $form['existing_sql_file'] === $file['relative_path'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($file['basename'] . ' (' . installer_human_size((int) $file['size']) . ')'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="field">
                            <label for="existing_sql_path">Custom SQL path</label>
                            <input type="text" id="existing_sql_path" name="existing_sql_path" value="<?php echo htmlspecialchars($form['existing_sql_path']); ?>" placeholder="Relative or absolute filesystem path">
                        </div>
                    </div>

                    <div id="upload-fields" class="form-grid" style="<?php echo $form['sql_source'] === 'upload' ? '' : 'display:none;'; ?>">
                        <div class="field span-2">
                            <label for="sql_upload">Upload SQL package</label>
                            <input type="file" id="sql_upload" name="sql_upload" accept=".sql">
                        </div>
                    </div>

                    <label class="toggle-box">
                        <input type="checkbox" name="drop_existing_tables" <?php echo $form['drop_existing_tables'] ? 'checked' : ''; ?>>
                        <span>
                            <strong>Reset the target database before import</strong><br>
                            <span class="muted">Required when reinstalling over an existing database. This drops all current tables in the selected schema before the new SQL package is imported.</span>
                        </span>
                    </label>

                    <div class="actions">
                        <button type="submit" name="action" value="install" class="btn btn-primary">Run Installer</button>
                        <?php if ($reinstallMode): ?>
                            <button type="submit" name="action" value="cancel_reinstall" class="btn btn-secondary">Cancel Reinstall</button>
                        <?php endif; ?>
                        <span class="muted">Only full installation SQL packages are accepted. Partial migration scripts are rejected automatically.</span>
                    </div>
                </form>
            </div>
        </div>

        <div class="installer-panel">
            <div class="panel-body">
                <h2 class="panel-title">Pre-installation environment scan</h2>
                <p class="panel-subtitle">The installer checks the essentials that commonly fail on shared hosting, cPanel, and manual Apache deployments.</p>

                <div class="check-list">
                    <?php foreach ($scan['checks'] as $check): ?>
                        <div class="check-item">
                            <span class="check-badge <?php echo htmlspecialchars($check['status']); ?>"><?php echo htmlspecialchars($check['status']); ?></span>
                            <div>
                                <strong><?php echo htmlspecialchars($check['label']); ?></strong>
                                <div class="muted"><?php echo htmlspecialchars($check['details']); ?></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div style="margin-top:1.25rem;padding:1rem;border-radius:1rem;background:#fff;border:1px solid rgba(148,163,184,.16);">
                    <strong>Current installer policy</strong>
                    <div class="muted" style="margin-top:.4rem;">
                        Installation is allowed only while the system is unconfigured, or while a system administrator has explicitly unlocked a reinstall session. Once installation finishes, the public installer route locks again.
                    </div>
                </div>
            </div>
        </div>
    </section>
</div>

<script>
(function () {
    var sourceInputs = document.querySelectorAll('input[name="sql_source"]');
    var filesystemFields = document.getElementById('filesystem-fields');
    var uploadFields = document.getElementById('upload-fields');

    function syncSourceFields() {
        var selected = document.querySelector('input[name="sql_source"]:checked');
        var useUpload = selected && selected.value === 'upload';

        filesystemFields.style.display = useUpload ? 'none' : 'grid';
        uploadFields.style.display = useUpload ? 'grid' : 'none';
    }

    sourceInputs.forEach(function (input) {
        input.addEventListener('change', syncSourceFields);
    });

    syncSourceFields();
})();
</script>
</body>
</html>
