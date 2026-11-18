# Post-defense revision setup

## Updating a local installation

1. Back up the existing database and private configuration outside the repository. Preserve uncommitted local work before switching branches or pulling updates.
2. Fetch and check out the reviewed revision branch. After it has been merged by the owner, pull the approved default branch instead. A GitHub push never refreshes another computer's localhost automatically.
3. Keep private database, pickup URL, SMS provider settings, API keys and consumed test claims local. Never copy production configuration into a test installation. Use simulation with live sending disabled.
4. For an existing database, inspect whether `users.phone_normalized` and its unique index already exist. First run `php scripts/migrate-unique-phones.php` in report-only mode against an explicitly configured local database. Resolve duplicate ownership with staff review; never delete/merge accounts or overwrite original numbers automatically. After backup, review and separate authorization, use `--apply` during maintenance. The original local installation has known conflicts; no migration was applied to it as part of this release.
5. Inspect `sms_notification_audit.actor_role`. If missing, review and apply `sql/sms-staff-audit-migration.sql` to that intended database only. Old entries remain administrator entries; librarians cannot change SMS permission until audit attribution is available.
6. Restart the local PHP server if needed and hard-refresh the pages. Check registration, staff login, catalog slot counter, requests, QR handover, returns and staff settings using synthetic records.

Do not reimport the fresh schema into a populated database. Existing conflicting users remain a migration blocker; code alone cannot resolve ownership.

## New isolated installations

Import `sql/schema.sql` into a new empty database. It contains all 12 tables, the unique normalized-phone field, the staff audit role and SMS permission OFF. It contains no accounts, library records, provider credentials or reusable account passwords. Use the interactive administrator setup helper, then create synthetic borrower accounts through registration and staff approval. No sample passwords are committed as usable accounts.

The three-slot policy counts Pending, Awaiting Pickup, Checked Out and legitimate active legacy Approved requests, plus Ready/Fulfilled reservations. Waiting/Rejected/Cancelled/Returned do not count. Existing allocated checkout does not use another slot; a separate active-loan check prevents a fourth physical loan, including for preserved over-limit accounts. See `BORROWER-ALLOCATION-LIMIT.md`.

Rejected account/borrowing records remain in the database and authorized audit views. They are excluded from ordinary lists. Approved librarians may manage SMS permission and the default borrowing period; audit entries distinguish their role and ID. Private provider credentials, live authorization and sender/test guards remain separate.

## Safe verification

Run JavaScript and PHP mock suites locally. Provider mock suites require PHP with `-d disable_functions=curl_init,curl_exec,curl_multi_init`.

The following commands create owned disposable schemas. The connection-settings directory must be local and permit creating disposable databases; secrets are read privately, never passed on the command line. The synthetic-source option never selects the configured original database:

```sh
php tests/post-defense-registration.php --run /path/to/local/connection-settings --integrated --synthetic-source
php tests/post-defense-combined-limit.php --run /path/to/local/connection-settings
php tests/post-defense-combined-limit.php --run /path/to/local/connection-settings --staff
```

Concurrency checks require MySQL 8 with InnoDB, performance_schema lock visibility, POSIX process functions and the PHP CLI worker server on a Unix-like test host. Run on a Mac/Linux test machine if the Windows CLI cannot provide overlapping workers. Ordinary XAMPP application use does not require these test-only capabilities. Ports 8906 and 8911 must be free for these suites. No test should target school records or use real provider credentials.

## Return reminders

The CLI runner defaults to dry-run. Scheduling is documentation only and remains inactive. Future deployment needs explicit review of the PHP CLI path, protected configuration, server timezone, package inclusion and SMS policy. Do not install cron or enable live sends merely to update the source. See `SMS-RETURN-REMINDERS.md`.

## Verification scope

Automated tests do not establish browser or physical-phone acceptance. Remaining visual checks must be completed separately. GitHub contains source and safe schemas, not the private localhost demo database or its accounts. This update has no automatic deployment configuration.
