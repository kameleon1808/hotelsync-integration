# API Integration Reference

## Overview

All communication with the HotelSync (OTASync) API uses:
- **Transport:** HTTPS, HTTP POST only
- **Encoding:** JSON request body + JSON response
- **Base URL:** `https://app.otasync.me/api`
- **No Authorization header** – credentials travel in the POST body

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

| Field      | Type   | Description                                 |
|------------|--------|---------------------------------------------|
| `token`    | string | Partner/integration token (from OTASync)    |
| `username` | string | OTASync account email                       |
| `password` | string | OTASync account password                    |
| `remember` | int    | Session persistence flag (always `0`)       |

### Response

```json
{
  "pkey": "abc123sessionkey",
  "properties": [
    { "id_properties": 10586, "name": "My Hotel" }
  ]
}
```

| Field        | Type   | Description                                          |
|--------------|--------|------------------------------------------------------|
| `pkey`       | string | Session key – passed as `key` in all further calls   |
| `properties` | array  | List of properties the account has access to         |

### Integration code

`api_login()` in [src/api.php](../src/api.php) handles this flow and returns
`['pkey' => ..., 'properties' => [...], 'user' => ...]`.

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

`api_request($endpoint, $payload, $pkey)` in [src/api.php](../src/api.php)
handles this automatically.

---

## Catalog Sync Endpoints

### Rooms

```
POST /room/data/rooms
```

**Request body** – `"type":"list"` is **required** (omitting it returns HTTP 400 `Missing type`):

```json
{
  "token":         "775580f2b13be0215b5aee08a17c7aa892ece321",
  "key":           "<pkey>",
  "id_properties": 10586,
  "type":          "list"
}
```

**Example response** – a flat JSON array (no wrapper object):

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

| API field       | DB column    | Transformation                        |
|-----------------|--------------|---------------------------------------|
| `id_room_types` | `hs_room_id` | Cast to string                        |
| `name`          | `name`       | Stored as-is                          |
| —               | `slug`       | `slugify($name)`                      |
| —               | `external_id`| `generate_room_external_id(id, name)` |
| full object     | `raw_data`   | `json_encode($room)`                  |

**External ID example:** `HS-34152-double-room-with-sea-view`

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

**Actual response** – flat JSON array:

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

> **Note:** The API has no `meal_plan` text field. The board/meal type is
> referenced by `id_board_names` (a numeric foreign key). The integration
> stores it as `B{id_board_names}` (e.g. `B1`, `B9`) in the `meal_plan` column.

| API field          | DB column        | Transformation                                  |
|--------------------|------------------|-------------------------------------------------|
| `id_pricing_plans` | `hs_rate_plan_id`| Cast to string                                  |
| `name`             | `name`           | Stored as-is                                    |
| `id_board_names`   | `meal_plan`      | Prefixed: `"B" . id_board_names` (e.g. `B1`)   |
| —                  | `external_id`    | `generate_rate_plan_external_id(id, meal_plan)` |
| full object        | `raw_data`       | `json_encode($rp)`                              |

**External ID example:** `RP-31432-B9`

---

## Upsert Logic

Both rooms and rate plans follow identical change-detection logic:

```
1. Call API endpoint → receive list
2. For each item:
   a. Parse hs_id and name from response
   b. Generate external_id and slug/meal_plan
   c. Compute SHA-256 hash of the incoming payload  (hash_payload())
   d. SELECT existing row by hs_room_id / hs_rate_plan_id
   e. If NOT found  → INSERT (inserted++)
   f. If found:
        - Decode stored raw_data, compute its hash
        - If hashes match → skip (unchanged++)
        - If hashes differ → UPDATE (updated++)
3. Print summary: "X inserted, Y updated, Z unchanged"
```

Change detection uses `hash_payload()` from [src/helpers.php](../src/helpers.php),
which computes `sha256(json_encode($data, JSON_SORT_KEYS))`.
Only the `raw_data` JSON column is read from the DB for comparison – no
field-by-field diff is needed.

---

## Error Handling

| Scenario                         | Behaviour                                       |
|----------------------------------|-------------------------------------------------|
| Login fails (cURL / HTTP / auth) | Log ERROR, print message, `exit(1)`             |
| DB connection fails              | Log ERROR, print message, `exit(1)`             |
| Rooms/rate-plans fetch fails     | Log ERROR, print message, close DB, `exit(1)`  |
| Single record processing error   | Log ERROR, print message, continue to next item |
| Missing id or name in payload    | Log WARNING, skip record, continue              |

---

## Response Field Fallbacks

The integration tolerates minor API schema variations:

**Rooms / rate plans list location:**
1. `$response['data']` (wrapped)
2. Root-level array (confirmed actual format)

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
