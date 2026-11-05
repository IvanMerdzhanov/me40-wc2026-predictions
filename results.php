<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/layout.php';

require_verified_user();

$commonResults = common_match_results();
$totalFinishedResults = array_sum($commonResults);
$uniqueResultCount = count($commonResults);
$topResult = array_key_first($commonResults);
$topResultCount = $topResult === null ? 0 : (int) $commonResults[$topResult];
$maxResultCount = max($commonResults ?: [1]);
$drawCount = 0;
$totalGoals = 0;

foreach ($commonResults as $result => $count) {
    [$highScore, $lowScore] = array_map('intval', explode(':', (string) $result));

    if ($highScore === $lowScore) {
        $drawCount += (int) $count;
    }

    $totalGoals += ($highScore + $lowScore) * (int) $count;
}

$averageGoals = $totalFinishedResults > 0 ? number_format($totalGoals / $totalFinishedResults, 2) : '0.00';

render_header('Results');
?>

<section class="page-head">
    <div>
        <h1>Match Result Stats</h1>
        <p class="muted">Confirmed official match results, not user predictions. Reversed scores are counted together, so 2:1 includes both 2-1 and 1-2.</p>
    </div>
    <a class="button ghost" href="index.php">Back home</a>
</section>

<?php if (!$commonResults): ?>
    <section class="panel">
        <p class="empty">No confirmed match results yet.</p>
    </section>
<?php else: ?>
    <section class="results-summary">
        <div class="result-summary-card">
            <span>Confirmed Matches</span>
            <strong><?= (int) $totalFinishedResults ?></strong>
        </div>
        <div class="result-summary-card">
            <span>Different Results</span>
            <strong><?= (int) $uniqueResultCount ?></strong>
        </div>
        <div class="result-summary-card result-summary-card-featured">
            <span>Most Frequent</span>
            <strong><?= h($topResult) ?></strong>
            <small><?= (int) $topResultCount ?> <?= (int) $topResultCount === 1 ? 'time' : 'times' ?></small>
        </div>
        <div class="result-summary-card">
            <span>Draws</span>
            <strong><?= (int) $drawCount ?></strong>
        </div>
        <div class="result-summary-card">
            <span>Average Goals</span>
            <strong><?= h($averageGoals) ?></strong>
        </div>
    </section>

    <section class="panel results-board">
        <div class="section-title">
            <div>
                <h2>All Confirmed Results</h2>
                <p class="muted"><?= (int) $totalFinishedResults ?> confirmed matches counted.</p>
            </div>
        </div>

        <ol class="results-ranking">
            <?php $rank = 1; ?>
            <?php foreach ($commonResults as $result => $count): ?>
                <?php
                $percentage = $totalFinishedResults > 0 ? ((int) $count / $totalFinishedResults) * 100 : 0;
                $barWidth = $maxResultCount > 0 ? ((int) $count / $maxResultCount) * 100 : 0;
                ?>
                <li class="result-rank-row">
                    <span class="result-rank-number"><?= $rank ?></span>
                    <strong class="result-rank-score"><?= h($result) ?></strong>
                    <div class="result-rank-meter" aria-label="<?= h($result) ?> frequency">
                        <span style="width: <?= h(number_format($barWidth, 2, '.', '')) ?>%"></span>
                    </div>
                    <span class="result-rank-count"><?= (int) $count ?> <?= (int) $count === 1 ? 'time' : 'times' ?></span>
                    <span class="result-rank-share"><?= h(number_format($percentage, 1)) ?>%</span>
                </li>
                <?php $rank++; ?>
            <?php endforeach; ?>
        </ol>
    </section>
<?php endif; ?>

<?php render_footer(); ?>
