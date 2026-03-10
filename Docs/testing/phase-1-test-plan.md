# Phase 1 Test Plan – Authentication & Catalog Sync

**Tester level:** Non-technical (no PHP or MySQL knowledge required)
**Estimated time:** 20–30 minutes
**Goal:** Confirm that `sync_catalog.php` successfully logs in to the HotelSync
API, fetches rooms and rate plans, and stores them in the local database.

---

## Prerequisites

Before running any tests:

- Phase 0 must be **fully passed** (all tables exist, API login works)
- MySQL is running and the `hotelsync` database is accessible
- Terminal is open in the project root folder

---

## Step 1 – Run the sync script for the first time

Open a terminal in the project root folder and run:

```
php scripts/sync_catalog.php
```

**Expected output:**

```
=== HotelSync Catalog Sync ===
Login: OK

--- Rooms ---
Rooms synced: X inserted, 0 updated, 0 unchanged

--- Rate Plans ---
Rate plans synced: Y inserted, 0 updated, 0 unchanged

=== Catalog sync complete ===
```

Where `X` is the number of rooms returned by the API (at least 1) and `Y` is
the number of rate plans returned by the API.

> **Note – Rate plans on the test property:** The OTASync test property used
> during development does not have any pricing plans configured, so the rate
> plans section prints a different message:
> ```
> Rate plans: none found in API (property may not have pricing plans configured).
> ```
> This is **correct behaviour** – the script found the endpoint, received an
> empty response, and reported it cleanly. The `rate_plans` table will be empty.
> The step is still considered **passed** as long as no `[ERROR]` line appears.

**Pass criteria:**
- No `[ERROR]` lines appear
- `Login: OK` is printed
- Rooms line appears with `inserted > 0`
- Rate plans line shows either `inserted > 0` OR the "none found" notice

**If you see `[ERROR] Login failed`:**
Check `API_USERNAME`, `API_PASSWORD`, and `API_TOKEN` in `config/config.php`.

**If you see `[ERROR] Failed to fetch rooms`:**
The API endpoint or session key may be invalid. Check `logs/app.log` for details.

---

## Step 2 – Verify rooms in the database

Open MySQL (phpMyAdmin, MySQL Workbench, VS Code MySQL extension, or terminal):

```sql
SELECT id, external_id, hs_room_id, name, slug, created_at
FROM rooms
ORDER BY id;
```

**Expected result:**
- At least one row exists
- `external_id` values follow the format `HS-{id}-{slug}` (e.g. `HS-34152-double-room-with-sea-view`)
- `hs_room_id` contains numeric string IDs from the HotelSync API
- `name` contains human-readable room names
- `slug` is lowercase with hyphens, no special characters

**Actual rows on the test property:**

| id | external_id                             | hs_room_id | name                           |
|----|-----------------------------------------|------------|--------------------------------|
| 1  | HS-34152-double-room-with-sea-view      | 34152      | Double Room with Sea View      |
| 2  | HS-34153-twin-room-with-garden-view     | 34153      | Twin Room with Garden View     |
| 3  | HS-34154-deluxe-suite-with-private-pool | 34154      | Deluxe Suite with Private Pool |

---

## Step 3 – Verify rate plans in the database

```sql
SELECT id, external_id, hs_rate_plan_id, name, meal_plan, created_at
FROM rate_plans
ORDER BY id;
```

**Expected result (when pricing plans are configured in OTASync):**
- At least one row exists
- `external_id` values follow the format `RP-{id}-{meal_plan}` (e.g. `RP-7-BB`)
- `meal_plan` is an uppercase code such as `BB`, `HB`, `FB`, `AI`, or `RO`

**Example rows (when data exists):**

| id | external_id | hs_rate_plan_id | name            | meal_plan |
|----|-------------|-----------------|-----------------|-----------|
| 1  | RP-7-BB     | 7               | Bed & Breakfast | BB        |
| 2  | RP-8-AI     | 8               | All Inclusive   | AI        |

> **Note:** If the OTASync property has no pricing plans, this table will be
> empty. That is expected — it is not a failure. The sync script logs a
> `[WARNING]` entry in `logs/app.log` instead of inserting rows.

---

## Step 4 – Verify raw_data is stored

```sql
SELECT id, name, raw_data
FROM rooms
LIMIT 1;
```

**Expected result:**
- The `raw_data` column contains a JSON string (not `NULL`)
- It looks like `{"id_room_types": "34152", "name": "Double Room with Sea View", ...}`

If rate plans were synced, run the same check for them:

```sql
SELECT id, name, raw_data
FROM rate_plans
LIMIT 1;
```

---

## Step 5 – Run the sync script a second time (idempotency test)

Run the same command again without making any changes:

```
php scripts/sync_catalog.php
```

**Expected output:**

```
=== HotelSync Catalog Sync ===
Login: OK

--- Rooms ---
Rooms synced: 0 inserted, 0 updated, X unchanged

--- Rate Plans ---
Rate plans synced: 0 inserted, 0 updated, Y unchanged

=== Catalog sync complete ===
```

> **Note:** If the test property has no pricing plans, the rate plans line will
> again show `Rate plans: none found in API (...)`. That is still correct — the
> script is not skipping the check; the API genuinely returns no data.

**Pass criteria:**
- Rooms: `inserted` and `updated` are both `0`; `unchanged` equals the count from Step 1
- Rate plans: either `0 inserted, 0 updated, N unchanged` or the "none found" notice
- Row counts in `rooms` have not changed since Step 1

This confirms the change-detection (payload hash comparison) is working correctly.

---

## Step 6 – Check the log file

Open `logs/app.log` in any text editor and look at the entries added by this run.

**Expected entries (most recent at the bottom):**

```
[2026-03-10 22:04:56] [INFO]    sync_catalog: Starting catalog sync
[2026-03-10 22:04:56] [INFO]    API login attempt for user: marko00djokic@gmail.com
[2026-03-10 22:04:56] [SUCCESS] API login successful. Properties available: 1
[2026-03-10 22:04:56] [SUCCESS] sync_catalog: API login successful
[2026-03-10 22:04:56] [INFO]    Database connection established.
[2026-03-10 22:04:56] [INFO]    sync_catalog: Fetching rooms from API
[2026-03-10 22:04:56] [INFO]    API POST https://app.otasync.me/api/room/data/rooms
[2026-03-10 22:04:57] [SUCCESS] API response HTTP 200 from POST https://app.otasync.me/api/room/data/rooms
[2026-03-10 22:04:57] [INFO]    sync_catalog: Rooms received from API: 3
[2026-03-10 22:04:57] [INFO]    sync_catalog: Room updated – HS-34152-... | ext_id=HS-34152-...
...
[2026-03-10 22:04:57] [INFO]    sync_catalog: Fetching rate plans from API
[2026-03-10 22:04:57] [INFO]    API POST https://app.otasync.me/api/room/data/pricing_plans
[2026-03-10 22:04:57] [SUCCESS] API response HTTP 200 from POST https://app.otasync.me/api/room/data/pricing_plans
[2026-03-10 22:04:57] [INFO]    API returned empty body from POST ...pricing_plans – treating as empty array
[2026-03-10 22:04:57] [INFO]    sync_catalog: Rate plans received from API: 0
[2026-03-10 22:04:57] [WARNING] sync_catalog: No rate plans returned by API – skipping rate plan sync
[2026-03-10 22:04:57] [INFO]    sync_catalog: Rate plans complete – inserted=0 updated=0 unchanged=0
[2026-03-10 22:04:57] [INFO]    Database connection closed.
[2026-03-10 22:04:57] [SUCCESS] sync_catalog: Catalog sync complete
```

**Pass criteria:**
- No `[ERROR]` entries
- Each synced room has a `[SUCCESS] ... inserted` or `[INFO] ... unchanged/updated` line
- Rate plans section shows `[WARNING] No rate plans returned` OR `[SUCCESS] ... inserted` entries
- The final line is `sync_catalog: Catalog sync complete`

---

## Step 7 – Simulate a data change (optional, advanced)

This step verifies that the update path works.

1. Manually corrupt one row's `raw_data` in the database to simulate stale data:
   ```sql
   UPDATE rooms SET raw_data = '{"id_room_types": "34152", "name": "Old Name"}' WHERE id = 1;
   ```

2. Run the sync again:
   ```
   php scripts/sync_catalog.php
   ```

3. **Expected output:** `Rooms synced: 0 inserted, 1 updated, 2 unchanged`

   The corrupted row has a different hash than the live API data, so the script
   detects the change and updates it.

4. Verify the `raw_data` column was restored to the full API payload:
   ```sql
   SELECT raw_data FROM rooms WHERE id = 1;
   ```
   The value should be the full JSON object returned by the API (many fields, not just 2).

5. Run the sync once more to confirm it returns to `0 inserted, 0 updated, 3 unchanged`.

---

## Pass Criteria Summary

Phase 1 is **PASSED** when ALL of the following are true:

- [x] First run: `sync_catalog.php` prints `Login: OK` with no `[ERROR]` lines
- [x] `SELECT * FROM rooms` returns 3 rows with correct `external_id`, `name`, `slug`, and non-null `raw_data`
- [x] Rate plans: either rows exist in `rate_plans`, OR the script prints the "none found" notice without errors
- [x] Second run: `sync_catalog.php` shows `0 inserted, 0 updated, N unchanged` for rooms (rate plans: same as first run)
- [x] Step 7: corrupted row triggers `1 updated`; next run returns to `3 unchanged`
- [x] `logs/app.log` contains no `[ERROR]` lines; final line is `sync_catalog: Catalog sync complete`

**Phase 1 is complete. Proceed to Phase 2.**
