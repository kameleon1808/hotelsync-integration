<?php

/**
 * config/config.php
 *
 * Central configuration file for HotelSync Integration.
 * All credentials and environment settings are defined here.
 * Never hardcode credentials anywhere else in the codebase.
 */

// ─── Database ────────────────────────────────────────────────────────────────

define('DB_HOST',   '127.0.0.1');
define('DB_PORT',   3307);
define('DB_USER',   'root');
define('DB_PASS',   '');
define('DB_NAME',   'hotelsync');
define('DB_CHARSET','utf8mb4');

// ─── HotelSync API ───────────────────────────────────────────────────────────
// API_TOKEN   – partner/integration token issued by OTASync support
// API_USERNAME / API_PASSWORD – your OTASync account login credentials
// API_PROPERTY_ID – id_properties for your property (from login response)
// The session key (pkey) is obtained automatically via api_login().

define('API_BASE_URL',      'https://app.otasync.me/api');
define('API_TOKEN',         '775580f2b13be0215b5aee08a17c7aa892ece321');
define('API_USERNAME',      'marko00djokic@gmail.com');
define('API_PASSWORD',      'T3$t00U$er100E2ooo@');
define('API_PROPERTY_ID',   10586);    // id_properties integer from your OTASync account

// ─── Application ─────────────────────────────────────────────────────────────

define('APP_ENV',      'dev');   // 'dev' | 'prod'
define('LOG_FILE',     __DIR__ . '/../logs/app.log');
define('LOG_LEVEL',    'DEBUG'); // 'DEBUG' | 'INFO' | 'WARNING' | 'ERROR'
