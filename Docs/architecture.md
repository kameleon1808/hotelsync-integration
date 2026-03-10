# Architecture Overview

## Integration Summary

HotelSync Integration is a **stateless, script-based PHP service** that acts as
a bridge between the cloud-hosted HotelSync API and the on-premise BridgeOne PMS.

There is no long-running daemon. Each synchronisation task is a PHP CLI script
invoked manually or by a cron job.

---

## Data Flow

```
┌──────────────────┐        HTTPS / cURL        ┌───────────────────────┐
│   HotelSync API  │ ◄──────────────────────►   │  src/api.php          │
│ (app.otasync.me) │                             │  (api_request)        │
└──────────────────┘                             └───────────┬───────────┘
                                                             │
                                                   JSON response
                                                             │
                                                 ┌───────────▼───────────┐
                                                 │  scripts/             │
                                                 │  sync_catalog.php     │
                                                 │  sync_reservations.php│
                                                 │  update_reservation   │
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
│  HotelSync API   │ ─────────────► │  public/webhooks/otasync.php      │
└──────────────────┘                │  → stores to webhook_events table │
                                    └───────────────────────────────────┘
```

---

## Source File Responsibilities

### `config/config.php`
Single source of truth for all configuration constants: database credentials,
API base URL, API token, log file path, and environment flag (`dev`/`prod`).
**No credentials should appear anywhere else in the codebase.**

### `src/logger.php`
Appends structured timestamped log entries to `logs/app.log`.
Every significant action (DB query error, API call, status change) must be
recorded through `log_event()`.

### `src/db.php`
Thin mysqli wrapper. Provides:
- `get_db_connection()` – opens and returns a verified connection.
- `execute_query()` – prepares, binds, and executes a parameterised statement.
- `close_db_connection()` – gracefully closes the connection.

### `src/api.php`
cURL-based HTTP client for the HotelSync REST API. All requests go through
`api_request()` which handles authentication headers, JSON encoding/decoding,
error detection, and logging.

### `src/helpers.php`
Pure utility functions with no I/O side effects:
- ID generators (`generate_room_external_id`, `generate_rate_plan_external_id`, etc.)
- `slugify()` for URL-safe strings.
- `hash_payload()` for change-detection hashing.

---

## Key Design Decisions

| Decision | Rationale |
|----------|-----------|
| Procedural PHP | Zero framework dependencies, runs on any shared host |
| mysqli only | Consistency with BridgeOne codebase |
| File-based logger | No external logging service required in dev/prod |
| `payload_hash` column | Allows cheap change detection without field-by-field comparison |
| `lock_id` on reservations | Prevents duplicate imports on repeated syncs |
| `webhook_events` table | Decouples webhook receipt from processing; enables replay |
