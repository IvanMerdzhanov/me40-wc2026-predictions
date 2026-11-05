<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/layout.php';

require_verified_user();

$leaders = db()->query("
    SELECT u.id, u.username,
           COALESCE(s.points, 0)             AS points,
           COALESCE(s.predictions_count, 0)  AS predictions_count,
           COALESCE(s.exact_scores, 0)        AS exact_scores,
           COALESCE(s.correct_outcomes, 0)    AS correct_outcomes,
           COALESCE(s.correct_picks, 0)       AS correct_picks
    FROM users u
    LEFT JOIN (
        SELECT user_id,
               SUM(points)                                        AS points,
               COUNT(*)                                           AS predictions_count,
               SUM(CASE WHEN points = 7 THEN 1 ELSE 0 END)       AS exact_scores,
               SUM(CASE WHEN points = 3 THEN 1 ELSE 0 END)       AS correct_outcomes,
               SUM(CASE WHEN points IN (7, 3) THEN 1 ELSE 0 END) AS correct_picks
        FROM predictions
        GROUP BY user_id
    ) s ON s.user_id = u.id
    WHERE u.role = 'player' AND u.is_verified = 1
    ORDER BY points DESC, exact_scores DESC, correct_outcomes DESC, u.username ASC
")->fetchAll();

$playerIds = array_column($leaders, 'id');
$maxPoints = (int) ($leaders[0]['points'] ?? 1) ?: 1;

// ── Race data ─────────────────────────────────────────────────────────────────
$raceFrames = [];
if ($leaders) {
    $predRows = db()->query("
        SELECT p.user_id, p.points, m.id AS match_id, m.kickoff_at_utc, m.team1, m.team2
        FROM predictions p
        INNER JOIN wc_matches m ON m.id = p.match_id
        INNER JOIN users u      ON u.id = p.user_id
        WHERE u.role = 'player' AND u.is_verified = 1
          AND m.kickoff_at_utc IS NOT NULL
          AND m.status = 'finished'
          AND m.score1 IS NOT NULL AND m.score2 IS NOT NULL
        ORDER BY m.kickoff_at_utc ASC, m.id ASC
    ")->fetchAll();

    $byMatch = [];
    foreach ($predRows as $r) {
        $k = (int) $r['match_id'];
        $byMatch[$k]['label']   ??= canonical_team_name((string) $r['team1']) . ' v ' . canonical_team_name((string) $r['team2']);
        $byMatch[$k]['kickoff'] ??= $r['kickoff_at_utc'];
        $byMatch[$k]['pts'][$r['user_id']] = (int) $r['points'];
    }

    $cum = array_fill_keys($playerIds, 0);
    foreach ($byMatch as $data) {
        foreach ($playerIds as $pid) {
            $cum[$pid] += $data['pts'][$pid] ?? 0;
        }
        $raceFrames[] = [
            'label'   => $data['label'],
            'kickoff' => $data['kickoff'],
            'scores'  => array_values(array_map(fn ($pid) => $cum[$pid], $playerIds)),
        ];
    }
}

$playerNames = array_column($leaders, 'username');

render_header('Leaderboard');
?>

<style>
/* ── Page layout ─────────────────────────────────────────────── */
.lb-page { display: flex; flex-direction: column; gap: 28px; }

/* ── Current standings bars ──────────────────────────────────── */
.lb-bars { display: flex; flex-direction: column; gap: 14px; }

.lb-bar-row {
    display: grid;
    grid-template-columns: 28px 120px 1fr;
    align-items: center;
    gap: 12px;
}

.lb-rank {
    font-size: 13px;
    font-weight: 700;
    color: var(--muted);
    text-align: right;
}
.lb-rank.gold { color: var(--gold); font-size: 16px; }

.lb-player-name {
    font-weight: 600;
    font-size: 14px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.lb-track {
    position: relative;
    height: 36px;
    background: var(--surface-strong);
    border-radius: 6px;
    overflow: hidden;
    border: 1px solid var(--line);
}

.lb-fill {
    position: absolute;
    inset: 0 auto 0 0;
    width: 0;
    border-radius: 5px;
    display: flex;
    align-items: center;
    padding-right: 10px;
    justify-content: flex-end;
    animation: lb-grow var(--dur, 0.9s) cubic-bezier(.25,.8,.25,1) forwards;
    animation-delay: var(--delay, 0s);
}

@keyframes lb-grow { to { width: var(--pct); } }

.lb-fill-pts {
    font-size: 12px;
    font-weight: 700;
    color: #fff;
    white-space: nowrap;
    text-shadow: 0 1px 2px rgba(0,0,0,0.3);
    opacity: 0;
    animation: lb-pts-fade 0.2s ease forwards;
    animation-delay: calc(var(--delay, 0s) + var(--dur, 0.9s) - 0.15s);
}
@keyframes lb-pts-fade { to { opacity: 1; } }

/* ── Classic table ───────────────────────────────────────────── */
.table-wrap { overflow-x: auto; }
table { width: 100%; border-collapse: collapse; font-size: 14px; }
thead th {
    padding: 10px 12px;
    text-align: left;
    font-size: 11px;
    font-weight: 600;
    letter-spacing: .06em;
    text-transform: uppercase;
    color: var(--muted);
    border-bottom: 2px solid var(--line);
}
tbody td { padding: 10px 12px; border-bottom: 1px solid var(--line); }
tbody tr:last-child td { border-bottom: none; }
tbody tr:hover td { background: var(--surface-strong); }

/* ── Race player ─────────────────────────────────────────────── */
.race-wrap { display: flex; flex-direction: column; gap: 20px; }

.race-header {
    display: flex;
    flex-direction: column;
    gap: 6px;
}
.race-match-label {
    font-size: 16px;
    font-weight: 700;
    min-height: 1.4em;
}
.race-counter {
    font-size: 12px;
    color: var(--muted);
}

.race-stage {
    position: relative;
    overflow: hidden;
}

.race-row {
    position: absolute;
    left: 0; right: 0;
    height: 60px;
    display: grid;
    grid-template-columns: 100px 1fr 72px;
    align-items: center;
    gap: 10px;
    transition: transform 0.65s cubic-bezier(.4,0,.2,1);
}

.race-player-name {
    font-weight: 600;
    font-size: 14px;
    text-align: right;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.race-bar-track {
    height: 38px;
    background: var(--surface-strong);
    border-radius: 6px;
    overflow: hidden;
    border: 1px solid var(--line);
    position: relative;
}

.race-bar-fill {
    position: absolute;
    inset: 0 auto 0 0;
    width: 0;
    border-radius: 5px;
    min-width: 6px;
    transition: width 0.65s cubic-bezier(.4,0,.2,1);
}
.race-bar-fill.no-anim { transition: none; }

.race-score {
    font-size: 13px;
    font-weight: 700;
    text-align: right;
    white-space: nowrap;
}

/* ── Race controls ───────────────────────────────────────────── */
.race-controls {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
}

.race-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 38px; height: 38px;
    border-radius: 50%;
    border: 1.5px solid var(--line);
    background: var(--surface);
    cursor: pointer;
    font-size: 16px;
    color: var(--text);
    transition: background .15s, border-color .15s;
    flex-shrink: 0;
    padding: 0;
}
.race-btn:hover { background: var(--surface-strong); border-color: var(--pitch); }
.race-btn.primary {
    width: 44px; height: 44px;
    background: var(--pitch);
    border-color: var(--pitch);
    color: #fff;
    font-size: 18px;
}
.race-btn.primary:hover { background: var(--pitch-dark); border-color: var(--pitch-dark); }

.race-scrubber {
    flex: 1;
    min-width: 120px;
    height: 4px;
    accent-color: var(--pitch);
    cursor: pointer;
}

.race-speed-label { font-size: 12px; color: var(--muted); white-space: nowrap; }
.race-speed {
    font-size: 12px;
    padding: 4px 8px;
    border-radius: 6px;
    border: 1px solid var(--line);
    background: var(--surface);
    cursor: pointer;
    color: var(--text);
}

/* ── Section title ───────────────────────────────────────────── */
.section-title {
    display: flex;
    align-items: baseline;
    justify-content: space-between;
    margin-bottom: 18px;
}
.section-title h2 { margin: 0; font-size: 17px; }

/* ── Responsive ──────────────────────────────────────────────── */
@media (max-width: 600px) {
    .lb-bar-row { grid-template-columns: 22px 90px 1fr; gap: 8px; }
    .lb-player-name { font-size: 13px; }
    .race-row { grid-template-columns: 72px 1fr 60px; }
    .race-player-name { font-size: 12px; }
    .race-score { font-size: 12px; }
}
</style>

<section class="page-head">
    <div>
        <h1>Leaderboard</h1>
        <p class="muted">7 pts for exact score &middot; 3 pts for correct outcome</p>
    </div>
    <a class="button ghost" href="https://avanti-bg.com/produkt/%D0%B2%D0%BE%D0%B4%D0%BA%D0%B0-grey-goose-1%D0%BB/" target="_blank" rel="noopener">🍾 Prize</a>
</section>

<?php if (!$leaders): ?>
    <section class="panel narrow">
        <p class="empty">No approved players yet.</p>
    </section>
<?php else: ?>

<div class="lb-page">

    <!-- ── Current standings ──────────────────────────────────── -->
    <section class="panel">
        <div class="section-title">
            <h2>Current Standings</h2>
            <span style="font-size:13px;color:var(--muted)"><?= count($leaders) ?> players</span>
        </div>

        <?php
        $palette = ['#e8303a', '#f47920', '#f5c518', '#22a854', '#1a73e8', '#4040cc', '#9c27b0'];
        ?>

        <div class="lb-bars">
            <?php foreach ($leaders as $i => $leader): ?>
                <?php
                $pct   = round((int) $leader['points'] / $maxPoints * 100, 1);
                $color = $palette[$i % count($palette)];
                $delay = round($i * 0.08, 2);
                $isTop = $i === 0;
                ?>
                <div class="lb-bar-row">
                    <div class="lb-rank <?= $isTop ? 'gold' : '' ?>">
                        <?= $isTop ? '🏆' : $i + 1 ?>
                    </div>
                    <div class="lb-player-name">
                        <a class="profile-link" href="profile.php?id=<?= (int) $leader['id'] ?>">
                            <?= h($leader['username']) ?>
                        </a>
                    </div>
                    <div class="lb-track">
                        <div class="lb-fill"
                             style="--pct:<?= $pct ?>%;--delay:<?= $delay ?>s;background:<?= $color ?><?= $isTop ? ';box-shadow:0 0 14px '.($color).'55' : '' ?>">
                            <span class="lb-fill-pts" style="--delay:<?= $delay ?>s;--dur:0.9s">
                                <?= (int) $leader['points'] ?> pts
                            </span>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </section>

    <!-- ── Classic table ──────────────────────────────────────── -->
    <section class="panel">
        <div class="section-title"><h2>Stats</h2></div>
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>#</th>
                    <th>Player</th>
                    <th>Points</th>
                    <th>Exact&nbsp;scores</th>
                    <th>Outcomes</th>
                    <th>Correct&nbsp;matches</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($leaders as $i => $leader): ?>
                    <tr>
                        <td style="color:var(--muted);font-weight:600"><?= $i + 1 ?></td>
                        <td>
                            <a class="profile-link" href="profile.php?id=<?= (int) $leader['id'] ?>">
                                <strong><?= h($leader['username']) ?></strong>
                            </a>
                        </td>
                        <td><strong><?= (int) $leader['points'] ?></strong></td>
                        <td><?= (int) $leader['exact_scores'] ?></td>
                        <td><?= (int) $leader['correct_outcomes'] ?></td>
                        <td><?= (int) $leader['correct_picks'] ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>

    <!-- ── Race animation ─────────────────────────────────────── -->
    <?php if (count($raceFrames) > 0): ?>
    <section class="panel">
        <div class="section-title">
            <h2>Match-by-Match Race</h2>
            <span style="font-size:13px;color:var(--muted)"><?= count($raceFrames) ?> matches</span>
        </div>

        <div class="race-wrap">
            <div class="race-header">
                <div id="race-match" class="race-match-label">—</div>
                <div id="race-counter" class="race-counter">Match 0 / <?= count($raceFrames) ?></div>
            </div>

            <!-- race bars -->
            <div class="race-stage" id="race-stage"
                 style="height:<?= count($leaders) * 68 ?>px">
                <?php foreach ($leaders as $i => $leader): ?>
                    <?php $color = $palette[$i % count($palette)]; ?>
                    <div class="race-row" id="rr-<?= $i ?>" style="top:0">
                        <div class="race-player-name"><?= h($leader['username']) ?></div>
                        <div class="race-bar-track">
                            <div class="race-bar-fill" id="rr-fill-<?= $i ?>"
                                 style="background:<?= $color ?>"></div>
                        </div>
                        <div class="race-score" id="rr-score-<?= $i ?>">0 pts</div>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- controls -->
            <div class="race-controls">
                <button class="race-btn" id="btn-prev" title="Previous match">&#8592;</button>
                <button class="race-btn primary" id="btn-play" title="Play / Pause">&#9654;</button>
                <button class="race-btn" id="btn-next" title="Next match">&#8594;</button>
                <input type="range" class="race-scrubber" id="race-scrubber"
                       min="0" max="<?= count($raceFrames) - 1 ?>" value="0">
                <span class="race-speed-label">Speed</span>
                <select class="race-speed" id="race-speed">
                    <option value="1800">0.5×</option>
                    <option value="1100" selected>1×</option>
                    <option value="600">2×</option>
                    <option value="250">4×</option>
                </select>
            </div>
        </div>
    </section>
    <?php endif; ?>

</div>

<?php endif; ?>

<script>
(function () {
    const frames  = <?= json_encode($raceFrames, JSON_UNESCAPED_UNICODE) ?>;
    const nPlayers = <?= count($leaders) ?>;
    const ROW_H    = 68; // must match CSS height + gap

    if (!frames.length || !nPlayers) return;

    let cur       = 0;
    let playing   = false;
    let timer     = null;
    let speed     = 1100;

    const elMatch   = document.getElementById('race-match');
    const elCounter = document.getElementById('race-counter');
    const elScrub   = document.getElementById('race-scrubber');
    const elPlay    = document.getElementById('btn-play');
    const elPrev    = document.getElementById('btn-prev');
    const elNext    = document.getElementById('btn-next');
    const elSpeed   = document.getElementById('race-speed');

    function rows(i)  { return document.getElementById('rr-' + i); }
    function fills(i) { return document.getElementById('rr-fill-' + i); }
    function scores(i){ return document.getElementById('rr-score-' + i); }

    function renderFrame(idx, animate) {
        const frame = frames[idx];

        // build sorted ranking
        const ranked = Array.from({length: nPlayers}, (_, i) => ({
            i,
            score: frame.scores[i] ?? 0
        })).sort((a, b) => b.score - a.score || a.i - b.i);

        const maxScore = ranked[0].score || 1;

        ranked.forEach((item, rank) => {
            const row  = rows(item.i);
            const fill = fills(item.i);
            const sc   = scores(item.i);

            if (!animate) {
                row.style.transition  = 'none';
                fill.style.transition = 'none';
            }

            row.style.transform = 'translateY(' + (rank * ROW_H) + 'px)';
            fill.style.width    = Math.max((item.score / maxScore) * 100, item.score > 0 ? 1 : 0) + '%';
            sc.textContent      = item.score + ' pts';

            if (!animate) {
                // force reflow then re-enable transitions
                row.offsetHeight;
                row.style.transition  = '';
                fill.style.transition = '';
            }
        });

        elMatch.textContent   = frame.label;
        elCounter.textContent = 'Match ' + (idx + 1) + ' / ' + frames.length;
        elScrub.value         = idx;
        cur = idx;

        elPrev.disabled = cur <= 0;
        elNext.disabled = cur >= frames.length - 1;
    }

    function step() {
        if (cur < frames.length - 1) {
            renderFrame(cur + 1, true);
        } else {
            pause();
        }
    }

    function play() {
        playing = true;
        elPlay.innerHTML = '&#9646;&#9646;';
        timer = setInterval(step, speed);
    }

    function pause() {
        playing = false;
        elPlay.innerHTML = '&#9654;';
        clearInterval(timer);
    }

    elPlay.addEventListener('click', () => playing ? pause() : play());

    elPrev.addEventListener('click', () => {
        pause();
        if (cur > 0) renderFrame(cur - 1, true);
    });

    elNext.addEventListener('click', () => {
        pause();
        if (cur < frames.length - 1) renderFrame(cur + 1, true);
    });

    elScrub.addEventListener('input', () => {
        pause();
        renderFrame(+elScrub.value, true);
    });

    elSpeed.addEventListener('change', () => {
        speed = +elSpeed.value;
        if (playing) { clearInterval(timer); timer = setInterval(step, speed); }
    });

    // Initial render (frame 0, no animation so bars snap into position)
    renderFrame(0, false);
})();
</script>

<?php render_footer(); ?>
