<?php
/**
 * Admin: register and manage locations (the Location dropdown on registration).
 * users.location stores the location name, so renaming a location also updates
 * its users, and a location can't be deleted while users are assigned to it.
 */
require_once __DIR__ . '/includes/config.php';
$user = require_role('admin');

$pageTitle  = 'Locations';
$activePage = 'locations';
$errors     = [];

/**
 * Validate a location name; returns an error message or null.
 */
function location_name_error(string $name, int $exceptId = 0): ?string
{
    if ($name === '') {
        return 'Location name is required.';
    }
    if (mb_strlen($name) > 100) {
        return 'Location name must be at most 100 characters.';
    }
    $stmt = db()->prepare('SELECT 1 FROM locations WHERE name = ? AND location_id <> ?');
    $stmt->execute([$name, $exceptId]);
    return $stmt->fetchColumn() ? "\"$name\" already exists." : null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $id     = (int) ($_POST['location_id'] ?? 0);
    $name   = preg_replace('/\s+/', ' ', trim((string) ($_POST['name'] ?? '')));

    if (!csrf_verify()) {
        $errors[] = 'Your session expired. Please try again.';
    } elseif ($action === 'add') {
        if ($error = location_name_error($name)) {
            $errors[] = $error;
        } else {
            db()->prepare('INSERT INTO locations (name) VALUES (?)')->execute([$name]);
            $_SESSION['flash'] = ['success', "Location \"$name\" added. It now appears in the registration dropdown."];
            redirect('locations.php');
        }
    } elseif ($action === 'rename') {
        $stmt = db()->prepare('SELECT name FROM locations WHERE location_id = ?');
        $stmt->execute([$id]);
        $oldName = $stmt->fetchColumn();

        if ($oldName === false) {
            $errors[] = 'Location not found.';
        } elseif ($error = location_name_error($name, $id)) {
            $errors[] = $error;
        } elseif ($name !== $oldName) {
            $pdo = db();
            $pdo->beginTransaction();
            try {
                $pdo->prepare('UPDATE locations SET name = ? WHERE location_id = ?')->execute([$name, $id]);
                $moved = $pdo->prepare('UPDATE users SET location = ? WHERE location = ?');
                $moved->execute([$name, $oldName]);
                $pdo->commit();
            } catch (Throwable $ex) {
                $pdo->rollBack();
                throw $ex;
            }
            $count = $moved->rowCount();
            $_SESSION['flash'] = ['success', "Renamed \"$oldName\" to \"$name\"" . ($count ? " and updated $count user" . ($count === 1 ? '' : 's') : '') . '.'];
            redirect('locations.php');
        } else {
            redirect('locations.php');
        }
    } elseif ($action === 'delete') {
        $stmt = db()->prepare(
            'SELECT l.name, (SELECT COUNT(*) FROM users u WHERE u.location = l.name) AS user_count
             FROM locations l WHERE l.location_id = ?'
        );
        $stmt->execute([$id]);
        $location = $stmt->fetch();

        if (!$location) {
            $errors[] = 'Location not found.';
        } elseif ($location['user_count'] > 0) {
            $errors[] = "\"{$location['name']}\" can't be deleted: {$location['user_count']} user(s) are assigned to it. Move them to another location first, or rename it instead.";
        } else {
            db()->prepare('DELETE FROM locations WHERE location_id = ?')->execute([$id]);
            $_SESSION['flash'] = ['success', "Location \"{$location['name']}\" deleted."];
            redirect('locations.php');
        }
    } else {
        $errors[] = 'Unknown action.';
    }
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$locations = db()->query(
    'SELECT l.location_id, l.name, l.created_at, COUNT(u.user_id) AS user_count
     FROM locations l
     LEFT JOIN users u ON u.location = l.name
     GROUP BY l.location_id
     ORDER BY l.name'
)->fetchAll();

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
<?php if ($errors): ?>
      <div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

      <div class="row">
        <div class="col-lg-4">
          <div class="card">
            <div class="card-body">
              <h5 class="card-title">Register Location</h5>
              <form method="post" class="row g-2">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="add">
                <div class="col-12">
                  <label for="locationName" class="form-label">Location name</label>
                  <input type="text" name="name" id="locationName" class="form-control" maxlength="100"
                         placeholder="e.g. Cebu Depot" value="<?= ($_POST['action'] ?? '') === 'add' ? old('name') : '' ?>" required>
                </div>
                <div class="col-12">
                  <button type="submit" class="btn btn-primary w-100"><i class="bi bi-plus-lg"></i> Add Location</button>
                </div>
              </form>
              <p class="small text-muted mt-3 mb-0">New locations appear right away in the Location dropdown on the registration form.</p>
            </div>
          </div>
        </div>

        <div class="col-lg-8">
          <div class="card">
            <div class="card-body">
              <h5 class="card-title">Locations <span>| <?= count($locations) ?></span></h5>

              <div class="table-responsive">
                <table class="table align-middle text-nowrap">
                  <thead>
                    <tr>
                      <th>Name</th>
                      <th class="text-end">Users</th>
                      <th>Added</th>
                      <th class="text-end">Action</th>
                    </tr>
                  </thead>
                  <tbody>
<?php foreach ($locations as $loc):
    $formId = 'rename' . (int) $loc['location_id'];
?>
                    <tr>
                      <td style="min-width: 12rem">
                        <form method="post" id="<?= $formId ?>" class="d-flex gap-2">
                          <?= csrf_field() ?>
                          <input type="hidden" name="action" value="rename">
                          <input type="hidden" name="location_id" value="<?= (int) $loc['location_id'] ?>">
                          <input type="text" name="name" class="form-control form-control-sm" maxlength="100" value="<?= e($loc['name']) ?>" required aria-label="Name of <?= e($loc['name']) ?>">
                        </form>
<?php if ($loc['name'] === DEFAULT_LOCATION): ?>
                        <span class="badge bg-secondary mt-1">Default</span>
<?php endif; ?>
                      </td>
                      <td class="text-end"><?= (int) $loc['user_count'] ?></td>
                      <td class="small text-muted"><?= e(date('M j, Y', strtotime($loc['created_at']))) ?></td>
                      <td class="text-end">
                        <button type="submit" form="<?= $formId ?>" class="btn btn-sm btn-outline-primary" title="Save new name"><i class="bi bi-check-lg"></i> Rename</button>
                        <form method="post" class="d-inline" onsubmit="return confirm('Delete location <?= e(addslashes($loc['name'])) ?>?')">
                          <?= csrf_field() ?>
                          <input type="hidden" name="action" value="delete">
                          <input type="hidden" name="location_id" value="<?= (int) $loc['location_id'] ?>">
                          <button type="submit" class="btn btn-sm btn-outline-danger" <?= $loc['user_count'] > 0 ? 'disabled title="Users are assigned to this location"' : 'title="Delete"' ?>>
                            <i class="bi bi-trash"></i>
                          </button>
                        </form>
                      </td>
                    </tr>
<?php endforeach; ?>
<?php if (!$locations): ?>
                    <tr><td colspan="4" class="text-muted text-center py-4">No locations yet. Add one on the left.</td></tr>
<?php endif; ?>
                  </tbody>
                </table>
              </div>
              <p class="small text-muted mb-0">Renaming a location also updates every user assigned to it. A location with users can't be deleted.</p>
            </div>
          </div>
        </div>
      </div>
    </section>

  </main><!-- End #main -->

<?php
require __DIR__ . '/includes/footer.php';
require __DIR__ . '/includes/scripts.php';
