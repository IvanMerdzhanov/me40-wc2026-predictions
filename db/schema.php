<?php
declare(strict_types=1);

function ensure_schema(): void
{
    static $done = false;

    if ($done) {
        return;
    }

    $pdo = db();

    if (db_driver() === 'sqlite') {
        ensure_sqlite_schema($pdo);
    } else {
        ensure_mysql_schema($pdo);
    }

    $done = true;
}

function ensure_mysql_schema(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS users (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(40) NOT NULL UNIQUE,
            password_hash VARCHAR(255) NOT NULL,
            role VARCHAR(20) NOT NULL DEFAULT 'player',
            is_verified TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            verified_at DATETIME NULL,
            verified_by INT UNSIGNED NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS wc_matches (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            external_id VARCHAR(32) NOT NULL UNIQUE,
            match_no INT UNSIGNED NOT NULL,
            round_name VARCHAR(80) NOT NULL,
            match_date DATE NOT NULL,
            match_time VARCHAR(40) NULL,
            kickoff_at_utc DATETIME NULL,
            team1 VARCHAR(100) NOT NULL,
            team2 VARCHAR(100) NOT NULL,
            group_name VARCHAR(80) NULL,
            ground VARCHAR(160) NULL,
            score1 INT NULL,
            score2 INT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'scheduled',
            source_payload MEDIUMTEXT NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_wc_matches_date (match_date, kickoff_at_utc),
            INDEX idx_wc_matches_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS predictions (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            match_id INT UNSIGNED NOT NULL,
            pred1 INT NOT NULL,
            pred2 INT NOT NULL,
            points INT NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_user_match (user_id, match_id),
            INDEX idx_predictions_user (user_id),
            INDEX idx_predictions_match (match_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

function ensure_sqlite_schema(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT NOT NULL UNIQUE,
            password_hash TEXT NOT NULL,
            role TEXT NOT NULL DEFAULT 'player',
            is_verified INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            verified_at TEXT NULL,
            verified_by INTEGER NULL
        )
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS wc_matches (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            external_id TEXT NOT NULL UNIQUE,
            match_no INTEGER NOT NULL,
            round_name TEXT NOT NULL,
            match_date TEXT NOT NULL,
            match_time TEXT NULL,
            kickoff_at_utc TEXT NULL,
            team1 TEXT NOT NULL,
            team2 TEXT NOT NULL,
            group_name TEXT NULL,
            ground TEXT NULL,
            score1 INTEGER NULL,
            score2 INTEGER NULL,
            status TEXT NOT NULL DEFAULT 'scheduled',
            source_payload TEXT NULL,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS predictions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            match_id INTEGER NOT NULL,
            pred1 INTEGER NOT NULL,
            pred2 INTEGER NOT NULL,
            points INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE (user_id, match_id)
        )
    ");

    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_wc_matches_date ON wc_matches (match_date, kickoff_at_utc)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_wc_matches_status ON wc_matches (status)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_predictions_user ON predictions (user_id)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_predictions_match ON predictions (match_id)');
}
