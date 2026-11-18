# Local installation and operation

These instructions cover the revised BCIHS LMS. They do not reset an existing database, supply demo credentials, activate paid SMS, or establish that a specific Windows machine has passed acceptance testing.

## 1. Obtain the correct source

Use the latest reviewed `main` from the authorized repository, or the explicitly reviewed revision branch if it has not been integrated. With Git:

```sh
git clone https://github.com/devbynielll/bcihs-library-management-system.git bcihs-library
```

The GitHub repository is currently public; browsing or downloading its source does not require collaborator access. Private runtime configuration and school data must still remain outside Git. Alternatively use **Code → Download ZIP**, extract it and rename the application folder to `bcihs-library`. Do not nest an extra project folder inside it. Preserve existing local files before updating; never copy private configuration or uploads from another installation.

For an existing clone, inspect `git status`, preserve local work and fetch/review the intended branch before pulling. A push to GitHub does not automatically update another person's localhost. This working source and a clean GitHub export may have different schema filenames; inspect `sql/` before importing.

## 2. Prepare MAMP or XAMPP

Required:

- PHP **8.3, 64-bit**, with PDO MySQL and sessions; cURL for the provider adapters.
- Extensions required by the locked PhpSpreadsheet dependency: ctype, DOM, fileinfo, filter, GD, iconv, libxml, mbstring, SimpleXML, XML, XMLReader, XMLWriter, ZIP and zlib.
- Local MySQL/InnoDB; MySQL 8 is the verified concurrency-test environment. Other server versions, including MariaDB, need schema/workflow verification on the actual installation.
- Composer 2. Node.js is optional for application use and required for JavaScript tests.

**macOS/MAMP:** select PHP 8.3 in MAMP, check the configured document root and place the project there. Start Apache and MySQL. Common ports are Apache 8888 and MySQL 8889; confirm the values in MAMP.

**Windows/XAMPP:** place the project under XAMPP's configured `htdocs`, for example `C:\xampp\htdocs\bcihs-library`. Start Apache and MySQL. Typical ports are 80 and 3306, but use the actual values. Confirm the bundled PHP version. If it is older than 8.3, have the developer configure a compatible local PHP stack before continuing; do not bypass Composer's platform requirements or replace PHP binaries blindly.

Open a terminal in the project directory. Ensure `php` resolves to the same compatible runtime as the web server:

```sh
php -v
php --ini
php -m
composer --version
```

If PHP is not on PATH, use the full executable path from your own installation; in PowerShell use `&` before a quoted executable path. CLI and Apache can load different `php.ini` files. Enable required extensions in the appropriate configuration and restart the affected service.

## 3. Create a new empty database

Use phpMyAdmin through MAMP/XAMPP. Create **a new, empty local database named `bcihs_library`**, with `utf8mb4` / `utf8mb4_unicode_ci`. This name is required by the existing local administrator/check helpers. Do not use a populated school database for a fresh installation.

**Never import `sql/schema.sql` over an existing populated `bcihs_library` database.** Use the existing-database upgrade procedure instead.

Import **`sql/schema.sql`** once into the selected new, empty database. This consolidated schema contains all 12 tables, `users.phone_normalized` and unique index `uq_users_phone_normalized`, SMS permission/audit tables, and `sms_notification_audit.actor_role`. SMS permission starts OFF. It contains no user accounts, catalog records, passwords or provider credentials.

**No separate migration is needed for a fresh import of this schema.** Do not also import `sql/sms-notification-settings.sql` or execute its staff-audit ALTER: those structures are already included. Migrations are for an existing database missing those structures.

Do not run every file in `sql/`. Most are historical ALTER migrations already represented in the consolidated schema. `sql/admin-account.sql` is instructions only; it does not create an account. Do not import a schema into an existing populated database to update it.

## 4. Configure the private local connection

Copy `includes/db-local.example.php` to `includes/db-local.php` in your editor. Preserve its PHP syntax and direct-request guard. Enter your actual values for:

- `BCIHS_DB_HOST`: `127.0.0.1` for an explicit local TCP connection.
- `BCIHS_DB_PORT`: actual MySQL port, usually 8889 for MAMP or 3306 for XAMPP.
- `BCIHS_DB_NAME`: `bcihs_library` for the local helper workflow.
- `BCIHS_DB_USER` and `BCIHS_DB_PASS`: your local MySQL credentials; do not assume an empty/default password.

Never paste passwords into terminal commands, chat, screenshots or committed examples. Environment variables with these names take precedence over the private file, including explicitly empty values. Check for unintended overrides without printing secrets. Use local filesystem access controls to restrict the file; it must stay Git-ignored. Do not use this source-local configuration as a public hosting configuration.

Keep SMS unconfigured for its safe default, or copy `includes/sms-local.example.php` to the ignored `includes/sms-local.php` and retain `provider=simulation`, `mode=simulate`, `live_enabled=false`, blank secrets and no armed test authorization. No provider credentials are required to run the application locally. Keep notification permission OFF for initial setup.

## 5. Install dependencies and check the database

Run in the project directory using the compatible CLI PHP:

```sh
composer install
composer check-platform-reqs
php scripts/check-local-db.php
```

Use `install`, not `update`, to respect `composer.lock`. Do not use `--ignore-platform-reqs`. Composer creates `vendor/`, which is not supplied by a normal source download. Excel exports require these dependencies.

The database checker reads base metadata only; it does not migrate, verify every latest constraint, or prove all workflows pass. Separately confirm the unique-phone field/index, SMS permission/audit tables and staff audit column through phpMyAdmin's Structure view. The check and administrator helpers refuse non-local hosts and database names other than `bcihs_library`.

## 6. Create the initial administrator

From a real interactive terminal in the project directory:

```sh
php scripts/create-local-admin.php
```

Choose a unique username of 3–100 letters, digits, dots, underscores or hyphens, and a password of **12–72 bytes**, entered twice. Input is hidden; passwords are stored only as hashes. The helper creates a new administrator and refuses to overwrite an existing one. No preset account/password is included in a fresh schema.

Hidden input needs PowerShell and `shell_exec` on Windows; macOS/Linux also need `stty` and `exec`. The helper is CLI-only. Do not upload it as a web password-reset page or substitute a reusable SQL password hash.

## 7. Open the application and verify basics

With Apache/MySQL running, open the URL matching your document root:

- XAMPP on usual Apache port 80: `http://localhost/bcihs-library/`
- MAMP on usual Apache port 8888: `http://localhost:8888/bcihs-library/`

Use the actual port if changed. Open through HTTP, not by double-clicking HTML files. The static homepage alone does not establish database connectivity; sign in through **Staff Login** with the administrator you created.

The new database has no catalog, notices or borrower accounts. Add synthetic books/notices through the UI. Register a synthetic borrower at `select-role.html`, then approve it as staff. Approved librarians share the management controls but retain their role and audit identity. Never use real recipient numbers or school records for routine local acceptance tests.

For pickup QR testing, copy `includes/pickup-local.example.php` to the ignored `includes/pickup-local.php`. Set its existing `BCIHS_PUBLIC_BASE_URL` to your actual application root, such as `http://localhost/bcihs-library` or the MAMP equivalent, without a trailing slash. Codes identify approved requests, not physical books. Staff sign-in and explicit checkout remain mandatory. Manual checkout does not require a configured QR base URL.

A phone cannot reach your PC using the phone's own `localhost`. Physical-phone testing requires a separately reviewed LAN-reachable base URL, same-network connectivity and authorized staff login; do not publicly expose the local server for this purpose.

Rehearse registration/login, three requests and a blocked fourth, approval without double-counting, QR/manual checkout, returns restoring capacity, Waiting versus Ready reservations, rejected audit filters, staff settings and reports. Automated results alone are not browser/Windows acceptance.

## 8. Upgrade an existing local database — separate procedure

Do not import either fresh schema over existing records. Preserve the configured connection, uploads and consumed SMS claims. Make and verify a private backup and rehearse on a restored disposable copy before modifying the intended database. MySQL ALTER statements commit implicitly; rollback of a failed migration may require restoring a backup rather than a transaction rollback.

1. Inspect current tables, columns and indexes. Very old installations need developer review of the historical migrations; there is no universal “run all SQL” order.
2. If SMS permission/audit tables are both absent, apply the current `sql/sms-notification-settings.sql` once to the reviewed intended database. If only one table exists, stop and investigate the partial setup.
3. If the audit table exists but lacks `actor_role`, apply `sql/sms-staff-audit-migration.sql` separately after review. It marks old entries as admin; the legacy `admin_id` field plus `actor_role` identifies which account table owns the actor. Without it, librarian SMS changes fail closed.
4. If `users.phone_normalized` is missing, run `php scripts/migrate-unique-phones.php` in report-only mode. Review duplicate ownership with authorized staff. Previously reported original data had six accepted student accounts sharing one normalized number (five duplicate entries); do not assume the conflict has been resolved or change those records automatically.
5. Only after conflict resolution, backup and explicit approval, run `php scripts/migrate-unique-phones.php --apply` in a maintenance window. It preserves original phone/name values. Missing/malformed legacy numbers remain NULL in the unique field and require staff review. If a prior attempt left the column/index partially installed, investigate before retrying; the helper does not repair partial DDL automatically.
6. Verify unique index `uq_users_phone_normalized`, schema completeness, authentication and workflow tests before accepting writes. Changing code or pulling from Git does not migrate data.

## 9. Operational rules

**Outstanding slots:** count Pending, Awaiting Pickup, Checked Out and legitimate active legacy Approved ordinary records, plus Ready/Fulfilled reservations. Waiting, Rejected, Cancelled and Returned do not count. Each record stays one slot across approval/checkout. New requests/manual checkouts/Ready allocations require capacity; returns, rejection and existing permitted cancellation release it. Existing over-limit records are never auto-edited. A valid existing hold may convert if physical loans remain below three; no checkout can create a fourth physical loan. Inventory copy limits and scheduled pickup quotas remain separate checks.

**Due dates:** staff review the exact date before checkout. Default changes affect future checkouts, never existing deadlines. Saved dates drive the portal, transactions, reports and checkout SMS. Dates use Philippine library time.

**Reminders:** `php scripts/send-due-reminders.php --dry-run` is optional on an already isolated configured database. It is also the default with no argument, produces aggregate counts and reserves no SMS attempt. Before 09:00 Asia/Manila it reports `before_schedule`; candidates are loans due tomorrow. Ordinary active/legacy and fulfilled reservation loans are included; returned/ineligible/unlinked records are not. No catch-up or automatic retry is scheduled. Do not use `--run` against a live-configured environment during testing.

The recommended production schedule is daily at 09:00 Asia/Manila, **documentation only**. Hostinger cron timezone and PHP executable must be verified separately; `0 9 * * *` applies only when cron uses Manila time, `0 1 * * *` when it uses UTC. The existing package excludes scripts, so runner inclusion/private-config wiring needs reviewed deployment work. Do not add a public SMS-trigger URL.

**SMS:** UI permission ON does not authorize live sending. Live adapters need private credentials, sender/network eligibility and separate live authorization; consumed guards, duplicate suppression and no-fallback/no-uncertain-retry behavior remain mandatory. Simulation SMS uses no credits, but the separate credit-balance Refresh may make a read-only provider request if privately configured—do not use it in zero-provider-call tests. Localhost isolation is a test environment, not production deployment evidence.

## 10. Optional safe tests

Start with database-free JavaScript suites from the project directory:

```sh
node tests/phase2b-borrowing.js
node tests/phase3b-pickup-qr.js
node tests/phase4-reservations.js
node tests/phase7a-users-announcements.js
node tests/sms-settings.js
```

PHP provider tests must explicitly block network transport:

```sh
php -d disable_functions=curl_init,curl_exec,curl_multi_init tests/phase7b-unisms.php
php -d disable_functions=curl_init,curl_exec,curl_multi_init tests/phase7b-live-guard.php
```

`tests/post-defense-combined-limit.php` owns a disposable schema created from source SQL and uses credential-free simulation. Its documented `--run` argument points to a local directory containing `includes/db_config.php` and private DB settings; those credentials need disposable-schema creation permission. It does not select the original database. Modes include normal workflow checks, `--staff` and `--users` as the fourth command argument. Review the test before execution.

For integrated tests, use this repository's actual synthetic-source option:

```sh
php tests/post-defense-registration.php --run /path/to/local/connection-settings --integrated --synthetic-source
```

Replace the path with a local checkout containing `includes/db_config.php` and private settings. This mode creates an empty synthetic source schema and the disposable workflow database; it does not select the configured original database. Without `--synthetic-source`, the runner reads the local source database for schema and row fingerprints, so do not omit that flag when original-record reads are prohibited. Never run historical fixture suites blindly against an active database.

Concurrency suites require Unix-like PHP CLI workers, POSIX functions and MySQL 8 `performance_schema` lock visibility. They are not Windows-native acceptance evidence. Older API suites may require fixture/contract updates; the revised focused tests, not an unexecuted historical suite, are the relevant evidence. Use explicit mocks and keep real provider credentials inaccessible.

## 11. Troubleshooting and handover limits

- **Database connection error:** confirm MySQL is running, port/credentials/database are correct, PDO MySQL is enabled and no environment override points elsewhere. Restarting Apache alone does not start MySQL. Do not reset/import to cure a connection error or publish raw PDO diagnostics.
- **Registration/contact-update setup error:** inspect `phone_normalized` and its index. Follow the correct schema path/upgrade review; do not bypass uniqueness or remove conflicting records.
- **Librarian SMS setting unavailable:** inspect audit `actor_role`; apply only the missing reviewed migration. Keep SMS disabled if storage is incomplete.
- **Composer/PHP/Excel error:** verify both PHP runtimes, extension configuration and `vendor/autoload.php`; rerun `composer check-platform-reqs`. Do not bypass platform checks.
- **Login fails:** a fresh schema has no accounts. Create one through the approved CLI helper. Confirm identifiers, approval status and the configured database; never reset accounts merely to guess credentials.
- **Blank/static/un-styled page:** verify Apache URL, document root, folder nesting, assets and browser console. Hard-refresh after source updates. A source download is not a database/demo download.
- **QR fails:** verify application-root base URL, current request/account eligibility, staff authentication and network reachability. Scanning is read-only; checkout/return checks remain server-side.
- **No reminder SMS:** local simulation/OFF and inactive scheduling are intentional. Do not arm live settings or schedule paid sends to troubleshoot a demo.
- **Install-ready status:** the consolidated fresh schema includes the current revision fields, but PHP extensions, Composer dependencies and private local connection setup are still required; dependencies are not bundled. Existing duplicate-phone conflicts block that database's migration. Actual Windows/browser acceptance and production deployment/scheduling remain separate checks, not guarantees from these instructions.

Start handover with this guide and [README.md](README.md), then read [BORROWER-ALLOCATION-LIMIT.md](BORROWER-ALLOCATION-LIMIT.md), [SMS-RETURN-REMINDERS.md](SMS-RETURN-REMINDERS.md), [STAFF-MANAGEMENT-PERMISSIONS.md](STAFF-MANAGEMENT-PERMISSIONS.md) and [BORROWING-DUE-DATES.md](BORROWING-DUE-DATES.md). Production/private configuration, account rotation and activation gates must follow [DEPLOYMENT.md](DEPLOYMENT.md), [SECURITY.md](SECURITY.md) and [HANDOVER.md](HANDOVER.md). No local setup or source download authorizes live SMS or deployment.
