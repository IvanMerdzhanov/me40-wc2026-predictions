<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/layout.php';

require_verified_user();

$matchId = isset($_GET['match_id']) ? (int) $_GET['match_id'] : 0;

if ($matchId > 0) {
    $statement = db()->prepare('SELECT * FROM wc_matches WHERE id = ?');
    $statement->execute([$matchId]);
    $match = $statement->fetch();

    if (!$match) {
        flash('Match not found.', 'error');
        redirect('predictions.php');
    }

    if (!match_is_locked($match)) {
        flash('Picks for this match are hidden until kickoff.', 'error');
        redirect('matches.php#match-' . $matchId);
    }

    $rows = db()->prepare('
        SELECT p.*, u.username
        FROM predictions p
        INNER JOIN users u ON u.id = p.user_id
        WHERE p.match_id = ? AND u.is_verified = 1
        ORDER BY p.points DESC, u.username ASC
    ');
    $rows->execute([$matchId]);
    $predictions = $rows->fetchAll();

    render_header('Match Picks');
    ?>

    <section class="page-head">
        <div>
            <h1><?= h(canonical_team_name((string) $match['team1'])) ?> vs <?= h(canonical_team_name((string) $match['team2'])) ?></h1>
            <p class="muted">Normal-time result: <?= format_match_score($match) ?></p>
        </div>
        <a class="button ghost" href="matches.php#match-<?= (int) $match['id'] ?>">Back to match</a>
    </section>

    <section class="panel">
        <?php if (!$predictions): ?>
            <p class="empty">No predictions were submitted for this match.</p>
        <?php else: ?>
            <div class="table-wrap">
                <table class="match-picks-table">
                    <thead>
                    <tr>
                        <th>Player</th>
                        <th>Pick</th>
                        <th>Outcome</th>
                        <th>Points</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($predictions as $prediction): ?>
                        <tr>
                            <td data-label="Player"><?= h($prediction['username']) ?></td>
                            <td data-label="Pick"><?= (int) $prediction['pred1'] ?>:<?= (int) $prediction['pred2'] ?></td>
                            <td data-label="Outcome"><?= h(outcome_for((int) $prediction['pred1'], (int) $prediction['pred2'])) ?></td>
                            <td data-label="Points"><strong><?= (int) $prediction['points'] ?></strong></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

    <?php
    render_footer();
    exit;
}

function match_has_imported_result(array $match): bool
{
    return $match['score1'] !== null && $match['score2'] !== null;
}

function format_imported_result(array $match): string
{
    if (!match_has_imported_result($match)) {
        return '-';
    }

    return (int) $match['score1'] . ':' . (int) $match['score2'];
}

function finished_pick_match_key(array $match): string
{
    $key = match_fixture_key($match);

    if ($key === '||') {
        return 'match-' . (int) ($match['id'] ?? 0);
    }

    return $key;
}

function finished_pick_team_key(array $match): string
{
    $teams = [
        canonical_team_key((string) ($match['team1'] ?? '')),
        canonical_team_key((string) ($match['team2'] ?? '')),
    ];

    if ($teams[0] === '' || $teams[1] === '' || ($teams[0] === 'tbd' && $teams[1] === 'tbd')) {
        return '';
    }

    sort($teams, SORT_STRING);

    return implode('|', $teams);
}

function match_date_distance_days(array $a, array $b): int
{
    $dateA = DateTimeImmutable::createFromFormat('Y-m-d', (string) ($a['match_date'] ?? ''));
    $dateB = DateTimeImmutable::createFromFormat('Y-m-d', (string) ($b['match_date'] ?? ''));

    if (!$dateA || !$dateB) {
        return PHP_INT_MAX;
    }

    return abs((int) $dateA->diff($dateB)->format('%r%a'));
}

function finished_pick_group_key_for(array $match, array $groupMeta): string
{
    $exactKey = finished_pick_match_key($match);
    $teamKey = finished_pick_team_key($match);

    if ($teamKey === '') {
        return $exactKey;
    }

    foreach ($groupMeta as $key => $meta) {
        if (($meta['team_key'] ?? '') !== $teamKey) {
            continue;
        }

        if (match_date_distance_days($match, $meta['match']) <= 1) {
            return (string) $key;
        }
    }

    return $exactKey;
}

function compare_finished_pick_matches(array $a, array $b): int
{
    $source = match_source_priority($a) <=> match_source_priority($b);
    if ($source !== 0) {
        return $source;
    }

    $matchNo = (int) ($a['match_no'] ?? 0) <=> (int) ($b['match_no'] ?? 0);
    if ($matchNo !== 0) {
        return $matchNo;
    }

    return (int) ($a['id'] ?? 0) <=> (int) ($b['id'] ?? 0);
}

function build_finished_pick_groups(array $matches): array
{
    $allByKey = [];
    $finishedByKey = [];
    $groupMeta = [];

    foreach ($matches as $match) {
        $key = finished_pick_group_key_for($match, $groupMeta);
        $groupMeta[$key] ??= [
            'team_key' => finished_pick_team_key($match),
            'match' => $match,
        ];
        $allByKey[$key][] = $match;

        if (match_is_locked($match)) {
            $finishedByKey[$key][] = $match;
        }
    }

    $groups = [];

    foreach ($finishedByKey as $key => $finishedMatches) {
        usort($finishedMatches, 'compare_finished_pick_matches');
        $sourceMatches = $allByKey[$key] ?? $finishedMatches;
        usort($sourceMatches, 'compare_finished_pick_matches');

        $groups[] = [
            'match' => $finishedMatches[0],
            'sources' => $sourceMatches,
        ];
    }

    usort($groups, static function (array $a, array $b): int {
        $kickoffA = (string) ($a['match']['kickoff_at_utc'] ?? $a['match']['match_date'] ?? '');
        $kickoffB = (string) ($b['match']['kickoff_at_utc'] ?? $b['match']['match_date'] ?? '');
        $kickoff  = strcmp($kickoffB, $kickoffA);
        if ($kickoff !== 0) {
            return $kickoff;
        }

        return (int) ($b['match']['match_no'] ?? 0) <=> (int) ($a['match']['match_no'] ?? 0);
    });

    return $groups;
}

function finished_pick_prediction_for_player(array $group, int $userId, array $predictionsByMatchAndUser): ?array
{
    $displayMatch = $group['match'];
    $sourceMatches = $group['sources'];

    usort($sourceMatches, static function (array $a, array $b) use ($displayMatch): int {
        $displayId = (int) ($displayMatch['id'] ?? 0);
        $aDisplay = (int) ($a['id'] ?? 0) === $displayId ? 0 : 1;
        $bDisplay = (int) ($b['id'] ?? 0) === $displayId ? 0 : 1;

        if ($aDisplay !== $bDisplay) {
            return $aDisplay <=> $bDisplay;
        }

        $orientationOrder = ['same' => 0, 'reversed' => 1, 'unknown' => 2];
        $orientation = ($orientationOrder[match_orientation($a, $displayMatch)] ?? 2)
            <=> ($orientationOrder[match_orientation($b, $displayMatch)] ?? 2);

        if ($orientation !== 0) {
            return $orientation;
        }

        return compare_finished_pick_matches($a, $b);
    });

    foreach ($sourceMatches as $sourceMatch) {
        $prediction = $predictionsByMatchAndUser[(int) $sourceMatch['id']][$userId] ?? null;

        if (!$prediction) {
            continue;
        }

        $pred1 = (int) $prediction['pred1'];
        $pred2 = (int) $prediction['pred2'];

        if (match_orientation($sourceMatch, $displayMatch) === 'reversed') {
            [$pred1, $pred2] = [$pred2, $pred1];
        }

        return [
            'pred1' => $pred1,
            'pred2' => $pred2,
            'points' => prediction_points($pred1, $pred2, (int) $displayMatch['score1'], (int) $displayMatch['score2']),
        ];
    }

    return null;
}

$players = db()->query("
    SELECT id, username
    FROM users
    WHERE role = 'player' AND is_verified = 1
    ORDER BY username ASC
")->fetchAll();

$matchesQuery = db()->query("
    SELECT *
    FROM wc_matches
    ORDER BY
        COALESCE(kickoff_at_utc, '1970-01-01') DESC,
        match_no DESC,
        id ASC
");
$matches = $matchesQuery->fetchAll();
$finishedGroups = build_finished_pick_groups($matches);

$predictionRows = db()->query("
    SELECT p.*
    FROM predictions p
    INNER JOIN users u ON u.id = p.user_id
    WHERE u.role = 'player' AND u.is_verified = 1
")->fetchAll();
$predictionsByMatchAndUser = [];

foreach ($predictionRows as $prediction) {
    $predictionsByMatchAndUser[(int) $prediction['match_id']][(int) $prediction['user_id']] = $prediction;
}

render_header('Finished Picks');
?>

<section class="page-head">
    <div>
        <h1>Finished Picks</h1>
        <p class="muted">Picks are revealed once a match kicks off. Points shown after the result is imported.</p>
    </div>
</section>

<section class="panel">
    <?php if (!$finishedGroups): ?>
        <p class="empty">No finished match results yet.</p>
    <?php elseif (!$players): ?>
        <p class="empty">No approved players yet.</p>
    <?php else: ?>
        <div class="table-wrap matrix-wrap">
            <table class="picks-matrix">
                <thead>
                <tr>
                    <th class="match-col">Match</th>
                    <th class="result-col">Result</th>
                    <?php foreach ($players as $player): ?>
                        <th class="player-col">
                            <a class="profile-link" href="profile.php?id=<?= (int) $player['id'] ?>">
                                <?= h($player['username']) ?>
                            </a>
                        </th>
                    <?php endforeach; ?>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($finishedGroups as $group): ?>
                    <?php $match = $group['match']; ?>
                    <tr>
                        <td class="match-col">
                            <strong>#<?= (int) $match['match_no'] ?> <?= h(canonical_team_name((string) $match['team1'])) ?> vs <?= h(canonical_team_name((string) $match['team2'])) ?></strong>
                            <span class="table-subline"><?= h($match['match_date']) ?> <?= h($match['match_time']) ?> &middot; <?= h($match['round_name']) ?></span>
                        </td>
                        <td class="result-col">
                            <strong><?= h(format_imported_result($match)) ?></strong>
                            <span class="table-subline"><?= h($match['status']) ?></span>
                        </td>
                        <?php foreach ($players as $player): ?>
                            <?php $prediction = finished_pick_prediction_for_player($group, (int) $player['id'], $predictionsByMatchAndUser); ?>
                            <td class="pick-col" data-player="<?= h($player['username']) ?>">
                                <?php if ($prediction): ?>
                                    <span class="pick-cell">
                                        <strong><?= (int) $prediction['pred1'] ?>:<?= (int) $prediction['pred2'] ?></strong>
                                        <span><?= h(outcome_for((int) $prediction['pred1'], (int) $prediction['pred2'])) ?> &middot; <?= (int) $prediction['points'] ?> pts</span>
                                    </span>
                                <?php else: ?>
                                    <span class="pick-missing">No pick</span>
                                <?php endif; ?>
                            </td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<?php render_footer(); ?>
