# API Integration Reference

## Overview

All communication with the HotelSync (OTASync) API uses:

- **Transport:** HTTPS, HTTP POST only
- **Encoding:** JSON request body + JSON response
- **Base URL:** `https://app.otasync.me/api`
- **No Authorization header** – credentials travel in the POST body
- **Primary reference:** `postman/otasync-api.json` – full exported Postman
  collection with confirmed request bodies and field names.  Always consult
  this file when implementing new endpoints or debugging unexpected responses.

---

## Authentication

### Endpoint

```
POST /user/auth/login
```

### Request body

```json
{
  "token":    "775580f2b13be0215b5aee08a17c7aa892ece321",
  "username": "user@example.com",
  "password": "YourPassword",
  "remember": 0
}
```

| Field      | Type   | Description                              |
|------------|--------|------------------------------------------|
| `token`    | string | Partner/integration token (from OTASync) |
| `username` | string | OTASync account email                    |
| `password` | string | OTASync account password                 |
| `remember` | int    | Session persistence flag (always `0`)    |

### Response

```json
{
  "pkey": "abc123sessionkey",
  "properties": [
    { "id_properties": 10586, "name": "My Hotel" }
  ],
  "user": { "id_users": 1234, "email": "user@example.com" }
}
```

| Field        | Type   | Description                                        |
|--------------|--------|----------------------------------------------------|
| `pkey`       | string | Session key – passed as `key` in all further calls |
| `properties` | array  | List of properties the account has access to       |

### Integration code

`api_login()` in [src/api.php](../src/api.php) handles this flow and returns
`['pkey' => ..., 'properties' => [...], 'user' => ...]`.

The integration always uses the **first** property in the list
(`properties[0]['id_properties']`).

---

## Authenticated Requests

All subsequent calls merge the following fields into every POST body:

```json
{
  "token":         "775580f2b13be0215b5aee08a17c7aa892ece321",
  "key":           "<pkey from login>",
  "id_properties": 10586
}
```

`api_request($endpoint, $method, $payload, $token)` in
[src/api.php](../src/api.php) handles this automatically.

---

## Catalog Sync Endpoints

### Rooms

```
POST /room/data/rooms
```

**Request body** – `"type":"list"` is **required** (omitting it returns
HTTP 400 `Missing type`):

```json
{
  "token":         "775580f2b13be0215b5aee08a17c7aa892ece321",
  "key":           "<pkey>",
  "id_properties": 10586,
  "type":          "list"
}
```

**Response** – flat JSON array (no wrapper object):

```json
[
  {
    "id_room_types": "34152",
    "id_properties": "10586",
    "name":          "Double Room with Sea View",
    "type":          "room",
    "shortname":     "DBL",
    "price":         "45",
    "avail":         "3",
    "occupancy":     "2",
    "area":          "50"
  },
  {
    "id_room_types": "34153",
    "id_properties": "10586",
    "name":          "Twin Room with Garden View",
    "type":          "room",
    "shortname":     "TWIN",
    "price":         "50",
    "avail":         "3",
    "occupancy":     "2"
  }
]
```

**Field mapping:**

| API field       | DB column     | Transformation                         |
|-----------------|---------------|----------------------------------------|
| `id_room_types` | `hs_room_id`  | Cast to string                         |
| `name`          | `name`        | Stored as-is                           |
| —               | `slug`        | `slugify($name)`                       |
| —               | `external_id` | `generate_room_external_id(id, name)`  |
| full object     | `raw_data`    | `json_encode($room)`                   |

**External ID format:** `HS-{id_room_types}-{slug}`
Example: `HS-34152-double-room-with-sea-view`

---

### Rate Plans (Pricing Plans)

```
POST /pricingPlan/data/pricing_plans
```

**Request body** – no `"type"` field needed for this endpoint:

```json
{
  "token":         "775580f2b13be0215b5aee08a17c7aa892ece321",
  "key":           "<pkey>",
  "id_properties": 10586
}
```

**Response** – flat JSON array:

```json
[
  {
    "id_pricing_plans":     31432,
    "id_properties":        10586,
    "name":                 "Osnovni cenovnik",
    "id_board_names":       9,
    "id_restriction_plans": 16558,
    "id_boards":            95175,
    "type":                 "daily"
  },
  {
    "id_pricing_plans":     31433,
    "id_properties":        10586,
    "name":                 "Doručak uključen",
    "id_board_names":       1,
    "id_restriction_plans": 16558,
    "id_boards":            95167,
    "type":                 "daily"
  }
]
```

> **Note:** There is no `meal_plan` text field in the API.  Board/meal type is
> encoded in `id_board_names` (a numeric reference).  The integration stores it
> as `B{id_board_names}` (e.g. `B1`, `B9`) in the `meal_plan` column.

**Field mapping:**

| API field          | DB column          | Transformation                                   |
|--------------------|--------------------|--------------------------------------------------|
| `id_pricing_plans` | `hs_rate_plan_id`  | Cast to string                                   |
| `name`             | `name`             | Stored as-is                                     |
| `id_board_names`   | `meal_plan`        | Prefixed: `"B" . id_board_names` (e.g. `B9`)    |
| —                  | `external_id`      | `generate_rate_plan_external_id(id, meal_plan)`  |
| full object        | `raw_data`         | `json_encode($rp)`                               |

**External ID format:** `RP-{id_pricing_plans}-{meal_plan}`
Example: `RP-31432-B9`

---

## Upsert Logic (Catalog)

Both rooms and rate plans follow identical change-detection logic:

```
1. Call API endpoint → receive list
2. For each item:
   a. Parse hs_id and name from response
   b. Generate external_id and slug / meal_plan
   c. Compute SHA-256 hash of the incoming payload (hash_payload())
   d. SELECT existing row by hs_room_id / hs_rate_plan_id
   e. If NOT found  → INSERT (inserted++)
   f. If found:
        - Decode stored raw_data, compute its hash
        - If hashes match  → skip (unchanged++)
        - If hashes differ → UPDATE (updated++)
3. Print summary: "X inserted, Y updated, Z unchanged"
```

Change detection uses `hash_payload()` from [src/helpers.php](../src/helpers.php),
which computes `sha256(json_encode($data, JSON_SORT_KEYS))`.
Only the `raw_data` JSON column is compared – no field-by-field diff is needed.

---

## Reservations Endpoint

### Fetch Reservation List

```
POST /reservation/data/reservations
```

**Important:** The full body is required.  Omitting any field causes HTTP 500.

```json
{
  "token":         "775580f2b13be0215b5aee08a17c7aa892ece321",
  "key":           "<pkey>",
  "id_properties": 10586,
  "dfrom":         "2026-01-01",
  "dto":           "2026-12-31",
  "page":          1
}
```

| Field           | Description                                     |
|-----------------|-------------------------------------------------|
| `dfrom`         | Start of date range (arrival date, `YYYY-MM-DD`) |
| `dto`           | End of date range (departure date, `YYYY-MM-DD`) |
| `page`          | Page number (1-based); see `total_pages_number` in response |

**Response:**

```json
{
  "reservations": [
    {
      "id_reservations":  "123456",
      "first_name":       "John",
      "last_name":        "Doe",
      "date_arrival":     "2026-03-15",
      "date_departure":   "2026-03-18",
      "status":           "confirmed",
      "id_pricing_plans": "31432",
      "rooms": [
        { "id_room_types": "34152", "quantity": 1 }
      ]
    }
  ],
  "currency":            "EUR",
  "total_pages_number":  3
}
```

> **No reservations:** When no reservations match the date range, the response
> contains only `{"currency":"EUR"}` with no `reservations` key.

**Confirmed field names:**

| API field          | DB column         | Notes                          |
|--------------------|-------------------|--------------------------------|
| `id_reservations`  | `hs_reservation_id` | Stored as string               |
| `first_name`       | `guest_name`      | Combined: `"$first $last"`     |
| `last_name`        | `guest_name`      | Combined with first_name       |
| `date_arrival`     | `arrival_date`    |                                |
| `date_departure`   | `departure_date`  |                                |
| `status`           | `status`          | `confirmed`, `cancelled`, etc. |
| `id_pricing_plans` | (rate_plans join) | Scalar on root object          |
| `rooms[].id_room_types` | (rooms join) | Array of room objects         |

---

### Fetch Single Reservation

```
POST /reservation/data/reservation
```

Note: endpoint is singular (`reservation`, not `reservations`).

**Request body:**

```json
{
  "token":           "775580f2b13be0215b5aee08a17c7aa892ece321",
  "key":             "<pkey>",
  "id_properties":   10586,
  "id_reservations": "123456"
}
```

**Response:** Flat reservation object (not wrapped in an array):

```json
{
  "id_reservations":  "123456",
  "first_name":       "John",
  "last_name":        "Doe",
  "date_arrival":     "2026-03-15",
  "date_departure":   "2026-03-18",
  "status":           "confirmed",
  "id_pricing_plans": "31432",
  "rooms": [
    { "id_room_types": "34152", "quantity": 1 }
  ]
}
```

**Error case:** If the reservation ID does not exist, the API returns
`HTTP 400` with body `"Invalid ID"` (not a JSON object, not HTTP 404).

**Cancellation detection:**

```php
$is_cancelled = (
    in_array(strtolower($res['status']), ['cancelled', 'canceled'])
    || !empty($res['is_deleted'])
);
```

---

## Webhook Events (Inbound)

HotelSync pushes events to the configured webhook URL via HTTP POST.

**Endpoint (production):**
```
POST https://your-domain.com/webhooks/otasync.php
```

**Supported event types:**

| `event_type` | Trigger |
|--------------|---------|
| `reservation.created` | New reservation made |
| `reservation.updated` | Reservation modified |
| `reservation.cancelled` | Reservation cancelled |

**Payload structure (all event types):**

```json
{
  "event_type": "reservation.created",
  "reservation": {
    "id_reservations": "123456",
    "first_name":      "John",
    "last_name":       "Doe",
    "date_arrival":    "2026-05-01",
    "date_departure":  "2026-05-05",
    "status":          "confirmed",
    "id_pricing_plans":"31432",
    "rooms": [
      { "id_room_types": "34152", "quantity": 1 }
    ]
  }
}
```

**Response (success):**

```json
{ "status": "ok", "event_type": "reservation.created" }
```

**Response (duplicate):**

```json
{ "status": "already_processed" }
```

Deduplication uses `SHA-256(raw POST body)` stored in `webhook_events.payload_hash`.

---

## Token Management

| Concern | Current approach |
|---------|-----------------|
| Storage | `API_TOKEN`, `API_USERNAME`, `API_PASSWORD` constants in `config/config.php` |
| Session key | `api_login()` is called at the start of each script; `pkey` lives only in memory for the duration of the run |
| Expiry | Not managed; `pkey` is obtained fresh on every script invocation |
| Production recommendation | Cache `pkey` in Redis/APCu; refresh only on `401`/auth failure |

---

## Error Handling

| Scenario | Behaviour |
|----------|-----------|
| Login fails (cURL / HTTP / bad credentials) | Log `ERROR`, print message, `exit(1)` |
| DB connection fails | Log `ERROR`, print message, `exit(1)` |
| Rooms/rate-plans fetch fails | Log `ERROR`, print message, close DB, `exit(1)` |
| Single record processing error | Log `ERROR`, print message, continue to next item |
| Missing required field in API response | Log `WARNING`, skip record, continue |
| Reservation not found in HotelSync (`HTTP 400 "Invalid ID"`) | Log `ERROR`, `exit(1)` |
| Reservation not found in local DB (for update) | Log `WARNING`, advise sync, `exit(0)` |
| Webhook – invalid JSON | Return `400 Bad Request` with JSON error body |
| Webhook – wrong HTTP method | Return `405 Method Not Allowed` |
| Webhook – duplicate payload | Return `200 OK` with `{"status":"already_processed"}` |

---

## Response Field Fallbacks

The integration tolerates minor API schema variations:

**Rooms / rate plans list location:**
1. Root-level array (confirmed actual format)
2. `$response['data']` (wrapped – fallback for future schema changes)

**Room ID field (in order of precedence):**
1. `id_room_types` ← confirmed actual field name
2. `id_room`
3. `id`

**Rate plan ID field:**
1. `id_pricing_plans` ← confirmed actual field name

**Meal plan derivation:**
- Source field: `id_board_names` (numeric board type reference)
- Stored as: `"B" . id_board_names` (e.g. `B1`, `B9`)
- Default if missing: `RO`
