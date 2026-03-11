<?php

/**
 * scripts/sync_reservations.php
 *
 * Phase 2 – Reservation Import.
 *
 * Authenticates against the HotelSync API, fetches reservations within the
 * specified arrival-date range, and inserts new records into the local database.
 * Reservations already present are skipped (full update logic is Phase 3).
 * Room and rate-plan associations are resolved against the local catalog tables.
 *
 * Confirmed API endpoint (verified against Postman collection 2026-03-11):
 *   POST /reservation/data/reservations
 *   Date range fields: dfrom / dto  (NOT from/to – no type:"list" needed)
 *   Required filter fields: filter_by, view_type, page
 *   Reservation ID field: id_reservations
 *   Room reference field: id_room_types   (matches rooms.hs_room_id)
 *   Rate plan reference field: id_pricing_plans (matches rate_plans.hs_rate_plan_id)
 *
 * Usage:
 *   php scripts/sync_reservations.php --from=2026-01-01 --to=2026-01-31
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../src/logger.php';
require_once __DIR__ . '/../src/db.php';
require_once __DIR__ . '/../src/api.php';
require_once __DIR__ . '/../src/helpers.php';

// ─── STEP 1 – Argument parsing ────────────────────────────────────────────────

/**
 * Prints CLI usage instructions and exits.
 *
 * @return never
 */
function usage(): void
{
    echo PHP_EOL;
    echo 'Usage:  php scripts/sync_reservations.php --from=YYYY-MM-DD --to=YYYY-MM-DD' . PHP_EOL;
    echo PHP_EOL;
    echo 'Options:' . PHP_EOL;
    echo '  --from   Arrival date range start (inclusive), format YYYY-MM-DD' . PHP_EOL;
    echo '  --to     Arrival date range end   (inclusive), format YYYY-MM-DD' . PHP_EOL;
    echo PHP_EOL;
    echo 'Example:' . PHP_EOL;
    echo '  php scripts/sync_reservations.php --from=2026-01-01 --to=2026-01-31' . PHP_EOL;
    echo PHP_EOL;
    exit(1);
}

/**
 * Validates that a string is a well-formed YYYY-MM-DD date.
 *
 * @param string $date Candidate date string.
 * @return bool
 */
function is_valid_date(string $date): bool
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return false;
    }
    [$y, $m, $d] = explode('-', $date);
    return checkdate((int)$m, (int)$d, (int)$y);
}

$opts = getopt('', ['from:', 'to:']);

$date_from = $opts['from'] ?? null;
$date_to   = $opts['to']   ?? null;

if ($date_from === null || $date_to === null) {
    echo '[ERROR] Both --from and --to are required.' . PHP_EOL;
    usage();
}

if (!is_valid_date($date_from)) {
    echo "[ERROR] --from value '{$date_from}' is not a valid YYYY-MM-DD date." . PHP_EOL;
    usage();
}

if (!is_valid_date($date_to)) {
    echo "[ERROR] --to value '{$date_to}' is not a valid YYYY-MM-DD date." . PHP_EOL;
    usage();
}

if ($date_from > $date_to) {
    echo "[ERROR] --from ({$date_from}) must not be later than --to ({$date_to})." . PHP_EOL;
    usage();
}

// ─── Boot ─────────────────────────────────────────────────────────────────────

echo PHP_EOL . '=== HotelSync Reservation Sync ===' . PHP_EOL;
echo "Date range: {$date_from} → {$date_to}" . PHP_EOL . PHP_EOL;
log_event('INFO', "sync_reservations: Starting import for {$date_from} → {$date_to}");

// ─── STEP 2a – Authenticate ───────────────────────────────────────────────────

try {
    $login = api_login();
    $pkey  = $login['pkey'];
    echo 'Login: OK' . PHP_EOL;
    log_event('SUCCESS', 'sync_reservations: API login successful');
} catch (RuntimeException $e) {
    echo '[ERROR] Login failed: ' . $e->getMessage() . PHP_EOL;
    log_event('ERROR', 'sync_reservations: Login failed: ' . $e->getMessage());
    exit(1);
}

// ─── STEP 2b – Database connection ───────────────────────────────────────────

try {
    $conn = get_db_connection();
} catch (RuntimeException $e) {
    echo '[ERROR] Database connection failed: ' . $e->getMessage() . PHP_EOL;
    log_event('ERROR', 'sync_reservations: DB connection failed: ' . $e->getMessage());
    exit(1);
}

// ─── STEP 2c – Fetch reservations (with pagination) ──────────────────────────

echo 'Fetching reservations from API...' . PHP_EOL;
log_event('INFO', 'sync_reservations: Fetching reservations from API');

/**
 * Fetches all pages of reservations from the HotelSync API.
 *
 * Handles two common pagination shapes:
 *   1. Flat array – returned as-is (no pagination).
 *   2. Object with 'data' array + optional 'current_page' / 'last_page'.
 *
 * @param string $pkey      Session key from api_login().
 * @param string $date_from Range start (YYYY-MM-DD).
 * @param string $date_to   Range end   (YYYY-MM-DD).
 * @return array All reservation records concatenated across pages.
 */
function fetch_all_reservations(string $pkey, string $date_from, string $date_to): array
{
    $all  = [];
    $page = 1;

    do {
        // Full body required by the API – omitting any of these fields causes
        // HTTP 500. Confirmed against Postman collection 2026-03-11.
        $payload = [
            'dfrom'               => $date_from,
            'dto'                 => $date_to,
            'filter_by'           => 'date_received',
            'view_type'           => 'reservations',
            'order_by'            => 'date_received',
            'order_type'          => 'desc',
            'status'              => '0',   // 0 = all statuses
            'show_rooms'          => 1,
            'show_nights'         => 1,
            'page'                => $page,
            'channels'            => [],
            'countries'           => [],
            'rooms'               => [],
            'companies'           => [],
            'contigents'          => [],
            'pricing_plans'       => [],
            'arrivals'            => 0,
            'departures'          => 0,
            'last_modified_from'  => '',
            'last_modified_to'    => '',
            'max_nights'          => '',
            'max_price'           => '',
            'min_nights'          => '',
            'min_price'           => '',
            'multiple_properties' => '0',
            'offer_expiring'      => '0',
            'search'              => '',
        ];

        $resp = api_request('/reservation/data/reservations', $payload, $pkey);

        // Detect response shape ─────────────────────────────────────────────
        if (empty($resp)) {
            // Empty body – no reservations
            break;
        }

        // When there are no reservations the API returns {"currency":"EUR"} with
        // no reservation array. Treat any response that has no recognisable data
        // key and no numeric keys as "no results".
        if (isset($resp['reservations']) && is_array($resp['reservations'])) {
            // {"currency":"EUR","reservations":[...], "total":N, "last_page":3}
            $records   = $resp['reservations'];
            $last_page = (int)($resp['last_page'] ?? $resp['total_pages'] ?? $page);
        } elseif (isset($resp['data']) && is_array($resp['data'])) {
            // Generic paginated wrapper: {"data":[...], "last_page":3}
            $records   = $resp['data'];
            $last_page = (int)($resp['last_page'] ?? $resp['total_pages'] ?? $page);
        } elseif (array_key_exists(0, $resp)) {
            // Flat array – single page
            $records   = $resp;
            $last_page = $page;
        } else {
            // {"currency":"EUR"} or similar metadata-only response → no reservations
            log_event('INFO', 'sync_reservations: API returned metadata-only response (no reservations). Keys: ' . implode(', ', array_keys($resp)));
            break;
        }

        $all = array_merge($all, $records);

        log_event('INFO', "sync_reservations: Page {$page}/{$last_page} – " . count($records) . ' records');

        $page++;
    } while ($page <= $last_page);

    return $all;
}

try {
    $reservations = fetch_all_reservations($pkey, $date_from, $date_to);
    $total_fetched = count($reservations);
    echo "Reservations fetched from API: {$total_fetched}" . PHP_EOL;
    log_event('INFO', "sync_reservations: Total reservations fetched: {$total_fetched}");
} catch (RuntimeException $e) {
    echo '[ERROR] Failed to fetch reservations: ' . $e->getMessage() . PHP_EOL;
    log_event('ERROR', 'sync_reservations: Failed to fetch reservations: ' . $e->getMessage());
    close_db_connection($conn);
    exit(1);
}

// ─── Build local catalog lookup maps ─────────────────────────────────────────

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

log_event('INFO', 'sync_reservations: Catalog maps built – rooms: ' . count($room_map) . ', rate plans: ' . count($rate_plan_map));

// ─── STEP 3–5 – Process each reservation ─────────────────────────────────────

$count_imported = 0;
$count_skipped  = 0;
$count_warnings = 0;

echo PHP_EOL . '--- Processing reservations ---' . PHP_EOL;

foreach ($reservations as $res) {
    // ── Extract core fields (confirmed field names from API 2026-03-11) ────
    $hs_reservation_id = (string)(
        $res['id_reservations'] ?? $res['id_reservation'] ?? $res['id'] ?? ''
    );

    // Guest name – API returns first_name + last_name as separate fields
    $guest_name = trim(
        ($res['first_name'] ?? $res['firstname'] ?? '')
        . ' '
        . ($res['last_name'] ?? $res['lastname'] ?? '')
    );
    if ($guest_name === '' || $guest_name === ' ') {
        $guest_name = $res['guest_name'] ?? $res['client_name'] ?? $res['name'] ?? 'Unknown Guest';
    }
    if (trim($guest_name) === '') {
        $guest_name = 'Unknown Guest';
    }

    // Arrival / departure – confirmed API fields: date_arrival / date_departure
    $arrival_date   = (string)($res['date_arrival']   ?? $res['arrival_date']   ?? $res['date_from'] ?? '');
    $departure_date = (string)($res['date_departure']  ?? $res['departure_date'] ?? $res['date_to']   ?? '');
    $status         = strtolower((string)($res['status'] ?? 'new'));

    if ($hs_reservation_id === '' || $arrival_date === '' || $departure_date === '') {
        log_event(
            'WARNING',
            'sync_reservations: Skipping reservation – missing id/dates: ' . json_encode($res)
        );
        $count_warnings++;
        continue;
    }

    // ── STEP 3 – Deduplication check ──────────────────────────────────────
    $lock_id = generate_lock_id((int)$hs_reservation_id, $arrival_date);

    $chk_stmt = execute_query(
        $conn,
        'SELECT id FROM reservations WHERE hs_reservation_id = ?',
        [$hs_reservation_id],
        's'
    );
    $chk_result   = $chk_stmt->get_result();
    $existing_row = $chk_result->fetch_assoc();
    $chk_stmt->close();

    if ($existing_row !== null) {
        log_event(
            'INFO',
            "sync_reservations: Skipping hs_reservation_id={$hs_reservation_id} (already exists)"
        );
        $count_skipped++;
        continue;
    }

    // ── Insert the reservation ─────────────────────────────────────────────
    $raw_json    = json_encode($res, JSON_UNESCAPED_UNICODE);
    $payload_hash = hash_payload($res);

    try {
        execute_query(
            $conn,
            'INSERT INTO reservations
                (lock_id, hs_reservation_id, guest_name, arrival_date, departure_date, status, payload_hash, raw_data)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$lock_id, $hs_reservation_id, $guest_name, $arrival_date, $departure_date, $status, $payload_hash, $raw_json],
            'ssssssss'
        )->close();

        $local_res_id = $conn->insert_id;
        $count_imported++;

        log_event(
            'SUCCESS',
            "sync_reservations: Reservation inserted – local_id={$local_res_id} hs_id={$hs_reservation_id} guest={$guest_name}",
            $local_res_id,
            $hs_reservation_id
        );
    } catch (RuntimeException $e) {
        log_event('ERROR', "sync_reservations: Failed to insert reservation {$hs_reservation_id}: " . $e->getMessage());
        echo "[ERROR] Reservation {$hs_reservation_id}: " . $e->getMessage() . PHP_EOL;
        $count_warnings++;
        continue;
    }

    // ── STEP 4 – Map rooms ────────────────────────────────────────────────

    // Rooms may live at $res['rooms'], $res['room_types'], or directly as $res['id_room_types']
    $res_rooms = [];
    if (isset($res['rooms']) && is_array($res['rooms'])) {
        $res_rooms = $res['rooms'];
    } elseif (isset($res['room_types']) && is_array($res['room_types'])) {
        $res_rooms = $res['room_types'];
    } elseif (isset($res['id_room_types'])) {
        // Single room stored directly on the reservation object
        $res_rooms = [['id_room_types' => $res['id_room_types'], 'quantity' => $res['quantity'] ?? 1]];
    }

    foreach ($res_rooms as $room_entry) {
        $hs_room_id = (string)(
            $room_entry['id_room_types'] ?? $room_entry['id_room'] ?? $room_entry['room_id'] ?? ''
        );
        $quantity = (int)($room_entry['quantity'] ?? 1);

        if ($hs_room_id === '') {
            log_event('WARNING', "sync_reservations: Room entry missing ID in reservation {$hs_reservation_id}");
            $count_warnings++;
            continue;
        }

        if (!isset($room_map[$hs_room_id])) {
            log_event(
                'WARNING',
                "sync_reservations: Room hs_room_id={$hs_room_id} not found in local catalog – skipping (reservation {$hs_reservation_id})"
            );
            $count_warnings++;
            continue;
        }

        $local_room_id = $room_map[$hs_room_id];

        try {
            execute_query(
                $conn,
                'INSERT IGNORE INTO reservation_rooms (reservation_id, room_id, quantity) VALUES (?, ?, ?)',
                [$local_res_id, $local_room_id, $quantity],
                'iii'
            )->close();

            log_event(
                'INFO',
                "sync_reservations: Room linked – reservation {$hs_reservation_id} → hs_room_id={$hs_room_id}"
            );
        } catch (RuntimeException $e) {
            log_event('ERROR', "sync_reservations: Failed to link room {$hs_room_id} to reservation {$hs_reservation_id}: " . $e->getMessage());
            $count_warnings++;
        }
    }

    // ── STEP 5 – Map rate plans ───────────────────────────────────────────

    // API returns id_pricing_plans as a scalar directly on the reservation
    // (confirmed 2026-03-11). Wrap it so we can loop uniformly.
    $res_rate_plans = [];
    if (isset($res['id_pricing_plans']) && $res['id_pricing_plans'] !== '' && $res['id_pricing_plans'] !== '0') {
        $res_rate_plans = [['id_pricing_plans' => $res['id_pricing_plans']]];
    } elseif (isset($res['pricing_plans']) && is_array($res['pricing_plans'])) {
        $res_rate_plans = $res['pricing_plans'];
    } elseif (isset($res['rate_plans']) && is_array($res['rate_plans'])) {
        $res_rate_plans = $res['rate_plans'];
    }

    foreach ($res_rate_plans as $rp_entry) {
        $hs_rp_id = (string)(
            $rp_entry['id_pricing_plans'] ?? $rp_entry['id_rate_plan'] ?? $rp_entry['rate_plan_id'] ?? ''
        );

        if ($hs_rp_id === '') {
            log_event('WARNING', "sync_reservations: Rate plan entry missing ID in reservation {$hs_reservation_id}");
            $count_warnings++;
            continue;
        }

        if (!isset($rate_plan_map[$hs_rp_id])) {
            log_event(
                'WARNING',
                "sync_reservations: Rate plan hs_rate_plan_id={$hs_rp_id} not found in local catalog – skipping (reservation {$hs_reservation_id})"
            );
            $count_warnings++;
            continue;
        }

        $local_rp_id = $rate_plan_map[$hs_rp_id];

        try {
            execute_query(
                $conn,
                'INSERT IGNORE INTO reservation_rate_plans (reservation_id, rate_plan_id) VALUES (?, ?)',
                [$local_res_id, $local_rp_id],
                'ii'
            )->close();

            log_event(
                'INFO',
                "sync_reservations: Rate plan linked – reservation {$hs_reservation_id} → hs_rp_id={$hs_rp_id}"
            );
        } catch (RuntimeException $e) {
            log_event('ERROR', "sync_reservations: Failed to link rate plan {$hs_rp_id} to reservation {$hs_reservation_id}: " . $e->getMessage());
            $count_warnings++;
        }
    }
}

// ─── STEP 6 – Summary ─────────────────────────────────────────────────────────

close_db_connection($conn);

echo PHP_EOL;
echo "Reservations imported: {$count_imported} new, {$count_skipped} skipped (already exist)" . PHP_EOL;
echo "Warnings: {$count_warnings} rooms/rate plans not found in local catalog" . PHP_EOL;

log_event(
    'SUCCESS',
    "sync_reservations: Complete – imported={$count_imported} skipped={$count_skipped} warnings={$count_warnings}"
);

echo PHP_EOL . '=== Reservation sync complete ===' . PHP_EOL;
