<?php

/**
 * src/api.php
 *
 * HotelSync API client.
 * All communication with the remote API goes through api_request().
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/logger.php';

/**
 * Makes an HTTP request to the HotelSync API via cURL.
 *
 * @param string      $endpoint Relative endpoint path, e.g. "/rooms".
 * @param string      $method   HTTP method: GET | POST | PUT | PATCH | DELETE.
 * @param array|null  $payload  Request body data (will be JSON-encoded).
 * @param string|null $token    Bearer token; falls back to API_TOKEN from config.
 *
 * @throws RuntimeException On cURL error or non-2xx HTTP response.
 * @return array Decoded JSON response as an associative array.
 */
function api_request(
    string  $endpoint,
    string  $method  = 'GET',
    ?array  $payload = null,
    ?string $token   = null
): array {
    $token  = $token ?? API_TOKEN;
    $url    = rtrim(API_BASE_URL, '/') . '/' . ltrim($endpoint, '/');
    $method = strtoupper($method);

    log_event('INFO', "API {$method} {$url}");

    $ch = curl_init();

    $headers = [
        'Accept: application/json',
        'Content-Type: application/json',
        'Authorization: Bearer ' . $token,
    ];

    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_CUSTOMREQUEST  => $method,
    ]);

    if ($payload !== null && in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    }

    $response    = curl_exec($ch);
    $http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error  = curl_error($ch);
    curl_close($ch);

    if ($curl_error) {
        $msg = "API cURL error on {$method} {$url}: {$curl_error}";
        log_event('ERROR', $msg);
        throw new RuntimeException($msg);
    }

    log_event(
        $http_status >= 200 && $http_status < 300 ? 'SUCCESS' : 'ERROR',
        "API response HTTP {$http_status} from {$method} {$url}"
    );

    if ($http_status < 200 || $http_status >= 300) {
        $msg = "API returned HTTP {$http_status} for {$method} {$url}. Body: {$response}";
        log_event('ERROR', $msg);
        throw new RuntimeException($msg);
    }

    $decoded = json_decode($response, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        $msg = "API response JSON decode error: " . json_last_error_msg();
        log_event('ERROR', $msg);
        throw new RuntimeException($msg);
    }

    return $decoded;
}
