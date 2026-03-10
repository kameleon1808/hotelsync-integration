# Database Schema Reference

## Overview

All tables live in the `hotelsync` database using `utf8mb4_unicode_ci` charset
and `InnoDB` engine (supports foreign keys and transactions).

---

## Tables

### `rooms`

Stores room/unit records synced from the HotelSync catalog.

| Column        | Type            | Description                                   |
|---------------|-----------------|-----------------------------------------------|
| `id`          | INT UNSIGNED PK | Auto-increment internal ID                    |
| `external_id` | VARCHAR(100)    | Generated ID in format `HS-{id}-{slug}` (unique) |
| `hs_room_id`  | VARCHAR(100)    | Room ID as returned by HotelSync API (unique) |
| `name`        | VARCHAR(255)    | Human-readable room name                      |
| `slug`        | VARCHAR(255)    | URL-safe lowercase version of name            |
| `raw_data`    | JSON            | Full API response payload for reference       |
| `created_at`  | DATETIME        | Record creation timestamp                     |
| `updated_at`  | DATETIME        | Last modification timestamp (auto-updated)    |

---

### `rate_plans`

Stores pricing / rate plan records from the HotelSync catalog.

| Column            | Type            | Description                                     |
|-------------------|-----------------|-------------------------------------------------|
| `id`              | INT UNSIGNED PK | Auto-increment internal ID                      |
| `external_id`     | VARCHAR(100)    | Generated ID in format `RP-{id}-{meal_plan}`    |
| `hs_rate_plan_id` | VARCHAR(100)    | Rate plan ID from HotelSync API (unique)        |
| `name`            | VARCHAR(255)    | Rate plan display name                          |
| `meal_plan`       | VARCHAR(50)     | Meal plan code: BB, HB, FB, AI, RO              |
| `raw_data`        | JSON            | Full API response payload                       |
| `created_at`      | DATETIME        | Record creation timestamp                       |
| `updated_at`      | DATETIME        | Last modification timestamp                     |

---

### `reservations`

Central table for every reservation imported from HotelSync.

| Column               | Type            | Description                                           |
|----------------------|-----------------|-------------------------------------------------------|
| `id`                 | INT UNSIGNED PK | Auto-increment internal ID                            |
| `lock_id`            | VARCHAR(150)    | `LOCK-{id}-{arrival_date}` – deduplication key (unique) |
| `hs_reservation_id`  | VARCHAR(100)    | HotelSync reservation ID (unique)                     |
| `guest_name`         | VARCHAR(255)    | Primary guest full name                               |
| `arrival_date`       | DATE            | Check-in date                                         |
| `departure_date`     | DATE            | Check-out date                                        |
| `status`             | VARCHAR(50)     | Lifecycle status: new \| confirmed \| cancelled \| invoiced |
| `payload_hash`       | VARCHAR(64)     | SHA-256 of the raw payload – used for change detection |
| `raw_data`           | JSON            | Full API response payload                             |
| `created_at`         | DATETIME        | Import timestamp                                      |
| `updated_at`         | DATETIME        | Last sync timestamp                                   |

---

### `reservation_rooms`

Junction table linking reservations to rooms (many-to-many).

| Column           | Type            | Description                            |
|------------------|-----------------|----------------------------------------|
| `id`             | INT UNSIGNED PK | Auto-increment internal ID             |
| `reservation_id` | INT UNSIGNED FK | References `reservations.id`           |
| `room_id`        | INT UNSIGNED FK | References `rooms.id`                  |
| `quantity`       | TINYINT         | Number of this room type in the booking |

---

### `reservation_rate_plans`

Junction table linking reservations to rate plans (many-to-many).

| Column           | Type            | Description                     |
|------------------|-----------------|---------------------------------|
| `id`             | INT UNSIGNED PK | Auto-increment internal ID      |
| `reservation_id` | INT UNSIGNED FK | References `reservations.id`    |
| `rate_plan_id`   | INT UNSIGNED FK | References `rate_plans.id`      |

---

### `audit_log`

Append-only record of all state changes. Never updated or deleted.

| Column           | Type            | Description                                 |
|------------------|-----------------|---------------------------------------------|
| `id`             | INT UNSIGNED PK | Auto-increment internal ID                  |
| `reservation_id` | INT UNSIGNED    | Related reservation (nullable for system events) |
| `event_type`     | VARCHAR(100)    | e.g. `reservation_created`, `status_changed` |
| `old_data`       | JSON            | Previous state (null for creation events)   |
| `new_data`       | JSON            | New state (null for deletion events)        |
| `created_at`     | DATETIME        | When the event occurred                     |

---

### `invoice_queue`

Invoices pending generation and delivery to BridgeOne.

| Column           | Type               | Description                                       |
|------------------|--------------------|---------------------------------------------------|
| `id`             | INT UNSIGNED PK    | Auto-increment internal ID                        |
| `invoice_number` | VARCHAR(30)        | `HS-INV-YYYY-000001` format (unique)              |
| `reservation_id` | INT UNSIGNED FK    | References `reservations.id`                      |
| `guest_name`     | VARCHAR(255)       | Guest name at time of invoice generation          |
| `arrival_date`   | DATE               | Stay check-in date                                |
| `departure_date` | DATE               | Stay check-out date                               |
| `line_items`     | JSON               | Array of `{description, qty, unit_price, total}`  |
| `total_amount`   | DECIMAL(12,2)      | Invoice total                                     |
| `currency`       | CHAR(3)            | ISO 4217 code, default `EUR`                      |
| `status`         | VARCHAR(30)        | `pending` \| `sent` \| `failed`                   |
| `retry_count`    | TINYINT            | Number of delivery attempts                       |
| `created_at`     | DATETIME           | Queue entry creation timestamp                    |
| `updated_at`     | DATETIME           | Last status update timestamp                      |

---

### `webhook_events`

Raw inbound webhook payloads from HotelSync, stored for async processing.

| Column         | Type            | Description                                            |
|----------------|-----------------|--------------------------------------------------------|
| `id`           | INT UNSIGNED PK | Auto-increment internal ID                             |
| `event_type`   | VARCHAR(100)    | e.g. `reservation.created`, `reservation.cancelled`    |
| `payload`      | JSON            | Full raw webhook body                                  |
| `payload_hash` | VARCHAR(64)     | SHA-256 of payload – prevents duplicate processing (unique) |
| `processed`    | TINYINT(1)      | `0` = pending, `1` = processed                         |
| `created_at`   | DATETIME        | Receipt timestamp                                      |

---

## Table Relationships

```
reservations ──< reservation_rooms    >── rooms
reservations ──< reservation_rate_plans >── rate_plans
reservations ──< audit_log
reservations ──< invoice_queue
webhook_events  (standalone, linked to reservations during processing)
```

---

## External ID Naming Conventions

| Entity    | Format                       | Example              |
|-----------|------------------------------|----------------------|
| Room      | `HS-{internal_id}-{slug}`    | `HS-42-deluxe-double`|
| Rate Plan | `RP-{internal_id}-{meal_plan}` | `RP-7-BB`          |
| Lock      | `LOCK-{reservation_id}-{arrival_date}` | `LOCK-1001-2025-06-15` |
| Invoice   | `HS-INV-{year}-{seq:06d}`    | `HS-INV-2025-000001` |

External IDs are stable and human-readable. They are designed so that even if
the local database is wiped and reimported, the same IDs will be regenerated
from the same source data.
