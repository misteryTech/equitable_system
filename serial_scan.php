<?php
/**
 * Serial Inventory — Scan: register serial numbers from QR codes / barcodes and see
 * at once whether a serial is already registered.
 *   Register mode   new serial -> saved with today's date; existing -> not saved, shows its date
 *   Check only mode nothing saved; shows whether the serial exists
 * New serials get the label picked at the top (CBG-MP, CBG-AB, TBG … from Terminal Labels).
 * Camera via html5-qrcode (stored locally), a photo from the phone camera, or typing.
 * AJAX endpoint: POST action=scan (raw, mode) -> JSON {status: registered|exists|not_found, serial, date}.
 */
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/serial_inventory.php';
require_once __DIR__ . '/includes/terminals.php';
$user = require_role('field_engineer', 'admin');

$pageTitle  = 'Scan Serials';
$activePage = 'serial-scan';
$today      = date('Y-m-d');   // Asia/Manila (config.php)

// Optional (config.local.php): define('SCAN_FORCE_HTTPS', true) sends LAN visitors of this
// page to https:// so phone cameras work. Off by default; localhost is never redirected.
$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
$host    = preg_replace('/[^A-Za-z0-9.\-:\[\]]/', '', (string) ($_SERVER['HTTP_HOST'] ?? ''));
$isLocal = (bool) preg_match('/^(localhost|127\.0\.0\.1|\[::1\])(:\d+)?$/', $host);
if (defined('SCAN_FORCE_HTTPS') && SCAN_FORCE_HTTPS && !$isHttps && !$isLocal && $host !== '' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    redirect('https://' . preg_replace('/:\d+$/', '', $host) . $_SERVER['REQUEST_URI']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $respond = static function (array $data, int $status = 200): void {
        http_response_code($status);
        echo json_encode($data);
        exit;
    };
    if (!csrf_verify()) {
        $respond(['errors' => ['Your session expired. Please reload the page.']], 403);
    }

    // Ids registered from this page in this session, so they can be undone.
    $mine = &$_SESSION['serial_scan_ids'];
    $mine = is_array($mine) ? $mine : [];
    $label = static fn(?string $d): ?string => $d ? date('M j, Y', strtotime($d)) : null;

    $action = $_POST['action'] ?? '';
    if ($action === 'scan') {
        $raw  = (string) ($_POST['raw'] ?? '');
        $mode = ($_POST['mode'] ?? '') === 'check' ? 'check' : 'register';
        if (strlen($raw) > 2000) {
            $respond(['errors' => ['That code is too long to be a serial number.']], 422);
        }
        $serial = clean_serial($raw);
        if ($serial === null) {
            $shown = mb_substr(trim(preg_replace('/\s+/', ' ', $raw)), 0, 60);
            $respond(['errors' => [$shown === '' ? 'Nothing to check.' : "No serial number found in \"$shown\"."]], 422);
        }

        // Label for new serials: an active label from Terminal Labels, or none.
        $labelId = (int) ($_POST['label_id'] ?? 0);
        $labels  = terminal_labels();
        if ($labelId && empty($labels[$labelId]['is_active'])) {
            $respond(['errors' => ['That label is no longer available. Please pick another label.']], 422);
        }

        $result = $mode === 'check' ? check_serial(db(), $serial) : register_serial(db(), $serial, $today, $labelId ?: null);
        $shown  = $result['label_id'] !== null ? ($labels[$result['label_id']] ?? null) : null;
        if ($result['status'] === 'registered') {
            $mine[] = $result['id'];
            $mine   = array_slice($mine, -500);
        }
        $respond([
            'ok'         => true,
            'mode'       => $mode,
            'status'     => $result['status'],
            'serial'     => $result['serial'],
            'date'       => $result['date'],
            'date_label' => $label($result['date']),
            'id'         => $result['status'] === 'registered' ? $result['id'] : null,
            'label'      => $shown ? $shown['name'] : null,
            'label_class'=> $shown ? terminal_label_badge_class($shown['id']) : null,
        ]);
    }

    if ($action === 'undo') {
        $id = (int) ($_POST['id'] ?? 0);
        if (!in_array($id, $mine, true) && !is_admin($user)) {
            $respond(['errors' => ['Only serials registered from this page in this session can be undone here.']], 403);
        }
        db()->prepare('DELETE FROM serial_inventory WHERE id = ?')->execute([$id]);
        $mine = array_values(array_diff($mine, [$id]));
        $respond(['ok' => true]);
    }

    $respond(['errors' => ['Unknown action.']], 400);
}

require __DIR__ . '/includes/head.php';
require __DIR__ . '/includes/header.php';
require __DIR__ . '/includes/sidebar.php';
?>

  <main id="main" class="main">

    <div class="pagetitle">
      <h1><?= e($pageTitle) ?></h1>
      <nav>
        <ol class="breadcrumb">
          <li class="breadcrumb-item"><a href="index.php">Home</a></li>
          <li class="breadcrumb-item">Serial Inventory</li>
          <li class="breadcrumb-item active">Scan</li>
        </ol>
      </nav>
    </div><!-- End Page Title -->

    <section class="section">
      <div id="siAlerts"></div>

      <div class="card">
        <div class="card-body py-3 d-flex flex-wrap align-items-center gap-3">
          <div class="btn-group" role="radiogroup" aria-label="Scan mode">
            <input type="radio" class="btn-check" name="siMode" id="siModeRegister" value="register" autocomplete="off" checked>
            <label class="btn btn-outline-primary" for="siModeRegister"><i class="bi bi-plus-circle"></i> Register</label>
            <input type="radio" class="btn-check" name="siMode" id="siModeCheck" value="check" autocomplete="off">
            <label class="btn btn-outline-primary" for="siModeCheck"><i class="bi bi-search"></i> Check only</label>
          </div>
          <div class="d-flex align-items-center gap-2">
            <label for="siLabel" class="form-label mb-0 fw-semibold">Label</label>
            <select id="siLabel" class="form-select" style="width: auto">
              <option value="0">No label</option>
<?php foreach (terminal_labels(true) as $l): ?>
              <option value="<?= (int) $l['id'] ?>"><?= e($l['name']) ?></option>
<?php endforeach; ?>
            </select>
          </div>
          <div class="small text-muted" id="siModeHelp"></div>
        </div>
      </div>

      <div class="row">
        <div class="col-lg-7">
          <div class="card">
            <div class="card-body">
              <h5 class="card-title">Scanner</h5>

              <div class="d-flex flex-wrap gap-2 align-items-center mb-2">
                <button type="button" class="btn btn-primary" id="siStart"><i class="bi bi-camera-video"></i> Start Camera</button>
                <button type="button" class="btn btn-outline-danger d-none" id="siStop"><i class="bi bi-stop-circle"></i> Stop</button>
                <button type="button" class="btn btn-outline-warning d-none" id="siTorch" aria-pressed="false"><i class="bi bi-lightning-charge"></i> Flash</button>
                <div class="d-none align-items-center gap-2 flex-grow-1" id="siZoomWrap" style="min-width: 10rem">
                  <label for="siZoom" class="small text-muted mb-0"><i class="bi bi-zoom-in"></i> Zoom</label>
                  <input type="range" class="form-range" id="siZoom" min="1" max="1" step="0.1" value="1">
                </div>
              </div>
              <div class="mb-2 d-none" id="siCameraWrap">
                <label for="siCamera" class="form-label small mb-1">Camera</label>
                <select id="siCamera" class="form-select form-select-sm"></select>
              </div>

              <div id="siReader" class="rounded overflow-hidden bg-dark mb-2" style="width: 100%; max-width: 640px; margin: 0 auto; display: none"></div>
              <div id="siStatus" class="text-center small text-muted mb-3">Tap Start Camera and point it at a QR code or barcode. Scanning keeps going after each item.</div>

              <div id="siResult" class="rounded p-3 mb-3 text-center border" aria-live="polite">
                <div class="small text-muted">Last scan</div>
                <div class="fs-5 text-muted">Nothing scanned yet</div>
              </div>

              <div class="row g-2">
                <div class="col-md-6">
                  <label for="siFile" class="form-label small mb-1"><i class="bi bi-image"></i> Scan from photo</label>
                  <input type="file" id="siFile" class="form-control form-control-sm" accept="image/*" capture="environment">
                  <div class="form-text">Opens the phone's camera app. Works without HTTPS.</div>
                </div>
                <div class="col-md-6">
                  <label for="siManual" class="form-label small mb-1"><i class="bi bi-keyboard"></i> Type a serial</label>
                  <div class="input-group input-group-sm">
                    <input type="text" id="siManual" class="form-control font-monospace text-uppercase" maxlength="200" placeholder="e.g. 2367KD8E6059" autocomplete="off" autocapitalize="characters">
                    <button type="button" class="btn btn-outline-primary" id="siManualGo">Go</button>
                  </div>
                </div>
              </div>
              <div id="siFileReader" class="d-none"></div>
            </div>
          </div>
        </div>

        <div class="col-lg-5">
          <div class="card">
            <div class="card-body">
              <h5 class="card-title d-flex align-items-center">This session
                <a href="serial_records.php" class="ms-auto small fw-normal">Records</a></h5>
              <div class="d-flex flex-wrap gap-2 mb-3">
                <span class="badge bg-success fs-6 fw-normal">New <strong id="siCountNew">0</strong></span>
                <span class="badge bg-warning text-dark fs-6 fw-normal">Already registered <strong id="siCountExists">0</strong></span>
                <span class="badge bg-danger fs-6 fw-normal">Not registered <strong id="siCountMissing">0</strong></span>
              </div>
              <button type="button" class="btn btn-sm btn-outline-primary mb-2" id="siCopy" disabled><i class="bi bi-clipboard"></i> Copy list</button>
              <ul class="list-group" id="siList">
                <li class="list-group-item text-muted small" id="siEmpty">Nothing scanned yet.</li>
              </ul>
            </div>
          </div>
        </div>
      </div>
    </section>

  </main><!-- End #main -->

  <style>
    #siManual::placeholder { text-transform: none; }   /* typed text is upper-cased, the hint isn't */
    #siResult .si-serial { font-size: 1.5rem; letter-spacing: .03em; word-break: break-all; }
    #siResult .si-status { font-size: 1.15rem; font-weight: 700; letter-spacing: .04em; }
    #siReader video { width: 100% !important; height: auto !important; }
  </style>

  <script src="<?= asset('assets/js/html5-qrcode.min.js') ?>"></script>
  <script src="<?= asset('assets/js/email-generator.js') ?>"></script>
  <script>
    (function () {
      var csrf = <?= json_encode(csrf_token()) ?>;
      var REPEAT_MS = 3000;                    // the same code read again within 3 s is ignored
      var MODE_KEY = "serialScanMode", CAMERA_KEY = "serialScanCamera", LABEL_KEY = "serialScanLabel";
      var scanner = null, fileScanner = null, audio = null;
      var cameras = [];
      var lastText = "", lastAt = 0;
      var counts = { registered: 0, exists: 0, not_found: 0 };
      var rows = [];                           // session list for Copy list

      function $(id) { return document.getElementById(id); }
      function el(tag, className, text) {
        var node = document.createElement(tag);
        if (className) { node.className = className; }
        if (text != null) { node.textContent = text; }
        return node;
      }
      function store(key, value) { try { if (value === undefined) { return localStorage.getItem(key); } localStorage.setItem(key, value); } catch (e) { return null; } }

      /* ---------- Messages ---------- */

      function alertBox(id, variant, title, parts) {
        var old = $(id);
        if (old) { old.remove(); }
        var box = el("div", "alert alert-" + variant + " alert-dismissible fade show");
        box.id = id;
        box.setAttribute("role", "alert");
        box.append(el("strong", null, title + " "));
        parts.forEach(function (p) { box.append(p); });
        var close = el("button", "btn-close");
        close.type = "button"; close.setAttribute("data-bs-dismiss", "alert"); close.setAttribute("aria-label", "Close");
        box.append(close);
        $("siAlerts").append(box);
        box.scrollIntoView({ block: "nearest" });
      }
      function link(href, text) { var a = el("a", null, text); a.href = href; return a; }
      function fallbacks() { return " You can still use Scan from photo or Type a serial below."; }

      var inApp = /FBAN|FBAV|FB_IAB|FBIOS|Messenger|Instagram|Line\/|MicroMessenger|; wv\)/i.test(navigator.userAgent);
      var secure = window.isSecureContext && navigator.mediaDevices && navigator.mediaDevices.getUserMedia;
      var httpsUrl = "https://" + location.host.replace(/:\d+$/, "") + location.pathname;

      if (inApp) {
        var copyUrl = el("button", "btn btn-sm btn-outline-dark ms-1", "Copy link");
        copyUrl.type = "button";
        copyUrl.addEventListener("click", function () { EmailGen.copyWithToast(location.href, "link"); });
        alertBox("siInApp", "warning", "Open in Chrome or Safari.",
          ["Messenger, Facebook and other in-app browsers usually block the camera. Tap ⋮ or ··· and choose ", el("strong", null, "Open in browser"), ", or copy the link and paste it into Chrome or Safari.", copyUrl]);
      }
      if (!secure) {
        $("siStart").disabled = true;
        var parts = ["Browsers only allow the camera on https:// pages (or http://localhost on this computer). "];
        if (location.protocol === "http:") { parts.push("Open ", link(httpsUrl, httpsUrl), " instead (ask your admin if it doesn't open)."); }
        parts.push(fallbacks());
        alertBox("siHttps", "warning", "Camera needs HTTPS.", parts);
      }

      function cameraError(err) {
        var text = String(err && (err.name ? err.name + " " + (err.message || "") : err));
        if (/NotAllowed|Permission|denied/i.test(text)) {
          alertBox("siCamErr", "danger", "Camera permission was refused.",
            ["Chrome: tap the icon left of the address, then Permissions (or Site settings) → Camera → Allow, and reload. ",
             "Safari (iPhone): tap aA in the address bar → Website Settings → Camera → Allow, or Settings → Safari → Camera → Allow.", fallbacks()]);
        } else if (/NotFound|DevicesNotFound|not found|no camera/i.test(text)) {
          alertBox("siCamErr", "warning", "No camera found on this device.", [fallbacks()]);
        } else if (/NotReadable|TrackStart|Could not start|in use|Concurrent/i.test(text)) {
          alertBox("siCamErr", "warning", "The camera is being used by another app.",
            ["Close other apps or tabs that use the camera (video calls, the camera app), then tap Start Camera again.", fallbacks()]);
        } else {
          alertBox("siCamErr", "danger", "Could not start the camera.", [text + "." + fallbacks()]);
        }
        $("siStatus").textContent = "Camera not running.";
      }

      /* ---------- Mode ---------- */

      function mode() { return document.querySelector('input[name="siMode"]:checked').value; }
      function labelId() { return $("siLabel").value; }
      function labelName() { var o = $("siLabel").selectedOptions[0]; return o && o.value !== "0" ? o.textContent : ""; }
      function showMode() {
        $("siModeHelp").textContent = mode() === "check"
          ? "Check only: shows whether each serial is registered, and its label. Nothing is saved."
          : "Register: new serials are saved with today's date (<?= e(date('M j, Y', strtotime($today))) ?>)" +
            (labelName() ? " and the label " + labelName() : " and no label") + ". Serials already registered are not saved again.";
      }
      // The label picked last time (if it is still in the list).
      var savedLabel = store(LABEL_KEY);
      if (savedLabel && $("siLabel").querySelector('option[value="' + savedLabel.replace(/\D/g, "") + '"]')) { $("siLabel").value = savedLabel; }
      $("siLabel").addEventListener("change", function () { store(LABEL_KEY, labelId()); showMode(); });
      var savedMode = store(MODE_KEY);
      if (savedMode === "check") { $("siModeCheck").checked = true; }
      document.querySelectorAll('input[name="siMode"]').forEach(function (r) {
        r.addEventListener("change", function () { store(MODE_KEY, mode()); showMode(); });
      });
      showMode();

      /* ---------- Feedback ---------- */

      function tone(freq, start, length) {
        var osc = audio.createOscillator(), gain = audio.createGain();
        osc.frequency.value = freq; gain.gain.value = 0.15;
        osc.connect(gain); gain.connect(audio.destination);
        osc.start(audio.currentTime + start); osc.stop(audio.currentTime + start + length);
      }
      function beep(kind) {
        try {
          if (!audio) { audio = new (window.AudioContext || window.webkitAudioContext)(); }
          if (kind === "registered" || kind === "found") { tone(1200, 0, 0.12); }
          else if (kind === "exists") { tone(700, 0, 0.1); tone(700, 0.18, 0.1); }   // double beep
          else { tone(320, 0, 0.35); }
        } catch (e) {}
        if (navigator.vibrate) {
          navigator.vibrate(kind === "registered" || kind === "found" ? 80 : kind === "exists" ? [70, 80, 70] : [200]);
        }
      }

      /* ---------- Result card and session list ---------- */

      var LOOK = {
        registered:          { cls: "bg-success-subtle border-success text-success-emphasis", status: "REGISTERED" },
        exists_register:     { cls: "bg-warning-subtle border-warning text-warning-emphasis", status: "ALREADY REGISTERED" },
        exists_check:        { cls: "bg-success-subtle border-success text-success-emphasis", status: "EXISTS" },
        not_found:           { cls: "bg-danger-subtle border-danger text-danger-emphasis",   status: "NOT REGISTERED" },
        pending:             { cls: "bg-light border", status: "Checking…" },
        error:               { cls: "bg-danger-subtle border-danger text-danger-emphasis", status: "NOT SAVED" }
      };
      function lookFor(json) { return json.status === "exists" ? (json.mode === "check" ? "exists_check" : "exists_register") : json.status; }
      function dateText(json) {
        if (json.status === "registered") { return "Registered " + json.date_label; }
        if (json.status === "exists") { return json.mode === "check" ? "Registered " + json.date_label : "Since " + json.date_label; }
        return "";
      }

      function labelBadge(json) {
        return json && json.label ? el("span", "badge " + json.label_class, json.label) : el("span", "badge bg-light text-muted border", "no label");
      }

      function showResult(look, serial, detail, action, json) {
        var card = $("siResult");
        card.className = "rounded p-3 mb-3 text-center border " + LOOK[look].cls;
        card.innerHTML = "";
        card.append(el("div", "font-monospace fw-semibold si-serial", serial));
        card.append(el("div", "si-status", LOOK[look].status));
        if (json && (json.status === "registered" || json.status === "exists")) {
          var tag = el("div", "mb-1");
          tag.append(labelBadge(json));
          card.append(tag);
        }
        if (detail) { card.append(el("div", "small", detail)); }
        if (action) { var wrap = el("div", "mt-2"); wrap.append(action); card.append(wrap); }
      }

      function updateCounts() {
        $("siCountNew").textContent = counts.registered;
        $("siCountExists").textContent = counts.exists;
        $("siCountMissing").textContent = counts.not_found;
        $("siEmpty").classList.toggle("d-none", rows.length > 0);
        $("siCopy").disabled = rows.length === 0;
      }

      function post(data) {
        var body = new FormData();
        body.append("csrf_token", csrf);
        Object.keys(data).forEach(function (k) { body.append(k, data[k]); });
        return fetch("serial_scan.php", { method: "POST", body: body })
          .then(function (res) { return res.json().catch(function () { return { errors: ["Unexpected server response (" + res.status + ")."] }; }); })
          .then(function (json) { if (json.errors) { throw json.errors; } return json; });
      }

      function button(content, cls, title) {
        var b = el("button", "btn btn-sm " + cls);
        b.type = "button";
        if (title) { b.title = title; b.setAttribute("aria-label", title); }
        if (content.indexOf("bi-") === 0) { b.append(el("i", "bi " + content)); } else { b.textContent = content; }
        return b;
      }

      // One row per scan; it is updated in place (Register now, Undo).
      function addRow(json) {
        var row = { serial: json.serial, look: lookFor(json), date: json.date || "", label: json.label || "", time: new Date().toLocaleTimeString([], { hour: "numeric", minute: "2-digit" }) };
        rows.unshift(row);
        var li = el("li", "list-group-item d-flex flex-wrap align-items-center gap-2");
        row.li = li;
        $("siList").insertBefore(li, $("siList").firstChild);
        drawRow(row, json);
        return row;
      }

      function drawRow(row, json) {
        var li = row.li;
        li.innerHTML = "";
        li.classList.toggle("opacity-50", row.look === "removed");
        li.append(el("span", "font-monospace fw-semibold text-break", row.serial));
        var badge = { registered: "bg-success", exists_register: "bg-warning text-dark", exists_check: "bg-success", not_found: "bg-danger", removed: "bg-light text-muted border" }[row.look];
        li.append(el("span", "badge " + badge, row.look === "removed" ? "Removed" : LOOK[row.look].status));
        if (json && row.look !== "not_found" && row.look !== "removed") { li.append(labelBadge(json)); }
        var actions = el("span", "ms-auto d-flex gap-1 align-items-center");
        actions.append(el("span", "small text-muted", (row.date && row.look !== "removed" && row.look !== "not_found" ? dateLabel(row.date) + " · " : "") + row.time));
        if (row.look === "registered" && json && json.id) {
          var undo = button("bi-x-lg", "btn-outline-danger", "Undo this registration");
          undo.addEventListener("click", function () {
            undo.disabled = true;
            post({ action: "undo", id: json.id })
              .then(function () { counts.registered--; row.look = "removed"; drawRow(row); updateCounts(); })
              .catch(function (err) { undo.disabled = false; alert(Array.isArray(err) ? err.join("\n") : "Could not undo."); });
          });
          actions.append(undo);
        }
        if (row.look === "not_found") {
          var reg = button("Register now", "btn-outline-success");
          reg.addEventListener("click", function () { registerNow(row, reg); });
          actions.append(reg);
        }
        li.append(actions);
      }

      function dateLabel(ymd) {
        var d = new Date(ymd + "T00:00:00");
        return isNaN(d) ? ymd : d.toLocaleDateString("en-US", { month: "short", day: "numeric", year: "numeric" });
      }

      function registerNow(row, btn) {
        if (btn) { btn.disabled = true; }
        post({ action: "scan", raw: row.serial, mode: "register", label_id: labelId() }).then(function (json) {
          counts.not_found--;
          counts[json.status === "registered" ? "registered" : "exists"]++;
          row.look = lookFor(json); row.date = json.date || ""; row.label = json.label || "";
          drawRow(row, json);
          updateCounts();
          beep(json.status);
          showResult(row.look, json.serial, dateText(json), null, json);
        }).catch(function (err) {
          if (btn) { btn.disabled = false; }
          beep("error");
          showResult("error", row.serial, Array.isArray(err) ? err.join(" ") : "Could not register. Check your connection.");
        });
      }

      // Scanned / typed / photo text -> server -> result card + list.
      function handle(raw, source) {
        showResult("pending", raw.trim().slice(0, 60) || "…", source ? "From " + source : "");
        post({ action: "scan", raw: raw, mode: mode(), label_id: labelId() }).then(function (json) {
          counts[json.status]++;
          var row = addRow(json);
          updateCounts();
          beep(json.status === "exists" && json.mode === "check" ? "found" : json.status);
          var action = null;
          if (json.status === "not_found") {
            action = button("Register now", "btn-success");
            action.addEventListener("click", function () { registerNow(row, action); });
          }
          var cleaned = raw.trim().toUpperCase() !== json.serial ? " · read as \"" + raw.trim().replace(/\s+/g, " ").slice(0, 40) + "\"" : "";
          showResult(row.look, json.serial, dateText(json) + cleaned, action, json);
        }).catch(function (err) {
          beep("error");
          showResult("error", raw.trim().slice(0, 60) || "…", Array.isArray(err) ? err.join(" ") : "Could not reach the server. Check your connection.");
        });
      }

      $("siCopy").addEventListener("click", function () {
        var names = { registered: "Registered", exists_register: "Already registered", exists_check: "Exists", not_found: "Not registered", removed: "Removed" };
        var text = "Serial\tStatus\tRegistered Date\tLabel\r\n" + rows.slice().reverse().map(function (r) {
          var gone = r.look === "not_found" || r.look === "removed";
          return [r.serial, names[r.look], gone ? "" : r.date, gone ? "" : r.label].join("\t");
        }).join("\r\n");
        EmailGen.copyText(text).then(function (ok) {
          EmailGen.toast(ok ? "Copied " + rows.length + " row" + (rows.length === 1 ? "" : "s") + " — paste into Excel." : "Could not copy.", ok ? "success" : "danger");
        });
      });

      /* ---------- Camera ---------- */

      var formats = [
        Html5QrcodeSupportedFormats.QR_CODE, Html5QrcodeSupportedFormats.CODE_128, Html5QrcodeSupportedFormats.CODE_39,
        Html5QrcodeSupportedFormats.EAN_13, Html5QrcodeSupportedFormats.DATA_MATRIX
      ];
      var libConfig = { formatsToSupport: formats, verbose: false, experimentalFeatures: { useBarCodeDetectorIfSupported: true } };
      // Scan box about 70% of the width; wide enough for 1D barcodes.
      var scanConfig = {
        fps: 10,
        qrbox: function (w, h) { var bw = Math.max(160, Math.floor(w * 0.7)); return { width: bw, height: Math.max(100, Math.floor(Math.min(h * 0.7, bw * 0.6))) }; }
      };

      // iOS Safari only plays inline video that is muted and marked playsinline.
      new MutationObserver(function () {
        var v = $("siReader").querySelector("video");
        if (v && !v.hasAttribute("playsinline")) {
          v.setAttribute("playsinline", ""); v.setAttribute("webkit-playsinline", ""); v.setAttribute("muted", ""); v.setAttribute("autoplay", "");
          v.muted = true; v.playsInline = true;
        }
      }).observe($("siReader"), { childList: true, subtree: true });

      function onScan(text) {
        var now = Date.now();
        var repeat = text === lastText && now - lastAt < REPEAT_MS;
        lastText = text; lastAt = now;   // a label held in view keeps restarting the 3 s window
        if (repeat) { return; }
        handle(text, "camera");
      }

      function startWith(camera) { return scanner.start(camera, scanConfig, onScan, function () {}); }

      function fillCameras(runningId) {
        var select = $("siCamera");
        select.innerHTML = "";
        cameras.forEach(function (c, i) {
          var o = el("option", null, c.label || "Camera " + (i + 1));
          o.value = c.id;
          select.append(o);
        });
        if (runningId) { select.value = runningId; }
        $("siCameraWrap").classList.toggle("d-none", cameras.length < 2);
      }

      function setupExtras() {
        var caps = {};
        try { caps = scanner.getRunningTrackCapabilities() || {}; } catch (e) {}
        var torch = $("siTorch");
        torch.classList.toggle("d-none", !caps.torch);
        torch.setAttribute("aria-pressed", "false");
        torch.classList.remove("active");
        var zoomWrap = $("siZoomWrap"), zoom = $("siZoom");
        if (caps.zoom && caps.zoom.max > caps.zoom.min) {
          zoom.min = caps.zoom.min; zoom.max = Math.min(caps.zoom.max, caps.zoom.min * 8); zoom.step = caps.zoom.step || 0.1;
          var settings = {};
          try { settings = scanner.getRunningTrackSettings() || {}; } catch (e) {}
          zoom.value = settings.zoom || caps.zoom.min;
          zoomWrap.classList.remove("d-none"); zoomWrap.classList.add("d-flex");
        } else {
          zoomWrap.classList.add("d-none"); zoomWrap.classList.remove("d-flex");
        }
      }

      function running() {
        $("siStart").classList.add("d-none");
        $("siStop").classList.remove("d-none");
        $("siStatus").textContent = "Camera on — point it at a label.";
        var old = $("siCamErr"); if (old) { old.remove(); }
        var id = null;
        try { id = (scanner.getRunningTrackSettings() || {}).deviceId; } catch (e) {}
        if (id) { store(CAMERA_KEY, id); }
        fillCameras(id);
        setupExtras();
      }

      // Order: the camera picked last time, then the back camera, then any camera.
      function startCamera() {
        if (!scanner) { scanner = new Html5Qrcode("siReader", libConfig); }
        $("siReader").style.display = "";
        $("siStatus").textContent = "Starting the camera…";
        var lastError = null;
        // Listing the cameras asks for permission; it must run from the tap (iOS).
        return Html5Qrcode.getCameras().then(function (list) {
          cameras = list || [];
          if (!cameras.length) { throw new Error("NotFoundError no camera"); }
          var saved = store(CAMERA_KEY);
          var back = cameras.filter(function (c) { return /back|rear|environment|trasera|arri[eè]re/i.test(c.label); });
          var tries = [];
          if (saved && cameras.some(function (c) { return c.id === saved; })) { tries.push(saved); }
          tries.push({ facingMode: { exact: "environment" } }, { facingMode: "environment" });
          if (back.length) { tries.push(back[back.length - 1].id); }
          tries.push(cameras[0].id);
          return tries.reduce(function (chain, camera) {
            return chain.catch(function (err) { if (err) { lastError = err; } return startWith(camera); });
          }, Promise.reject(null)).catch(function (err) { throw err || lastError; });
        }).then(running).catch(function (err) {
          $("siReader").style.display = "none";
          cameraError(err);
        });
      }

      function stopCamera(note) {
        if (!scanner || !scanner.isScanning) { return Promise.resolve(); }
        return scanner.stop().catch(function () {}).then(function () {
          $("siReader").style.display = "none";
          $("siStop").classList.add("d-none");
          $("siStart").classList.remove("d-none");
          $("siTorch").classList.add("d-none");
          $("siZoomWrap").classList.add("d-none"); $("siZoomWrap").classList.remove("d-flex");
          $("siStatus").textContent = note || "Camera stopped.";
        });
      }

      $("siStart").addEventListener("click", function () {
        try { if (!audio) { audio = new (window.AudioContext || window.webkitAudioContext)(); } } catch (e) {}   // iPhone: sound needs a tap first
        startCamera();
      });
      $("siStop").addEventListener("click", function () { stopCamera(); });
      $("siCamera").addEventListener("change", function () {
        var id = this.value;
        store(CAMERA_KEY, id);
        stopCamera("Switching camera…").then(function () {
          $("siReader").style.display = "";
          startWith(id).then(running).catch(function (err) { $("siReader").style.display = "none"; cameraError(err); });
        });
      });
      $("siTorch").addEventListener("click", function () {
        var btn = this, on = btn.getAttribute("aria-pressed") !== "true";
        scanner.applyVideoConstraints({ advanced: [{ torch: on }] })
          .then(function () { btn.setAttribute("aria-pressed", String(on)); btn.classList.toggle("active", on); })
          .catch(function () { EmailGen.toast("Flash isn't available on this camera.", "warning"); });
      });
      $("siZoom").addEventListener("input", function () {
        var value = parseFloat(this.value);
        scanner.applyVideoConstraints({ advanced: [{ zoom: value }] }).catch(function () {});
      });

      // Release the camera when leaving the page or switching tabs.
      document.addEventListener("visibilitychange", function () {
        if (document.hidden) { stopCamera("Camera paused while the page was hidden. Tap Start Camera to continue."); }
      });
      window.addEventListener("pagehide", function () { if (scanner && scanner.isScanning) { scanner.stop().catch(function () {}); } });

      /* ---------- Photo and manual entry ---------- */

      $("siFile").addEventListener("change", function () {
        var input = this, file = input.files[0];
        if (!file) { return; }
        if (!fileScanner) { fileScanner = new Html5Qrcode("siFileReader", libConfig); }
        $("siStatus").textContent = "Reading the photo…";
        fileScanner.scanFile(file, false)
          .then(function (text) { $("siStatus").textContent = "Photo read."; handle(text, "photo"); })
          .catch(function () {
            beep("error");
            $("siStatus").textContent = "No QR code or barcode found in that photo. Try a sharper, closer photo with the label filling most of it, or type the serial.";
          })
          .then(function () { input.value = ""; });
      });

      function manual() {
        var input = $("siManual");
        if (input.value.trim() === "") { input.focus(); return; }
        handle(input.value, "typed");
        input.value = "";
        input.focus();
      }
      $("siManualGo").addEventListener("click", manual);
      $("siManual").addEventListener("keydown", function (e) { if (e.key === "Enter") { e.preventDefault(); manual(); } });

      updateCounts();
    })();
  </script>

<?php
require __DIR__ . '/includes/footer.php';
require __DIR__ . '/includes/scripts.php';
