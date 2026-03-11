# HotelSync Integration

A lightweight PHP integration service that synchronises rooms, rate plans, and
reservations between the **HotelSync API** (OTASync) and the local **BridgeOne**
property-management system.

All six phases are implemented and tested:

| Phase | Description |
|-------|-------------|
| 0 | Infrastructure – environment verification, DB, API auth |
| 1 | Catalog sync – rooms and rate plans |
| 2 | Reservation import |
| 3 | Reservation update and cancellation |
| 4 | Invoice generation |
| 5 | Inbound webhook endpoint |

---

## Requirements

| Dependency | Minimum version |
|------------|----------------|
| PHP        | 7.4            |
| MySQL      | 5.7 (or MariaDB 10.3) |
| PHP extensions | `mysqli`, `curl`, `json` |

> **Windows / XAMPP note:** The built-in XAMPP PHP (`/c/xampp/php/php.exe`) is
> recommended on Windows because it ships with `mysqli` already enabled.  If you
> use the WinGet/system PHP, make sure to enable `extension=mysqli` in `php.ini`.

---

## Setup

### 1. Clone the repository

```bash
git clone <your-repo-url> hotelsync-integration
cd hotelsync-integration
```

### 2. Configure the application

Open `config/config.php` and fill in your database credentials and API token:

```php
define('DB_HOST',     'localhost');
define('DB_USER',     'your_db_user');
define('DB_PASS',     'your_db_password');
define('DB_NAME',     'hotelsync');
define('API_BASE_URL','https://app.otasync.me/api');
define('API_TOKEN',   'your_token_here');
define('API_USERNAME','your_otasync_email');
define('API_PASSWORD','your_otasync_password');
define('APP_ENV',     'dev');   // set to 'prod' on production
```

> **Never** commit real credentials to version control.  `config.php` is listed
> in `.gitignore` for exactly this reason.

### 3. Import the database schema

```bash
mysql -u your_db_user -p hotelsync < sql/schema.sql
```

This creates eight tables: `rooms`, `rate_plans`, `reservations`,
`reservation_rooms`, `reservation_rate_plans`, `audit_log`,
`invoice_queue`, and `webhook_events`.

### 4. Verify the environment

```bash
php scripts/test_connection.php
```

Expected output (all lines show `[OK]`):

```
=== PHP Environment ===
  [OK]    PHP >= 7.4
  [OK]    mysqli extension loaded
  [OK]    cURL extension loaded

=== Database Connection ===
  [OK]    Database connection: OK
  [OK]    Table 'rooms' exists
  [OK]    Table 'rate_plans' exists
  [OK]    Table 'reservations' exists
  ...

=== API Connection ===
  [OK]    API authentication: OK
  [OK]    Property found: My Hotel (id=10586)
```

If any line shows `[FAIL]`, check the relevant section of `config/config.php`
and consult [Docs/testing/phase-0-test-plan.md](Docs/testing/phase-0-test-plan.md).

---

## Scripts Reference

### `scripts/sync_catalog.php` – Phase 1: Catalog Sync

Fetches all room types and rate plans from HotelSync and upserts them into the
local database.  Uses SHA-256 payload hashing for change detection – unchanged
records are skipped.

```bash
php scripts/sync_catalog.php
```

**Expected output:**

```
=== HotelSync Catalog Sync ===

--- Rooms ---
Rooms: 2 inserted, 0 updated, 1 unchanged

--- Rate Plans ---
Rate plans: 1 inserted, 1 updated, 0 unchanged

=== Catalog sync complete ===
```

---

### `scripts/sync_reservations.php` – Phase 2: Reservation Import

Fetches reservations from HotelSync within a date range and upserts them
locally.  Links each reservation to its room(s) and rate plan.

```bash
# Import reservations for a date range
php scripts/sync_reservations.php --from=2026-01-01 --to=2026-12-31

# Both arguments are required
php scripts/sync_reservations.php --from=YYYY-MM-DD --to=YYYY-MM-DD
```

**Expected output:**

```
=== HotelSync Reservation Sync ===
Date range: 2026-01-01 to 2026-12-31

Fetching page 1 of N...
  [OK] Reservation 12345 – John Doe (confirmed) – inserted
  [OK] Reservation 12346 – Jane Smith (confirmed) – unchanged

=== Sync complete: 1 inserted, 0 updated, 6 unchanged ===
```

---

### `scripts/update_reservation.php` – Phase 3: Reservation Update

Fetches a single reservation from HotelSync by ID, compares it with the local
record, and applies any changes.  Cancellations are soft-deletes.  Every change
is recorded in the `audit_log` table.

```bash
php scripts/update_reservation.php --reservation_id=12345
```

**Expected output (no changes):**

```
=== HotelSync Reservation Update ===
Reservation ID: 12345
No changes detected for reservation 12345.
```

**Expected output (cancellation):**

```
=== HotelSync Reservation Update ===
Reservation ID: 12345
Changes detected – updating local record...
Reservation 12345 marked as cancelled.
Record retained in DB (soft-delete). Audit log entry written.
```

---

### `scripts/generate_invoice.php` – Phase 4: Invoice Generation

Generates an invoice for a local reservation, inserts it into `invoice_queue`,
and simulates a send attempt with up to 5 retries.  Invoice numbers are
race-condition-safe (`HS-INV-YYYY-000001` format).

```bash
php scripts/generate_invoice.php --reservation_id=12345
```

The `--reservation_id` value is matched against both `hs_reservation_id`
(HotelSync ID) and the local auto-increment `id`.

**Expected output:**

```
=== HotelSync Invoice Generation ===
Reservation ID: 12345

Reservation found: id=3, hs_id=12345, status=confirmed
Line items: 1 room(s), 3 night(s), total 300.00 EUR
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

---

### `public/webhooks/otasync.php` – Phase 5: Inbound Webhook

Receives HTTP POST events from HotelSync.  Validates the JSON payload,
deduplicates by payload hash, and routes each event to the correct handler.

**Supported event types:**

| Event type | Action |
|------------|--------|
| `reservation.created` | Inserts new reservation |
| `reservation.updated` | Updates existing reservation, writes `audit_log` |
| `reservation.cancelled` | Soft-cancels reservation, writes `audit_log` |
| *(any other)* | Recorded in `webhook_events`, no handler runs |

**Local testing:**

```bash
# Start the built-in PHP server
php -S localhost:8000 -t public

# Send a test event (in a second terminal)
curl -s -X POST http://localhost:8000/webhooks/otasync.php \
  -H "Content-Type: application/json" \
  -d '{
    "event_type": "reservation.created",
    "reservation": {
      "id_reservations": "999001",
      "first_name": "Ivan",
      "last_name": "Horvat",
      "date_arrival": "2026-05-01",
      "date_departure": "2026-05-05",
      "status": "confirmed",
      "id_pricing_plans": "31432",
      "rooms": [{ "id_room_types": "34152", "quantity": 1 }]
    }
  }'
```

**Expected response:**

```json
{ "status": "ok", "event_type": "reservation.created" }
```

**Production deployment:** Deploy the project under a web server (Apache/Nginx),
set `APP_ENV=prod` in `config/config.php`, and configure HotelSync to POST
events to `https://your-domain.com/webhooks/otasync.php`.

---

## Architecture Overview

```
┌──────────────────┐   HTTPS/cURL   ┌─────────────────────────────┐
│  HotelSync API   │ ◄───────────► │  src/api.php (api_request)   │
│ (app.otasync.me) │               └──────────────┬──────────────┘
└──────────────────┘                              │
                                        JSON response
                                                  │
                                    ┌─────────────▼─────────────┐
                                    │  scripts/                  │
                                    │  sync_catalog.php          │
                                    │  sync_reservations.php     │
                                    │  update_reservation.php    │
                                    │  generate_invoice.php      │
                                    └─────────────┬─────────────┘
                                                  │
                                      INSERT / UPDATE / SELECT
                                                  │
                                    ┌─────────────▼─────────────┐
                                    │   MySQL (hotelsync)        │
                                    └─────────────┬─────────────┘
                                                  │
                                     invoice_queue / data
                                                  │
                                    ┌─────────────▼─────────────┐
                                    │   BridgeOne PMS (local)    │
                                    └───────────────────────────┘

Inbound webhooks:
┌──────────────────┐  HTTP POST  ┌──────────────────────────────────┐
│  HotelSync API   │ ──────────► │  public/webhooks/otasync.php     │
└──────────────────┘             │  → webhook_events + handlers      │
                                 └──────────────────────────────────┘
```

There is no long-running daemon.  Each sync task is a PHP CLI script invoked
manually or by cron.

**Key design decisions:**

| Decision | Rationale |
|----------|-----------|
| Procedural PHP | Zero framework dependencies; runs on any shared host |
| `mysqli` only | Consistency with the BridgeOne codebase |
| File-based logger | No external service needed in dev or prod |
| `payload_hash` column | Cheap change detection without field-by-field comparison |
| `lock_id` on reservations | Prevents duplicate imports on repeated syncs |
| `webhook_events` table | Decouples receipt from processing; enables replay |

---

## Detailed Documentation

| Document | Contents |
|----------|----------|
| [Docs/architecture.md](Docs/architecture.md) | Full system architecture and component descriptions |
| [Docs/api-integration.md](Docs/api-integration.md) | All HotelSync endpoints, request/response examples, error handling |
| [Docs/database-schema.md](Docs/database-schema.md) | Table definitions and relationships |
| [Docs/testing/phase-0-test-plan.md](Docs/testing/phase-0-test-plan.md) | Environment verification tests |
| [Docs/testing/phase-1-test-plan.md](Docs/testing/phase-1-test-plan.md) | Catalog sync tests |
| [Docs/testing/phase-2-test-plan.md](Docs/testing/phase-2-test-plan.md) | Reservation import tests |
| [Docs/testing/phase-3-test-plan.md](Docs/testing/phase-3-test-plan.md) | Reservation update / cancellation tests |
| [Docs/testing/phase-4-test-plan.md](Docs/testing/phase-4-test-plan.md) | Invoice generation tests |
| [Docs/testing/phase-5-test-plan.md](Docs/testing/phase-5-test-plan.md) | Webhook endpoint tests |

---

## Logs

All scripts write structured log entries to `logs/app.log`:

```bash
tail -f logs/app.log
```

Log format: `[YYYY-MM-DD HH:MM:SS] [LEVEL] component: message`
