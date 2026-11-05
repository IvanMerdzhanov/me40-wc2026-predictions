<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/layout.php';

$user = require_verified_user();

$showDecreeGate = !is_admin($user);

if ($showDecreeGate && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['decree_accept'])) {
    verify_csrf();
    $_SESSION['index_decree_pass'] = true;
    redirect('index.php');
}

if ($showDecreeGate && !empty($_SESSION['index_decree_pass'])) {
    unset($_SESSION['index_decree_pass']);
    $showDecreeGate = false;
}

if ($showDecreeGate) {
    render_decree_gate();
}

$playerCount = (int) db()->query("SELECT COUNT(*) FROM users WHERE role = 'player' AND is_verified = 1")->fetchColumn();
$matchCount = (int) db()->query('SELECT COUNT(*) FROM wc_matches')->fetchColumn();
$predictionCount = (int) db()->query('SELECT COUNT(*) FROM predictions')->fetchColumn();

$topPlayers = db()->query("
    SELECT u.username, COALESCE(s.points, 0) AS points
    FROM users u
    LEFT JOIN (
        SELECT user_id, SUM(points) AS points
        FROM predictions
        GROUP BY user_id
    ) s ON s.user_id = u.id
    WHERE u.role = 'player' AND u.is_verified = 1
    ORDER BY points DESC, u.username ASC
    LIMIT 5
")->fetchAll();

$upcoming = db()->prepare("
    SELECT *
    FROM wc_matches
    WHERE status = 'scheduled'
      AND (
          (kickoff_at_utc IS NOT NULL AND kickoff_at_utc > ?)
          OR (kickoff_at_utc IS NULL AND match_date >= ?)
      )
    ORDER BY
      CASE WHEN kickoff_at_utc IS NULL THEN 1 ELSE 0 END ASC,
      kickoff_at_utc ASC,
      match_date ASC,
      match_no ASC
    LIMIT 4
");
$upcoming->execute([now_sql(), gmdate('Y-m-d')]);
$upcomingMatches = $upcoming->fetchAll();

$recentResults = db()->query("
    SELECT *
    FROM wc_matches
    WHERE status = 'finished'
    ORDER BY match_no DESC
    LIMIT 4
")->fetchAll();

$commonResults = common_match_results(5);

render_header('Home');
?>

<section class="dashboard-grid">
    <div class="panel intro-panel">
        <img src="resources/team.jpeg" alt="People competing" class="team-photo">
        <div>
            <h1>World Cup Predictions</h1>
            <p class="muted">Predict every 2026 match score. Exact normal-time score gives 7 points. Correct 1/X/2 outcome gives 3 points.</p>
            <div class="action-row">
                <?php if (!$user): ?>
                    <a class="button" href="login.php">Login</a>
                    <a class="button ghost" href="register.php">Register</a>
                <?php elseif (!user_is_verified($user)): ?>
                    <a class="button" href="pending.php">Approval status</a>
                <?php else: ?>
                    <a class="button" href="matches.php#tab-list">Enter predictions</a>
                    <a class="button ghost" href="leaderboard.php">View leaderboard</a>
                <?php endif; ?>
                <?php if (!admin_exists()): ?>
                    <a class="button ghost" href="admin_setup.php">Create CSKA admin</a>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <aside class="panel prize-panel">
        <img src="resources/grey_goose_cutout.png" alt="Grey Goose 1L prize">
        <div>
            <h2>Winner Prize</h2>
            <p>Winner takes 1L Grey Goose.</p>
            <a class="text-link" href="https://avanti-bg.com/produkt/%D0%B2%D0%BE%D0%B4%D0%BA%D0%B0-grey-goose-1%D0%BB/" target="_blank" rel="noopener">Open prize link</a>
        </div>
    </aside>
</section>

<section class="stat-grid">
    <div class="stat">
        <span><?= $playerCount ?></span>
        <label>Approved players</label>
    </div>
    <div class="stat">
        <span><?= $matchCount ?></span>
        <label>Matches loaded</label>
    </div>
    <div class="stat">
        <span><?= $predictionCount ?></span>
        <label>Predictions</label>
    </div>
</section>

<section class="split-grid">
    <div class="panel">
        <div class="section-title">
            <h2>Top Players</h2>
            <a class="text-link" href="leaderboard.php">Full table</a>
        </div>
        <?php if (!$topPlayers): ?>
            <p class="empty">No points yet.</p>
        <?php else: ?>
            <ol class="leader-mini">
                <?php foreach ($topPlayers as $player): ?>
                    <li>
                        <span><?= h($player['username']) ?></span>
                        <strong><?= (int) $player['points'] ?> pts</strong>
                    </li>
                <?php endforeach; ?>
            </ol>
        <?php endif; ?>
    </div>

    <div class="panel">
        <div class="section-title">
            <h2>Upcoming</h2>
            <a class="text-link" href="matches.php">All matches</a>
        </div>
        <?php if (!$upcomingMatches): ?>
            <p class="empty">No upcoming matches loaded.</p>
        <?php else: ?>
            <div class="compact-list">
                <?php foreach ($upcomingMatches as $match): ?>
                    <a href="matches.php#match-<?= (int) $match['id'] ?>">
                        <span><?= h($match['team1']) ?> vs <?= h($match['team2']) ?></span>
                        <?php if (!empty($match['kickoff_at_utc'])): ?>
                            <small class="match-kickoff-time"
                                   data-kickoff-utc="<?= h($match['kickoff_at_utc']) ?>">
                                <?= h($match['match_date']) ?> <?= h($match['match_time']) ?>
                            </small>
                        <?php else: ?>
                            <small><?= h($match['match_date']) ?> <?= h($match['match_time']) ?></small>
                        <?php endif; ?>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</section>

<?php if ($recentResults): ?>
    <section class="panel">
        <div class="section-title">
            <h2>Recent Results</h2>
            <a class="text-link" href="predictions.php">Finished picks</a>
        </div>
        <div class="result-strip">
            <?php foreach ($recentResults as $match): ?>
                <a href="predictions.php?match_id=<?= (int) $match['id'] ?>">
                    <span><?= h($match['team1']) ?></span>
                    <strong><?= format_match_score($match) ?></strong>
                    <span><?= h($match['team2']) ?></span>
                </a>
            <?php endforeach; ?>
        </div>
    </section>
<?php endif; ?>

<?php if ($commonResults): ?>
    <section class="panel common-results-panel">
        <div class="section-title">
            <div>
                <h2>Top 5 Confirmed Match Results</h2>
                <p class="muted">Confirmed official results, not predictions. Reversed scores are counted together.</p>
            </div>
            <a class="text-link" href="results.php">All results</a>
        </div>
        <div class="common-results">
            <?php foreach ($commonResults as $result => $count): ?>
                <div class="common-result">
                    <strong><?= h($result) ?></strong>
                    <span><?= (int) $count ?> <?= (int) $count === 1 ? 'time' : 'times' ?></span>
                </div>
            <?php endforeach; ?>
        </div>
    </section>
<?php endif; ?>

<script>
document.querySelectorAll('.match-kickoff-time[data-kickoff-utc]').forEach(function (el) {
    var utcStr = el.dataset.kickoffUtc;
    if (!utcStr) return;
    var dt = new Date(utcStr.replace(' ', 'T') + 'Z');
    if (isNaN(dt.getTime())) return;
    var datePart = dt.toLocaleDateString([], {month: 'short', day: 'numeric'});
    var timePart = dt.toLocaleTimeString([], {hour: '2-digit', minute: '2-digit'});
    var tzPart = dt.toLocaleTimeString([], {timeZoneName: 'short'}).split(' ').pop();
    el.textContent = datePart + ' ' + timePart + ' ' + tzPart;
});
</script>

<?php render_footer(); ?>

<?php
function render_decree_gate(): never
{
    ?>
    <!doctype html>
    <html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>House Rules | WC 2026 Predictions</title>
        <link rel="stylesheet" href="assets/css/style.css?v=6">
    </head>
    <body>
        <main class="decree-only-page">
            <div class="decree-overlay" role="dialog" aria-modal="true" aria-label="House rules" aria-describedby="decree-copy">
                <form class="decree-card" method="post">
                    <?= csrf_input() ?>
                    <input type="hidden" name="decree_accept" value="1">
                    <div class="decree-portrait">
                        <img src="resources/dictator.png" alt="The house rules wizard">
                        <span>Official Mood Inspector</span>
                    </div>
                    <div class="decree-copy" id="decree-copy">
                        <span class="decree-badge">Mandatory refresh ritual</span>
                        <p>Before entering the sacred scoreboard, you must agree to these completely normal tournament laws.</p>
                        <ol class="decree-rules">
                            <li><strong>Speak of the admin:</strong> -10 points.</li>
                            <li><strong>Criticize the platform:</strong> two-match ban.</li>
                            <li><strong>Root for the admin not to land an exact score:</strong> your next 7-pointer becomes 0.</li>
                            <li><strong>Forget to enter your score and ask the admin to add it manually:</strong> the match is not included, plus -3 points per match.</li>
                            <li><strong>Pressure the admin to pull last night's results:</strong> -2 points.</li>
                            <li><strong>Reach first place and immediately suggest a new prize:</strong> -10 points and a two-match ban.</li>
                            <li><strong>Play smart on Facebook:</strong> 1-8 points deducted, depending on tone.</li>
                            <li><strong>Score against the admin in FIFA:</strong> -2 points per goal. That would never happen anyway, hahaha. Losers.</li>
                        </ol>
                        <button class="button decree-confirm" type="submit">I agree to the rules</button>
                        <p class="decree-warning">No agreement, no entry. The dashboard is not even sent until you accept.</p>
                    </div>
                </form>
            </div>
        </main>
    </body>
    </html>
    <?php
    exit;
}
