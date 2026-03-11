# Phase 4 – Invoice Generation: Test Plan

## Prerequisites

- Phases 0–3 complete and verified.
- At least one reservation in the `reservations` table with **status ≠ 'cancelled'**.
- MySQL running and `hotelsync` database accessible.

Find a valid reservation ID to use in tests:

```sql
SELECT id, hs_reservation_id, guest_name, status FROM reservations WHERE status != 'cancelled' LIMIT 5;
```

---

## Test 1 – Generate invoice for a valid reservation

**Run:**

```bash
php scripts/generate_invoice.php --reservation_id=<hs_reservation_id>
```

**Expected output:**

```
=== HotelSync Invoice Generation ===
Reservation ID: XXXXXX

Reservation found: id=N, hs_id=XXXXXX, status=confirmed
Line items: 1 room(s), N night(s), total 100.00 EUR
Generating invoice number...
Invoice number  : HS-INV-2026-000001
Sending invoice...
  Attempt 1: SUCCESS – invoice sent.

─────────────────────────────────────────
Invoice number : HS-INV-2026-000001
Guest          : John Doe
Arrival        : 2026-03-15
Departure      : 2026-03-18
Total amount   : 300.00 EUR
Final status   : sent
─────────────────────────────────────────

=== Invoice generation complete ===
```

**Verify in DB:**

```sql
SELECT invoice_number, guest_name, total_amount, status, retry_count
  FROM invoice_queue
 ORDER BY id DESC
 LIMIT 5;
```

Expected: one row with `status = 'sent'`, `retry_count = 0` or small number.

```sql
SELECT status FROM reservations WHERE hs_reservation_id = 'XXXXXX';
```

Expected: `status = 'invoiced'`

---

## Test 2 – Attempt invoice for a cancelled reservation

First, cancel a reservation manually or use a known cancelled one:

```sql
UPDATE reservations SET status = 'cancelled' WHERE hs_reservation_id = 'YYYYYY';
```

**Run:**

```bash
php scripts/generate_invoice.php --reservation_id=<cancelled_hs_id>
```

**Expected output:**

```
[ERROR] Cannot generate invoice for cancelled reservation.
```

Script exits with code 1. No row inserted into `invoice_queue`.

---

## Test 3 – Attempt invoice for non-existent reservation

```bash
php scripts/generate_invoice.php --reservation_id=999999999
```

**Expected output:**

```
[ERROR] Reservation 999999999 not found in local database.
        Run sync_reservations.php first to import it.
```

Script exits with code 1.

---

## Test 4 – Invoice numbering sequence (run twice)

Run the script for **two different valid reservations** (different `hs_reservation_id` values):

```bash
php scripts/generate_invoice.php --reservation_id=<id_1>
php scripts/generate_invoice.php --reservation_id=<id_2>
```

**Expected:** Invoice numbers increment by 1:

```
HS-INV-2026-000001
HS-INV-2026-000002
```

**Verify in DB:**

```sql
SELECT invoice_number, reservation_id, status
  FROM invoice_queue
 ORDER BY id;
```

---

## Test 5 – Duplicate invoice prevention

Run the script **twice for the same reservation**:

```bash
php scripts/generate_invoice.php --reservation_id=<hs_id>
php scripts/generate_invoice.php --reservation_id=<hs_id>
```

**Expected on second run:**

```
[WARNING] Invoice already exists for this reservation.
  Invoice number : HS-INV-2026-000001
  Status         : sent
```

Script exits with code 0. No duplicate row in `invoice_queue`.

---

## Test 6 – Force failure scenario and verify retry logic

Because the success rate is random (70 %), run the script multiple times until a failure
occurs naturally, **or** temporarily change the threshold in `src/helpers.php`:

```php
// Change 7 to 0 to force all failures:
$success = (rand(1, 10) <= 0);
```

**Run:**

```bash
php scripts/generate_invoice.php --reservation_id=<unused_id>
```

**Expected output (all 5 attempts fail):**

```
  Attempt 1: FAILED – retrying...
  Attempt 2: FAILED – retrying...
  Attempt 3: FAILED – retrying...
  Attempt 4: FAILED – retrying...
  Attempt 5: FAILED – retrying...
  All 5 attempts failed – invoice marked as 'failed'.
...
Final status   : failed
```

**Verify in DB:**

```sql
SELECT invoice_number, status, retry_count
  FROM invoice_queue
 ORDER BY id DESC
 LIMIT 1;
```

Expected: `status = 'failed'`, `retry_count = 5`.

**Check logs:**

```bash
tail -30 logs/app.log
```

Expected: 5 WARNING lines followed by one ERROR line for the invoice number.

---

## Test 7 – Verify line_items JSON content

```sql
SELECT invoice_number, line_items, total_amount
  FROM invoice_queue
 ORDER BY id DESC
 LIMIT 1;
```

The `line_items` column should contain a JSON array like:

```json
[
  {
    "room_name": "Deluxe Double",
    "quantity": 1,
    "nights": 3,
    "rate_per_night": 100.00,
    "total": 300.00
  }
]
```

`total_amount` should match the sum of all `total` values across line items.

---

## Test 8 – Missing argument validation

```bash
php scripts/generate_invoice.php
```

Expected:

```
[ERROR] --reservation_id is required.
```

```bash
php scripts/generate_invoice.php --reservation_id=abc
```

Expected:

```
[ERROR] --reservation_id must be numeric, got: abc
```
