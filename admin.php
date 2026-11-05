<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/worldcup_importer.php';
require_once __DIR__ . '/includes/gol_scraper.php';
require_once __DIR__ . '/includes/layout.php';

$admin = require_admin();
$importPreview = $_SESSION['worldcup_import_preview'] ?? null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'approve' || $action === 'unapprove') {
        $userId = (int) ($_POST['user_id'] ?? 0);
        $verified = $action === 'approve' ? 1 : 0;
        $statement = db()->prepare('
            UPDATE users
            SET is_verified = ?, verified_at = ?, verified_by = ?
            WHERE id = ? AND role = ?
        ');
        $statement->execute([
            $verified,
            $verified ? now_sql() : null,
            $verified ? (int) $admin['id'] : null,
            $userId,
            'player',
        ]);
        flash($verified ? 'Player approved.' : 'Player approval removed.', 'success');
        redirect('admin.php');
    }

    if ($action === 'preview_import_matches' || $action === 'preview_import_results') {
        $mode = $action === 'preview_import_results' ? 'results' : 'all';

        try {
            $_SESSION['worldcup_import_preview'] = build_worldcup_import_preview($mode);
            $count = count($_SESSION['worldcup_import_preview']['items']);
            flash(
                $count > 0
                    ? sprintf('Preview ready: %d change%s found. Review and confirm below.', $count, $count === 1 ? '' : 's')
                    : 'Preview ready: no changes found.',
                $count > 0 ? 'success' : 'info'
            );
        } catch (Throwable $exception) {
            flash($exception->getMessage(), 'error');
        }
        redirect('admin.php');
    }

    if ($action === 'confirm_import_preview') {
        $preview = $_SESSION['worldcup_import_preview'] ?? null;

        if (!$preview || empty($preview['items'])) {
            flash('There is no import preview to confirm.', 'error');
            redirect('admin.php');
        }

        try {
            $stats = apply_worldcup_import_preview($preview);
            unset($_SESSION['worldcup_import_preview']);
            flash(
                sprintf(
                    'Confirmed import complete: %d new matches, %d updated matches, %d result updates, %d predictions recalculated.',
                    $stats['imported'],
                    $stats['updated'],
                    $stats['result_updates'],
                    $stats['recalculated']
                ),
                'success'
            );
        } catch (Throwable $exception) {
            flash($exception->getMessage(), 'error');
        }
        redirect('admin.php');
    }

    if ($action === 'cancel_import_preview') {
        unset($_SESSION['worldcup_import_preview']);
        flash('Import preview cancelled.', 'info');
        redirect('admin.php');
    }

}

$importPreview = $_SESSION['worldcup_import_preview'] ?? null;

$pending = db()->query("
    SELECT * FROM users
    WHERE role = 'player' AND is_verified = 0
    ORDER BY created_at ASC
")->fetchAll();

$players = db()->query("
    SELECT u.*,
           COALESCE(s.points, 0) AS points,
           COALESCE(s.predictions_count, 0) AS predictions_count
    FROM users u
    LEFT JOIN (
        SELECT user_id, SUM(points) AS points, COUNT(*) AS predictions_count
        FROM predictions
        GROUP BY user_id
    ) s ON s.user_id = u.id
    WHERE u.role = 'player'
    ORDER BY u.is_verified DESC, points DESC, u.username ASC
")->fetchAll();

$matchCount = (int) db()->query('SELECT COUNT(*) FROM wc_matches')->fetchColumn();
$finishedCount = (int) db()->query("SELECT COUNT(*) FROM wc_matches WHERE status = 'finished'")->fetchColumn();

render_header('Admin');
?>

<section class="page-head">
    <div>
        <h1>Admin Panel</h1>
        <p class="muted">Approve players, import matches, and keep the competition table fresh.</p>
    </div>
    <div class="admin-actions">
        <form method="post">
            <?= csrf_input() ?>
            <input type="hidden" name="action" value="preview_import_matches">
            <button class="button" type="submit">Preview import matches</button>
        </form>
        <form method="post">
            <?= csrf_input() ?>
            <input type="hidden" name="action" value="preview_import_results">
            <button class="button ghost" type="submit">Preview newest results</button>
        </form>
    </div>
</section>

<section class="stat-grid">
    <div class="stat">
        <span><?= count($pending) ?></span>
        <label>Pending players</label>
    </div>
    <div class="stat">
        <span><?= $matchCount ?></span>
        <label>Imported matches</label>
    </div>
    <div class="stat">
        <span><?= $finishedCount ?></span>
        <label>Finished matches</label>
    </div>
</section>

<?php if ($importPreview): ?>
    <section class="panel">
        <div class="section-title">
            <div>
                <h2><?= ($importPreview['mode'] ?? '') === 'results' ? 'Newest Results Preview' : 'Matches Import Preview' ?></h2>
                <p class="muted">Review these changes. Nothing is inserted until you confirm.</p>
            </div>
            <div class="admin-actions">
                <form method="post">
                    <?= csrf_input() ?>
                    <input type="hidden" name="action" value="confirm_import_preview">
                    <button class="button" type="submit">Confirm insert</button>
                </form>
                <form method="post">
                    <?= csrf_input() ?>
                    <input type="hidden" name="action" value="cancel_import_preview">
                    <button class="button ghost" type="submit">Cancel</button>
                </form>
            </div>
        </div>

        <?php if (empty($importPreview['items'])): ?>
            <p class="empty">No new changes found.</p>
        <?php else: ?>
            <div class="table-wrap">
                <table>
                    <thead>
                    <tr>
                        <th>Change</th>
                        <th>Match</th>
                        <th>Date</th>
                        <th>Old result</th>
                        <th>New result</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($importPreview['items'] as $item): ?>
                        <?php $record = $item['record']; ?>
                        <tr>
                            <td><span class="status-pill"><?= h($item['change']) ?></span></td>
                            <td>
                                <strong><?= h($record['team1']) ?> vs <?= h($record['team2']) ?></strong>
                                <span class="table-subline">#<?= (int) $record['match_no'] ?> <?= h($record['round_name']) ?></span>
                            </td>
                            <td><?= h($record['match_date']) ?> <?= h($record['match_time']) ?></td>
                            <td><?= h($item['old_score'] ?? '-') ?></td>
                            <td><?= h(score_pair($record['score1'], $record['score2']) ?? '-') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
<?php endif; ?>

<section class="panel">
    <h2>Pending Registrations</h2>
    <?php if (!$pending): ?>
        <p class="empty">No pending registrations.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>Username</th>
                    <th>Registered</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($pending as $player): ?>
                    <tr>
                        <td><?= h($player['username']) ?></td>
                        <td><?= h($player['created_at']) ?></td>
                        <td class="right">
                            <form method="post" class="inline-form">
                                <?= csrf_input() ?>
                                <input type="hidden" name="action" value="approve">
                                <input type="hidden" name="user_id" value="<?= (int) $player['id'] ?>">
                                <button class="button small" type="submit">Approve</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<section class="panel">
    <h2>Players</h2>
    <div class="table-wrap">
        <table>
            <thead>
            <tr>
                <th>Username</th>
                <th>Status</th>
                <th>Predictions</th>
                <th>Points</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($players as $player): ?>
                <tr>
                    <td><?= h($player['username']) ?></td>
                    <td><?= (int) $player['is_verified'] === 1 ? 'Approved' : 'Pending' ?></td>
                    <td><?= (int) $player['predictions_count'] ?></td>
                    <td><strong><?= (int) $player['points'] ?></strong></td>
                    <td class="right">
                        <form method="post" class="inline-form">
                            <?= csrf_input() ?>
                            <input type="hidden" name="action" value="<?= (int) $player['is_verified'] === 1 ? 'unapprove' : 'approve' ?>">
                            <input type="hidden" name="user_id" value="<?= (int) $player['id'] ?>">
                            <button class="button ghost small" type="submit">
                                <?= (int) $player['is_verified'] === 1 ? 'Unapprove' : 'Approve' ?>
                            </button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<?php render_footer(); ?>
