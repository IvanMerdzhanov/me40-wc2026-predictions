<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/../db/connector.php';
require_once __DIR__ . '/../db/schema.php';
require_once __DIR__ . '/functions.php';

ensure_schema();
csrf_token();
