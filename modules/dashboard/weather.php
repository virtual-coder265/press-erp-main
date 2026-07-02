<?php
/**
 * Dashboard weather proxy
 *
 * Thin auth-gated wrapper around Open-Meteo (https://open-meteo.com).
 * Provides two actions used by the dashboard hero weather widget:
 *   - geocode:  city/place autocomplete -> { results: [...] }
 *   - forecast: current conditions + next 4 hourly steps for a lat/lon
 *
 * Responses are cached on disk (logs/cache/weather/) to keep us well under the
 * free Open-Meteo rate limits even on busy dashboards.
 */

require_once __DIR__ . '/../../config/app.php';
checkAuth();
require_once __DIR__ . '/../../includes/hero_weather_helper.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=60');

$action = isset($_GET['action']) ? (string) $_GET['action'] : '';

function weather_respond(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function weather_cache_dir(): string
{
    $dir = ROOT_PATH . 'logs' . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'weather';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    return $dir;
}

function weather_cache_get(string $key, int $ttlSeconds): ?array
{
    $file = weather_cache_dir() . DIRECTORY_SEPARATOR . sha1($key) . '.json';
    if (!is_file($file)) {
        return null;
    }
    $age = time() - (int) @filemtime($file);
    if ($age > $ttlSeconds) {
        return null;
    }
    $raw = @file_get_contents($file);
    if (!is_string($raw) || $raw === '') {
        return null;
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : null;
}

function weather_cache_put(string $key, array $payload): void
{
    $file = weather_cache_dir() . DIRECTORY_SEPARATOR . sha1($key) . '.json';
    @file_put_contents(
        $file,
        json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        LOCK_EX
    );
}

function weather_http_get(string $url): ?array
{
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => 5,
            'header' => "Accept: application/json\r\nUser-Agent: PressERP-Weather/1.0\r\n",
            'ignore_errors' => true,
        ],
    ]);

    $response = @file_get_contents($url, false, $context);
    if ($response === false || $response === '') {
        return null;
    }

    $decoded = json_decode($response, true);
    return is_array($decoded) ? $decoded : null;
}

function weather_round_coord($value, int $precision = 2): float
{
    return round((float) $value, $precision);
}

if ($action === 'geocode') {
    $query = trim((string) ($_GET['q'] ?? ''));
    if ($query === '' || mb_strlen($query) < 2) {
        weather_respond(400, ['error' => 'Query must be at least 2 characters.']);
    }

    $cacheKey = 'geocode:' . mb_strtolower($query);
    $cached = weather_cache_get($cacheKey, 60 * 60 * 24 * 30); // 30 days
    if ($cached !== null) {
        weather_respond(200, $cached);
    }

    $url = 'https://geocoding-api.open-meteo.com/v1/search?'
        . http_build_query([
            'name' => $query,
            'count' => 5,
            'language' => 'en',
            'format' => 'json',
        ]);

    $remote = weather_http_get($url);
    if ($remote === null) {
        weather_respond(502, ['error' => 'Geocoding service unavailable.']);
    }

    $results = [];
    foreach ((array) ($remote['results'] ?? []) as $row) {
        if (!isset($row['latitude'], $row['longitude'], $row['name'])) {
            continue;
        }
        $results[] = [
            'name' => (string) $row['name'],
            'admin1' => isset($row['admin1']) ? (string) $row['admin1'] : '',
            'country' => isset($row['country']) ? (string) $row['country'] : '',
            'country_code' => isset($row['country_code']) ? (string) $row['country_code'] : '',
            'latitude' => (float) $row['latitude'],
            'longitude' => (float) $row['longitude'],
            'timezone' => isset($row['timezone']) ? (string) $row['timezone'] : 'auto',
        ];
    }

    $payload = ['results' => $results];
    weather_cache_put($cacheKey, $payload);
    weather_respond(200, $payload);
}

if ($action === 'reverse') {
    $lat = isset($_GET['lat']) ? (float) $_GET['lat'] : null;
    $lon = isset($_GET['lon']) ? (float) $_GET['lon'] : null;

    if ($lat === null || $lon === null || $lat < -90 || $lat > 90 || $lon < -180 || $lon > 180) {
        weather_respond(400, ['error' => 'Valid lat and lon are required.']);
    }

    // Round to ~1km buckets so users hitting the same locality share a cache slot.
    $latKey = weather_round_coord($lat, 2);
    $lonKey = weather_round_coord($lon, 2);
    $cacheKey = 'reverse:' . $latKey . ':' . $lonKey;

    $cached = weather_cache_get($cacheKey, 60 * 60 * 24 * 30); // 30 days
    if ($cached !== null) {
        weather_respond(200, $cached);
    }

    // Nominatim usage policy requires an identifiable User-Agent (already set in
    // weather_http_get) and modest request rates. We cache aggressively above so
    // a normal session triggers at most one upstream call per locality.
    $url = 'https://nominatim.openstreetmap.org/reverse?'
        . http_build_query([
            'lat' => $lat,
            'lon' => $lon,
            'format' => 'jsonv2',
            'zoom' => 10,
            'addressdetails' => 1,
            'accept-language' => 'en',
        ]);

    $remote = weather_http_get($url);
    if ($remote === null) {
        weather_respond(502, ['error' => 'Reverse geocoding service unavailable.']);
    }

    $address = (array) ($remote['address'] ?? []);
    $name = '';
    foreach (['city', 'town', 'village', 'municipality', 'hamlet', 'suburb', 'county'] as $field) {
        if (!empty($address[$field])) {
            $name = (string) $address[$field];
            break;
        }
    }
    if ($name === '' && !empty($remote['name'])) {
        $name = (string) $remote['name'];
    }

    $payload = [
        'name' => $name,
        'admin1' => isset($address['state']) ? (string) $address['state']
            : (isset($address['region']) ? (string) $address['region'] : ''),
        'country' => isset($address['country']) ? (string) $address['country'] : '',
        'country_code' => isset($address['country_code']) ? strtoupper((string) $address['country_code']) : '',
        'latitude' => (float) ($remote['lat'] ?? $lat),
        'longitude' => (float) ($remote['lon'] ?? $lon),
        'display_name' => isset($remote['display_name']) ? (string) $remote['display_name'] : '',
    ];

    weather_cache_put($cacheKey, $payload);
    weather_respond(200, $payload);
}

if ($action === 'forecast') {
    $lat = isset($_GET['lat']) ? (float) $_GET['lat'] : null;
    $lon = isset($_GET['lon']) ? (float) $_GET['lon'] : null;
    $tz = isset($_GET['tz']) && $_GET['tz'] !== '' ? (string) $_GET['tz'] : 'auto';

    if ($lat === null || $lon === null || $lat < -90 || $lat > 90 || $lon < -180 || $lon > 180) {
        weather_respond(400, ['error' => 'Valid lat and lon are required.']);
    }

    // Round coordinates so users near each other share cache buckets (~1km granularity).
    // The trailing version suffix lets us invalidate old payloads when the response
    // shape changes (e.g. the day v2 added per-hour is_day).
    $latKey = weather_round_coord($lat);
    $lonKey = weather_round_coord($lon);
    $cacheKey = 'forecast:v2:' . $latKey . ':' . $lonKey . ':' . $tz;

    $cached = weather_cache_get($cacheKey, 10 * 60); // 10 minutes
    if ($cached !== null) {
        weather_respond(200, $cached);
    }

    $url = 'https://api.open-meteo.com/v1/forecast?'
        . http_build_query([
            'latitude' => $lat,
            'longitude' => $lon,
            'current' => 'temperature_2m,relative_humidity_2m,apparent_temperature,is_day,precipitation,weather_code,wind_speed_10m',
            'hourly' => 'temperature_2m,weather_code,precipitation_probability,is_day',
            'forecast_days' => 2,
            'timezone' => $tz,
            'wind_speed_unit' => 'kmh',
            'temperature_unit' => 'celsius',
        ]);

    $remote = weather_http_get($url);
    if ($remote === null || !isset($remote['current'])) {
        weather_respond(502, ['error' => 'Weather service unavailable.']);
    }

    $current = $remote['current'] ?? [];
    $hourly = $remote['hourly'] ?? [];
    $hourlyTimes = (array) ($hourly['time'] ?? []);
    $hourlyTemps = (array) ($hourly['temperature_2m'] ?? []);
    $hourlyCodes = (array) ($hourly['weather_code'] ?? []);
    $hourlyPop = (array) ($hourly['precipitation_probability'] ?? []);
    $hourlyIsDay = (array) ($hourly['is_day'] ?? []);

    $currentTime = (string) ($current['time'] ?? '');
    $currentHourStamp = $currentTime !== '' ? substr($currentTime, 0, 13) : '';

    $next4 = [];
    $startIndex = -1;
    if ($currentHourStamp !== '') {
        foreach ($hourlyTimes as $i => $stamp) {
            if (substr((string) $stamp, 0, 13) > $currentHourStamp) {
                $startIndex = $i;
                break;
            }
        }
    }
    if ($startIndex === -1 && !empty($hourlyTimes)) {
        $startIndex = 1;
    }

    for ($i = $startIndex; $i < $startIndex + 4 && $i >= 0 && $i < count($hourlyTimes); $i++) {
        $next4[] = [
            'time' => (string) $hourlyTimes[$i],
            'temperature' => isset($hourlyTemps[$i]) ? (float) $hourlyTemps[$i] : null,
            'weather_code' => isset($hourlyCodes[$i]) ? (int) $hourlyCodes[$i] : null,
            'precipitation_probability' => isset($hourlyPop[$i]) ? (int) $hourlyPop[$i] : null,
            'is_day' => isset($hourlyIsDay[$i]) ? (int) $hourlyIsDay[$i] : 1,
        ];
    }

    $payload = [
        'fetched_at' => date('c'),
        'timezone' => (string) ($remote['timezone'] ?? $tz),
        'timezone_abbreviation' => (string) ($remote['timezone_abbreviation'] ?? ''),
        'utc_offset_seconds' => (int) ($remote['utc_offset_seconds'] ?? 0),
        'latitude' => (float) ($remote['latitude'] ?? $lat),
        'longitude' => (float) ($remote['longitude'] ?? $lon),
        'current' => [
            'time' => $currentTime,
            'temperature' => isset($current['temperature_2m']) ? (float) $current['temperature_2m'] : null,
            'apparent_temperature' => isset($current['apparent_temperature']) ? (float) $current['apparent_temperature'] : null,
            'humidity' => isset($current['relative_humidity_2m']) ? (int) $current['relative_humidity_2m'] : null,
            'wind_speed' => isset($current['wind_speed_10m']) ? (float) $current['wind_speed_10m'] : null,
            'precipitation' => isset($current['precipitation']) ? (float) $current['precipitation'] : null,
            'weather_code' => isset($current['weather_code']) ? (int) $current['weather_code'] : null,
            'is_day' => isset($current['is_day']) ? (int) $current['is_day'] : 1,
        ],
        'hourly_next4' => $next4,
    ];

    weather_cache_put($cacheKey, $payload);
    weather_respond(200, $payload);
}

if ($action === 'hero_config') {
    // Admin-managed mapping of "weather_group:daypart" => absolute URL.
    // The dashboard JS uses this to override the bundled defaults when the
    // feature is enabled. When disabled, the JS falls back to the static
    // gradient and skips the background swap entirely.
    $enabled = hero_weather_is_enabled();
    $overrides = $enabled ? hero_weather_get_overrides() : [];

    $resolved = [];
    if ($enabled) {
        foreach ($overrides as $slot => $relative) {
            $resolved[$slot] = rtrim(BASE_URL, '/') . '/' . ltrim($relative, '/');
        }
    }

    weather_respond(200, [
        'enabled' => $enabled,
        'backgrounds' => (object) $resolved,
    ]);
}

weather_respond(400, ['error' => 'Unknown action. Expected geocode, reverse, forecast, or hero_config.']);
