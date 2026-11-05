<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/layout.php';

$user = require_verified_user();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string) ($_POST['action'] ?? 'predict');

    // Admin: manually set a match result
    if ($action === 'set_result' && is_admin($user)) {
        $matchId = (int) ($_POST['match_id'] ?? 0);
        $score1Raw = trim((string) ($_POST['score1'] ?? ''));
        $score2Raw = trim((string) ($_POST['score2'] ?? ''));

        $score1 = $score1Raw !== '' ? filter_var($score1Raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 99]]) : null;
        $score2 = $score2Raw !== '' ? filter_var($score2Raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 99]]) : null;

        if ($score1 === false || $score2 === false) {
            flash('Invalid score values.', 'error');
            redirect('matches.php');
        }

        $newStatus = ($score1 !== null && $score2 !== null) ? 'finished' : 'scheduled';
        db()->prepare('UPDATE wc_matches SET score1 = ?, score2 = ?, status = ?, updated_at = ? WHERE id = ?')
             ->execute([$score1, $score2, $newStatus, now_sql(), $matchId]);

        // Recalculate every prediction for this match
        $preds = db()->prepare('SELECT id, pred1, pred2 FROM predictions WHERE match_id = ?');
        $preds->execute([$matchId]);
        $upd = db()->prepare('UPDATE predictions SET points = ? WHERE id = ?');
        foreach ($preds->fetchAll() as $pred) {
            $pts = prediction_points((int) $pred['pred1'], (int) $pred['pred2'], $score1, $score2);
            $upd->execute([$pts, (int) $pred['id']]);
        }

        flash('Result saved.', 'success');
        redirect('matches.php');
    }

    // Player: submit or update a score prediction
    $matchId = (int) ($_POST['match_id'] ?? 0);
    $pred1 = filter_input(INPUT_POST, 'pred1', FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 20]]);
    $pred2 = filter_input(INPUT_POST, 'pred2', FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 20]]);

    $statement = db()->prepare('SELECT * FROM wc_matches WHERE id = ?');
    $statement->execute([$matchId]);
    $match = $statement->fetch();

    if (!$match) {
        flash('Match not found.', 'error');
        redirect('matches.php');
    }

    if ($pred1 === false || $pred2 === false || $pred1 === null || $pred2 === null) {
        flash('Predicted goals must be numbers from 0 to 20.', 'error');
        redirect('matches.php#match-' . $matchId);
    }

    if (match_is_locked($match)) {
        flash('This match is locked. Predictions cannot be changed after kickoff.', 'error');
        redirect('matches.php#match-' . $matchId);
    }

    $points = prediction_points(
        (int) $pred1,
        (int) $pred2,
        $match['score1'] === null ? null : (int) $match['score1'],
        $match['score2'] === null ? null : (int) $match['score2']
    );

    $existing = db()->prepare('SELECT id FROM predictions WHERE user_id = ? AND match_id = ?');
    $existing->execute([(int) $user['id'], $matchId]);
    $predictionId = $existing->fetchColumn();

    if ($predictionId) {
        db()->prepare('UPDATE predictions SET pred1 = ?, pred2 = ?, points = ?, updated_at = ? WHERE id = ?')
            ->execute([(int) $pred1, (int) $pred2, $points, now_sql(), (int) $predictionId]);
    } else {
        db()->prepare('INSERT INTO predictions (user_id, match_id, pred1, pred2, points, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)')
            ->execute([(int) $user['id'], $matchId, (int) $pred1, (int) $pred2, $points, now_sql(), now_sql()]);
    }

    flash('Prediction saved.', 'success');
    redirect('matches.php#match-' . $matchId);
}

// ── Data: list view ────────────────────────────────────────────────────────
$matchesQuery = db()->prepare("
    SELECT *
    FROM wc_matches
    ORDER BY
        CASE
            WHEN status <> 'finished'
             AND (kickoff_at_utc IS NULL OR kickoff_at_utc >= ?) THEN 0
            WHEN status <> 'finished' THEN 1
            ELSE 2
        END ASC,
        CASE WHEN status = 'finished' THEN NULL ELSE kickoff_at_utc END ASC,
        CASE WHEN status = 'finished' THEN match_no ELSE NULL END DESC,
        match_date ASC,
        match_no ASC
");
$matchesQuery->execute([now_sql()]);
$matches = $matchesQuery->fetchAll();

$predictionRows = db()->prepare('SELECT * FROM predictions WHERE user_id = ?');
$predictionRows->execute([(int) $user['id']]);
$predictions = [];
foreach ($predictionRows->fetchAll() as $pr) {
    $predictions[(int) $pr['match_id']] = $pr;
}

$totalMatches    = count($matches);
$savedPredictions = count($predictions);
$openMatches     = 0;
$finishedMatches = 0;
$totalPoints     = 0;
foreach ($matches as $m) {
    if ($m['status'] === 'finished') { $finishedMatches++; }
    if (!match_is_locked($m)) { $openMatches++; }
}
foreach ($predictions as $p) { $totalPoints += (int) $p['points']; }

// ── Data: bracket view ─────────────────────────────────────────────────────
$allForBracket = db()->query("SELECT * FROM wc_matches WHERE external_id LIKE 'wc2026-%' ORDER BY match_no ASC")->fetchAll();
$groupStage    = [];
$knockout      = [];

foreach ($allForBracket as $bm) {
    if (!empty($bm['group_name'])) {
        $groupStage[$bm['group_name']][] = $bm;
    } else {
        $knockout[$bm['round_name']][] = $bm;
    }
}
ksort($groupStage);

$koRoundOrder = [
    'Round of 32'              => 0,
    'Round of 16'              => 1,
    'Quarter-finals'           => 2,
    'Quarter-final'            => 2,
    'Semi-finals'              => 3,
    'Semi-final'               => 3,
    'Third-place play-off'     => 4,
    'Third-Place Play-off'     => 4,
    'Third Place Play-off'     => 4,
    'Third Place'              => 4,
    'Third-place match'        => 4,
    'Third place match'        => 4,
    'Match for third place'    => 4,
    'Play-off for third place' => 4,
    'Final'                    => 5,
];
uksort($knockout, static fn(string $a, string $b): int =>
    ($koRoundOrder[$a] ?? 99) <=> ($koRoundOrder[$b] ?? 99)
);

// Separate Final and 3rd-place from the rounds that feed the bracket halves
$finalRound      = null;
$thirdPlaceRound = null;
$bracketRounds   = []; // R32, R16, QF, SF

foreach ($knockout as $rn => $rms) {
    $order = $koRoundOrder[$rn] ?? 99;
    if ($order === 5) {
        $finalRound = ['name' => $rn, 'matches' => $rms];
    } elseif ($order === 4) {
        $thirdPlaceRound = ['name' => $rn, 'matches' => $rms];
    } else {
        $bracketRounds[$rn] = $rms;
    }
}

// Keep knockout rounds aligned like a bracket without letting later rounds
// create huge empty columns.
$maxRoundCount = 0;
foreach ($bracketRounds as $rms) {
    $maxRoundCount = max($maxRoundCount, count($rms));
}
$bracketSlotBasePx = 92;

// Build proper bracket halves by following WXX parent references (with real-team-name fallback)
$bracketMatchByNo = [];
foreach ($allForBracket as $bm) {
    if (empty($bm['group_name'])) {
        $bracketMatchByNo[(int) $bm['match_no']] = $bm;
    }
}
$bracketTeamToMatchNo = [];
foreach ($bracketMatchByNo as $bNo => $bm) {
    foreach (['team1', 'team2'] as $k) {
        $t = trim((string) ($bm[$k] ?? ''));
        if ($t !== '' && !preg_match('/^[WL]\d+$/i', $t)) {
            $bracketTeamToMatchNo[$t][] = $bNo;
        }
    }
}

$sfRoundMatches = [];
foreach ($bracketRounds as $rn => $rms) {
    if (($koRoundOrder[$rn] ?? 99) === 3) {
        $sfRoundMatches = $rms;
        break;
    }
}

$leftHalf  = [];
$rightHalf = [];
if (count($sfRoundMatches) === 2) {
    $leftTree  = collect_bracket_half($sfRoundMatches[0], $bracketMatchByNo, $bracketTeamToMatchNo, $koRoundOrder);
    $rightTree = collect_bracket_half($sfRoundMatches[1], $bracketMatchByNo, $bracketTeamToMatchNo, $koRoundOrder);
    foreach ($bracketRounds as $rn => $rms) {
        $leftHalf[$rn]  = $leftTree[$rn]  ?? [];
        $rightHalf[$rn] = $rightTree[$rn] ?? [];
    }
} else {
    foreach ($bracketRounds as $rn => $rms) {
        $half           = (int) ceil(count($rms) / 2);
        $leftHalf[$rn]  = array_slice($rms, 0, $half);
        $rightHalf[$rn] = array_slice($rms, $half);
    }
}

// ── Helpers ────────────────────────────────────────────────────────────────
function match_date_label(array $match): string
{
    $date = DateTimeImmutable::createFromFormat('Y-m-d', (string) $match['match_date']);
    return $date ? $date->format('D, M j') : (string) $match['match_date'];
}

function match_time_label(array $match): string
{
    $parts = [];
    if (!empty($match['match_time'])) { $parts[] = (string) $match['match_time']; }
    if (!empty($match['ground']))     { $parts[] = (string) $match['ground']; }
    return implode(' · ', $parts);
}

function build_group_standings(array $matches): array
{
    $table = [];

    foreach ($matches as $match) {
        foreach (['team1', 'team2'] as $teamKey) {
            $team = trim((string) ($match[$teamKey] ?? ''));
            if ($team === '') {
                continue;
            }

            $table[$team] ??= [
                'team' => $team,
                'played' => 0,
                'wins' => 0,
                'draws' => 0,
                'losses' => 0,
                'gf' => 0,
                'ga' => 0,
                'gd' => 0,
                'points' => 0,
            ];
        }

        if (
            $match['status'] !== 'finished'
            || $match['score1'] === null
            || $match['score2'] === null
        ) {
            continue;
        }

        $team1 = trim((string) $match['team1']);
        $team2 = trim((string) $match['team2']);
        if ($team1 === '' || $team2 === '') {
            continue;
        }

        $score1 = (int) $match['score1'];
        $score2 = (int) $match['score2'];

        $table[$team1]['played']++;
        $table[$team2]['played']++;
        $table[$team1]['gf'] += $score1;
        $table[$team1]['ga'] += $score2;
        $table[$team2]['gf'] += $score2;
        $table[$team2]['ga'] += $score1;

        if ($score1 > $score2) {
            $table[$team1]['wins']++;
            $table[$team2]['losses']++;
            $table[$team1]['points'] += 3;
        } elseif ($score2 > $score1) {
            $table[$team2]['wins']++;
            $table[$team1]['losses']++;
            $table[$team2]['points'] += 3;
        } else {
            $table[$team1]['draws']++;
            $table[$team2]['draws']++;
            $table[$team1]['points']++;
            $table[$team2]['points']++;
        }
    }

    foreach ($table as &$row) {
        $row['gd'] = $row['gf'] - $row['ga'];
    }
    unset($row);

    $rows = array_values($table);
    usort($rows, static function (array $a, array $b): int {
        return $b['points'] <=> $a['points']
            ?: $b['gd'] <=> $a['gd']
            ?: $b['gf'] <=> $a['gf']
            ?: $a['ga'] <=> $b['ga']
            ?: strcasecmp($a['team'], $b['team']);
    });

    return $rows;
}

function render_bk_match(array $m, array $user): void
{
    $s1       = $m['score1'];
    $s2       = $m['score2'];
    $finished = $m['status'] === 'finished';
    $t1wins   = $finished && $s1 !== null && $s2 !== null && (int) $s1 > (int) $s2;
    $t2wins   = $finished && $s1 !== null && $s2 !== null && (int) $s2 > (int) $s1;
    ?>
    <div class="bk-match <?= $finished ? 'is-finished' : '' ?>">
        <div class="bk-team <?= $t1wins ? 'is-winner' : '' ?>">
            <span><?= h($m['team1']) ?></span>
            <span class="bk-score <?= !$finished ? 'pending' : '' ?>">
                <?= $finished && $s1 !== null ? (int) $s1 : '?' ?>
            </span>
        </div>
        <div class="bk-team <?= $t2wins ? 'is-winner' : '' ?>">
            <span><?= h($m['team2']) ?></span>
            <span class="bk-score <?= !$finished ? 'pending' : '' ?>">
                <?= $finished && $s2 !== null ? (int) $s2 : '?' ?>
            </span>
        </div>
        <div class="bk-match-meta">
            <span>#<?= (int) $m['match_no'] ?></span>
            <span><?= h($m['match_date']) ?></span>
        </div>
        <?php if (is_admin($user)): ?>
            <details class="bk-result-editor">
                <summary>Edit</summary>
                <form method="post" class="bk-result-form">
                    <?= csrf_input() ?>
                    <input type="hidden" name="action" value="set_result">
                    <input type="hidden" name="match_id" value="<?= (int) $m['id'] ?>">
                    <input type="number" name="score1" min="0" max="30"
                           value="<?= $s1 !== null ? (int) $s1 : '' ?>" placeholder="?">
                    <span>:</span>
                    <input type="number" name="score2" min="0" max="30"
                           value="<?= $s2 !== null ? (int) $s2 : '' ?>" placeholder="?">
                    <button class="button small" type="submit">Save</button>
                </form>
            </details>
        <?php endif; ?>
    </div>
    <?php
}

/**
 * Recursively collect all ancestor matches of $match in bracket display order.
 * Follows WXX parent references; for real team names, finds the source match via
 * a reverse lookup so finished/pre-seeded matches are still placed correctly.
 *
 * @return array<string, list<array>> round_name => [match, ...]
 */
function collect_bracket_half(
    array $match,
    array $byNo,
    array $teamToMatchNo,
    array $koRoundOrder,
    int $depth = 0
): array {
    if ($depth > 8) {
        return [$match['round_name'] => [$match]];
    }
    $rn      = (string) $match['round_name'];
    $myOrder = $koRoundOrder[$rn] ?? 99;
    $result  = [$rn => [$match]];

    foreach (['team1', 'team2'] as $k) {
        $team = trim((string) ($match[$k] ?? ''));
        if ($team === '') {
            continue;
        }
        $parentNo = null;
        if (preg_match('/^W(\d+)$/i', $team, $ref)) {
            $parentNo = (int) $ref[1];
        } elseif (!preg_match('/^[WL]\d+$/i', $team)) {
            foreach ($teamToMatchNo[$team] ?? [] as $candNo) {
                $cand = $byNo[$candNo] ?? null;
                if ($cand !== null && ($koRoundOrder[$cand['round_name']] ?? 99) < $myOrder) {
                    $parentNo = $candNo;
                    break;
                }
            }
        }
        if ($parentNo !== null && isset($byNo[$parentNo])) {
            foreach (collect_bracket_half($byNo[$parentNo], $byNo, $teamToMatchNo, $koRoundOrder, $depth + 1) as $subRn => $subMs) {
                foreach ($subMs as $sm) {
                    $result[$subRn][] = $sm;
                }
            }
        }
    }
    return $result;
}

$groupStandings = [];
foreach ($groupStage as $groupName => $groupMatches) {
    $groupStandings[$groupName] = build_group_standings($groupMatches);
}

render_header('Matches');
?>

<section class="page-head matches-head">
    <div>
        <h1>Matches</h1>
        <p class="muted">Submit exact normal-time scores before kickoff. Extra-time and penalties do not count.</p>
    </div>
    <?php if (is_admin($user)): ?>
        <a class="button ghost" href="admin.php">Admin panel</a>
    <?php endif; ?>
</section>

<?php if (!$matches): ?>
    <section class="panel narrow">
        <h2>No matches loaded</h2>
        <p>CSKA needs to import the World Cup data from the admin panel.</p>
        <?php if (is_admin($user)): ?>
            <a class="button" href="admin.php">Go to admin</a>
        <?php endif; ?>
    </section>
<?php else: ?>

    <section class="matches-overview">
        <div class="match-stat">
            <span><?= $openMatches ?></span>
            <label>Open for picks</label>
        </div>
        <div class="match-stat">
            <span><?= $savedPredictions ?></span>
            <label>Your picks</label>
        </div>
        <div class="match-stat">
            <span><?= $totalPoints ?></span>
            <label>Your points</label>
        </div>
        <div class="match-stat">
            <span><?= $finishedMatches ?>/<?= $totalMatches ?></span>
            <label>Finished</label>
        </div>
    </section>

    <div class="page-tabs">
        <button class="page-tab is-active" data-tab="bracket">Bracket</button>
        <button class="page-tab" data-tab="list">Match List</button>
    </div>

    <!-- ── BRACKET TAB ──────────────────────────────────────────────────── -->
    <div class="tab-pane is-active" id="tab-bracket">

        <!-- Knockout bracket (shown first) -->
        <?php if ($bracketRounds || $finalRound): ?>
            <div class="bracket-stage-title">Knockout Stage</div>

            <div class="knockout-bracket-wrapper">
                <div class="knockout-bracket-inner">

                    <!-- LEFT HALF: rounds flow R32 → SF toward the Final -->
                    <div class="bracket-half bracket-left">
                        <?php foreach ($leftHalf as $rn => $halfMatches): ?>
                            <?php
                            $mult = $maxRoundCount > 0
                                ? $maxRoundCount / max(1, count($knockout[$rn] ?? $halfMatches))
                                : 1;
                            $slotHeight = (int) round($bracketSlotBasePx * $mult);
                            ?>
                            <div class="bracket-round-col">
                                <div class="bracket-round-col-header"><?= h($rn) ?></div>
                                <?php foreach ($halfMatches as $m): ?>
                                    <div class="bracket-slot" style="--slot-height: <?= $slotHeight ?>px">
                                        <?php render_bk_match($m, $user); ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <!-- CENTER: Final + 3rd place -->
                    <div class="bracket-final-col">
                        <div class="bracket-round-col-header"><?= $finalRound ? h($finalRound['name']) : 'Final' ?></div>
                        <div class="bracket-final-slot">
                            <?php if ($finalRound): ?>
                                <?php foreach ($finalRound['matches'] as $m): ?>
                                    <?php render_bk_match($m, $user); ?>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="bk-match">
                                    <div class="bk-team"><span>TBD</span><span class="bk-score pending">?</span></div>
                                    <div class="bk-team"><span>TBD</span><span class="bk-score pending">?</span></div>
                                </div>
                            <?php endif; ?>
                        </div>
                        <?php if ($thirdPlaceRound): ?>
                            <div class="bracket-third-sub-header"><?= h($thirdPlaceRound['name']) ?></div>
                            <div class="bracket-final-slot bracket-third-slot">
                                <?php foreach ($thirdPlaceRound['matches'] as $m): ?>
                                    <?php render_bk_match($m, $user); ?>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- RIGHT HALF: row-reverse so SF is closest to Final, R32 is outermost -->
                    <div class="bracket-half bracket-right">
                        <?php foreach ($rightHalf as $rn => $halfMatches): ?>
                            <?php
                            $mult = $maxRoundCount > 0
                                ? $maxRoundCount / max(1, count($knockout[$rn] ?? $halfMatches))
                                : 1;
                            $slotHeight = (int) round($bracketSlotBasePx * $mult);
                            ?>
                            <div class="bracket-round-col">
                                <div class="bracket-round-col-header"><?= h($rn) ?></div>
                                <?php foreach ($halfMatches as $m): ?>
                                    <div class="bracket-slot" style="--slot-height: <?= $slotHeight ?>px">
                                        <?php render_bk_match($m, $user); ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>

                </div>
            </div>

        <?php endif; ?>

        <!-- Group stage (shown below knockout) -->
        <?php if ($groupStage): ?>
            <div class="bracket-stage-title">Group Stage</div>
            <div class="bracket-groups-grid">
                <?php $groupIndex = 0; ?>
                <?php foreach ($groupStage as $groupName => $groupMatches): ?>
                    <?php
                    $groupIndex++;
                    $dialogId = 'group-results-' . $groupIndex;
                    $standings = $groupStandings[$groupName] ?? [];
                    ?>
                    <div class="bracket-group bracket-standings-card">
                        <div class="bracket-group-head"><?= h($groupName) ?></div>
                        <table class="group-standings-table">
                            <thead>
                                <tr>
                                    <th>Team</th>
                                    <th>P</th>
                                    <th>W</th>
                                    <th>D</th>
                                    <th>L</th>
                                    <th>GD</th>
                                    <th>Pts</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($standings as $row): ?>
                                    <tr>
                                        <td><?= h($row['team']) ?></td>
                                        <td><?= (int) $row['played'] ?></td>
                                        <td><?= (int) $row['wins'] ?></td>
                                        <td><?= (int) $row['draws'] ?></td>
                                        <td><?= (int) $row['losses'] ?></td>
                                        <td><?= (int) $row['gd'] > 0 ? '+' : '' ?><?= (int) $row['gd'] ?></td>
                                        <td><?= (int) $row['points'] ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        <div class="group-standings-actions">
                            <button class="button ghost small" type="button" data-group-dialog="<?= h($dialogId) ?>">
                                Results
                            </button>
                        </div>
                    </div>

                    <dialog class="group-results-dialog" id="<?= h($dialogId) ?>">
                        <div class="group-results-window">
                            <div class="group-results-head">
                                <h3><?= h($groupName) ?> Results</h3>
                                <button class="button ghost small group-dialog-close" type="button">Close</button>
                            </div>
                            <div class="group-results-list">
                                <?php foreach ($groupMatches as $gm): ?>
                                    <div class="group-result-row">
                                        <div class="group-result-meta">
                                            <span>#<?= (int) $gm['match_no'] ?></span>
                                            <span><?= h($gm['match_date']) ?></span>
                                        </div>
                                        <div class="group-result-scoreline">
                                            <span><?= h($gm['team1']) ?></span>
                                            <strong class="<?= $gm['status'] !== 'finished' ? 'pending' : '' ?>">
                                                <?= h(format_match_score($gm)) ?>
                                            </strong>
                                            <span><?= h($gm['team2']) ?></span>
                                        </div>
                                        <?php if (!empty(match_time_label($gm))): ?>
                                            <div class="group-result-venue"><?= h(match_time_label($gm)) ?></div>
                                        <?php endif; ?>
                                        <?php if (is_admin($user)): ?>
                                            <form method="post" class="bgm-result-form group-result-form">
                                                <?= csrf_input() ?>
                                                <input type="hidden" name="action" value="set_result">
                                                <input type="hidden" name="match_id" value="<?= (int) $gm['id'] ?>">
                                                <input type="number" name="score1" min="0" max="30"
                                                       value="<?= $gm['score1'] !== null ? (int) $gm['score1'] : '' ?>"
                                                       placeholder="?">
                                                <span>:</span>
                                                <input type="number" name="score2" min="0" max="30"
                                                       value="<?= $gm['score2'] !== null ? (int) $gm['score2'] : '' ?>"
                                                       placeholder="?">
                                                <button class="button small" type="submit">Save</button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </dialog>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if (!$groupStage && !$bracketRounds && !$finalRound): ?>
            <div class="panel narrow">
                <h2>No matches loaded yet</h2>
                <p>Import matches from the admin panel to see the bracket.</p>
                <?php if (is_admin($user)): ?>
                    <a class="button" href="admin.php">Go to admin</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>

    </div><!-- /tab-bracket -->

    <!-- ── LIST TAB ─────────────────────────────────────────────────────── -->
    <div class="tab-pane" id="tab-list">
        <section class="matches-list refined-matches">
            <?php $currentGroup = null; ?>
            <?php foreach ($matches as $match): ?>
                <?php
                $prediction = $predictions[(int) $match['id']] ?? null;
                $locked     = match_is_locked($match);
                $group      = $match['status'] === 'finished' ? 'Finished matches' : match_date_label($match);
                if ($group !== $currentGroup):
                    $currentGroup = $group;
                ?>
                    <div class="matches-date-divider">
                        <span><?= h($group) ?></span>
                        <?php if ($group !== 'Finished matches'): ?>
                            <small>soonest first</small>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
                <?php
                $pickText = $prediction ? (int) $prediction['pred1'] . ':' . (int) $prediction['pred2'] : 'No pick';
                $points   = $prediction ? (int) $prediction['points'] : 0;
                ?>
                <article class="match-card refined-match-card <?= $locked ? 'is-locked' : 'is-open' ?>"
                         id="match-<?= (int) $match['id'] ?>">
                    <div class="match-meta">
                        <div>
                            <span class="match-number">#<?= (int) $match['match_no'] ?></span>
                            <span><?= h($match['round_name']) ?></span>
                        </div>
                        <div class="match-meta-right">
                            <?php if (!$locked && !empty($match['kickoff_at_utc'])): ?>
                                <span class="match-kickoff-time"
                                      data-kickoff-utc="<?= h($match['kickoff_at_utc']) ?>"
                                      data-ground="<?= h($match['ground'] ?? '') ?>">
                                    <?= h(match_time_label($match)) ?>
                                </span>
                            <?php else: ?>
                                <span><?= h(match_time_label($match)) ?></span>
                            <?php endif; ?>
                            <span class="status-pill <?= h($match['status']) ?>"><?= $locked ? 'locked' : 'open' ?></span>
                        </div>
                    </div>

                    <div class="match-main">
                        <div class="team-block">
                            <span>Home</span>
                            <strong><?= h($match['team1']) ?></strong>
                        </div>
                        <div class="score-box">
                            <small><?= $match['status'] === 'finished' ? 'FT' : 'VS' ?></small>
                            <strong><?= format_match_score($match) ?></strong>
                        </div>
                        <div class="team-block right-team">
                            <span>Away</span>
                            <strong><?= h($match['team2']) ?></strong>
                        </div>
                    </div>

                    <div class="match-sub">
                        <span><?= h($match['group_name']) ?></span>
                        <span><?= h($match['match_date']) ?></span>
                    </div>

                    <?php if ($locked): ?>
                        <div class="prediction-locked">
                            <div class="pick-summary">
                                <span>Your pick</span>
                                <strong><?= h($pickText) ?></strong>
                            </div>
                            <div class="pick-summary">
                                <span>Points</span>
                                <strong><?= $points ?></strong>
                            </div>
                            <a class="button ghost small" href="predictions.php?match_id=<?= (int) $match['id'] ?>">All picks</a>
                        </div>
                    <?php else: ?>
                        <form class="prediction-form" method="post">
                            <?= csrf_input() ?>
                            <input type="hidden" name="match_id" value="<?= (int) $match['id'] ?>">
                            <div class="saved-pick">
                                <span>Your pick</span>
                                <strong><?= h($pickText) ?></strong>
                            </div>
                            <label>
                                <?= h($match['team1']) ?>
                                <input type="number" name="pred1" min="0" max="20" required
                                       value="<?= $prediction ? (int) $prediction['pred1'] : '' ?>">
                            </label>
                            <span>:</span>
                            <label>
                                <?= h($match['team2']) ?>
                                <input type="number" name="pred2" min="0" max="20" required
                                       value="<?= $prediction ? (int) $prediction['pred2'] : '' ?>">
                            </label>
                            <button class="button small" type="submit"><?= $prediction ? 'Update' : 'Save' ?></button>
                        </form>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        </section>
    </div><!-- /tab-list -->

<?php endif; ?>

<script>
(function () {
    var tabs  = document.querySelectorAll('.page-tab');
    var panes = document.querySelectorAll('.tab-pane');

    function activate(name) {
        tabs.forEach(function (btn) {
            btn.classList.toggle('is-active', btn.dataset.tab === name);
        });
        panes.forEach(function (pane) {
            pane.classList.toggle('is-active', pane.id === 'tab-' + name);
        });
        history.replaceState(null, '', location.pathname + '#tab-' + name);
    }

    tabs.forEach(function (btn) {
        btn.addEventListener('click', function () { activate(btn.dataset.tab); });
    });

    // If redirected back after saving a prediction, stay on list tab and let
    // the layout.php centerLinkedMatch handler scroll to the match.
    var hash = location.hash;
    if (hash === '#tab-list' || hash.startsWith('#match-')) {
        activate('list');
    }

    // Convert kickoff UTC times to local timezone
    document.querySelectorAll('.match-kickoff-time[data-kickoff-utc]').forEach(function (el) {
        var utcStr = el.dataset.kickoffUtc;
        if (!utcStr) return;
        var dt = new Date(utcStr.replace(' ', 'T') + 'Z');
        if (isNaN(dt.getTime())) return;
        var timePart = dt.toLocaleTimeString([], {hour: '2-digit', minute: '2-digit'});
        var tzPart   = dt.toLocaleTimeString([], {timeZoneName: 'short'}).split(' ').pop();
        var ground   = el.dataset.ground;
        var display  = timePart + ' ' + tzPart;
        if (ground) display += ' · ' + ground;
        el.textContent = display;
    });

    function closeGroupDialog(dialog) {
        if (!dialog) return;
        if (typeof dialog.close === 'function') {
            dialog.close();
        } else {
            dialog.removeAttribute('open');
        }
    }

    document.querySelectorAll('[data-group-dialog]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var dialog = document.getElementById(btn.dataset.groupDialog);
            if (!dialog) return;
            if (typeof dialog.showModal === 'function') {
                dialog.showModal();
            } else {
                dialog.setAttribute('open', 'open');
            }
        });
    });

    document.querySelectorAll('.group-dialog-close').forEach(function (btn) {
        btn.addEventListener('click', function () {
            closeGroupDialog(btn.closest('dialog'));
        });
    });

    document.querySelectorAll('.group-results-dialog').forEach(function (dialog) {
        dialog.addEventListener('click', function (event) {
            if (event.target === dialog) {
                closeGroupDialog(dialog);
            }
        });
    });
})();
</script>

<?php render_footer(); ?>
