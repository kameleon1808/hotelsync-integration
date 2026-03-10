# Phase 0 Test Plan – Project Setup & Infrastructure

**Tester level:** Non-technical (no PHP or MySQL knowledge required)
**Estimated time:** 15–20 minutes
**Goal:** Confirm that the development environment is correctly set up and the
integration service can reach both the database and the HotelSync API.

---

## Prerequisites (Before You Start)

Before running any tests, make sure the following are installed on your machine:

- **PHP 7.4 or higher** – download from https://www.php.net/downloads
- **MySQL 5.7 or higher** – or XAMPP / MAMP which bundles both
- A **Terminal** (Mac: Terminal app; Windows: Command Prompt or PowerShell)

---

## Step 1 – Open the Project Folder in Terminal

1. Open your Terminal application.
2. Navigate to the project folder by typing:
   ```
   cd path/to/hotelsync-integration
   ```
   Replace `path/to/hotelsync-integration` with the actual folder path on your
   computer (e.g. `cd C:\Users\YourName\Documents\hotelsync-integration` on
   Windows or `cd ~/Documents/hotelsync-integration` on Mac).
3. Press **Enter**.

**Expected result:** The terminal prompt changes to show the project folder.
No error messages.

---

## Step 2 – Check Your PHP Version

In the terminal, type:
```
php -v
```
Press **Enter**.

**Expected result:** You should see a line starting with:
```
PHP 7.4.x  (or any higher version like 8.0, 8.1, 8.2...)
```

**If you see an error** like `php: command not found`:
- PHP is not installed or not in your PATH.
- Install PHP or add it to your system PATH, then restart the terminal.

---

## Step 3 – Configure the Database Credentials

1. Open the file `config/config.php` in any text editor (Notepad, VS Code, etc.).
2. Fill in your MySQL credentials:
   ```php
   define('DB_HOST', 'localhost');     // usually localhost
   define('DB_USER', 'root');          // your MySQL username
   define('DB_PASS', '');              // your MySQL password
   define('DB_NAME', 'hotelsync');     // database name to create/use
   ```
3. Save the file.

---

## Step 4 – Create the Database and Import the Schema

1. Open MySQL (via phpMyAdmin, MySQL Workbench, or terminal).
2. Create a new database named `hotelsync`:
   ```sql
   CREATE DATABASE hotelsync CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   ```
3. Import the schema by running in terminal:
   ```
   mysql -u root -p hotelsync < sql/schema.sql
   ```
   Enter your MySQL password when prompted.

**Expected result:** No error messages. The command completes silently.

**If you see an error** like `ERROR 1049 (42000): Unknown database 'hotelsync'`:
- The database was not created in step 2. Go back and create it first.

---

## Step 5 – Run the Phase 0 Test Script

In the terminal, type:
```
php scripts/test_connection.php
```
Press **Enter**.

**Expected result:**
```
=== PHP Environment ===
  [OK]    PHP >= 7.4
  [OK]    mysqli extension loaded
  [OK]    cURL extension loaded
  [OK]    json extension loaded

=== Database Connection ===
  Host   : localhost
  User   : root
  Name   : hotelsync
  [OK]    Database connection: OK
  [OK]    Table 'rooms' exists
  [OK]    Table 'rate_plans' exists
  [OK]    Table 'reservations' exists
  [OK]    Table 'reservation_rooms' exists
  [OK]    Table 'reservation_rate_plans' exists
  [OK]    Table 'audit_log' exists
  [OK]    Table 'invoice_queue' exists
  [OK]    Table 'webhook_events' exists

=== API Connection ===
  Base URL : https://app.otasync.me/api
  Token    : 775580f2...
  [OK]    API authentication: OK

=== Summary ===
  Phase 0 check complete. Review any [FAIL] items above.
```

---

## Troubleshooting

| Error message | What to do |
|---|---|
| `[FAIL] PHP >= 7.4` | Upgrade PHP to 7.4 or higher |
| `[FAIL] mysqli extension NOT found` | Open `php.ini`, uncomment `extension=mysqli`, restart |
| `[FAIL] cURL extension NOT found` | Open `php.ini`, uncomment `extension=curl`, restart |
| `[FAIL] Database connection: FAILED` | Check credentials in `config/config.php`. Make sure MySQL is running. |
| `[FAIL] Table 'rooms' NOT found` | Re-run `mysql ... < sql/schema.sql` (Step 4) |
| `[FAIL] API connection: FAILED` | Check internet connection. Verify `API_TOKEN` in `config/config.php`. |

---

## Step 6 – Check the Log File

After running the test script, a log file is created automatically.

1. Open the file `logs/app.log` in any text editor.
2. You should see timestamped entries like:
   ```
   [2025-06-01 10:00:01] [INFO] Database connection established.
   [2025-06-01 10:00:01] [INFO] API GET https://app.otasync.me/api/properties
   [2025-06-01 10:00:02] [SUCCESS] API response HTTP 200 from GET https://app.otasync.me/api/properties
   ```

**Expected result:** The log file exists and contains entries with no `[ERROR]` lines.

**If the log file is empty or missing:** Run the test script again and check that
`logs/` folder exists and is writable.

---

## Pass Criteria

Phase 0 is **PASSED** when ALL of the following are true:

- [ ] `php scripts/test_connection.php` shows zero `[FAIL]` lines
- [ ] All 8 database tables show `[OK]`
- [ ] API authentication shows `[OK]`
- [ ] `logs/app.log` exists and contains entries

If all boxes are checked, Phase 0 is complete. Proceed to Phase 1.
