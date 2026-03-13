<?php

/**
 * scripts/update_reservation.php
 *
 * Phase 3 – Reservation Update and Cancellation.
 *
 * Fetches a single reservation from the OTASync API by ID, compares it with
 * the locally stored record, and applies any changes.  Cancellations are
 * handled as soft-deletes (status field updated, record kept in DB).
 * Every change is recorded in the audit_log table.
 *
 * Confirmed API endpoint (verified against Postman collection 2026-03-11):
 *   POST /reservation/data/reservation
 *   Body: {token, key, id_properties, id_reservations}
 *   Response: flat reservation object (not wrapped in array)
 *   Room field: rooms[].id_room_types
 *   Rate plan field: id_pricing_plans (scalar on root object)
 *
 * Usage:
 *   php scripts/update_reservation.php --reservation_id=XXXX
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../src/logger.php';
require_once __DIR__ . '/../src/db.php';
require_once __DIR__ . '/../src/api.php';
require_once __DIR__ . '/../src/helpers.php';

// ─── STEP 1 – Argument parsing ─────────────────────────────────────────────

/**
 * Prints CLI usage instructions and exits with code 1.
 *
 * @return never
 */
function usage(): void
{
    echo PHP_EOL;
    echo 'Usage:  php scripts/update_reservation.php --reservation_id=XXXX' . PHP_EOL;
    echo PHP_EOL;
    echo 'Options:' . PHP_EOL;
    echo '  --reservation_id   OTASync reservation ID (numeric)' . PHP_EOL;
    echo PHP_EOL;
    echo 'Example:' . PHP_EOL;
    echo '  php scripts/update_reservation.php --reservation_id=606308' . PHP_EOL;
    echo PHP_EOL;
    exit(1);
}

$opts           = getopt('', ['reservation_id:']);
$reservation_id = $opts['reservation_id'] ?? null;

if ($reservation_id === null) {
    echo '[ERROR] --reservation_id is required.' . PHP_EOL;
    usage();
}

if (!ctype_digit((string)$reservation_id)) {
    echo "[ERROR] --reservation_id must be numeric, got: {$reservation_id}" . PHP_EOL;
    usage();
}

$reservation_id = (string)$reservation_id;

// ─── Boot ──────────────────────────────────────────────────────────────────

echo PHP_EOL . '=== BridgeOne Reservation Update ===' . PHP_EOL;
echo "Reservation ID: {$reservation_id}" . PHP_EOL . PHP_EOL;
log_event('INFO', "update_reservation: Starting update for hs_reservation_id={$reservation_id}");

// ─── STEP 2 – Authenticate + fetch from API ────────────────────────────────

try {
    $login = api_login();
    $pkey  = $login['pkey'];
    echo 'Login: OK' . PHP_EOL;
    log_event('SUCCESS', 'update_reservation: API login successful');
} catch (RuntimeException $e) {
    echo '[ERROR] Login failed: ' . $e->getMessage() . PHP_EOL;
    log_event('ERROR', 'update_reservation: Login failed: ' . $e->getMessage());
    exit(1);
}

/**
 * Fetches a single reservation from the OTASync API by its ID.
 *
 * Returns null when the API signals the reservation does not exist
 * (empty response, missing id_reservations key, or is_deleted = 1 with
 * no status information).
 *
 * @param string $hs_id      OTASync reservation ID.
 * @param string $pkey       Session key from api_login().
 * @return array|null  Decoded reservation object, or null if not found.
 */
function fetch_single_reservation(string $hs_id, string $pkey): ?array
{
    try {
        $resp = api_request(
            '/reservation/data/reservation',
            ['id_reservations' => $hs_id],
            $pkey
        );
    } catch (RuntimeException $e) {
        // HTTP 400 "Invalid ID" means the reservation does not exist in this
        // property.  Treat it the same as a 404 / empty response.
        if (strpos($e->getMessage(), 'HTTP 400') !== false
            || stripos($e->getMessage(), 'Invalid ID') !== false
        ) {
            return null;
        }
        // Any other error (network, 500, etc.) re-throw so the caller can log it
        throw $e;
    }

    // Empty response → not found
    if (empty($resp)) {
        return null;
    }

    // The endpoint returns a flat object.  If the primary key is absent the
    // reservation does not exist in this property.
    if (!isset($resp['id_reservations'])) {
        return null;
    }

    return $resp;
}

echo "Fetching reservation {$reservation_id} from API..." . PHP_EOL;
log_event('INFO', "update_reservation: Fetching hs_reservation_id={$reservation_id} from API");

try {
    $api_res = fetch_single_reservation($reservation_id, $pkey);
} catch (RuntimeException $e) {
    echo '[ERROR] API request failed: ' . $e->getMessage() . PHP_EOL;
    log_event('ERROR', "update_reservation: API request failed for {$reservation_id}: " . $e->getMessage());
    exit(1);
}

if ($api_res === null) {
    echo "[ERROR] Reservation {$reservation_id} not found in OTASync." . PHP_EOL;
    log_event('ERROR', "update_reservation: Reservation not found in OTASync – hs_reservation_id={$reservation_id}");
    exit(1);
}

log_event('INFO', "update_reservation: Fetched hs_reservation_id={$reservation_id} from API");
echo "API fetch: OK" . PHP_EOL;

// ─── STEP 2b – Database connection ────────────────────────────────────────

try {
    $conn = get_db_connection();
} catch (RuntimeException $e) {
    echo '[ERROR] Database connection failed: ' . $e->getMessage() . PHP_EOL;
    log_event('ERROR', 'update_reservation: DB connection failed: ' . $e->getMessage());
    exit(1);
}

// ─── STEP 3 – Check local existence ───────────────────────────────────────

$local_stmt  = execute_query(
    $conn,
    'SELECT id, payload_hash, status, raw_data FROM reservations WHERE hs_reservation_id = ?',
    [$reservation_id],
    's'
);
$local_row = $local_stmt->get_result()->fetch_assoc();
$local_stmt->close();

if ($local_row === null) {
    echo "[WARNING] Reservation {$reservation_id} not found in local DB." . PHP_EOL;
    echo "          Run sync_reservations.php first to import it." . PHP_EOL;
    log_event(
        'WARNING',
        "update_reservation: hs_reservation_id={$reservation_id} not in local DB – run sync_reservations first"
    );
    close_db_connection($conn);
    exit(0);
}

$local_id      = (int)$local_row['id'];
$old_hash      = (string)$local_row['payload_hash'];
$old_status    = (string)$local_row['status'];
$old_raw_data  = $local_row['raw_data'];   // stored JSON string

echo "Local record found (id={$local_id}, status={$old_status})" . PHP_EOL;

// ─── STEP 4 – Compare payload hash ────────────────────────────────────────

$new_hash = hash_payload($api_res);

if ($new_hash === $old_hash) {
    echo PHP_EOL . "No changes detected for reservation {$reservation_id}." . PHP_EOL;
    log_event('INFO', "update_reservation: No changes detected for hs_reservation_id={$reservation_id}");
    close_db_connection($conn);
    echo PHP_EOL . '=== Update complete (no changes) ===' . PHP_EOL;
    exit(0);
}

echo "Changes detected – updating local record..." . PHP_EOL;
log_event('INFO', "update_reservation: Changes detected for hs_reservation_id={$reservation_id}, applying update");

// ─── STEP 5 – Determine new values ────────────────────────────────────────

// Extract core fields using the same field-name fallback logic as sync_reservations
$new_guest_name = trim(
    ($api_res['first_name'] ?? $api_res['firstname'] ?? '')
    . ' '
    . ($api_res['last_name'] ?? $api_res['lastname'] ?? '')
);
if ($new_guest_name === '' || $new_guest_name === ' ') {
    $new_guest_name = $api_res['guest_name'] ?? $api_res['client_name'] ?? $api_res['name'] ?? 'Unknown Guest';
}
if (trim($new_guest_name) === '') {
    $new_guest_name = 'Unknown Guest';
}

$new_arrival   = (string)($api_res['date_arrival']   ?? $api_res['arrival_date']   ?? $api_res['date_from'] ?? '');
$new_departure = (string)($api_res['date_departure']  ?? $api_res['departure_date'] ?? $api_res['date_to']   ?? '');
$new_status    = strtolower((string)($api_res['status'] ?? 'new'));

// Normalise cancellation spelling variants
if (in_array($new_status, ['canceled', 'cancellation', 'cancelled'], true)) {
    $new_status = 'cancelled';
}

// Also treat is_deleted = 1 as cancelled (API soft-delete flag)
if (!empty($api_res['is_deleted']) && (int)$api_res['is_deleted'] === 1) {
    $new_status = 'cancelled';
}

$new_raw_json = json_encode($api_res, JSON_UNESCAPED_UNICODE);

// Determine event type for audit log
$event_type = ($new_status === 'cancelled') ? 'cancelled' : 'updated';

// ─── STEP 5a – Update reservations table ──────────────────────────────────

try {
    execute_query(
        $conn,
        'UPDATE reservations
            SET guest_name    = ?,
                arrival_date  = ?,
                departure_date = ?,
                status        = ?,
                payload_hash  = ?,
                raw_data      = ?,
                updated_at    = NOW()
          WHERE id = ?',
        [$new_guest_name, $new_arrival, $new_departure, $new_status, $new_hash, $new_raw_json, $local_id],
        'ssssssi'
    )->close();

    log_event(
        'SUCCESS',
        "update_reservation: reservations table updated – local_id={$local_id} status={$new_status}",
        $local_id,
        $reservation_id
    );
} catch (RuntimeException $e) {
    echo '[ERROR] Failed to update reservation: ' . $e->getMessage() . PHP_EOL;
    log_event('ERROR', "update_reservation: Failed to update reservation {$reservation_id}: " . $e->getMessage());
    close_db_connection($conn);
    exit(1);
}

// ─── STEP 5b – Build lookup maps for rooms and rate plans ─────────────────

/**
 * Returns a map of hs_room_id → local room id from the rooms table.
 *
 * @param mysqli $conn Active DB connection.
 * @return array<string, int>
 */
function build_room_map(mysqli $conn): array
{
    $stmt   = execute_query($conn, 'SELECT id, hs_room_id FROM rooms', [], '');
    $result = $stmt->get_result();
    $map    = [];
    while ($row = $result->fetch_assoc()) {
        $map[(string)$row['hs_room_id']] = (int)$row['id'];
    }
    $stmt->close();
    return $map;
}

/**
 * Returns a map of hs_rate_plan_id → local rate_plan id from the rate_plans table.
 *
 * @param mysqli $conn Active DB connection.
 * @return array<string, int>
 */
function build_rate_plan_map(mysqli $conn): array
{
    $stmt   = execute_query($conn, 'SELECT id, hs_rate_plan_id FROM rate_plans', [], '');
    $result = $stmt->get_result();
    $map    = [];
    while ($row = $result->fetch_assoc()) {
        $map[(string)$row['hs_rate_plan_id']] = (int)$row['id'];
    }
    $stmt->close();
    return $map;
}

$room_map      = build_room_map($conn);
$rate_plan_map = build_rate_plan_map($conn);

// ─── STEP 5c – Rebuild reservation_rooms ──────────────────────────────────

// Collect new room set from API response
$new_rooms = [];
if (isset($api_res['rooms']) && is_array($api_res['rooms'])) {
    foreach ($api_res['rooms'] as $room_entry) {
        $hs_room_id = (string)(
            $room_entry['id_room_types'] ?? $room_entry['id_room'] ?? $room_entry['room_id'] ?? ''
        );
        $quantity = (int)($room_entry['quantity'] ?? 1);

        if ($hs_room_id === '' || !isset($room_map[$hs_room_id])) {
            if ($hs_room_id !== '') {
                log_event(
                    'WARNING',
                    "update_reservation: Room hs_room_id={$hs_room_id} not in local catalog – skipping"
                );
            }
            continue;
        }

        $local_room_id = $room_map[$hs_room_id];
        // Use local_room_id as key to deduplicate multiple room rows with same type
        $new_rooms[$local_room_id] = $quantity;
    }
} elseif (isset($api_res['id_room_types'])) {
    // Scalar fallback
    $hs_room_id = (string)$api_res['id_room_types'];
    if (isset($room_map[$hs_room_id])) {
        $new_rooms[$room_map[$hs_room_id]] = (int)($api_res['quantity'] ?? 1);
    }
}

// Fetch existing rooms from DB
$existing_rooms_stmt = execute_query(
    $conn,
    'SELECT room_id, quantity FROM reservation_rooms WHERE reservation_id = ?',
    [$local_id],
    'i'
);
$existing_rooms_result = $existing_rooms_stmt->get_result();
$existing_rooms        = [];
while ($r = $existing_rooms_result->fetch_assoc()) {
    $existing_rooms[(int)$r['room_id']] = (int)$r['quantity'];
}
$existing_rooms_stmt->close();

if ($new_rooms !== $existing_rooms) {
    // Full replace: delete all, then insert new set (idempotent)
    execute_query(
        $conn,
        'DELETE FROM reservation_rooms WHERE reservation_id = ?',
        [$local_id],
        'i'
    )->close();

    foreach ($new_rooms as $local_room_id => $quantity) {
        execute_query(
            $conn,
            'INSERT INTO reservation_rooms (reservation_id, room_id, quantity) VALUES (?, ?, ?)',
            [$local_id, $local_room_id, $quantity],
            'iii'
        )->close();
    }

    log_event(
        'INFO',
        "update_reservation: reservation_rooms rebuilt for local_id={$local_id} – " . count($new_rooms) . ' room(s)'
    );
}

// ─── STEP 5d – Rebuild reservation_rate_plans ─────────────────────────────

// Collect new rate plan set from API response
$new_rate_plans = [];
if (isset($api_res['id_pricing_plans'])
    && $api_res['id_pricing_plans'] !== ''
    && $api_res['id_pricing_plans'] !== '0'
    && (int)$api_res['id_pricing_plans'] !== 0
) {
    $hs_rp_id = (string)$api_res['id_pricing_plans'];
    if (isset($rate_plan_map[$hs_rp_id])) {
        $new_rate_plans[$rate_plan_map[$hs_rp_id]] = true;
    } else {
        log_event('WARNING', "update_reservation: Rate plan hs_rp_id={$hs_rp_id} not in local catalog – skipping");
    }
} elseif (isset($api_res['pricing_plans']) && is_array($api_res['pricing_plans'])) {
    foreach ($api_res['pricing_plans'] as $rp_entry) {
        $hs_rp_id = (string)($rp_entry['id_pricing_plans'] ?? '');
        if ($hs_rp_id !== '' && isset($rate_plan_map[$hs_rp_id])) {
            $new_rate_plans[$rate_plan_map[$hs_rp_id]] = true;
        }
    }
}

// Fetch existing rate plans from DB
$existing_rp_stmt = execute_query(
    $conn,
    'SELECT rate_plan_id FROM reservation_rate_plans WHERE reservation_id = ?',
    [$local_id],
    'i'
);
$existing_rp_result = $existing_rp_stmt->get_result();
$existing_rate_plans = [];
while ($r = $existing_rp_result->fetch_assoc()) {
    $existing_rate_plans[(int)$r['rate_plan_id']] = true;
}
$existing_rp_stmt->close();

if ($new_rate_plans !== $existing_rate_plans) {
    // Full replace: delete all, then insert new set (idempotent)
    execute_query(
        $conn,
        'DELETE FROM reservation_rate_plans WHERE reservation_id = ?',
        [$local_id],
        'i'
    )->close();

    foreach (array_keys($new_rate_plans) as $local_rp_id) {
        execute_query(
            $conn,
            'INSERT INTO reservation_rate_plans (reservation_id, rate_plan_id) VALUES (?, ?)',
            [$local_id, $local_rp_id],
            'ii'
        )->close();
    }

    log_event(
        'INFO',
        "update_reservation: reservation_rate_plans rebuilt for local_id={$local_id} – " . count($new_rate_plans) . ' plan(s)'
    );
}

// ─── STEP 6 – Write audit_log ──────────────────────────────────────────────

try {
    execute_query(
        $conn,
        'INSERT INTO audit_log (reservation_id, event_type, old_data, new_data)
         VALUES (?, ?, ?, ?)',
        [$local_id, $event_type, $old_raw_data, $new_raw_json],
        'isss'
    )->close();

    log_event(
        'SUCCESS',
        "update_reservation: audit_log written – local_id={$local_id} event={$event_type}",
        $local_id,
        $reservation_id
    );
} catch (RuntimeException $e) {
    // Audit log failure is non-fatal; log it but continue
    echo '[WARNING] audit_log insert failed: ' . $e->getMessage() . PHP_EOL;
    log_event('ERROR', "update_reservation: audit_log insert failed for {$reservation_id}: " . $e->getMessage());
}

// ─── STEP 7 – Cancellation output ─────────────────────────────────────────

close_db_connection($conn);

echo PHP_EOL;

if ($event_type === 'cancelled') {
    echo "Reservation {$reservation_id} marked as cancelled." . PHP_EOL;
    echo "Record retained in DB (soft-delete). Audit log entry written." . PHP_EOL;
    log_event(
        'SUCCESS',
        "update_reservation: Reservation {$reservation_id} cancelled (soft-delete)",
        $local_id,
        $reservation_id
    );
} else {
    echo "Reservation {$reservation_id} updated successfully." . PHP_EOL;
    echo "Guest: {$new_guest_name} | Arrival: {$new_arrival} | Status: {$new_status}" . PHP_EOL;
    log_event(
        'SUCCESS',
        "update_reservation: Reservation {$reservation_id} updated – guest={$new_guest_name} status={$new_status}",
        $local_id,
        $reservation_id
    );
}

echo PHP_EOL . '=== Reservation update complete ===' . PHP_EOL;
