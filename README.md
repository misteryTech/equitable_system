# Equitable System

PHP + MySQL (XAMPP) system for field engineers: Time Clock and DAS, Terminals,
Transportation Expenses, Email Generator (Service / ATW requests) and Serial Inventory.

## Setting up on a new PC

1. Install XAMPP (PHP 8.2+, MariaDB/MySQL) and copy this folder to `C:\xampp\htdocs\equitable_system`.
2. Create the database from the full schema:
   ```
   C:\xampp\mysql\bin\mysql -u root -p < database\schema.sql
   ```
3. Copy `includes/config.local.example.php` to `includes/config.local.php` and enter that
   PC's database port and password. (`config.local.php` is never uploaded to GitHub.)
4. Open `http://localhost/equitable_system/` and register, or create the first admin in phpMyAdmin.

### Updating an existing database

Run the new files in `database/migrations/` in name order, for example:
```
C:\xampp\mysql\bin\mysql -h 127.0.0.1 -P 3308 -u root -p equitable_system < database\migrations\2026_10_19_serial_inventory_2_unique.sql
```
`database/schema.sql` always holds the complete, current structure.

### Version

Every page shows the version in its footer, and the login page shows it under the form (e.g. "Version v1.3.0 · Oct 30, 2026").
If the Hostinger site shows an older number than your PC, it still has old files: upload the new ones and run the
migrations listed in [CHANGELOG.md](CHANGELOG.md) for the versions it is missing.
Before each push to GitHub, raise `APP_VERSION` and `APP_RELEASED` in `includes/version.php` and add the changes to `CHANGELOG.md`.
When uploading to Hostinger, follow [DEPLOY.md](DEPLOY.md) (it includes raising `CACHE_VERSION` in `sw.js`).

### Tests

```
C:\xampp\php\php.exe tests\time_compute_test.php
C:\xampp\php\php.exe tests\transpo_test.php
C:\xampp\php\php.exe tests\email_generator_test.php
C:\xampp\php\php.exe tests\terminals_test.php
C:\xampp\php\php.exe tests\serial_inventory_test.php
C:\xampp\php\php.exe tests\password_reset_test.php
```

## Serial Inventory

A register of serial numbers: each serial is stored once, with the date it was first
registered. Nothing else is kept (no user, location or terminal).

- **Serial Inventory > Scan** has two modes (the page remembers the last one):
  - **Register**: a new serial is saved with today's date and shows a green
    **REGISTERED** card with one beep. A serial that already exists isn't saved again: it
    shows an orange **ALREADY REGISTERED … since <date>** card with a double beep.
  - **Check only**: nothing is saved. **EXISTS – registered <date>** (green) or
    **NOT REGISTERED** (red) with a **Register now** button.
- **Start Camera** uses the back camera (pick another from the list; the choice is
  remembered). It reads QR, Code 128, Code 39, EAN-13 and Data Matrix, and keeps scanning.
  The same code read again within 3 seconds is ignored. Flash and zoom appear when the
  phone supports them.
- No live camera? Use **Scan from photo** (opens the phone's camera app) or **Type a serial**.
  Both work without HTTPS.
- Text like `SN:2367KD8E6059`, `S/N …` or a multi-line QR is reduced to just the serial, in
  capitals.
- The session panel counts new / already registered / not registered, lists every scan, and
  **Copy list** pastes it into Excel. ✕ undoes a registration made on that page.
- **Serial Inventory > Records**: search, date range, total count, **Copy all**, **CSV**, and
  **Bulk check** (paste serials one per line → which exist / which don't →
  **Register all missing**). Admins can edit (a serial stays unique) and delete.

## HTTPS for phone cameras

Phone browsers only allow the camera on `https://` pages. Run
`tools\https\setup-https.bat` and restart Apache: the script makes a certificate for the PC's
LAN address and points Apache at it. It uses mkcert when that is installed, otherwise OpenSSL.
Then open `https://<PC-IP>/equitable_system/serial_scan.php` on the phone.

The full step-by-step guide covers mkcert, the Apache config, the firewall rule, installing or
accepting the certificate on Android and iPhone, and troubleshooting:
**[docs/HTTPS_SETUP.md](docs/HTTPS_SETUP.md)**.
