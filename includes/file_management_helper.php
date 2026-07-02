<?php
/**
 * File hub: links task, project, message, and invoice documents with a managed library.
 */

const FILE_LIBRARY_SEGMENT = 'file_library';

function file_hub_user_can_view(): bool
{
    if (!function_exists('hasPermission')) {
        return false;
    }

    return hasPermission('view_files')
        || hasPermission('view_projects')
        || hasPermission('view_tasks')
        || hasPermission('view_invoices');
}

function file_hub_user_can_manage_library(): bool
{
    if (!function_exists('hasPermission')) {
        return false;
    }

    return hasPermission('manage_files')
        || hasPermission('manage_tasks')
        || hasPermission('manage_projects')
        || hasPermission('manage_invoices');
}

function file_hub_table_exists(PDO $pdo, string $table): bool
{
    if (!preg_match('/^[a-z0-9_]+$/i', $table)) {
        return false;
    }
    $stmt = $pdo->query('SHOW TABLES LIKE ' . $pdo->quote($table));
    return $stmt && $stmt->fetchColumn() !== false;
}

function file_hub_column_exists(PDO $pdo, string $table, string $column): bool
{
    if (!preg_match('/^[a-z0-9_]+$/i', $table) || !preg_match('/^[a-z0-9_]+$/i', $column)) {
        return false;
    }
    $stmt = $pdo->query("SHOW COLUMNS FROM `$table` LIKE " . $pdo->quote($column));

    return $stmt && $stmt->fetch(PDO::FETCH_ASSOC) !== false;
}

function file_hub_message_attachment_name_sql(PDO $pdo): string
{
    if (file_hub_column_exists($pdo, 'message_attachments', 'original_name')) {
        return 'COALESCE(NULLIF(TRIM(ma.original_name), \'\'), ma.file_name)';
    }

    return 'ma.file_name';
}

function file_library_absolute_root(): string
{
    return rtrim(ROOT_PATH, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . FILE_LIBRARY_SEGMENT;
}

function file_library_web_prefix(): string
{
    return 'uploads/' . FILE_LIBRARY_SEGMENT;
}

function file_management_is_excluded_name(string $basename): bool
{
    if ($basename === '.' || $basename === '..') {
        return true;
    }

    $lower = strtolower($basename);
    $exact = [
        '.ds_store', 'thumbs.db', 'ehthumbs.db', 'ehthumbs_vista.db', 'desktop.ini',
        '.htaccess', 'web.config', '@eadir', 'index.html',
    ];
    if (in_array($lower, $exact, true)) {
        return true;
    }

    if (strpos($basename, '._') === 0) {
        return true;
    }

    if (strpos($lower, '~$') === 0) {
        return true;
    }

    return false;
}

function file_designation_from_extension(string $ext): string
{
    $ext = strtolower(ltrim($ext, '.'));
    $music = ['mp3', 'wav', 'ogg', 'flac', 'm4a', 'aac', 'wma'];
    $docs = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'csv', 'txt', 'rtf', 'ppt', 'pptx', 'odt', 'ods'];
    $media = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg', 'tif', 'tiff', 'ico', 'mp4', 'webm', 'mov', 'avi', 'mkv'];

    if (in_array($ext, $music, true)) {
        return 'music';
    }
    if (in_array($ext, $docs, true)) {
        return 'documents';
    }
    if (in_array($ext, $media, true)) {
        return 'media';
    }

    return 'other';
}

function file_designation_from_filename(string $filename): string
{
    $ext = pathinfo($filename, PATHINFO_EXTENSION);

    return file_designation_from_extension($ext);
}

/**
 * UI preview group for library files (thumbnail / lightbox). Uses MIME when present, else extension.
 *
 * @return string 'image'|'video'|'audio'|'pdf'|'other'
 */
function file_hub_preview_category(?string $mimeType, string $designation, string $originalName): string
{
    $mime = strtolower(trim((string) $mimeType));
    if ($mime !== '') {
        if (strpos($mime, 'image/') === 0) {
            return 'image';
        }
        if (strpos($mime, 'video/') === 0) {
            return 'video';
        }
        if (strpos($mime, 'audio/') === 0) {
            return 'audio';
        }
        if ($mime === 'application/pdf') {
            return 'pdf';
        }
    }

    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $images = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'tif', 'tiff', 'ico', 'svg'];
    if (in_array($ext, $images, true)) {
        return 'image';
    }
    $videos = ['mp4', 'webm', 'mov', 'avi', 'mkv', 'm4v'];
    if (in_array($ext, $videos, true)) {
        return 'video';
    }
    if (in_array($ext, ['ogg'], true) && $designation === 'music') {
        return 'audio';
    }
    if (in_array($ext, ['ogg'], true)) {
        return 'video';
    }
    $audio = ['mp3', 'wav', 'flac', 'm4a', 'aac', 'wma', 'oga'];
    if (in_array($ext, $audio, true)) {
        return 'audio';
    }
    if ($ext === 'pdf') {
        return 'pdf';
    }

    return 'other';
}

function file_library_slugify(string $name): string
{
    $s = strtolower(trim($name));
    $s = preg_replace('/[^a-z0-9._-]+/u', '-', $s);
    $s = trim((string) $s, '-');

    return $s !== '' ? $s : 'folder';
}

function file_library_folder_relative_path(PDO $pdo, int $folderId): ?string
{
    $stmt = $pdo->prepare('SELECT relative_path FROM file_library_folders WHERE id = ?');
    $stmt->execute([$folderId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ? (string) $row['relative_path'] : null;
}

function file_library_next_child_slug(PDO $pdo, ?int $parentId, string $baseSlug): string
{
    $parentPath = '';
    if ($parentId) {
        $parentPath = file_library_folder_relative_path($pdo, $parentId) ?? '';
    }

    $slug = $baseSlug;
    $n = 2;
    while (true) {
        $candidate = $parentPath === '' ? $slug : $parentPath . '/' . $slug;
        $st = $pdo->prepare('SELECT id FROM file_library_folders WHERE relative_path = ? LIMIT 1');
        $st->execute([$candidate]);
        if (!$st->fetchColumn()) {
            return $slug;
        }
        $slug = $baseSlug . '-' . $n;
        $n++;
    }
}

function file_library_get_or_create_folder_chain(PDO $pdo, string $relativeDir, int $userId): ?int
{
    $relativeDir = str_replace('\\', '/', trim($relativeDir, '/'));
    if ($relativeDir === '') {
        return null;
    }

    $parts = array_values(array_filter(explode('/', $relativeDir), static function ($p) {
        return $p !== '' && $p !== '.' && $p !== '..';
    }));

    if ($parts === []) {
        return null;
    }

    $parentId = null;
    $cumulative = '';

    foreach ($parts as $part) {
        if (file_management_is_excluded_name($part)) {
            continue;
        }
        $cumulative = $cumulative === '' ? $part : $cumulative . '/' . $part;

        $find = $pdo->prepare('SELECT id FROM file_library_folders WHERE relative_path = ? LIMIT 1');
        $find->execute([$cumulative]);
        $existing = $find->fetchColumn();
        if ($existing) {
            $parentId = (int) $existing;
            continue;
        }

        $ins = $pdo->prepare('
            INSERT INTO file_library_folders (parent_id, name, slug_segment, relative_path, created_by)
            VALUES (?, ?, ?, ?, ?)
        ');
        $ins->execute([
            $parentId,
            $part,
            $part,
            $cumulative,
            $userId,
        ]);
        $parentId = (int) $pdo->lastInsertId();
    }

    return $parentId;
}

function file_library_sync_disk(PDO $pdo, int $actorId): array
{
    $root = file_library_absolute_root();
    if (!is_dir($root) && !mkdir($root, 0755, true) && !is_dir($root)) {
        return ['registered' => 0, 'skipped' => 0, 'errors' => ['Could not create library root directory.']];
    }

    if (!file_hub_table_exists($pdo, 'file_library_files')) {
        return ['registered' => 0, 'skipped' => 0, 'errors' => ['File library tables are not installed.']];
    }

    $registered = 0;
    $skipped = 0;
    $errors = [];
    $prefix = file_library_web_prefix();
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $fileInfo) {
        if (!$fileInfo->isFile()) {
            continue;
        }
        $basename = $fileInfo->getBasename();
        if (file_management_is_excluded_name($basename)) {
            $skipped++;
            continue;
        }

        $full = $fileInfo->getPathname();
        $relPhysical = str_replace(['\\', '/'], '/', substr($full, strlen(rtrim($root, '/\\'))));
        $relPhysical = ltrim($relPhysical, '/');
        $relFromUploads = 'uploads/' . FILE_LIBRARY_SEGMENT . '/' . $relPhysical;

        $dirRel = dirname($relPhysical);
        if ($dirRel === '.' || $dirRel === '') {
            $dirRel = '';
        } else {
            $dirRel = str_replace('\\', '/', $dirRel);
        }

        try {
            $pdo->beginTransaction();
            $folderId = file_library_get_or_create_folder_chain($pdo, $dirRel, $actorId);

            $size = (int) $fileInfo->getSize();
            $mime = 'application/octet-stream';
            if (function_exists('mime_content_type')) {
                $detected = @mime_content_type($full);
                if (is_string($detected) && $detected !== '') {
                    $mime = $detected;
                }
            }
            $designation = file_designation_from_filename($basename);

            $sql = "INSERT INTO file_library_files (folder_id, relative_path, original_name, mime_type, file_size, designation, uploaded_by)
                VALUES (?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    file_size = VALUES(file_size),
                    mime_type = VALUES(mime_type),
                    original_name = VALUES(original_name),
                    designation = VALUES(designation)";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                $folderId,
                $relFromUploads,
                $basename,
                $mime,
                $size,
                $designation,
                $actorId,
            ]);
            $pdo->commit();
            $registered++;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errors[] = $relFromUploads . ': ' . $e->getMessage();
            $skipped++;
        }
    }

    return ['registered' => $registered, 'skipped' => $skipped, 'errors' => $errors];
}

function file_hub_virtual_folder_counts(PDO $pdo): array
{
    $out = [
        'tasks' => 0,
        'task_docs' => 0,
        'projects' => 0,
        'messages' => 0,
        'invoices' => 0,
        'library' => 0,
    ];

    try {
        if (file_hub_table_exists($pdo, 'task_attachments') && function_exists('hasPermission') && hasPermission('view_tasks')) {
            $out['tasks'] = (int) $pdo->query('SELECT COUNT(*) FROM task_attachments')->fetchColumn();
        }
    } catch (Throwable $e) {
    }

    try {
        if (file_hub_table_exists($pdo, 'task_documentation') && hasPermission('view_tasks')) {
            $out['task_docs'] = (int) $pdo->query(
                "SELECT COUNT(*) FROM task_documentation WHERE document_path IS NOT NULL AND document_path <> ''"
            )->fetchColumn();
        }
    } catch (Throwable $e) {
    }

    try {
        if (file_hub_table_exists($pdo, 'project_comment_attachments') && hasPermission('view_projects')) {
            $out['projects'] = (int) $pdo->query('SELECT COUNT(*) FROM project_comment_attachments')->fetchColumn();
        }
    } catch (Throwable $e) {
    }

    try {
        if (file_hub_table_exists($pdo, 'message_attachments')) {
            $out['messages'] = (int) $pdo->query('SELECT COUNT(*) FROM message_attachments')->fetchColumn();
        }
    } catch (Throwable $e) {
    }

    try {
        if (file_hub_table_exists($pdo, 'invoices') && hasPermission('view_invoices')) {
            $out['invoices'] = (int) $pdo->query(
                "SELECT COUNT(*) FROM invoices WHERE pdf_path IS NOT NULL AND pdf_path <> ''"
            )->fetchColumn();
        }
    } catch (Throwable $e) {
    }

    try {
        if (file_hub_table_exists($pdo, 'file_library_files')) {
            $out['library'] = (int) $pdo->query('SELECT COUNT(*) FROM file_library_files')->fetchColumn();
        }
    } catch (Throwable $e) {
    }

    return $out;
}

function file_hub_collect_recent_files(PDO $pdo, int $limit = 12): array
{
    $rows = [];

    $push = static function (array $r) use (&$rows): void {
        $rows[] = $r;
    };

    try {
        if (file_hub_table_exists($pdo, 'task_attachments') && hasPermission('view_tasks')) {
            $sql = "
                SELECT ta.original_name AS name, ta.file_size AS size_bytes, ta.created_at AS sort_at,
                    ta.file_path AS path, 'task_attachment' AS src,
                    t.id AS task_id, p.id AS project_id,
                    COALESCE(u.name, 'User') AS uploader
                FROM task_attachments ta
                INNER JOIN tasks t ON t.id = ta.task_id
                INNER JOIN projects p ON p.id = t.project_id
                LEFT JOIN users u ON u.id = ta.uploaded_by
                ORDER BY ta.created_at DESC
                LIMIT " . (int) max(1, $limit);
            foreach ($pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $push([
                    'name' => $row['name'],
                    'size_bytes' => (int) $row['size_bytes'],
                    'sort_at' => strtotime($row['sort_at'] ?? 'now'),
                    'designation' => file_designation_from_filename($row['name']),
                    'source_label' => 'Task file',
                    'source_url' => 'modules/tasks/view?id=' . (int) $row['task_id'],
                    'file_url' => BASE_URL . ltrim((string) $row['path'], '/'),
                    'members' => [strtoupper(substr((string) $row['uploader'], 0, 1))],
                ]);
            }
        }
    } catch (Throwable $e) {
    }

    try {
        if (file_hub_table_exists($pdo, 'task_documentation') && hasPermission('view_tasks')) {
            $sql = "
                SELECT td.document_path AS path, td.created_at AS sort_at, t.id AS task_id,
                    COALESCE(u.name, 'User') AS uploader
                FROM task_documentation td
                INNER JOIN tasks t ON t.id = td.task_id
                LEFT JOIN users u ON u.id = td.user_id
                WHERE td.document_path IS NOT NULL AND td.document_path <> ''
                ORDER BY td.created_at DESC
                LIMIT " . (int) max(1, $limit);
            foreach ($pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $path = (string) $row['path'];
                $base = basename($path);
                $push([
                    'name' => $base,
                    'size_bytes' => file_hub_disk_size(ROOT_PATH . ltrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path), '/')),
                    'sort_at' => strtotime($row['sort_at'] ?? 'now'),
                    'designation' => file_designation_from_filename($base),
                    'source_label' => 'Task submission',
                    'source_url' => 'modules/tasks/view?id=' . (int) $row['task_id'],
                    'file_url' => BASE_URL . ltrim($path, '/'),
                    'members' => [strtoupper(substr((string) $row['uploader'], 0, 1))],
                ]);
            }
        }
    } catch (Throwable $e) {
    }

    try {
        if (file_hub_table_exists($pdo, 'project_comment_attachments') && hasPermission('view_projects')) {
            $sql = "
                SELECT pca.file_name AS name, pca.file_size AS size_bytes, pca.created_at AS sort_at,
                    pca.file_path AS path, pc.project_id AS project_id
                FROM project_comment_attachments pca
                INNER JOIN project_comments pc ON pc.id = pca.comment_id
                ORDER BY pca.created_at DESC
                LIMIT " . (int) max(1, $limit);
            foreach ($pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $push([
                    'name' => $row['name'],
                    'size_bytes' => (int) $row['size_bytes'],
                    'sort_at' => strtotime($row['sort_at'] ?? 'now'),
                    'designation' => file_designation_from_filename($row['name']),
                    'source_label' => 'Project',
                    'source_url' => 'modules/projects/view?id=' . (int) $row['project_id'],
                    'file_url' => BASE_URL . ltrim((string) $row['path'], '/'),
                    'members' => ['P'],
                ]);
            }
        }
    } catch (Throwable $e) {
    }

    try {
        if (file_hub_table_exists($pdo, 'message_attachments') && file_hub_table_exists($pdo, 'messages')) {
            $nameSql = file_hub_message_attachment_name_sql($pdo);
            $hasConv = file_hub_table_exists($pdo, 'conversations');
            $convJoin = $hasConv
                ? ", (SELECT c.id FROM conversations c WHERE (c.participant1_id = m.sender_id AND c.participant2_id = m.recipient_id)
                    OR (c.participant1_id = m.recipient_id AND c.participant2_id = m.sender_id) ORDER BY c.id DESC LIMIT 1) AS conversation_id"
                : '';
            $sql = "
                SELECT $nameSql AS name, ma.file_size AS size_bytes, ma.created_at AS sort_at,
                    ma.file_path AS path, m.id AS message_id $convJoin
                FROM message_attachments ma
                INNER JOIN messages m ON m.id = ma.message_id
                ORDER BY ma.created_at DESC
                LIMIT " . (int) max(1, $limit);
            foreach ($pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $convId = isset($row['conversation_id']) ? (int) $row['conversation_id'] : 0;
                $msgLink = $convId > 0
                    ? 'modules/messaging/view?id=' . $convId
                    : 'modules/messaging/inbox';
                $push([
                    'name' => $row['name'],
                    'size_bytes' => (int) $row['size_bytes'],
                    'sort_at' => strtotime($row['sort_at'] ?? 'now'),
                    'designation' => file_designation_from_filename($row['name']),
                    'source_label' => 'Message',
                    'source_url' => $msgLink,
                    'file_url' => BASE_URL . ltrim((string) $row['path'], '/'),
                    'members' => ['M'],
                ]);
            }
        }
    } catch (Throwable $e) {
    }

    try {
        if (file_hub_table_exists($pdo, 'invoices') && hasPermission('view_invoices')) {
            $sql = "
                SELECT i.pdf_path AS path, i.generated_date AS sort_date, i.invoice_number AS invno, i.id AS invoice_id
                FROM invoices i
                WHERE i.pdf_path IS NOT NULL AND i.pdf_path <> ''
                ORDER BY i.generated_date DESC, i.id DESC
                LIMIT " . (int) max(1, $limit);
            foreach ($pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $path = (string) $row['path'];
                $base = basename($path);
                $push([
                    'name' => $base !== '' ? $base : ('Invoice-' . $row['invno'] . '.pdf'),
                    'size_bytes' => file_hub_disk_size(ROOT_PATH . ltrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path), '/')),
                    'sort_at' => strtotime($row['sort_date'] ?? 'now'),
                    'designation' => 'documents',
                    'source_label' => 'Invoice PDF',
                    'source_url' => 'modules/invoices/view?id=' . (int) $row['invoice_id'],
                    'file_url' => BASE_URL . ltrim($path, '/'),
                    'members' => ['$'],
                ]);
            }
        }
    } catch (Throwable $e) {
    }

    try {
        if (file_hub_table_exists($pdo, 'file_library_files')) {
            $sql = "
                SELECT lf.original_name AS name, lf.file_size AS size_bytes, UNIX_TIMESTAMP(lf.created_at) AS sort_ts,
                    lf.relative_path AS path, lf.designation AS designation,
                    COALESCE(u.name, 'User') AS uploader
                FROM file_library_files lf
                LEFT JOIN users u ON u.id = lf.uploaded_by
                ORDER BY lf.created_at DESC
                LIMIT " . (int) max(1, $limit);
            foreach ($pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $path = (string) $row['path'];
                $push([
                    'name' => $row['name'],
                    'size_bytes' => (int) $row['size_bytes'],
                    'sort_at' => (int) $row['sort_ts'],
                    'designation' => (string) $row['designation'],
                    'source_label' => 'Library',
                    'source_url' => 'modules/files/index#library',
                    'file_url' => BASE_URL . ltrim($path, '/'),
                    'members' => [strtoupper(substr((string) $row['uploader'], 0, 1))],
                ]);
            }
        }
    } catch (Throwable $e) {
    }

    usort($rows, static function ($a, $b) {
        return ($b['sort_at'] ?? 0) <=> ($a['sort_at'] ?? 0);
    });

    return array_slice($rows, 0, $limit);
}

function file_hub_disk_size(string $absolute): int
{
    if (is_file($absolute)) {
        $s = @filesize($absolute);

        return $s !== false ? (int) $s : 0;
    }

    return 0;
}

function file_hub_format_bytes(?int $bytes): string
{
    $bytes = max(0, (int) $bytes);
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $u = 0;
    $v = (float) $bytes;
    while ($v >= 1024 && $u < count($units) - 1) {
        $v /= 1024;
        $u++;
    }

    return ($u === 0 ? (string) (int) $v : number_format($v, $u >= 3 ? 2 : 1)) . ' ' . $units[$u];
}

function file_hub_storage_summary(PDO $pdo): array
{
    $byDesignation = ['media' => 0, 'documents' => 0, 'music' => 0, 'other' => 0];
    $used = 0;

    $addRow = static function (string $name, int $bytes) use (&$byDesignation, &$used): void {
        $d = file_designation_from_filename($name);
        if (!isset($byDesignation[$d])) {
            $d = 'other';
        }
        $byDesignation[$d] += $bytes;
        $used += $bytes;
    };

    try {
        if (file_hub_table_exists($pdo, 'task_attachments') && hasPermission('view_tasks')) {
            foreach ($pdo->query('SELECT original_name, file_size FROM task_attachments')->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $addRow($r['original_name'], (int) $r['file_size']);
            }
        }
    } catch (Throwable $e) {
    }

    try {
        if (file_hub_table_exists($pdo, 'task_documentation') && hasPermission('view_tasks')) {
            $q = $pdo->query("SELECT document_path FROM task_documentation WHERE document_path IS NOT NULL AND document_path <> ''");
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $p = ROOT_PATH . ltrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, (string) $r['document_path']), '/');
                $addRow(basename($p), file_hub_disk_size($p));
            }
        }
    } catch (Throwable $e) {
    }

    try {
        if (file_hub_table_exists($pdo, 'project_comment_attachments') && hasPermission('view_projects')) {
            foreach ($pdo->query('SELECT file_name, file_size FROM project_comment_attachments')->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $addRow($r['file_name'], (int) $r['file_size']);
            }
        }
    } catch (Throwable $e) {
    }

    try {
        if (file_hub_table_exists($pdo, 'message_attachments')) {
            $mn = file_hub_column_exists($pdo, 'message_attachments', 'original_name')
                ? 'COALESCE(NULLIF(TRIM(original_name), \'\'), file_name)'
                : 'file_name';
            foreach ($pdo->query("SELECT $mn AS fname, file_size FROM message_attachments")->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $addRow($r['fname'], (int) $r['file_size']);
            }
        }
    } catch (Throwable $e) {
    }

    try {
        if (file_hub_table_exists($pdo, 'invoices') && hasPermission('view_invoices')) {
            $q = $pdo->query("SELECT pdf_path FROM invoices WHERE pdf_path IS NOT NULL AND pdf_path <> ''");
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $p = ROOT_PATH . ltrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, (string) $r['pdf_path']), '/');
                $addRow(basename($p), file_hub_disk_size($p));
            }
        }
    } catch (Throwable $e) {
    }

    try {
        if (file_hub_table_exists($pdo, 'file_library_files')) {
            foreach ($pdo->query('SELECT designation, file_size FROM file_library_files')->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $d = (string) $r['designation'];
                if (!isset($byDesignation[$d])) {
                    $d = 'other';
                }
                $b = (int) $r['file_size'];
                $byDesignation[$d] += $b;
                $used += $b;
            }
        }
    } catch (Throwable $e) {
    }

    $quota = 512 * 1024 * 1024 * 1024;
    if (function_exists('get_setting')) {
        $q = get_setting('file_storage_quota_gb', null);
        if ($q !== null && is_numeric($q) && (float) $q > 0) {
            $quota = (int) round((float) $q * 1024 * 1024 * 1024);
        }
    }

    $pct = $quota > 0 ? min(100, (int) round(($used / $quota) * 100)) : 0;

    return [
        'used_bytes' => $used,
        'quota_bytes' => $quota,
        'percent_used' => $pct,
        'by_designation' => $byDesignation,
    ];
}

function file_hub_activity_last_days(PDO $pdo, int $days = 7): array
{
    $days = max(3, min(30, $days));
    $start = strtotime('-' . ($days - 1) . ' days midnight');

    $bucket = [];
    for ($i = 0; $i < $days; $i++) {
        $d = date('Y-m-d', $start + $i * 86400);
        $bucket[$d] = ['media' => 0, 'documents' => 0, 'music' => 0];
    }

    $increment = static function (string $date, string $des) use (&$bucket): void {
        if (!isset($bucket[$date])) {
            return;
        }
        if ($des !== 'documents' && $des !== 'music') {
            $des = 'media';
        }
        $bucket[$date][$des]++;
    };

    $messageNameCol = file_hub_column_exists($pdo, 'message_attachments', 'original_name')
        ? 'COALESCE(NULLIF(TRIM(`original_name`), \'\'), `file_name`)'
        : '`file_name`';

    $tables = [
        ['task_attachments', 'created_at', '`original_name`', null],
        ['project_comment_attachments', 'created_at', '`file_name`', null],
        ['message_attachments', 'created_at', $messageNameCol, null],
        ['file_library_files', 'created_at', '`original_name`', 'designation'],
    ];

    $startDate = date('Y-m-d H:i:s', $start);

    foreach ($tables as [$table, $timeColumn, $namePart, $designationColumn]) {
        try {
            if (!file_hub_table_exists($pdo, $table)) {
                continue;
            }
            if ($table === 'task_attachments' && !hasPermission('view_tasks')) {
                continue;
            }
            if ($table === 'project_comment_attachments' && !hasPermission('view_projects')) {
                continue;
            }

            $selectDes = $designationColumn ? ", `$designationColumn` AS fixed_des" : '';
            $sql = "SELECT DATE(`$timeColumn`) AS d, $namePart AS fname $selectDes FROM `$table` WHERE `$timeColumn` >= ?";
            $st = $pdo->prepare($sql);
            $st->execute([$startDate]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $d = $row['d'] ?? null;
                if (!$d) {
                    continue;
                }
                $des = $designationColumn && !empty($row['fixed_des'])
                    ? (string) $row['fixed_des']
                    : file_designation_from_filename((string) $row['fname']);
                if (!in_array($des, ['media', 'documents', 'music'], true)) {
                    $des = 'media';
                }
                $increment((string) $d, $des);
            }
        } catch (Throwable $e) {
        }
    }

    return $bucket;
}

function file_hub_root_folders(PDO $pdo): array
{
    if (!file_hub_table_exists($pdo, 'file_library_folders')) {
        return [];
    }
    $stmt = $pdo->query("
        SELECT f.*, u.name AS owner_name,
            (SELECT COUNT(*) FROM file_library_files lf WHERE lf.folder_id = f.id) AS file_count
        FROM file_library_folders f
        LEFT JOIN users u ON u.id = f.created_by
        WHERE f.parent_id IS NULL
        ORDER BY f.name ASC
    ");

    return $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
}

function file_library_folder_query_arg(?int $folderId): string
{
    if ($folderId === null || $folderId <= 0) {
        return '';
    }

    return '&folder=' . (int) $folderId;
}

function file_library_nav_url(?int $folderId): string
{
    $base = BASE_URL . 'modules/files/index';
    if ($folderId !== null && $folderId > 0) {
        return $base . '?folder=' . (int) $folderId;
    }

    return $base;
}

function file_library_folder_row(PDO $pdo, int $folderId): ?array
{
    if (!file_hub_table_exists($pdo, 'file_library_folders') || $folderId <= 0) {
        return null;
    }
    $st = $pdo->prepare('SELECT * FROM file_library_folders WHERE id = ?');
    $st->execute([$folderId]);

    $row = $st->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

function file_library_normalize_folder_id(PDO $pdo, ?int $folderId): ?int
{
    if ($folderId === null || $folderId <= 0) {
        return null;
    }
    if (file_library_folder_row($pdo, $folderId) === null) {
        return null;
    }

    return $folderId;
}

function file_library_breadcrumb(PDO $pdo, ?int $folderId): array
{
    $libraryRoot = [['id' => null, 'name' => 'Library']];
    if ($folderId === null || $folderId <= 0 || !file_hub_table_exists($pdo, 'file_library_folders')) {
        return $libraryRoot;
    }

    $segments = [];
    $id = $folderId;
    $seen = [];
    while ($id !== null && $id > 0) {
        if (isset($seen[$id])) {
            break;
        }
        $seen[$id] = true;
        $stmt = $pdo->prepare('SELECT id, parent_id, name FROM file_library_folders WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            break;
        }
        array_unshift($segments, ['id' => (int) $row['id'], 'name' => (string) $row['name']]);
        $pid = $row['parent_id'];
        $id = ($pid !== null && (int) $pid > 0) ? (int) $pid : null;
    }

    return array_merge($libraryRoot, $segments);
}

function file_library_parent_id_for(PDO $pdo, ?int $folderId): ?int
{
    if ($folderId === null || $folderId <= 0) {
        return null;
    }
    $row = file_library_folder_row($pdo, $folderId);
    if (!$row || $row['parent_id'] === null || $row['parent_id'] === '') {
        return null;
    }

    return (int) $row['parent_id'];
}

function file_library_child_folders(PDO $pdo, ?int $parentId): array
{
    if (!file_hub_table_exists($pdo, 'file_library_folders')) {
        return [];
    }

    $sql = '
        SELECT f.*, u.name AS owner_name,
            (SELECT COUNT(*) FROM file_library_files lf WHERE lf.folder_id = f.id) AS file_count,
            (SELECT COUNT(*) FROM file_library_folders c WHERE c.parent_id = f.id) AS subfolder_count
        FROM file_library_folders f
        LEFT JOIN users u ON u.id = f.created_by
        WHERE ';

    if ($parentId === null || $parentId <= 0) {
        $sql .= 'f.parent_id IS NULL ORDER BY f.name ASC';
        $stmt = $pdo->query($sql);
    } else {
        $sql .= 'f.parent_id = ? ORDER BY f.name ASC';
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$parentId]);
    }

    return $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
}

function file_library_files_in_folder(PDO $pdo, ?int $folderId): array
{
    if (!file_hub_table_exists($pdo, 'file_library_files')) {
        return [];
    }

    if ($folderId === null || $folderId <= 0) {
        $stmt = $pdo->query("
            SELECT lf.*, COALESCE(u.name, 'User') AS uploader_name
            FROM file_library_files lf
            LEFT JOIN users u ON u.id = lf.uploaded_by
            WHERE lf.folder_id IS NULL
            ORDER BY lf.original_name ASC
        ");
    } else {
        $stmt = $pdo->prepare("
            SELECT lf.*, COALESCE(u.name, 'User') AS uploader_name
            FROM file_library_files lf
            LEFT JOIN users u ON u.id = lf.uploaded_by
            WHERE lf.folder_id = ?
            ORDER BY lf.original_name ASC
        ");
        $stmt->execute([$folderId]);
    }

    return $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
}

function file_hub_native_storage_summary(PDO $pdo): array
{
    $byDesignation = ['media' => 0, 'documents' => 0, 'music' => 0, 'other' => 0];
    $used = 0;

    try {
        if (file_hub_table_exists($pdo, 'file_library_files')) {
            foreach ($pdo->query('SELECT designation, file_size FROM file_library_files')->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $d = (string) $r['designation'];
                if (!isset($byDesignation[$d])) {
                    $d = 'other';
                }
                $b = (int) $r['file_size'];
                $byDesignation[$d] += $b;
                $used += $b;
            }
        }
    } catch (Throwable $e) {
    }

    $quota = 512 * 1024 * 1024 * 1024;
    if (function_exists('get_setting')) {
        $q = get_setting('file_storage_quota_gb', null);
        if ($q !== null && is_numeric($q) && (float) $q > 0) {
            $quota = (int) round((float) $q * 1024 * 1024 * 1024);
        }
    }

    $pct = $quota > 0 ? min(100, (int) round(($used / $quota) * 100)) : 0;

    return [
        'used_bytes' => $used,
        'quota_bytes' => $quota,
        'percent_used' => $pct,
        'by_designation' => $byDesignation,
    ];
}

function file_hub_native_activity_last_days(PDO $pdo, int $days = 7): array
{
    $days = max(3, min(30, $days));
    $start = strtotime('-' . ($days - 1) . ' days midnight');

    $bucket = [];
    for ($i = 0; $i < $days; $i++) {
        $d = date('Y-m-d', $start + $i * 86400);
        $bucket[$d] = ['media' => 0, 'documents' => 0, 'music' => 0];
    }

    if (!file_hub_table_exists($pdo, 'file_library_files')) {
        return $bucket;
    }

    $startDate = date('Y-m-d H:i:s', $start);
    $sql = "
        SELECT DATE(created_at) AS d, designation
        FROM file_library_files
        WHERE created_at >= ?
    ";
    $st = $pdo->prepare($sql);
    $st->execute([$startDate]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $day = (string) ($row['d'] ?? '');
        if ($day === '' || !isset($bucket[$day])) {
            continue;
        }
        $des = (string) ($row['designation'] ?? 'other');
        if ($des !== 'documents' && $des !== 'music') {
            $des = 'media';
        }
        $bucket[$day][$des]++;
    }

    return $bucket;
}
