# Changelog

Every page shows the version it runs in its footer (and on the login page). If a computer
or the Hostinger site shows an older version than the newest one below, it still has old files:
upload the new files and run the migrations listed for the versions it is missing.

Before each push to GitHub, raise the version in `includes/version.php` and add it here.

## 1.3.0 — 2026-10-30

- Terminals: **Remarks** and **FAQ** (questions with answers) on Register / Edit Terminal. Admins can add,
  edit, reorder and remove FAQs; everyone can read them with the **Remarks & FAQ** button in the list.
  Search also looks in remarks and FAQs.
- Dashboard: **Weekly Transpo** (Monday to Sunday) with previous / next week. Field engineers see their own
  total and each day; admins see every engineer per day, with totals and a link to Transpo Reports.

Migrations:
- `2026_10_30_terminal_remarks_faq.sql`

## 1.2.0 — 2026-10-28

- Transportation: modes moved to a list admins manage on **Transport Modes**. A missing mode can be
  registered from a leg ("+ Add new mode…"). Fixes saving legs and fares with Bus, Van or Car.
- My DAS: **Location** per day (Within the city / Out of town); included in Copy all.
- **Forgot password?** on the login page: an admin approves the request on **Password Resets**, sets a
  new password to give to the user, and the user chooses their own at the next login.
- Serial Inventory: **Label** (CBG-MP, CBG-AB, TBG …) on Scan, Records, Bulk check and CSV.
- Version number shown in the footer and on the login page.
- Login, registration, forgot-password and change-password pages use the Mistery logo; template credits removed.
- Installed app (PWA) always updates: service worker `sw.js` with `CACHE_VERSION`, `manifest.json`,
  CSS/JS links with `?v=<file time>`, and no-cache rules in `.htaccess`. Update steps are in **DEPLOY.md**.

Migrations:
- `2026_10_28_transpo_modes.sql`
- `2026_10_28_das_trip_type.sql`
- `2026_10_28_password_resets.sql`
- `2026_10_28_serial_inventory_label.sql`

## 1.1.0 — 2026-10-20

- Email Generator: Service Request and ATW Request emails, recipients list, My Requests with CSV.
- Terminals: labels managed on **Terminal Labels**; SN, TID and merchant fields removed.
- Serial Inventory: Scan (camera, photo, typing) with Register / Check only, and Records with Bulk check.
- Locations get an address; users get mobile number and area.
- HTTPS setup for phone cameras (`tools/https/setup-https.bat`, `docs/HTTPS_SETUP.md`).

Migrations:
- `2026_10_17_email_request.sql`
- `2026_10_17_email_request_2_generator.sql`
- `2026_10_19_serial_inventory.sql`
- `2026_10_19_terminals_labels.sql`
- `2026_10_19_serial_inventory_2_unique.sql`

## 1.0.0 — 2026-10-16

- First version on GitHub: registration with admin approval, Time Clock, My Records, My DAS with leave
  days, user schedules, locations, terminals with photos, notifications and the Transportation
  Expense module.
