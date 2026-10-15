<?php
/**
 * One-time import of plain-text transpo notes.
 *   transpo_import_parse()  text -> tickets with legs (pure, unit-tested)
 *   transpo_import_plan()   matches names to locations, finds duplicates / fare conflicts
 *   transpo_import_commit() saves everything in one transaction
 * Include after includes/transpo.php.
 *
 * Note format understood:
 *   260911-BSERV-0523 FARMACIA BEATRIZ 2026-10-03 09:12:30   (ticket no, merchant, date/time, optional address)
 *   NEAREST HIGHWAY FROM BASE TO POLYTECHNIC - TRYKE = 20     (leg: X TO Y - MODE = fare)
 *   FROM MERCHANT TID: 260911-BSERV-0523 TO MERCHANT - HABAL = 50
 *   LATE FILING - DUE ISMS PROBLEM                            (remark for the next ticket)
 *   =====                                                     (separates groups)
 */

const TRANSPO_IMPORT_BASE     = '@BASE';
const TRANSPO_IMPORT_MERCHANT = '@MERCHANT';

/**
 * "tryke", "TRICYCLE", "habal-habal", "jeep" ... -> TRYKE / HABAL / PUJ / OTHER.
 */
function transpo_import_mode(string $word): string
{
    $w = strtoupper(preg_replace('/[^A-Za-z]/', '', $word));
    if (preg_match('/^(TRYKE|TRIKE|TRICYCLE|TRICY|TRIC|TRI)$/', $w)) {
        return 'TRYKE';
    }
    if (preg_match('/^(HABAL|HABALHABAL|HABALHABAL|MOTOR|MOTORCYCLE|SKYLAB)$/', $w)) {
        return 'HABAL';
    }
    if (preg_match('/^(PUJ|JEEP|JEEPNEY|JEEPNY|MULTICAB)$/', $w)) {
        return 'PUJ';
    }
    return 'OTHER';
}

/**
 * First date/time in a line: [Y-m-d H:i:s, the matched text] or [null, null].
 * Understands 09/30/2026 11:45:45, 9/30/2026 11:45 AM and 2026-10-03 15:54:41.
 */
function transpo_import_datetime(string $line): array
{
    $patterns = [
        '/\b(\d{1,2}\/\d{1,2}\/\d{4})\s+(\d{1,2}:\d{2}(?::\d{2})?)(\s*[AaPp][Mm])?/',
        '/\b(\d{4}-\d{2}-\d{2})[ T](\d{1,2}:\d{2}(?::\d{2})?)(\s*[AaPp][Mm])?/',
    ];
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $line, $m)) {
            $text = $m[0];
            $time = strtotime(str_replace('/', '/', $m[1]) . ' ' . $m[2] . (isset($m[3]) ? ' ' . trim($m[3]) : ''));
            if ($time !== false) {
                return [date('Y-m-d H:i:s', $time), $text];
            }
        }
    }
    return [null, null];
}

/**
 * Parse the notes. Returns:
 *   tickets  [{ticket_no, merchant, visited_at, address, remarks, line, group, legs: [{from, to, mode, fare, line}]}]
 *            from/to are names, TRANSPO_IMPORT_BASE, TRANSPO_IMPORT_MERCHANT (= this ticket's merchant),
 *            or ['ticket' => 'xxx'] (= merchant of ticket xxx)
 *   unparsed [[line_no, text]]
 *   warnings [text]
 */
function transpo_import_parse(string $text): array
{
    $tickets  = [];
    $unparsed = [];
    $warnings = [];
    $pendingLegs = [];
    $pendingRemarks = [];
    $current  = null;          // index into $tickets for the open ticket
    $group    = 1;
    $baseNorm = transpo_normalize(TRANSPO_DEFAULT_BASE);

    $place = static function (string $name) use ($baseNorm) {
        $name = trim(preg_replace('/\s+/', ' ', $name), " \t-–:|.,");
        if (preg_match('/^(?:FROM\s+)?MERCHANT\s+TID\s*[:#]?\s*([A-Z0-9-]+)$/i', $name, $m)) {
            return ['ticket' => strtoupper($m[1])];
        }
        if (preg_match('/^(?:THE\s+)?MERCHANT$/i', $name)) {
            return TRANSPO_IMPORT_MERCHANT;
        }
        if (transpo_normalize($name) === $baseNorm || preg_match('/^(?:BASE|FROM BASE)$/i', $name)) {
            return TRANSPO_IMPORT_BASE;
        }
        return preg_replace('/^FROM\s+/i', '', $name);
    };

    $lines = preg_split('/\r\n|\r|\n/', $text);
    foreach ($lines as $i => $raw) {
        $lineNo = $i + 1;
        $line   = trim(preg_replace('/\s+/u', ' ', $raw));
        $line   = preg_replace('/^(?:\d+[.)]|[-*•])\s+/u', '', $line);        // list bullets
        if ($line === '') {
            continue;
        }

        // ===== separates groups.
        if (preg_match('/^[=]{3,}$/', $line)) {
            $current = null;
            $group++;
            continue;
        }

        // Ticket line.
        if (preg_match('/^(\d{6}-[A-Za-z]+-\d{4})\b\s*[-:|]?\s*(.*)$/', $line, $m)) {
            [$when, $whenText] = transpo_import_datetime($m[2]);
            $rest     = $m[2];
            $address  = '';
            if ($whenText !== null) {
                [$before, $after] = array_pad(explode($whenText, $rest, 2), 2, '');
                $rest    = $before;
                $address = trim($after, " \t-–:|,");
            }
            $tickets[] = [
                'ticket_no'  => strtoupper($m[1]),
                'merchant'   => trim($rest, " \t-–:|,"),
                'visited_at' => $when,
                'address'    => $address,
                'remarks'    => implode('; ', $pendingRemarks),
                'line'       => $lineNo,
                'group'      => $group,
                'legs'       => $pendingLegs,
                'own_legs'   => 0,          // legs written after this ticket line
            ];
            $current        = count($tickets) - 1;
            $pendingLegs    = [];
            $pendingRemarks = [];
            continue;
        }

        // Leg line: "X TO Y - MODE = fare".
        if (preg_match('/^(.+?)\s*[-–:]?\s*([A-Za-z][A-Za-z\-]*)\s*=\s*(?:PHP|P|₱)?\s*(\d+(?:[.,]\d{1,2})?)\s*(?:PESOS?)?$/iu', $line, $m)
            && preg_match('/^(.+?)\s+TO\s+(.+)$/i', $m[1], $route)) {
            $leg = [
                'from' => $place($route[1]),
                'to'   => $place($route[2]),
                'mode' => transpo_import_mode($m[2]),
                'fare' => (float) str_replace(',', '.', $m[3]),
                'line' => $lineNo,
            ];
            // A leg that starts at the open ticket's merchant begins the NEXT ticket's trip.
            $startsNext = $current !== null && $tickets[$current]['legs'] && (
                (is_array($leg['from']) && $leg['from']['ticket'] === $tickets[$current]['ticket_no'])
                || (is_string($leg['from']) && $tickets[$current]['merchant'] !== ''
                    && transpo_normalize($leg['from']) === transpo_normalize($tickets[$current]['merchant'])));
            if ($current === null || $startsNext) {
                $pendingLegs[] = $leg;
                if ($startsNext) {
                    $current = null;
                }
            } else {
                $tickets[$current]['legs'][] = $leg;
                $tickets[$current]['own_legs']++;
            }
            continue;
        }

        // Remark lines attach to the next ticket.
        if (preg_match('/^(LATE\s+FIL+ING|REMARKS?\s*:|NOTE\s*:)/i', $line)) {
            $pendingRemarks[] = preg_replace('/^(REMARKS?|NOTE)\s*:\s*/i', '', $line);
            continue;
        }

        // Details of the open ticket (before its legs): merchant, date/time, address.
        if ($current !== null && $tickets[$current]['own_legs'] === 0) {
            [$when, $whenText] = transpo_import_datetime($line);
            if ($when !== null && $tickets[$current]['visited_at'] === null) {
                $tickets[$current]['visited_at'] = $when;
                $line = trim(str_replace($whenText, '', $line), " \t-–:|,");
                if ($line === '') {
                    continue;
                }
            }
            if ($tickets[$current]['merchant'] === '') {
                $tickets[$current]['merchant'] = trim($line, " \t-–:|,");
            } else {
                $tickets[$current]['address'] = trim($tickets[$current]['address'] . ', ' . $line, ' ,');
            }
            continue;
        }

        $unparsed[] = [$lineNo, $line];
    }

    if ($pendingLegs) {
        $warnings[] = count($pendingLegs) . ' leg(s) at the end have no ticket after them and were not imported.';
    }
    if ($pendingRemarks) {
        $warnings[] = 'Remark(s) at the end have no ticket after them: ' . implode('; ', $pendingRemarks);
    }

    // Resolve "FROM MERCHANT TID: xxx" to that ticket's merchant name.
    $merchantOf = [];
    foreach ($tickets as $t) {
        $merchantOf[$t['ticket_no']] = $t['merchant'];
    }
    foreach ($tickets as $ti => $t) {
        if ($t['merchant'] === '') {
            $warnings[] = "Ticket {$t['ticket_no']} (line {$t['line']}) has no merchant name.";
        }
        if ($t['visited_at'] === null) {
            $warnings[] = "Ticket {$t['ticket_no']} (line {$t['line']}) has no date/time; the default date will be used.";
        }
        foreach ($t['legs'] as $li => $leg) {
            foreach (['from', 'to'] as $end) {
                if (is_array($leg[$end])) {
                    $ref   = $leg[$end]['ticket'];
                    $found = $merchantOf[$ref] ?? null;
                    if ($found === null) {                       // allow the last digits only, e.g. "TID: 0523"
                        foreach ($merchantOf as $no => $name) {
                            if (str_ends_with($no, $ref)) {
                                $found = $name;
                            }
                        }
                    }
                    if ($found === null) {
                        $warnings[] = "Line {$leg['line']}: ticket \"$ref\" was not found; using the previous ticket's merchant.";
                        $found = $ti > 0 ? $tickets[$ti - 1]['merchant'] : TRANSPO_IMPORT_BASE;
                    }
                    $tickets[$ti]['legs'][$li][$end] = $found;
                }
            }
        }
    }

    return ['tickets' => $tickets, 'unparsed' => $unparsed, 'warnings' => $warnings];
}

/**
 * Name a leg end refers to, for display and matching.
 */
function transpo_import_end_name($end, array $ticket, string $baseName): string
{
    if ($end === TRANSPO_IMPORT_BASE) {
        return $baseName;
    }
    if ($end === TRANSPO_IMPORT_MERCHANT) {
        return $ticket['merchant'];
    }
    return (string) $end;
}

/**
 * Work out how each place name would be saved, fare conflicts and duplicates.
 * $choices: [normalized name => 'new' | location id | 'same:<normalized name>'] from the preview form.
 */
function transpo_import_plan(array $parsed, array $engineer, array $choices = []): array
{
    $base     = transpo_base_location($engineer);
    $names    = [];          // normalized => ['name', 'type', 'existing', 'candidates', 'similar_in_import']
    $tickets  = $parsed['tickets'];

    $addName = function (string $name, string $type) use (&$names) {
        $norm = transpo_normalize($name);
        if ($norm === '') {
            return;
        }
        if (!isset($names[$norm])) {
            $names[$norm] = ['name' => transpo_clean_name($name), 'type' => $type, 'count' => 0];
        }
        $names[$norm]['count']++;
        if ($type === 'merchant') {
            $names[$norm]['type'] = 'merchant';
        }
    };
    foreach ($tickets as $t) {
        if ($t['merchant'] !== '') {
            $addName($t['merchant'], 'merchant');
        }
        foreach ($t['legs'] as $leg) {
            foreach (['from', 'to'] as $end) {
                if ($leg[$end] !== TRANSPO_IMPORT_BASE && $leg[$end] !== TRANSPO_IMPORT_MERCHANT) {
                    $addName($leg[$end], 'landmark');
                }
            }
        }
    }

    foreach ($names as $norm => &$info) {
        $info['existing']   = transpo_find_location($info['name']);
        $info['candidates'] = $info['existing'] ? [] : transpo_similar_locations($info['name']);
        $info['similar_in_import'] = [];
        foreach ($names as $otherNorm => $other) {
            if ($otherNorm !== $norm && transpo_similarity($norm, $otherNorm) >= TRANSPO_SIMILAR_THRESHOLD) {
                $info['similar_in_import'][] = $otherNorm;
            }
        }
        // Default choice: existing exact match; else a very close existing place; else new.
        if ($info['existing']) {
            $info['choice'] = (int) $info['existing']['id'];
        } elseif (isset($choices[$norm])) {
            $info['choice'] = $choices[$norm];
        } elseif ($info['candidates'] && $info['candidates'][0]['score'] >= 90) {
            $info['choice'] = (int) $info['candidates'][0]['id'];
        } else {
            $info['choice'] = 'new';
        }
    }
    unset($info);

    // Fares per route + mode (A-B and B-A count as the same two-way route); most recent wins.
    $routes = [];
    $order  = 0;
    foreach ($tickets as $t) {
        foreach ($t['legs'] as $leg) {
            $a = transpo_normalize(transpo_import_end_name($leg['from'], $t, $base['name']));
            $b = transpo_normalize(transpo_import_end_name($leg['to'], $t, $base['name']));
            if ($a === '' || $b === '' || $a === $b) {
                continue;
            }
            $pair = [$a, $b];
            sort($pair);
            $key = implode(' | ', $pair) . ' | ' . $leg['mode'];
            $when = ($t['visited_at'] ?? '') . sprintf('%06d', ++$order);
            $routes[$key]['fares'][(string) $leg['fare']] = max($routes[$key]['fares'][(string) $leg['fare']] ?? '', $when);
            $routes[$key] += ['from' => $a, 'to' => $b, 'mode' => $leg['mode'], 'from_name' => transpo_import_end_name($leg['from'], $t, $base['name']), 'to_name' => transpo_import_end_name($leg['to'], $t, $base['name'])];
        }
    }
    $conflicts = [];
    foreach ($routes as $key => &$route) {
        arsort($route['fares']);
        $route['fare'] = (float) array_key_first($route['fares']);
        if (count($route['fares']) > 1) {
            $conflicts[] = $route['from_name'] . ' ↔ ' . $route['to_name'] . ' (' . $route['mode'] . '): '
                . implode(', ', array_map(fn($f) => transpo_money($f), array_keys($route['fares']))) . ' — using the most recent, ' . transpo_money($route['fare']);
        }
    }
    unset($route);

    // Tickets already filed for this engineer.
    $already = [];
    $stmt = db()->prepare('SELECT 1 FROM transpo_ticket_visits WHERE engineer_id = ? AND ticket_no = ? AND visited_at = ?');
    foreach ($tickets as $t) {
        if ($t['visited_at']) {
            $stmt->execute([$engineer['user_id'], $t['ticket_no'], $t['visited_at']]);
            if ($stmt->fetchColumn()) {
                $already[] = $t['ticket_no'] . ' (' . date('M j, g:i A', strtotime($t['visited_at'])) . ')';
            }
        }
    }

    return ['base' => $base, 'names' => $names, 'routes' => $routes, 'conflicts' => $conflicts, 'already' => $already];
}

/**
 * Save the import in one transaction. Returns a summary.
 * $defaultDate (Y-m-d) is used for tickets without a date/time (at 08:00).
 */
function transpo_import_commit(array $parsed, array $plan, array $engineer, array $admin, string $defaultDate): array
{
    $summary = ['locations' => 0, 'aliases' => 0, 'rates' => 0, 'rates_kept' => [], 'tickets' => 0, 'legs' => 0, 'skipped' => []];
    $pdo = db();
    $pdo->beginTransaction();
    try {
        // 1. Every name -> a location id.
        $ids = [];
        $resolve = function (string $norm, int $depth = 0) use (&$resolve, &$ids, $plan, $admin, &$summary) {
            if (isset($ids[$norm])) {
                return $ids[$norm];
            }
            $info   = $plan['names'][$norm];
            $choice = $info['choice'];
            if (is_string($choice) && str_starts_with($choice, 'same:') && $depth < 5 && isset($plan['names'][substr($choice, 5)])) {
                $id = $resolve(substr($choice, 5), $depth + 1);
                if (transpo_add_alias($id, $info['name'])) {
                    $summary['aliases']++;
                }
            } elseif (is_numeric($choice) && ($loc = transpo_location((int) $choice))) {
                $id = (int) $loc['id'];
                if ($loc['normalized_name'] !== $norm && transpo_add_alias($id, $info['name'])) {
                    $summary['aliases']++;
                }
            } else {
                $existed = transpo_find_location($info['name']);
                $loc     = transpo_create_location($info['name'], $info['type'], null, (int) $admin['user_id']);
                if (!$existed) {
                    $summary['locations']++;
                }
                $id = (int) $loc['id'];
            }
            return $ids[$norm] = $id;
        };
        $baseId = (int) $plan['base']['id'];
        $endId  = function ($end, array $ticket) use ($resolve, $baseId) {
            if ($end === TRANSPO_IMPORT_BASE) {
                return $baseId;
            }
            $name = $end === TRANSPO_IMPORT_MERCHANT ? $ticket['merchant'] : $end;
            $norm = transpo_normalize($name);
            return $norm === transpo_normalize(TRANSPO_DEFAULT_BASE) ? $baseId : $resolve($norm);
        };

        // 2. Seed approved route fares (existing fares are kept as they are).
        foreach ($plan['routes'] as $route) {
            $baseNorm = transpo_normalize($plan['base']['name']);
            $from = $route['from'] === $baseNorm ? $baseId : $resolve($route['from']);
            $to   = $route['to'] === $baseNorm ? $baseId : $resolve($route['to']);
            if ($from === $to) {
                continue;
            }
            if ($existing = transpo_find_rate($from, $to, $route['mode'])) {
                if (abs((float) $existing['fare'] - $route['fare']) > 0.001) {
                    $summary['rates_kept'][] = $route['from_name'] . ' ↔ ' . $route['to_name'] . ' (' . $route['mode'] . '): kept ' . transpo_money($existing['fare']) . ', notes say ' . transpo_money($route['fare']);
                }
                continue;
            }
            $result = transpo_add_rate($from, $to, $route['mode'], $route['fare'], true, $admin);
            if ($result['ok']) {
                $summary['rates']++;
            }
        }

        // 3. Tickets and legs.
        $insVisit = $pdo->prepare('INSERT IGNORE INTO transpo_ticket_visits (engineer_id, ticket_no, merchant_location_id, visited_at, remarks) VALUES (?, ?, ?, ?, ?)');
        $insLeg   = $pdo->prepare('INSERT INTO transpo_trip_legs (visit_id, seq, from_location_id, to_location_id, mode, fare, route_rate_id, fare_overridden) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        $useRate  = $pdo->prepare('UPDATE transpo_route_rates SET times_used = times_used + 1 WHERE id = ?');
        foreach ($parsed['tickets'] as $t) {
            if ($t['merchant'] === '' || !$t['legs']) {
                $summary['skipped'][] = $t['ticket_no'] . ($t['merchant'] === '' ? ' (no merchant)' : ' (no legs)');
                continue;
            }
            $merchantId = $resolve(transpo_normalize($t['merchant']));
            if ($t['address'] !== '') {
                transpo_fill_address(transpo_location($merchantId), $t['address']);
            }
            $visitedAt = $t['visited_at'] ?? ($defaultDate . ' 08:00:00');
            $insVisit->execute([$engineer['user_id'], $t['ticket_no'], $merchantId, $visitedAt, $t['remarks'] !== '' ? mb_substr($t['remarks'], 0, 255) : null]);
            if ($insVisit->rowCount() === 0) {
                $summary['skipped'][] = $t['ticket_no'] . ' (already filed)';
                continue;
            }
            $visitId = (int) $pdo->lastInsertId();
            $touched = [$merchantId];
            foreach ($t['legs'] as $seq => $leg) {
                $from = $endId($leg['from'], $t);
                $to   = $endId($leg['to'], $t);
                if ($from === $to) {
                    continue;
                }
                $rate = transpo_find_rate($from, $to, $leg['mode']);
                $insLeg->execute([$visitId, $seq + 1, $from, $to, $leg['mode'], $leg['fare'], $rate['id'] ?? null,
                                  $rate && abs((float) $rate['fare'] - $leg['fare']) > 0.001 ? 1 : 0]);
                if ($rate) {
                    $useRate->execute([$rate['id']]);
                }
                array_push($touched, $from, $to);
                $summary['legs']++;
            }
            transpo_touch_locations($touched);
            $summary['tickets']++;
        }
        $pdo->commit();
    } catch (Throwable $ex) {
        $pdo->rollBack();
        throw $ex;
    }
    return $summary;
}
