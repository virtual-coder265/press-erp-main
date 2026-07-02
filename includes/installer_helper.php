<?php

if (!function_exists('env')) {
    require_once __DIR__ . '/../config/env.php';
}

if (!defined('ROOT_PATH')) {
    define('ROOT_PATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);
}

require_once __DIR__ . '/settings_helper.php';

if (!function_exists('installer_public_route')) {
    function installer_public_route(): string
    {
        return 'modules/installer/index';
    }
}

if (!function_exists('installer_detect_base_url')) {
    function installer_detect_base_url(): string
    {
        $scheme = 'http';
        if (
            (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
        ) {
            $scheme = 'https';
        }

        $host = trim((string) ($_SERVER['HTTP_HOST'] ?? 'localhost'));
        $scriptDir = str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '')));
        $basePath = preg_replace('#/modules/installer$#', '', rtrim($scriptDir, '/'));

        return rtrim($scheme . '://' . $host . ($basePath !== '' ? $basePath : ''), '/') . '/';
    }
}

if (!function_exists('installer_env_bool')) {
    function installer_env_bool(string $key, bool $default = false): bool
    {
        $value = env($key, $default ? 'true' : 'false');
        $normalized = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        return $normalized === null ? $default : $normalized;
    }
}

if (!function_exists('installer_current_path')) {
    function installer_current_path(): string
    {
        return str_replace('\\', '/', (string) ($_SERVER['PHP_SELF'] ?? $_SERVER['SCRIPT_NAME'] ?? ''));
    }
}

if (!function_exists('installer_is_installer_request')) {
    function installer_is_installer_request(): bool
    {
        return strpos(installer_current_path(), '/modules/installer') !== false;
    }
}

if (!function_exists('installer_env_has_explicit_state')) {
    function installer_env_has_explicit_state(): bool
    {
        $envValues = parse_env_file_to_array();

        return array_key_exists('APP_INSTALLED', $envValues);
    }
}

if (!function_exists('installer_default_db_config')) {
    function installer_default_db_config(): array
    {
        return [
            'host' => trim((string) env('DB_HOST', 'localhost')),
            'port' => max(1, (int) env('DB_PORT', '3306')),
            'name' => trim((string) env('DB_NAME', 'press_erp')),
            'user' => trim((string) env('DB_USER', 'root')),
            'pass' => (string) env('DB_PASS', ''),
        ];
    }
}

if (!function_exists('installer_extract_db_config')) {
    function installer_extract_db_config(array $source): array
    {
        $defaults = installer_default_db_config();

        return [
            'host' => trim((string) ($source['db_host'] ?? $defaults['host'])),
            'port' => max(1, (int) ($source['db_port'] ?? $defaults['port'])),
            'name' => trim((string) ($source['db_name'] ?? $defaults['name'])),
            'user' => trim((string) ($source['db_user'] ?? $defaults['user'])),
            'pass' => (string) ($source['db_pass'] ?? $defaults['pass']),
        ];
    }
}

if (!function_exists('installer_escape_identifier')) {
    function installer_escape_identifier(string $identifier): string
    {
        if (!preg_match('/^[A-Za-z0-9_$-]+$/', $identifier)) {
            throw new InvalidArgumentException('Database names may only contain letters, numbers, underscores, dollar signs, and hyphens.');
        }

        return '`' . str_replace('`', '``', $identifier) . '`';
    }
}

if (!function_exists('installer_connect_database')) {
    function installer_connect_database(array $config, bool $includeDatabase = true): PDO
    {
        if ($config['host'] === '') {
            throw new RuntimeException('Database host is required.');
        }

        if ($config['user'] === '') {
            throw new RuntimeException('Database username is required.');
        }

        if ($includeDatabase && $config['name'] === '') {
            throw new RuntimeException('Database name is required.');
        }

        $dsn = 'mysql:host=' . $config['host'] . ';port=' . (int) $config['port'] . ';charset=utf8mb4';
        if ($includeDatabase) {
            $dsn .= ';dbname=' . $config['name'];
        }

        return new PDO($dsn, $config['user'], $config['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_TIMEOUT => 5,
        ]);
    }
}

if (!function_exists('installer_legacy_database_ready')) {
    function installer_legacy_database_ready(): bool
    {
        static $resolved = null;

        if ($resolved !== null) {
            return $resolved;
        }

        $config = installer_default_db_config();
        if ($config['host'] === '' || $config['name'] === '' || $config['user'] === '') {
            $resolved = false;
            return $resolved;
        }

        try {
            $pdo = installer_connect_database($config, true);
            $stmt = $pdo->prepare("
                SELECT COUNT(*)
                FROM information_schema.TABLES
                WHERE TABLE_SCHEMA = :schema_name
                  AND TABLE_NAME IN ('users', 'roles')
            ");
            $stmt->execute(['schema_name' => $config['name']]);
            $resolved = ((int) $stmt->fetchColumn()) === 2;
        } catch (Throwable $exception) {
            $resolved = false;
        }

        return $resolved;
    }
}

if (!function_exists('installer_is_installed')) {
    function installer_is_installed(): bool
    {
        static $installed = null;

        if ($installed !== null) {
            return $installed;
        }

        if (installer_env_has_explicit_state()) {
            $installed = installer_env_bool('APP_INSTALLED', false);
            return $installed;
        }

        $installed = installer_legacy_database_ready();
        return $installed;
    }
}

if (!function_exists('installer_get_reentry_token')) {
    function installer_get_reentry_token(): string
    {
        return trim((string) env('INSTALLER_REENTRY_TOKEN', ''));
    }
}

if (!function_exists('installer_is_reinstall_mode')) {
    function installer_is_reinstall_mode(): bool
    {
        return !installer_is_installed() && installer_get_reentry_token() !== '';
    }
}

if (!function_exists('installer_is_fresh_install_mode')) {
    function installer_is_fresh_install_mode(): bool
    {
        return !installer_is_installed() && !installer_is_reinstall_mode();
    }
}

if (!function_exists('installer_sync_reentry_session')) {
    function installer_sync_reentry_session(): void
    {
        $expectedToken = installer_get_reentry_token();
        if ($expectedToken === '') {
            unset($_SESSION['installer_reentry_token']);
            return;
        }

        $providedToken = trim((string) ($_GET['token'] ?? $_POST['installer_token'] ?? ''));
        if ($providedToken !== '' && hash_equals($expectedToken, $providedToken)) {
            $_SESSION['installer_reentry_token'] = $expectedToken;
        }
    }
}

if (!function_exists('installer_has_page_access')) {
    function installer_has_page_access(): bool
    {
        if (installer_is_fresh_install_mode()) {
            return true;
        }

        if (!installer_is_reinstall_mode()) {
            return false;
        }

        $expectedToken = installer_get_reentry_token();
        $sessionToken = (string) ($_SESSION['installer_reentry_token'] ?? '');

        return $expectedToken !== '' && $sessionToken !== '' && hash_equals($expectedToken, $sessionToken);
    }
}

if (!function_exists('installer_bootstrap_guard')) {
    function installer_bootstrap_guard(): void
    {
        if (PHP_SAPI === 'cli') {
            return;
        }

        installer_sync_reentry_session();

        $installerRequest = installer_is_installer_request();

        if (!installer_is_installed()) {
            if ($installerRequest) {
                if (!installer_has_page_access()) {
                    http_response_code(403);
                    die("<div style='padding:3rem;font-family:sans-serif;max-width:42rem;margin:0 auto;text-align:center;'>
                            <h1 style='font-size:2rem;margin-bottom:1rem;'>Installer Access Locked</h1>
                            <p style='font-size:1rem;color:#555;'>This reinstall session requires a valid installer token from the system administration utility.</p>
                         </div>");
                }
                return;
            }

            header('Location: ' . installer_detect_base_url() . ltrim(installer_public_route(), '/'));
            exit;
        }

        if ($installerRequest) {
            $destination = isset($_SESSION['user_id']) ? 'modules/dashboard/index' : 'modules/auth/login';
            header('Location: ' . BASE_URL . ltrim($destination, '/'));
            exit;
        }
    }
}

if (!function_exists('installer_human_size')) {
    function installer_human_size(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }

        $units = ['KB', 'MB', 'GB'];
        $size = $bytes / 1024;
        $unitIndex = 0;

        while ($size >= 1024 && $unitIndex < count($units) - 1) {
            $size /= 1024;
            $unitIndex++;
        }

        return number_format($size, 1) . ' ' . $units[$unitIndex];
    }
}

if (!function_exists('installer_get_available_sql_files')) {
    function installer_get_available_sql_files(): array
    {
        $sqlDirectory = ROOT_PATH . 'sql';
        if (!is_dir($sqlDirectory)) {
            return [];
        }

        $files = [];
        foreach (glob($sqlDirectory . DIRECTORY_SEPARATOR . '*.sql') ?: [] as $path) {
            $basename = basename($path);
            $relativePath = 'sql/' . $basename;
            $recommended = (bool) preg_match('/(?:full_db|release|install|schema)/i', $basename);

            $files[] = [
                'basename' => $basename,
                'relative_path' => $relativePath,
                'absolute_path' => $path,
                'size' => is_file($path) ? (int) filesize($path) : 0,
                'recommended' => $recommended,
            ];
        }

        usort($files, static function (array $left, array $right): int {
            if ($left['recommended'] !== $right['recommended']) {
                return $left['recommended'] ? -1 : 1;
            }

            return strnatcasecmp($left['basename'], $right['basename']);
        });

        return $files;
    }
}

if (!function_exists('installer_default_sql_choice')) {
    function installer_default_sql_choice(array $availableFiles): string
    {
        foreach ($availableFiles as $file) {
            if (stripos($file['basename'], 'full_db') !== false) {
                return $file['relative_path'];
            }
        }

        return $availableFiles[0]['relative_path'] ?? '';
    }
}

if (!function_exists('installer_run_environment_scan')) {
    function installer_run_environment_scan(): array
    {
        $checks = [];
        $serverSoftware = trim((string) ($_SERVER['SERVER_SOFTWARE'] ?? 'Unknown'));
        $apacheModules = function_exists('apache_get_modules') ? apache_get_modules() : [];
        $apacheDetected = function_exists('apache_get_modules') || stripos($serverSoftware, 'apache') !== false;

        $checks[] = [
            'label' => 'PHP runtime',
            'status' => version_compare(PHP_VERSION, '7.4.0', '>=') ? 'pass' : 'fail',
            'details' => 'Detected PHP ' . PHP_VERSION . '. Required version is 7.4 or newer.',
        ];

        $checks[] = [
            'label' => 'Apache web server',
            'status' => $apacheDetected ? 'pass' : 'fail',
            'details' => $apacheDetected
                ? 'Detected ' . ($serverSoftware !== '' ? $serverSoftware : 'Apache-compatible runtime') . '.'
                : 'Detected ' . ($serverSoftware !== '' ? $serverSoftware : 'unknown server') . '. This release currently supports Apache deployments only.',
        ];

        $rewriteStatus = 'warn';
        $rewriteDetails = 'mod_rewrite could not be verified automatically. Confirm it is enabled before go-live.';
        if (!empty($apacheModules)) {
            $rewriteEnabled = in_array('mod_rewrite', $apacheModules, true);
            $rewriteStatus = $rewriteEnabled ? 'pass' : 'fail';
            $rewriteDetails = $rewriteEnabled
                ? 'mod_rewrite is available for clean URLs and .htaccess routing.'
                : 'mod_rewrite was not detected. Clean URLs and installer redirects will fail until it is enabled.';
        } elseif (!$apacheDetected) {
            $rewriteStatus = 'fail';
            $rewriteDetails = 'mod_rewrite cannot be verified because Apache was not detected.';
        }

        $checks[] = [
            'label' => 'URL rewriting',
            'status' => $rewriteStatus,
            'details' => $rewriteDetails,
        ];

        foreach ([
            'pdo_mysql' => 'PDO MySQL extension',
            'mbstring' => 'mbstring extension',
            'openssl' => 'OpenSSL extension',
            'curl' => 'cURL extension',
            'json' => 'JSON extension',
            'fileinfo' => 'Fileinfo extension',
        ] as $extension => $label) {
            $loaded = extension_loaded($extension);
            $checks[] = [
                'label' => $label,
                'status' => $loaded ? 'pass' : 'fail',
                'details' => $loaded
                    ? $label . ' is loaded.'
                    : $label . ' is not loaded. Installation cannot continue until it is enabled.',
            ];
        }

        $fileUploadsEnabled = filter_var((string) ini_get('file_uploads'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        $checks[] = [
            'label' => 'File uploads',
            'status' => ($fileUploadsEnabled === null || $fileUploadsEnabled) ? 'pass' : 'fail',
            'details' => 'PHP file uploads are ' . (($fileUploadsEnabled === null || $fileUploadsEnabled) ? 'enabled' : 'disabled') . '.',
        ];

        $envPath = settings_env_file_path();
        $envWritable = file_exists($envPath) ? is_writable($envPath) : is_writable(dirname($envPath));
        $checks[] = [
            'label' => '.env configuration write access',
            'status' => $envWritable ? 'pass' : 'fail',
            'details' => $envWritable
                ? 'The installer can update ' . basename($envPath) . '.'
                : 'The installer cannot write to ' . $envPath . '. Fix file permissions first.',
        ];

        foreach ([
            ROOT_PATH . 'uploads' => 'Uploads directory',
            ROOT_PATH . 'assets' . DIRECTORY_SEPARATOR . 'uploads' => 'Asset uploads directory',
        ] as $path => $label) {
            $exists = is_dir($path);
            $writable = $exists && is_writable($path);
            $checks[] = [
                'label' => $label,
                'status' => ($exists && $writable) ? 'pass' : 'fail',
                'details' => !$exists
                    ? $path . ' does not exist.'
                    : ($writable ? $path . ' is writable.' : $path . ' is not writable by the web server user.'),
            ];
        }

        $checks[] = [
            'label' => 'Upload limit',
            'status' => 'warn',
            'details' => 'upload_max_filesize is ' . (string) ini_get('upload_max_filesize') . ' and post_max_size is ' . (string) ini_get('post_max_size') . '. Ensure your SQL package fits within both limits when using browser upload.',
        ];

        $failures = 0;
        $warnings = 0;
        foreach ($checks as $check) {
            if ($check['status'] === 'fail') {
                $failures++;
            } elseif ($check['status'] === 'warn') {
                $warnings++;
            }
        }

        return [
            'checks' => $checks,
            'ready' => $failures === 0,
            'failure_count' => $failures,
            'warning_count' => $warnings,
        ];
    }
}

if (!function_exists('installer_recommended_admin_tasks')) {
    function installer_recommended_admin_tasks(): array
    {
        return [
            'Confirm Apache VirtualHost or cPanel document root points to this project and that the RewriteBase in .htaccess matches the deployed path.',
            'Grant the web server account write access to .env, uploads/, and assets/uploads/ before starting the installer.',
            'Prepare a full database backup before any reinstall because the reset option can drop existing tables.',
            'Plan cron or scheduled task execution for reminder, email queue, and notification queue workers after installation.',
            'Enable HTTPS before production use so secure cookies and browser push features behave correctly.',
        ];
    }
}

if (!function_exists('installer_normalize_local_path')) {
    function installer_normalize_local_path(string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            return '';
        }

        if (preg_match('/^[A-Za-z]:[\\\\\\/]/', $path) || substr($path, 0, 1) === '/' || substr($path, 0, 1) === '\\') {
            return $path;
        }

        return ROOT_PATH . ltrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path), DIRECTORY_SEPARATOR);
    }
}

if (!function_exists('installer_resolve_sql_selection')) {
    function installer_resolve_sql_selection(array $post, array $files): array
    {
        $source = trim((string) ($post['sql_source'] ?? 'filesystem'));

        if ($source === 'upload') {
            if (!isset($files['sql_upload']) || !is_array($files['sql_upload'])) {
                throw new RuntimeException('No SQL upload was received.');
            }

            $upload = $files['sql_upload'];
            $errorCode = (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE);
            if ($errorCode !== UPLOAD_ERR_OK) {
                throw new RuntimeException('SQL upload failed. Check the selected file and PHP upload limits.');
            }

            $originalName = basename((string) ($upload['name'] ?? 'database.sql'));
            if (!preg_match('/\.sql$/i', $originalName)) {
                throw new RuntimeException('Uploaded files must use the .sql extension.');
            }

            $tmpName = (string) ($upload['tmp_name'] ?? '');
            if ($tmpName === '' || !is_uploaded_file($tmpName)) {
                throw new RuntimeException('The uploaded SQL file is no longer available.');
            }

            return [
                'mode' => 'upload',
                'path' => $tmpName,
                'label' => $originalName,
            ];
        }

        $manualPath = trim((string) ($post['existing_sql_path'] ?? ''));
        $selectedPath = trim((string) ($post['existing_sql_file'] ?? ''));
        $candidate = installer_normalize_local_path($manualPath !== '' ? $manualPath : $selectedPath);

        if ($candidate === '') {
            throw new RuntimeException('Choose an existing SQL file or upload one before installing.');
        }

        $resolvedPath = realpath($candidate) ?: $candidate;
        if (!is_file($resolvedPath) || !is_readable($resolvedPath)) {
            throw new RuntimeException('The selected SQL file could not be read from the filesystem.');
        }

        if (!preg_match('/\.sql$/i', $resolvedPath)) {
            throw new RuntimeException('Existing SQL files must use the .sql extension.');
        }

        return [
            'mode' => 'filesystem',
            'path' => $resolvedPath,
            'label' => basename($resolvedPath),
        ];
    }
}

if (!function_exists('installer_load_sql_contents')) {
    function installer_load_sql_contents(string $path): string
    {
        $contents = (string) file_get_contents($path);
        if ($contents === '') {
            throw new RuntimeException('The selected SQL file is empty.');
        }

        return $contents;
    }
}

if (!function_exists('installer_validate_sql_dump')) {
    function installer_validate_sql_dump(string $sql): array
    {
        $requiredTables = [
            'roles',
            'departments',
            'users',
            'branches',
            'business_settings',
            'estimations',
            'invoices',
            'messages',
            'notifications',
            'projects',
            'tasks',
            'dispatch_register',
        ];

        $missingTables = [];
        foreach ($requiredTables as $table) {
            if (!preg_match('/CREATE\s+TABLE(?:\s+IF\s+NOT\s+EXISTS)?\s+`?' . preg_quote($table, '/') . '`?/i', $sql)) {
                $missingTables[] = $table;
            }
        }

        $forbiddenStatements = [];
        foreach ([
            'DROP DATABASE' => '/^\s*DROP\s+DATABASE\b/im',
            'GRANT' => '/^\s*GRANT\b/im',
            'REVOKE' => '/^\s*REVOKE\b/im',
        ] as $label => $pattern) {
            if (preg_match($pattern, $sql)) {
                $forbiddenStatements[] = $label;
            }
        }

        return [
            'eligible' => empty($missingTables) && empty($forbiddenStatements),
            'missing_tables' => $missingTables,
            'forbidden_statements' => $forbiddenStatements,
        ];
    }
}

if (!function_exists('installer_statement_excerpt')) {
    function installer_statement_excerpt(string $statement, int $maxLength = 220): string
    {
        $normalized = preg_replace('/\s+/', ' ', trim($statement)) ?? trim($statement);
        if (mb_strlen($normalized) <= $maxLength) {
            return $normalized;
        }

        return mb_substr($normalized, 0, $maxLength - 3) . '...';
    }
}

if (!function_exists('installer_split_sql_statements')) {
    function installer_split_sql_statements(string $sql): array
    {
        $statements = [];
        $buffer = '';
        $length = strlen($sql);
        $inSingleQuote = false;
        $inDoubleQuote = false;
        $inLineComment = false;
        $inBlockComment = false;

        for ($index = 0; $index < $length; $index++) {
            $char = $sql[$index];
            $next = $index + 1 < $length ? $sql[$index + 1] : '';

            if ($inLineComment) {
                if ($char === "\n") {
                    $inLineComment = false;
                }
                continue;
            }

            if ($inBlockComment) {
                if ($char === '*' && $next === '/') {
                    $inBlockComment = false;
                    $index++;
                }
                continue;
            }

            if (!$inSingleQuote && !$inDoubleQuote) {
                if ($char === '#' || ($char === '-' && $next === '-' && (($index + 2) >= $length || ctype_space($sql[$index + 2])))) {
                    $inLineComment = true;
                    if ($char === '-') {
                        $index++;
                    }
                    continue;
                }

                if ($char === '/' && $next === '*') {
                    $inBlockComment = true;
                    $index++;
                    continue;
                }
            }

            if ($char === "'" && !$inDoubleQuote) {
                $escaped = $index > 0 && $sql[$index - 1] === '\\';
                if (!$escaped) {
                    $inSingleQuote = !$inSingleQuote;
                }
                $buffer .= $char;
                continue;
            }

            if ($char === '"' && !$inSingleQuote) {
                $escaped = $index > 0 && $sql[$index - 1] === '\\';
                if (!$escaped) {
                    $inDoubleQuote = !$inDoubleQuote;
                }
                $buffer .= $char;
                continue;
            }

            if ($char === ';' && !$inSingleQuote && !$inDoubleQuote) {
                $statement = trim($buffer);
                if ($statement !== '') {
                    $statements[] = $statement;
                }
                $buffer = '';
                continue;
            }

            $buffer .= $char;
        }

        $statement = trim($buffer);
        if ($statement !== '') {
            $statements[] = $statement;
        }

        return $statements;
    }
}

if (!function_exists('installer_should_skip_statement')) {
    function installer_should_skip_statement(string $statement): bool
    {
        foreach ([
            '/^USE\b/i',
            '/^CREATE\s+DATABASE\b/i',
            '/^ALTER\s+DATABASE\b/i',
            '/^LOCK\s+TABLES\b/i',
            '/^UNLOCK\s+TABLES\b/i',
            '/^START\s+TRANSACTION\b/i',
            '/^COMMIT\b/i',
            '/^ROLLBACK\b/i',
        ] as $pattern) {
            if (preg_match($pattern, $statement)) {
                return true;
            }
        }

        return false;
    }
}

if (!function_exists('installer_import_sql_dump')) {
    function installer_import_sql_dump(PDO $pdo, string $sql): array
    {
        $statements = installer_split_sql_statements($sql);
        $executed = 0;

        foreach ($statements as $position => $statement) {
            if (installer_should_skip_statement($statement)) {
                continue;
            }

            try {
                $pdo->exec($statement);
                $executed++;
            } catch (Throwable $exception) {
                throw new RuntimeException(
                    'SQL import failed near statement ' . ($position + 1) . ' [' . installer_statement_excerpt($statement, 160) . ']: ' . $exception->getMessage()
                );
            }
        }

        return [
            'statement_count' => $executed,
        ];
    }
}

if (!function_exists('installer_table_exists')) {
    function installer_table_exists(PDO $pdo, string $tableName): bool
    {
        $stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
        ");
        $stmt->execute([$tableName]);

        return ((int) $stmt->fetchColumn()) > 0;
    }
}

if (!function_exists('installer_column_exists')) {
    function installer_column_exists(PDO $pdo, string $tableName, string $columnName): bool
    {
        $stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
              AND COLUMN_NAME = ?
        ");
        $stmt->execute([$tableName, $columnName]);

        return ((int) $stmt->fetchColumn()) > 0;
    }
}

if (!function_exists('installer_index_exists')) {
    function installer_index_exists(PDO $pdo, string $tableName, string $indexName): bool
    {
        $stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
              AND INDEX_NAME = ?
        ");
        $stmt->execute([$tableName, $indexName]);

        return ((int) $stmt->fetchColumn()) > 0;
    }
}

if (!function_exists('installer_count_tables')) {
    function installer_count_tables(PDO $pdo): int
    {
        $tables = $pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_NUM);

        return count($tables);
    }
}

if (!function_exists('installer_drop_all_tables')) {
    function installer_drop_all_tables(PDO $pdo): void
    {
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');

        $views = $pdo->query("SHOW FULL TABLES WHERE Table_type = 'VIEW'")->fetchAll(PDO::FETCH_NUM) ?: [];
        foreach ($views as $view) {
            if (!isset($view[0])) {
                continue;
            }
            $pdo->exec('DROP VIEW IF EXISTS `' . str_replace('`', '``', (string) $view[0]) . '`');
        }

        $tables = $pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_NUM) ?: [];
        foreach ($tables as $table) {
            if (!isset($table[0])) {
                continue;
            }
            $pdo->exec('DROP TABLE IF EXISTS `' . str_replace('`', '``', (string) $table[0]) . '`');
        }

        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }
}

if (!function_exists('installer_create_database_if_missing')) {
    function installer_create_database_if_missing(PDO $pdo, string $databaseName): void
    {
        $pdo->exec('CREATE DATABASE IF NOT EXISTS ' . installer_escape_identifier($databaseName) . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    }
}

if (!function_exists('installer_execute_sql_file')) {
    function installer_execute_sql_file(PDO $pdo, string $path): void
    {
        $contents = installer_load_sql_contents($path);
        installer_import_sql_dump($pdo, $contents);
    }
}

if (!function_exists('installer_ensure_permissions_schema')) {
    function installer_ensure_permissions_schema(PDO $pdo): array
    {
        if (installer_table_exists($pdo, 'permissions') && installer_table_exists($pdo, 'role_permissions')) {
            return [];
        }

        installer_execute_sql_file($pdo, ROOT_PATH . 'sql' . DIRECTORY_SEPARATOR . 'permissions_schema.sql');

        return ['permissions_schema'];
    }
}

if (!function_exists('installer_ensure_business_settings_schema')) {
    function installer_ensure_business_settings_schema(PDO $pdo): array
    {
        if (installer_table_exists($pdo, 'business_settings')) {
            return [];
        }

        installer_execute_sql_file($pdo, ROOT_PATH . 'sql' . DIRECTORY_SEPARATOR . 'business_settings.sql');

        return ['business_settings'];
    }
}

if (!function_exists('installer_ensure_ai_schema')) {
    function installer_ensure_ai_schema(PDO $pdo): array
    {
        if (installer_table_exists($pdo, 'ai_usage_events')) {
            return [];
        }

        installer_execute_sql_file($pdo, ROOT_PATH . 'sql' . DIRECTORY_SEPARATOR . 'ai_usage_events.sql');

        return ['ai_usage_events'];
    }
}

if (!function_exists('installer_ensure_user_compatibility')) {
    function installer_ensure_user_compatibility(PDO $pdo): array
    {
        if (!installer_table_exists($pdo, 'users')) {
            return [];
        }

        $changes = [];
        $columnDefinitions = [
            'phone' => "ALTER TABLE `users` ADD COLUMN `phone` VARCHAR(20) DEFAULT NULL AFTER `email`",
            'whatsapp_phone' => "ALTER TABLE `users` ADD COLUMN `whatsapp_phone` VARCHAR(20) DEFAULT NULL AFTER `phone`",
            'address' => "ALTER TABLE `users` ADD COLUMN `address` TEXT DEFAULT NULL AFTER `whatsapp_phone`",
            'reset_token' => "ALTER TABLE `users` ADD COLUMN `reset_token` VARCHAR(255) DEFAULT NULL",
            'reset_expires_at' => "ALTER TABLE `users` ADD COLUMN `reset_expires_at` DATETIME DEFAULT NULL",
        ];

        foreach ($columnDefinitions as $columnName => $sql) {
            if (installer_column_exists($pdo, 'users', $columnName)) {
                continue;
            }

            $pdo->exec($sql);
            $changes[] = 'users.' . $columnName;
        }

        return $changes;
    }
}

if (!function_exists('installer_ensure_notifications_schema')) {
    function installer_ensure_notifications_schema(PDO $pdo): array
    {
        $changes = [];

        if (!installer_table_exists($pdo, 'notifications')) {
            $pdo->exec("
                CREATE TABLE `notifications` (
                    `id` INT PRIMARY KEY AUTO_INCREMENT,
                    `user_id` INT NULL,
                    `message_id` INT NULL,
                    `type` VARCHAR(50) DEFAULT NULL,
                    `title` VARCHAR(255) DEFAULT NULL,
                    `description` TEXT DEFAULT NULL,
                    `link` VARCHAR(255) DEFAULT NULL,
                    `related_id` INT DEFAULT NULL,
                    `is_read` TINYINT(1) DEFAULT 0,
                    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    INDEX `idx_user_read` (`user_id`, `is_read`),
                    INDEX `idx_user_read_created` (`user_id`, `is_read`, `created_at`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
            ");
            $changes[] = 'notifications.create';
        }

        foreach ([
            'message_id' => "ALTER TABLE `notifications` ADD COLUMN `message_id` INT DEFAULT NULL AFTER `user_id`",
            'link' => "ALTER TABLE `notifications` ADD COLUMN `link` VARCHAR(255) DEFAULT NULL AFTER `description`",
            'related_id' => "ALTER TABLE `notifications` ADD COLUMN `related_id` INT DEFAULT NULL AFTER `link`",
        ] as $columnName => $sql) {
            if (installer_column_exists($pdo, 'notifications', $columnName)) {
                continue;
            }

            $pdo->exec($sql);
            $changes[] = 'notifications.' . $columnName;
        }

        if (!installer_index_exists($pdo, 'notifications', 'idx_user_read')) {
            $pdo->exec("ALTER TABLE `notifications` ADD INDEX `idx_user_read` (`user_id`, `is_read`)");
            $changes[] = 'notifications.idx_user_read';
        }

        if (!installer_index_exists($pdo, 'notifications', 'idx_user_read_created')) {
            $pdo->exec("ALTER TABLE `notifications` ADD INDEX `idx_user_read_created` (`user_id`, `is_read`, `created_at`)");
            $changes[] = 'notifications.idx_user_read_created';
        }

        if (!installer_table_exists($pdo, 'notification_settings')) {
            $pdo->exec("
                CREATE TABLE `notification_settings` (
                    `id` INT PRIMARY KEY AUTO_INCREMENT,
                    `user_id` INT NOT NULL,
                    `notification_type` VARCHAR(50) NOT NULL,
                    `email_enabled` TINYINT(1) NOT NULL DEFAULT 1,
                    `in_app_enabled` TINYINT(1) NOT NULL DEFAULT 1,
                    `push_enabled` TINYINT(1) NOT NULL DEFAULT 1,
                    `sms_enabled` TINYINT(1) NOT NULL DEFAULT 0,
                    `whatsapp_enabled` TINYINT(1) NOT NULL DEFAULT 0,
                    UNIQUE KEY `unique_user_type` (`user_id`, `notification_type`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
            ");
            $changes[] = 'notification_settings.create';
        }

        foreach ([
            'push_enabled' => "ALTER TABLE `notification_settings` ADD COLUMN `push_enabled` TINYINT(1) NOT NULL DEFAULT 1",
            'sms_enabled' => "ALTER TABLE `notification_settings` ADD COLUMN `sms_enabled` TINYINT(1) NOT NULL DEFAULT 0",
            'whatsapp_enabled' => "ALTER TABLE `notification_settings` ADD COLUMN `whatsapp_enabled` TINYINT(1) NOT NULL DEFAULT 0",
        ] as $columnName => $sql) {
            if (installer_column_exists($pdo, 'notification_settings', $columnName)) {
                continue;
            }

            $pdo->exec($sql);
            $changes[] = 'notification_settings.' . $columnName;
        }

        foreach (['message', 'task', 'security', 'reminder'] as $type) {
            $stmt = $pdo->prepare("
                INSERT IGNORE INTO `notification_settings`
                    (`user_id`, `notification_type`, `email_enabled`, `in_app_enabled`, `push_enabled`, `sms_enabled`, `whatsapp_enabled`)
                SELECT `id`, :notification_type, 0, 1, 1, 0, 0
                FROM `users`
            ");
            $stmt->execute(['notification_type' => $type]);
        }

        if (!installer_table_exists($pdo, 'notification_queue')) {
            $pdo->exec("
                CREATE TABLE `notification_queue` (
                    `id` INT PRIMARY KEY AUTO_INCREMENT,
                    `user_id` INT NOT NULL,
                    `channel` ENUM('email','sms','whatsapp') NOT NULL,
                    `notification_type` VARCHAR(50) NOT NULL,
                    `title` VARCHAR(255) NOT NULL,
                    `description` TEXT DEFAULT NULL,
                    `link` VARCHAR(255) DEFAULT NULL,
                    `related_id` INT DEFAULT NULL,
                    `context_json` LONGTEXT DEFAULT NULL,
                    `status` ENUM('pending','processing','sent','failed') NOT NULL DEFAULT 'pending',
                    `attempts` INT NOT NULL DEFAULT 0,
                    `last_error` TEXT DEFAULT NULL,
                    `available_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `processed_at` TIMESTAMP NULL DEFAULT NULL,
                    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    INDEX `idx_notification_queue_status_available` (`status`, `available_at`),
                    INDEX `idx_notification_queue_user_created` (`user_id`, `created_at`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
            ");
            $changes[] = 'notification_queue.create';
        }

        return $changes;
    }
}

if (!function_exists('installer_ensure_audit_schema')) {
    function installer_ensure_audit_schema(PDO $pdo): array
    {
        $requiredTables = ['audit_logs', 'security_login_attempts', 'security_ip_blocks', 'notification_dispatch_logs'];
        foreach ($requiredTables as $tableName) {
            if (!installer_table_exists($pdo, $tableName)) {
                installer_execute_sql_file($pdo, ROOT_PATH . 'sql' . DIRECTORY_SEPARATOR . 'admin_audit_utilities.sql');
                return ['admin_audit_utilities'];
            }
        }

        return [];
    }
}

if (!function_exists('installer_apply_support_schema')) {
    function installer_apply_support_schema(PDO $pdo): array
    {
        $changes = [];
        $changes = array_merge($changes, installer_ensure_permissions_schema($pdo));
        $changes = array_merge($changes, installer_ensure_business_settings_schema($pdo));
        $changes = array_merge($changes, installer_ensure_ai_schema($pdo));
        $changes = array_merge($changes, installer_ensure_user_compatibility($pdo));
        $changes = array_merge($changes, installer_ensure_notifications_schema($pdo));
        $changes = array_merge($changes, installer_ensure_audit_schema($pdo));

        return $changes;
    }
}

if (!function_exists('installer_unlock_reinstallation')) {
    function installer_unlock_reinstallation(): string
    {
        $token = bin2hex(random_bytes(24));
        $previousMaintenanceMode = installer_env_bool('MAINTENANCE_MODE', false) ? 'true' : 'false';

        if (!write_env_values([
            'APP_INSTALLED' => 'false',
            'MAINTENANCE_MODE' => 'true',
            'INSTALLER_REENTRY_TOKEN' => $token,
            'INSTALLER_PREVIOUS_MAINTENANCE_MODE' => $previousMaintenanceMode,
        ])) {
            throw new RuntimeException('Unable to unlock the installer. Check .env write permissions.');
        }

        $_SESSION['installer_reentry_token'] = $token;

        return $token;
    }
}

if (!function_exists('installer_finalize_installation')) {
    function installer_finalize_installation(array $appConfig, array $meta = []): void
    {
        $restoreMaintenance = trim((string) env('INSTALLER_PREVIOUS_MAINTENANCE_MODE', 'false'));
        $maintenanceMode = installer_is_reinstall_mode()
            ? (filter_var($restoreMaintenance, FILTER_VALIDATE_BOOLEAN) ? 'true' : 'false')
            : 'false';

        if (!write_env_values([
            'APP_INSTALLED' => 'true',
            'BASE_URL' => rtrim((string) ($appConfig['base_url'] ?? installer_detect_base_url()), '/') . '/',
            'APP_NAME' => (string) ($appConfig['app_name'] ?? env('APP_NAME', 'Gov Press ERP')),
            'APP_TIMEZONE' => (string) ($appConfig['app_timezone'] ?? env('APP_TIMEZONE', 'Africa/Blantyre')),
            'DB_HOST' => (string) ($appConfig['db_host'] ?? env('DB_HOST', 'localhost')),
            'DB_PORT' => (string) ($appConfig['db_port'] ?? env('DB_PORT', '3306')),
            'DB_NAME' => (string) ($appConfig['db_name'] ?? env('DB_NAME', 'press_erp')),
            'DB_USER' => (string) ($appConfig['db_user'] ?? env('DB_USER', 'root')),
            'DB_PASS' => (string) ($appConfig['db_pass'] ?? env('DB_PASS', '')),
            'MAINTENANCE_MODE' => $maintenanceMode,
            'INSTALLER_REENTRY_TOKEN' => '',
            'INSTALLER_PREVIOUS_MAINTENANCE_MODE' => '',
            'INSTALLER_LAST_SQL_FILE' => (string) ($meta['sql_label'] ?? ''),
            'INSTALLER_LAST_MODE' => (string) ($meta['mode'] ?? 'fresh'),
            'INSTALLER_LAST_INSTALLED_AT' => gmdate('c'),
        ])) {
            throw new RuntimeException('Installation completed, but the final environment file update failed.');
        }

        unset($_SESSION['installer_reentry_token']);
    }
}

if (!function_exists('installer_cancel_reinstallation')) {
    function installer_cancel_reinstallation(): void
    {
        $previousMaintenanceMode = trim((string) env('INSTALLER_PREVIOUS_MAINTENANCE_MODE', 'false'));
        $maintenanceMode = filter_var($previousMaintenanceMode, FILTER_VALIDATE_BOOLEAN) ? 'true' : 'false';

        if (!write_env_values([
            'APP_INSTALLED' => 'true',
            'MAINTENANCE_MODE' => $maintenanceMode,
            'INSTALLER_REENTRY_TOKEN' => '',
            'INSTALLER_PREVIOUS_MAINTENANCE_MODE' => '',
        ])) {
            throw new RuntimeException('Unable to cancel reinstall mode because the .env file could not be updated.');
        }

        unset($_SESSION['installer_reentry_token']);
    }
}

if (!function_exists('installer_log_directory')) {
    function installer_log_directory(): string
    {
        return ROOT_PATH . 'logs' . DIRECTORY_SEPARATOR . 'installer';
    }
}

if (!function_exists('installer_ensure_log_directory')) {
    function installer_ensure_log_directory(): string
    {
        $directory = installer_log_directory();
        if (!is_dir($directory)) {
            @mkdir($directory, 0775, true);
        }

        return $directory;
    }
}

if (!function_exists('installer_log_relative_path')) {
    function installer_log_relative_path(?string $date = null): string
    {
        $date = $date ?: date('Y-m-d');

        return 'logs/installer/installer-' . $date . '.log';
    }
}

if (!function_exists('installer_generate_reference')) {
    function installer_generate_reference(string $prefix = 'INS'): string
    {
        return strtoupper($prefix) . '-' . date('Ymd-His') . '-' . substr(bin2hex(random_bytes(4)), 0, 8);
    }
}

if (!function_exists('installer_mask_sensitive_context')) {
    function installer_mask_sensitive_context($value, ?string $key = null)
    {
        $sensitivePattern = '/pass|password|secret|token|key/i';

        if ($key !== null && preg_match($sensitivePattern, $key)) {
            return '[redacted]';
        }

        if (is_array($value)) {
            $masked = [];
            foreach ($value as $childKey => $childValue) {
                $masked[$childKey] = installer_mask_sensitive_context($childValue, is_string($childKey) ? $childKey : null);
            }

            return $masked;
        }

        if (is_object($value)) {
            return installer_mask_sensitive_context((array) $value, $key);
        }

        return $value;
    }
}

if (!function_exists('installer_log_event')) {
    function installer_log_event(string $level, string $message, array $context = [], ?string $reference = null): string
    {
        $reference = $reference ?: installer_generate_reference();
        $directory = installer_ensure_log_directory();
        $date = date('Y-m-d');
        $path = $directory . DIRECTORY_SEPARATOR . 'installer-' . $date . '.log';

        $payload = [
            'timestamp' => date('c'),
            'level' => strtoupper($level),
            'reference' => $reference,
            'message' => $message,
            'context' => installer_mask_sensitive_context($context),
        ];

        $line = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
        $written = @file_put_contents($path, $line, FILE_APPEND | LOCK_EX);
        if ($written === false) {
            error_log('[installer][' . $reference . '] ' . $message . ' ' . json_encode($payload['context'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        }

        return $reference;
    }
}

if (!function_exists('installer_is_safe_public_message')) {
    function installer_is_safe_public_message(string $message): bool
    {
        if ($message === '' || mb_strlen($message) > 260) {
            return false;
        }

        return !preg_match('/SQLSTATE|PDO|syntax error|access violation|stack trace|information_schema|near\s+[\'"]?\?|manual that corresponds/i', $message);
    }
}

if (!function_exists('installer_public_error_message')) {
    function installer_public_error_message(Throwable $exception, string $reference): string
    {
        $message = trim($exception->getMessage());
        if (installer_is_safe_public_message($message)) {
            return $message . ' Reference: ' . $reference . '.';
        }

        return 'Installation could not be completed. Detailed diagnostics were written to ' . installer_log_relative_path() . ' under reference ' . $reference . '.';
    }
}

if (!function_exists('installer_metadata')) {
    function installer_metadata(): array
    {
        return [
            'installed' => installer_is_installed(),
            'mode' => installer_is_reinstall_mode() ? 'reinstall' : (installer_is_fresh_install_mode() ? 'fresh' : 'installed'),
            'last_sql_file' => trim((string) env('INSTALLER_LAST_SQL_FILE', '')),
            'last_mode' => trim((string) env('INSTALLER_LAST_MODE', '')),
            'last_installed_at' => trim((string) env('INSTALLER_LAST_INSTALLED_AT', '')),
        ];
    }
}
