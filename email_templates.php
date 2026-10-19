<?php
/**
 * Admin: Service Request email settings — greeting / closing texts of the two templates
 * and the list of request types (checkboxes on the Service Request generator).
 */
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/email_generator.php';
$user = require_role('admin');

$pageTitle  = 'Email Templates';
$activePage = 'email-templates';
$errors     = [];

$textFields = [
    'short_greeting'  => ['Short: first line', 'Hi system, for assistance'],
    'short_closing'   => ['Short: closing', 'Thanks and regards'],
    'formal_greeting' => ['Formal: first line', 'Hi Maam/Sir'],
    'formal_intro'    => ['Formal: line after the greeting', 'For assistance'],
    'formal_closing'  => ['Formal: closing', 'Thank you'],
];

$settings = service_settings();
$types    = service_request_types(false);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if (!csrf_verify()) {
        $errors[] = 'Your session expired. Please try again.';
    } elseif ($action === 'save_texts') {
        $input = [];
        foreach ($textFields as $key => [$label]) {
            $input[$key] = trim(preg_replace('/\s+/', ' ', (string) ($_POST[$key] ?? '')));
            if ($input[$key] === '' || mb_strlen($input[$key]) > 100) {
                $errors[] = "$label is required (up to 100 characters).";
            }
        }
        if ($errors) {
            $settings = $input + $settings;
        } else {
            db()->prepare(
                'INSERT INTO email_request_settings (id, short_greeting, short_closing, formal_greeting, formal_intro, formal_closing, updated_by)
                 VALUES (1, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE short_greeting = VALUES(short_greeting), short_closing = VALUES(short_closing),
                   formal_greeting = VALUES(formal_greeting), formal_intro = VALUES(formal_intro),
                   formal_closing = VALUES(formal_closing), updated_by = VALUES(updated_by)'
            )->execute([...array_values(array_intersect_key($input, $textFields)), $user['user_id']]);
            $_SESSION['flash'] = ['success', 'Template texts saved.'];
            redirect('email_templates.php');
        }
    } elseif ($action === 'save_types') {
        // One row per existing type (keyed by id) plus the "new" row.
        $rows    = (array) ($_POST['type'] ?? []);
        $known   = array_column($types, null, 'id');
        $changes = [];
        $labels  = [];
        foreach ($rows as $key => $row) {
            $id     = $key === 'new' ? 0 : (int) $key;
            $label  = trim(preg_replace('/\s+/', ' ', (string) ($row['label'] ?? '')));
            $phrase = trim(preg_replace('/\s+/', ' ', (string) ($row['phrase'] ?? '')));
            $mode   = (string) ($row['text_mode'] ?? 'none');
            $hint   = trim(preg_replace('/\s+/', ' ', (string) ($row['text_hint'] ?? '')));
            $order  = filter_var($row['sort_order'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => -999, 'max_range' => 9999]]);
            $delete = !empty($row['delete']);

            if ($id && !isset($known[$id])) {
                continue;
            }
            if (!$id && $label === '' && $phrase === '') {
                continue;   // blank "add" row
            }
            if ($delete) {
                $changes[] = ['delete', $id];
                continue;
            }
            $name = $label !== '' ? "\"$label\"" : 'A request type';
            if ($label === '' || mb_strlen($label) > 60) {
                $errors[] = "$name: the label is required (up to 60 characters).";
            } elseif (isset($labels[mb_strtolower($label)])) {
                $errors[] = "$name is listed twice.";
            }
            $labels[mb_strtolower($label)] = true;
            if ($phrase === '' || mb_strlen($phrase) > 150) {
                $errors[] = "$name: the Formal wording is required (up to 150 characters).";
            }
            if (!array_key_exists($mode, SERVICE_TEXT_MODES)) {
                $errors[] = "$name: choose how the text box is used.";
            } elseif ($mode !== 'none' && strpos($phrase, '{text}') === false) {
                $errors[] = "$name has a text box, so its Formal wording must contain {text}.";
            } elseif ($mode === 'none' && strpos($phrase, '{text}') !== false) {
                $errors[] = "$name has no text box, so remove {text} from its Formal wording.";
            }
            if (mb_strlen($hint) > 60) {
                $errors[] = "$name: the text box hint must be at most 60 characters.";
            }
            if ($order === false) {
                $errors[] = "$name: order must be a whole number.";
            }
            $changes[] = ['save', $id, $label, $phrase, $mode, $hint === '' ? null : $hint, (int) $order, empty($row['is_active']) ? 0 : 1];
        }

        if (!$errors) {
            $pdo = db();
            $pdo->beginTransaction();
            try {
                // Deletes first, so a deleted label can be reused in the same save.
                usort($changes, fn($a, $b) => ($a[0] === 'delete' ? 0 : 1) <=> ($b[0] === 'delete' ? 0 : 1));
                foreach ($changes as $change) {
                    if ($change[0] === 'delete') {
                        $pdo->prepare('DELETE FROM email_request_types WHERE id = ?')->execute([$change[1]]);
                    } elseif ($change[1]) {
                        $pdo->prepare('UPDATE email_request_types SET label = ?, phrase = ?, text_mode = ?, text_hint = ?, sort_order = ?, is_active = ? WHERE id = ?')
                            ->execute([...array_slice($change, 2), $change[1]]);
                    } else {
                        $pdo->prepare('INSERT INTO email_request_types (label, phrase, text_mode, text_hint, sort_order, is_active) VALUES (?, ?, ?, ?, ?, ?)')
                            ->execute(array_slice($change, 2));
                    }
                }
                $pdo->commit();
            } catch (PDOException $ex) {
                $pdo->rollBack();
                if ($ex->getCode() !== '23000') {
                    throw $ex;
                }
                $errors[] = 'Two request types cannot have the same label.';
            }
            if (!$errors) {
                $_SESSION['flash'] = ['success', 'Request types saved.'];
                redirect('email_templates.php');
            }
        }
    }
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

// After a failed types save, show what was typed.
$postedTypes = ($_POST['action'] ?? '') === 'save_types' ? (array) ($_POST['type'] ?? []) : [];
$typeValue = static function ($id, string $field, $default) use ($postedTypes) {
    if (!$postedTypes) {
        return $default;
    }
    return $postedTypes[$id][$field] ?? (in_array($field, ['is_active', 'delete'], true) ? '' : $default);
};

// Sample sentence for the help text.
$sample = service_sentence([
    service_action(['label' => 'Transfer', 'phrase' => 'transfer {model}', 'text_mode' => 'none']),
    service_action(['label' => 'Update to version', 'phrase' => 'update {model} to version {text}', 'text_mode' => 'append'], '16.21'),
], 'S1F2');

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
        <div class="col-xl-4">
          <div class="card">
            <div class="card-body">
              <h5 class="card-title">Template Texts</h5>
              <form method="post" class="row g-3">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="save_texts">
<?php foreach ($textFields as $key => [$label, $placeholder]): ?>
                <div class="col-12">
                  <label for="set_<?= e($key) ?>" class="form-label"><?= e($label) ?></label>
                  <input type="text" class="form-control" id="set_<?= e($key) ?>" name="<?= e($key) ?>" maxlength="100" required placeholder="<?= e($placeholder) ?>" value="<?= e($settings[$key]) ?>">
                </div>
<?php endforeach; ?>
                <div class="col-12 small text-muted">
                  The Formal template adds Goodmorning / Good afternoon / Good evening after its first line, based on the time the email is made.
                  Recipients are managed on <a href="email_recipients.php">Email Recipients</a>.
                </div>
                <div class="col-12">
                  <button type="submit" class="btn btn-primary">Save Texts</button>
                </div>
              </form>
            </div>
          </div>
        </div>

        <div class="col-xl-8">
          <div class="card">
            <div class="card-body">
              <h5 class="card-title">Request Types</h5>
              <p class="small text-muted">
                The <strong>label</strong> is the checkbox text and is used as-is in the Short template.
                The <strong>Formal wording</strong> goes into "please …": <code>{model}</code> becomes the terminal model and <code>{text}</code> the typed text.
                Several types are joined, e.g. "<?= e($sample) ?>".
              </p>
              <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="save_types">
                <div class="table-responsive">
                  <table class="table table-sm align-middle">
                    <thead>
                      <tr>
                        <th style="min-width: 10rem">Label</th>
                        <th style="min-width: 14rem">Formal wording</th>
                        <th style="min-width: 10rem">Text box</th>
                        <th style="min-width: 8rem">Text box hint</th>
                        <th style="width: 5rem">Order</th>
                        <th class="text-center">Active</th>
                        <th class="text-center">Delete</th>
                      </tr>
                    </thead>
                    <tbody>
<?php foreach (array_merge($types, [['id' => 'new', 'label' => '', 'phrase' => '', 'text_mode' => 'none', 'text_hint' => '', 'sort_order' => (int) max(array_column($types, 'sort_order') ?: [0]) + 10, 'is_active' => 1]]) as $type):
    $id    = $type['id'];
    $isNew = $id === 'new';
    $name  = 'type[' . $id . ']';
    $mode  = $typeValue($id, 'text_mode', $type['text_mode']);
?>
                      <tr<?= $isNew ? ' class="table-light"' : '' ?>>
                        <td><input type="text" class="form-control form-control-sm" name="<?= e($name) ?>[label]" maxlength="60" value="<?= e($typeValue($id, 'label', $type['label'])) ?>" placeholder="<?= $isNew ? 'Add a new type…' : '' ?>" aria-label="Label"></td>
                        <td><input type="text" class="form-control form-control-sm font-monospace" name="<?= e($name) ?>[phrase]" maxlength="150" value="<?= e($typeValue($id, 'phrase', $type['phrase'])) ?>" placeholder="<?= $isNew ? 'e.g. check {model}' : '' ?>" aria-label="Formal wording"></td>
                        <td>
                          <select class="form-select form-select-sm" name="<?= e($name) ?>[text_mode]" aria-label="Text box">
<?php foreach (SERVICE_TEXT_MODES as $key => $text): ?>
                            <option value="<?= e($key) ?>" <?= $mode === $key ? 'selected' : '' ?>><?= e($text) ?></option>
<?php endforeach; ?>
                          </select>
                        </td>
                        <td><input type="text" class="form-control form-control-sm" name="<?= e($name) ?>[text_hint]" maxlength="60" value="<?= e($typeValue($id, 'text_hint', (string) $type['text_hint'])) ?>" aria-label="Text box hint"></td>
                        <td><input type="number" class="form-control form-control-sm" name="<?= e($name) ?>[sort_order]" value="<?= e($typeValue($id, 'sort_order', $type['sort_order'])) ?>" aria-label="Order"></td>
                        <td class="text-center"><input type="checkbox" class="form-check-input" name="<?= e($name) ?>[is_active]" value="1" <?= $typeValue($id, 'is_active', $type['is_active']) ? 'checked' : '' ?> aria-label="Active"></td>
                        <td class="text-center">
<?php if (!$isNew): ?>
                          <input type="checkbox" class="form-check-input" name="<?= e($name) ?>[delete]" value="1" <?= $typeValue($id, 'delete', '') ? 'checked' : '' ?> aria-label="Delete">
<?php endif; ?>
                        </td>
                      </tr>
<?php endforeach; ?>
                    </tbody>
                  </table>
                </div>
                <p class="small text-muted">Untick <strong>Active</strong> to hide a type without deleting it. Saved email requests keep their text either way.</p>
                <button type="submit" class="btn btn-primary">Save Request Types</button>
              </form>
            </div>
          </div>
        </div>
      </div>
    </section>

  </main><!-- End #main -->

<?php
require __DIR__ . '/includes/footer.php';
require __DIR__ . '/includes/scripts.php';
