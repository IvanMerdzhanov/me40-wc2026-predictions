<?php
declare(strict_types=1);

function render_header(string $title): void
{
    $user = current_user();
    $warning = db_warning();
    ?>
    <!doctype html>
    <html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title><?= h($title) ?> | WC 2026 Predictions</title>
        <link rel="stylesheet" href="assets/css/style.css?v=6">
    </head>
    <body>
    <header class="topbar">
        <a class="brand" href="index.php">WC 2026</a>
        <nav class="nav">
            <a href="leaderboard.php">Leaderboard</a>
            <?php if ($user && user_is_verified($user)): ?>
                <a href="matches.php">Matches</a>
                <a href="predictions.php">Finished Picks</a>
                <a href="results.php">Results</a>
            <?php endif; ?>
            <?php if (is_admin($user)): ?>
                <a href="admin.php">Admin</a>
            <?php endif; ?>
        </nav>
        <div class="account">
            <?php if ($user): ?>
                <span><?= h($user['username']) ?></span>
                <a class="button ghost small" href="logout.php">Logout</a>
            <?php else: ?>
                <a class="button ghost small" href="login.php">Login</a>
                <a class="button small" href="register.php">Register</a>
            <?php endif; ?>
        </div>
    </header>

    <?php if ($warning): ?>
        <div class="system-warning"><?= h($warning) ?></div>
    <?php endif; ?>

    <?php foreach (consume_flashes() as $message): ?>
        <div class="flash <?= h($message['type']) ?>"><?= h($message['message']) ?></div>
    <?php endforeach; ?>

    <main class="page">
    <?php
}

function render_footer(): void
{
    ?>
    </main>
    <script>
    (() => {
        function centerLinkedMatch() {
            if (!window.location.hash.startsWith('#match-')) {
                return;
            }

            const id = decodeURIComponent(window.location.hash.slice(1));
            const target = document.getElementById(id);

            if (!target) {
                return;
            }

            window.requestAnimationFrame(() => {
                target.scrollIntoView({block: 'center', inline: 'nearest'});
            });
        }

        window.addEventListener('load', centerLinkedMatch);
        window.addEventListener('hashchange', centerLinkedMatch);
    })();
    </script>
    </body>
    </html>
    <?php
}
