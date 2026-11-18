# Return reminders

The CLI runner checks today's date in Asia/Manila and reminds approved registered borrowers whose saved due date falls tomorrow. It includes ordinary `checked_out` and legacy `approved` loans, plus `fulfilled` reservation loans. It excludes other states, missing due dates, and unlinked historical loans. No book title is included, so long or Unicode titles cannot expand this message's SMS cost.

Message: `BCIHS Library: Reminder: Your borrowed book is due tomorrow, Nov 19, 2026. Please return it on time. Thank you.` The date comes from the saved loan due date.

## Local verification

Run the owned disposable test harness from this worktree:

```sh
php tests/post-defense-registration.php --run /path/to/original/local/checkout --reminders
```

This uses schema-only disposable data, synthetic provider settings, mocked transports, disabled provider cURL functions, and compares original record fingerprints. The original path supplies local database connection settings only; real SMS credentials are never copied.

For an already isolated, configured local application:

```sh
php scripts/send-due-reminders.php --dry-run
```

Dry-run is also the default when no argument is supplied. It reports aggregate counts, not phone numbers or borrower details, creates no SMS logs, and never loads provider credentials or invokes transport. It requires a working database connection. `--run` intentionally uses the configured SMS permission and provider mode: do not run it against a live-configured environment during development.

## Scheduling — future deployment only

No scheduler has been installed. After separate deployment review, a Hostinger cron can invoke the CLI runner once daily at **09:00 Asia/Manila**. Select the hosting account's verified PHP 8.3 CLI executable and actual application path. If the cron scheduler uses UTC, the schedule is `0 1 * * *`; if it uses Asia/Manila it is `0 9 * * *`. Confirm the host's scheduler timezone first.

Command shape:

```text
<verified-php-cli-path> <application-root>/scripts/send-due-reminders.php --run
```

Deploy only through a reviewed package update: existing packaging excludes scripts. Keep the runner denied over HTTP and ensure CLI loads the same protected hosting database/SMS adapters. Never add a public trigger URL. Store cron output outside public_html with private permissions and log retention. No keys or passwords belong in the cron command.

## Safety and limitations

- Runs before 09:00 local time do not send. A late run considers only its current local tomorrow; missed-day reminders are not recovered.
- A database-scoped advisory lock prevents overlapping batches. Existing permission/event locks and persistent log reservations prevent repeat attempts, including after process interruption.
- The event is `due_reminder`. Its key contains source, loan ID and saved due calendar date. Changing only the time on the same due day cannot generate another reminder.
- Immediately before the log reservation, the engine rechecks status, owner approval/role, exact saved due timestamp, tomorrow's range and the owner's current phone.
- OFF, simulation, invalid phones, provider failures and unknown outcomes are logged through existing SMS behavior and are not replayed. Correcting a skipped phone or enabling SMS does not resend that day's consumed event.
- If logging/lock storage fails before an attempt is reserved, no transport occurs. No automatic retry is scheduled. An operator must inspect failures; no exactly-once handset-delivery claim is made.
- A return after the final eligibility check cannot recall a provider request already being initiated. Returns before that check are excluded. Sending does not modify loan or inventory records.
- UniSMS retains one-segment/no-link restrictions and its separate production authorization. Acceptance-only mode still refuses this event. Semaphore uses its existing live/sender gates. No fallback or provider polling is added.
- Normal SMS credit availability, sender/network eligibility and handset delivery remain provider-dependent. No live verification was performed for this feature.
