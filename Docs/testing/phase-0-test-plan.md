# Phase 0 Test Plan – Project Setup & Infrastructure

**Tester level:** Non-technical (no PHP or MySQL knowledge required)
**Estimated time:** 15–20 minutes
**Goal:** Confirm that the development environment is correctly set up and the
integration service can reach both the database and the OTASync API.

---

## Prerequisites (Before You Start)

Before running any tests, make sure the following are installed on your machine:

- **PHP 7.4 or higher** with `mysqli`, `curl`, and `json` extensions enabled
- **MySQL 5.7 or higher** – or XAMPP / MAMP which bundles both
- A **Terminal** (Mac: Terminal app; Windows: Git Bash or Command Prompt)

> **Windows + XAMPP note:** If you have XAMPP installed, use the XAMPP PHP
> binary to ensure all extensions are available:
> ```
> /c/xampp/php/php.exe scripts/test_connection.php
> ```
> Or add XAMPP PHP to your PATH permanently.

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

## Step 3 – Configure the Credentials

1. Open the file `config/config.php` in any text editor (Notepad, VS Code, etc.).
2. Fill in your MySQL credentials:
   ```php
   define('DB_HOST', '127.0.0.1');  // usually 127.0.0.1 or localhost
   define('DB_PORT', 3306);         // 3306 is MySQL default; change if needed
   define('DB_USER', 'root');       // your MySQL username
   define('DB_PASS', '');           // your MySQL password
   define('DB_NAME', 'hotelsync');  // database name to create/use
   ```
3. Fill in your OTASync API credentials:
   ```php
   define('API_TOKEN',       'your_partner_token_here');
   define('API_USERNAME',    'your_otasync_email@example.com');
   define('API_PASSWORD',    'your_otasync_password');
   define('API_PROPERTY_ID', 12345);  // numeric ID from your OTASync property
   ```
   - `API_TOKEN` – partner integration token (contact OTASync support)
   - `API_USERNAME` / `API_PASSWORD` – your OTASync account login
   - `API_PROPERTY_ID` – found after logging in; the test script will list all
     accessible property IDs so you can confirm the correct one
4. Save the file.

> **How to find your API_PROPERTY_ID:** Run the test script once with
> `API_PROPERTY_ID` set to any number. If login succeeds, the script prints
> all properties you have access to. Use the correct `id_properties` value.

---

## Step 4 – Create the Database and Import the Schema

1. Open MySQL (via phpMyAdmin, MySQL Workbench, or terminal).
2. Create a new database named `hotelsync`:
   ```sql
   CREATE DATABASE hotelsync CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   ```
3. Import the schema. Via phpMyAdmin: select the `hotelsync` database →
   **Import** tab → choose `sql/schema.sql` → click **Go**.

   Or via terminal (Linux/Mac):
   ```
   mysql -u root -p hotelsync < sql/schema.sql
   ```

**Expected result:** No error messages. All 8 tables are created.

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
  PHP Version   : 8.x.x
  [OK]    PHP >= 7.4
  [OK]    mysqli extension loaded
  [OK]    cURL extension loaded
  [OK]    json extension loaded

=== Database Connection ===
  Host   : 127.0.0.1
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
  Base URL  : https://app.otasync.me/api
  Token     : 77558...
  Username  : your_email@example.com
  Property  : 12345
  [OK]    API login: OK
  Session key : xxxxxxxx...
  Properties  :
    id=12345 name=Your Property Name <-- active
  [OK]    API_PROPERTY_ID valid: OK

=== Summary ===
  Phase 0 check complete. Review any [FAIL] items above.
```

The `<-- active` marker confirms that `API_PROPERTY_ID` in your config matches
one of the properties your account can access.

---

## Troubleshooting

| Error message | What to do |
|---|---|
| `[FAIL] PHP >= 7.4` | Upgrade PHP to 7.4 or higher |
| `[FAIL] mysqli extension NOT found` | Open `php.ini`, uncomment `extension=mysqli`, restart. On Windows+XAMPP use `/c/xampp/php/php.exe` |
| `[FAIL] cURL extension NOT found` | Open `php.ini`, uncomment `extension=curl`, restart |
| `[FAIL] Database connection: FAILED` | Check credentials in `config/config.php`. Make sure MySQL is running on the correct port (`DB_PORT`). |
| `[FAIL] Table 'rooms' NOT found` | Import `sql/schema.sql` into the `hotelsync` database (Step 4) |
| `[FAIL] API credentials not configured` | Set `API_USERNAME` and `API_PASSWORD` in `config/config.php` |
| `[FAIL] API login: FAILED` | Check `API_USERNAME`, `API_PASSWORD`, and `API_TOKEN` in `config/config.php`. Verify internet connection. |
| `[FAIL] API_PROPERTY_ID not found` | Login succeeded but the configured ID is wrong. Check the printed properties list and update `API_PROPERTY_ID`. |

---

## Step 6 – Check the Log File

After running the test script, a log file is created automatically.

1. Open the file `logs/app.log` in any text editor.
2. You should see timestamped entries like:
   ```
   [2026-03-10 10:00:01] [INFO] Database connection established.
   [2026-03-10 10:00:01] [INFO] API login attempt for user: your_email@example.com
   [2026-03-10 10:00:02] [SUCCESS] API login successful. Properties available: 1
   [2026-03-10 10:00:02] [SUCCESS] API connectivity and login test passed.
   ```

**Expected result:** The log file exists and contains entries with no `[ERROR]` lines.

**If the log file is empty or missing:** Run the test script again and check that
`logs/` folder exists and is writable.

---

## Pass Criteria

Phase 0 is **PASSED** when ALL of the following are true:

- [ ] `php scripts/test_connection.php` shows zero `[FAIL]` lines
- [ ] All 8 database tables show `[OK]`
- [ ] API login shows `[OK]` and `API_PROPERTY_ID valid: OK`
- [ ] `logs/app.log` exists and contains entries with no `[ERROR]` lines

If all boxes are checked, Phase 0 is complete. Proceed to Phase 1.
