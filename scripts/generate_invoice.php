<?php

/**
 * scripts/generate_invoice.php
 *
 * Phase 4 – Invoice Generation.
 *
 * Generates an invoice for a reservation stored in the local database,
 * inserts it into invoice_queue, and simulates a send attempt with
 * configurable retries.  Invoice numbers are generated race-condition-safe
 * using a DB transaction with SELECT … FOR UPDATE.
 *
 * Usage:
 *   php scripts/generate_invoice.php --reservation_id=XXXX
 *
 * The --reservation_id value is matched against both hs_reservation_id
 * (HotelSync ID) and the local auto-increment id.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../src/logger.php';
require_once __DIR__ . '/../src/db.php';
require_once __DIR__ . '/../src/helpers.php';

// ─── STEP 1 – Argument parsing ────────────────────────────────────────────────

/**
 * Prints CLI usage instructions and exits with code 1.
 *
 * @return never
 */
function usage(): void
{
    echo PHP_EOL;
    echo 'Usage:  php scripts/generate_invoice.php --reservation_id=XXXX' . PHP_EOL;
    echo PHP_EOL;
    echo 'Options:' . PHP_EOL;
    echo '  --reservation_id   HotelSync reservation ID or local DB id (numeric)' . PHP_EOL;
    echo PHP_EOL;
    echo 'Example:' . PHP_EOL;
    echo '  php scripts/generate_invoice.php --reservation_id=606308' . PHP_EOL;
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

// ─── Boot ─────────────────────────────────────────────────────────────────────

echo PHP_EOL . '=== HotelSync Invoice Generation ===' . PHP_EOL;
echo "Reservation ID: {$reservation_id}" . PHP_EOL . PHP_EOL;
log_event('INFO', "generate_invoice: Starting for reservation_id={$reservation_id}");

// ─── DB connection ─────────────────────────────────────────────────────────────

try {
    $conn = get_db_connection();
} catch (RuntimeException $e) {
    echo '[ERROR] Database connection failed: ' . $e->getMessage() . PHP_EOL;
    log_event('ERROR', 'generate_invoice: DB connection failed: ' . $e->getMessage());
    exit(1);
}

// ─── STEP 2 – Load reservation ────────────────────────────────────────────────

// Accept both hs_reservation_id and local auto-increment id
$stmt = execute_query(
    $conn,
    'SELECT id, hs_reservation_id, guest_name, arrival_date, departure_date, status
       FROM reservations
      WHERE hs_reservation_id = ? OR id = ?
      LIMIT 1',
    [(string)$reservation_id, (int)$reservation_id],
    'si'
);
$reservation = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ($reservation === null) {
    echo "[ERROR] Reservation {$reservation_id} not found in local database." . PHP_EOL;
    echo "        Run sync_reservations.php first to import it." . PHP_EOL;
    log_event('ERROR', "generate_invoice: Reservation {$reservation_id} not found in local DB");
    close_db_connection($conn);
    exit(1);
}

$local_id       = (int)$reservation['id'];
$hs_id          = (string)$reservation['hs_reservation_id'];
$guest_name     = (string)$reservation['guest_name'];
$arrival_date   = (string)$reservation['arrival_date'];
$departure_date = (string)$reservation['departure_date'];
$status         = (string)$reservation['status'];

echo "Reservation found: id={$local_id}, hs_id={$hs_id}, status={$status}" . PHP_EOL;

if ($status === 'cancelled') {
    echo PHP_EOL . '[ERROR] Cannot generate invoice for cancelled reservation.' . PHP_EOL;
    log_event(
        'ERROR',
        "generate_invoice: Cannot generate invoice for cancelled reservation id={$local_id}",
        $local_id,
        $hs_id
    );
    close_db_connection($conn);
    exit(1);
}

// ─── Check for existing invoice ───────────────────────────────────────────────

$existing_stmt = execute_query(
    $conn,
    'SELECT id, invoice_number, status FROM invoice_queue WHERE reservation_id = ? LIMIT 1',
    [$local_id],
    'i'
);
$existing_invoice = $existing_stmt->get_result()->fetch_assoc();
$existing_stmt->close();

if ($existing_invoice !== null) {
    echo PHP_EOL . '[WARNING] Invoice already exists for this reservation.' . PHP_EOL;
    echo "  Invoice number : {$existing_invoice['invoice_number']}" . PHP_EOL;
    echo "  Status         : {$existing_invoice['status']}" . PHP_EOL;
    log_event(
        'WARNING',
        "generate_invoice: Invoice already exists for reservation id={$local_id} – {$existing_invoice['invoice_number']}",
        $local_id,
        $hs_id
    );
    close_db_connection($conn);
    exit(0);
}

// ─── STEP 3 – Build invoice line items ────────────────────────────────────────

/**
 * Calculates the number of nights between two YYYY-MM-DD date strings.
 *
 * @param string $arrival   Arrival date (YYYY-MM-DD).
 * @param string $departure Departure date (YYYY-MM-DD).
 * @return int Number of nights (minimum 1).
 */
function calculate_nights(string $arrival, string $departure): int
{
    $diff = (int)round(
        (strtotime($departure) - strtotime($arrival)) / 86400
    );
    return max(1, $diff);
}

// Fetch rooms linked to this reservation (join with rooms table for name)
$rooms_stmt = execute_query(
    $conn,
    'SELECT r.name AS room_name, rr.quantity
       FROM reservation_rooms rr
       JOIN rooms r ON r.id = rr.room_id
      WHERE rr.reservation_id = ?',
    [$local_id],
    'i'
);
$rooms_result = $rooms_stmt->get_result();
$room_rows    = [];
while ($row = $rooms_result->fetch_assoc()) {
    $room_rows[] = $row;
}
$rooms_stmt->close();

$nights     = calculate_nights($arrival_date, $departure_date);
$line_items = [];
$total      = 0.00;

// Rate per night is a placeholder (100.00 EUR) – no pricing data is stored
// in the local schema.  Replace with real pricing source when available.
$rate_per_night = 100.00;

foreach ($room_rows as $room) {
    $room_total   = round($room['quantity'] * $nights * $rate_per_night, 2);
    $line_items[] = [
        'room_name'      => $room['room_name'],
        'quantity'       => (int)$room['quantity'],
        'nights'         => $nights,
        'rate_per_night' => $rate_per_night,
        'total'          => $room_total,
    ];
    $total += $room_total;
}

// Fallback: if no rooms linked, create a single line item from reservation data
if (empty($line_items)) {
    $room_total   = round(1 * $nights * $rate_per_night, 2);
    $line_items[] = [
        'room_name'      => 'Room (unlinked)',
        'quantity'       => 1,
        'nights'         => $nights,
        'rate_per_night' => $rate_per_night,
        'total'          => $room_total,
    ];
    $total += $room_total;
    log_event('WARNING', "generate_invoice: No linked rooms for reservation id={$local_id} – using fallback line item");
}

$total = round($total, 2);

echo "Line items: " . count($line_items) . " room(s), {$nights} night(s), total {$total} EUR" . PHP_EOL;
log_event(
    'INFO',
    "generate_invoice: Built " . count($line_items) . " line item(s), total={$total} EUR for id={$local_id}",
    $local_id,
    $hs_id
);

// ─── STEP 4 & 5 – Invoice number + insert (race-condition safe) ───────────────

$year           = (int)date('Y');
$line_items_json = json_encode($line_items, JSON_UNESCAPED_UNICODE);

echo "Generating invoice number..." . PHP_EOL;

try {
    // Begin transaction – all subsequent queries in this block are atomic
    if (!$conn->begin_transaction()) {
        throw new RuntimeException('Failed to start DB transaction: ' . $conn->error);
    }

    // Lock existing invoice rows for this year to prevent concurrent inserts
    // from picking the same sequence number (SELECT … FOR UPDATE)
    $lock_stmt = execute_query(
        $conn,
        "SELECT COALESCE(
             MAX(CAST(SUBSTRING_INDEX(invoice_number, '-', -1) AS UNSIGNED)),
             0
         ) AS max_seq
           FROM invoice_queue
          WHERE invoice_number LIKE ?
          FOR UPDATE",
        ["HS-INV-{$year}-%"],
        's'
    );
    $lock_row  = $lock_stmt->get_result()->fetch_assoc();
    $lock_stmt->close();

    $next_seq      = (int)$lock_row['max_seq'] + 1;
    $invoice_number = generate_invoice_number($year, $next_seq);

    // Insert the new invoice record within the same transaction
    $insert_stmt = execute_query(
        $conn,
        'INSERT INTO invoice_queue
             (invoice_number, reservation_id, guest_name, arrival_date, departure_date,
              line_items, total_amount, currency, status, retry_count)
         VALUES (?, ?, ?, ?, ?, ?, ?, \'EUR\', \'pending\', 0)',
        [$invoice_number, $local_id, $guest_name, $arrival_date, $departure_date,
         $line_items_json, $total],
        'sissssd'
    );
    $invoice_db_id = (int)$conn->insert_id;
    $insert_stmt->close();

    $conn->commit();

    echo "Invoice number  : {$invoice_number}" . PHP_EOL;
    log_event(
        'SUCCESS',
        "generate_invoice: Invoice created – {$invoice_number} (db_id={$invoice_db_id})",
        $local_id,
        $hs_id
    );
} catch (RuntimeException $e) {
    $conn->rollback();
    echo '[ERROR] Failed to generate invoice: ' . $e->getMessage() . PHP_EOL;
    log_event('ERROR', "generate_invoice: Transaction failed for id={$local_id}: " . $e->getMessage(), $local_id, $hs_id);
    close_db_connection($conn);
    exit(1);
}

// Update reservation status to 'invoiced'
try {
    execute_query(
        $conn,
        "UPDATE reservations SET status = 'invoiced', updated_at = NOW() WHERE id = ?",
        [$local_id],
        'i'
    )->close();
    log_event('INFO', "generate_invoice: Reservation id={$local_id} status set to 'invoiced'", $local_id, $hs_id);
} catch (RuntimeException $e) {
    // Non-fatal: invoice is already inserted; just log the warning
    echo '[WARNING] Could not update reservation status: ' . $e->getMessage() . PHP_EOL;
    log_event('WARNING', "generate_invoice: Could not set reservation invoiced status: " . $e->getMessage());
}

// ─── STEP 6 – Simulate send attempt ──────────────────────────────────────────

echo "Sending invoice..." . PHP_EOL;
log_event('INFO', "generate_invoice: Starting send simulation for {$invoice_number}");

$final_status = attempt_invoice_send($conn, $invoice_db_id, $invoice_number);

// ─── STEP 7 – Output ──────────────────────────────────────────────────────────

close_db_connection($conn);

echo PHP_EOL;
echo '─────────────────────────────────────────' . PHP_EOL;
echo "Invoice number : {$invoice_number}" . PHP_EOL;
echo "Guest          : {$guest_name}" . PHP_EOL;
echo "Arrival        : {$arrival_date}" . PHP_EOL;
echo "Departure      : {$departure_date}" . PHP_EOL;
echo "Total amount   : {$total} EUR" . PHP_EOL;
echo "Final status   : {$final_status}" . PHP_EOL;
echo '─────────────────────────────────────────' . PHP_EOL;
echo PHP_EOL . '=== Invoice generation complete ===' . PHP_EOL;
