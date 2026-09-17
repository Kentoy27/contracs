<?php

/**
 * PayMongo configuration for the admin plan-extension payment flow.
 *
 * Keys are read from environment variables when present so production
 * deployments can swap them without touching code. The defaults below are
 * TEST-MODE keys used for local development only — never commit live keys.
 */

function paymongo_secret_key(): string
{
    $env = getenv('PAYMONGO_SECRET_KEY');
    if (is_string($env) && trim($env) !== '') {
        return trim($env);
    }
    return 'sk_test_7nTf7qKwqtFx2TWDniwywSQK';
}

function paymongo_public_key(): string
{
    $env = getenv('PAYMONGO_PUBLIC_KEY');
    if (is_string($env) && trim($env) !== '') {
        return trim($env);
    }
    return 'pk_test_tRpYsLRt3HxjrzGqK7o8NY1z';
}

/**
 * Public base URL of the app (used to build success/cancel URLs that
 * PayMongo redirects the customer's browser back to).
 */
function paymongo_app_base_url(): string
{
    $env = getenv('PAYMONGO_APP_BASE_URL');
    if (is_string($env) && trim($env) !== '') {
        return rtrim(trim($env), '/');
    }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (string)($_SERVER['SERVER_PORT'] ?? '') === '443';
    $host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
    return ($https ? 'https' : 'http') . '://' . $host;
}

/**
 * Path to a CA bundle for TLS verification, or '' when none is available.
 * XAMPP ships an outdated curl-ca-bundle.crt, so prefer the refreshed
 * cacert.pem in php/extras/ssl (see .freebuff/run.md for how to update it).
 * Override with the PAYMONGO_CAINFO env var if needed.
 */
function paymongo_cainfo(): string
{
    $env = getenv('PAYMONGO_CAINFO');
    if (is_string($env) && trim($env) !== '' && is_file(trim($env))) {
        return trim($env);
    }
    $candidates = [
        __DIR__ . '/../php/extras/ssl/cacert.pem',
        'C:/xampp/php/extras/ssl/cacert.pem',
    ];
    foreach ($candidates as $c) {
        if (is_file($c) && is_readable($c)) {
            return $c;
        }
    }
    return '';
}

/**
 * Shared JSON request helper for the PayMongo API.
 * Returns ['code' => int, 'body' => array|null, 'raw' => string].
 * Throws RuntimeException on transport failure.
 */
function paymongo_api_request(string $method, string $url, ?array $payload = null): array
{
    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_USERPWD => paymongo_secret_key() . ':',
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
        CURLOPT_TIMEOUT => 20,
        CURLOPT_CONNECTTIMEOUT => 10,
    ];
    if ($payload !== null) {
        $opts[CURLOPT_POSTFIELDS] = json_encode($payload);
    }
    $ca = paymongo_cainfo();
    if ($ca !== '') {
        $opts[CURLOPT_CAINFO] = $ca;
    }
    curl_setopt_array($ch, $opts);
    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($resp === false) {
        throw new RuntimeException('Could not reach the payment gateway. Please try again.');
    }
    return [
        'code' => $code,
        'body' => json_decode((string)$resp, true),
        'raw' => (string)$resp,
    ];
}
