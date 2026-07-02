<?php
/**
 * System branding (application logo and favicon) helpers.
 */

require_once __DIR__ . '/settings_helper.php';

if (!function_exists('system_branding_upload_prefix')) {
    function system_branding_upload_prefix(): string
    {
        return 'assets/uploads/system-branding/';
    }
}

if (!function_exists('system_branding_setting_key')) {
    /**
     * @return 'system_logo'|'system_favicon'
     */
    function system_branding_setting_key(string $slot): string
    {
        return $slot === 'favicon' ? 'system_favicon' : 'system_logo';
    }
}

if (!function_exists('system_branding_default_asset')) {
    function system_branding_default_asset(string $slot): string
    {
        return $slot === 'favicon' ? 'images/favicon.png' : 'images/logo.png';
    }
}

if (!function_exists('system_branding_stored_path')) {
    function system_branding_stored_path(string $slot): string
    {
        return trim((string) get_setting(system_branding_setting_key($slot), ''));
    }
}

if (!function_exists('system_branding_resolved_url')) {
    /**
     * Public URL for a branding slot, falling back to bundled defaults.
     *
     * @param string $slot logo|favicon
     */
    function system_branding_resolved_url(string $slot): string
    {
        $stored = system_branding_stored_path($slot);
        if ($stored === '') {
            return asset(system_branding_default_asset($slot));
        }

        if (preg_match('/^https?:\/\//i', $stored)) {
            return $stored;
        }

        if ($stored[0] === '/') {
            return rtrim(BASE_URL, '/') . $stored;
        }

        return rtrim(BASE_URL, '/') . '/' . ltrim($stored, '/');
    }
}

if (!function_exists('system_branding_delete_uploaded_file')) {
    function system_branding_delete_uploaded_file(string $relativePath): void
    {
        $relativePath = ltrim($relativePath, '/');
        $prefix = system_branding_upload_prefix();
        if ($relativePath === '' || strpos($relativePath, $prefix) !== 0) {
            return;
        }

        $absolute = ROOT_PATH . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        $real = realpath($absolute);
        $allowedRoot = realpath(ROOT_PATH . str_replace('/', DIRECTORY_SEPARATOR, rtrim($prefix, '/')));

        if ($real === false || $allowedRoot === false) {
            return;
        }
        if (strpos($real, $allowedRoot) !== 0) {
            return;
        }

        @unlink($real);
    }
}

if (!function_exists('system_branding_remove_slot')) {
    function system_branding_remove_slot(string $slot): void
    {
        $key = system_branding_setting_key($slot);
        $previous = system_branding_stored_path($slot);
        update_setting($key, '', 'system');

        if ($previous !== '') {
            system_branding_delete_uploaded_file($previous);
        }
    }
}

if (!function_exists('system_branding_store_upload')) {
    /**
     * Validate, persist, and register an uploaded branding image.
     *
     * @return string Stored public path (e.g. /assets/uploads/system-branding/...)
     */
    function system_branding_store_upload(array $file, string $slot): string
    {
        $profile = $slot === 'favicon' ? 'system_favicon' : 'system_logo';
        $destDir = ROOT_PATH . 'assets' . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'system-branding' . DIRECTORY_SEPARATOR;
        $prefix = $slot === 'favicon' ? 'favicon-' : 'logo-';

        $stored = store_validated_uploaded_file(
            $file,
            $profile,
            $destDir,
            '/assets/uploads/system-branding',
            $prefix
        );

        $relative = ltrim($stored, '/');
        $key = system_branding_setting_key($slot);
        $previous = system_branding_stored_path($slot);

        if (!update_setting($key, $relative, 'system')) {
            system_branding_delete_uploaded_file($relative);
            throw new RuntimeException('Unable to save the branding setting.');
        }

        if ($previous !== '' && $previous !== $relative) {
            system_branding_delete_uploaded_file($previous);
        }

        return $stored;
    }
}

if (!function_exists('system_branding_handle_post')) {
    /**
     * Process logo/favicon uploads and removals from the system settings form.
     */
    function system_branding_handle_post(): void
    {
        foreach (['logo' => 'system_logo', 'favicon' => 'system_favicon'] as $slot => $fileField) {
            $removeFlag = 'remove_' . $fileField;
            if (!empty($_POST[$removeFlag])) {
                system_branding_remove_slot($slot);
                continue;
            }

            if (!isset($_FILES[$fileField]) || ($_FILES[$fileField]['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                continue;
            }

            system_branding_store_upload($_FILES[$fileField], $slot);
        }
    }
}
