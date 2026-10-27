<?php

declare(strict_types=1);

define('APP_ROOT', __DIR__);
define('ROOT', dirname(__DIR__));
define('PUBLIC_ROOT', ROOT . '/public');

load_env(ROOT . '/.env');

$storageRoot = getenv('STORAGE_ROOT');
if (!is_string($storageRoot) || $storageRoot === '') {
    $storageRoot = ROOT . '/storage';
}
define('STORAGE_ROOT', rtrim($storageRoot, '/'));

ini_set('display_errors', '0');
ini_set('log_errors', '1');

require APP_ROOT . '/lib/Http.php';
require APP_ROOT . '/lib/Storage.php';
require APP_ROOT . '/lib/Validator.php';
require APP_ROOT . '/lib/Folio.php';
require APP_ROOT . '/lib/RateLimiter.php';
require APP_ROOT . '/lib/CrmClient.php';
require APP_ROOT . '/lib/MetaCapi.php';

function load_env(string $path): void
{
    if (!is_file($path)) {
        return;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        return;
    }

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        if (!str_contains($line, '=')) {
            continue;
        }

        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);
        if ($key === '' || !preg_match('/^[A-Z0-9_]+$/', $key)) {
            continue;
        }
        if (
            strlen($value) >= 2
            && (($value[0] === '"' && str_ends_with($value, '"'))
                || ($value[0] === "'" && str_ends_with($value, "'")))
        ) {
            $value = substr($value, 1, -1);
        }

        if (getenv($key) !== false) {
            continue;
        }

        putenv($key . '=' . $value);
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }
}

function env(string $key, string $default = ''): string
{
    $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
    if ($value === false || $value === null || $value === '') {
        return $default;
    }

    return (string) $value;
}

function app_now(): DateTimeImmutable
{
    return new DateTimeImmutable('now', new DateTimeZone('America/Mexico_City'));
}

/**
 * @return array<string, array<string, mixed>>
 */
function landings(): array
{
    static $data = null;
    if (is_array($data)) {
        return $data;
    }

    $raw = file_get_contents(APP_ROOT . '/landings.json');
    if ($raw === false) {
        throw new RuntimeException('landings');
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('landings');
    }

    $clean = [];
    foreach ($decoded as $slug => $landing) {
        if (is_string($slug) && is_array($landing)) {
            $clean[$slug] = $landing;
        }
    }

    $data = $clean;
    return $data;
}

/**
 * @return list<string>
 */
function allowed_origins(): array
{
    $parts = explode(',', env('ALLOWED_ORIGINS'));
    $origins = [];
    foreach ($parts as $part) {
        $part = rtrim(trim($part), '/');
        if ($part !== '') {
            $origins[] = $part;
        }
    }

    return $origins;
}

function request_origin(): string
{
    $origin = trim((string) ($_SERVER['HTTP_ORIGIN'] ?? ''));
    if ($origin !== '') {
        return rtrim($origin, '/');
    }

    $referer = trim((string) ($_SERVER['HTTP_REFERER'] ?? ''));
    if ($referer === '') {
        return '';
    }

    $parts = parse_url($referer);
    if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
        return '';
    }

    $built = $parts['scheme'] . '://' . $parts['host'];
    if (!empty($parts['port'])) {
        $built .= ':' . $parts['port'];
    }

    return $built;
}

function origin_allowed(): bool
{
    $origin = request_origin();
    if ($origin === '') {
        return false;
    }

    foreach (allowed_origins() as $allowed) {
        if (strcasecmp($allowed, $origin) === 0) {
            return true;
        }
    }

    return false;
}

function normalize_ip(string $ip): string
{
    $ip = trim($ip);
    if (stripos($ip, '::ffff:') === 0) {
        $mapped = substr($ip, 7);
        if (filter_var($mapped, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return $mapped;
        }
    }

    return $ip;
}

function ip_in_cidr(string $ip, string $cidr): bool
{
    $slash = strpos($cidr, '/');
    if ($slash === false) {
        return false;
    }

    $subnet = substr($cidr, 0, $slash);
    $bits = substr($cidr, $slash + 1);
    if ($bits === '' || preg_match('/^\d+$/', $bits) !== 1) {
        return false;
    }

    $prefix = (int) $bits;
    $ipBin = inet_pton($ip);
    $netBin = inet_pton($subnet);
    if ($ipBin === false || $netBin === false || strlen($ipBin) !== strlen($netBin)) {
        return false;
    }

    $max = strlen($ipBin) * 8;
    if ($prefix > $max) {
        return false;
    }

    $bytes = intdiv($prefix, 8);
    $remainder = $prefix % 8;
    if ($bytes > 0 && substr($ipBin, 0, $bytes) !== substr($netBin, 0, $bytes)) {
        return false;
    }
    if ($remainder === 0) {
        return true;
    }

    $mask = (0xFF << (8 - $remainder)) & 0xFF;

    return (ord($ipBin[$bytes]) & $mask) === (ord($netBin[$bytes]) & $mask);
}

function ip_is_cloudflare(string $ip): bool
{
    static $ranges = null;
    if ($ranges === null) {
        $loaded = require APP_ROOT . '/config/cloudflare_ips.php';
        $ranges = is_array($loaded) ? $loaded : [];
    }

    foreach ($ranges as $cidr) {
        if (is_string($cidr) && ip_in_cidr($ip, $cidr)) {
            return true;
        }
    }

    return false;
}

function client_ip(): string
{
    $remote = normalize_ip((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
    $remoteValid = filter_var($remote, FILTER_VALIDATE_IP) !== false;
    $cloudflare = normalize_ip(trim((string) ($_SERVER['HTTP_CF_CONNECTING_IP'] ?? '')));

    // CF-Connecting-IP solo cuenta si quien conecta es un borde de Cloudflare.
    // Si no, el encabezado se puede falsificar y saltarse el límite por IP.
    if (
        $remoteValid
        && ip_is_cloudflare($remote)
        && filter_var($cloudflare, FILTER_VALIDATE_IP) !== false
    ) {
        return $cloudflare;
    }

    if ($remoteValid) {
        return $remote;
    }

    return '0.0.0.0';
}

function client_ua(): string
{
    $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
    $ua = preg_replace('/[\x00-\x1F\x7F]/', '', $ua) ?? '';

    return mb_substr($ua, 0, 512);
}

function request_path(): string
{
    $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
    $path = parse_url($uri, PHP_URL_PATH);
    if (!is_string($path) || $path === '') {
        return '/';
    }

    $decoded = rawurldecode($path);
    if (str_contains($decoded, "\0")) {
        return '/';
    }

    return $decoded;
}

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function send_security_headers(): void
{
    header_remove('X-Powered-By');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('X-Frame-Options: DENY');
    header(
        "Content-Security-Policy: default-src 'self'; "
        . "script-src 'self' https://connect.facebook.net; "
        . "style-src 'self' 'unsafe-inline'; "
        . "img-src 'self' data: https://facebook.com https://*.facebook.com https://connect.facebook.net; "
        . "connect-src 'self' https://facebook.com https://*.facebook.com https://connect.facebook.net; "
        . "font-src 'self'; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'"
    );
}

/**
 * @param array<string, mixed> $payload
 */
function json_out(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function app_log(string $message): void
{
    $message = preg_replace('/\d{8,}/', '[redacted]', $message) ?? $message;
    $message = str_replace(["\r", "\n"], ' ', $message);
    $dir = STORAGE_ROOT . '/logs';
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        return;
    }

    $line = app_now()->format('c') . ' ' . $message . "\n";
    file_put_contents($dir . '/app.log', $line, FILE_APPEND | LOCK_EX);
}

/**
 * @param array<string, mixed> $landing
 */
function whatsapp_url(array $landing, string $folio, string $programa): string
{
    $number = preg_replace('/\D/', '', (string) ($landing['whatsapp'] ?? '')) ?? '';
    $template = (string) ($landing['mensaje'] ?? 'Hola, quiero información (folio {folio})');
    $message = strtr($template, [
        '{folio}' => $folio,
        '{programa}' => $programa,
    ]);

    return 'https://wa.me/' . $number . '?text=' . rawurlencode($message);
}
