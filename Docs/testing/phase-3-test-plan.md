# Phase 3 – Reservation Update & Cancellation – Test Plan

## Overview

`scripts/update_reservation.php` fetches a single reservation from HotelSync,
compares it with the local DB record, and applies any changes.  Cancellations
are soft-deletes: the row stays in the `reservations` table with
`status = 'cancelled'`.  Every change is written to `audit_log`.

---

## Pre-conditions

- Phase 1 (catalog sync) and Phase 2 (reservation import) completed.
- At least one reservation exists in the local `reservations` table.
- Run the following query to list available reservation IDs:

```sql
SELECT id, hs_reservation_id, guest_name, arrival_date, status
FROM reservations
ORDER BY id;
```

---

## Test Cases

### TC-01 – Missing argument

```bash
php scripts/update_reservation.php
```

**Expected:**
- Prints `[ERROR] --reservation_id is required.`
- Prints usage help
- Exits with code 1

---

### TC-02 – Non-numeric reservation ID

```bash
php scripts/update_reservation.php --reservation_id=abc123
```

**Expected:**
- Prints `[ERROR] --reservation_id must be numeric`
- Exits with code 1

---

### TC-03 – Reservation not found in HotelSync

Use an ID that does not exist in HotelSync (e.g. `--reservation_id=1`):

```bash
php scripts/update_reservation.php --reservation_id=1
```

**Expected:**
- Prints `[ERROR] Reservation 1 not found in HotelSync.`
- Logs `ERROR: update_reservation: Reservation not found in HotelSync`
- Exits with code 1

---

### TC-04 – Reservation exists in API but not in local DB

Use a valid HotelSync reservation ID that was never imported locally:

```bash
php scripts/update_reservation.php --reservation_id=<VALID_HS_ID_NOT_IN_DB>
```

**Expected:**
- Prints `[WARNING] Reservation XXXX not found in local DB.`
- Advises running `sync_reservations.php` first
- Exits with code 0 (non-fatal)

---

### TC-05 – No changes detected (idempotency check)

Run the update on a reservation that was recently imported (hash will match):

```bash
php scripts/update_reservation.php --reservation_id=<KNOWN_GOOD_ID>
```

**Expected:**
- Prints `No changes detected for reservation XXXX.`
- Logs `INFO: No changes detected`
- No row written to `audit_log`
- Exits with code 0

Run the same command a second time → same result.

---

### TC-06 – Changes detected and applied

To trigger this test, manually modify a field in the local DB and then run
the script (the API hash will differ from the modified stored hash):

```sql
-- Force a hash mismatch by clearing the stored hash
UPDATE reservations
SET payload_hash = 'fake_hash_to_force_update'
WHERE hs_reservation_id = '<KNOWN_GOOD_ID>';
```

Then run:

```bash
php scripts/update_reservation.php --reservation_id=<KNOWN_GOOD_ID>
```

**Expected:**
- Prints `Changes detected – updating local record...`
- Prints `Reservation XXXX updated successfully.`
- Verify in DB:

```sql
SELECT id, guest_name, arrival_date, status, payload_hash, updated_at
FROM reservations
WHERE hs_reservation_id = '<KNOWN_GOOD_ID>';
```

- Verify audit log entry:

```sql
SELECT id, reservation_id, event_type, created_at
FROM audit_log
WHERE reservation_id = (
    SELECT id FROM reservations WHERE hs_reservation_id = '<KNOWN_GOOD_ID>'
)
ORDER BY id DESC
LIMIT 5;
```

Expected: one new row with `event_type = 'updated'`, `old_data` containing
previous JSON, `new_data` containing fresh API JSON.

---

### TC-07 – Cancellation via HotelSync UI

#### How to cancel a reservation in HotelSync

1. Log in to the HotelSync web app.
2. Navigate to **Reservations**.
3. Open the reservation you want to cancel.
4. Click **Cancel Reservation** (or the equivalent button in the UI).
5. Confirm the cancellation.
6. Note the `id_reservations` value from the reservation detail URL or UI.

> **Note for test accounts:** Some demo/test accounts may have cancellation
> restricted or require a specific workflow.  If cancellation is unavailable,
> use the manual hash-mismatch method (TC-06) with a `status` set to
> `cancelled` in a mock response, or use TC-07b below.

#### TC-07b – Simulate cancellation via DB (if UI not available)

```sql
-- Record the current payload hash first
SELECT hs_reservation_id, payload_hash FROM reservations LIMIT 1;

-- Force a hash mismatch AND pre-set a cancelled raw_data stub is NOT needed –
-- the script reads live from API.  Instead, just clear the hash so the
-- script re-evaluates the latest API status.
UPDATE reservations
SET payload_hash = 'simulate_change'
WHERE hs_reservation_id = '<ID>';
```

Then if the API now returns `status: "cancelled"` for that reservation, the
script will detect and record the cancellation.

---

### TC-08 – Verify soft-delete (no DB row removed)

After a cancellation (TC-07):

```bash
php scripts/update_reservation.php --reservation_id=<CANCELLED_ID>
```

**Expected:**
- Prints `Reservation XXXX marked as cancelled.`
- Prints `Record retained in DB (soft-delete). Audit log entry written.`

Verify the row still exists:

```sql
SELECT id, hs_reservation_id, status FROM reservations
WHERE hs_reservation_id = '<CANCELLED_ID>';
```

Expected: row present with `status = 'cancelled'`.

Verify no DELETE occurred:

```sql
SELECT COUNT(*) FROM reservations WHERE hs_reservation_id = '<CANCELLED_ID>';
```

Expected: `1`.

---

### TC-09 – Audit log inspection

```sql
SELECT
    al.id,
    al.reservation_id,
    al.event_type,
    r.hs_reservation_id,
    r.guest_name,
    al.created_at
FROM audit_log al
JOIN reservations r ON r.id = al.reservation_id
ORDER BY al.id DESC
LIMIT 10;
```

**Expected:** Rows with `event_type` = `updated` or `cancelled`, linked to
the correct `reservation_id`.

To inspect the full JSON diff:

```sql
SELECT
    event_type,
    JSON_UNQUOTE(JSON_EXTRACT(old_data, '$.status')) AS old_status,
    JSON_UNQUOTE(JSON_EXTRACT(new_data, '$.status')) AS new_status,
    created_at
FROM audit_log
WHERE reservation_id = <LOCAL_ID>
ORDER BY id DESC;
```

---

### TC-10 – Idempotency after cancellation

Run the script again on the same cancelled reservation:

```bash
php scripts/update_reservation.php --reservation_id=<CANCELLED_ID>
```

**Expected:**
- Hash now matches (API still returns same cancelled payload)
- Prints `No changes detected`
- No duplicate `audit_log` row written

---

## Verify Log File

All operations are logged to `logs/app.log`.  Check recent entries:

```bash
tail -30 logs/app.log
```

Expected log lines for a successful update:
```
[SUCCESS] API login successful
[INFO]    Fetching hs_reservation_id=XXXX from API
[SUCCESS] Fetched hs_reservation_id=XXXX from API
[INFO]    Changes detected for hs_reservation_id=XXXX
[SUCCESS] reservations table updated – local_id=N status=confirmed
[SUCCESS] audit_log written – local_id=N event=updated
[SUCCESS] Reservation XXXX updated
```

Expected log lines for a cancellation:
```
[SUCCESS] reservations table updated – local_id=N status=cancelled
[SUCCESS] audit_log written – local_id=N event=cancelled
[SUCCESS] Reservation XXXX cancelled (soft-delete)
```
