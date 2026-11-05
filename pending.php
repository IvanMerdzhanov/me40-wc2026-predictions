<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/layout.php';

$user = require_login();

if (user_is_verified($user)) {
    redirect('index.php');
}

render_header('Waiting Approval');
?>

<section class="panel narrow">
    <h1>Waiting for approval</h1>
    <p>Your account is registered. CSKA needs to approve it before you can submit predictions.</p>
    <a class="button ghost" href="logout.php">Logout</a>
</section>

<?php render_footer(); ?>
