<?php

function audit_admin_access_allowed(): bool
{
    return ($_SESSION['role'] ?? '') === 'System Admin'
        || hasPermission('manage_settings')
        || hasPermission('view_audit_logs')
        || hasPermission('view_system_health');
}

function audit_security_control_allowed(): bool
{
    return ($_SESSION['role'] ?? '') === 'System Admin'
        || hasPermission('manage_settings')
        || hasPermission('manage_security_controls');
}

function audit_require_access(): void
{
    if (!audit_admin_access_allowed()) {
        header('HTTP/1.1 403 Forbidden');
        die('Access Denied.');
    }
}

function audit_table_exists(PDO $pdo, string $tableName): bool
{
    static $cache = [];

    if (array_key_exists($tableName, $cache)) {
        return $cache[$tableName];
    }

    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM information_schema.tables
            WHERE table_schema = DATABASE()
              AND table_name = ?
        ");
        $stmt->execute([$tableName]);
        $cache[$tableName] = ((int) $stmt->fetchColumn()) > 0;
    } catch (Exception $exception) {
        $cache[$tableName] = false;
    }

    return $cache[$tableName];
}

function audit_column_exists(PDO $pdo, string $tableName, string $columnName): bool
{
    static $cache = [];
    $cacheKey = $tableName . '.' . $columnName;

    if (array_key_exists($cacheKey, $cache)) {
        return $cache[$cacheKey];
    }

    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
              AND COLUMN_NAME = ?
        ");
        $stmt->execute([$tableName, $columnName]);
        $cache[$cacheKey] = ((int) $stmt->fetchColumn()) > 0;
    } catch (Exception $exception) {
        $cache[$cacheKey] = false;
    }

    return $cache[$cacheKey];
}

function audit_read_filters(array $source): array
{
    $page = max(1, (int) ($source['page'] ?? 1));

    return [
        'date_from' => trim((string) ($source['date_from'] ?? '')),
        'date_to' => trim((string) ($source['date_to'] ?? '')),
        'category' => trim((string) ($source['category'] ?? '')),
        'severity' => trim((string) ($source['severity'] ?? '')),
        'status' => trim((string) ($source['status'] ?? '')),
        'search' => trim((string) ($source['search'] ?? '')),
        'page' => $page,
    ];
}

function audit_build_log_filter_sql(array $filters): array
{
    $conditions = ['1=1'];
    $params = [];

    if ($filters['date_from'] !== '') {
        $conditions[] = 'l.created_at >= :date_from';
        $params['date_from'] = $filters['date_from'] . ' 00:00:00';
    }

    if ($filters['date_to'] !== '') {
        $conditions[] = 'l.created_at < DATE_ADD(:date_to, INTERVAL 1 DAY)';
        $params['date_to'] = $filters['date_to'];
    }

    if ($filters['category'] !== '') {
        $conditions[] = 'l.category = :category';
        $params['category'] = $filters['category'];
    }

    if ($filters['severity'] !== '') {
        $conditions[] = 'l.severity = :severity';
        $params['severity'] = $filters['severity'];
    }

    if ($filters['status'] !== '') {
        $conditions[] = 'l.status = :status';
        $params['status'] = $filters['status'];
    }

    if ($filters['search'] !== '') {
        $conditions[] = '(l.event_type LIKE :search OR l.message LIKE :search OR l.ip_address LIKE :search OR l.route LIKE :search OR actor.name LIKE :search OR target.name LIKE :search)';
        $params['search'] = '%' . $filters['search'] . '%';
    }

    return [
        'where_sql' => implode(' AND ', $conditions),
        'params' => $params,
    ];
}

function audit_fetch_categories(PDO $pdo): array
{
    if (!audit_table_exists($pdo, 'audit_logs')) {
        return [];
    }

    $stmt = $pdo->query("SELECT DISTINCT category FROM audit_logs WHERE category IS NOT NULL AND category <> '' ORDER BY category ASC");
    return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
}

function audit_format_bytes($bytes): string
{
    if (!is_numeric($bytes) || $bytes < 0) {
        return 'N/A';
    }

    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $bytes = (float) $bytes;
    $power = $bytes > 0 ? (int) floor(log($bytes, 1024)) : 0;
    $power = max(0, min($power, count($units) - 1));

    return number_format($bytes / (1024 ** $power), $power === 0 ? 0 : 2) . ' ' . $units[$power];
}

function audit_format_block_until(?string $blockedUntil): string
{
    return $blockedUntil ? date('Y-m-d H:i', strtotime($blockedUntil)) : 'Until cleared';
}

function audit_read_log_tail(string $path, int $maxLines = 200, int $maxBytes = 262144): array
{
    if (!is_file($path) || !is_readable($path)) {
        return [];
    }

    $fileSize = @filesize($path);
    if (!is_int($fileSize) || $fileSize <= 0) {
        return [];
    }

    $bytesToRead = max(4096, min($maxBytes, $fileSize));
    $handle = @fopen($path, 'rb');
    if ($handle === false) {
        return [];
    }

    if ($fileSize > $bytesToRead) {
        fseek($handle, -$bytesToRead, SEEK_END);
    }

    $contents = stream_get_contents($handle);
    fclose($handle);

    if (!is_string($contents) || $contents === '') {
        return [];
    }

    if ($fileSize > $bytesToRead) {
        $firstNewline = strpos($contents, "\n");
        if ($firstNewline !== false) {
            $contents = substr($contents, $firstNewline + 1);
        }
    }

    $contents = str_replace("\0", '', $contents);
    $lines = preg_split("/\r\n|\n|\r/", trim($contents));
    if (!is_array($lines)) {
        return [];
    }

    $lines = array_values(array_filter(array_map('trim', $lines), static function ($line): bool {
        return $line !== '';
    }));

    return array_slice($lines, -$maxLines);
}

function audit_normalize_log_severity(string $level): string
{
    $level = strtolower(trim($level));

    if (in_array($level, ['emerg', 'alert', 'crit', 'critical', 'fatal', 'error'], true)) {
        return 'critical';
    }

    if (in_array($level, ['warn', 'warning'], true)) {
        return 'warning';
    }

    return 'info';
}

function audit_parse_log_timestamp(string $value): ?int
{
    $value = trim($value);
    if ($value === '') {
        return null;
    }

    $normalized = preg_replace('/(\d{2}:\d{2}:\d{2})\.\d+/', '$1', $value);
    $timestamp = strtotime((string) $normalized);

    return $timestamp === false ? null : $timestamp;
}

function audit_should_surface_log_entry(string $severity, string $message): bool
{
    if ($severity !== 'info') {
        return true;
    }

    return (bool) preg_match('/\b(error|failed|exception|fatal|uncaught|warning|undefined|cannot|denied|blocked)\b/i', $message);
}

function audit_parse_runtime_log_line(string $line, string $label, string $path): ?array
{
    if (!preg_match('/^\[([^\]]+)\]\s+\[([^\]]+)\](?:\s+\[[^\]]+\])*\s*(.*)$/', $line, $matches)) {
        return null;
    }

    $rawTimestamp = trim((string) ($matches[1] ?? ''));
    $channel = trim((string) ($matches[2] ?? ''));
    $message = trim((string) ($matches[3] ?? ''));

    if ($message === '') {
        return null;
    }

    $channelParts = explode(':', $channel, 2);
    $level = $channelParts[1] ?? $channelParts[0] ?? 'info';
    $severity = audit_normalize_log_severity($level);

    if (!audit_should_surface_log_entry($severity, $message)) {
        return null;
    }

    $timestampUnix = audit_parse_log_timestamp($rawTimestamp);

    return [
        'timestamp' => $timestampUnix ? date('Y-m-d H:i:s', $timestampUnix) : $rawTimestamp,
        'timestamp_unix' => $timestampUnix ?? 0,
        'severity' => $severity,
        'source' => $label,
        'origin' => $channel,
        'message' => $message,
        'path' => $path,
    ];
}

function audit_parse_json_log_line(string $line, string $label, string $path): ?array
{
    $payload = json_decode($line, true);
    if (!is_array($payload)) {
        return null;
    }

    $message = trim((string) ($payload['message'] ?? ''));
    $context = $payload['context'] ?? [];
    if (is_array($context) && !empty($context['exception_message'])) {
        $exceptionMessage = trim((string) $context['exception_message']);
        if ($exceptionMessage !== '' && stripos($message, $exceptionMessage) === false) {
            $message .= ($message !== '' ? ' ' : '') . $exceptionMessage;
        }
    }

    if ($message === '') {
        return null;
    }

    $rawLevel = trim((string) ($payload['level'] ?? 'info'));
    $severity = audit_normalize_log_severity($rawLevel);
    if (!audit_should_surface_log_entry($severity, $message)) {
        return null;
    }

    $timestampUnix = audit_parse_log_timestamp((string) ($payload['timestamp'] ?? ''));
    $origin = 'installer';
    if ($rawLevel !== '') {
        $origin .= ':' . strtolower($rawLevel);
    }
    if (!empty($payload['reference'])) {
        $origin .= ' / ' . trim((string) $payload['reference']);
    }

    return [
        'timestamp' => $timestampUnix ? date('Y-m-d H:i:s', $timestampUnix) : (string) ($payload['timestamp'] ?? ''),
        'timestamp_unix' => $timestampUnix ?? 0,
        'severity' => $severity,
        'source' => $label,
        'origin' => $origin,
        'message' => $message,
        'path' => $path,
    ];
}

function audit_discover_error_log_sources(): array
{
    $sources = [];
    $seen = [];
    $repoRoot = rtrim(ROOT_PATH, "\\/");
    $xamppRoot = dirname(dirname($repoRoot));

    $runtimeCandidates = [];
    $configuredErrorLog = trim((string) ini_get('error_log'));
    if ($configuredErrorLog !== '') {
        $runtimeCandidates[] = [
            'label' => 'PHP error log',
            'path' => $configuredErrorLog,
            'parser' => 'runtime',
        ];
    }

    $phpBinaryDir = dirname((string) PHP_BINARY);
    $runtimeCandidates[] = [
        'label' => 'PHP runtime log',
        'path' => $phpBinaryDir . DIRECTORY_SEPARATOR . 'php_error_log',
        'parser' => 'runtime',
    ];
    $runtimeCandidates[] = [
        'label' => 'PHP runtime log',
        'path' => $phpBinaryDir . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'php_error_log',
        'parser' => 'runtime',
    ];
    $runtimeCandidates[] = [
        'label' => 'Apache error log',
        'path' => $xamppRoot . DIRECTORY_SEPARATOR . 'apache' . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'error.log',
        'parser' => 'runtime',
    ];

    foreach ($runtimeCandidates as $candidate) {
        $path = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, (string) $candidate['path']);
        if (!is_file($path) || !is_readable($path)) {
            continue;
        }

        $realPath = realpath($path) ?: $path;
        $key = strtolower($realPath);
        if (isset($seen[$key])) {
            continue;
        }

        $seen[$key] = true;
        $sources[] = [
            'label' => $candidate['label'],
            'path' => $realPath,
            'parser' => $candidate['parser'],
            'size_bytes' => (int) (@filesize($realPath) ?: 0),
            'modified_at' => (int) (@filemtime($realPath) ?: 0),
        ];
    }

    $installerLogs = glob($repoRoot . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'installer' . DIRECTORY_SEPARATOR . '*.log') ?: [];
    rsort($installerLogs);

    foreach (array_slice($installerLogs, 0, 3) as $path) {
        if (!is_file($path) || !is_readable($path)) {
            continue;
        }

        $realPath = realpath($path) ?: $path;
        $key = strtolower($realPath);
        if (isset($seen[$key])) {
            continue;
        }

        $seen[$key] = true;
        $sources[] = [
            'label' => 'Installer log',
            'path' => $realPath,
            'parser' => 'json',
            'size_bytes' => (int) (@filesize($realPath) ?: 0),
            'modified_at' => (int) (@filemtime($realPath) ?: 0),
        ];
    }

    usort($sources, static function (array $left, array $right): int {
        return ($right['modified_at'] ?? 0) <=> ($left['modified_at'] ?? 0);
    });

    return $sources;
}

function audit_collect_recent_error_logs(int $maxEntries = 12, int $scanLinesPerSource = 250): array
{
    $sources = audit_discover_error_log_sources();
    $entries = [];
    $seenEntries = [];

    foreach ($sources as $source) {
        $lines = audit_read_log_tail((string) $source['path'], $scanLinesPerSource);
        foreach ($lines as $line) {
            $entry = null;
            if (($source['parser'] ?? '') === 'json') {
                $entry = audit_parse_json_log_line($line, (string) $source['label'], (string) $source['path']);
            } else {
                $entry = audit_parse_runtime_log_line($line, (string) $source['label'], (string) $source['path']);
            }

            if ($entry === null) {
                continue;
            }

            $entryKey = md5($entry['path'] . '|' . $entry['timestamp'] . '|' . $entry['message']);
            if (isset($seenEntries[$entryKey])) {
                continue;
            }

            $seenEntries[$entryKey] = true;
            $entries[] = $entry;
        }
    }

    usort($entries, static function (array $left, array $right): int {
        $leftTimestamp = (int) ($left['timestamp_unix'] ?? 0);
        $rightTimestamp = (int) ($right['timestamp_unix'] ?? 0);

        if ($leftTimestamp === $rightTimestamp) {
            return strcmp((string) ($right['timestamp'] ?? ''), (string) ($left['timestamp'] ?? ''));
        }

        return $rightTimestamp <=> $leftTimestamp;
    });

    $entries = array_slice($entries, 0, max(1, $maxEntries));
    $summary = [
        'critical' => 0,
        'warning' => 0,
        'info' => 0,
    ];

    foreach ($entries as $entry) {
        $severity = (string) ($entry['severity'] ?? 'info');
        if (!isset($summary[$severity])) {
            $summary[$severity] = 0;
        }
        $summary[$severity]++;
    }

    return [
        'entries' => $entries,
        'sources' => $sources,
        'summary' => $summary,
    ];
}
