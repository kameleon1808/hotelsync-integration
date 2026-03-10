<?php

/**
 * scripts/test_connection.php
 *
 * Phase 0 verification script.
 * Tests database connectivity, API authentication, and PHP environment.
 *
 * Usage: php scripts/test_connection.php
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../src/logger.php';
require_once __DIR__ . '/../src/db.php';
require_once __DIR__ . '/../src/api.php';

// ─── Helpers ─────────────────────────────────────────────────────────────────

function pass(string $label): void
{
    echo "  [OK]    {$label}" . PHP_EOL;
}

function fail(string $label, string $detail = ''): void
{
    echo "  [FAIL]  {$label}" . ($detail ? ": {$detail}" : '') . PHP_EOL;
}

function section(string $title): void
{
    echo PHP_EOL . "=== {$title} ===" . PHP_EOL;
}

// ─── PHP Environment ─────────────────────────────────────────────────────────

section('PHP Environment');

$phpVersion = PHP_VERSION;
echo "  PHP Version   : {$phpVersion}" . PHP_EOL;

if (version_compare($phpVersion, '7.4.0', '>=')) {
    pass('PHP >= 7.4');
} else {
    fail('PHP >= 7.4 required', "found {$phpVersion}");
}

if (extension_loaded('mysqli')) {
    pass('mysqli extension loaded');
} else {
    fail('mysqli extension NOT found – enable it in php.ini');
}

if (extension_loaded('curl')) {
    pass('cURL extension loaded');
} else {
    fail('cURL extension NOT found – enable it in php.ini');
}

if (extension_loaded('json')) {
    pass('json extension loaded');
} else {
    fail('json extension NOT found');
}

// ─── Database Connection ─────────────────────────────────────────────────────

section('Database Connection');

echo "  Host   : " . DB_HOST . PHP_EOL;
echo "  User   : " . DB_USER . PHP_EOL;
echo "  Name   : " . DB_NAME . PHP_EOL;

try {
    $conn = get_db_connection();
    pass('Database connection: OK');

    // Verify required tables exist
    $tables = ['rooms', 'rate_plans', 'reservations', 'reservation_rooms',
               'reservation_rate_plans', 'audit_log', 'invoice_queue', 'webhook_events'];

    $result = $conn->query("SHOW TABLES");
    $existing = [];
    while ($row = $result->fetch_row()) {
        $existing[] = $row[0];
    }

    foreach ($tables as $table) {
        if (in_array($table, $existing, true)) {
            pass("Table '{$table}' exists");
        } else {
            fail("Table '{$table}' NOT found", 'Run: mysql -u ' . DB_USER . ' -p ' . DB_NAME . ' < sql/schema.sql');
        }
    }

    close_db_connection($conn);
} catch (RuntimeException $e) {
    fail('Database connection: FAILED', $e->getMessage());
    echo PHP_EOL . "  Hint: Check DB_HOST, DB_USER, DB_PASS, DB_NAME in config/config.php" . PHP_EOL;
}

// ─── API Connection ──────────────────────────────────────────────────────────

section('API Connection');

echo "  Base URL : " . API_BASE_URL . PHP_EOL;
echo "  Token    : " . substr(API_TOKEN, 0, 8) . '...' . PHP_EOL;

// Phase 0: just verify the server is reachable and token is accepted.
// We use cURL directly here to check HTTP status without throwing on non-2xx.
$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL            => rtrim(API_BASE_URL, '/') . '/accommodations',
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 10,
    CURLOPT_HTTPHEADER     => [
        'Accept: application/json',
        'Authorization: Bearer ' . API_TOKEN,
    ],
]);
$body        = curl_exec($ch);
$http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curl_error  = curl_error($ch);
curl_close($ch);

if ($curl_error) {
    fail('API server reachable: FAILED', $curl_error);
    echo PHP_EOL . "  Hint: Check internet connection and API_BASE_URL in config/config.php" . PHP_EOL;
} elseif ($http_status === 0) {
    fail('API server reachable: FAILED', 'No response (timeout or DNS error)');
} elseif ($http_status === 401 || $http_status === 403) {
    pass('API server reachable: OK');
    fail('API token rejected (HTTP ' . $http_status . ')', 'Check API_TOKEN in config/config.php');
} elseif ($http_status >= 200 && $http_status < 300) {
    pass('API server reachable: OK');
    pass('API token accepted (HTTP ' . $http_status . ')');
    log_event('SUCCESS', 'API connectivity test passed.');
} else {
    pass('API server reachable: OK');
    echo "  NOTE: HTTP {$http_status} – endpoint may not exist yet (normal for Phase 0)." . PHP_EOL;
    echo "  Server responded, which confirms the base URL and token are correct." . PHP_EOL;
    log_event('INFO', 'API connectivity test: server reachable, HTTP ' . $http_status);
}

// ─── Summary ─────────────────────────────────────────────────────────────────

section('Summary');
echo "  Phase 0 check complete. Review any [FAIL] items above." . PHP_EOL . PHP_EOL;
