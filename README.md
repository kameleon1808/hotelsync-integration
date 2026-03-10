# HotelSync Integration

A lightweight PHP integration service that synchronises rooms, rate plans, and
reservations between the **HotelSync API** (OTASync) and the local **BridgeOne**
property-management system.

---

## Requirements

| Dependency | Minimum version |
|------------|----------------|
| PHP        | 7.4             |
| MySQL      | 5.7 (or MariaDB 10.3) |
| PHP extensions | `mysqli`, `curl`, `json` |

---

## Setup

### 1. Clone the repository

```bash
git clone <your-repo-url> hotelsync-integration
cd hotelsync-integration
```

### 2. Configure the application

Copy and edit the configuration file:

```bash
cp config/config.php config/config.local.php   # optional local override
```

Open `config/config.php` and fill in your database credentials and API token:

```php
define('DB_HOST', 'localhost');
define('DB_USER', 'your_db_user');
define('DB_PASS', 'your_db_password');
define('DB_NAME', 'hotelsync');
define('API_TOKEN', 'your_token_here');
```

### 3. Import the database schema

```bash
mysql -u your_db_user -p hotelsync < sql/schema.sql
```

### 4. Run the Phase 0 verification test

```bash
php scripts/test_connection.php
```

Expected output:
```
=== PHP Environment ===
  [OK]    PHP >= 7.4
  [OK]    mysqli extension loaded
  [OK]    cURL extension loaded

=== Database Connection ===
  [OK]    Database connection: OK
  [OK]    Table 'rooms' exists
  ...

=== API Connection ===
  [OK]    API authentication: OK
```

---

## Scripts Overview

| Script | Description |
|--------|-------------|
| `scripts/test_connection.php`      | Phase 0 – verifies DB, API, and PHP environment |
| `scripts/sync_catalog.php`         | Phase 1 – imports rooms and rate plans from API |
| `scripts/sync_reservations.php`    | Phase 2 – imports and updates reservations |
| `scripts/update_reservation.php`   | Phase 3 – pushes reservation changes back to API |
| `scripts/generate_invoice.php`     | Phase 4 – creates and queues invoices for BridgeOne |
| `public/webhooks/otasync.php`      | Phase 5 – receives inbound webhook events |

---

## Architecture Decisions

> _To be filled in as the project evolves._

- Why procedural PHP (not OOP/framework): keep deployment friction minimal on
  shared hosting environments without Composer.
- Why mysqli (not PDO): matches the existing BridgeOne codebase conventions.
- Why a separate `invoice_queue` table: decouples invoice generation from
  reservation sync, allowing retries without re-processing the full reservation.
