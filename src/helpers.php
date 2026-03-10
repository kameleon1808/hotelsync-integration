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
    // Transliterate non-ASCII characters
    $string = transliterator_transliterate('Any-Latin; Latin-ASCII; Lower()', $string)
              ?? strtolower($string);

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
    return hash('sha256', json_encode($data, JSON_UNESCAPED_UNICODE | JSON_SORT_KEYS));
}
