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

echo "  Base URL  : " . API_BASE_URL . PHP_EOL;
echo "  Token     : " . substr(API_TOKEN, 0, 8) . '...' . PHP_EOL;
echo "  Username  : " . API_USERNAME . PHP_EOL;
echo "  Property  : " . API_PROPERTY_ID . PHP_EOL;

if (API_USERNAME === 'YOUR_EMAIL_HERE' || API_PASSWORD === 'YOUR_PASSWORD_HERE') {
    fail('API credentials not configured', 'Set API_USERNAME and API_PASSWORD in config/config.php');
} else {
    try {
        $login = api_login();
        pass('API login: OK');
        echo "  Session key : " . substr($login['pkey'], 0, 8) . '...' . PHP_EOL;

        // List accessible properties
        echo "  Properties  :" . PHP_EOL;
        foreach ($login['properties'] as $prop) {
            $marker = ((int)$prop['id_properties'] === API_PROPERTY_ID) ? ' <-- active' : '';
            echo "    id={$prop['id_properties']} name={$prop['name']}{$marker}" . PHP_EOL;
        }

        // Verify the configured property ID is accessible
        $ids = array_column($login['properties'], 'id_properties');
        if (!in_array((string)API_PROPERTY_ID, $ids, true)) {
            fail('API_PROPERTY_ID not found in your properties list', 'Check config/config.php');
        } else {
            pass('API_PROPERTY_ID valid: OK');
            log_event('SUCCESS', 'API connectivity and login test passed.');
        }
    } catch (RuntimeException $e) {
        fail('API login: FAILED', $e->getMessage());
        echo PHP_EOL . "  Hint: Check API_USERNAME, API_PASSWORD, API_TOKEN in config/config.php" . PHP_EOL;
    }
}

// ─── Summary ─────────────────────────────────────────────────────────────────

section('Summary');
echo "  Phase 0 check complete. Review any [FAIL] items above." . PHP_EOL . PHP_EOL;
