<?php

/**
 * src/logger.php
 *
 * Simple file-based logger.
 * Writes structured log entries to the path defined in config/config.php.
 *
 * Log format:
 *   [YYYY-MM-DD HH:MM:SS] [TYPE] description | res_id=X | ext_id=Y
 */

if (!defined('LOG_FILE')) {
    require_once __DIR__ . '/../config/config.php';
}

/**
 * Writes a log entry to the application log file.
 *
 * @param string      $type           Log level: INFO | SUCCESS | ERROR | WARNING.
 * @param string      $description    Human-readable description of the event.
 * @param int|null    $reservation_id Internal reservation ID (optional).
 * @param string|null $external_id    External / OTASync ID (optional).
 *
 * @return void
 */
function log_event(
    string $type,
    string $description,
    ?int   $reservation_id = null,
    ?string $external_id   = null
): void {
    $allowed = ['INFO', 'SUCCESS', 'ERROR', 'WARNING', 'DEBUG'];
    $type    = in_array(strtoupper($type), $allowed, true) ? strtoupper($type) : 'INFO';

    $timestamp = date('Y-m-d H:i:s');
    $line      = "[{$timestamp}] [{$type}] {$description}";

    if ($reservation_id !== null) {
        $line .= " | res_id={$reservation_id}";
    }

    if ($external_id !== null) {
        $line .= " | ext_id={$external_id}";
    }

    $line .= PHP_EOL;

    $log_dir = dirname(LOG_FILE);
    if (!is_dir($log_dir)) {
        mkdir($log_dir, 0775, true);
    }

    file_put_contents(LOG_FILE, $line, FILE_APPEND | LOCK_EX);
}
