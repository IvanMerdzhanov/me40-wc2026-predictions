<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/layout.php';

if (admin_exists()) {
    redirect('login.php');
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $password = (string) ($_POST['password'] ?? '');
    $confirm = (string) ($_POST['password_confirm'] ?? '');

    if (strlen($password) < 6) {
        $error = 'Use at least 6 characters for the admin password.';
    } elseif ($password !== $confirm) {
        $error = 'The repeated password does not match.';
    } else {
        $statement = db()->prepare('
            INSERT INTO users (username, password_hash, role, is_verified, verified_at)
            VALUES (?, ?, ?, 1, ?)
        ');
        $statement->execute([
            'CSKA',
            password_hash($password, PASSWORD_DEFAULT),
            'admin',
            now_sql(),
        ]);

        flash('CSKA admin account created. You can log in now.', 'success');
        redirect('login.php');
    }
}

render_header('Create Admin');
?>

<section class="auth-shell">
    <form class="panel auth-form" method="post">
        <?= csrf_input() ?>
        <h1>Create CSKA Admin</h1>
        <p class="muted">This one-time setup creates the admin account that approves all players.</p>

        <?php if ($error): ?>
            <div class="inline-error"><?= h($error) ?></div>
        <?php endif; ?>

        <label>
            Username
            <input type="text" value="CSKA" disabled>
        </label>

        <label>
            Password
            <input type="password" name="password" required minlength="6" autocomplete="new-password">
        </label>

        <label>
            Repeat password
            <input type="password" name="password_confirm" required minlength="6" autocomplete="new-password">
        </label>

        <button class="button" type="submit">Create admin</button>
    </form>
</section>

<?php render_footer(); ?>
