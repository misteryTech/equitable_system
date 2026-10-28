# Deploying an update (Hostinger)

Follow these steps every time you upload new files, so phones with the installed app
("Add to Home Screen") show the new version instead of an old saved copy.

## Before uploading

1. **Raise the service worker version** in `sw.js`:
   ```js
   const CACHE_VERSION = "equitable-v1";   // → "equitable-v2", "equitable-v3", …
   ```
   This makes every phone throw away its saved pages and files and reload once.
2. **Raise the app version** in `includes/version.php` (`APP_VERSION`, `APP_RELEASED`)
   and add what changed to `CHANGELOG.md`, including any new migration files.
3. Test locally on XAMPP (`http://localhost/equitable_system/`) and run the tests
   listed in `README.md`.

## Uploading

4. Upload the changed files to `public_html` (File Manager or FTP). Always include
   `sw.js` when you changed `CACHE_VERSION`.
   Do **not** upload `includes/config.local.php` over the live one: it holds the Hostinger
   database password.
5. Run the new migrations from `database/migrations/` in phpMyAdmin, in date order.
   `CHANGELOG.md` lists them for each version.

## After uploading

6. **Purge the server cache** in hPanel: *Websites → Manage → Advanced → Cache Manager → Purge All*
   (also purge the CDN if it is turned on).
7. **Check on a phone:**
   - Open the app and wait a few seconds. It should reload by itself once, or you can close
     and reopen it or pull down to refresh.
   - The footer (or the login page) must show the new version number.
   - If it still shows the old one, check that `https://<site>/sw.js` shows the new
     `CACHE_VERSION`. As a last resort, clear the site data in the phone browser
     (Settings → Site settings → the site → Clear & reset).

## Why this works

- PHP pages, `sw.js` and `manifest.json` are sent with "no-cache" (`.htaccess`), so the
  browser always asks the server for them.
- CSS, JS and images are cached for a year, but pages link them as `file.css?v=<file time>`
  (`asset()` in `includes/config.php`). A changed file gets a new link and is downloaded again.
- `sw.js` loads pages from the network first and uses the saved copy only when offline. It
  never saves logins, password pages, `*_api.php` endpoints, exports or POST requests, and
  logging out deletes the saved pages.
- Service workers need HTTPS. Hostinger provides it; locally they work on `http://localhost`.
