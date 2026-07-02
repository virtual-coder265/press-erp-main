<?php
/**
 * Login page background slider helper.
 *
 * Slides are stored as JSON in the `login_slides` business setting.
 * Uploaded images live under assets/uploads/login-slides/.
 */

require_once __DIR__ . '/settings_helper.php';

if (!function_exists('login_slides_default_catalog')) {
    /**
     * Bundled slides used when no custom configuration exists.
     *
     * @return array<int, array{id:string, image:string, title:string, caption:string, enabled:bool, sort:int}>
     */
    function login_slides_default_catalog(): array
    {
        return [
            [
                'id' => 'default-1',
                'image' => 'images/printer.jpg',
                'title' => 'Precision in every print',
                'caption' => 'Track every job from quotation through to dispatch — all in one workspace.',
                'enabled' => true,
                'sort' => 10,
            ],
            [
                'id' => 'default-2',
                'image' => 'images/DigitalPrintingService.jpg',
                'title' => 'Digital printing, redefined',
                'caption' => 'Modern workflows connecting estimations, production, and billing seamlessly.',
                'enabled' => true,
                'sort' => 20,
            ],
            [
                'id' => 'default-3',
                'image' => 'images/print.avif',
                'title' => 'Manage estimations and production',
                'caption' => 'Seamlessly calculate costs, track job progress, and ensure timely delivery with ease.',
                'enabled' => true,
                'sort' => 30,
            ],
            [
                'id' => 'default-4',
                'image' => 'images/15.png',
                'title' => 'Real-time operational clarity',
                'caption' => 'Revenue, active work, and dispatch volume — all visible at a glance.',
                'enabled' => true,
                'sort' => 40,
            ],
            [
                'id' => 'default-5',
                'image' => 'images/17.png',
                'title' => 'Manage projects, tasks, and teams',
                'caption' => 'Streamline collaboration and project management with ease.',
                'enabled' => true,
                'sort' => 50,
            ],
        ];
    }
}

if (!function_exists('login_slides_normalize_entry')) {
    /**
     * @param array<string, mixed> $entry
     * @return array{id:string, image:string, title:string, caption:string, enabled:bool, sort:int}|null
     */
    function login_slides_normalize_entry(array $entry): ?array
    {
        $image = trim((string) ($entry['image'] ?? ''));
        $title = trim((string) ($entry['title'] ?? ''));
        $caption = trim((string) ($entry['caption'] ?? ''));

        if ($image === '' || $title === '') {
            return null;
        }

        $id = trim((string) ($entry['id'] ?? ''));
        if ($id === '') {
            $id = 'slide-' . bin2hex(random_bytes(8));
        }

        return [
            'id' => $id,
            'image' => $image,
            'title' => $title,
            'caption' => $caption,
            'enabled' => !empty($entry['enabled']),
            'sort' => (int) ($entry['sort'] ?? 0),
        ];
    }
}

if (!function_exists('login_slides_get_stored_raw')) {
    function login_slides_get_stored_raw(): array
    {
        $raw = (string) get_setting('login_slides', '');
        if ($raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }
}

if (!function_exists('login_slides_has_custom_config')) {
    function login_slides_has_custom_config(): bool
    {
        return login_slides_get_stored_raw() !== [];
    }
}

if (!function_exists('login_slides_get_all')) {
    /**
     * @return array<int, array{id:string, image:string, title:string, caption:string, enabled:bool, sort:int}>
     */
    function login_slides_get_all(): array
    {
        $raw = login_slides_get_stored_raw();
        if ($raw === []) {
            return login_slides_default_catalog();
        }

        $slides = [];
        foreach ($raw as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $normalized = login_slides_normalize_entry($entry);
            if ($normalized !== null) {
                $slides[] = $normalized;
            }
        }

        if ($slides === []) {
            return login_slides_default_catalog();
        }

        usort($slides, static function (array $left, array $right): int {
            $sortCompare = ($left['sort'] <=> $right['sort']);
            return $sortCompare !== 0 ? $sortCompare : strcmp($left['id'], $right['id']);
        });

        return $slides;
    }
}

if (!function_exists('login_slides_get_active')) {
    /**
     * Slides shown on the login screen.
     *
     * @return array<int, array{id:string, image:string, title:string, caption:string, enabled:bool, sort:int}>
     */
    function login_slides_get_active(): array
    {
        $active = array_values(array_filter(login_slides_get_all(), static function (array $slide): bool {
            return !empty($slide['enabled']);
        }));

        return $active !== [] ? $active : login_slides_default_catalog();
    }
}

if (!function_exists('login_slides_resolve_image_url')) {
    function login_slides_resolve_image_url(string $path): string
    {
        $path = ltrim($path, '/');
        if ($path === '') {
            return '';
        }

        if (preg_match('#^https?://#i', $path)) {
            return $path;
        }

        if (strpos($path, 'assets/') === 0) {
            return rtrim(BASE_URL, '/') . '/' . $path;
        }

        return asset($path);
    }
}

if (!function_exists('login_slides_for_frontend')) {
    /**
     * Payload consumed by the login page slider JavaScript.
     *
     * @return array<int, array{img:string, title:string, caption:string}>
     */
    function login_slides_for_frontend(): array
    {
        $payload = [];
        foreach (login_slides_get_active() as $slide) {
            $payload[] = [
                'img' => login_slides_resolve_image_url($slide['image']),
                'title' => $slide['title'],
                'caption' => $slide['caption'],
            ];
        }

        return $payload;
    }
}

if (!function_exists('login_slides_find_by_id')) {
    function login_slides_find_by_id(string $id): ?array
    {
        foreach (login_slides_get_all() as $slide) {
            if ($slide['id'] === $id) {
                return $slide;
            }
        }

        return null;
    }
}

if (!function_exists('login_slides_save_all')) {
    /**
     * @param array<int, array<string, mixed>> $slides
     */
    function login_slides_save_all(array $slides): bool
    {
        $clean = [];
        foreach ($slides as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $normalized = login_slides_normalize_entry($entry);
            if ($normalized !== null) {
                $clean[] = $normalized;
            }
        }

        usort($clean, static function (array $left, array $right): int {
            return ($left['sort'] <=> $right['sort']) ?: strcmp($left['id'], $right['id']);
        });

        $payload = $clean === [] ? '' : json_encode($clean, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return (bool) update_setting('login_slides', $payload, 'login');
    }
}

if (!function_exists('login_slides_next_sort')) {
    function login_slides_next_sort(array $slides): int
    {
        $max = 0;
        foreach ($slides as $slide) {
            $max = max($max, (int) ($slide['sort'] ?? 0));
        }

        return $max + 10;
    }
}

if (!function_exists('login_slides_delete_uploaded_file')) {
    function login_slides_delete_uploaded_file(string $relativePath): void
    {
        $relativePath = ltrim($relativePath, '/');
        if ($relativePath === '' || strpos($relativePath, 'assets/uploads/login-slides/') !== 0) {
            return;
        }

        $absolute = ROOT_PATH . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        $real = realpath($absolute);
        $allowedRoot = realpath(ROOT_PATH . 'assets' . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'login-slides');

        if ($real === false || $allowedRoot === false) {
            return;
        }
        if (strpos($real, $allowedRoot) !== 0) {
            return;
        }

        @unlink($real);
    }
}

if (!function_exists('login_slides_reset_to_defaults')) {
    function login_slides_reset_to_defaults(): bool
    {
        foreach (login_slides_get_all() as $slide) {
            login_slides_delete_uploaded_file($slide['image']);
        }

        return login_slides_save_all([]);
    }
}
