<?php
declare(strict_types=1);

$target = 'profile.php';
if ($_SERVER['QUERY_STRING'] ?? '') {
    $target .= '?' . $_SERVER['QUERY_STRING'];
}

header('Location: ' . $target, true, 301);
exit;
