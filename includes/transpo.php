<?php
/**
 * TRANSPORTATION EXPENSE module: shared helpers.
 * - Location normalization + duplicate detection (used by filing, route fares, import)
 * - Route fare master list lookups and changes (with history)
 * - Permissions and formatting
 * Include after includes/config.php.
 */

const TRANSPO_MODES = [
    'TRYKE' => 'Tryke',
    'HABAL' => 'Habal-habal',
    'PUJ'   => 'PUJ (jeep)',
    'OTHER' => 'Other',
];
const TRANSPO_LOCATION_TYPES = [
    'base'     => 'Base',
    'merchant' => 'Merchant',
    'landmark' => 'Landmark / Dropoint',
];
const TRANSPO_TICKET_PATTERN     = '/^\d{6}-[A-Z]+-\d{4}$/';
const TRANSPO_DEFAULT_BASE       = 'NEAREST HIGHWAY FROM BASE';
const TRANSPO_SIMILAR_THRESHOLD  = 80;     // % similarity that triggers "Did you mean ...?"
const TRANSPO_MAX_FARE           = 99999.99;
const TRANSPO_MAX_LEGS           = 20;

/* ======================================================================
 * Normalization (one function used everywhere)
 * ==================================================================== */

/**
 * Normalized form used to compare location names:
 * uppercase, punctuation (. , - # ') removed, spaces collapsed, and common
 * spellings standardized (DROP POINT / DROP PT -> DROPOINT, HWY -> HIGHWAY, NAT'L -> NATIONAL,
 * GEN SAN / GENERAL SANTOS -> GENSAN).
 */
function transpo_normalize(string $name): string
{
    $s = mb_strtoupper(trim($name), 'UTF-8');
    $s = str_replace(['’', '‘', '`'], "'", $s);
    $s = str_replace("'", '', $s);                       // NAT'L -> NATL
    $s = preg_replace('/[.,\-#]+/u', ' ', $s);
    $s = preg_replace('/\s+/u', ' ', $s);
    $s = ' ' . trim($s) . ' ';

    // Longest phrases first so "GENERAL SANTOS" wins over "GEN".
    $words = [
        ' GENERAL SANTOS ' => ' GENSAN ',
        ' GEN SANTOS '     => ' GENSAN ',
        ' GEN SAN '        => ' GENSAN ',
        ' DROP POINT '     => ' DROPOINT ',
        ' DROPPOINT '      => ' DROPOINT ',
        ' DROP PNT '       => ' DROPOINT ',
        ' DROP PT '        => ' DROPOINT ',
        ' DRP PT '         => ' DROPOINT ',
        ' HIGH WAY '       => ' HIGHWAY ',
        ' HGWY '           => ' HIGHWAY ',
        ' HWAY '           => ' HIGHWAY ',
        ' HWY '            => ' HIGHWAY ',
        ' NATL '           => ' NATIONAL ',
    ];
    // Run twice so back-to-back matches ("HWY HWY") are both replaced.
    for ($i = 0; $i < 2; $i++) {
        $s = str_replace(array_keys($words), array_values($words), $s);
    }
    return trim(preg_replace('/\s+/u', ' ', $s));
}

/**
 * Display name as typed: trimmed and with single spaces.
 */
function transpo_clean_name(string $name): string
{
    return trim(preg_replace('/\s+/u', ' ', $name));
}

/**
 * Similarity of two normalized names, 0-100. Uses similar_text and Levenshtein,
 * and treats one name fully contained in the other ("MAKAR" / "MAKAR DROPOINT") as similar.
 */
function transpo_similarity(string $a, string $b): float
{
    if ($a === '' || $b === '') {
        return 0.0;
    }
    similar_text($a, $b, $percent);
    $max   = max(strlen($a), strlen($b));
    $lev   = $max <= 255 ? (1 - levenshtein($a, $b) / $max) * 100 : 0;
    $score = max($percent, $lev);

    $short = strlen($a) <= strlen($b) ? $a : $b;
    $long  = $short === $a ? $b : $a;
    if (strlen($short) >= 4 && preg_match('/(^| )' . preg_quote($short, '/') . '( |$)/', $long)) {
        $score = max($score, 85);
    }
    return round($score, 1);
}

/* ======================================================================
 * Locations
 * ==================================================================== */

function transpo_location(int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM transpo_locations WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

/**
 * Exact match: 1) normalized name, 2) alias. Adds 'matched_via' => 'name'|'alias'.
 */
function transpo_find_location(string $name): ?array
{
    $norm = transpo_normalize($name);
    if ($norm === '') {
        return null;
    }
    $stmt = db()->prepare('SELECT * FROM transpo_locations WHERE normalized_name = ?');
    $stmt->execute([$norm]);
    if ($row = $stmt->fetch()) {
        return $row + ['matched_via' => 'name'];
    }
    $stmt = db()->prepare(
        'SELECT l.* FROM transpo_location_aliases a JOIN transpo_locations l ON l.id = a.location_id WHERE a.normalized_alias = ?'
    );
    $stmt->execute([$norm]);
    if ($row = $stmt->fetch()) {
        return $row + ['matched_via' => 'alias'];
    }
    return null;
}

/**
 * Locations whose name (or an alias) is similar to $name, best first.
 * Each row gets 'score'. Exact matches are not included.
 */
function transpo_similar_locations(string $name, int $limit = 5): array
{
    $norm = transpo_normalize($name);
    if ($norm === '') {
        return [];
    }

    $sql = 'SELECT l.*, GROUP_CONCAT(a.normalized_alias SEPARATOR "|") AS alias_list
            FROM transpo_locations l LEFT JOIN transpo_location_aliases a ON a.location_id = l.id';
    $params = [];

    // Up to a few thousand places, score them all (catches typos like MAKKAR / MAKAR).
    // Beyond that, narrow first with LIKE on the start and end of each distinctive word,
    // which still tolerates a typo in the middle of a word.
    $total = (int) db()->query('SELECT COUNT(*) FROM transpo_locations')->fetchColumn();
    if ($total > 3000) {
        $generic  = ['DROPOINT', 'HIGHWAY', 'GENSAN', 'CITY', 'THE', 'STREET', 'ST', 'ROAD', 'RD', 'NATIONAL', 'BRGY', 'BARANGAY', 'STORE', 'TO', 'FROM', 'OF', 'AND'];
        $keywords = array_values(array_filter(explode(' ', $norm), fn($w) => strlen($w) >= 3 && !in_array($w, $generic, true)));
        $pieces   = [];
        foreach (array_slice($keywords ?: explode(' ', $norm), 0, 5) as $word) {
            $pieces[] = substr($word, 0, 3);
            $pieces[] = substr($word, -3);
        }
        $where = [];
        foreach (array_unique(array_filter($pieces)) as $piece) {
            $where[]  = '(l.normalized_name LIKE ? OR a.normalized_alias LIKE ?)';
            $like     = '%' . addcslashes($piece, '%_\\') . '%';
            $params[] = $like;
            $params[] = $like;
        }
        if ($where) {
            $sql .= ' WHERE ' . implode(' OR ', $where);
        }
    }
    $sql .= ' GROUP BY l.id ORDER BY l.times_used DESC LIMIT 3000';
    $stmt = db()->prepare($sql);
    $stmt->execute($params);

    $matches = [];
    foreach ($stmt->fetchAll() as $row) {
        if ($row['normalized_name'] === $norm) {
            continue;
        }
        $score = transpo_similarity($norm, $row['normalized_name']);
        foreach (array_filter(explode('|', (string) $row['alias_list'])) as $alias) {
            if ($alias === $norm) {
                continue 2;
            }
            $score = max($score, transpo_similarity($norm, $alias));
        }
        if ($score >= TRANSPO_SIMILAR_THRESHOLD) {
            unset($row['alias_list']);
            $matches[] = $row + ['score' => $score];
        }
    }
    usort($matches, fn($a, $b) => [$b['score'], $b['times_used']] <=> [$a['score'], $a['times_used']]);
    return array_slice($matches, 0, $limit);
}

/**
 * Save another spelling for a location so it matches next time.
 * Skipped if that spelling already belongs to a location or alias.
 */
function transpo_add_alias(int $locationId, string $alias): bool
{
    $alias = transpo_clean_name($alias);
    $norm  = transpo_normalize($alias);
    if ($norm === '' || transpo_find_location($alias)) {
        return false;
    }
    $stmt = db()->prepare('INSERT IGNORE INTO transpo_location_aliases (location_id, alias, normalized_alias) VALUES (?, ?, ?)');
    $stmt->execute([$locationId, mb_substr($alias, 0, 150), mb_substr($norm, 0, 150)]);
    return $stmt->rowCount() === 1;
}

/**
 * Create a location, or return the existing one with the same normalized name.
 */
function transpo_create_location(string $name, string $type, ?string $address, ?int $userId): array
{
    $name = mb_substr(transpo_clean_name($name), 0, 150);
    $norm = mb_substr(transpo_normalize($name), 0, 150);
    if ($existing = transpo_find_location($name)) {
        return $existing;
    }
    $type    = array_key_exists($type, TRANSPO_LOCATION_TYPES) ? $type : 'landmark';
    $address = $address !== null && trim($address) !== '' ? mb_substr(trim($address), 0, 255) : null;
    db()->prepare('INSERT INTO transpo_locations (name, normalized_name, type, address, created_by) VALUES (?, ?, ?, ?, ?)')
        ->execute([$name, $norm, $type, $address, $userId]);
    return transpo_location((int) db()->lastInsertId()) + ['matched_via' => 'created'];
}

/**
 * Fill in a merchant's address the first time one is given.
 */
function transpo_fill_address(array $location, ?string $address): array
{
    $address = $address !== null ? trim($address) : '';
    if ($address !== '' && trim((string) $location['address']) === '') {
        db()->prepare('UPDATE transpo_locations SET address = ? WHERE id = ?')->execute([mb_substr($address, 0, 255), $location['id']]);
        $location['address'] = $address;
    }
    return $location;
}

/**
 * The duplicate-safe way to turn a typed name into a location:
 *   1. exact normalized name  2. alias  3. similar names -> ask the user
 *   4. create only when the user confirms ($decision === 'new').
 * $decision: null (first try), 'new' (create anyway) or an existing location id
 * ("Use existing" - the typed spelling is saved as an alias).
 * Returns ['status' => 'found'|'created'|'similar'|'error', 'location' => row, 'candidates' => [...], 'message' => ...].
 */
function transpo_resolve_location(string $name, string $type, ?string $address, ?int $userId, $decision = null): array
{
    $name = transpo_clean_name($name);
    if ($name === '' || transpo_normalize($name) === '') {
        return ['status' => 'error', 'message' => 'Please type a location name.'];
    }
    if (mb_strlen($name) > 150) {
        return ['status' => 'error', 'message' => 'Location name must be at most 150 characters.'];
    }

    if (is_numeric($decision) && (int) $decision > 0) {
        $location = transpo_location((int) $decision);
        if (!$location) {
            return ['status' => 'error', 'message' => 'That location no longer exists.'];
        }
        if (transpo_normalize($name) !== $location['normalized_name']) {
            transpo_add_alias((int) $location['id'], $name);
        }
        return ['status' => 'found', 'location' => transpo_fill_address($location, $address), 'aliased' => true];
    }

    if ($found = transpo_find_location($name)) {
        return ['status' => 'found', 'location' => transpo_fill_address($found, $address)];
    }

    if ($decision !== 'new') {
        $similar = transpo_similar_locations($name);
        if ($similar) {
            return ['status' => 'similar', 'name' => $name, 'candidates' => $similar];
        }
    }

    return ['status' => 'created', 'location' => transpo_create_location($name, $type, $address, $userId)];
}

/**
 * Count a use of each location (repeat ids count again) and stamp last_used_at.
 */
function transpo_touch_locations(array $ids): void
{
    $counts = array_count_values(array_filter(array_map('intval', $ids)));
    $stmt   = db()->prepare('UPDATE transpo_locations SET times_used = times_used + ?, last_used_at = NOW() WHERE id = ?');
    foreach ($counts as $id => $count) {
        $stmt->execute([$count, $id]);
    }
}

/**
 * The engineer's starting point: their own base, or the shared default.
 */
function transpo_base_location(array $user): array
{
    if (!empty($user['transpo_base_location_id']) && ($loc = transpo_location((int) $user['transpo_base_location_id']))) {
        return $loc;
    }
    $default = transpo_find_location(TRANSPO_DEFAULT_BASE);
    return $default ?: transpo_create_location(TRANSPO_DEFAULT_BASE, 'base', null, null);
}

/**
 * Merge duplicate locations into one, in a single transaction:
 * - trip legs, ticket visits, engineer bases and route fares point to $keepId
 * - when two route fares collide, the approved / most recently updated one is kept,
 *   the usage counts are added, and legs, history and reports move to it
 * - the removed names (and their aliases) become aliases of the kept location
 * Returns a summary of what changed.
 */
function transpo_merge_locations(int $keepId, array $dupIds): array
{
    $dupIds = array_values(array_unique(array_filter(array_map('intval', $dupIds), fn($id) => $id !== $keepId)));
    $keep   = transpo_location($keepId);
    if (!$keep || !$dupIds) {
        throw new InvalidArgumentException('Choose the location to keep and at least one duplicate.');
    }

    $summary = ['merged' => 0, 'legs' => 0, 'visits' => 0, 'rates_moved' => 0, 'rates_combined' => 0, 'aliases' => 0];
    $pdo     = db();
    $pdo->beginTransaction();
    try {
        foreach ($dupIds as $dupId) {
            $dup = transpo_location($dupId);
            if (!$dup) {
                continue;
            }

            foreach (['from_location_id', 'to_location_id'] as $col) {
                $stmt = $pdo->prepare("UPDATE transpo_trip_legs SET $col = ? WHERE $col = ?");
                $stmt->execute([$keepId, $dupId]);
                $summary['legs'] += $stmt->rowCount();
            }
            $stmt = $pdo->prepare('UPDATE transpo_ticket_visits SET merchant_location_id = ? WHERE merchant_location_id = ?');
            $stmt->execute([$keepId, $dupId]);
            $summary['visits'] += $stmt->rowCount();
            $pdo->prepare('UPDATE users SET transpo_base_location_id = ? WHERE transpo_base_location_id = ?')->execute([$keepId, $dupId]);

            // Route fares that touch the duplicate.
            $stmt = $pdo->prepare('SELECT * FROM transpo_route_rates WHERE from_location_id = ? OR to_location_id = ?');
            $stmt->execute([$dupId, $dupId]);
            foreach ($stmt->fetchAll() as $rate) {
                $from = (int) $rate['from_location_id'] === $dupId ? $keepId : (int) $rate['from_location_id'];
                $to   = (int) $rate['to_location_id'] === $dupId ? $keepId : (int) $rate['to_location_id'];
                if ($from === $to) {                     // became "A to A": no longer a route
                    $pdo->prepare('DELETE FROM transpo_route_rates WHERE id = ?')->execute([$rate['id']]);
                    $summary['rates_combined']++;
                    continue;
                }
                $other = transpo_rate_conflict($from, $to, $rate['mode'], (bool) $rate['is_two_way'], (int) $rate['id']);
                if (!$other) {
                    $pdo->prepare('UPDATE transpo_route_rates SET from_location_id = ?, to_location_id = ? WHERE id = ?')->execute([$from, $to, $rate['id']]);
                    $summary['rates_moved']++;
                    continue;
                }
                // Keep the approved one, then the most recently updated.
                $rank   = fn($r) => [$r['status'] === 'approved' ? 1 : 0, $r['updated_at'], (int) $r['id']];
                $winner = $rank($rate) >= $rank($other) ? $rate : $other;
                $loser  = $winner === $rate ? $other : $rate;
                foreach (['transpo_trip_legs', 'transpo_route_rate_history', 'transpo_route_rate_reports'] as $table) {
                    $pdo->prepare("UPDATE $table SET route_rate_id = ? WHERE route_rate_id = ?")->execute([$winner['id'], $loser['id']]);
                }
                $pdo->prepare('UPDATE transpo_route_rates SET times_used = times_used + ? WHERE id = ?')->execute([$loser['times_used'], $winner['id']]);
                $pdo->prepare('DELETE FROM transpo_route_rates WHERE id = ?')->execute([$loser['id']]);
                if ($winner === $rate) {
                    $pdo->prepare('UPDATE transpo_route_rates SET from_location_id = ?, to_location_id = ? WHERE id = ?')->execute([$from, $to, $rate['id']]);
                }
                $summary['rates_combined']++;
            }

            // Keep the duplicate's spellings as aliases of the kept location.
            $pdo->prepare('UPDATE transpo_location_aliases SET location_id = ? WHERE location_id = ?')->execute([$keepId, $dupId]);
            $kept     = transpo_location($keepId);
            $lastUsed = max((string) $kept['last_used_at'], (string) $dup['last_used_at']) ?: null;
            $pdo->prepare('UPDATE transpo_locations SET times_used = times_used + ?, last_used_at = ?, address = COALESCE(address, ?) WHERE id = ?')
                ->execute([$dup['times_used'], $lastUsed, $dup['address'], $keepId]);
            $pdo->prepare('DELETE FROM transpo_locations WHERE id = ?')->execute([$dupId]);
            $stmt = $pdo->prepare('INSERT IGNORE INTO transpo_location_aliases (location_id, alias, normalized_alias) VALUES (?, ?, ?)');
            $stmt->execute([$keepId, $dup['name'], $dup['normalized_name']]);
            $summary['aliases'] += $stmt->rowCount();
            $summary['merged']++;
        }
        $pdo->commit();
    } catch (Throwable $ex) {
        $pdo->rollBack();
        throw $ex;
    }
    return $summary;
}

/* ======================================================================
 * Route fare master list
 * ==================================================================== */

/**
 * Active fare for a route: exact direction first, then the reverse if it is two-way.
 * Approved and pending fares both count. Adds 'direction' => 'exact'|'reverse'.
 */
function transpo_find_rate(int $fromId, int $toId, string $mode): ?array
{
    $stmt = db()->prepare(
        'SELECT * FROM transpo_route_rates WHERE from_location_id = ? AND to_location_id = ? AND mode = ? AND is_active = 1'
    );
    $stmt->execute([$fromId, $toId, $mode]);
    if ($row = $stmt->fetch()) {
        return $row + ['direction' => 'exact'];
    }
    $stmt = db()->prepare(
        'SELECT * FROM transpo_route_rates WHERE from_location_id = ? AND to_location_id = ? AND mode = ? AND is_two_way = 1 AND is_active = 1'
    );
    $stmt->execute([$toId, $fromId, $mode]);
    if ($row = $stmt->fetch()) {
        return $row + ['direction' => 'reverse'];
    }
    return null;
}

/**
 * An existing fare that already covers this route + mode (any status/active state):
 * the same direction, or the reverse when either fare is two-way.
 */
function transpo_rate_conflict(int $fromId, int $toId, string $mode, bool $twoWay, int $exceptId = 0): ?array
{
    $stmt = db()->prepare(
        'SELECT * FROM transpo_route_rates
         WHERE id <> ? AND mode = ? AND (
               (from_location_id = ? AND to_location_id = ?)
            OR (from_location_id = ? AND to_location_id = ? AND (is_two_way = 1 OR ? = 1)))
         LIMIT 1'
    );
    $stmt->execute([$exceptId, $mode, $fromId, $toId, $toId, $fromId, $twoWay ? 1 : 0]);
    return $stmt->fetch() ?: null;
}

/**
 * Add a route fare. Admin fares are approved; engineer fares are pending.
 * Returns ['ok' => true, 'rate' => row] or ['ok' => false, 'error' => ..., 'conflict' => row].
 */
function transpo_add_rate(int $fromId, int $toId, string $mode, float $fare, bool $twoWay, array $user): array
{
    if ($fromId === $toId) {
        return ['ok' => false, 'error' => 'From and To must be different places.'];
    }
    if (!transpo_location($fromId) || !transpo_location($toId)) {
        return ['ok' => false, 'error' => 'Please pick valid From and To locations.'];
    }
    if (!array_key_exists($mode, TRANSPO_MODES)) {
        return ['ok' => false, 'error' => 'Please pick a valid mode.'];
    }
    if ($conflict = transpo_rate_conflict($fromId, $toId, $mode, $twoWay)) {
        return [
            'ok'       => false,
            'error'    => 'This route already has a fare: ' . transpo_money($conflict['fare']) . " ($mode)"
                        . ($conflict['is_active'] ? '' : ' (inactive)') . '. Edit it instead.',
            'conflict' => $conflict,
        ];
    }

    $admin = is_admin($user);
    $pdo   = db();
    $pdo->prepare(
        'INSERT INTO transpo_route_rates (from_location_id, to_location_id, mode, fare, is_two_way, status, created_by, approved_by, approved_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([
        $fromId, $toId, $mode, $fare, $twoWay ? 1 : 0,
        $admin ? 'approved' : 'pending', $user['user_id'],
        $admin ? $user['user_id'] : null, $admin ? date('Y-m-d H:i:s') : null,
    ]);
    $rateId = (int) $pdo->lastInsertId();
    transpo_log_fare($rateId, null, $fare, (int) $user['user_id']);
    transpo_touch_locations([$fromId, $toId]);

    if (!$admin) {
        $route = transpo_location($fromId)['name'] . ($twoWay ? ' ↔ ' : ' → ') . transpo_location($toId)['name'];
        notify_admins('transpo_rate', full_name($user) . " added a route fare for approval: $route ($mode " . transpo_money($fare) . ').', 'route_fares.php?status=pending', $rateId);
    }

    $stmt = $pdo->prepare('SELECT * FROM transpo_route_rates WHERE id = ?');
    $stmt->execute([$rateId]);
    return ['ok' => true, 'rate' => $stmt->fetch()];
}

/**
 * Record a fare change in the history (old_fare NULL = route created).
 */
function transpo_log_fare(int $rateId, $oldFare, float $newFare, ?int $userId): void
{
    db()->prepare('INSERT INTO transpo_route_rate_history (route_rate_id, old_fare, new_fare, changed_by) VALUES (?, ?, ?, ?)')
        ->execute([$rateId, $oldFare, $newFare, $userId]);
}

/**
 * A positive fare with at most 2 decimals, or null.
 */
function transpo_parse_fare($value): ?float
{
    $value = str_replace([',', '₱', 'P', 'p', ' '], '', trim((string) $value));
    if (!preg_match('/^\d+(\.\d{1,2})?$/', $value)) {
        return null;
    }
    $fare = (float) $value;
    return $fare > 0 && $fare <= TRANSPO_MAX_FARE ? round($fare, 2) : null;
}

/**
 * Admin sidebar badge: pending engineer fares + open "wrong fare" reports.
 */
function transpo_pending_counts(): array
{
    $row = db()->query(
        "SELECT (SELECT COUNT(*) FROM transpo_route_rates WHERE status = 'pending') AS rates,
                (SELECT COUNT(*) FROM transpo_route_rate_reports WHERE status = 'open') AS reports"
    )->fetch();
    return ['rates' => (int) $row['rates'], 'reports' => (int) $row['reports']];
}

/* ======================================================================
 * Visits / permissions / formatting
 * ==================================================================== */

/**
 * Engineers can edit or delete their own visits on the current day only; admins any.
 */
function transpo_can_edit_visit(array $visit, array $user): bool
{
    if (is_admin($user)) {
        return true;
    }
    return (int) $visit['engineer_id'] === (int) $user['user_id'] && substr($visit['visited_at'], 0, 10) === date('Y-m-d');
}

function transpo_money($amount): string
{
    return '₱' . number_format((float) $amount, 2);
}

function transpo_type_badge(string $type): string
{
    $class = ['base' => 'bg-dark', 'merchant' => 'bg-primary', 'landmark' => 'bg-info text-dark'][$type] ?? 'bg-secondary';
    return '<span class="badge ' . $class . '">' . e(TRANSPO_LOCATION_TYPES[$type] ?? $type) . '</span>';
}

function transpo_status_badge(string $status, bool $active = true): string
{
    if (!$active) {
        return '<span class="badge bg-light text-muted border">Inactive</span>';
    }
    return $status === 'pending'
        ? '<span class="badge bg-warning text-dark">Pending</span>'
        : '<span class="badge bg-success">Approved</span>';
}

/**
 * A location row as sent to the browser (Tom Select options and API responses).
 */
function transpo_location_json(array $loc): array
{
    return [
        'id'         => (int) $loc['id'],
        'name'       => $loc['name'],
        'type'       => $loc['type'],
        'address'    => (string) ($loc['address'] ?? ''),
        'times_used' => (int) ($loc['times_used'] ?? 0),
    ];
}
