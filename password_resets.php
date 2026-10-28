<?php
/**
 * Admin: password reset requests from the login page ("Forgot password?").
 * Approve = set a new password for that user (typed or generated) and give it to them;
 * by default they must choose their own at the next login. Reject = close the request.
 * Passwords are only changed through a request; nothing is emailed.
 */
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/password_reset.php';
$admin = require_role('admin');

$pageTitle  = 'Password Resets';
$activePage = 'password-resets';
$errors     = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $stmt   = db()->prepare(
        "SELECT r.*, u.first_name, u.last_name, u.username, u.status AS user_status
         FROM password_reset_requests r JOIN users u ON u.user_id = r.user_id WHERE r.id = ?"
    );
    $stmt->execute([(int) ($_POST['id'] ?? 0)]);
    $request = $stmt->fetch();

    if (!csrf_verify()) {
        $errors[] = 'Your session expired. Please try again.';
    } elseif (!$request || $request['status'] !== 'pending') {
        $errors[] = 'That request was already handled or no longer exists.';
    } elseif ($action === 'approve') {
        $password   = (string) ($_POST['new_password'] ?? '');
        $mustChange = !empty($_POST['must_change']) ? 1 : 0;
        if ($error = password_policy_error($password)) {
            $errors[] = $error;
        } elseif ($request['user_status'] !== 'approved') {
            $errors[] = 'This account is not active, so its password was not changed.';
        } else {
            $pdo = db();
            $pdo->beginTransaction();
            $pdo->prepare('UPDATE users SET password_hash = ?, must_change_password = ? WHERE user_id = ?')
                ->execute([password_hash($password, PASSWORD_DEFAULT), $mustChange, $request['user_id']]);
            // This request and any other open one from the same user are done.
            $pdo->prepare("UPDATE password_reset_requests SET status = 'approved', handled_by = ?, handled_at = NOW() WHERE user_id = ? AND status = 'pending'")
                ->execute([$admin['user_id'], $request['user_id']]);
            $pdo->commit();
            // Shown once on the next page so the admin can give it to the user.
            $_SESSION['reset_result'] = [
                'name'        => trim($request['first_name'] . ' ' . $request['last_name']),
                'username'    => $request['username'],
                'password'    => $password,
                'must_change' => $mustChange,
            ];
            redirect('password_resets.php');
        }
    } elseif ($action === 'reject') {
        $note = trim(preg_replace('/\s+/', ' ', (string) ($_POST['admin_note'] ?? '')));
        if (mb_strlen($note) > 255) {
            $errors[] = 'The reason can be at most 255 characters.';
        } else {
            db()->prepare("UPDATE password_reset_requests SET status = 'rejected', handled_by = ?, handled_at = NOW(), admin_note = ? WHERE id = ?")
                ->execute([$admin['user_id'], $note === '' ? null : $note, $request['id']]);
            $_SESSION['flash'] = ['success', 'Request from ' . trim($request['first_name'] . ' ' . $request['last_name']) . ' rejected. Their password was not changed.'];
            redirect('password_resets.php');
        }
    } else {
        $errors[] = 'Unknown action.';
    }
}

$flash  = $_SESSION['flash'] ?? null;
$result = $_SESSION['reset_result'] ?? null;
unset($_SESSION['flash'], $_SESSION['reset_result']);

$pending = db()->query(
    "SELECT r.*, u.first_name, u.last_name, u.username, u.email, u.mobile_number, u.field_engineer_id, u.role, u.status AS user_status
     FROM password_reset_requests r JOIN users u ON u.user_id = r.user_id
     WHERE r.status = 'pending' ORDER BY r.requested_at"
)->fetchAll();
$history = db()->query(
    "SELECT r.*, u.first_name, u.last_name, u.username, CONCAT(a.first_name, ' ', a.last_name) AS admin_name
     FROM password_reset_requests r JOIN users u ON u.user_id = r.user_id LEFT JOIN users a ON a.user_id = r.handled_by
     WHERE r.status <> 'pending' ORDER BY r.handled_at DESC, r.id DESC LIMIT 50"
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
<?php if ($result): ?>
      <div class="alert alert-success" role="status">
        <div class="fw-semibold mb-1">Password reset for <?= e($result['name']) ?> (@<?= e($result['username']) ?>).</div>
        <div class="d-flex flex-wrap align-items-center gap-2 my-2">
          <span>New password:</span>
          <code class="fs-5 px-2 py-1 bg-white border rounded user-select-all" id="newPasswordShown"><?= e($result['password']) ?></code>
          <button type="button" class="btn btn-sm btn-outline-success" id="copyNewPassword"><i class="bi bi-clipboard"></i> Copy</button>
        </div>
        <div class="small">
          Give it to them in person, by phone or chat. It is shown only this once.
          <?= $result['must_change'] ? 'They will be asked to choose their own password when they log in.' : 'They can keep using it.' ?>
        </div>
      </div>
<?php endif; ?>
<?php if ($flash): ?>
      <div class="alert alert-<?= e($flash[0]) ?>"><?= e($flash[1]) ?></div>
<?php endif; ?>
<?php if ($errors): ?>
      <div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

      <div class="card">
        <div class="card-body">
          <h5 class="card-title">Waiting for approval <span>| <?= count($pending) ?></span></h5>
<?php if (!$pending): ?>
          <p class="text-muted mb-0">No password reset requests right now. Users send one from the login page with "Forgot password?".</p>
<?php endif; ?>
<?php foreach ($pending as $r):
    $name = trim($r['first_name'] . ' ' . $r['last_name']);
?>
          <div class="border rounded p-3 mb-3">
            <div class="d-flex flex-wrap justify-content-between gap-2">
              <div>
                <div class="fw-semibold"><?= e($name) ?> <span class="text-muted fw-normal">@<?= e($r['username']) ?></span></div>
                <div class="small text-muted">
                  <?= e(role_label($r['role'])) ?><?= $r['field_engineer_id'] ? ' · ' . e($r['field_engineer_id']) : '' ?>
                  · <?= e($r['email']) ?><?= $r['mobile_number'] ? ' · ' . e($r['mobile_number']) : '' ?>
                </div>
                <div class="small text-muted">Requested <?= e(date('M j, Y g:i A', strtotime($r['requested_at']))) ?></div>
<?php if ($r['note']): ?>
                <div class="small mt-1"><i class="bi bi-chat-left-text"></i> "<?= e($r['note']) ?>"</div>
<?php endif; ?>
<?php if ($r['user_status'] !== 'approved'): ?>
                <div class="small text-danger mt-1">This account is not active (<?= e($r['user_status']) ?>).</div>
<?php endif; ?>
              </div>
            </div>

            <div class="row g-2 mt-2">
              <div class="col-lg-8">
                <form method="post" class="border rounded p-2 bg-light h-100">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="approve">
                  <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                  <label for="pw<?= (int) $r['id'] ?>" class="form-label small fw-semibold mb-1">New password to give <?= e($r['first_name']) ?></label>
                  <div class="input-group input-group-sm">
                    <input type="text" name="new_password" id="pw<?= (int) $r['id'] ?>" class="form-control font-monospace pw-input" minlength="<?= PASSWORD_MIN_LENGTH ?>" maxlength="<?= PASSWORD_MAX_LENGTH ?>" autocomplete="off" required>
                    <button type="button" class="btn btn-outline-secondary pw-generate" title="Make a random password"><i class="bi bi-shuffle"></i> Generate</button>
                  </div>
                  <div class="form-text">At least <?= PASSWORD_MIN_LENGTH ?> characters, with a letter and a number.</div>
                  <div class="form-check mt-1">
                    <input class="form-check-input" type="checkbox" name="must_change" value="1" id="mc<?= (int) $r['id'] ?>" checked>
                    <label class="form-check-label small" for="mc<?= (int) $r['id'] ?>">Ask them to choose their own password at the next login</label>
                  </div>
                  <button type="submit" class="btn btn-sm btn-success mt-2" onclick="return confirm('Set this new password for <?= e(addslashes($name)) ?>?')"><i class="bi bi-check-lg"></i> Approve &amp; set password</button>
                </form>
              </div>
              <div class="col-lg-4">
                <form method="post" class="border rounded p-2 h-100">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="reject">
                  <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                  <label for="rn<?= (int) $r['id'] ?>" class="form-label small fw-semibold mb-1">Reject</label>
                  <input type="text" name="admin_note" id="rn<?= (int) $r['id'] ?>" class="form-control form-control-sm" maxlength="255" placeholder="Reason (optional)">
                  <button type="submit" class="btn btn-sm btn-outline-danger mt-2" onclick="return confirm('Reject this request? The password stays as it is.')"><i class="bi bi-x-lg"></i> Reject</button>
                </form>
              </div>
            </div>
          </div>
<?php endforeach; ?>
        </div>
      </div>

      <div class="card">
        <div class="card-body">
          <h5 class="card-title">Recently handled</h5>
          <div class="table-responsive">
            <table class="table table-sm align-middle">
              <thead><tr><th>User</th><th>Requested</th><th>Result</th><th>By</th><th class="d-none d-md-table-cell">Note</th></tr></thead>
              <tbody>
<?php foreach ($history as $h): ?>
                <tr>
                  <td><?= e(trim($h['first_name'] . ' ' . $h['last_name'])) ?> <span class="small text-muted">@<?= e($h['username']) ?></span></td>
                  <td class="small text-nowrap"><?= e(date('M j, Y g:i A', strtotime($h['requested_at']))) ?></td>
                  <td><span class="badge <?= $h['status'] === 'approved' ? 'bg-success' : 'bg-secondary' ?>"><?= $h['status'] === 'approved' ? 'Password set' : 'Rejected' ?></span>
                    <div class="small text-muted"><?= $h['handled_at'] ? e(date('M j, g:i A', strtotime($h['handled_at']))) : '' ?></div></td>
                  <td class="small"><?= e($h['admin_name'] ?? '') ?></td>
                  <td class="small d-none d-md-table-cell"><?= e($h['admin_note'] ?: ($h['note'] ? '"' . $h['note'] . '"' : '')) ?></td>
                </tr>
<?php endforeach; ?>
<?php if (!$history): ?>
                <tr><td colspan="5" class="text-muted text-center py-3">Nothing yet.</td></tr>
<?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </section>

  </main><!-- End #main -->

  <script src="<?= asset('assets/js/email-generator.js') ?>"></script>
  <script>
    (function () {
      // Readable random password (no 0/O, 1/l/I), with a lower-case letter, a capital and a digit.
      var CHARS = <?= json_encode(PASSWORD_GENERATE_CHARS) ?>;
      function generate(length) {
        var out, values = new Uint32Array(length);
        do {
          window.crypto.getRandomValues(values);
          out = Array.prototype.map.call(values, function (v) { return CHARS[v % CHARS.length]; }).join("");
        } while (!/[a-z]/.test(out) || !/[A-Z]/.test(out) || !/\d/.test(out));
        return out;
      }
      document.querySelectorAll(".pw-generate").forEach(function (btn) {
        btn.addEventListener("click", function () {
          var input = btn.parentNode.querySelector(".pw-input");
          input.value = generate(10);
          input.select();
        });
      });
      var copy = document.getElementById("copyNewPassword");
      if (copy) {
        copy.addEventListener("click", function () {
          EmailGen.copyWithToast(document.getElementById("newPasswordShown").textContent, "password");
        });
      }
    })();
  </script>

<?php
require __DIR__ . '/includes/footer.php';
require __DIR__ . '/includes/scripts.php';
