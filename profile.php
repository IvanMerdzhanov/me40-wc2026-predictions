<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/layout.php';

require_verified_user();

$profileId = (int) ($_GET['id'] ?? 0);
$viewer = current_user();
$isOwnProfile = $viewer && (int) $viewer['id'] === $profileId;

$statement = db()->prepare("
    SELECT u.*,
           COALESCE(s.points, 0) AS points,
           COALESCE(s.predictions_count, 0) AS predictions_count,
           COALESCE(s.exact_scores, 0) AS exact_scores,
           COALESCE(s.correct_outcomes, 0) AS correct_outcomes,
           COALESCE(s.correct_picks, 0) AS correct_picks
    FROM users u
    LEFT JOIN (
        SELECT user_id,
               SUM(points) AS points,
               COUNT(*) AS predictions_count,
               SUM(CASE WHEN points = 7 THEN 1 ELSE 0 END) AS exact_scores,
               SUM(CASE WHEN points = 3 THEN 1 ELSE 0 END) AS correct_outcomes,
               SUM(CASE WHEN points IN (7, 3) THEN 1 ELSE 0 END) AS correct_picks
        FROM predictions
        GROUP BY user_id
    ) s ON s.user_id = u.id
    WHERE u.id = ? AND u.role = 'player' AND u.is_verified = 1
");
$statement->execute([$profileId]);
$profile = $statement->fetch();

if (!$profile) {
    http_response_code(404);
    render_header('Player Not Found');
    ?>
    <section class="panel narrow">
        <h1>Player not found</h1>
        <p>This player does not exist or has not been approved.</p>
        <a class="button ghost" href="leaderboard.php">Back to leaderboard</a>
    </section>
    <?php
    render_footer();
    exit;
}

$rows = db()->prepare("
    SELECT p.*,
           m.team1,
           m.team2,
           m.score1,
           m.score2,
           m.status,
           m.match_no,
           m.round_name,
           m.match_date,
           m.match_time,
           m.kickoff_at_utc,
           m.ground
    FROM predictions p
    INNER JOIN wc_matches m ON m.id = p.match_id
    WHERE p.user_id = ?
    ORDER BY COALESCE(m.kickoff_at_utc, '9999-12-31') ASC, m.match_no ASC
");
$rows->execute([(int) $profile['id']]);
$predictions = $rows->fetchAll();

$upcoming = [];
$past = [];

$now = now_sql();
foreach ($predictions as $prediction) {
    $finished = $prediction['status'] === 'finished';
    $kickoff  = $prediction['kickoff_at_utc'] ?? null;
    $isFuture = !$finished && ($kickoff === null || $kickoff >= $now);

    if ($isFuture) {
        $upcoming[] = $prediction;
    } else {
        $past[] = $prediction;
    }
}

$past = array_reverse($past);

function render_profile_prediction_table(array $rows, bool $showPoints): void
{
    if (!$rows) {
        echo '<p class="empty">No predictions here yet.</p>';
        return;
    }
    ?>
    <div class="table-wrap">
        <table>
            <thead>
            <tr>
                <th>Match</th>
                <th>Date</th>
                <th>Pick</th>
                <th>Outcome</th>
                <th>Result</th>
                <?php if ($showPoints): ?>
                    <th>Points</th>
                <?php endif; ?>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $row): ?>
                <tr>
                    <td>
                        <strong><?= h(canonical_team_name((string) $row['team1'])) ?> vs <?= h(canonical_team_name((string) $row['team2'])) ?></strong>
                        <span class="table-subline">#<?= (int) $row['match_no'] ?> <?= h($row['round_name']) ?></span>
                    </td>
                    <td><?= h($row['match_date']) ?> <?= h($row['match_time']) ?></td>
                    <td><strong><?= (int) $row['pred1'] ?>:<?= (int) $row['pred2'] ?></strong></td>
                    <td><?= h(outcome_for((int) $row['pred1'], (int) $row['pred2'])) ?></td>
                    <td>
                        <?= $row['status'] === 'finished' && $row['score1'] !== null && $row['score2'] !== null
                            ? (int) $row['score1'] . ':' . (int) $row['score2']
                            : 'Pending' ?>
                    </td>
                    <?php if ($showPoints): ?>
                        <td><strong><?= (int) $row['points'] ?></strong></td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php
}

render_header($profile['username']);
?>

<section class="page-head">
    <div>
        <h1><?= h($profile['username']) ?></h1>
        <p class="muted">Picks are revealed once a match kicks off.</p>
    </div>
    <a class="button ghost" href="leaderboard.php">Back to leaderboard</a>
</section>

<section class="matches-overview">
    <div class="match-stat">
        <span><?= (int) $profile['points'] ?></span>
        <label>Total points</label>
    </div>
    <div class="match-stat">
        <span><?= (int) $profile['correct_picks'] ?></span>
        <label>Correct picks</label>
    </div>
    <div class="match-stat">
        <span><?= (int) $profile['exact_scores'] ?></span>
        <label>Exact scores</label>
    </div>
    <div class="match-stat">
        <span><?= (int) $profile['correct_outcomes'] ?></span>
        <label>Outcomes</label>
    </div>
</section>

<section class="<?= $isOwnProfile ? 'split-profile' : 'single-profile' ?>">
    <?php if ($isOwnProfile): ?>
    <div class="panel">
        <div class="section-title">
            <h2>Future Picks</h2>
            <span class="count-pill"><?= count($upcoming) ?></span>
        </div>
        <?php render_profile_prediction_table($upcoming, false); ?>
    </div>
    <?php endif; ?>

    <div class="panel">
        <div class="section-title">
            <h2>Revealed Picks</h2>
            <span class="count-pill"><?= count($past) ?></span>
        </div>
        <?php render_profile_prediction_table($past, true); ?>
    </div>
</section>

<?php render_footer(); ?>
