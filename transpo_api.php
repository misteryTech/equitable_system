<?php
/**
 * TRANSPORTATION EXPENSE AJAX endpoint. Always returns JSON.
 *
 * GET  ?action=search_locations&q=&type=   searchable dropdown (names + aliases, most used first)
 * GET  ?action=get_rate&from=&to=&mode=    fare auto-fill from the master list
 * POST action=resolve_location             duplicate-safe "create new location"
 * POST action=quick_add_rate               "No fare set - Add this route fare"
 * POST action=save_visit                   file / edit a ticket with its legs
 * POST action=delete_visit
 * POST action=set_base                     engineer's daily starting point
 * POST action=report_rate                  "Report wrong fare"
 * POST requests need the csrf_token field.
 */
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/transpo.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

function respond(array $data, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($data);
    exit;
}

$user = current_user();
if ($user === null) {
    respond(['error' => 'Your session has expired. Please log in again.'], 401);
}
$isAdmin = is_admin($user);
$action  = $_GET['action'] ?? $_POST['action'] ?? '';

/* ---------- GET ---------- */

if ($action === 'search_locations') {
    $q    = transpo_normalize((string) ($_GET['q'] ?? ''));
    $type = array_key_exists($_GET['type'] ?? '', TRANSPO_LOCATION_TYPES) ? $_GET['type'] : '';
    $sql  = 'SELECT DISTINCT l.* FROM transpo_locations l LEFT JOIN transpo_location_aliases a ON a.location_id = l.id WHERE 1 = 1';
    $params = [];
    if ($q !== '') {
        $sql .= ' AND (l.normalized_name LIKE ? OR a.normalized_alias LIKE ?)';
        $like = '%' . addcslashes($q, '%_\\') . '%';
        array_push($params, $like, $like);
    }
    if ($type !== '') {
        $sql .= ' AND l.type = ?';
        $params[] = $type;
    }
    $stmt = db()->prepare($sql . ' ORDER BY l.times_used DESC, l.name LIMIT 30');
    $stmt->execute($params);
    respond(['results' => array_map('transpo_location_json', $stmt->fetchAll())]);
}

if ($action === 'get_rate') {
    $from = (int) ($_GET['from'] ?? 0);
    $to   = (int) ($_GET['to'] ?? 0);
    $mode = (string) ($_GET['mode'] ?? '');
    if (!$from || !$to || $from === $to || !array_key_exists($mode, TRANSPO_MODES)) {
        respond(['rate' => null]);
    }
    $rate = transpo_find_rate($from, $to, $mode);
    respond(['rate' => $rate ? [
        'id'        => (int) $rate['id'],
        'fare'      => (float) $rate['fare'],
        'status'    => $rate['status'],
        'two_way'   => (bool) $rate['is_two_way'],
        'direction' => $rate['direction'],
    ] : null]);
}

if ($action === 'rate_history') {
    $stmt = db()->prepare(
        "SELECT h.old_fare, h.new_fare, h.changed_at, CONCAT(u.first_name, ' ', u.last_name) AS changed_by
         FROM transpo_route_rate_history h LEFT JOIN users u ON u.user_id = h.changed_by
         WHERE h.route_rate_id = ? ORDER BY h.changed_at DESC, h.id DESC"
    );
    $stmt->execute([(int) ($_GET['rate_id'] ?? 0)]);
    respond(['history' => array_map(fn($h) => [
        'old_fare'   => $h['old_fare'] === null ? null : (float) $h['old_fare'],
        'new_fare'   => (float) $h['new_fare'],
        'changed_at' => date('M j, Y g:i A', strtotime($h['changed_at'])),
        'changed_by' => $h['changed_by'] ?: '—',
    ], $stmt->fetchAll())]);
}

/* ---------- POST ---------- */

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(['error' => 'Unknown action.'], 400);
}
if (!csrf_verify()) {
    respond(['error' => 'Your session expired. Please reload the page.'], 403);
}

/**
 * The engineer a request acts for: yourself, or (admins) any approved user.
 */
function target_engineer(array $user, bool $isAdmin, $requestedId): array
{
    $id = (int) $requestedId;
    if (!$isAdmin || !$id || $id === (int) $user['user_id']) {
        return $user;
    }
    $stmt = db()->prepare("SELECT * FROM users WHERE user_id = ? AND status = 'approved'");
    $stmt->execute([$id]);
    $engineer = $stmt->fetch();
    if (!$engineer) {
        respond(['error' => 'Engineer not found.'], 404);
    }
    return $engineer;
}

switch ($action) {
    case 'resolve_location':
        $type = array_key_exists($_POST['type'] ?? '', TRANSPO_LOCATION_TYPES) ? $_POST['type'] : 'landmark';
        if ($type === 'base' && !$isAdmin) {
            $type = 'landmark';
        }
        $result = transpo_resolve_location(
            (string) ($_POST['name'] ?? ''),
            $type,
            isset($_POST['address']) ? (string) $_POST['address'] : null,
            (int) $user['user_id'],
            $_POST['decision'] ?? null
        );
        if ($result['status'] === 'error') {
            respond(['error' => $result['message']], 422);
        }
        if ($result['status'] === 'similar') {
            respond([
                'status'     => 'similar',
                'name'       => $result['name'],
                'candidates' => array_map(fn($c) => transpo_location_json($c) + ['score' => $c['score']], $result['candidates']),
            ]);
        }
        respond(['status' => $result['status'], 'location' => transpo_location_json($result['location'])]);

    case 'quick_add_rate':
        $fare = transpo_parse_fare($_POST['fare'] ?? '');
        if ($fare === null) {
            respond(['error' => 'Fare must be a positive amount (e.g. 20 or 25.50).'], 422);
        }
        $result = transpo_add_rate((int) ($_POST['from'] ?? 0), (int) ($_POST['to'] ?? 0), (string) ($_POST['mode'] ?? ''), $fare, !empty($_POST['two_way']), $user);
        if (!$result['ok']) {
            respond(['error' => $result['error']], 422);
        }
        $rate = $result['rate'];
        respond(['ok' => true, 'rate' => ['id' => (int) $rate['id'], 'fare' => (float) $rate['fare'], 'status' => $rate['status'], 'two_way' => (bool) $rate['is_two_way'], 'direction' => 'exact']]);

    case 'save_visit':
        $data = json_decode((string) ($_POST['payload'] ?? ''), true);
        if (!is_array($data)) {
            respond(['error' => 'Nothing to save.'], 400);
        }
        $engineer = target_engineer($user, $isAdmin, $data['engineer_id'] ?? 0);
        $visitId  = (int) ($data['visit_id'] ?? 0);
        $errors   = [];

        $existing = null;
        if ($visitId) {
            $stmt = db()->prepare('SELECT * FROM transpo_ticket_visits WHERE id = ?');
            $stmt->execute([$visitId]);
            $existing = $stmt->fetch();
            if (!$existing || !transpo_can_edit_visit($existing, $user)) {
                respond(['error' => 'You can only edit your own entries for the current day.'], 403);
            }
            $engineer = target_engineer($user, $isAdmin, $existing['engineer_id']);
        }

        $ticket = strtoupper(trim((string) ($data['ticket_no'] ?? '')));
        if (!preg_match(TRANSPO_TICKET_PATTERN, $ticket)) {
            $errors[] = 'Ticket no. must look like 260911-BSERV-0523.';
        }
        $merchant = transpo_location((int) ($data['merchant_id'] ?? 0));
        if (!$merchant) {
            $errors[] = 'Please choose the merchant.';
        }
        $visitedAt = DateTimeImmutable::createFromFormat('Y-m-d\TH:i', (string) ($data['visited_at'] ?? ''))
            ?: DateTimeImmutable::createFromFormat('Y-m-d H:i:s', (string) ($data['visited_at'] ?? ''));
        if (!$visitedAt) {
            $errors[] = 'Please enter a valid date and time.';
        } elseif ($visitedAt->format('Y-m-d') > date('Y-m-d')) {
            $errors[] = "The date can't be in the future.";
        } elseif ($existing && !$isAdmin && $visitedAt->format('Y-m-d') !== date('Y-m-d')) {
            $errors[] = 'Entries can only be moved within the current day.';
        }
        $remarks = trim((string) ($data['remarks'] ?? ''));
        $address = trim((string) ($data['address'] ?? ''));
        if (mb_strlen($remarks) > 255 || mb_strlen($address) > 255) {
            $errors[] = 'Remarks and address can be at most 255 characters.';
        }

        $legs = is_array($data['legs'] ?? null) ? array_values($data['legs']) : [];
        if (!$legs) {
            $errors[] = 'Add at least one leg (From / To / Mode / Fare).';
        } elseif (count($legs) > TRANSPO_MAX_LEGS) {
            $errors[] = 'A ticket can have at most ' . TRANSPO_MAX_LEGS . ' legs.';
        }
        $clean = [];
        foreach ($legs as $i => $leg) {
            $n    = $i + 1;
            $from = (int) ($leg['from_id'] ?? 0);
            $to   = (int) ($leg['to_id'] ?? 0);
            $mode = (string) ($leg['mode'] ?? '');
            $fare = transpo_parse_fare($leg['fare'] ?? '');
            if (!$from || !transpo_location($from) || !$to || !transpo_location($to)) {
                $errors[] = "Leg $n: choose From and To.";
                continue;
            }
            if ($from === $to) {
                $errors[] = "Leg $n: From and To must be different.";
            }
            if (!array_key_exists($mode, TRANSPO_MODES)) {
                $errors[] = "Leg $n: choose the mode.";
            }
            if ($fare === null) {
                $errors[] = "Leg $n: fare must be a positive amount.";
            }
            // The master list decides route_rate_id / override, not the browser.
            $rate    = array_key_exists($mode, TRANSPO_MODES) ? transpo_find_rate($from, $to, $mode) : null;
            $clean[] = [
                'from' => $from, 'to' => $to, 'mode' => $mode, 'fare' => $fare,
                'rate_id'    => $rate ? (int) $rate['id'] : null,
                'overridden' => $rate && $fare !== null && abs((float) $rate['fare'] - $fare) > 0.001 ? 1 : 0,
            ];
        }
        if ($errors) {
            respond(['errors' => array_values(array_unique($errors))], 422);
        }

        $pdo = db();
        $pdo->beginTransaction();
        try {
            $visited = $visitedAt->format('Y-m-d H:i:00');
            if ($existing) {
                $pdo->prepare('UPDATE transpo_ticket_visits SET ticket_no = ?, merchant_location_id = ?, visited_at = ?, remarks = ? WHERE id = ?')
                    ->execute([$ticket, $merchant['id'], $visited, $remarks ?: null, $visitId]);
                $pdo->prepare('DELETE FROM transpo_trip_legs WHERE visit_id = ?')->execute([$visitId]);
            } else {
                $pdo->prepare('INSERT INTO transpo_ticket_visits (engineer_id, ticket_no, merchant_location_id, visited_at, remarks) VALUES (?, ?, ?, ?, ?)')
                    ->execute([$engineer['user_id'], $ticket, $merchant['id'], $visited, $remarks ?: null]);
                $visitId = (int) $pdo->lastInsertId();
            }

            $insert   = $pdo->prepare(
                'INSERT INTO transpo_trip_legs (visit_id, seq, from_location_id, to_location_id, mode, fare, route_rate_id, fare_overridden)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $useRate  = $pdo->prepare('UPDATE transpo_route_rates SET times_used = times_used + 1 WHERE id = ?');
            $touched  = [$merchant['id']];
            foreach ($clean as $i => $leg) {
                $insert->execute([$visitId, $i + 1, $leg['from'], $leg['to'], $leg['mode'], $leg['fare'], $leg['rate_id'], $leg['overridden']]);
                if ($leg['rate_id']) {
                    $useRate->execute([$leg['rate_id']]);
                }
                array_push($touched, $leg['from'], $leg['to']);
            }
            transpo_touch_locations($touched);
            transpo_fill_address($merchant, $address);
            $pdo->commit();
        } catch (PDOException $ex) {
            $pdo->rollBack();
            if ($ex->getCode() === '23000') {
                respond(['errors' => ["Ticket $ticket at " . $visitedAt->format('M j, g:i A') . ' is already filed.']], 422);
            }
            throw $ex;
        }
        respond(['ok' => true, 'visit_id' => $visitId, 'date' => $visitedAt->format('Y-m-d')]);

    case 'delete_visit':
        $stmt = db()->prepare('SELECT * FROM transpo_ticket_visits WHERE id = ?');
        $stmt->execute([(int) ($_POST['visit_id'] ?? 0)]);
        $visit = $stmt->fetch();
        if (!$visit || !transpo_can_edit_visit($visit, $user)) {
            respond(['error' => 'You can only delete your own entries for the current day.'], 403);
        }
        db()->prepare('DELETE FROM transpo_ticket_visits WHERE id = ?')->execute([$visit['id']]);   // legs cascade
        respond(['ok' => true]);

    case 'set_base':
        $engineer = target_engineer($user, $isAdmin, $_POST['engineer_id'] ?? 0);
        $location = transpo_location((int) ($_POST['location_id'] ?? 0));
        if (!$location) {
            respond(['error' => 'Please choose a starting point.'], 422);
        }
        db()->prepare('UPDATE users SET transpo_base_location_id = ? WHERE user_id = ?')->execute([$location['id'], $engineer['user_id']]);
        respond(['ok' => true, 'location' => transpo_location_json($location)]);

    case 'report_rate':
        $rateId = (int) ($_POST['rate_id'] ?? 0);
        $note   = trim((string) ($_POST['note'] ?? ''));
        $sugg   = trim((string) ($_POST['suggested_fare'] ?? ''));
        $fare   = $sugg === '' ? null : transpo_parse_fare($sugg);
        $stmt   = db()->prepare('SELECT 1 FROM transpo_route_rates WHERE id = ?');
        $stmt->execute([$rateId]);
        if (!$stmt->fetchColumn()) {
            respond(['error' => 'Route fare not found.'], 404);
        }
        if ($note === '' || mb_strlen($note) > 255) {
            respond(['error' => 'Please describe what is wrong (up to 255 characters).'], 422);
        }
        if ($sugg !== '' && $fare === null) {
            respond(['error' => 'Suggested fare must be a positive amount.'], 422);
        }
        db()->prepare('INSERT INTO transpo_route_rate_reports (route_rate_id, reported_by, suggested_fare, note) VALUES (?, ?, ?, ?)')
            ->execute([$rateId, $user['user_id'], $fare, $note]);
        notify_admins('transpo_report', full_name($user) . ' reported a wrong route fare: ' . $note, 'route_fares.php?tab=reports', (int) db()->lastInsertId());
        respond(['ok' => true]);

    default:
        respond(['error' => 'Unknown action.'], 400);
}
