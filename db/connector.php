<?php
declare(strict_types=1);


// Local testing credentials.
 const MYSQL_HOST = '127.0.0.1';
 const MYSQL_PORT = 3306;
 const MYSQL_DATABASE = 'world_cup';
 const MYSQL_USERNAME = 'root';
 const MYSQL_PASSWORD = '';

function db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $driver = getenv('WC2026_DB_DRIVER') ?: 'auto';

    if ($driver === 'sqlite') {
        $pdo = sqlite_connection();
        $GLOBALS['wc2026_db_driver'] = 'sqlite';
        return $pdo;
    }

    try {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            MYSQL_HOST,
            MYSQL_PORT,
            MYSQL_DATABASE
        );

        $pdo = new PDO($dsn, MYSQL_USERNAME, MYSQL_PASSWORD, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $GLOBALS['wc2026_db_driver'] = 'mysql';
        return $pdo;
    } catch (PDOException $exception) {
        if ($driver === 'mysql') {
            throw $exception;
        }

        $GLOBALS['wc2026_db_warning'] = 'MySQL is unavailable, so this local run is using SQLite. Fix the MySQL host before deploying.';
        $pdo = sqlite_connection();
        $GLOBALS['wc2026_db_driver'] = 'sqlite';
        return $pdo;
    }
}

function sqlite_connection(): PDO
{
    $path = __DIR__ . '/local.sqlite';
    $pdo = new PDO('sqlite:' . $path, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA foreign_keys = ON');

    return $pdo;
}

function db_driver(): string
{
    db();
    return $GLOBALS['wc2026_db_driver'] ?? 'mysql';
}

function db_warning(): ?string
{
    return $GLOBALS['wc2026_db_warning'] ?? null;
}
