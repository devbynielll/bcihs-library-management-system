# Client handover

## Feature guide

- **Catalog and announcements:** public pages read current catalog and published notices from the configured database.
- **Borrowing and reservations:** account eligibility, inventory, queue capacity, approval, pickup, checkout, return, and saved due dates are checked server-side.
- **QR pickup:** a QR identifies an approved request. Staff authentication and an explicit checkout confirmation are still required; scanning alone does not change a loan.
- **Attendance:** staff can record library entry. Departure tracking is policy-controlled.
- **Reports:** dashboard and report data are generated from stored transactions. The statistical circulation report counts checkout transactions; confirm the metric and signatories with the librarian before official use.
- **SMS:** common notification events use the selected private provider adapter and shared duplicate protection/logs. The SMS permission, simulation/live mode, provider authorization, provider restrictions, and one-attempt test guards are distinct controls.
- **Semaphore credit panel:** administrator-only read-only balance lookup with caching; refresh checks the provider balance, not message delivery.

## Operating rules

- Preserve the existing database and uploads. Back up before a reviewed migration or deployment.
- Changing the default loan period affects future checkouts only. Existing loans retain their saved due dates.
- Keep SMS OFF or simulation-only during development and routine deployment checks. Never use production users as test recipients.
- A provider accepting a message is not proof of handset delivery. The SMS Logs refresh reloads local records; it does not poll provider delivery status.
- Do not expose database, provider, activation, or password-preparation files under the public document root.

## Tests

JavaScript test files run with `node tests/<suite>.js`. PHP unit/mock suites run with `php tests/<suite>.php`. Suites requiring database fixtures or a web server identify their isolation requirements in the test setup. Use only a separate disposable database for those suites; do not run fixture tests against the active school database.

## Scope notes

Provider delivery-status polling, scheduled reminders, and policy decisions such as attendance quotas, holiday handling, and official due-date rules require separate school approval. This handover does not authorize a live SMS, database migration, or production deployment.
