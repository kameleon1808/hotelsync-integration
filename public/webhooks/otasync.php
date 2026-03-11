<?php

/**
 * public/webhooks/otasync.php
 *
 * Phase 5 – Webhook Endpoint.
 *
 * Receives OTASync webhook POST requests, validates them, prevents duplicate
 * processing via payload hash idempotency, and routes each event to the
 * appropriate handler.
 *
 * Supported event types:
 *   reservation.created   – inserts new reservation if not already present
 *   reservation.updated   – updates existing reservation and writes audit_log
 *   reservation.cancelled – soft-cancels reservation and writes audit_log
 *
 * Start local server:
 *   php -S localhost:8000 -t public
 *
 * Endpoint URL (local):
 *   POST http://localhost:8000/webhooks/otasync.php
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../src/logger.php';
require_once __DIR__ . '/../../src/db.php';
require_once __DIR__ . '/../../src/helpers.php';

// ─── Output helper ────────────────────────────────────────────────────────────

/**
 * Sends a JSON response with the given HTTP status code and exits.
 *
 * @param int   $http_code HTTP status code.
 * @param array $data      Response payload to JSON-encode.
 * @return never
 */
function json_response(int $http_code, array $data): void
{
    http_response_code($http_code);
    header('Content-Type: application/json');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

// ─── STEP 1 – Request validation ─────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    log_event('WARNING', 'webhook: Rejected non-POST request – method=' . $_SERVER['REQUEST_METHOD']);
    json_response(405, ['status' => 'error', 'message' => 'Method Not Allowed. POST required.']);
}

$raw_body = (string)file_get_contents('php://input');

$payload = json_decode($raw_body, true);

if (json_last_error() !== JSON_ERROR_NONE || !is_array($payload)) {
    log_event('WARNING', 'webhook: Rejected request with invalid JSON body');
    json_response(400, ['status' => 'error', 'message' => 'Invalid JSON payload.']);
}

// ─── STEP 2 – Payload hash ────────────────────────────────────────────────────

$payload_hash = hash('sha256', $raw_body);

// ─── DB connection ────────────────────────────────────────────────────────────

try {
    $conn = get_db_connection();
} catch (RuntimeException $e) {
    log_event('ERROR', 'webhook: DB connection failed: ' . $e->getMessage());
    json_response(500, ['status' => 'error', 'message' => 'Database unavailable.']);
}

// ─── STEP 3 – Idempotency check ───────────────────────────────────────────────

$idem_stmt = execute_query(
    $conn,
    'SELECT id FROM webhook_events WHERE payload_hash = ? LIMIT 1',
    [$payload_hash],
    's'
);
$existing_event = $idem_stmt->get_result()->fetch_assoc();
$idem_stmt->close();

if ($existing_event !== null) {
    log_event('INFO', "webhook: Duplicate event received (hash={$payload_hash}) – skipping");
    close_db_connection($conn);
    json_response(200, ['status' => 'already_processed']);
}

// ─── STEP 4 – Save webhook event ─────────────────────────────────────────────

$event_type   = (string)($payload['event_type'] ?? 'unknown');
$payload_json = json_encode($payload, JSON_UNESCAPED_UNICODE);

try {
    $insert_event_stmt = execute_query(
        $conn,
        'INSERT INTO webhook_events (event_type, payload, payload_hash, processed)
         VALUES (?, ?, ?, 0)',
        [$event_type, $payload_json, $payload_hash],
        'sss'
    );
    $webhook_event_id = (int)$conn->insert_id;
    $insert_event_stmt->close();

    log_event('INFO', "webhook: Event saved – id={$webhook_event_id} type={$event_type}");
} catch (RuntimeException $e) {
    log_event('ERROR', 'webhook: Failed to save webhook event: ' . $e->getMessage());
    close_db_connection($conn);
    json_response(500, ['status' => 'error', 'message' => 'Failed to record event.']);
}

// ─── Helper functions ─────────────────────────────────────────────────────────

/**
 * Extracts and normalises reservation data from a webhook payload.
 *
 * The reservation may be nested under a 'reservation' key or at root level.
 *
 * @param array $payload Decoded webhook payload.
 * @return array Reservation data as an associative array.
 */
function extract_reservation_data(array $payload): array
{
    if (isset($payload['reservation']) && is_array($payload['reservation'])) {
        return $payload['reservation'];
    }
    // Root-level fallback: strip the event_type key
    $data = $payload;
    unset($data['event_type']);
    return $data;
}

/**
 * Returns a map of hs_room_id → local room id from the rooms table.
 *
 * @param mysqli $conn Active DB connection.
 * @return array<string, int>
 */
function get_room_map(mysqli $conn): array
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
function get_rate_plan_map(mysqli $conn): array
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

/**
 * Inserts a new reservation and its room / rate-plan links from webhook data.
 *
 * Mirrors the import logic in sync_reservations.php.
 * Returns the local DB id on success, or null on failure.
 *
 * @param mysqli $conn     Active DB connection.
 * @param array  $res_data Reservation data array from the webhook payload.
 * @return int|null Local reservation id, or null on failure.
 */
function insert_reservation_from_webhook(mysqli $conn, array $res_data): ?int
{
    $hs_id = (string)(
        $res_data['id_reservations'] ?? $res_data['reservation_id'] ?? $res_data['id'] ?? ''
    );
    if ($hs_id === '') {
        log_event('ERROR', 'webhook: insert_reservation – missing id_reservations in payload');
        return null;
    }

    $guest_name = trim(
        ($res_data['first_name'] ?? $res_data['firstname'] ?? '')
        . ' '
        . ($res_data['last_name'] ?? $res_data['lastname'] ?? '')
    );
    if ($guest_name === '' || $guest_name === ' ') {
        $guest_name = $res_data['guest_name'] ?? $res_data['client_name'] ?? $res_data['name'] ?? 'Unknown Guest';
    }
    $guest_name = trim($guest_name) ?: 'Unknown Guest';

    $arrival   = (string)($res_data['date_arrival']   ?? $res_data['arrival_date']   ?? $res_data['date_from'] ?? '');
    $departure = (string)($res_data['date_departure']  ?? $res_data['departure_date'] ?? $res_data['date_to']   ?? '');
    $status    = strtolower((string)($res_data['status'] ?? 'new'));

    if (in_array($status, ['canceled', 'cancellation', 'cancelled'], true)) {
        $status = 'cancelled';
    }
    if (!empty($res_data['is_deleted']) && (int)$res_data['is_deleted'] === 1) {
        $status = 'cancelled';
    }

    $lock_id      = generate_lock_id((int)$hs_id, $arrival);
    $payload_hash = hash_payload($res_data);
    $raw_json     = json_encode($res_data, JSON_UNESCAPED_UNICODE);

    try {
        execute_query(
            $conn,
            'INSERT INTO reservations
                 (lock_id, hs_reservation_id, guest_name, arrival_date, departure_date,
                  status, payload_hash, raw_data)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$lock_id, $hs_id, $guest_name, $arrival, $departure, $status, $payload_hash, $raw_json],
            'ssssssss'
        )->close();
    } catch (RuntimeException $e) {
        log_event('ERROR', "webhook: insert_reservation failed for hs_id={$hs_id}: " . $e->getMessage());
        return null;
    }

    $local_id  = (int)$conn->insert_id;
    $room_map  = get_room_map($conn);

    // Link rooms
    if (isset($res_data['rooms']) && is_array($res_data['rooms'])) {
        foreach ($res_data['rooms'] as $room_entry) {
            $hs_room_id = (string)($room_entry['id_room_types'] ?? $room_entry['id_room'] ?? '');
            if ($hs_room_id === '' || !isset($room_map[$hs_room_id])) {
                continue;
            }
            $quantity = (int)($room_entry['quantity'] ?? 1);
            try {
                execute_query(
                    $conn,
                    'INSERT IGNORE INTO reservation_rooms (reservation_id, room_id, quantity)
                     VALUES (?, ?, ?)',
                    [$local_id, $room_map[$hs_room_id], $quantity],
                    'iii'
                )->close();
            } catch (RuntimeException $e) {
                log_event('WARNING', "webhook: Could not link room {$hs_room_id} to reservation {$local_id}");
            }
        }
    }

    // Link rate plan (scalar id_pricing_plans on root object)
    if (isset($res_data['id_pricing_plans'])
        && $res_data['id_pricing_plans'] !== ''
        && (int)$res_data['id_pricing_plans'] !== 0
    ) {
        $rate_plan_map = get_rate_plan_map($conn);
        $hs_rp_id     = (string)$res_data['id_pricing_plans'];
        if (isset($rate_plan_map[$hs_rp_id])) {
            try {
                execute_query(
                    $conn,
                    'INSERT IGNORE INTO reservation_rate_plans (reservation_id, rate_plan_id)
                     VALUES (?, ?)',
                    [$local_id, $rate_plan_map[$hs_rp_id]],
                    'ii'
                )->close();
            } catch (RuntimeException $e) {
                log_event('WARNING', "webhook: Could not link rate plan {$hs_rp_id} to reservation {$local_id}");
            }
        }
    }

    log_event('SUCCESS', "webhook: Reservation inserted – local_id={$local_id} hs_id={$hs_id}", $local_id, $hs_id);
    return $local_id;
}

// ─── STEP 5 – Event handlers ──────────────────────────────────────────────────

/**
 * Handles the reservation.created webhook event.
 *
 * Checks if the reservation already exists locally; skips if it does.
 * Otherwise inserts the full reservation record with room/rate-plan links.
 *
 * @param mysqli $conn    Active DB connection.
 * @param array  $payload Decoded webhook payload.
 * @return void
 */
function handle_reservation_created(mysqli $conn, array $payload): void
{
    $res_data = extract_reservation_data($payload);
    $hs_id    = (string)(
        $res_data['id_reservations'] ?? $res_data['reservation_id'] ?? $res_data['id'] ?? ''
    );

    if ($hs_id === '') {
        log_event('ERROR', 'webhook: reservation.created – missing reservation ID in payload');
        return;
    }

    $stmt = execute_query(
        $conn,
        'SELECT id FROM reservations WHERE hs_reservation_id = ? LIMIT 1',
        [$hs_id],
        's'
    );
    $existing = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($existing !== null) {
        log_event(
            'INFO',
            "webhook: reservation.created – hs_id={$hs_id} already exists (local_id={$existing['id']}) – skipping",
            (int)$existing['id'],
            $hs_id
        );
        return;
    }

    $local_id = insert_reservation_from_webhook($conn, $res_data);
    if ($local_id === null) {
        log_event('ERROR', "webhook: reservation.created – insert failed for hs_id={$hs_id}");
    }
}

/**
 * Handles the reservation.updated webhook event.
 *
 * Compares the new payload hash with the stored one.  If changed, updates
 * all fields and writes an audit_log entry.  If not found locally, inserts
 * the reservation as new.
 *
 * @param mysqli $conn    Active DB connection.
 * @param array  $payload Decoded webhook payload.
 * @return void
 */
function handle_reservation_updated(mysqli $conn, array $payload): void
{
    $res_data = extract_reservation_data($payload);
    $hs_id    = (string)(
        $res_data['id_reservations'] ?? $res_data['reservation_id'] ?? $res_data['id'] ?? ''
    );

    if ($hs_id === '') {
        log_event('ERROR', 'webhook: reservation.updated – missing reservation ID in payload');
        return;
    }

    $stmt = execute_query(
        $conn,
        'SELECT id, payload_hash, status, raw_data FROM reservations WHERE hs_reservation_id = ? LIMIT 1',
        [$hs_id],
        's'
    );
    $local_row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($local_row === null) {
        log_event('WARNING', "webhook: reservation.updated – hs_id={$hs_id} not found locally, inserting as new");
        insert_reservation_from_webhook($conn, $res_data);
        return;
    }

    $local_id = (int)$local_row['id'];
    $new_hash = hash_payload($res_data);

    if ($new_hash === $local_row['payload_hash']) {
        log_event('INFO', "webhook: reservation.updated – no changes detected for hs_id={$hs_id}", $local_id, $hs_id);
        return;
    }

    // Build updated fields using same field-name fallback as other scripts
    $guest_name = trim(
        ($res_data['first_name'] ?? $res_data['firstname'] ?? '')
        . ' '
        . ($res_data['last_name'] ?? $res_data['lastname'] ?? '')
    );
    if ($guest_name === '' || $guest_name === ' ') {
        $guest_name = $res_data['guest_name'] ?? $res_data['client_name'] ?? $res_data['name'] ?? 'Unknown Guest';
    }
    $guest_name = trim($guest_name) ?: 'Unknown Guest';

    $new_arrival   = (string)($res_data['date_arrival']   ?? $res_data['arrival_date']   ?? '');
    $new_departure = (string)($res_data['date_departure']  ?? $res_data['departure_date'] ?? '');
    $new_status    = strtolower((string)($res_data['status'] ?? $local_row['status']));

    if (in_array($new_status, ['canceled', 'cancellation', 'cancelled'], true)) {
        $new_status = 'cancelled';
    }
    if (!empty($res_data['is_deleted']) && (int)$res_data['is_deleted'] === 1) {
        $new_status = 'cancelled';
    }

    $new_raw_json = json_encode($res_data, JSON_UNESCAPED_UNICODE);

    execute_query(
        $conn,
        'UPDATE reservations
            SET guest_name     = ?,
                arrival_date   = ?,
                departure_date = ?,
                status         = ?,
                payload_hash   = ?,
                raw_data       = ?,
                updated_at     = NOW()
          WHERE id = ?',
        [$guest_name, $new_arrival, $new_departure, $new_status, $new_hash, $new_raw_json, $local_id],
        'ssssssi'
    )->close();

    execute_query(
        $conn,
        'INSERT INTO audit_log (reservation_id, event_type, old_data, new_data)
         VALUES (?, ?, ?, ?)',
        [$local_id, 'reservation.updated', $local_row['raw_data'], $new_raw_json],
        'isss'
    )->close();

    log_event(
        'SUCCESS',
        "webhook: reservation.updated – hs_id={$hs_id} updated, audit_log written",
        $local_id,
        $hs_id
    );
}

/**
 * Handles the reservation.cancelled webhook event.
 *
 * Soft-cancels the local reservation by setting status = 'cancelled'.
 * The record is NOT deleted.  An audit_log entry is written.
 *
 * @param mysqli $conn    Active DB connection.
 * @param array  $payload Decoded webhook payload.
 * @return void
 */
function handle_reservation_cancelled(mysqli $conn, array $payload): void
{
    $res_data = extract_reservation_data($payload);
    $hs_id    = (string)(
        $res_data['id_reservations'] ?? $res_data['reservation_id'] ?? $res_data['id'] ?? ''
    );

    if ($hs_id === '') {
        log_event('ERROR', 'webhook: reservation.cancelled – missing reservation ID in payload');
        return;
    }

    $stmt = execute_query(
        $conn,
        'SELECT id, status, raw_data FROM reservations WHERE hs_reservation_id = ? LIMIT 1',
        [$hs_id],
        's'
    );
    $local_row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($local_row === null) {
        log_event('WARNING', "webhook: reservation.cancelled – hs_id={$hs_id} not found locally – nothing to cancel");
        return;
    }

    $local_id = (int)$local_row['id'];

    if ($local_row['status'] === 'cancelled') {
        log_event('INFO', "webhook: reservation.cancelled – hs_id={$hs_id} already cancelled", $local_id, $hs_id);
        return;
    }

    execute_query(
        $conn,
        "UPDATE reservations SET status = 'cancelled', updated_at = NOW() WHERE id = ?",
        [$local_id],
        'i'
    )->close();

    $new_raw_json = json_encode($res_data, JSON_UNESCAPED_UNICODE);

    execute_query(
        $conn,
        'INSERT INTO audit_log (reservation_id, event_type, old_data, new_data)
         VALUES (?, ?, ?, ?)',
        [$local_id, 'cancelled', $local_row['raw_data'], $new_raw_json],
        'isss'
    )->close();

    log_event(
        'SUCCESS',
        "webhook: reservation.cancelled – hs_id={$hs_id} soft-cancelled, audit_log written",
        $local_id,
        $hs_id
    );
}

// ─── Route ────────────────────────────────────────────────────────────────────

log_event('INFO', "webhook: Processing event type='{$event_type}'");

try {
    switch ($event_type) {
        case 'reservation.created':
            handle_reservation_created($conn, $payload);
            break;

        case 'reservation.updated':
            handle_reservation_updated($conn, $payload);
            break;

        case 'reservation.cancelled':
            handle_reservation_cancelled($conn, $payload);
            break;

        default:
            log_event('WARNING', "webhook: Unknown event type '{$event_type}' – recorded but not handled");
            break;
    }
} catch (RuntimeException $e) {
    log_event('ERROR', "webhook: Handler threw exception for event '{$event_type}': " . $e->getMessage());
    // Do not mark as processed on error
    close_db_connection($conn);
    json_response(500, ['status' => 'error', 'message' => 'Event handling failed: ' . $e->getMessage()]);
}

// ─── STEP 6 – Mark as processed ──────────────────────────────────────────────

try {
    execute_query(
        $conn,
        'UPDATE webhook_events SET processed = 1 WHERE id = ?',
        [$webhook_event_id],
        'i'
    )->close();
    log_event('INFO', "webhook: Event id={$webhook_event_id} marked as processed");
} catch (RuntimeException $e) {
    log_event('ERROR', "webhook: Failed to mark event id={$webhook_event_id} as processed: " . $e->getMessage());
}

close_db_connection($conn);

// ─── STEP 7 – Response ────────────────────────────────────────────────────────

log_event('SUCCESS', "webhook: Event '{$event_type}' completed successfully");
json_response(200, ['status' => 'ok', 'event_type' => $event_type]);
