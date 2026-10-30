# Local installation

## Requirements

- PHP 8.3 with PDO MySQL, cURL, and the extensions required by Composer
- MySQL or MariaDB with InnoDB
- Composer 2 and Node.js for dependency installation and JavaScript tests
- Apache with PHP, or another PHP-capable local server

## Install

1. Clone or copy this repository into a local development directory.
2. Create a **new, empty** local database and import [`sql/schema.sql`](sql/schema.sql). Do not import it into an existing database.
3. Copy [`includes/db-local.example.php`](includes/db-local.example.php) to `includes/db-local.php`. Enter the local host, port, database name, username, and password in your editor. Keep the file private; it is ignored by Git.
4. Copy [`includes/sms-local.example.php`](includes/sms-local.example.php) to `includes/sms-local.php` only when local SMS settings are needed. Keep `provider=simulation`, `mode=simulate`, and `live_enabled=false` for development.
5. Run `composer install` from the project root.
6. Start Apache/PHP and open the local project URL shown by your environment.
7. Create the first local administrator using the interactive command below. It asks for a password without echoing it and stores only a password hash.

```sh
php scripts/create-local-admin.php
```

The helper is for local development only. It does not reset an existing administrator and refuses remote databases. Do not create an account by pasting a password or reusable hash into SQL.

## Configuration

Database environment variables, when present, take precedence over the private local file:

| Variable | Purpose |
| --- | --- |
| `BCIHS_DB_HOST` | Local database hostname |
| `BCIHS_DB_PORT` | Optional MySQL port |
| `BCIHS_DB_NAME` | Local database name |
| `BCIHS_DB_USER` | Local database account |
| `BCIHS_DB_PASS` | Local database password |

The examples contain placeholders only. Use a dedicated database account and do not reuse production credentials. The local defaults exist only for compatibility with a new XAMPP installation; set private values before use.

## Safe checks

Run from the repository root:

```sh
php scripts/check-local-db.php
node --check js/sms-credits.js
```

See [HANDOVER.md](HANDOVER.md) for the database-free and isolated test commands. Tests that create database fixtures must use their documented isolated test setup; never point them at a database containing school records.

## Existing database

Do not re-import `sql/schema.sql` over an existing database. Back up the database privately, inspect the applied schema, then select only the missing reviewed migration from `sql/`. Apply migrations manually during a maintenance window, one at a time. No migration is run automatically by page requests.
