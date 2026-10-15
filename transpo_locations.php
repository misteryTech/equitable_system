<?php
/**
 * Admin: transpo locations (bases, merchants, landmarks/dropoints).
 * List, search, edit name/type/address, manage aliases, and MERGE duplicates.
 */
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/transpo.php';
$user = require_role('admin');

$pageTitle  = 'Transpo Locations';
$activePage = 'transpo-locations';

$q    = trim((string) ($_GET['q'] ?? ''));
$type = array_key_exists($_GET['type'] ?? '', TRANSPO_LOCATION_TYPES) ? $_GET['type'] : '';
$back = 'transpo_locations.php' . (($qs = http_build_query(array_filter(['q' => $q, 'type' => $type]))) ? "?$qs" : '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $flash  = null;

    if (!csrf_verify()) {
        $flash = ['danger', 'Your session expired. Please try again.'];
    } elseif ($action === 'merge') {
        $keepId = (int) ($_POST['keep_id'] ?? 0);
        $ids    = array_map('intval', (array) ($_POST['ids'] ?? []));
        try {
            $s = transpo_merge_locations($keepId, $ids);
            $keep = transpo_location($keepId);
            $flash = ['success', "Merged {$s['merged']} location(s) into \"{$keep['name']}\": {$s['visits']} ticket(s), {$s['legs']} leg end(s), "
                . "{$s['rates_moved']} route fare(s) moved, {$s['rates_combined']} duplicate route fare(s) combined, {$s['aliases']} name(s) kept as aliases."];
        } catch (InvalidArgumentException $ex) {
            $flash = ['danger', $ex->getMessage()];
        }
    } else {
        $location = transpo_location((int) ($_POST['id'] ?? 0));
        if (!$location) {
            $flash = ['danger', 'Location not found.'];
        } elseif ($action === 'edit') {
            $name    = transpo_clean_name((string) ($_POST['name'] ?? ''));
            $norm    = transpo_normalize($name);
            $newType = array_key_exists($_POST['type'] ?? '', TRANSPO_LOCATION_TYPES) ? $_POST['type'] : $location['type'];
            $address = trim((string) ($_POST['address'] ?? ''));
            $other   = $norm !== '' ? transpo_find_location($name) : null;
            if ($norm === '' || mb_strlen($name) > 150 || mb_strlen($address) > 255) {
                $flash = ['danger', 'Name is required (up to 150 characters); address up to 255.'];
            } elseif ($other && (int) $other['id'] !== (int) $location['id']) {
                $flash = ['warning', "\"$name\" already exists as \"{$other['name']}\". Use Merge to combine them instead."];
            } else {
                // Renaming to one of its own aliases: swap (old name becomes the alias).
                db()->prepare('DELETE FROM transpo_location_aliases WHERE location_id = ? AND normalized_alias = ?')->execute([$location['id'], $norm]);
                db()->prepare('UPDATE transpo_locations SET name = ?, normalized_name = ?, type = ?, address = ? WHERE id = ?')
                    ->execute([$name, $norm, $newType, $address === '' ? null : $address, $location['id']]);
                if ($norm !== $location['normalized_name']) {
                    transpo_add_alias((int) $location['id'], $location['name']);
                }
                $flash = ['success', "Saved \"$name\"."];
            }
        } elseif ($action === 'add_alias') {
            $alias = transpo_clean_name((string) ($_POST['alias'] ?? ''));
            $match = transpo_find_location($alias);
            if (transpo_normalize($alias) === '') {
                $flash = ['danger', 'Type the other spelling to add.'];
            } elseif ($match) {
                $flash = ['warning', (int) $match['id'] === (int) $location['id']
                    ? "\"$alias\" already matches \"{$location['name']}\"."
                    : "\"$alias\" already belongs to \"{$match['name']}\". Use Merge if they are the same place."];
            } else {
                transpo_add_alias((int) $location['id'], $alias);
                $flash = ['success', "\"$alias\" now matches \"{$location['name']}\"."];
            }
        } elseif ($action === 'remove_alias') {
            db()->prepare('DELETE FROM transpo_location_aliases WHERE id = ? AND location_id = ?')->execute([(int) ($_POST['alias_id'] ?? 0), $location['id']]);
            $flash = ['success', 'Alias removed.'];
        } elseif ($action === 'delete') {
            $stmt = db()->prepare(
                'SELECT (SELECT COUNT(*) FROM transpo_trip_legs WHERE from_location_id = ? OR to_location_id = ?)
                      + (SELECT COUNT(*) FROM transpo_ticket_visits WHERE merchant_location_id = ?)
                      + (SELECT COUNT(*) FROM transpo_route_rates WHERE from_location_id = ? OR to_location_id = ?)
                      + (SELECT COUNT(*) FROM users WHERE transpo_base_location_id = ?)'
            );
            $id = (int) $location['id'];
            $stmt->execute([$id, $id, $id, $id, $id, $id]);
            if ((int) $stmt->fetchColumn() > 0) {
                $flash = ['warning', "\"{$location['name']}\" is used by tickets, legs, route fares or an engineer's base, so it can't be deleted. Merge it into the right location instead."];
            } else {
                db()->prepare('DELETE FROM transpo_locations WHERE id = ?')->execute([$id]);
                $flash = ['success', "Deleted \"{$location['name']}\"."];
            }
        } else {
            $flash = ['danger', 'Unknown action.'];
        }
    }
    $_SESSION['flash'] = $flash;
    redirect($back);
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$sql = 'SELECT DISTINCT l.* FROM transpo_locations l LEFT JOIN transpo_location_aliases a ON a.location_id = l.id WHERE 1 = 1';
$params = [];
if ($q !== '') {
    $sql .= ' AND (l.normalized_name LIKE ? OR a.normalized_alias LIKE ? OR l.address LIKE ?)';
    $like = '%' . addcslashes(transpo_normalize($q), '%_\\') . '%';
    array_push($params, $like, $like, '%' . addcslashes($q, '%_\\') . '%');
}
if ($type !== '') {
    $sql .= ' AND l.type = ?';
    $params[] = $type;
}
$stmt = db()->prepare($sql . ' ORDER BY l.times_used DESC, l.name LIMIT 500');
$stmt->execute($params);
$locations = $stmt->fetchAll();

$aliases = [];
if ($locations) {
    $ids  = array_column($locations, 'id');
    $stmt = db()->prepare('SELECT * FROM transpo_location_aliases WHERE location_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY alias');
    $stmt->execute($ids);
    foreach ($stmt->fetchAll() as $a) {
        $aliases[$a['location_id']][] = $a;
    }
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
          <li class="breadcrumb-item active"><?= e($pageTitle) ?></li>
        </ol>
      </nav>
    </div><!-- End Page Title -->

    <section class="section">
<?php if ($flash): ?>
      <div class="alert alert-<?= e($flash[0]) ?>"><?= e($flash[1]) ?></div>
<?php endif; ?>

      <div class="card">
        <div class="card-body pt-3">
          <form method="get" class="row g-2 align-items-end mb-3">
            <div class="col-md-5"><label class="form-label" for="q">Search</label><input type="search" id="q" name="q" class="form-control" placeholder="Name, alias or address" value="<?= e($q) ?>"></div>
            <div class="col-md-3"><label class="form-label" for="type">Type</label>
              <select id="type" name="type" class="form-select"><option value="">All</option>
<?php foreach (TRANSPO_LOCATION_TYPES as $key => $label): ?>
                <option value="<?= e($key) ?>" <?= $type === $key ? 'selected' : '' ?>><?= e($label) ?></option>
<?php endforeach; ?>
              </select></div>
            <div class="col-md-4 d-flex gap-2"><button type="submit" class="btn btn-primary"><i class="bi bi-funnel"></i> Filter</button>
              <button type="button" class="btn btn-warning ms-auto" id="mergeBtn" disabled><i class="bi bi-intersect"></i> Merge selected (<span id="mergeCount">0</span>)</button></div>
          </form>
          <p class="small text-muted">Tick two or more entries that are the same place, then <strong>Merge selected</strong>. Everything that used them moves to the one you keep, and the other names become aliases.</p>

          <div class="table-responsive">
            <table class="table align-middle">
              <thead><tr><th style="width: 2rem"></th><th>Name</th><th>Address</th><th>Aliases</th><th class="text-end">Used</th><th class="d-none d-md-table-cell">Last used</th><th class="text-end">Action</th></tr></thead>
              <tbody>
<?php foreach ($locations as $l): ?>
                <tr>
                  <td><input type="checkbox" class="form-check-input merge-check" value="<?= (int) $l['id'] ?>" data-name="<?= e($l['name']) ?>" data-used="<?= (int) $l['times_used'] ?>" aria-label="Select <?= e($l['name']) ?>"></td>
                  <td><span class="fw-semibold"><?= e($l['name']) ?></span><br><?= transpo_type_badge($l['type']) ?></td>
                  <td class="small"><?= e($l['address'] ?? '') ?></td>
                  <td class="small">
<?php foreach ($aliases[$l['id']] ?? [] as $a): ?>
                    <form method="post" class="d-inline-block">
                      <?= csrf_field() ?><input type="hidden" name="action" value="remove_alias"><input type="hidden" name="id" value="<?= (int) $l['id'] ?>"><input type="hidden" name="alias_id" value="<?= (int) $a['id'] ?>">
                      <span class="badge bg-light text-dark border"><?= e($a['alias']) ?>
                        <button type="submit" class="btn-close" style="font-size: .5rem" aria-label="Remove alias <?= e($a['alias']) ?>" onclick="return confirm('Remove this alias?')"></button></span>
                    </form>
<?php endforeach; ?>
                  </td>
                  <td class="text-end"><?= (int) $l['times_used'] ?></td>
                  <td class="small text-muted d-none d-md-table-cell"><?= $l['last_used_at'] ? e(date('M j, Y', strtotime($l['last_used_at']))) : '—' ?></td>
                  <td class="text-end text-nowrap">
                    <button type="button" class="btn btn-sm btn-outline-primary edit-btn" data-id="<?= (int) $l['id'] ?>" data-name="<?= e($l['name']) ?>" data-type="<?= e($l['type']) ?>" data-address="<?= e($l['address'] ?? '') ?>" title="Edit / aliases"><i class="bi bi-pencil"></i></button>
                    <form method="post" class="d-inline" onsubmit="return confirm('Delete this location?')"><?= csrf_field() ?>
                      <input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $l['id'] ?>">
                      <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete (only if unused)"><i class="bi bi-trash"></i></button>
                    </form>
                  </td>
                </tr>
<?php endforeach; ?>
<?php if (!$locations): ?>
                <tr><td colspan="7" class="text-muted text-center py-4">No locations found.</td></tr>
<?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </section>

  </main><!-- End #main -->

  <!-- Edit -->
  <div class="modal fade" id="editModal" tabindex="-1" aria-labelledby="editTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered"><div class="modal-content">
      <div class="modal-header"><h5 class="modal-title" id="editTitle">Edit Location</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
      <div class="modal-body">
        <form method="post" id="editForm">
          <?= csrf_field() ?><input type="hidden" name="action" value="edit"><input type="hidden" name="id" class="loc-id">
          <label class="form-label" for="editName">Name</label>
          <input type="text" name="name" id="editName" class="form-control mb-2" maxlength="150" required>
          <label class="form-label" for="editType">Type</label>
          <select name="type" id="editType" class="form-select mb-2">
<?php foreach (TRANSPO_LOCATION_TYPES as $key => $label): ?>
            <option value="<?= e($key) ?>"><?= e($label) ?></option>
<?php endforeach; ?>
          </select>
          <label class="form-label" for="editAddress">Address</label>
          <input type="text" name="address" id="editAddress" class="form-control mb-3" maxlength="255">
          <button type="submit" class="btn btn-primary">Save</button>
          <span class="small text-muted ms-2">Renaming keeps the old name as an alias.</span>
        </form>
        <hr>
        <form method="post" class="d-flex gap-2">
          <?= csrf_field() ?><input type="hidden" name="action" value="add_alias"><input type="hidden" name="id" class="loc-id">
          <input type="text" name="alias" class="form-control" maxlength="150" placeholder="Other spelling, e.g. MAKAR DROP PT" aria-label="New alias">
          <button type="submit" class="btn btn-outline-primary text-nowrap">Add alias</button>
        </form>
      </div>
    </div></div>
  </div>

  <!-- Merge -->
  <div class="modal fade" id="mergeModal" tabindex="-1" aria-labelledby="mergeTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered"><form method="post" class="modal-content" onsubmit="return confirm('Merge these locations? This cannot be undone.')">
      <div class="modal-header"><h5 class="modal-title" id="mergeTitle">Merge Locations</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
      <div class="modal-body">
        <?= csrf_field() ?><input type="hidden" name="action" value="merge">
        <p>Choose the one to <strong>keep</strong>. The others are merged into it and deleted; their names become aliases.</p>
        <div id="mergeList" class="list-group"></div>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-warning">Merge</button></div>
    </form></div>
  </div>

  <script>
    (function () {
      function modal(id) { return bootstrap.Modal.getOrCreateInstance(document.getElementById(id)); }
      document.querySelectorAll(".edit-btn").forEach(function (b) {
        b.addEventListener("click", function () {
          document.querySelectorAll("#editModal .loc-id").forEach(function (i) { i.value = b.dataset.id; });
          document.getElementById("editName").value = b.dataset.name;
          document.getElementById("editType").value = b.dataset.type;
          document.getElementById("editAddress").value = b.dataset.address;
          modal("editModal").show();
        });
      });

      var checks = document.querySelectorAll(".merge-check");
      var btn = document.getElementById("mergeBtn");
      function selected() { return Array.prototype.filter.call(checks, function (c) { return c.checked; }); }
      checks.forEach(function (c) {
        c.addEventListener("change", function () {
          var n = selected().length;
          document.getElementById("mergeCount").textContent = n;
          btn.disabled = n < 2;
        });
      });
      btn.addEventListener("click", function () {
        var list = document.getElementById("mergeList");
        list.innerHTML = "";
        var picked = selected().sort(function (a, b) { return b.dataset.used - a.dataset.used; });
        picked.forEach(function (c, i) {
          var label = document.createElement("label");
          label.className = "list-group-item d-flex gap-2 align-items-center";
          var radio = document.createElement("input");
          radio.type = "radio"; radio.name = "keep_id"; radio.value = c.value; radio.className = "form-check-input"; radio.checked = i === 0;
          var hidden = document.createElement("input");
          hidden.type = "hidden"; hidden.name = "ids[]"; hidden.value = c.value;
          var text = document.createElement("span");
          text.textContent = c.dataset.name + " (used " + c.dataset.used + "×)";
          label.appendChild(radio); label.appendChild(text); label.appendChild(hidden);
          list.appendChild(label);
        });
        modal("mergeModal").show();
      });
    })();
  </script>

<?php
require __DIR__ . '/includes/footer.php';
require __DIR__ . '/includes/scripts.php';
