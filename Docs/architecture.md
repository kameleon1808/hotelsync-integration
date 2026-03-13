# Architecture Overview

## System Overview

**BridgeOne** is an on-premise property-management system (PMS) used by the
hotel to manage bookings, billing, and operations locally.

**HotelSync** (OTASync) is a cloud-hosted channel manager and PMS API at
`https://app.otasync.me/api`.  It aggregates reservations from online travel
agencies (OTAs) and exposes them via a JSON REST API.

**BridgeOne** integration service is the bridge between the two.  It is a stateless,
script-based PHP service with no long-running daemon.  Each synchronisation
task is a PHP CLI script invoked manually or by a cron job.  An inbound webhook
endpoint (`public/webhooks/otasync.php`) handles real-time push events from
the OTASync platform.

---

## Data Flow

```
┌──────────────────┐        HTTPS / cURL        ┌───────────────────────┐
│   OTASync API    │ ◄──────────────────────►   │  src/api.php          │
│ (app.otasync.me) │                             │  (api_request)        │
└──────────────────┘                             └───────────┬───────────┘
                                                             │
                                                   JSON response
                                                             │
                                                 ┌───────────▼───────────┐
                                                 │  scripts/             │
                                                 │  sync_catalog.php     │
                                                 │  sync_reservations.php│
                                                 │  update_reservation.php│
                                                 │  generate_invoice.php │
                                                 └───────────┬───────────┘
                                                             │
                                             INSERT / UPDATE / SELECT
                                                             │
                                                 ┌───────────▼───────────┐
                                                 │   MySQL Database       │
                                                 │   (hotelsync schema)   │
                                                 └───────────┬───────────┘
                                                             │
                                                  invoice_queue / data
                                                             │
                                                 ┌───────────▼───────────┐
                                                 │   BridgeOne PMS        │
                                                 │   (local system)       │
                                                 └───────────────────────┘

Inbound webhooks:
┌──────────────────┐   HTTP POST    ┌───────────────────────────────────┐
│  OTASync API     │ ─────────────► │  public/webhooks/otasync.php      │
└──────────────────┘                │  → webhook_events + handlers       │
                                    └───────────────────────────────────┘
```

---

## Component Descriptions

### `config/config.php`

Single source of truth for all runtime constants:

- Database credentials (`DB_HOST`, `DB_USER`, `DB_PASS`, `DB_NAME`)
- API base URL (`API_BASE_URL`), partner token (`API_TOKEN`), login credentials
- Log file path (`LOG_FILE`)
- Environment flag (`APP_ENV`: `dev` or `prod`)

No credentials appear anywhere else in the codebase.  In development, SSL peer
verification is disabled via `CURLOPT_SSL_VERIFYPEER = false` when
`APP_ENV !== 'prod'` (required on Windows due to missing CA bundle in default
PHP installations).

---

### `src/logger.php`

Appends structured, timestamped log entries to `logs/app.log`.

```
[2026-03-11 14:05:22] [SUCCESS] sync_catalog: Rooms synced – 2 inserted, 0 updated, 1 unchanged
```

Function signature: `log_event(string $type, string $description, $res_id = null, $ext_id = null)`

Log levels used: `INFO`, `SUCCESS`, `WARNING`, `ERROR`.

Every significant action (API call, DB insert/update, status change, error)
must be recorded through this function.

---

### `src/db.php`

Thin `mysqli` wrapper.  Provides three functions:

| Function | Description |
|----------|-------------|
| `get_db_connection()` | Opens and returns a verified `mysqli` connection |
| `execute_query($conn, $sql, $types, $params)` | Prepares, binds, and executes a parameterised statement |
| `close_db_connection($conn)` | Gracefully closes the connection |

All SQL uses prepared statements with bound parameters.  No string
interpolation of user-controlled values is permitted.

---

### `src/api.php`

cURL-based HTTP client for the HotelSync REST API.

**Key functions:**

| Function | Description |
|----------|-------------|
| `api_login()` | POSTs credentials, returns `{pkey, properties, user}` |
| `api_request($endpoint, $method, $payload, $token)` | Sends an authenticated request, decodes JSON |

All HotelSync calls use HTTP POST with a JSON body.  There are no Bearer
headers – the session key (`pkey`) is passed in the request body as `key`.

Empty response bodies are handled gracefully (returns `[]` instead of `null`).

---

### `src/helpers.php`

Pure utility functions with no I/O side effects:

| Function | Description |
|----------|-------------|
| `slugify($str)` | Converts a string to a URL-safe slug |
| `hash_payload($data)` | SHA-256 of `json_encode($data, JSON_SORT_KEYS)` |
| `generate_room_external_id($id, $name)` | Returns `HS-{id}-{slug}` |
| `generate_rate_plan_external_id($id, $meal)` | Returns `RP-{id}-{meal_plan}` |
| `generate_lock_id($res_id, $arrival)` | Returns `LOCK-{res_id}-{arrival}` |
| `generate_invoice_number($year, $seq)` | Returns `HS-INV-{year}-{seq:06d}` |

---

### `scripts/`

CLI scripts, each implementing one phase of the integration:

| Script | Phase | Description |
|--------|-------|-------------|
| `test_connection.php` | 0 | Verifies PHP environment, DB, and API auth |
| `sync_catalog.php` | 1 | Fetches rooms and rate plans; upserts to DB |
| `sync_reservations.php` | 2 | Fetches reservations by date range; upserts to DB |
| `update_reservation.php` | 3 | Fetches one reservation; applies changes; soft-cancels |
| `generate_invoice.php` | 4 | Generates invoice, queues it, simulates send with retries |

Each script: validates CLI arguments, logs every step, prints clear
success/error messages to stdout, and exits with code `0` (success) or `1`
(error).

---

### `public/webhooks/otasync.php`

HTTP endpoint that receives push events from HotelSync.

**Processing pipeline for each request:**

1. Reject non-POST methods → `405 Method Not Allowed`
2. Parse and validate JSON body → `400 Bad Request` on failure
3. Compute SHA-256 hash of the raw payload
4. Check `webhook_events` table for duplicate hash → respond `already_processed`
5. Insert new row in `webhook_events` (unprocessed)
6. Route by `event_type` to the appropriate handler
7. Mark `webhook_events` row as processed
8. Return `{"status":"ok","event_type":"..."}` with `200 OK`

Unrecognised event types are stored but no handler runs, ensuring forward
compatibility.

---

### `sql/schema.sql`

Defines eight tables:

| Table | Purpose |
|-------|---------|
| `rooms` | HotelSync room types synced locally |
| `rate_plans` | HotelSync pricing/rate plans synced locally |
| `reservations` | Reservation records (canonical local copy) |
| `reservation_rooms` | Join table: reservation ↔ room + quantity |
| `reservation_rate_plans` | Join table: reservation ↔ rate plan |
| `audit_log` | Immutable log of every reservation change (old/new JSON) |
| `invoice_queue` | Invoice records with status, retry count, line items |
| `webhook_events` | Inbound webhook payloads with deduplication hash |

---

## Key Design Decisions

| Decision | Rationale |
|----------|-----------|
| Procedural PHP | Zero framework dependencies; runs on any shared hosting without Composer |
| `mysqli` only (no PDO) | Consistency with the existing BridgeOne codebase |
| File-based logger | No external logging service required in dev or prod |
| `payload_hash` column | Allows cheap change detection without field-by-field comparison |
| `lock_id` on reservations | Prevents duplicate imports when sync runs are repeated |
| `webhook_events` table | Decouples webhook receipt from processing; enables replay and deduplication |
| Soft-delete for cancellations | Preserves history for audit and billing; BridgeOne expects the record to remain |
| `SELECT … FOR UPDATE` on invoice seq | Race-condition-safe invoice numbering without application-level locking |

---

## Known Limitations

- **No retry queue for failed API calls.** If a cURL request to HotelSync fails
  mid-sync, the script logs the error and continues to the next record.  There
  is no automatic retry across runs beyond the per-record error handling.

- **No pagination on catalog sync.** The rooms and rate plans endpoints return
  flat arrays without pagination metadata.  If HotelSync adds pagination in the
  future, `sync_catalog.php` will need to be updated.

- **Invoice "sending" is simulated.** `generate_invoice.php` simulates a
  send attempt with a random success/fail model.  In production, this would
  call the BridgeOne billing API or push to a message queue.

- **Webhook authentication is not implemented.** The webhook endpoint accepts
  any POST request.  In production, HotelSync should sign requests with an
  HMAC secret and the endpoint should verify the signature.

- **Single-property assumption.** The integration uses the first property
  returned by the login response.  Multi-property accounts would require
  looping over all properties or accepting an `--property_id` argument.

- **No cron management.** The scripts must be wired to a cron job externally.
  There is no built-in scheduler.

---

## What Would Be Added in a Production System

### Authentication Improvements

- **Webhook HMAC verification:** HotelSync (or a proxy) signs each webhook
  payload with a shared secret.  The endpoint verifies the `X-Signature` header
  before processing.
- **Credential rotation:** API tokens should be stored in environment variables
  or a secrets manager (e.g. AWS Secrets Manager, HashiCorp Vault) rather than
  a PHP config file.
- **Session key caching:** `api_login()` is called at the top of every script.
  In production, the `pkey` session token would be cached (Redis, APCu, or a
  DB row) and reused until expiry, reducing authentication overhead.

### Queue System for Webhooks

Instead of processing webhook events synchronously within the HTTP request,
a production system would:

1. Receive the POST → validate JSON → insert into `webhook_events` → respond
   `202 Accepted` immediately (under 50 ms).
2. A background worker (e.g. a PHP daemon, Supervisor process, or AWS Lambda)
   polls `webhook_events` for unprocessed rows and handles them asynchronously.

This pattern eliminates timeout risk on high-volume event bursts and allows
horizontal scaling of workers independently of the web tier.

### More Robust Retry Mechanism

The current invoice retry is a simple loop within a single script run.  A
production retry mechanism would:

- Store failed attempts in `invoice_queue` with `next_retry_at` and
  exponential back-off (`retry_count * 2^retry_count` seconds).
- A cron job or background worker periodically picks up rows where
  `status = 'failed'` and `next_retry_at <= NOW()` and retries.
- Dead-letter the row after N total attempts and alert the team.

The same pattern would apply to API sync failures – a `sync_queue` table
would track individual records that failed to sync, allowing targeted retries
without reprocessing the entire catalog or date range.

### Monitoring and Alerting

- **Structured logging:** Replace the flat `logs/app.log` with JSON-structured
  logs ingested by a log aggregator (e.g. Datadog, Papertrail, ELK stack).
- **Error alerting:** Send a Slack/email notification when a script exits with
  code `1` or when `invoice_queue.status = 'failed'` rows accumulate beyond a
  threshold.
- **Cron health checks:** Use a dead-man's-switch service (e.g. Cronitor,
  Healthchecks.io) to alert if a scheduled sync script stops running.
- **Metrics dashboard:** Track sync counts (inserted/updated/unchanged) per run,
  webhook throughput, invoice send rate, and API latency over time.
