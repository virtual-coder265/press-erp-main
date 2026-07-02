<?php
/**
 * Hero weather background helper.
 *
 * Provides the catalogue of weather groups and dayparts that the dashboard
 * hero card cycles through, plus accessors for the admin-managed overrides
 * stored in the `hero_weather_backgrounds` setting.
 */

require_once __DIR__ . '/settings_helper.php';

if (!function_exists('hero_weather_groups')) {
    /**
     * Catalogue of weather groups exposed to admins. The keys mirror the
     * `group` field in the dashboard JS WMO mapping.
     *
     * @return array<string, array{label:string, description:string, icon:string}>
     */
    function hero_weather_groups(): array
    {
        return [
            'clear' => [
                'label' => 'Clear sky',
                'description' => 'Bright, mostly cloudless conditions.',
                'icon' => 'sun',
            ],
            'cloud-partial' => [
                'label' => 'Partly cloudy',
                'description' => 'Calm with soft, drifting cloud cover.',
                'icon' => 'cloud-sun',
            ],
            'cloud-overcast' => [
                'label' => 'Overcast',
                'description' => 'Grey and even cloud cover.',
                'icon' => 'cloud',
            ],
            'fog' => [
                'label' => 'Fog / mist',
                'description' => 'Reduced visibility from low cloud or mist.',
                'icon' => 'cloud-fog',
            ],
            'rain' => [
                'label' => 'Rain',
                'description' => 'Drizzle or steady rainfall.',
                'icon' => 'cloud-rain',
            ],
            'snow' => [
                'label' => 'Snow',
                'description' => 'Snowfall or wintry precipitation.',
                'icon' => 'snowflake',
            ],
            'storm' => [
                'label' => 'Thunderstorm',
                'description' => 'Storms, lightning or heavy violent showers.',
                'icon' => 'cloud-lightning',
            ],
        ];
    }
}

if (!function_exists('hero_weather_dayparts')) {
    /**
     * Catalogue of dayparts exposed to admins.
     *
     * @return array<string, array{label:string, range:string, icon:string}>
     */
    function hero_weather_dayparts(): array
    {
        return [
            'morning' => [
                'label' => 'Morning',
                'range' => '05:00 – 10:59',
                'icon' => 'sunrise',
            ],
            'noon' => [
                'label' => 'Midday',
                'range' => '11:00 – 13:59',
                'icon' => 'sun',
            ],
            'afternoon' => [
                'label' => 'Afternoon',
                'range' => '14:00 – 16:59',
                'icon' => 'sun-medium',
            ],
            'sunset' => [
                'label' => 'Sunset',
                'range' => '17:00 – 19:59',
                'icon' => 'sunset',
            ],
            'night' => [
                'label' => 'Night',
                'range' => '20:00 – 04:59 (and any time without sunlight)',
                'icon' => 'moon',
            ],
        ];
    }
}

if (!function_exists('hero_weather_default_backgrounds')) {
    /**
     * Public URL paths (relative to BASE_URL) for the static fallback assets
     * the dashboard already ships with. These are surfaced in the management
     * UI as the "default" preview when an admin has not uploaded an override.
     *
     * @return array<string, string> map of "group:daypart" => relative asset path
     */
    function hero_weather_default_backgrounds(): array
    {
        $base = 'assets/images/weather-illustrations/';

        return [
            'clear:morning' => $base . 'morning-calm.png',
            'clear:noon' => $base . 'clyde-rs-4XbZCfU2Uoo-unsplash.jpg',
            'clear:afternoon' => $base . 'yellow-late-afternoon.jpg',
            'clear:sunset' => $base . 'sunset.png',
            'clear:night' => $base . 'clear-night.png',

            'cloud-partial:morning' => $base . '10m-partially-cloudy.jpg',
            'cloud-partial:noon' => $base . 'partialy-cloud-noon.png',
            'cloud-partial:afternoon' => $base . 'partially-cloudy-afternoon.png',
            'cloud-partial:sunset' => $base . 'sunset.png',
            'cloud-partial:night' => $base . 'partially-cloud-night.png',

            'cloud-overcast:morning' => $base . 'cloudy-day.png',
            'cloud-overcast:noon' => $base . 'cloudy-day.png',
            'cloud-overcast:afternoon' => $base . '3pm-cloudy-afternoon.jpg',
            'cloud-overcast:sunset' => $base . 'cloudy-day.png',
            'cloud-overcast:night' => $base . 'partially-cloud-night.png',

            'fog:morning' => $base . 'morning-calm.png',
            'fog:noon' => $base . 'cloudy-day.png',
            'fog:afternoon' => $base . 'cloudy-day.png',
            'fog:sunset' => $base . 'sunset.png',
            'fog:night' => $base . 'partially-cloud-night.png',

            'rain:morning' => $base . 'cloudy-day.png',
            'rain:noon' => $base . 'cloudy-day.png',
            'rain:afternoon' => $base . '3pm-cloudy-afternoon.jpg',
            'rain:sunset' => $base . 'sunset.png',
            'rain:night' => $base . 'partially-cloud-night.png',

            'snow:morning' => $base . 'cloudy-day.png',
            'snow:noon' => $base . 'cloudy-day.png',
            'snow:afternoon' => $base . 'cloudy-day.png',
            'snow:sunset' => $base . 'sunset.png',
            'snow:night' => $base . 'partially-cloud-night.png',

            'storm:morning' => $base . 'thundery.png',
            'storm:noon' => $base . 'thundery.png',
            'storm:afternoon' => $base . 'thundery.png',
            'storm:sunset' => $base . 'lighting-and-thurnder.png',
            'storm:night' => $base . 'lighting-and-thurnder.png',
        ];
    }
}

if (!function_exists('hero_weather_slot_key')) {
    function hero_weather_slot_key(string $group, string $daypart): string
    {
        return $group . ':' . $daypart;
    }
}

if (!function_exists('hero_weather_is_valid_slot')) {
    function hero_weather_is_valid_slot(string $group, string $daypart): bool
    {
        return array_key_exists($group, hero_weather_groups())
            && array_key_exists($daypart, hero_weather_dayparts());
    }
}

if (!function_exists('hero_weather_get_overrides')) {
    /**
     * Decode admin-managed overrides into a `slot => relative path` map.
     *
     * @return array<string, string>
     */
    function hero_weather_get_overrides(): array
    {
        $raw = (string) get_setting('hero_weather_backgrounds', '');
        if ($raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        $clean = [];
        foreach ($decoded as $slot => $path) {
            if (!is_string($slot) || !is_string($path) || $path === '') {
                continue;
            }
            [$group, $daypart] = array_pad(explode(':', $slot, 2), 2, '');
            if (!hero_weather_is_valid_slot((string) $group, (string) $daypart)) {
                continue;
            }
            $clean[$slot] = $path;
        }

        return $clean;
    }
}

if (!function_exists('hero_weather_save_overrides')) {
    /**
     * Persist the override map. Empty/invalid entries are stripped first.
     *
     * @param array<string, string> $overrides
     */
    function hero_weather_save_overrides(array $overrides): bool
    {
        $clean = [];
        foreach ($overrides as $slot => $path) {
            if (!is_string($slot) || !is_string($path) || $path === '') {
                continue;
            }
            [$group, $daypart] = array_pad(explode(':', $slot, 2), 2, '');
            if (!hero_weather_is_valid_slot((string) $group, (string) $daypart)) {
                continue;
            }
            $clean[$slot] = $path;
        }

        $payload = empty($clean) ? '' : json_encode($clean, JSON_UNESCAPED_SLASHES);
        return (bool) update_setting('hero_weather_backgrounds', $payload, 'hero_weather');
    }
}

if (!function_exists('hero_weather_resolved_url')) {
    /**
     * Resolve a slot to a full public URL: admin override (if any) or default.
     */
    function hero_weather_resolved_url(string $group, string $daypart, array $overrides = null): string
    {
        $slot = hero_weather_slot_key($group, $daypart);
        $overrides = $overrides ?? hero_weather_get_overrides();
        $defaults = hero_weather_default_backgrounds();

        $relative = $overrides[$slot] ?? ($defaults[$slot] ?? '');
        if ($relative === '') {
            return '';
        }

        return rtrim(BASE_URL, '/') . '/' . ltrim($relative, '/');
    }
}

if (!function_exists('hero_weather_is_enabled')) {
    function hero_weather_is_enabled(): bool
    {
        return setting_truthy('hero_weather_enabled', true);
    }
}

if (!function_exists('hero_weather_delete_uploaded_file')) {
    /**
     * Best-effort cleanup of an uploaded background that lives inside the
     * managed uploads/ directory. Defaults shipped with the codebase are left
     * untouched even if a stale path slips through.
     */
    function hero_weather_delete_uploaded_file(string $relativePath): void
    {
        $relativePath = ltrim($relativePath, '/');
        if ($relativePath === '' || strpos($relativePath, 'assets/uploads/hero-weather-bg/') !== 0) {
            return;
        }

        $absolute = ROOT_PATH . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        $real = realpath($absolute);
        $allowedRoot = realpath(ROOT_PATH . 'assets' . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'hero-weather-bg');

        if ($real === false || $allowedRoot === false) {
            return;
        }
        if (strpos($real, $allowedRoot) !== 0) {
            return;
        }

        @unlink($real);
    }
}
