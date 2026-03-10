<?php

/**
 * src/api.php
 *
 * HotelSync (OTASync) API client.
 *
 * Authentication flow:
 *   1. Call api_login() to POST /user/auth/login → returns session pkey + properties list
 *   2. Pass the pkey as $key to api_request() for all subsequent calls
 *
 * Every request is HTTP POST with JSON body.
 * No Authorization header is used.
 *
 * Endpoint pattern:
 *   https://app.otasync.me/api/{resource}/{action}/{entity}
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/logger.php';

/**
 * Authenticates against the OTASync API and returns the session key (pkey)
 * together with the list of accessible properties.
 *
 * @param string|null $username  Override API_USERNAME (optional).
 * @param string|null $password  Override API_PASSWORD (optional).
 * @param string|null $token     Override API_TOKEN (optional).
 *
 * @throws RuntimeException On network error or failed login.
 * @return array ['pkey' => string, 'properties' => array, 'user' => array]
 */
function api_login(
    ?string $username = null,
    ?string $password = null,
    ?string $token    = null
): array {
    $url = rtrim(API_BASE_URL, '/') . '/user/auth/login';

    $body = json_encode([
        'token'    => $token    ?? API_TOKEN,
        'username' => $username ?? API_USERNAME,
        'password' => $password ?? API_PASSWORD,
        'remember' => 0,
    ]);

    log_event('INFO', 'API login attempt for user: ' . ($username ?? API_USERNAME));

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        // In dev the system CA bundle may be absent; skip peer verification outside prod
        CURLOPT_SSL_VERIFYPEER => (defined('APP_ENV') && APP_ENV === 'prod'),
        CURLOPT_SSL_VERIFYHOST => (defined('APP_ENV') && APP_ENV === 'prod') ? 2 : 0,
    ]);

    $response    = curl_exec($ch);
    $http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error  = curl_error($ch);
    curl_close($ch);

    if ($curl_error) {
        $msg = 'API login cURL error: ' . $curl_error;
        log_event('ERROR', $msg);
        throw new RuntimeException($msg);
    }

    if ($http_status !== 200) {
        $msg = "API login failed with HTTP {$http_status}. Body: {$response}";
        log_event('ERROR', $msg);
        throw new RuntimeException($msg);
    }

    $data = json_decode($response, true);

    if (json_last_error() !== JSON_ERROR_NONE || empty($data['pkey'])) {
        $msg = 'API login response invalid or missing pkey. Body: ' . substr($response, 0, 200);
        log_event('ERROR', $msg);
        throw new RuntimeException($msg);
    }

    log_event('SUCCESS', 'API login successful. Properties available: ' . count($data['properties'] ?? []));

    return [
        'pkey'       => $data['pkey'],
        'properties' => $data['properties'] ?? [],
        'user'       => $data,
    ];
}

/**
 * Makes an authenticated POST request to the HotelSync API via cURL.
 *
 * Credentials (token, key, id_properties) are automatically merged into
 * every request payload from config constants unless overridden.
 *
 * @param string      $endpoint    Relative endpoint path, e.g. "/room/data/rooms".
 * @param array       $payload     Additional body fields merged with auth credentials.
 * @param string|null $key         Session pkey obtained from api_login().
 * @param string|null $token       Override API_TOKEN (optional).
 * @param int|null    $property_id Override API_PROPERTY_ID (optional).
 *
 * @throws RuntimeException On cURL error or non-2xx HTTP response.
 * @return array Decoded JSON response as an associative array.
 */
function api_request(
    string  $endpoint,
    array   $payload      = [],
    ?string $key          = null,
    ?string $token        = null,
    ?int    $property_id  = null
): array {
    $url = rtrim(API_BASE_URL, '/') . '/' . ltrim($endpoint, '/');

    // Merge auth credentials into payload (caller fields take precedence)
    $body = array_merge([
        'token'         => $token       ?? API_TOKEN,
        'key'           => $key         ?? '',
        'id_properties' => $property_id ?? API_PROPERTY_ID,
    ], $payload);

    log_event('INFO', "API POST {$url}");

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($body),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Accept: application/json',
        ],
        // In dev the system CA bundle may be absent; skip peer verification outside prod
        CURLOPT_SSL_VERIFYPEER => (defined('APP_ENV') && APP_ENV === 'prod'),
        CURLOPT_SSL_VERIFYHOST => (defined('APP_ENV') && APP_ENV === 'prod') ? 2 : 0,
    ]);

    $response    = curl_exec($ch);
    $http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error  = curl_error($ch);
    curl_close($ch);

    if ($curl_error) {
        $msg = "API cURL error on POST {$url}: {$curl_error}";
        log_event('ERROR', $msg);
        throw new RuntimeException($msg);
    }

    $level = ($http_status >= 200 && $http_status < 300) ? 'SUCCESS' : 'ERROR';
    log_event($level, "API response HTTP {$http_status} from POST {$url}");

    if ($http_status < 200 || $http_status >= 300) {
        $msg = "API returned HTTP {$http_status} for POST {$url}. Body: {$response}";
        log_event('ERROR', $msg);
        throw new RuntimeException($msg);
    }

    // An empty body is a valid "no data" response from some endpoints
    if ($response === '' || $response === null) {
        log_event('INFO', "API returned empty body from POST {$url} – treating as empty array");
        return [];
    }

    $decoded = json_decode($response, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        $msg = 'API response JSON decode error: ' . json_last_error_msg();
        log_event('ERROR', $msg);
        throw new RuntimeException($msg);
    }

    return $decoded;
}
