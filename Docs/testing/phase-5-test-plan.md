# Phase 5 – Webhook Endpoint: Test Plan

## Prerequisites

- Phases 0–4 complete and verified.
- MySQL running with `hotelsync` database accessible.
- PHP CLI available.
- Postman (or curl) installed for sending HTTP requests.

---

## Starting the Local PHP Server

```bash
php -S localhost:8000 -t public
```

Leave this running in a separate terminal. The webhook endpoint is available at:

```
POST http://localhost:8000/webhooks/otasync.php
```

---

## Postman Setup

1. Open Postman → **New Request**
2. Set method to **POST**
3. URL: `http://localhost:8000/webhooks/otasync.php`
4. Headers tab → Add:
   - `Content-Type: application/json`
5. Body tab → select **raw** → type **JSON**
6. Paste one of the example payloads from the test cases below

---

## Example JSON Payloads

### reservation.created

```json
{
  "event_type": "reservation.created",
  "reservation": {
    "id_reservations": "999001",
    "first_name": "Ivan",
    "last_name": "Horvat",
    "date_arrival": "2026-05-01",
    "date_departure": "2026-05-05",
    "status": "confirmed",
    "id_pricing_plans": "31432",
    "rooms": [
      { "id_room_types": "10001", "quantity": 1 }
    ]
  }
}
```

> Adjust `id_room_types` and `id_pricing_plans` to match values already in your
> `rooms` and `rate_plans` tables (check with `SELECT hs_room_id FROM rooms;`).

---

### reservation.updated

Same structure as `reservation.created` but with `event_type` changed and
at least one field value modified (e.g. `date_departure` extended by one day):

```json
{
  "event_type": "reservation.updated",
  "reservation": {
    "id_reservations": "999001",
    "first_name": "Ivan",
    "last_name": "Horvat",
    "date_arrival": "2026-05-01",
    "date_departure": "2026-05-06",
    "status": "confirmed",
    "id_pricing_plans": "31432",
    "rooms": [
      { "id_room_types": "10001", "quantity": 1 }
    ]
  }
}
```

---

### reservation.cancelled

```json
{
  "event_type": "reservation.cancelled",
  "reservation": {
    "id_reservations": "999001",
    "status": "cancelled"
  }
}
```

---

## Test Case 1 – Send reservation.created

**Action:** POST the `reservation.created` payload above.

**Expected HTTP response:** `200 OK`

```json
{ "status": "ok", "event_type": "reservation.created" }
```

**Verify in DB:**

```sql
-- Reservation inserted
SELECT id, hs_reservation_id, guest_name, arrival_date, departure_date, status
  FROM reservations
 WHERE hs_reservation_id = '999001';

-- Webhook event recorded and marked processed
SELECT id, event_type, processed
  FROM webhook_events
 ORDER BY id DESC
 LIMIT 3;

-- Room linked (if id_room_types matched)
SELECT rr.reservation_id, r.name AS room_name, rr.quantity
  FROM reservation_rooms rr
  JOIN rooms r ON r.id = rr.room_id
  JOIN reservations res ON res.id = rr.reservation_id
 WHERE res.hs_reservation_id = '999001';
```

---

## Test Case 2 – Send same webhook again (idempotency)

**Action:** POST the **exact same** `reservation.created` payload again (no changes).

**Expected HTTP response:** `200 OK`

```json
{ "status": "already_processed" }
```

**Verify:** No new row in `reservations`. No new `webhook_events` row for this hash.
The existing `webhook_events` row still has `processed = 1`.

```sql
SELECT COUNT(*) FROM reservations WHERE hs_reservation_id = '999001';
-- Must be 1 (not 2)

SELECT COUNT(*) FROM webhook_events WHERE event_type = 'reservation.created';
-- Must be 1 (not 2)
```

---

## Test Case 3 – Send reservation.updated

**Action:** POST the `reservation.updated` payload (departure date changed to 2026-05-06).

**Expected HTTP response:** `200 OK`

```json
{ "status": "ok", "event_type": "reservation.updated" }
```

**Verify:**

```sql
-- Departure date updated
SELECT departure_date FROM reservations WHERE hs_reservation_id = '999001';
-- Expected: 2026-05-06

-- audit_log entry written
SELECT reservation_id, event_type, created_at
  FROM audit_log
 WHERE reservation_id = (SELECT id FROM reservations WHERE hs_reservation_id = '999001')
 ORDER BY id DESC
 LIMIT 3;
-- Expected: one row with event_type = 'reservation.updated'
```

**Send the same updated payload again** (no further changes):

```json
{ "status": "already_processed" }
```

Because the payload hash is the same as before, it is deduplicated.

---

## Test Case 4 – Send reservation.cancelled

**Action:** POST the `reservation.cancelled` payload.

**Expected HTTP response:** `200 OK`

```json
{ "status": "ok", "event_type": "reservation.cancelled" }
```

**Verify:**

```sql
-- Status is now cancelled (NOT deleted)
SELECT id, status FROM reservations WHERE hs_reservation_id = '999001';
-- Expected: status = 'cancelled'

-- Soft-delete: record still exists
SELECT COUNT(*) FROM reservations WHERE hs_reservation_id = '999001';
-- Expected: 1

-- audit_log entry written
SELECT event_type, created_at
  FROM audit_log
 WHERE reservation_id = (SELECT id FROM reservations WHERE hs_reservation_id = '999001')
 ORDER BY id DESC
 LIMIT 1;
-- Expected: event_type = 'cancelled'
```

---

## Test Case 5 – Invalid JSON payload

**Action:** POST raw text that is not valid JSON:

```
not-json-at-all
```

**Expected HTTP response:** `400 Bad Request`

```json
{ "status": "error", "message": "Invalid JSON payload." }
```

---

## Test Case 6 – Wrong HTTP method

**Action:** Send a **GET** request to the endpoint (use Postman or curl):

```bash
curl http://localhost:8000/webhooks/otasync.php
```

**Expected HTTP response:** `405 Method Not Allowed`

```json
{ "status": "error", "message": "Method Not Allowed. POST required." }
```

---

## Test Case 7 – Unknown event type

```json
{
  "event_type": "some.unknown.event",
  "reservation": { "id_reservations": "999999" }
}
```

**Expected HTTP response:** `200 OK`

```json
{ "status": "ok", "event_type": "some.unknown.event" }
```

Event is recorded in `webhook_events` with `processed = 1`, but no handler runs.

```sql
SELECT event_type, processed FROM webhook_events WHERE event_type = 'some.unknown.event';
```

---

## Verifying Logs

```bash
tail -50 logs/app.log
```

Each successful webhook processing produces:
- `[INFO] webhook: Processing event type='...'`
- `[SUCCESS] webhook: Reservation inserted / updated / cancelled ...`
- `[INFO] webhook: Event id=N marked as processed`
- `[SUCCESS] webhook: Event '...' completed successfully`
