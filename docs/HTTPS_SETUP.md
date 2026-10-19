# HTTPS setup for phone cameras

**Serial Inventory > Scan** uses the camera. Browsers only allow the camera on `https://`
pages, or on `http://localhost` on the PC itself. A phone that opens
`http://192.168.x.x/equitable_system/` gets **no camera**. The Scan page says so, and
**Scan from photo** and **Type a serial** keep working. To use the live camera on phones,
turn on HTTPS in XAMPP once:

1. [Give the PC a fixed address](#1-give-the-pc-a-fixed-address)
2. [Make the certificate](#2-make-the-certificate) (mkcert preferred, OpenSSL as a fallback)
3. [Check the Apache configuration](#3-check-the-apache-configuration)
4. [Restart Apache](#4-restart-apache)
5. [Allow port 443 in Windows Firewall](#5-allow-port-443-in-windows-firewall)
6. [Open the site on the phone](#6-open-the-site-on-the-phone)

On Hostinger none of this is needed: turn on the free SSL and **Force HTTPS** in hPanel.

---

## 1. Give the PC a fixed address

The certificate lists the PC's LAN address (for example `192.168.0.106`). If the router gives
the PC a new address later, the phone shows a certificate warning again. Fix the address once:

- In the router's settings, add a **DHCP reservation** for this PC, or
- re-run step 2 whenever the address changes.

Find the current address with `ipconfig` and look for **IPv4 Address**.

## 2. Make the certificate

### Option A: mkcert (preferred: trusted, no warnings)

mkcert creates a small private certificate authority (CA). The PC and each phone trust it once,
and every certificate it makes is then accepted without warnings. iPhones in particular need
this for the camera.

1. Install mkcert in a Command Prompt:
   ```
   winget install FiloSottile.mkcert
   ```
   Or download `mkcert-vX.Y.Z-windows-amd64.exe` from
   https://github.com/FiloSottile/mkcert/releases, rename it `mkcert.exe` and put it in a
   folder on your PATH. Close and reopen the Command Prompt afterwards.
2. Run the project's setup script. It finds mkcert automatically:
   ```
   C:\xampp\htdocs\equitable_system\tools\https\setup-https.bat
   ```
   The first time, Windows asks to install the mkcert root certificate. Answer **Yes**.

   To run mkcert by hand instead:
   ```
   mkcert -install
   mkcert -cert-file C:\xampp\apache\conf\ssl.crt\equitable.crt -key-file C:\xampp\apache\conf\ssl.key\equitable.key localhost 127.0.0.1 192.168.0.106
   ```
   Use your PC's address in place of `192.168.0.106`. Then do step 3 by hand.
3. Note where the CA lives: `mkcert -CAROOT`. You'll send `rootCA.pem` from that folder
   to the phones in step 6. **Never share `rootCA-key.pem`.**

### Option B: self-signed certificate with OpenSSL (no install)

Double-click `tools\https\setup-https.bat`. When mkcert is not installed, it uses XAMPP's own
`openssl.exe`. You can force this with `setup-https.bat -UseOpenSSL`. The certificate covers:

- `localhost`
- `127.0.0.1`
- the PC's name
- every LAN IPv4 address

It is valid for 825 days. Phones show a warning once, because you made the certificate yourself.

Both options save `C:\xampp\apache\conf\ssl.crt\equitable.crt` and
`C:\xampp\apache\conf\ssl.key\equitable.key`.

Extra names or addresses: `setup-https.bat -ExtraNames equitable.local,192.168.1.50`.

## 3. Check the Apache configuration

The script does this for you: it makes a dated backup
`httpd-ssl.conf.bak-YYYYMMDD-HHMMSS`, edits the file, and runs `httpd -t` (you should see
**Syntax OK**). To check or do it by hand:

- `C:\xampp\apache\conf\httpd.conf` must contain these lines, without a leading `#`
  (XAMPP has them on by default):
  ```
  LoadModule ssl_module modules/mod_ssl.so
  Include conf/extra/httpd-ssl.conf
  ```
- `C:\xampp\apache\conf\extra\httpd-ssl.conf` has `Listen 443`, and inside
  `<VirtualHost _default_:443>`:
  ```
  SSLCertificateFile "conf/ssl.crt/equitable.crt"
  SSLCertificateKeyFile "conf/ssl.key/equitable.key"
  ```
- Check: `C:\xampp\apache\bin\httpd.exe -t` prints `Syntax OK`.

## 4. Restart Apache

In the **XAMPP Control Panel**, press **Stop** next to Apache, then **Start**. On the PC,
`https://localhost/equitable_system/` should now open. With option B, the browser shows a
warning first.

If Apache won't start:

- Run `C:\xampp\apache\bin\httpd.exe -t` to see the error.
- To undo, copy the backup `httpd-ssl.conf.bak-…` over `httpd-ssl.conf` and start Apache again.
- If port 443 is taken by another program (Skype, VMware, IIS), close that program, or change
  `Listen 443` in `httpd-ssl.conf` and `<VirtualHost _default_:443>` to another port, e.g. 8443.
  Then use `https://<PC-IP>:8443/…`.

## 5. Allow port 443 in Windows Firewall

XAMPP usually adds an **Apache HTTP Server** rule the first time Apache starts. If the phone
can't connect, open a Command Prompt **as Administrator** and run:

```
netsh advfirewall firewall add rule name="Apache HTTPS (Equitable)" dir=in action=allow protocol=TCP localport=443
```

To check which network profile the PC uses (Public or Private), run this in PowerShell:
`Get-NetConnectionProfile`. A rule must cover that profile.

## 6. Open the site on the phone

The phone must be on the **same Wi-Fi** as the PC. Open:

```
https://192.168.0.106/equitable_system/serial_scan.php
```

Use your PC's address. Then tap **Start Camera** and allow the camera.

**Option A (mkcert): install the CA on each phone once** (send `rootCA.pem` by email,
Messenger file or USB):

- **Android**: Settings → Security (or Security & privacy) → More security settings →
  Encryption & credentials → **Install a certificate** → **CA certificate** → choose
  `rootCA.pem`. Then reopen Chrome.
- **iPhone / iPad**: open `rootCA.pem` (AirDrop or Mail), then:
  - Settings → **Profile Downloaded** → **Install**.
  - Then Settings → General → About → **Certificate Trust Settings**, and turn on full trust
    for the mkcert certificate.

**Option B (self-signed): accept the warning once**:

- **Chrome (Android)**: **Advanced** → **Proceed to 192.168.x.x (unsafe)**.
- **Safari (iPhone)**: **Show Details** → **visit this website** → **Visit Website**.

  If Safari still refuses the camera on the self-signed certificate, use option A.

## Troubleshooting on the phone

| Message on the Scan page | What to do |
|---|---|
| **Camera needs HTTPS** | You opened `http://`. Tap the `https://` link in the message. |
| **Open in Chrome or Safari** | Links opened inside Messenger or Facebook use an in-app browser that blocks the camera. Tap ⋮ or ··· → **Open in browser**, or use **Copy link** and paste it into Chrome or Safari. |
| **Camera permission was refused** | Chrome: tap the icon left of the address → Permissions / Site settings → Camera → **Allow**, then reload. Safari: tap **aA** → Website Settings → Camera → **Allow**, or Settings → Safari → Camera → Allow. |
| **No camera found on this device** | Use **Scan from photo** or **Type a serial**. |
| **The camera is being used by another app** | Close video calls and the camera app, then tap **Start Camera** again. |
| Page doesn't open at all | Check the phone is on the same Wi-Fi, check the firewall rule (step 5), and check that `https://localhost/equitable_system/` works on the PC. |

## Optional: always use HTTPS for the Scan page

Add this line to `includes/config.local.php`:

```php
define('SCAN_FORCE_HTTPS', true);
```

From then on, opening `http://<PC-IP>/equitable_system/serial_scan.php` jumps to the
`https://` address. `localhost` is never redirected, and other pages keep working over `http://`.
Only turn this on once HTTPS works (step 4), or the Scan page won't open from other devices.
