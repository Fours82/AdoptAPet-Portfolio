<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/db.php';
require_once dirname(__DIR__, 2) . '/config/application.php';

const STRAY_GEOCODE_CACHE_SECONDS = 2592000;
const STRAY_GEOCODE_MINIMUM_INTERVAL = 1.0;

function strayGeocodeRespond(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload);
    exit;
}

function strayGeocodeCacheDirectory(): string
{
    $projectPath = dirname(__DIR__, 2);
    return rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR
        . 'adoptapet-stray-geocode-' . substr(hash('sha256', $projectPath), 0, 16);
}

function strayGeocodeReadCache(string $path): ?array
{
    if (!is_file($path)) {
        return null;
    }
    $content = @file_get_contents($path);
    if (!is_string($content)) {
        return null;
    }
    $cached = json_decode($content, true);
    if (!is_array($cached)
        || !is_int($cached['saved_at'] ?? null)
        || time() - $cached['saved_at'] > STRAY_GEOCODE_CACHE_SECONDS
        || !is_array($cached['results'] ?? null)) {
        return null;
    }
    return $cached['results'];
}

function strayGeocodeWriteCache(string $path, array $results): void
{
    $temporaryPath = @tempnam(dirname($path), 'stray-geocode-');
    if (!is_string($temporaryPath)) {
        return;
    }
    $content = json_encode(['saved_at' => time(), 'results' => $results]);
    if (!is_string($content) || @file_put_contents($temporaryPath, $content, LOCK_EX) === false) {
        @unlink($temporaryPath);
        return;
    }
    if (is_file($path) && !@unlink($path)) {
        @unlink($temporaryPath);
        return;
    }
    if (!@rename($temporaryPath, $path)) {
        @unlink($temporaryPath);
    }
}

function strayGeocodeEndpoint(): string
{
    $endpoint = trim((string) (getenv('ADOPTAPET_GEOCODER_URL') ?: 'https://nominatim.openstreetmap.org/search'));
    return strayGeocodeValidateEndpoint($endpoint);
}

function strayGeocodeReverseEndpoint(): string
{
    // Kept separate so the existing forward-search URL remains compatible.
    $endpoint = trim((string) (getenv('ADOPTAPET_GEOCODER_REVERSE_URL') ?: 'https://nominatim.openstreetmap.org/reverse'));
    return strayGeocodeValidateEndpoint($endpoint);
}

function strayGeocodeValidateEndpoint(string $endpoint): string
{
    $parts = parse_url($endpoint);
    if (!is_array($parts)
        || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
        || !isset($parts['host'])
        || isset($parts['user'])
        || isset($parts['pass'])
        || isset($parts['fragment'])) {
        throw new RuntimeException('The geocoding service URL is invalid.');
    }
    return $endpoint;
}

function strayGeocodeFetch(string $query): ?array
{
    $applicationUrl = str_replace(["\r", "\n"], '', adoptAPetApplicationBaseUrl());
    $url = strayGeocodeEndpoint() . '?' . http_build_query([
        'q' => $query,
        'format' => 'jsonv2',
        'addressdetails' => 1,
        'limit' => 5,
        'countrycodes' => 'my',
    ], '', '&', PHP_QUERY_RFC3986);
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => 8,
            'ignore_errors' => true,
            'follow_location' => 0,
            'header' => "Accept: application/json\r\n"
                . "User-Agent: AdoptAPet-Stray-Report-Geocoder/1.0 ({$applicationUrl})\r\n"
                . "Referer: {$applicationUrl}\r\n",
        ],
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
        ],
    ]);
    $response = @file_get_contents($url, false, $context);
    $statusLine = is_array($http_response_header ?? null) ? ($http_response_header[0] ?? '') : '';
    if (!is_string($response) || !preg_match('/\s200(?:\s|$)/', $statusLine)) {
        return null;
    }

    $decoded = json_decode($response, true);
    if (!is_array($decoded) || !array_is_list($decoded)) {
        return null;
    }
    $results = [];
    foreach ($decoded as $item) {
        if (!is_array($item)
            || !is_string($item['display_name'] ?? null)
            || trim($item['display_name']) === ''
            || !is_numeric($item['lat'] ?? null)
            || !is_numeric($item['lon'] ?? null)) {
            continue;
        }
        $latitude = (float) $item['lat'];
        $longitude = (float) $item['lon'];
        if (!is_finite($latitude) || !is_finite($longitude)
            || $latitude < -90 || $latitude > 90
            || $longitude < -180 || $longitude > 180) {
            continue;
        }
        $results[] = [
            'display_name' => $item['display_name'],
            'latitude' => $latitude,
            'longitude' => $longitude,
        ];
        if (count($results) >= 5) {
            break;
        }
    }
    return $results;
}

function strayGeocodeLookup(string $query): array
{
    $cacheDirectory = strayGeocodeCacheDirectory();
    if (!is_dir($cacheDirectory)
        && !@mkdir($cacheDirectory, 0700, true)
        && !is_dir($cacheDirectory)) {
        return ['status' => 503, 'message' => 'Location search is temporarily unavailable.'];
    }

    $cacheKey = hash('sha256', strtolower($query));
    $cachePath = $cacheDirectory . DIRECTORY_SEPARATOR . $cacheKey . '.json';
    $cached = strayGeocodeReadCache($cachePath);
    if ($cached !== null) {
        return ['status' => 200, 'results' => $cached];
    }

    $lock = @fopen($cacheDirectory . DIRECTORY_SEPARATOR . 'nominatim-rate.lock', 'c+');
    if (!is_resource($lock)) {
        return ['status' => 503, 'message' => 'Location search is temporarily unavailable.'];
    }
    try {
        if (!flock($lock, LOCK_EX)) {
            return ['status' => 503, 'message' => 'Location search is temporarily unavailable.'];
        }
        $cached = strayGeocodeReadCache($cachePath);
        if ($cached !== null) {
            return ['status' => 200, 'results' => $cached];
        }

        rewind($lock);
        $lastRequestAt = (float) trim((string) stream_get_contents($lock));
        $now = microtime(true);
        if ($lastRequestAt > 0 && $now - $lastRequestAt < STRAY_GEOCODE_MINIMUM_INTERVAL) {
            return ['status' => 429, 'message' => 'Please wait a moment before searching again.'];
        }

        ftruncate($lock, 0);
        rewind($lock);
        fwrite($lock, sprintf('%.6F', $now));
        fflush($lock);

        $results = strayGeocodeFetch($query);
        if ($results === null) {
            return ['status' => 502, 'message' => 'Location search is temporarily unavailable. Try again later or place a marker manually.'];
        }
        strayGeocodeWriteCache($cachePath, $results);
        return ['status' => 200, 'results' => $results];
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function strayGeocodeReverseFetch(float $latitude, float $longitude): ?array
{
    $applicationUrl = str_replace(["\r", "\n"], '', adoptAPetApplicationBaseUrl());
    $url = strayGeocodeReverseEndpoint() . '?' . http_build_query([
        'lat' => $latitude,
        'lon' => $longitude,
        'format' => 'jsonv2',
        'addressdetails' => 1,
    ], '', '&', PHP_QUERY_RFC3986);
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => 8,
            'ignore_errors' => true,
            'follow_location' => 0,
            'header' => "Accept: application/json\r\n"
                . "User-Agent: AdoptAPet-Stray-Report-Geocoder/1.0 ({$applicationUrl})\r\n"
                . "Referer: {$applicationUrl}\r\n",
        ],
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
        ],
    ]);
    $response = @file_get_contents($url, false, $context);
    $statusLine = is_array($http_response_header ?? null) ? ($http_response_header[0] ?? '') : '';
    if (!is_string($response) || !preg_match('/\s(\d{3})(?:\s|$)/', $statusLine, $matches)) {
        return null;
    }

    $status = (int) $matches[1];
    if ($status === 429) {
        return ['rate_limited' => true];
    }
    $decoded = json_decode($response, true);
    if (!is_array($decoded)) {
        return null;
    }
    if (($status === 200 || $status === 404)
        && is_string($decoded['error'] ?? null)
        && trim($decoded['error']) !== '') {
        return ['address_suggestion' => null];
    }
    if ($status !== 200) {
        return null;
    }
    $address = is_string($decoded['display_name'] ?? null)
        ? trim($decoded['display_name'])
        : '';
    return ['address_suggestion' => $address === '' ? null : $address];
}

function strayGeocodeReverseLookup(float $latitude, float $longitude): array
{
    $cacheDirectory = strayGeocodeCacheDirectory();
    if (!is_dir($cacheDirectory)
        && !@mkdir($cacheDirectory, 0700, true)
        && !is_dir($cacheDirectory)) {
        return ['status' => 503, 'message' => 'Address lookup is temporarily unavailable.'];
    }

    $coordinateKey = number_format($latitude, 7, '.', '') . '|' . number_format($longitude, 7, '.', '');
    $cacheKey = hash('sha256', 'reverse|' . $coordinateKey);
    $cachePath = $cacheDirectory . DIRECTORY_SEPARATOR . $cacheKey . '.json';
    $cached = strayGeocodeReadCache($cachePath);
    if ($cached !== null) {
        return ['status' => 200, 'address_suggestion' => $cached['address_suggestion'] ?? null];
    }

    $lock = @fopen($cacheDirectory . DIRECTORY_SEPARATOR . 'nominatim-rate.lock', 'c+');
    if (!is_resource($lock)) {
        return ['status' => 503, 'message' => 'Address lookup is temporarily unavailable.'];
    }
    try {
        if (!flock($lock, LOCK_EX)) {
            return ['status' => 503, 'message' => 'Address lookup is temporarily unavailable.'];
        }
        $cached = strayGeocodeReadCache($cachePath);
        if ($cached !== null) {
            return ['status' => 200, 'address_suggestion' => $cached['address_suggestion'] ?? null];
        }

        rewind($lock);
        $lastRequestAt = (float) trim((string) stream_get_contents($lock));
        $now = microtime(true);
        if ($lastRequestAt > 0 && $now - $lastRequestAt < STRAY_GEOCODE_MINIMUM_INTERVAL) {
            return ['status' => 429, 'message' => 'Please wait a moment before requesting an address suggestion.'];
        }

        ftruncate($lock, 0);
        rewind($lock);
        fwrite($lock, sprintf('%.6F', $now));
        fflush($lock);

        $result = strayGeocodeReverseFetch($latitude, $longitude);
        if (($result['rate_limited'] ?? false) === true) {
            return ['status' => 429, 'message' => 'The location service is rate-limited. Please wait and try again.'];
        }
        if ($result === null) {
            return ['status' => 502, 'message' => 'Address lookup is temporarily unavailable. You can enter the address manually.'];
        }
        $address = $result['address_suggestion'] ?? null;
        strayGeocodeWriteCache($cachePath, ['address_suggestion' => $address]);
        return ['status' => 200, 'address_suggestion' => $address];
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function strayGeocodeCoordinates(mixed $latitudeRaw, mixed $longitudeRaw): ?array
{
    if (!is_numeric($latitudeRaw) || !is_numeric($longitudeRaw)) {
        return null;
    }
    $latitude = (float) $latitudeRaw;
    $longitude = (float) $longitudeRaw;
    if (!is_finite($latitude) || !is_finite($longitude)
        || $latitude < -90 || $latitude > 90
        || $longitude < -180 || $longitude > 180) {
        return null;
    }
    return [$latitude, $longitude];
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    strayGeocodeRespond(405, ['success' => false, 'message' => 'Method not allowed.']);
}

try {
    $userId = authenticatedUserId();
    if ($userId === null) {
        strayGeocodeRespond(401, ['success' => false, 'message' => 'Authentication is required to search for a report location.']);
    }
    requireValidCsrfToken();

    $pdo = getDatabaseConnection();
    $reporter = $pdo->prepare(
        'SELECT u.id
         FROM users u
         WHERE u.id = :id
           AND u.role = :role
           AND u.account_status = :status
           AND u.deleted_at IS NULL
           AND EXISTS (
               SELECT 1 FROM email_verifications ev
               WHERE ev.user_id = u.id AND ev.verified_at IS NOT NULL
           )
         LIMIT 1'
    );
    $reporter->execute([
        'id' => $userId,
        'role' => 'registered_user',
        'status' => 'active',
    ]);
    if (!$reporter->fetch()) {
        strayGeocodeRespond(403, ['success' => false, 'message' => 'An active, verified registered-user account is required.']);
    }

    $body = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($body)) {
        strayGeocodeRespond(400, ['success' => false, 'message' => 'Enter a valid location search.']);
    }
    $action = $body['action'] ?? 'search';
    if ($action === 'reverse') {
        if (array_diff(array_keys($body), ['action', 'latitude', 'longitude']) !== []) {
            strayGeocodeRespond(400, ['success' => false, 'message' => 'Enter valid map coordinates.']);
        }
        $coordinates = strayGeocodeCoordinates($body['latitude'] ?? null, $body['longitude'] ?? null);
        if ($coordinates === null) {
            strayGeocodeRespond(422, ['success' => false, 'message' => 'Latitude and longitude must be valid coordinates.']);
        }
        $result = strayGeocodeReverseLookup($coordinates[0], $coordinates[1]);
        if ($result['status'] === 429) {
            header('Retry-After: 1');
        }
        if ($result['status'] !== 200) {
            strayGeocodeRespond($result['status'], ['success' => false, 'message' => $result['message']]);
        }
        strayGeocodeRespond(200, [
            'success' => true,
            'address_suggestion' => $result['address_suggestion'],
        ]);
    }

    if ($action !== 'search'
        || array_diff(array_keys($body), ['action', 'query']) !== []
        || !is_string($body['query'] ?? null)) {
        strayGeocodeRespond(400, ['success' => false, 'message' => 'Enter a valid location search.']);
    }
    $query = trim($body['query']);
    $length = function_exists('mb_strlen') ? mb_strlen($query, 'UTF-8') : strlen($query);
    if ($query === '' || $length > 255 || preg_match('//u', $query) !== 1 || preg_match('/[\x00-\x1F\x7F]/u', $query)) {
        strayGeocodeRespond(422, ['success' => false, 'message' => 'Enter a location of up to 255 characters.']);
    }

    $result = strayGeocodeLookup($query);
    if ($result['status'] === 429) {
        header('Retry-After: 1');
    }
    if ($result['status'] !== 200) {
        strayGeocodeRespond($result['status'], ['success' => false, 'message' => $result['message']]);
    }
    strayGeocodeRespond(200, ['success' => true, 'results' => $result['results']]);
} catch (Throwable $exception) {
    strayGeocodeRespond(503, ['success' => false, 'message' => 'Location search is temporarily unavailable.']);
}
