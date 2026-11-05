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
    $confirm = (string) ($_POST['password_confirm'] ?? '');

    if (!preg_match('/^[A-Za-z0-9_-]{3,40}$/', $username)) {
        $error = 'Username must be 3-40 characters and use only letters, numbers, underscore or dash.';
    } elseif (strcasecmp($username, 'CSKA') === 0) {
        $error = 'CSKA is reserved for the admin account.';
    } elseif (strlen($password) < 6) {
        $error = 'Use at least 6 characters for the password.';
    } elseif ($password !== $confirm) {
        $error = 'The repeated password does not match.';
    } else {
        try {
            $statement = db()->prepare('
                INSERT INTO users (username, password_hash, role, is_verified)
                VALUES (?, ?, ?, 0)
            ');
            $statement->execute([
                $username,
                password_hash($password, PASSWORD_DEFAULT),
                'player',
            ]);

            flash('Registration sent. CSKA must approve you before you can play.', 'success');
            redirect('login.php');
        } catch (PDOException) {
            $error = 'This username is already taken.';
        }
    }
}

render_header('Register');
?>

<section class="auth-shell">
    <form class="panel auth-form" method="post">
        <?= csrf_input() ?>
        <h1>Register</h1>
        <p class="muted">After registration, CSKA approves the account from the admin panel.</p>

        <?php if ($error): ?>
            <div class="inline-error"><?= h($error) ?></div>
        <?php endif; ?>

        <label>
            Username
            <input type="text" name="username" required value="<?= h($username) ?>" autocomplete="username">
        </label>

        <label>
            Password
            <input type="password" name="password" required minlength="6" autocomplete="new-password">
        </label>

        <label>
            Repeat password
            <input type="password" name="password_confirm" required minlength="6" autocomplete="new-password">
        </label>

        <button class="button" type="submit">Send registration</button>
        <a class="text-link" href="login.php">Already registered?</a>
    </form>
</section>

<?php render_footer(); ?>
