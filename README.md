# ME40 — World Cup 2026 Predictions

A PHP web app where friends predict FIFA World Cup 2026 match results and compete on a leaderboard.

## Features

- Registration with admin approval, login and logout
- Match-by-match score predictions
- Group standings and knockout bracket
- Automatic results import (`includes/worldcup_importer.php`)
- Leaderboard, results statistics and player profiles
- Admin panel

**Stack:** PHP 8, MySQL (falls back to SQLite for local development), HTML, CSS, JavaScript

## Run locally

```bash
php -S localhost:8000
```

Database settings are in `db/connector.php`. If MySQL is unreachable, the app uses a local SQLite file. See [`db/README.md`](db/README.md).
