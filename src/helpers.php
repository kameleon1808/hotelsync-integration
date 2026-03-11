<?php

/**
 * src/helpers.php
 *
 * Utility / helper functions used across the integration.
 * No external dependencies – pure PHP.
 */

/**
 * Generates a deterministic external ID for a room.
 *
 * Format: HS-{ID}-{slug}
 * Example: HS-42-deluxe-double
 *
 * @param int    $room_id   Internal room ID.
 * @param string $room_name Human-readable room name.
 *
 * @return string
 */
function generate_room_external_id(int $room_id, string $room_name): string
{
    return 'HS-' . $room_id . '-' . slugify($room_name);
}

/**
 * Generates a deterministic external ID for a rate plan.
 *
 * Format: RP-{ID}-{meal_plan}
 * Example: RP-7-BB
 *
 * @param int    $rate_plan_id Internal rate plan ID.
 * @param string $meal_plan    Meal plan code (e.g. BB, HB, AI).
 *
 * @return string
 */
function generate_rate_plan_external_id(int $rate_plan_id, string $meal_plan): string
{
    return 'RP-' . $rate_plan_id . '-' . strtoupper(trim($meal_plan));
}

/**
 * Generates a composite lock ID used to prevent duplicate reservation imports.
 *
 * Format: LOCK-{reservation_id}-{arrival_date}
 * Example: LOCK-1001-2025-06-15
 *
 * @param int    $reservation_id Internal reservation ID.
 * @param string $arrival_date   Arrival date in YYYY-MM-DD format.
 *
 * @return string
 */
function generate_lock_id(int $reservation_id, string $arrival_date): string
{
    return 'LOCK-' . $reservation_id . '-' . $arrival_date;
}

/**
 * Generates a human-readable, zero-padded invoice number.
 *
 * Format: HS-INV-YYYY-000001
 * Example: HS-INV-2025-000042
 *
 * @param int $year     4-digit year.
 * @param int $sequence Sequential invoice counter.
 *
 * @return string
 */
function generate_invoice_number(int $year, int $sequence): string
{
    return 'HS-INV-' . $year . '-' . str_pad($sequence, 6, '0', STR_PAD_LEFT);
}

/**
 * Converts any string to a URL-friendly lowercase hyphenated slug.
 *
 * Example: "Deluxe Double Room!" → "deluxe-double-room"
 *
 * @param string $string Input string.
 *
 * @return string
 */
function slugify(string $string): string
{
    // Transliterate non-ASCII characters (requires intl extension; falls back to iconv or strip)
    if (function_exists('transliterator_transliterate')) {
        $string = transliterator_transliterate('Any-Latin; Latin-ASCII; Lower()', $string)
                  ?? strtolower($string);
    } elseif (function_exists('iconv')) {
        $string = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $string) ?: $string;
    }

    $string = strtolower($string);
    $string = preg_replace('/[^a-z0-9\s-]/', '', $string);   // Remove non-alphanumeric
    $string = preg_replace('/[\s-]+/', '-', $string);          // Collapse spaces/dashes
    return trim($string, '-');
}

/**
 * Returns a SHA-256 hex digest of the JSON-encoded data array.
 * Used to detect whether a reservation payload has changed.
 *
 * @param array $data Associative array to hash.
 *
 * @return string 64-character lowercase hex string.
 */
function hash_payload(array $data): string
{
    // JSON_SORT_KEYS (64) may be absent in some PHP builds; define it as a fallback
    if (!defined('JSON_SORT_KEYS')) {
        define('JSON_SORT_KEYS', 64);
    }
    return hash('sha256', json_encode($data, JSON_UNESCAPED_UNICODE | JSON_SORT_KEYS));
}

/**
 * Simulates sending an invoice with a 70 % success rate and up to 5 retries.
 *
 * On each attempt a pseudo-random check (rand(1, 10) <= 7) determines success.
 * Success:   sets invoice_queue.status = 'sent'.
 * All fail:  sets invoice_queue.status = 'failed'.
 * After each failed attempt retry_count is incremented in the DB.
 *
 * Requires src/db.php (execute_query) and src/logger.php (log_event)
 * to be loaded by the calling script before this function is invoked.
 *
 * @param mysqli $conn           Active database connection.
 * @param int    $invoice_id     Local invoice_queue.id.
 * @param string $invoice_number Human-readable invoice number (for log messages).
 * @param int    $max_attempts   Maximum send attempts before marking failed (default 5).
 * @param int    $retry_sleep_ms Milliseconds to sleep between failed attempts (default 200).
 *
 * @return string Final status: 'sent' | 'failed'
 */
function attempt_invoice_send(
    mysqli $conn,
    int    $invoice_id,
    string $invoice_number,
    int    $max_attempts   = 5,
    int    $retry_sleep_ms = 200
): string {
    $attempt = 0;

    while ($attempt < $max_attempts) {
        $attempt++;

        // 70 % success rate: values 1–7 out of range 1–10
        $success = (rand(1, 10) <= 7);

        if ($success) {
            execute_query(
                $conn,
                "UPDATE invoice_queue SET status = 'sent', updated_at = NOW() WHERE id = ?",
                [$invoice_id],
                'i'
            )->close();

            log_event(
                'SUCCESS',
                "attempt_invoice_send: {$invoice_number} sent on attempt {$attempt}"
            );
            echo "  Attempt {$attempt}: SUCCESS – invoice sent." . PHP_EOL;
            return 'sent';
        }

        // Increment retry counter in DB
        execute_query(
            $conn,
            'UPDATE invoice_queue SET retry_count = retry_count + 1, updated_at = NOW() WHERE id = ?',
            [$invoice_id],
            'i'
        )->close();

        log_event(
            'WARNING',
            "attempt_invoice_send: {$invoice_number} attempt {$attempt} failed"
        );
        echo "  Attempt {$attempt}: FAILED – retrying..." . PHP_EOL;

        if ($attempt < $max_attempts) {
            usleep($retry_sleep_ms * 1000);
        }
    }

    // All attempts exhausted – mark as failed
    execute_query(
        $conn,
        "UPDATE invoice_queue SET status = 'failed', updated_at = NOW() WHERE id = ?",
        [$invoice_id],
        'i'
    )->close();

    log_event(
        'ERROR',
        "attempt_invoice_send: {$invoice_number} failed after {$max_attempts} attempts – marked 'failed'"
    );
    echo "  All {$max_attempts} attempts failed – invoice marked as 'failed'." . PHP_EOL;
    return 'failed';
}
