# Database

`connector.php` contains the MySQL connection settings.

The app automatically creates the required tables when it can connect. While the configured MySQL host is unreachable, local development falls back to `db/local.sqlite`, which is ignored by git.

To force MySQL and fail instead of using SQLite:

```sh
WC2026_DB_DRIVER=mysql php cronjobs/import_matches.php
```

To force SQLite for local testing:

```sh
WC2026_DB_DRIVER=sqlite php cronjobs/import_matches.php
```
