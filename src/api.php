<?php

/**
 * EPRS - Electre cover-image gateway.
 *
 * Given an `ean` query parameter (EAN-13 or ISBN-10), authenticate against the
 * Electre API, look up the matching notice and stream back its cover image as
 * image/jpeg.
 *
 * Configuration (environment variables):
 *   ELECTRE_USERNAME       required - Electre account username
 *   ELECTRE_PASSWORD       required - Electre account password
 *   ELECTRE_CLIENT_ID      optional - OAuth client id      (default: api-client)
 *   ELECTRE_CLIENT_SECRET  optional - OAuth client secret   (default: empty)
 *   ALLOWED_IMAGE_HOST     optional - host the cover image URL must belong to
 *                                     (default: electre-ng.com)
 */

const ELECTRE_TOKEN_URL  = 'https://login.electre-ng.com/auth/realms/electre/protocol/openid-connect/token';
const ELECTRE_NOTICE_URL = 'https://api.electre-ng.com/notices/ean/';
const ACCESS_TOKEN_TTL   = 3500; // seconds - refresh the cached token after this

// ---------------------------------------------------------------------------
// Configuration
// ---------------------------------------------------------------------------

$config = [
    'username'      => env_value('ELECTRE_USERNAME'),
    'password'      => env_value('ELECTRE_PASSWORD'),
    'client_id'     => env_value('ELECTRE_CLIENT_ID', 'api-client'),
    'client_secret' => env_value('ELECTRE_CLIENT_SECRET', ''), // public-client API
    'image_host'    => env_value('ALLOWED_IMAGE_HOST', 'electre-ng.com'),
];

if ($config['username'] === null || $config['password'] === null) {
    fail(500, 'ELECTRE_USERNAME / ELECTRE_PASSWORD environment variables are not set');
}

// ---------------------------------------------------------------------------
// Request handling
// ---------------------------------------------------------------------------

// CORS
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
    header('Access-Control-Max-Age: 86400');
    http_response_code(204);
    exit;
}

// Validate the EAN / ISBN parameter.
$ean = $_GET['ean'] ?? '';

if ($ean === '') {
    fail(400, 'No EAN parameter received');
}

if (!preg_match('/^[\d-]+$/', $ean)) {
    $safe = substr(preg_replace('/[^\x20-\x7E]/', '', $ean), 0, 64);
    fail(400, "Invalid EAN parameter received - $safe");
}

$ean = isbn2ean($ean);

// ---------------------------------------------------------------------------
// Fetch and stream the cover image
// ---------------------------------------------------------------------------

session_start();

$token = access_token($config);

if ($token === null) {
    fail(404, "Unable to obtain an Electre access token for ean: $ean");
}

$notice    = fetch_notice($ean, $token);
$image_url = $notice['notices'][0]['imagetteCouverture'] ?? '';

if (!image_url_allowed($image_url, $config['image_host'])) {
    fail(404, "No usable cover image URL for ean: $ean");
}

$image = file_get_contents($image_url);

if ($image === false) {
    fail(404, "Unable to fetch cover image for ean: $ean");
}

header('Cache-Control: public, max-age=3600');
header('Content-Type: image/jpeg');
echo $image;

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Read a configuration value from the environment ($_ENV or getenv()),
 * returning $default when it is unset or empty.
 */
function env_value(string $name, ?string $default = null): ?string
{
    $value = $_ENV[$name] ?? getenv($name);

    return ($value === false || $value === null || $value === '') ? $default : $value;
}

/**
 * Send an error response and stop, logging $message to the error log.
 */
function fail(int $status, string $message): void
{
    error_log("api.php: $message");
    http_response_code($status);
    exit;
}

/**
 * Return a valid Electre access token, refreshing the per-session cached copy
 * once it is older than ACCESS_TOKEN_TTL seconds. Returns null on auth failure.
 */
function access_token(array $config): ?string
{
    $now = time();
    $age = $now - ($_SESSION['token_time'] ?? 0);

    if (empty($_SESSION['access_token']) || $age > ACCESS_TOKEN_TTL) {
        $_SESSION['access_token'] = request_access_token($config);
        $_SESSION['token_time']   = $now;
    }

    return $_SESSION['access_token'];
}

/**
 * Perform the OAuth password-grant request against Electre.
 */
function request_access_token(array $config): ?string
{
    $body = http_build_query([
        'grant_type'    => 'password',
        'scope'         => 'roles',
        'username'      => $config['username'],
        'password'      => $config['password'],
        'client_id'     => $config['client_id'],
        'client_secret' => $config['client_secret'],
    ]);

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => ELECTRE_TOKEN_URL,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_RETURNTRANSFER => true,
    ]);
    $response = curl_exec($ch);
    $status   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($status !== 200) {
        error_log("api.php: Electre token request failed (http $status)");
        return null;
    }

    return json_decode((string) $response, true)['access_token'] ?? null;
}

/**
 * Fetch the notice JSON for an EAN. Returns the decoded array, or an empty
 * array when the request fails.
 */
function fetch_notice(string $ean, string $token): array
{
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => ELECTRE_NOTICE_URL . $ean,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => false,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $token],
    ]);
    $response = curl_exec($ch);
    $status   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error    = curl_error($ch);
    curl_close($ch);

    if ($response === false || $status !== 200) {
        error_log("api.php: Electre notice request failed for ean $ean (http $status) $error");
        return [];
    }

    $decoded = json_decode((string) $response, true);

    return is_array($decoded) ? $decoded : [];
}

/**
 * Whether $url is a safe cover-image URL: HTTPS, hosted on $allowedHost or a
 * sub-domain of it. Guards against SSRF / local file reads via a crafted or
 * unexpected upstream response.
 */
function image_url_allowed(string $url, string $allowedHost): bool
{
    if ($url === '') {
        return false;
    }

    $parts = parse_url($url);

    if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https') {
        return false;
    }

    $host = $parts['host'] ?? '';

    return $host === $allowedHost || str_ends_with($host, '.' . $allowedHost);
}

/**
 * Convert a 10-digit ISBN to a 13-digit EAN. Values that are not 10 digits
 * (after stripping separators) are returned unchanged.
 */
function isbn2ean($x)
{
    $x = str_replace("-", "", $x);
    $x = str_replace(" ", "", $x);
    if (strlen($x) < 10) $x = $x . "X";
    if (strlen($x) == 10) {
        $x = substr($x, 0, -1);
        $x = "978" . $x;
        $code = $x;
        $x = str_split($x);
        $i = 0;
        $i2 = 0;
        $r = 0;

        while ($i2 <= 11) {
            if ($i2 % 2 == 0) $p = "1";
            else $p = "3";
            $r += $x[$i] * $p;
            if ($x[$i] != "-") $i2++;
            $i++;
        }

        $q = floor($r / 10);
        $x = 10 - ($r - $q * 10);
        if ($x == "10") $x = "0";
        $x = $code . $x;
    }

    return $x;
}
