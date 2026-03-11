# Phase 2 Test Plan – Reservation Import

**Tester level:** Non-technical (no PHP or MySQL knowledge required)
**Estimated time:** 30–45 minutes
**Goal:** Confirm that `sync_reservations.php` successfully fetches reservations
from the HotelSync API within a date range, inserts new records into the local
database, maps rooms and rate plans correctly, and skips duplicates on re-run.

---

## Prerequisites

Before running any tests:

- Phase 0 **passed** (tables exist, API login works)
- Phase 1 **passed** (`rooms` table has at least 1 row; `rate_plans` table may be empty)
- MySQL is running and the `hotelsync` database is accessible
- Terminal is open in the project root folder

---

## Step 1 – Verify the date range contains reservations

Before running the import, confirm which date range to use.

> **Tip:** Start with a wide range such as `2025-01-01` → `2026-12-31` to make
> sure you capture any reservations that exist on the test property.

---

## Step 2 – Run the sync script for the first time

```
php scripts/sync_reservations.php --from=2025-01-01 --to=2026-12-31
```

**Expected output (when reservations exist):**

```
=== HotelSync Reservation Sync ===
Date range: 2025-01-01 → 2026-12-31

Login: OK
Fetching reservations from API...
Reservations fetched from API: N

--- Processing reservations ---

Reservations imported: N new, 0 skipped (already exist)
Warnings: 0 rooms/rate plans not found in local catalog

=== Reservation sync complete ===
```

**Expected output (when no reservations exist in that range):**

```
=== HotelSync Reservation Sync ===
Date range: 2025-01-01 → 2026-12-31

Login: OK
Fetching reservations from API...
Reservations fetched from API: 0

--- Processing reservations ---

Reservations imported: 0 new, 0 skipped (already exist)
Warnings: 0 rooms/rate plans not found in local catalog

=== Reservation sync complete ===
```

> A result of `0 new` with no `[ERROR]` lines is still a **pass** – the API
> returned no reservations for that property/range, which is valid.

**Pass criteria:**
- No `[ERROR]` lines appear
- `Login: OK` is printed
- The script reaches `=== Reservation sync complete ===`

**If you see `[ERROR] Both --from and --to are required`:**
Re-run the command with both flags included.

**If you see `[ERROR] Login failed`:**
Check `API_USERNAME`, `API_PASSWORD`, and `API_TOKEN` in `config/config.php`.

---

## Step 3 – Test argument validation

### 3a – Missing arguments

```
php scripts/sync_reservations.php
```

**Expected output:** Error message + usage instructions printed; script exits.

```
[ERROR] Both --from and --to are required.

Usage:  php scripts/sync_reservations.php --from=YYYY-MM-DD --to=YYYY-MM-DD
...
```

### 3b – Invalid date format

```
php scripts/sync_reservations.php --from=01-01-2026 --to=2026-01-31
```

**Expected output:**

```
[ERROR] --from value '01-01-2026' is not a valid YYYY-MM-DD date.
```

### 3c – `from` later than `to`

```
php scripts/sync_reservations.php --from=2026-12-31 --to=2026-01-01
```

**Expected output:**

```
[ERROR] --from (2026-12-31) must not be later than --to (2026-01-01).
```

**Pass criteria for Step 3:** All three error messages appear as expected; no
`[SUCCESS]` or database interaction occurs.

---

## Step 4 – Verify reservations in the database

_(Skip this step if the API returned 0 reservations.)_

```sql
SELECT id, lock_id, hs_reservation_id, guest_name, arrival_date, departure_date, status, payload_hash, created_at
FROM reservations
ORDER BY id;
```

**Expected result:**

- At least one row exists
- `lock_id` follows the format `LOCK-{hs_reservation_id}-{YYYY-MM-DD}`
  (e.g. `LOCK-5001-2026-03-15`)
- `hs_reservation_id` matches the ID returned by the HotelSync API
- `guest_name` is not empty (may show `Unknown Guest` if the API field was absent)
- `arrival_date` and `departure_date` are valid dates
- `status` is a non-empty string (e.g. `new`, `confirmed`, `cancelled`)
- `payload_hash` is a 64-character hex string (not `NULL`)

---

## Step 5 – Verify raw_data is stored

```sql
SELECT id, guest_name, raw_data
FROM reservations
LIMIT 1;
```

**Expected result:**

- `raw_data` column is not `NULL`
- It contains a JSON string representing the full API response for that reservation
  (e.g. `{"id_reservations": "5001", "guest_name": "John Doe", ...}`)

---

## Step 6 – Verify room mappings

```sql
SELECT
    rr.id,
    r.hs_reservation_id,
    rm.hs_room_id,
    rm.name        AS room_name,
    rr.quantity
FROM reservation_rooms rr
JOIN reservations r  ON r.id  = rr.reservation_id
JOIN rooms        rm ON rm.id = rr.room_id
ORDER BY rr.id;
```

**Expected result (when reservations include room data):**

- At least one row exists per reservation that has rooms
- `hs_room_id` in this result matches a value in the `rooms` table
- `room_name` shows the human-readable name synced during Phase 1
- `quantity` is at least `1`

> **If no rows appear:** The API may not return room associations at the
> reservation level, or the test property has no room data on its reservations.
> Check `logs/app.log` for `[WARNING] Room hs_room_id=… not found` entries.

---

## Step 7 – Verify rate plan mappings

```sql
SELECT
    rrp.id,
    r.hs_reservation_id,
    rp.hs_rate_plan_id,
    rp.name       AS rate_plan_name,
    rp.meal_plan
FROM reservation_rate_plans rrp
JOIN reservations r   ON r.id  = rrp.reservation_id
JOIN rate_plans   rp  ON rp.id = rrp.rate_plan_id
ORDER BY rrp.id;
```

**Expected result (when reservations include rate plan data):**

- At least one row exists per reservation that has rate plans
- `hs_rate_plan_id` matches a value in the `rate_plans` table
- `rate_plan_name` and `meal_plan` are non-empty

> **If no rows appear AND rate_plans is empty:** The OTASync test property may
> not have pricing plans configured. This is expected; no warning is needed.
> Check `logs/app.log` for `[WARNING] Rate plan hs_rate_plan_id=… not found`.

---

## Step 8 – Run the sync script a second time (idempotency test)

Run the identical command again without clearing the database:

```
php scripts/sync_reservations.php --from=2025-01-01 --to=2026-12-31
```

**Expected output:**

```
Reservations imported: 0 new, N skipped (already exist)
Warnings: 0 rooms/rate plans not found in local catalog
```

**Pass criteria:**
- `new` count is `0`
- `skipped` count equals the `new` count from Step 2
- Row count in `reservations` has not changed since Step 2

This confirms deduplication via `hs_reservation_id` is working correctly.

---

## Step 9 – Check the log file

Open `logs/app.log` in any text editor and look at entries added by this run.

**Expected entries (example):**

```
[2026-03-10 23:00:00] [INFO]    sync_reservations: Starting import for 2025-01-01 → 2026-12-31
[2026-03-10 23:00:00] [INFO]    API login attempt for user: marko00djokic@gmail.com
[2026-03-10 23:00:00] [SUCCESS] API login successful. Properties available: 1
[2026-03-10 23:00:00] [SUCCESS] sync_reservations: API login successful
[2026-03-10 23:00:00] [INFO]    Database connection established.
[2026-03-10 23:00:00] [INFO]    sync_reservations: Fetching reservations from API
[2026-03-10 23:00:01] [INFO]    API POST https://app.otasync.me/api/reservation/data/reservations
[2026-03-10 23:00:01] [SUCCESS] API response HTTP 200 from POST ...reservations
[2026-03-10 23:00:01] [INFO]    sync_reservations: Page 1/1 – 3 records
[2026-03-10 23:00:01] [INFO]    sync_reservations: Total reservations fetched: 3
[2026-03-10 23:00:01] [INFO]    sync_reservations: Catalog maps built – rooms: 3, rate plans: 2
[2026-03-10 23:00:01] [SUCCESS] sync_reservations: Reservation inserted – local_id=1 hs_id=5001 guest=John Doe
[2026-03-10 23:00:01] [INFO]    sync_reservations: Room linked – reservation 5001 → hs_room_id=34152
[2026-03-10 23:00:01] [INFO]    sync_reservations: Rate plan linked – reservation 5001 → hs_rp_id=31432
...
[2026-03-10 23:00:01] [SUCCESS] sync_reservations: Complete – imported=3 skipped=0 warnings=0
[2026-03-10 23:00:01] [INFO]    Database connection closed.
```

**Pass criteria:**

- No `[ERROR]` entries
- Each new reservation has a `[SUCCESS] Reservation inserted` line
- Room/rate plan links appear as `[INFO] … linked` lines
- The final `[SUCCESS] sync_reservations: Complete` line is present
- Second run shows `[INFO] Skipping hs_reservation_id=…` for every reservation

---

## Step 10 – Verify payload_hash enables change detection (optional, advanced)

This step confirms the `payload_hash` column is populated and will be useful in Phase 3.

1. Note the `payload_hash` value of one reservation:
   ```sql
   SELECT id, hs_reservation_id, payload_hash FROM reservations LIMIT 1;
   ```

2. Manually corrupt `raw_data` to simulate a stale record:
   ```sql
   UPDATE reservations SET raw_data = '{"id_reservations":"5001"}' WHERE id = 1;
   ```

3. Recompute what the hash _should_ be vs what is stored:
   - The stored `payload_hash` should still be the original SHA-256.
   - This mismatch is what Phase 3's update logic will detect.

4. Restore the correct data by re-running the import after deleting the corrupted row:
   ```sql
   DELETE FROM reservation_rooms     WHERE reservation_id = 1;
   DELETE FROM reservation_rate_plans WHERE reservation_id = 1;
   DELETE FROM reservations          WHERE id = 1;
   ```
   Then run the sync again – the reservation should be re-imported cleanly.

---

## Pass Criteria Summary

Phase 2 is **PASSED** when ALL of the following are true:

- [ ] `sync_reservations.php --from=… --to=…` runs without `[ERROR]` lines and prints `=== Reservation sync complete ===`
- [ ] Invalid / missing arguments produce clear error messages and usage instructions (Step 3)
- [ ] `SELECT * FROM reservations` returns rows with correct `lock_id`, `hs_reservation_id`, `guest_name`, dates, `status`, non-null `payload_hash`, and non-null `raw_data`
- [ ] `SELECT * FROM reservation_rooms` has rows linking reservations to local room IDs (or log shows `[WARNING]` if the API returns no room data)
- [ ] `SELECT * FROM reservation_rate_plans` has rows linking reservations to local rate plan IDs (or log shows `[WARNING]` if the property has no pricing plans)
- [ ] Second run: `0 new, N skipped` – no duplicate rows created
- [ ] `logs/app.log` has no `[ERROR]` entries; final line is `sync_reservations: Complete`

**Phase 2 is complete. Proceed to Phase 3.**
