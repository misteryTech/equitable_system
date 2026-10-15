<?php
/**
 * Admin: set each user's (field engineers and admins) regular Time In / Time Out.
 * GET shows the page; POST (AJAX) saves one or more users and returns JSON.
 * The schedule is copied into time_logs at TIME IN, so changes here only
 * affect days timed in afterwards.
 */
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/time_logs.php';
$user = require_role('admin');

$pageTitle  = 'User Schedules';
$activePage = 'engineer-schedules';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $respond = static function (array $data, int $status = 200): void {
        http_response_code($status);
        header('Content-Type: application/json');
        echo json_encode($data);
        exit;
    };

    if (!csrf_verify()) {
        $respond(['error' => 'Your session expired. Please reload the page.'], 403);
    }

    $ids     = array_values(array_unique(array_filter(array_map('intval', (array) ($_POST['user_ids'] ?? [])))));
    $timeIn  = trim((string) ($_POST['time_in'] ?? ''));
    $timeOut = trim((string) ($_POST['time_out'] ?? ''));
    $isTime  = static fn(string $t): bool => (bool) preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $t);

    if (!$ids) {
        $respond(['error' => 'Select at least one user.'], 422);
    }
    if (!$isTime($timeIn) || !$isTime($timeOut)) {
        $respond(['error' => 'Both Time In and Time Out are required.'], 422);
    }
    if ($timeIn === $timeOut) {
        $respond(['error' => 'Time Out must be different from Time In.'], 422);
    }

    // Field engineers and admins can be given a schedule.
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = db()->prepare("SELECT user_id FROM users WHERE role IN ('field_engineer', 'admin') AND user_id IN ($placeholders)");
    $stmt->execute($ids);
    $engineerIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    if (count($engineerIds) !== count($ids)) {
        $respond(['error' => 'Some of the selected users were not found.'], 422);
    }

    db()->prepare("UPDATE users SET reg_time_in = ?, reg_time_out = ? WHERE role IN ('field_engineer', 'admin') AND user_id IN ($placeholders)")
        ->execute(array_merge(["$timeIn:00", "$timeOut:00"], $ids));

    $respond([
        'ok'        => true,
        'user_ids'  => $engineerIds,
        'time_in'   => $timeIn,
        'time_out'  => $timeOut,
        'in_label'  => time_log_clock("$timeIn:00"),
        'out_label' => time_log_clock("$timeOut:00"),
        'overnight' => $timeOut < $timeIn,
    ]);
}

// Date range for the hour totals columns (defaults to this month).
$isDate = static function ($value): bool {
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', (string) $value);
    return $d !== false && $d->format('Y-m-d') === $value;
};
$otFrom = $isDate($_GET['from'] ?? '') ? $_GET['from'] : date('Y-m-01');
$otTo   = $isDate($_GET['to'] ?? '') ? $_GET['to'] : date('Y-m-d');
if ($otFrom > $otTo) {
    [$otFrom, $otTo] = [$otTo, $otFrom];
}

$stmt = db()->prepare(
    "SELECT u.user_id, u.role, u.field_engineer_id, u.first_name, u.middle_name, u.last_name, u.location,
            u.reg_time_in, u.reg_time_out,
            COALESCE(SUM(t.total_hrs), 0) AS range_total_hrs,
            COALESCE(SUM(t.early_in_hrs), 0) AS range_early_hrs,
            COALESCE(SUM(t.ot_hrs), 0) AS range_ot_hrs,
            COALESCE(SUM(t.early_in_hrs + t.ot_hrs), 0) AS range_extra_hrs
     FROM users u
     LEFT JOIN time_logs t ON t.user_id = u.user_id AND t.work_date BETWEEN ? AND ? AND t.time_out IS NOT NULL
     WHERE u.role IN ('field_engineer', 'admin')
     GROUP BY u.user_id
     ORDER BY u.last_name, u.first_name"
);
$stmt->execute([$otFrom, $otTo]);
$engineers = $stmt->fetchAll();

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
          <li class="breadcrumb-item active"><?= e($pageTitle) ?></li>
        </ol>
      </nav>
    </div><!-- End Page Title -->

    <section class="section">
      <div class="card">
        <div class="card-body pt-3">

          <div class="row g-2 align-items-center mb-3">
            <div class="col-md-5">
              <div class="input-group">
                <span class="input-group-text"><i class="bi bi-search"></i></span>
                <input type="search" id="scheduleSearch" class="form-control" placeholder="Search users by name" aria-label="Search users by name">
              </div>
            </div>
            <div class="col-md-7 text-md-end">
              <button type="button" class="btn btn-primary" id="bulkEdit" disabled>
                <i class="bi bi-people"></i> Set schedule for selected (<span id="selectedCount">0</span>)
              </button>
            </div>
          </div>

          <form method="get" class="row g-2 align-items-end mb-3">
            <div class="col-auto"><span class="form-label d-block small text-muted mb-1">Hour totals for</span></div>
            <div class="col-sm-4 col-md-3">
              <label for="otFrom" class="visually-hidden">From</label>
              <input type="date" name="from" id="otFrom" class="form-control form-control-sm" value="<?= e($otFrom) ?>">
            </div>
            <div class="col-sm-4 col-md-3">
              <label for="otTo" class="visually-hidden">To</label>
              <input type="date" name="to" id="otTo" class="form-control form-control-sm" value="<?= e($otTo) ?>">
            </div>
            <div class="col-auto">
              <button type="submit" class="btn btn-sm btn-outline-secondary">Show</button>
            </div>
          </form>

          <div id="scheduleMessage" class="alert d-none" role="status"></div>

          <p class="small text-muted">Changes apply from the user's next TIME IN. Days already timed in keep the schedule they had.</p>

          <div class="table-responsive">
            <table class="table align-middle" id="scheduleTable">
              <thead>
                <tr>
                  <th style="width: 2.5rem"><input class="form-check-input" type="checkbox" id="selectAll" aria-label="Select all shown users"></th>
                  <th>Name</th>
                  <th>Regular Time In</th>
                  <th>Regular Time Out</th>
<?php $rangeTitle = e(time_log_date($otFrom) . ' – ' . time_log_date($otTo)); ?>
                  <th class="text-end" title="<?= $rangeTitle ?>">Total Hrs</th>
                  <th class="text-end" title="<?= $rangeTitle ?>">Early In</th>
                  <th class="text-end" title="<?= $rangeTitle ?>">OT Hrs</th>
                  <th class="text-end" title="Early In + OT, <?= $rangeTitle ?>">Extra Hrs</th>
                  <th class="text-end">Action</th>
                </tr>
              </thead>
              <tbody>
<?php foreach ($engineers as $eng):
    $name = full_name($eng);
?>
                <tr data-user-id="<?= (int) $eng['user_id'] ?>"
                    data-name="<?= e($name) ?>"
                    data-search="<?= e(strtolower($name . ' ' . $eng['field_engineer_id'])) ?>"
                    data-in="<?= e(substr((string) $eng['reg_time_in'], 0, 5)) ?>"
                    data-out="<?= e(substr((string) $eng['reg_time_out'], 0, 5)) ?>">
                  <td><input class="form-check-input row-check" type="checkbox" aria-label="Select <?= e($name) ?>"></td>
                  <td>
                    <?= e($name) ?>
                    <span class="badge <?= $eng['role'] === 'admin' ? 'bg-danger' : 'bg-primary' ?> ms-1"><?= e(role_label($eng['role'])) ?></span><br>
                    <span class="small text-muted"><?= e(implode(' · ', array_filter([$eng['field_engineer_id'], $eng['location']]))) ?></span>
                  </td>
<?php foreach (['reg_time_in' => 'in', 'reg_time_out' => 'out'] as $col => $key): ?>
                  <td data-cell="<?= $key ?>">
<?php if ($eng['reg_time_in'] && $eng['reg_time_out']): ?>
                    <?= e(time_log_clock($eng[$col])) ?><?= $key === 'out' && $eng['reg_time_out'] < $eng['reg_time_in'] ? ' <span class="badge bg-secondary">next day</span>' : '' ?>
<?php else: ?>
                    <span class="text-muted fst-italic">Not set</span>
<?php endif; ?>
                  </td>
<?php endforeach; ?>
<?php $recordsUrl = 'time_records.php?user=' . (int) $eng['user_id'] . '&from=' . $otFrom . '&to=' . $otTo; ?>
<?php foreach (['range_total_hrs', 'range_early_hrs', 'range_ot_hrs', 'range_extra_hrs'] as $sum): ?>
                  <td class="text-end"><a href="<?= e($recordsUrl) ?>" title="View records"><?= e(time_log_hours($eng[$sum], true)) ?></a></td>
<?php endforeach; ?>
                  <td class="text-end">
                    <button type="button" class="btn btn-sm btn-outline-primary edit-schedule"><i class="bi bi-pencil"></i> Edit</button>
                  </td>
                </tr>
<?php endforeach; ?>
                <tr id="noMatches" class="d-none"><td colspan="9" class="text-muted text-center py-4">No users match your search.</td></tr>
<?php if (!$engineers): ?>
                <tr><td colspan="9" class="text-muted text-center py-4">No users yet.</td></tr>
<?php endif; ?>
              </tbody>
            </table>
          </div>

        </div>
      </div>
    </section>

  </main><!-- End #main -->

  <!-- Edit schedule modal (single user or bulk) -->
  <div class="modal fade" id="scheduleModal" tabindex="-1" aria-labelledby="scheduleModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
      <form class="modal-content" id="scheduleForm" novalidate>
        <div class="modal-header">
          <h5 class="modal-title" id="scheduleModalTitle">Set Schedule</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <p class="small text-muted mb-3" id="scheduleModalFor"></p>
          <div class="row g-3">
            <div class="col-6">
              <label for="schedIn" class="form-label">Time In</label>
              <input type="time" class="form-control" id="schedIn" required>
            </div>
            <div class="col-6">
              <label for="schedOut" class="form-label">Time Out</label>
              <input type="time" class="form-control" id="schedOut" required>
            </div>
          </div>
          <div class="form-text" id="overnightHint" hidden>Overnight schedule: Time Out is on the next day.</div>
          <div class="alert alert-danger py-2 mt-3 mb-0 d-none" id="scheduleError"></div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary" id="scheduleSave">Save</button>
        </div>
      </form>
    </div>
  </div>

  <script>
    (function () {
      var csrf = <?= json_encode(csrf_token()) ?>;
      var table = document.getElementById("scheduleTable");
      var search = document.getElementById("scheduleSearch");
      var selectAll = document.getElementById("selectAll");
      var bulkBtn = document.getElementById("bulkEdit");
      var message = document.getElementById("scheduleMessage");
      var form = document.getElementById("scheduleForm");
      var inField = document.getElementById("schedIn");
      var outField = document.getElementById("schedOut");
      var errorBox = document.getElementById("scheduleError");
      var saveBtn = document.getElementById("scheduleSave");
      var editing = [];   // user ids the modal will save

      function rows() { return Array.prototype.slice.call(table.querySelectorAll("tbody tr[data-user-id]")); }
      function visibleRows() { return rows().filter(function (r) { return !r.classList.contains("d-none"); }); }
      function checkedRows() { return rows().filter(function (r) { return r.querySelector(".row-check").checked; }); }

      function refreshSelection() {
        var n = checkedRows().length;
        document.getElementById("selectedCount").textContent = n;
        bulkBtn.disabled = n === 0;
        var visible = visibleRows();
        var visibleChecked = visible.filter(function (r) { return r.querySelector(".row-check").checked; }).length;
        selectAll.checked = visible.length > 0 && visibleChecked === visible.length;
        selectAll.indeterminate = visibleChecked > 0 && visibleChecked < visible.length;
      }

      search.addEventListener("input", function () {
        var q = search.value.trim().toLowerCase();
        var shown = 0;
        rows().forEach(function (r) {
          var match = r.dataset.search.indexOf(q) !== -1;
          r.classList.toggle("d-none", !match);
          if (match) { shown++; }
        });
        document.getElementById("noMatches").classList.toggle("d-none", shown > 0 || rows().length === 0);
        refreshSelection();
      });

      selectAll.addEventListener("change", function () {
        visibleRows().forEach(function (r) { r.querySelector(".row-check").checked = selectAll.checked; });
        refreshSelection();
      });

      table.addEventListener("change", function (e) {
        if (e.target.classList.contains("row-check")) { refreshSelection(); }
      });

      function showOvernightHint() {
        document.getElementById("overnightHint").hidden = !(inField.value && outField.value && outField.value < inField.value);
      }
      inField.addEventListener("input", showOvernightHint);
      outField.addEventListener("input", showOvernightHint);

      function openModal(targetRows) {
        editing = targetRows.map(function (r) { return Number(r.dataset.userId); });
        var names = targetRows.map(function (r) { return r.dataset.name; });
        document.getElementById("scheduleModalTitle").textContent = targetRows.length === 1 ? "Edit Schedule" : "Set Schedule for " + targetRows.length + " Users";
        document.getElementById("scheduleModalFor").textContent = names.length <= 5 ? names.join(", ") : names.slice(0, 5).join(", ") + " and " + (names.length - 5) + " more";
        // Pre-fill with the user's schedule (single edit), or a 9:00 AM – 6:00 PM default for bulk.
        inField.value = targetRows.length === 1 ? (targetRows[0].dataset.in || "09:00") : "09:00";
        outField.value = targetRows.length === 1 ? (targetRows[0].dataset.out || "18:00") : "18:00";
        errorBox.classList.add("d-none");
        showOvernightHint();
        bootstrap.Modal.getOrCreateInstance(document.getElementById("scheduleModal")).show();
      }

      table.addEventListener("click", function (e) {
        var btn = e.target.closest(".edit-schedule");
        if (btn) { openModal([btn.closest("tr")]); }
      });
      bulkBtn.addEventListener("click", function () { openModal(checkedRows()); });

      function showError(text) {
        errorBox.textContent = text;
        errorBox.classList.remove("d-none");
      }

      function renderCell(cell, label, isNextDay) {
        cell.textContent = label;
        if (isNextDay) {
          var badge = document.createElement("span");
          badge.className = "badge bg-secondary ms-1";
          badge.textContent = "next day";
          cell.appendChild(badge);
        }
      }

      form.addEventListener("submit", function (e) {
        e.preventDefault();
        if (!inField.value || !outField.value) { showError("Both Time In and Time Out are required."); return; }
        if (inField.value === outField.value) { showError("Time Out must be different from Time In."); return; }

        var body = new FormData();
        body.append("csrf_token", csrf);
        body.append("time_in", inField.value);
        body.append("time_out", outField.value);
        editing.forEach(function (id) { body.append("user_ids[]", id); });

        saveBtn.disabled = true;
        fetch("engineer_schedules.php", { method: "POST", body: body })
          .then(function (res) {
            return res.json().catch(function () { return { error: "Unexpected server response (" + res.status + ")." }; });
          })
          .then(function (json) {
            if (json.error) { throw new Error(json.error); }
            json.user_ids.forEach(function (id) {
              var row = table.querySelector('tr[data-user-id="' + id + '"]');
              row.dataset.in = json.time_in;
              row.dataset.out = json.time_out;
              renderCell(row.querySelector('[data-cell="in"]'), json.in_label, false);
              renderCell(row.querySelector('[data-cell="out"]'), json.out_label, json.overnight);
              row.querySelector(".row-check").checked = false;
            });
            refreshSelection();
            bootstrap.Modal.getOrCreateInstance(document.getElementById("scheduleModal")).hide();
            message.className = "alert alert-success";
            message.textContent = "Schedule " + json.in_label + " – " + json.out_label + " saved for " +
              json.user_ids.length + " user" + (json.user_ids.length === 1 ? "" : "s") + ".";
          })
          .catch(function (err) { showError(err.message); })
          .finally(function () { saveBtn.disabled = false; });
      });
    })();
  </script>

<?php
require __DIR__ . '/includes/footer.php';
require __DIR__ . '/includes/scripts.php';
