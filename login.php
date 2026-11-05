<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/layout.php';

if (current_user()) {
    redirect('index.php');
}

$error = null;
$username = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    $statement = db()->prepare('SELECT * FROM users WHERE username = ?');
    $statement->execute([$username]);
    $user = $statement->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        $error = 'Wrong username or password.';
    } else {
        $_SESSION['user_id'] = (int) $user['id'];
        redirect(user_is_verified($user) ? 'index.php' : 'pending.php');
    }
}

render_header('Login');
?>

<section class="auth-shell">
    <form class="panel auth-form" method="post">
        <?= csrf_input() ?>
        <h1>Login</h1>
        <p class="muted">Approved players can enter predictions and watch the table.</p>

        <?php if (!admin_exists()): ?>
            <div class="inline-note">No admin exists yet. <a href="admin_setup.php">Create CSKA admin</a>.</div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="inline-error"><?= h($error) ?></div>
        <?php endif; ?>

        <label>
            Username
            <input type="text" name="username" required value="<?= h($username) ?>" autocomplete="username">
        </label>

        <label>
            Password
            <input type="password" name="password" required autocomplete="current-password">
        </label>

        <button class="button" type="submit">Login</button>
        <a class="text-link" href="register.php">Create player account</a>
    </form>
</section>

<?php render_footer(); ?>
