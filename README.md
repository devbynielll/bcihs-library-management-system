# BCIHS Library Management System

A PHP and MySQL library system for Batangas City Integrated High School, covering the public catalog, borrower accounts and daily library operations. The revised source is intended for reviewed local installation; this document does not establish production deployment or Windows acceptance.

## Features

- Public homepage, book catalog, services and published announcements with images.
- Registration, staff approval, sign-in, profile updates and protected password recovery.
- Book metadata, covers, copy counts and staff-managed inventory.
- Borrowing requests, pickup holds, account-linked manual checkout, returns and saved/custom due dates.
- Request-specific pickup QR codes: staff authentication and explicit handover confirmation are mandatory; scanning never checks out a book.
- Reservation waiting lists, pickup scheduling/capacity, Ready allocations, checkout and return.
- Attendance and walk-in recording; optional policy-controlled departure tracking.
- Dashboard, transaction history, reports, print and spreadsheet exports.
- Shared simulation/UniSMS/Semaphore notification engine, SMS permission controls, duplicate prevention and logs. Semaphore credit monitoring is a separate read-only provider operation, not a simulated SMS.
- Approved librarians share library-management controls with administrators, including notification permission and the default borrowing period. Private credentials and live transport authorization remain separate.

### Six post-defense revisions

1. **Unique mobile numbers:** normalized Philippine phone numbers are unique across registered user roles/statuses during registration and profile updates. Existing conflicts require review, never automatic deletion or merging.
2. **Complete registration names:** required First/Last Name, optional Middle Name, with compatible `users.full_name` storage and support for legitimate Unicode/compound names.
3. **Return reminders:** CLI-only reminders for eligible loans due tomorrow in Asia/Manila, using saved/custom due dates, with duplicate protection. Scheduling/live sending are not activated by installation.
4. **Three outstanding book slots:** Pending, Awaiting Pickup, Checked Out and legitimate active legacy Approved requests plus Ready/Fulfilled reservations count once. Waiting, Rejected, Cancelled and Returned do not count. The catalog shows **Available Book Slots: X/3**; no new request at zero. Approval and allocated checkout preserve a slot. A separate atomic three-physical-loan cap protects checkout even for preserved legacy over-limit accounts.
5. **Reservation concurrency:** waiting-list entries allocate no stock; transaction locking protects the last copy and borrower capacity across competing actions.
6. **Rejected visibility:** routine borrower/staff lists exclude rejected borrowing records; the Users default excludes rejected accounts. Authorized audit filters retain access without deleting history, reports or SMS logs.

## Technology and requirements

HTML, CSS, vanilla JavaScript, PHP 8.3 (64-bit), PDO MySQL, InnoDB and Composer-managed PhpSpreadsheet. Use MAMP on macOS or a compatible XAMPP/PHP/Apache installation on Windows. Check the actual PHP version: installing XAMPP alone does not guarantee PHP 8.3. MySQL 8 is the verified concurrency-test server; a different MySQL/MariaDB release needs local verification. Node.js is needed only for JavaScript tests. See [LOCAL_SETUP.md](LOCAL_SETUP.md) for extensions and commands.

## Quick start — new empty local installation

1. Obtain the latest reviewed source and place it in your configured local document root as `bcihs-library`. Do not overwrite an existing project or database.
2. Start Apache and MySQL. Verify web PHP and terminal PHP are compatible.
3. Prepare a **new, empty** local database and import the appropriate schema. Import the consolidated `sql/schema.sql` as described in [LOCAL_SETUP.md](LOCAL_SETUP.md#3-create-a-new-empty-database). It already includes the current phone-uniqueness and staff-audit fields. Do not run duplicate migrations on a fresh installation.
4. Copy `includes/db-local.example.php` to the ignored `includes/db-local.php`, then enter the actual local connection settings privately. Keep SMS absent/default simulation or explicitly simulation-only with live sending disabled.
5. In the project directory run `composer install`, followed by `composer check-platform-reqs`.
6. Run `php scripts/create-local-admin.php` in an interactive terminal. You choose the credentials; no accounts/passwords are supplied by a fresh schema.
7. Open `http://localhost/bcihs-library/` (usual XAMPP Apache port 80) or `http://localhost:8888/bcihs-library/` (usual MAMP Apache port 8888). Use the actual configured port/document root. Set the local pickup base URL before testing QR codes.

The database starts without users, books, transactions or announcements. Local demo records and private configuration are not supplied by downloading code. An existing database requires the separate upgrade procedure, not a fresh-schema import.

## Documentation

- [Local installation, existing-database upgrades and troubleshooting](LOCAL_SETUP.md)
- [Final outstanding-slot policy and checkout safeguards](BORROWER-ALLOCATION-LIMIT.md)
- [Reminder dry-run, scheduling and limitations](SMS-RETURN-REMINDERS.md)
- [Staff management permissions and audit migration](STAFF-MANAGEMENT-PERMISSIONS.md)
- [Borrowing periods and custom due dates](BORROWING-DUE-DATES.md)
- [Feature and client handover guide](HANDOVER.md)
- [Security safeguards](SECURITY.md)
- [Reviewed hosting deployment procedure](DEPLOYMENT.md)

Read the current local setup guide before using older workflow fixtures. See [POST-DEFENSE-SETUP.md](POST-DEFENSE-SETUP.md) for revision upgrade precautions and [POST-DEFENSE-VERIFICATION.md](POST-DEFENSE-VERIFICATION.md) for recorded test scope. No Git push, local setup command or SMS UI toggle authorizes hosted deployment, live sending or scheduler activation.

## Guide for Future Developers and AI Assistants

Read this README, [LOCAL_SETUP.md](LOCAL_SETUP.md), the slot-policy guide, reminder guide and staff-permission guide before changing workflows.

- `backend/`: PHP request handlers; enforce authorization, CSRF, validation and transactions here.
- `includes/`: shared borrowing/inventory, account validation, due-date, SMS, role and security helpers. `includes/borrowing.php` owns shared capacity checks; `includes/account-management.php` owns phone/name validation.
- `js/`, `css/`, root HTML: user interface; never rely on disabled buttons alone for policy enforcement.
- `sql/`, `scripts/`: reviewed schemas/migrations and CLI tools. Migrations are deliberate operations, not page-load hooks. `scripts/send-due-reminders.php` is CLI-only and defaults to dry-run.
- `tests/`: JavaScript, database-free mocks and explicitly isolated workflow tests. Older suites may encode historical contracts; read their prerequisites and current verification scope before running them.
- `composer.json`/`composer.lock`: dependency requirements and reproducible versions. Install from the lockfile; do not commit `vendor/` or secrets.

Preserve the book-before-borrower locking order, slot-versus-physical-loan distinction, ownership, approved-account requirements, last-copy holds, saved due dates, history protection, role-attributed audits and SMS one-attempt/no-fallback controls. Do not reset consumed claims, infer a network from its prefix, redirect recipients or replay uncertain messages. Normal notifications use the event owner's registered phone.

Before editing, inspect the active branch, private-config boundaries and relevant code/tests; preserve uncommitted work and make a source-only recovery copy. Use synthetic disposable databases and mocked transports, never school records or real provider credentials. Run focused tests and syntax checks, then report actual results and visual gaps. Schema changes need backup/conflict review and separate approval. Source-only changes are not permission to modify production, send SMS, push or deploy.
