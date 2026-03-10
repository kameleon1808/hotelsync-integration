<?php

/**
 * scripts/sync_catalog.php
 *
 * Phase 1 – Authentication & Catalog Sync.
 *
 * Authenticates against the HotelSync API, then fetches rooms and rate plans
 * and upserts them into the local MySQL database.
 *
 * Change detection is performed by SHA-256 hashing the raw API payload.
 * Records are only written when the payload has changed since the last sync.
 *
 * Confirmed API behaviour (tested 2026-03-10):
 *   - Rooms:      POST /room/data/rooms       – requires {"type":"list"} in body
 *                 Room ID field:  id_room_types
 *                 Response:       flat JSON array (no wrapper object)
 *   - Rate plans: POST /room/data/pricing_plans – returns empty array when none configured
 *                 Rate plan ID field: id_pricing_plans
 *
 * Usage:
 *   php scripts/sync_catalog.php
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../src/logger.php';
require_once __DIR__ . '/../src/db.php';
require_once __DIR__ . '/../src/api.php';
require_once __DIR__ . '/../src/helpers.php';

// ─── STEP 1 – Authentication ──────────────────────────────────────────────────

echo PHP_EOL . '=== HotelSync Catalog Sync ===' . PHP_EOL;
log_event('INFO', 'sync_catalog: Starting catalog sync');

try {
    $login = api_login();
    $pkey  = $login['pkey'];
    echo 'Login: OK' . PHP_EOL;
    log_event('SUCCESS', 'sync_catalog: API login successful');
} catch (RuntimeException $e) {
    echo '[ERROR] Login failed: ' . $e->getMessage() . PHP_EOL;
    log_event('ERROR', 'sync_catalog: Login failed: ' . $e->getMessage());
    exit(1);
}

// ─── Database connection ──────────────────────────────────────────────────────

try {
    $conn = get_db_connection();
} catch (RuntimeException $e) {
    echo '[ERROR] Database connection failed: ' . $e->getMessage() . PHP_EOL;
    log_event('ERROR', 'sync_catalog: DB connection failed: ' . $e->getMessage());
    exit(1);
}

// ─── STEP 2 – Fetch and sync rooms ───────────────────────────────────────────

echo PHP_EOL . '--- Rooms ---' . PHP_EOL;
log_event('INFO', 'sync_catalog: Fetching rooms from API');

try {
    // "type" => "list" is required by the API; omitting it returns HTTP 400 "Missing type"
    $rooms_resp = api_request('/room/data/rooms', ['type' => 'list'], $pkey);

    // The API returns a flat JSON array; guard against wrapped {"data":[...]} too
    if (isset($rooms_resp['data']) && is_array($rooms_resp['data'])) {
        $rooms = $rooms_resp['data'];
    } elseif (array_key_exists(0, $rooms_resp) || empty($rooms_resp)) {
        $rooms = $rooms_resp;
    } else {
        throw new RuntimeException(
            'Unexpected rooms response format. Top-level keys: ' . implode(', ', array_keys($rooms_resp))
        );
    }

    log_event('INFO', 'sync_catalog: Rooms received from API: ' . count($rooms));
} catch (RuntimeException $e) {
    echo '[ERROR] Failed to fetch rooms: ' . $e->getMessage() . PHP_EOL;
    log_event('ERROR', 'sync_catalog: Failed to fetch rooms: ' . $e->getMessage());
    close_db_connection($conn);
    exit(1);
}

$rooms_inserted  = 0;
$rooms_updated   = 0;
$rooms_unchanged = 0;

foreach ($rooms as $room) {
    try {
        // API field confirmed as "id_room_types" (not "id_room")
        $hs_room_id = (string)($room['id_room_types'] ?? $room['id_room'] ?? $room['id'] ?? '');
        $name       = (string)($room['name'] ?? '');

        if ($hs_room_id === '' || $name === '') {
            log_event(
                'WARNING',
                'sync_catalog: Skipping room – missing id or name: ' . json_encode($room)
            );
            continue;
        }

        $external_id = generate_room_external_id((int)$hs_room_id, $name);
        $slug        = slugify($name);
        $raw_json    = json_encode($room, JSON_UNESCAPED_UNICODE);
        $new_hash    = hash_payload($room);

        // Check whether this room already exists in the local DB
        $stmt     = execute_query(
            $conn,
            'SELECT id, raw_data FROM rooms WHERE hs_room_id = ?',
            [$hs_room_id],
            's'
        );
        $result   = $stmt->get_result();
        $existing = $result->fetch_assoc();
        $stmt->close();

        if ($existing !== null) {
            // Compare payload hashes to detect changes
            $stored_data = json_decode($existing['raw_data'], true) ?? [];
            $stored_hash = hash_payload($stored_data);

            if ($stored_hash === $new_hash) {
                $rooms_unchanged++;
                continue;
            }

            execute_query(
                $conn,
                'UPDATE rooms SET external_id = ?, name = ?, slug = ?, raw_data = ? WHERE hs_room_id = ?',
                [$external_id, $name, $slug, $raw_json, $hs_room_id],
                'sssss'
            )->close();
            $rooms_updated++;
            log_event('INFO', 'sync_catalog: Room updated – ' . $external_id, null, $external_id);

        } else {
            execute_query(
                $conn,
                'INSERT INTO rooms (external_id, hs_room_id, name, slug, raw_data)
                 VALUES (?, ?, ?, ?, ?)',
                [$external_id, $hs_room_id, $name, $slug, $raw_json],
                'sssss'
            )->close();
            $rooms_inserted++;
            log_event('SUCCESS', 'sync_catalog: Room inserted – ' . $external_id, null, $external_id);
        }

    } catch (RuntimeException $e) {
        log_event('ERROR', 'sync_catalog: Error processing room: ' . $e->getMessage());
        echo '[ERROR] Room processing error: ' . $e->getMessage() . PHP_EOL;
    }
}

echo "Rooms synced: {$rooms_inserted} inserted, {$rooms_updated} updated, {$rooms_unchanged} unchanged" . PHP_EOL;
log_event(
    'INFO',
    "sync_catalog: Rooms complete – inserted={$rooms_inserted} updated={$rooms_updated} unchanged={$rooms_unchanged}"
);

// ─── STEP 3 – Fetch and sync rate plans ──────────────────────────────────────

echo PHP_EOL . '--- Rate Plans ---' . PHP_EOL;
log_event('INFO', 'sync_catalog: Fetching rate plans from API');

try {
    // Confirmed endpoint (from Postman docs): pricingPlan/data/pricing_plans
    // No "type" field required for this endpoint
    $rp_resp    = api_request('/pricingPlan/data/pricing_plans', [], $pkey);

    if (isset($rp_resp['data']) && is_array($rp_resp['data'])) {
        $rate_plans = $rp_resp['data'];
    } elseif (array_key_exists(0, $rp_resp) || empty($rp_resp)) {
        $rate_plans = $rp_resp;
    } else {
        throw new RuntimeException(
            'Unexpected rate plans response format. Top-level keys: ' . implode(', ', array_keys($rp_resp))
        );
    }

    $count = count($rate_plans);
    log_event('INFO', 'sync_catalog: Rate plans received from API: ' . $count);

    if ($count === 0) {
        echo 'Rate plans: none found in API (property may not have pricing plans configured).' . PHP_EOL;
        log_event('WARNING', 'sync_catalog: No rate plans returned by API – skipping rate plan sync');
    }

} catch (RuntimeException $e) {
    echo '[ERROR] Failed to fetch rate plans: ' . $e->getMessage() . PHP_EOL;
    log_event('ERROR', 'sync_catalog: Failed to fetch rate plans: ' . $e->getMessage());
    close_db_connection($conn);
    exit(1);
}

$rp_inserted  = 0;
$rp_updated   = 0;
$rp_unchanged = 0;

foreach ($rate_plans as $rp) {
    try {
        // Confirmed API fields: id_pricing_plans, name, id_board_names
        // The API has no "meal_plan" text field; id_board_names is the board/meal type reference
        $hs_rp_id  = (string)($rp['id_pricing_plans'] ?? $rp['id'] ?? '');
        $name      = (string)($rp['name'] ?? '');
        // Use id_board_names as the meal plan code (e.g. "1", "9"); prefixed to form RP-{id}-B{board}
        $meal_plan = isset($rp['id_board_names'])
            ? 'B' . $rp['id_board_names']
            : 'RO';

        if ($hs_rp_id === '' || $name === '') {
            log_event(
                'WARNING',
                'sync_catalog: Skipping rate plan – missing id or name: ' . json_encode($rp)
            );
            continue;
        }

        $external_id = generate_rate_plan_external_id((int)$hs_rp_id, $meal_plan);
        $raw_json    = json_encode($rp, JSON_UNESCAPED_UNICODE);
        $new_hash    = hash_payload($rp);

        // Check whether this rate plan already exists in the local DB
        $stmt     = execute_query(
            $conn,
            'SELECT id, raw_data FROM rate_plans WHERE hs_rate_plan_id = ?',
            [$hs_rp_id],
            's'
        );
        $result   = $stmt->get_result();
        $existing = $result->fetch_assoc();
        $stmt->close();

        if ($existing !== null) {
            $stored_data = json_decode($existing['raw_data'], true) ?? [];
            $stored_hash = hash_payload($stored_data);

            if ($stored_hash === $new_hash) {
                $rp_unchanged++;
                continue;
            }

            execute_query(
                $conn,
                'UPDATE rate_plans SET external_id = ?, name = ?, meal_plan = ?, raw_data = ?
                 WHERE hs_rate_plan_id = ?',
                [$external_id, $name, $meal_plan, $raw_json, $hs_rp_id],
                'sssss'
            )->close();
            $rp_updated++;
            log_event('INFO', 'sync_catalog: Rate plan updated – ' . $external_id, null, $external_id);

        } else {
            execute_query(
                $conn,
                'INSERT INTO rate_plans (external_id, hs_rate_plan_id, name, meal_plan, raw_data)
                 VALUES (?, ?, ?, ?, ?)',
                [$external_id, $hs_rp_id, $name, $meal_plan, $raw_json],
                'sssss'
            )->close();
            $rp_inserted++;
            log_event('SUCCESS', 'sync_catalog: Rate plan inserted – ' . $external_id, null, $external_id);
        }

    } catch (RuntimeException $e) {
        log_event('ERROR', 'sync_catalog: Error processing rate plan: ' . $e->getMessage());
        echo '[ERROR] Rate plan processing error: ' . $e->getMessage() . PHP_EOL;
    }
}

if ($rp_inserted + $rp_updated + $rp_unchanged > 0) {
    echo "Rate plans synced: {$rp_inserted} inserted, {$rp_updated} updated, {$rp_unchanged} unchanged" . PHP_EOL;
}
log_event(
    'INFO',
    "sync_catalog: Rate plans complete – inserted={$rp_inserted} updated={$rp_updated} unchanged={$rp_unchanged}"
);

// ─── Done ─────────────────────────────────────────────────────────────────────

close_db_connection($conn);
echo PHP_EOL . '=== Catalog sync complete ===' . PHP_EOL;
log_event('SUCCESS', 'sync_catalog: Catalog sync complete');
