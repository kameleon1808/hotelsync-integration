<?php

/**
 * src/db.php
 *
 * Database layer using mysqli.
 * Provides connection management and a prepared-statement query helper.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/logger.php';

/**
 * Opens and returns a mysqli database connection.
 *
 * @throws RuntimeException If the connection cannot be established.
 * @return mysqli
 */
function get_db_connection(): mysqli
{
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

    if ($conn->connect_error) {
        log_event('ERROR', 'Database connection failed: ' . $conn->connect_error);
        throw new RuntimeException('Database connection failed: ' . $conn->connect_error);
    }

    $conn->set_charset(DB_CHARSET);

    log_event('INFO', 'Database connection established.');
    return $conn;
}

/**
 * Executes a prepared statement and returns the result.
 *
 * @param mysqli      $conn   Active database connection.
 * @param string      $sql    SQL query with ? placeholders.
 * @param array       $params Array of parameter values to bind.
 * @param string      $types  Bind types string (e.g. "ssi" for string, string, int).
 *
 * @throws RuntimeException On prepare or execute failure.
 * @return mysqli_stmt The executed statement (caller can use get_result() or affected_rows).
 */
function execute_query(mysqli $conn, string $sql, array $params = [], string $types = ''): mysqli_stmt
{
    $stmt = $conn->prepare($sql);

    if ($stmt === false) {
        $msg = 'Query prepare failed: ' . $conn->error . ' | SQL: ' . $sql;
        log_event('ERROR', $msg);
        throw new RuntimeException($msg);
    }

    if (!empty($params)) {
        if (empty($types)) {
            // Auto-detect types when not supplied
            $types = '';
            foreach ($params as $p) {
                if (is_int($p))    { $types .= 'i'; }
                elseif (is_float($p)) { $types .= 'd'; }
                else                  { $types .= 's'; }
            }
        }

        $stmt->bind_param($types, ...$params);
    }

    if (!$stmt->execute()) {
        $msg = 'Query execution failed: ' . $stmt->error . ' | SQL: ' . $sql;
        log_event('ERROR', $msg);
        throw new RuntimeException($msg);
    }

    return $stmt;
}

/**
 * Closes an open mysqli connection.
 *
 * @param mysqli $conn The connection to close.
 * @return void
 */
function close_db_connection(mysqli $conn): void
{
    $conn->close();
    log_event('INFO', 'Database connection closed.');
}
