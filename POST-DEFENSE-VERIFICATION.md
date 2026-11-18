# Revision verification

Verification performed on the release candidate on 2026-11-18, using local PHP 8.3/MySQL, synthetic records and blocked or mocked provider transport.

- Integrated registration, migration, borrowing, reservation, reminder and visibility suite: 352 checks passed using `--synthetic-source`; no school database was selected.
- Focused outstanding-slot, QR/manual checkout, last-copy and overlapping-transaction suite: 64 checks passed.
- Staff permission and audit suite: 25 checks passed.
- Ten JavaScript suites: 571 checks passed, including catalog, QR, reservations, attendance, reports, users/announcements, SMS settings/logs, due dates and Semaphore credits.
- Database-free PHP checks passed: SMS core 94, additional UniSMS 100, persistent live guard 120, production policy 59, loader 23, dynamic recipients 19, Semaphore credits 33 and due dates 35. See the suite's own output for authoritative counts.
- Syntax: 137 PHP files and 38 application JavaScript files passed.
- Synthetic closed-package checks: 10 passed. Offline administrator setup checks: 7 passed.
- Whitespace checks passed. Source/security review excluded private configuration, uploads, backups, activation markers, provider secrets and runtime ledgers.

One initial focused concurrency run timed out while observing two simultaneous lock waits. The integrated concurrency checks and subsequent focused run observed overlapping transactions and passed all invariants. The observation loop remains timing-sensitive; a timeout must be investigated, not labeled as a pass or bypassed.

The older account-registration/manual-checkout fixtures were updated for the new required fields and account-linked checkout contract, but those complete historical API suites were not rerun. They are not claimed as passed by this report. The isolated integrated and focused suites are the revision acceptance evidence.

Browser/mobile visual and physical-phone acceptance have not been repeated for this GitHub candidate. No live SMS, provider balance request, reminder scheduling, production migration or hosted deployment was performed. Existing phone-ownership conflicts remain an original-database migration prerequisite; this source update does not resolve them.
