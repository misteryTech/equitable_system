<?php
/**
 * Admin: terminal labels (the Label dropdown on Terminals) — add, rename,
 * activate / deactivate, reorder and delete. A label that terminals still use
 * can't be deleted; deactivate it instead (those terminals keep it, but it
 * can't be picked for new ones).
 */
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/terminals.php';
$user = require_role('admin');

$pageTitle  = 'Terminal Labels';
$activePage = 'terminal-labels';
$errors     = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $id     = (int) ($_POST['id'] ?? 0);
    $labels = terminal_labels();
    $label  = $labels[$id] ?? null;

    if (!csrf_verify()) {
        $errors[] = 'Your session expired. Please try again.';
    } elseif ($action === 'add') {
        [$newId, $error] = terminal_label_add((string) ($_POST['name'] ?? ''));
        if ($error) {
            $errors[] = $error;
        } else {
            $_SESSION['flash'] = ['success', 'Label "' . terminal_label_clean((string) $_POST['name']) . '" added. It now appears in the Label dropdown.'];
            redirect('terminal_labels.php');
        }
    } elseif (!$label) {
        $errors[] = 'Label not found. Please reload the page.';
    } elseif ($action === 'rename') {
        $name = terminal_label_clean((string) ($_POST['name'] ?? ''));
        $stmt = db()->prepare('SELECT 1 FROM terminal_labels WHERE name = ? AND id <> ?');
        $stmt->execute([$name, $id]);
        if ($error = terminal_label_name_error($name)) {
            $errors[] = $error;
        } elseif ($stmt->fetchColumn()) {
            $errors[] = "The label \"$name\" already exists.";
        } else {
            db()->prepare('UPDATE terminal_labels SET name = ? WHERE id = ?')->execute([$name, $id]);
            $_SESSION['flash'] = ['success', $name === $label['name'] ? 'No change.' : "Renamed \"{$label['name']}\" to \"$name\"" . ($label['terminals'] ? " on {$label['terminals']} terminal" . ($label['terminals'] === 1 ? '' : 's') : '') . '.'];
            redirect('terminal_labels.php');
        }
    } elseif ($action === 'toggle') {
        $active = $label['is_active'] ? 0 : 1;
        db()->prepare('UPDATE terminal_labels SET is_active = ? WHERE id = ?')->execute([$active, $id]);
        $_SESSION['flash'] = ['success', "\"{$label['name']}\" " . ($active ? 'is active again and can be picked for terminals.' : 'deactivated. Terminals that use it keep it, but it can no longer be picked.')];
        redirect('terminal_labels.php');
    } elseif ($action === 'move') {
        // Renumber 10, 20, 30… then swap with the neighbour.
        $ids = array_keys($labels);
        $pos = array_search($id, $ids, true);
        $to  = ($_POST['dir'] ?? '') === 'up' ? $pos - 1 : $pos + 1;
        if ($to >= 0 && $to < count($ids)) {
            [$ids[$pos], $ids[$to]] = [$ids[$to], $ids[$pos]];
        }
        $pdo = db();
        $pdo->beginTransaction();
        $update = $pdo->prepare('UPDATE terminal_labels SET sort_order = ? WHERE id = ?');
        foreach ($ids as $i => $labelId) {
            $update->execute([($i + 1) * 10, $labelId]);
        }
        $pdo->commit();
        redirect('terminal_labels.php');
    } elseif ($action === 'delete') {
        if ($label['terminals'] > 0 || $label['serials'] > 0) {
            $errors[] = "\"{$label['name']}\" can't be deleted: {$label['terminals']} terminal(s) and {$label['serials']} serial(s) use it. Deactivate it instead, or move them to another label first.";
        } else {
            try {
                db()->prepare('DELETE FROM terminal_labels WHERE id = ?')->execute([$id]);
                $_SESSION['flash'] = ['success', "Label \"{$label['name']}\" deleted."];
                redirect('terminal_labels.php');
            } catch (PDOException $ex) {
                // A terminal picked it in the meantime (foreign key).
                if ($ex->getCode() !== '23000') {
                    throw $ex;
                }
                $errors[] = "\"{$label['name']}\" can't be deleted: terminals or serials use it. Deactivate it instead.";
            }
        }
    } else {
        $errors[] = 'Unknown action.';
    }
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$labels = terminal_labels();

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
          <li class="breadcrumb-item"><a href="terminals.php">Terminals</a></li>
          <li class="breadcrumb-item active">Labels</li>
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
              <h5 class="card-title">Add Label</h5>
              <form method="post" class="row g-2">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="add">
                <div class="col-12">
                  <label for="labelName" class="form-label">Label name</label>
                  <input type="text" name="name" id="labelName" class="form-control" maxlength="<?= TERMINAL_LABEL_MAX ?>"
                         placeholder="e.g. CBG-MP" value="<?= ($_POST['action'] ?? '') === 'add' ? old('name') : '' ?>" required>
                </div>
                <div class="col-12">
                  <button type="submit" class="btn btn-primary w-100"><i class="bi bi-plus-lg"></i> Add Label</button>
                </div>
              </form>
              <p class="small text-muted mt-3 mb-0">New labels appear at the end of the Label dropdown on Terminals. Admins can also add one from that dropdown.</p>
            </div>
          </div>
        </div>

        <div class="col-lg-8">
          <div class="card">
            <div class="card-body">
              <h5 class="card-title">Labels <span>| <?= count($labels) ?></span></h5>

              <div class="table-responsive">
                <table class="table align-middle">
                  <thead>
                    <tr>
                      <th style="width: 4.5rem">Order</th>
                      <th>Name</th>
                      <th class="text-end">Used by</th>
                      <th>Status</th>
                      <th class="text-end">Action</th>
                    </tr>
                  </thead>
                  <tbody>
<?php foreach (array_values($labels) as $i => $l):
    $formId = 'rename' . $l['id'];
?>
                    <tr class="<?= $l['is_active'] ? '' : 'text-muted' ?>">
                      <td class="text-nowrap">
<?php foreach (['up' => 'arrow-up', 'down' => 'arrow-down'] as $dir => $icon): ?>
                        <form method="post" class="d-inline">
                          <?= csrf_field() ?>
                          <input type="hidden" name="action" value="move">
                          <input type="hidden" name="id" value="<?= (int) $l['id'] ?>">
                          <input type="hidden" name="dir" value="<?= $dir ?>">
                          <button type="submit" class="btn btn-sm btn-link p-0" title="Move <?= $dir ?>" aria-label="Move <?= e($l['name']) ?> <?= $dir ?>"
                                  <?= ($dir === 'up' && $i === 0) || ($dir === 'down' && $i === count($labels) - 1) ? 'disabled' : '' ?>><i class="bi bi-<?= $icon ?>"></i></button>
                        </form>
<?php endforeach; ?>
                      </td>
                      <td style="min-width: 10rem">
                        <form method="post" id="<?= e($formId) ?>" class="d-flex gap-2 align-items-center">
                          <?= csrf_field() ?>
                          <input type="hidden" name="action" value="rename">
                          <input type="hidden" name="id" value="<?= (int) $l['id'] ?>">
                          <span class="badge <?= e(terminal_label_badge_class($l['id'])) ?>">&nbsp;</span>
                          <input type="text" name="name" class="form-control form-control-sm" maxlength="<?= TERMINAL_LABEL_MAX ?>" value="<?= e($l['name']) ?>" required aria-label="Name of <?= e($l['name']) ?>">
                        </form>
                      </td>
                      <td class="text-end small text-nowrap">
                        <a href="terminals.php?label=<?= (int) $l['id'] ?>"><?= (int) $l['terminals'] ?> terminal<?= $l['terminals'] === 1 ? '' : 's' ?></a><br>
                        <a href="serial_records.php?label=<?= (int) $l['id'] ?>"><?= (int) $l['serials'] ?> serial<?= $l['serials'] === 1 ? '' : 's' ?></a>
                      </td>
                      <td><span class="badge <?= $l['is_active'] ? 'bg-success' : 'bg-secondary' ?>"><?= $l['is_active'] ? 'Active' : 'Inactive' ?></span></td>
                      <td class="text-end text-nowrap">
                        <button type="submit" form="<?= e($formId) ?>" class="btn btn-sm btn-outline-primary" title="Save name"><i class="bi bi-check-lg"></i><span class="d-none d-sm-inline"> Save</span></button>
                        <form method="post" class="d-inline">
                          <?= csrf_field() ?>
                          <input type="hidden" name="action" value="toggle">
                          <input type="hidden" name="id" value="<?= (int) $l['id'] ?>">
                          <button type="submit" class="btn btn-sm btn-outline-secondary"><?= $l['is_active'] ? 'Deactivate' : 'Activate' ?></button>
                        </form>
                        <form method="post" class="d-inline" onsubmit="return confirm('Delete label <?= e(addslashes($l['name'])) ?>? This cannot be undone.')">
                          <?= csrf_field() ?>
                          <input type="hidden" name="action" value="delete">
                          <input type="hidden" name="id" value="<?= (int) $l['id'] ?>">
                          <button type="submit" class="btn btn-sm btn-outline-danger" <?= $l['terminals'] > 0 || $l['serials'] > 0 ? 'disabled title="In use — deactivate it instead"' : 'title="Delete"' ?> aria-label="Delete <?= e($l['name']) ?>">
                            <i class="bi bi-trash"></i>
                          </button>
                        </form>
                      </td>
                    </tr>
<?php endforeach; ?>
<?php if (!$labels): ?>
                    <tr><td colspan="5" class="text-muted text-center py-4">No labels yet. Add one on the left.</td></tr>
<?php endif; ?>
                  </tbody>
                </table>
              </div>
              <p class="small text-muted mb-0">Renaming a label changes it on every terminal that uses it. A label in use can't be deleted: deactivate it to stop it being picked for new terminals.</p>
            </div>
          </div>
        </div>
      </div>
    </section>

  </main><!-- End #main -->

<?php
require __DIR__ . '/includes/footer.php';
require __DIR__ . '/includes/scripts.php';
