<?php
/**
 * Admin: one-time import of plain-text transpo notes.
 * Step 1: paste the notes and pick whose they are.  Step 2: PREVIEW (tickets, legs,
 * totals, possible duplicate places with a choice for each, fare conflicts,
 * unparsed lines).  Step 3: Import — everything is saved in one transaction.
 */
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/transpo.php';
require_once __DIR__ . '/includes/transpo_import.php';
$admin = require_role('admin');

$pageTitle  = 'Transpo Import';
$activePage = 'transpo-import';

$engineers = db()->query(
    "SELECT * FROM users WHERE status = 'approved' ORDER BY last_name, first_name"
)->fetchAll();

$text        = (string) ($_POST['notes'] ?? '');
$engineerId  = (int) ($_POST['engineer_id'] ?? 0);
$defaultDate = (string) ($_POST['default_date'] ?? date('Y-m-d'));
$action      = $_POST['action'] ?? '';
$errors      = [];
$preview     = null;

$key = static fn(string $norm): string => rtrim(strtr(base64_encode($norm), '+/', '-_'), '=');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $engineer = null;
    foreach ($engineers as $en) {
        if ((int) $en['user_id'] === $engineerId) {
            $engineer = $en;
        }
    }
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $defaultDate);
    if (!csrf_verify()) {
        $errors[] = 'Your session expired. Please try again.';
    } elseif (!$engineer) {
        $errors[] = 'Choose whose notes these are.';
    } elseif (trim($text) === '') {
        $errors[] = 'Paste the notes first.';
    } elseif (strlen($text) > 500000) {
        $errors[] = 'The notes are too long (max 500 KB). Import them in parts.';
    } elseif (!$d || $d->format('Y-m-d') !== $defaultDate) {
        $errors[] = 'The default date is not valid.';
    }

    if (!$errors) {
        $parsed = transpo_import_parse($text);
        // Choices from the preview form: base64 key -> 'new' | location id | 'same:<normalized>'.
        $choices = [];
        foreach ((array) ($_POST['choice'] ?? []) as $k => $choice) {
            $norm = base64_decode(strtr((string) $k, '-_', '+/'));
            $choices[$norm] = is_numeric($choice) ? (int) $choice : (string) $choice;
        }
        $plan = transpo_import_plan($parsed, $engineer, $choices);

        if (!$parsed['tickets']) {
            $errors[] = 'No tickets were found. Ticket lines must start with a number like 260911-BSERV-0523.';
        } elseif ($action === 'commit') {
            $s = transpo_import_commit($parsed, $plan, $engineer, $admin, $defaultDate);
            $_SESSION['flash'] = ['success', "Imported {$s['tickets']} ticket(s) with {$s['legs']} leg(s) for " . full_name($engineer)
                . ". Created {$s['locations']} place(s), {$s['aliases']} alias(es), {$s['rates']} approved route fare(s)."
                . ($s['skipped'] ? ' Skipped: ' . implode(', ', $s['skipped']) . '.' : '')
                . ($s['rates_kept'] ? ' Existing fares kept: ' . implode('; ', $s['rates_kept']) . '.' : '')];
            redirect('transpo_import.php');
        } else {
            $preview = ['parsed' => $parsed, 'plan' => $plan, 'engineer' => $engineer];
        }
    }
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

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

      <form method="post">
        <?= csrf_field() ?>
        <div class="card">
          <div class="card-body pt-3">
            <div class="row g-2">
              <div class="col-md-5">
                <label for="engineer_id" class="form-label">Whose notes are these?</label>
                <select name="engineer_id" id="engineer_id" class="form-select" required>
                  <option value="">Choose…</option>
<?php foreach ($engineers as $en): ?>
                  <option value="<?= (int) $en['user_id'] ?>" <?= $engineerId === (int) $en['user_id'] ? 'selected' : '' ?>><?= e($en['last_name'] . ', ' . $en['first_name']) ?><?= $en['field_engineer_id'] ? ' — ' . e($en['field_engineer_id']) : '' ?></option>
<?php endforeach; ?>
                </select>
              </div>
              <div class="col-md-3">
                <label for="default_date" class="form-label">Date for tickets without one</label>
                <input type="date" name="default_date" id="default_date" class="form-control" value="<?= e($defaultDate) ?>">
              </div>
              <div class="col-12">
                <label for="notes" class="form-label">Notes</label>
                <textarea name="notes" id="notes" class="form-control font-monospace" rows="<?= $preview ? 6 : 14 ?>" required
                          placeholder="260911-BSERV-0523 FARMACIA BEATRIZ 2026-10-03 09:12:30&#10;NEAREST HIGHWAY FROM BASE TO POLYTECHNIC - TRYKE = 20&#10;POLYTECHNIC TO MAKAR DROPOINT - TRYKE = 25&#10;MAKAR DROPOINT TO MERCHANT - PUJ = 20&#10;FROM MERCHANT TID: 260911-BSERV-0523 TO MERCHANT - HABAL = 50&#10;260910-BSERV-0011 WANG ZI HARDWARE-GENSAN&#10;====="><?= e($text) ?></textarea>
                <div class="form-text">Ticket lines (260911-BSERV-0523 MERCHANT date/time address), leg lines ("X TO Y - MODE = fare"), "FROM MERCHANT TID: xxx TO MERCHANT", remark lines ("LATE FILING - …", attach to the next ticket) and "=====" separators.</div>
              </div>
              <div class="col-12">
                <button type="submit" name="action" value="preview" class="btn btn-primary"><i class="bi bi-eye"></i> Preview</button>
              </div>
            </div>
          </div>
        </div>

<?php if ($preview):
    $parsed  = $preview['parsed'];
    $plan    = $preview['plan'];
    $base    = $plan['base'];
    $days    = [];
    $grand   = 0.0;
    $legCount = 0;
    foreach ($parsed['tickets'] as $t) {
        $sub = array_sum(array_column($t['legs'], 'fare'));
        $day = substr($t['visited_at'] ?? $defaultDate, 0, 10);
        $days[$day] = ($days[$day] ?? 0) + $sub;
        $grand += $sub;
        $legCount += count($t['legs']);
    }
    $endName = fn($end, $t) => transpo_import_end_name($end, $t, $base['name']);
?>
        <div class="card">
          <div class="card-body pt-3">
            <h5 class="card-title py-0">Preview for <?= e(full_name($preview['engineer'])) ?></h5>
            <p class="mb-2"><strong><?= count($parsed['tickets']) ?></strong> ticket(s), <strong><?= $legCount ?></strong> leg(s), total <strong><?= e(transpo_money($grand)) ?></strong>.
              Starting point for "NEAREST HIGHWAY FROM BASE": <strong><?= e($base['name']) ?></strong>.</p>
            <p class="small mb-3"><?php foreach ($days as $day => $sum): ?><span class="badge bg-light text-dark border me-1"><?= e(date('M j, Y', strtotime($day))) ?>: <?= e(transpo_money($sum)) ?></span><?php endforeach; ?></p>

<?php if ($parsed['warnings'] || $plan['already'] || $plan['conflicts']): ?>
            <div class="alert alert-warning small">
              <?php foreach ($parsed['warnings'] as $w): ?><div><?= e($w) ?></div><?php endforeach; ?>
              <?php if ($plan['already']): ?><div><strong>Already filed (will be skipped):</strong> <?= e(implode(', ', $plan['already'])) ?></div><?php endif; ?>
              <?php if ($plan['conflicts']): ?><div class="mt-1"><strong>Fare conflicts:</strong></div><?php foreach ($plan['conflicts'] as $c): ?><div>• <?= e($c) ?></div><?php endforeach; ?><?php endif; ?>
            </div>
<?php endif; ?>

            <h6>Tickets</h6>
            <div class="table-responsive">
              <table class="table table-sm table-bordered align-middle small">
                <thead class="table-light"><tr><th>Ticket</th><th>Date &amp; time</th><th>Merchant</th><th>Legs</th><th class="text-end">Subtotal</th></tr></thead>
                <tbody>
<?php foreach ($parsed['tickets'] as $t): ?>
                  <tr>
                    <td class="text-nowrap fw-semibold"><?= e($t['ticket_no']) ?><?php if ($t['remarks']): ?><div class="text-danger fw-normal"><?= e($t['remarks']) ?></div><?php endif; ?></td>
                    <td class="text-nowrap"><?= $t['visited_at'] ? e(date('m/d/Y g:i A', strtotime($t['visited_at']))) : '<span class="text-warning">' . e(date('m/d/Y', strtotime($defaultDate))) . ' 8:00 AM (default)</span>' ?></td>
                    <td><?= e($t['merchant'] ?: '—') ?><?php if ($t['address']): ?><div class="text-muted"><?= e($t['address']) ?></div><?php endif; ?></td>
                    <td>
<?php foreach ($t['legs'] as $leg): ?>
                      <div><?= e($endName($leg['from'], $t)) ?> → <?= e($endName($leg['to'], $t)) ?> <span class="text-muted">· <?= e($leg['mode']) ?> ·</span> <?= e(transpo_money($leg['fare'])) ?></div>
<?php endforeach; ?>
<?php if (!$t['legs']): ?><span class="text-danger">No legs — will be skipped</span><?php endif; ?>
                    </td>
                    <td class="text-end text-nowrap"><?= e(transpo_money(array_sum(array_column($t['legs'], 'fare')))) ?></td>
                  </tr>
<?php endforeach; ?>
                </tbody>
              </table>
            </div>

            <h6>Places <span class="small text-muted">(choose how each name is saved)</span></h6>
            <div class="table-responsive">
              <table class="table table-sm table-bordered align-middle small">
                <thead class="table-light"><tr><th>Name in notes</th><th>Type</th><th class="text-end">Uses</th><th>Save as</th></tr></thead>
                <tbody>
<?php foreach ($plan['names'] as $norm => $info): ?>
                  <tr class="<?= !$info['existing'] && ($info['candidates'] || $info['similar_in_import']) ? 'table-warning' : '' ?>">
                    <td class="fw-semibold"><?= e($info['name']) ?></td>
                    <td><?= transpo_type_badge($info['type']) ?></td>
                    <td class="text-end"><?= (int) $info['count'] ?></td>
                    <td>
<?php if ($info['existing']): ?>
                      <span class="text-success"><i class="bi bi-check-circle"></i> Existing: <?= e($info['existing']['name']) ?><?= $info['existing']['matched_via'] === 'alias' ? ' (via alias)' : '' ?></span>
<?php else: ?>
                      <select name="choice[<?= e($key($norm)) ?>]" class="form-select form-select-sm">
                        <option value="new" <?= $info['choice'] === 'new' ? 'selected' : '' ?>>Create new place</option>
<?php foreach ($info['candidates'] as $c): ?>
                        <option value="<?= (int) $c['id'] ?>" <?= $info['choice'] === (int) $c['id'] ? 'selected' : '' ?>>Use existing: <?= e($c['name']) ?> (used <?= (int) $c['times_used'] ?>×, <?= e($c['score']) ?>% similar)</option>
<?php endforeach; ?>
<?php foreach ($info['similar_in_import'] as $other): ?>
                        <option value="same:<?= e($other) ?>" <?= $info['choice'] === 'same:' . $other ? 'selected' : '' ?>>Same as "<?= e($plan['names'][$other]['name']) ?>" (in these notes)</option>
<?php endforeach; ?>
                      </select>
<?php endif; ?>
                    </td>
                  </tr>
<?php endforeach; ?>
                </tbody>
              </table>
            </div>

            <p class="small"><strong><?= count($plan['routes']) ?></strong> route fare(s) will be added to the fare list as <strong>approved</strong> (two-way, most recent fare when the notes disagree). Routes that already have a fare keep it.</p>

<?php if ($parsed['unparsed']): ?>
            <details class="mb-3"><summary class="small"><?= count($parsed['unparsed']) ?> line(s) not understood (ignored)</summary>
              <pre class="small bg-light p-2 mt-2 mb-0"><?php foreach ($parsed['unparsed'] as [$no, $line]): ?><?= e("Line $no: $line") . "\n" ?><?php endforeach; ?></pre>
            </details>
<?php endif; ?>

            <button type="submit" name="action" value="preview" class="btn btn-outline-primary"><i class="bi bi-arrow-repeat"></i> Update preview</button>
            <button type="submit" name="action" value="commit" class="btn btn-success" onclick="return confirm('Import these tickets and route fares?')"><i class="bi bi-check2-circle"></i> Import</button>
          </div>
        </div>
<?php endif; ?>
      </form>
    </section>

  </main><!-- End #main -->

<?php
require __DIR__ . '/includes/footer.php';
require __DIR__ . '/includes/scripts.php';
