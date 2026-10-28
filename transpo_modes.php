<?php
/**
 * Admin: transport modes (the Mode dropdown on legs and route fares) — add, rename,
 * turn on/off, reorder and delete. Engineers can also register a missing mode from the
 * leg form; admins are notified. A mode used by legs or fares can't be deleted
 * (turn it off instead); the built-in modes used by Transpo Import can't be deleted.
 */
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/transpo.php';
$user = require_role('admin');

$pageTitle  = 'Transport Modes';
$activePage = 'transpo-modes';
$errors     = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $code   = (string) ($_POST['code'] ?? '');
    $rows   = array_column(transpo_mode_rows(), null, 'code');
    $mode   = $rows[$code] ?? null;

    if (!csrf_verify()) {
        $errors[] = 'Your session expired. Please try again.';
    } elseif ($action === 'add') {
        $result = transpo_add_mode((string) ($_POST['label'] ?? ''), $user);
        if (!$result['ok']) {
            $errors[] = $result['error'];
        } else {
            $_SESSION['flash'] = ['success', $result['created']
                ? "Mode \"{$result['mode']['label']}\" added. It now appears in the Mode dropdowns."
                : "\"{$result['mode']['label']}\" is already in the list" . (isset($rows[$result['mode']['code']]) && !$rows[$result['mode']['code']]['is_active'] ? ' and is turned on again.' : '.')];
            redirect('transpo_modes.php');
        }
    } elseif (!$mode) {
        $errors[] = 'Mode not found. Please reload the page.';
    } elseif ($action === 'rename') {
        $label = transpo_mode_clean_label((string) ($_POST['label'] ?? ''));
        $stmt  = db()->prepare('SELECT 1 FROM transpo_modes WHERE label = ? AND code <> ?');
        $stmt->execute([$label, $code]);
        if ($error = transpo_mode_label_error($label)) {
            $errors[] = $error;
        } elseif ($stmt->fetchColumn()) {
            $errors[] = "Another mode is already called \"$label\".";
        } else {
            db()->prepare('UPDATE transpo_modes SET label = ? WHERE code = ?')->execute([$label, $code]);
            $_SESSION['flash'] = ['success', $label === $mode['label'] ? 'No change.' : "Renamed \"{$mode['label']}\" to \"$label\". Saved legs and fares show the new name."];
            redirect('transpo_modes.php');
        }
    } elseif ($action === 'toggle') {
        $active = $mode['is_active'] ? 0 : 1;
        if (!$active && count(array_filter($rows, fn($r) => $r['is_active'])) <= 1) {
            $errors[] = 'At least one mode must stay on.';
        } else {
            db()->prepare('UPDATE transpo_modes SET is_active = ? WHERE code = ?')->execute([$active, $code]);
            $_SESSION['flash'] = ['success', "\"{$mode['label']}\" " . ($active ? 'is on again.' : 'turned off. Saved legs and fares keep it, but it is no longer offered.')];
            redirect('transpo_modes.php');
        }
    } elseif ($action === 'move') {
        $codes = array_keys($rows);
        $pos   = array_search($code, $codes, true);
        $to    = ($_POST['dir'] ?? '') === 'up' ? $pos - 1 : $pos + 1;
        if ($to >= 0 && $to < count($codes)) {
            [$codes[$pos], $codes[$to]] = [$codes[$to], $codes[$pos]];
        }
        $pdo = db();
        $pdo->beginTransaction();
        $update = $pdo->prepare('UPDATE transpo_modes SET sort_order = ? WHERE code = ?');
        foreach ($codes as $i => $c) {
            $update->execute([($i + 1) * 10, $c]);
        }
        $pdo->commit();
        redirect('transpo_modes.php');
    } elseif ($action === 'delete') {
        if (in_array($code, TRANSPO_MODE_BUILT_IN, true)) {
            $errors[] = "\"{$mode['label']}\" is used by Transpo Import, so it can't be deleted. Turn it off instead.";
        } elseif ($mode['legs'] > 0 || $mode['rates'] > 0) {
            $errors[] = "\"{$mode['label']}\" can't be deleted: {$mode['legs']} leg(s) and {$mode['rates']} route fare(s) use it. Turn it off instead.";
        } else {
            try {
                db()->prepare('DELETE FROM transpo_modes WHERE code = ?')->execute([$code]);
                $_SESSION['flash'] = ['success', "Mode \"{$mode['label']}\" deleted."];
                redirect('transpo_modes.php');
            } catch (PDOException $ex) {
                if ($ex->getCode() !== '23000') {
                    throw $ex;
                }
                $errors[] = "\"{$mode['label']}\" is now used by a leg or fare. Turn it off instead.";
            }
        }
    } else {
        $errors[] = 'Unknown action.';
    }
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$modes = transpo_mode_rows();

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
          <li class="breadcrumb-item">Transportation</li>
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
              <h5 class="card-title">Add Mode</h5>
              <form method="post" class="row g-2">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="add">
                <div class="col-12">
                  <label for="modeLabel" class="form-label">Mode name</label>
                  <input type="text" name="label" id="modeLabel" class="form-control" maxlength="<?= TRANSPO_MODE_LABEL_MAX ?>"
                         placeholder="e.g. Motorela" value="<?= ($_POST['action'] ?? '') === 'add' ? old('label') : '' ?>" required>
                </div>
                <div class="col-12">
                  <button type="submit" class="btn btn-primary w-100"><i class="bi bi-plus-lg"></i> Add Mode</button>
                </div>
              </form>
              <p class="small text-muted mt-3 mb-0">Engineers can also add a missing mode from a leg's Mode list ("+ Add new mode…"); you get a notification when they do.</p>
            </div>
          </div>
        </div>

        <div class="col-lg-8">
          <div class="card">
            <div class="card-body">
              <h5 class="card-title">Modes <span>| <?= count($modes) ?></span></h5>

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
<?php foreach ($modes as $i => $m):
    $formId  = 'rename' . $m['code'];
    $builtIn = in_array($m['code'], TRANSPO_MODE_BUILT_IN, true);
    $inUse   = $m['legs'] > 0 || $m['rates'] > 0;
?>
                    <tr class="<?= $m['is_active'] ? '' : 'text-muted' ?>">
                      <td class="text-nowrap">
<?php foreach (['up' => 'arrow-up', 'down' => 'arrow-down'] as $dir => $icon): ?>
                        <form method="post" class="d-inline">
                          <?= csrf_field() ?>
                          <input type="hidden" name="action" value="move">
                          <input type="hidden" name="code" value="<?= e($m['code']) ?>">
                          <input type="hidden" name="dir" value="<?= $dir ?>">
                          <button type="submit" class="btn btn-sm btn-link p-0" title="Move <?= $dir ?>" aria-label="Move <?= e($m['label']) ?> <?= $dir ?>"
                                  <?= ($dir === 'up' && $i === 0) || ($dir === 'down' && $i === count($modes) - 1) ? 'disabled' : '' ?>><i class="bi bi-<?= $icon ?>"></i></button>
                        </form>
<?php endforeach; ?>
                      </td>
                      <td style="min-width: 11rem">
                        <form method="post" id="<?= e($formId) ?>">
                          <?= csrf_field() ?>
                          <input type="hidden" name="action" value="rename">
                          <input type="hidden" name="code" value="<?= e($m['code']) ?>">
                          <input type="text" name="label" class="form-control form-control-sm" maxlength="<?= TRANSPO_MODE_LABEL_MAX ?>" value="<?= e($m['label']) ?>" required aria-label="Name of <?= e($m['label']) ?>">
                        </form>
                        <div class="small text-muted mt-1">
                          Code <span class="font-monospace"><?= e($m['code']) ?></span><?= $m['created_by_name'] ? ' · added by ' . e($m['created_by_name']) : '' ?>
                        </div>
                      </td>
                      <td class="text-end small text-nowrap"><?= (int) $m['legs'] ?> leg<?= (int) $m['legs'] === 1 ? '' : 's' ?><br><?= (int) $m['rates'] ?> fare<?= (int) $m['rates'] === 1 ? '' : 's' ?></td>
                      <td><span class="badge <?= $m['is_active'] ? 'bg-success' : 'bg-secondary' ?>"><?= $m['is_active'] ? 'On' : 'Off' ?></span></td>
                      <td class="text-end text-nowrap">
                        <button type="submit" form="<?= e($formId) ?>" class="btn btn-sm btn-outline-primary" title="Save name"><i class="bi bi-check-lg"></i><span class="d-none d-sm-inline"> Save</span></button>
                        <form method="post" class="d-inline">
                          <?= csrf_field() ?>
                          <input type="hidden" name="action" value="toggle">
                          <input type="hidden" name="code" value="<?= e($m['code']) ?>">
                          <button type="submit" class="btn btn-sm btn-outline-secondary"><?= $m['is_active'] ? 'Turn off' : 'Turn on' ?></button>
                        </form>
                        <form method="post" class="d-inline" onsubmit="return confirm('Delete mode <?= e(addslashes($m['label'])) ?>? This cannot be undone.')">
                          <?= csrf_field() ?>
                          <input type="hidden" name="action" value="delete">
                          <input type="hidden" name="code" value="<?= e($m['code']) ?>">
                          <button type="submit" class="btn btn-sm btn-outline-danger" aria-label="Delete <?= e($m['label']) ?>"
                                  <?= $builtIn ? 'disabled title="Used by Transpo Import — turn it off instead"' : ($inUse ? 'disabled title="In use — turn it off instead"' : 'title="Delete"') ?>>
                            <i class="bi bi-trash"></i>
                          </button>
                        </form>
                      </td>
                    </tr>
<?php endforeach; ?>
                  </tbody>
                </table>
              </div>
              <p class="small text-muted mb-0">Renaming changes the name everywhere, including saved legs and fares (the code stays the same). A mode in use can't be deleted: turn it off to stop offering it.</p>
            </div>
          </div>
        </div>
      </div>
    </section>

  </main><!-- End #main -->

<?php
require __DIR__ . '/includes/footer.php';
require __DIR__ . '/includes/scripts.php';
