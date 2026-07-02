<?php
/**
 * Shared upload validation helpers.
 */

function get_upload_validation_profile(string $profile): array
{
    $profiles = [
        'task_document' => [
            'max_size' => 10 * 1024 * 1024,
            'invalid_type_message' => 'Only JPG, PNG, GIF, WEBP, PDF, DOC, or DOCX files are allowed.',
            'max_size_message' => 'The uploaded document is too large. Maximum size is 10 MB.',
            'extensions' => [
                'jpg' => ['mimes' => ['image/jpeg']],
                'jpeg' => ['mimes' => ['image/jpeg']],
                'png' => ['mimes' => ['image/png']],
                'gif' => ['mimes' => ['image/gif']],
                'webp' => ['mimes' => ['image/webp']],
                'pdf' => ['mimes' => ['application/pdf']],
                'doc' => ['mimes' => ['application/msword', 'application/x-ole-storage', 'application/vnd.ms-office']],
                'docx' => ['mimes' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip']],
            ],
        ],
        'business_logo' => [
            'max_size' => 5 * 1024 * 1024,
            'invalid_type_message' => 'Only JPG, PNG, GIF, or WEBP image files are allowed for the business logo.',
            'max_size_message' => 'The business logo is too large. Maximum size is 5 MB.',
            'extensions' => [
                'jpg' => ['mimes' => ['image/jpeg']],
                'jpeg' => ['mimes' => ['image/jpeg']],
                'png' => ['mimes' => ['image/png']],
                'gif' => ['mimes' => ['image/gif']],
                'webp' => ['mimes' => ['image/webp']],
            ],
        ],
        'system_logo' => [
            'max_size' => 5 * 1024 * 1024,
            'invalid_type_message' => 'Only JPG, PNG, GIF, or WEBP image files are allowed for the application logo.',
            'max_size_message' => 'The application logo is too large. Maximum size is 5 MB.',
            'extensions' => [
                'jpg' => ['mimes' => ['image/jpeg']],
                'jpeg' => ['mimes' => ['image/jpeg']],
                'png' => ['mimes' => ['image/png']],
                'gif' => ['mimes' => ['image/gif']],
                'webp' => ['mimes' => ['image/webp']],
            ],
        ],
        'system_favicon' => [
            'max_size' => 2 * 1024 * 1024,
            'invalid_type_message' => 'Only JPG, PNG, GIF, or WEBP image files are allowed for the application icon.',
            'max_size_message' => 'The application icon is too large. Maximum size is 2 MB.',
            'extensions' => [
                'jpg' => ['mimes' => ['image/jpeg']],
                'jpeg' => ['mimes' => ['image/jpeg']],
                'png' => ['mimes' => ['image/png']],
                'gif' => ['mimes' => ['image/gif']],
                'webp' => ['mimes' => ['image/webp']],
            ],
        ],
        'hero_weather_bg' => [
            'max_size' => 8 * 1024 * 1024,
            'invalid_type_message' => 'Only JPG, PNG, or WEBP image files are allowed for hero weather backgrounds.',
            'max_size_message' => 'The hero weather background is too large. Maximum size is 8 MB.',
            'extensions' => [
                'jpg' => ['mimes' => ['image/jpeg']],
                'jpeg' => ['mimes' => ['image/jpeg']],
                'png' => ['mimes' => ['image/png']],
                'webp' => ['mimes' => ['image/webp']],
            ],
        ],
        'login_slide' => [
            'max_size' => 8 * 1024 * 1024,
            'invalid_type_message' => 'Only JPG, PNG, WEBP, or AVIF image files are allowed for login slides.',
            'max_size_message' => 'The login slide image is too large. Maximum size is 8 MB.',
            'extensions' => [
                'jpg' => ['mimes' => ['image/jpeg']],
                'jpeg' => ['mimes' => ['image/jpeg']],
                'png' => ['mimes' => ['image/png']],
                'webp' => ['mimes' => ['image/webp']],
                'avif' => ['mimes' => ['image/avif', 'application/octet-stream']],
            ],
        ],
        'message_attachment' => [
            'max_size' => 12 * 1024 * 1024,
            'invalid_type_message' => 'Only images, PDFs, Office documents, or common audio (voice note) files are allowed as attachments.',
            'max_size_message' => 'Attachment is too large. Maximum size is 12 MB.',
            'extensions' => [
                'jpg'  => ['mimes' => ['image/jpeg']],
                'jpeg' => ['mimes' => ['image/jpeg']],
                'png'  => ['mimes' => ['image/png']],
                'gif'  => ['mimes' => ['image/gif']],
                'webp' => ['mimes' => ['image/webp']],
                'pdf'  => ['mimes' => ['application/pdf']],
                'doc'  => ['mimes' => ['application/msword', 'application/x-ole-storage', 'application/vnd.ms-office']],
                'docx' => ['mimes' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip']],
                'webm' => ['mimes' => ['audio/webm', 'video/webm', 'application/octet-stream']],
                'ogg'  => ['mimes' => ['audio/ogg', 'application/ogg', 'application/octet-stream']],
                'opus' => ['mimes' => ['audio/opus', 'audio/ogg', 'application/octet-stream']],
                'mp3'  => ['mimes' => ['audio/mpeg', 'audio/mp3', 'application/octet-stream']],
                'wav'  => ['mimes' => ['audio/wav', 'audio/x-wav', 'application/octet-stream']],
                'm4a'  => ['mimes' => ['audio/mp4', 'audio/x-m4a', 'audio/m4a', 'application/octet-stream']],
                'aac'  => ['mimes' => ['audio/aac', 'audio/x-aac', 'application/octet-stream']],
            ],
        ],
        'user_avatar' => [
            'max_size' => 2 * 1024 * 1024,
            'invalid_type_message' => 'Only JPG, PNG, GIF, or WEBP image files are allowed for profile photos.',
            'max_size_message' => 'The profile photo is too large. Maximum size is 2 MB.',
            'extensions' => [
                'jpg'  => ['mimes' => ['image/jpeg']],
                'jpeg' => ['mimes' => ['image/jpeg']],
                'png'  => ['mimes' => ['image/png']],
                'gif'  => ['mimes' => ['image/gif']],
                'webp' => ['mimes' => ['image/webp']],
            ],
        ],
        'file_library' => [
            'max_size' => 50 * 1024 * 1024,
            'invalid_type_message' => 'This file type is not allowed in the file library.',
            'max_size_message' => 'The file is too large. Maximum size is 50 MB.',
            'extensions' => [
                'jpg' => ['mimes' => ['image/jpeg']],
                'jpeg' => ['mimes' => ['image/jpeg']],
                'png' => ['mimes' => ['image/png']],
                'gif' => ['mimes' => ['image/gif']],
                'webp' => ['mimes' => ['image/webp']],
                'pdf' => ['mimes' => ['application/pdf']],
                'doc' => ['mimes' => ['application/msword', 'application/x-ole-storage', 'application/vnd.ms-office']],
                'docx' => ['mimes' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip']],
                'xls' => ['mimes' => ['application/vnd.ms-excel', 'application/x-ole-storage', 'application/vnd.ms-office']],
                'xlsx' => ['mimes' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip']],
                'csv' => ['mimes' => ['text/plain', 'text/csv', 'application/csv', 'text/x-csv', 'application/vnd.ms-excel']],
                'txt' => ['mimes' => ['text/plain']],
                'ppt' => ['mimes' => ['application/vnd.ms-powerpoint', 'application/x-ole-storage', 'application/vnd.ms-office']],
                'pptx' => ['mimes' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation', 'application/zip']],
                'zip' => ['mimes' => ['application/zip', 'application/x-zip-compressed']],
                'mp3' => ['mimes' => ['audio/mpeg', 'audio/mp3', 'application/octet-stream']],
                'wav' => ['mimes' => ['audio/wav', 'audio/x-wav', 'application/octet-stream']],
                'm4a' => ['mimes' => ['audio/mp4', 'audio/m4a', 'application/octet-stream']],
                'mp4' => ['mimes' => ['video/mp4', 'application/octet-stream']],
                'mov' => ['mimes' => ['video/quicktime', 'application/octet-stream']],
            ],
        ],
        'dispatch_import' => [
            'max_size' => 20 * 1024 * 1024,
            'invalid_type_message' => 'Invalid file type. Please upload CSV, Excel (XLSX/XLS), or PDF files.',
            'max_size_message' => 'The import file is too large. Maximum size is 20 MB.',
            'extensions' => [
                'csv' => ['mimes' => ['text/plain', 'text/csv', 'application/csv', 'text/x-csv', 'application/vnd.ms-excel']],
                'xlsx' => ['mimes' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip']],
                'xls' => ['mimes' => ['application/vnd.ms-excel', 'application/x-ole-storage', 'application/vnd.ms-office']],
                'pdf' => ['mimes' => ['application/pdf']],
            ],
        ],
    ];

    if (!isset($profiles[$profile])) {
        throw new InvalidArgumentException('Unknown upload validation profile.');
    }

    return $profiles[$profile];
}

function validate_uploaded_file(array $file, string $profile): array
{
    $config = get_upload_validation_profile($profile);

    if (empty($file) || !isset($file['tmp_name'], $file['name'], $file['error'])) {
        throw new RuntimeException('No file was uploaded.');
    }

    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException(upload_error_message((int) $file['error']));
    }

    if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
        throw new RuntimeException('Invalid upload source detected.');
    }

    $size = (int) ($file['size'] ?? 0);
    if ($size <= 0) {
        throw new RuntimeException('The uploaded file is empty.');
    }

    if ($size > $config['max_size']) {
        throw new RuntimeException($config['max_size_message']);
    }

    $originalName = trim((string) $file['name']);
    if ($originalName === '' || strpos($originalName, "\0") !== false) {
        throw new RuntimeException('The uploaded file name is invalid.');
    }

    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    if ($extension === '' || !isset($config['extensions'][$extension])) {
        throw new RuntimeException($config['invalid_type_message']);
    }

    $mimeType = detect_uploaded_mime_type($file['tmp_name']);
    $allowedMimes = $config['extensions'][$extension]['mimes'] ?? [];
    if (!empty($allowedMimes) && !in_array($mimeType, $allowedMimes, true)) {
        throw new RuntimeException($config['invalid_type_message']);
    }

    run_upload_security_checks($file['tmp_name'], $extension);

    return [
        'original_name' => $originalName,
        'extension' => $extension,
        'mime_type' => $mimeType,
        'size' => $size,
    ];
}

function store_validated_uploaded_file(
    array $file,
    string $profile,
    string $destinationDirectory,
    string $publicPathPrefix,
    string $filenamePrefix = ''
): string {
    $validated = validate_uploaded_file($file, $profile);

    if (!is_dir($destinationDirectory) && !mkdir($destinationDirectory, 0755, true) && !is_dir($destinationDirectory)) {
        throw new RuntimeException('Unable to prepare the upload directory.');
    }

    $filename = build_safe_uploaded_filename($validated['extension'], $filenamePrefix);
    $targetPath = rtrim($destinationDirectory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $filename;

    if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
        throw new RuntimeException('Unable to save the uploaded file.');
    }

    return rtrim($publicPathPrefix, '/') . '/' . $filename;
}

function build_safe_uploaded_filename(string $extension, string $filenamePrefix = ''): string
{
    $prefix = preg_replace('/[^a-zA-Z0-9_-]/', '', $filenamePrefix);
    $random = bin2hex(random_bytes(8));

    return $prefix . time() . '-' . $random . '.' . $extension;
}

function detect_uploaded_mime_type(string $path): string
{
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo !== false) {
            $mime = finfo_file($finfo, $path);
            finfo_close($finfo);
            if (is_string($mime) && $mime !== '') {
                return $mime;
            }
        }
    }

    if (function_exists('mime_content_type')) {
        $mime = @mime_content_type($path);
        if (is_string($mime) && $mime !== '') {
            return $mime;
        }
    }

    return 'application/octet-stream';
}

function run_upload_security_checks(string $path, string $extension): void
{
    switch ($extension) {
        case 'jpg':
        case 'jpeg':
        case 'png':
        case 'gif':
        case 'webp':
            if (@getimagesize($path) === false) {
                throw new RuntimeException('The uploaded image file is invalid or corrupted.');
            }
            return;

        case 'pdf':
            if (read_file_bytes($path, 4) !== '%PDF') {
                throw new RuntimeException('The uploaded PDF file failed the security check.');
            }
            return;

        case 'doc':
        case 'xls':
            if (bin2hex(read_file_bytes($path, 8)) !== 'd0cf11e0a1b11ae1') {
                throw new RuntimeException('The uploaded Office document failed the security check.');
            }
            return;

        case 'docx':
            ensure_zip_document_structure($path, ['[Content_Types].xml', 'word/']);
            return;

        case 'pptx':
            ensure_zip_document_structure($path, ['[Content_Types].xml', 'ppt/']);
            return;

        case 'ppt':
            if (bin2hex(read_file_bytes($path, 8)) !== 'd0cf11e0a1b11ae1') {
                throw new RuntimeException('The uploaded presentation failed the security check.');
            }
            return;

        case 'zip':
            if (read_file_bytes($path, 2) !== 'PK') {
                throw new RuntimeException('The uploaded archive failed the security check.');
            }
            return;

        case 'xlsx':
            ensure_zip_document_structure($path, ['[Content_Types].xml', 'xl/']);
            return;

        case 'csv':
            $sample = read_file_bytes($path, 4096);
            if (strpos($sample, "\0") !== false) {
                throw new RuntimeException('The uploaded CSV file failed the security check.');
            }

            $handle = fopen($path, 'r');
            if ($handle === false) {
                throw new RuntimeException('Unable to inspect the uploaded CSV file.');
            }

            $row = fgetcsv($handle);
            fclose($handle);

            if ($row === false && trim($sample) !== '') {
                throw new RuntimeException('The uploaded CSV file failed the security check.');
            }
            return;
    }
}

function ensure_zip_document_structure(string $path, array $requiredEntries): void
{
    if (read_file_bytes($path, 2) !== 'PK') {
        throw new RuntimeException('The uploaded Office document failed the security check.');
    }

    if (!class_exists('ZipArchive')) {
        return;
    }

    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        throw new RuntimeException('The uploaded Office document could not be inspected.');
    }

    foreach ($requiredEntries as $entry) {
        if ($zip->locateName($entry, ZipArchive::FL_NOCASE) !== false) {
            continue;
        }

        $foundDirectory = false;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if (!isset($stat['name'])) {
                continue;
            }

            if (stripos($stat['name'], $entry) === 0) {
                $foundDirectory = true;
                break;
            }
        }

        if (!$foundDirectory) {
            $zip->close();
            throw new RuntimeException('The uploaded Office document failed the security check.');
        }
    }

    $zip->close();
}

function read_file_bytes(string $path, int $length): string
{
    $handle = fopen($path, 'rb');
    if ($handle === false) {
        throw new RuntimeException('Unable to inspect the uploaded file.');
    }

    $data = fread($handle, $length);
    fclose($handle);

    if ($data === false) {
        throw new RuntimeException('Unable to inspect the uploaded file.');
    }

    return $data;
}

/**
 * True if multipart field has at least one successfully uploaded slice.
 *
 * @param array<string,mixed>|null $field Slice of $_FILES (e.g. $_FILES['comment_files'])
 */
function upload_form_has_any_ok(?array $field): bool
{
    if ($field === null || !isset($field['name'])) {
        return false;
    }

    $names = $field['name'];
    if (!is_array($names)) {
        return ($field['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK;
    }

    foreach ($names as $i => $_name) {
        if (($field['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
            return true;
        }
    }

    return false;
}

/** Whether a stored attachment row is an audio voice-style file for inline playback */
function attachment_is_voice(array $row): bool
{
    $type = strtolower((string) ($row['file_type'] ?? ''));
    if (strpos($type, 'audio/') === 0) {
        return true;
    }

    $name = strtolower((string) ($row['file_name'] ?? ''));

    return (bool) preg_match('/\.(webm|ogg|opus|mp3|wav|m4a|aac)$/i', $name);
}

function upload_error_message(int $errorCode): string
{
    switch ($errorCode) {
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:
            return 'The uploaded file is too large.';
        case UPLOAD_ERR_PARTIAL:
            return 'The uploaded file was only partially received. Please try again.';
        case UPLOAD_ERR_NO_FILE:
            return 'No file was uploaded.';
        case UPLOAD_ERR_NO_TMP_DIR:
            return 'The server is missing a temporary upload directory.';
        case UPLOAD_ERR_CANT_WRITE:
            return 'The server could not write the uploaded file.';
        case UPLOAD_ERR_EXTENSION:
            return 'A server extension blocked the upload.';
        default:
            return 'File upload failed. Please try again.';
    }
}
