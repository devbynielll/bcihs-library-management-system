# Administrator and librarian management access

Approved librarian accounts retain their own role and identity but may manage the same library UI functions as administrators, including SMS permission and the default borrowing period. Borrowers, visitors, pending/rejected librarians and unauthenticated sessions remain excluded. CSRF, explicit enable confirmation, optimistic versions, record-history protections and transaction locks remain mandatory.

SMS permission does not grant access to credentials or enable live transport. Provider selection, API keys, sender approvals, consumed acceptance claims and separate live authorization remain private deployment controls. The localhost demo retains credential-free simulation and disabled provider transmission.

## Audit compatibility

Apply `sql/sms-staff-audit-migration.sql` separately after backup/review for an existing installation. It adds only `actor_role`, defaulting old entries to admin. The existing `admin_id` column is retained as the legacy actor-ID field; interpret it with `actor_role` to distinguish the admins and users tables. Fresh installs include the column in `sql/sms-notification-settings.sql`. Without the migration, administrator behavior remains compatible and librarian SMS changes fail closed. No automatic migrations occur.

Default borrowing-policy JSON audit entries also include `actor_role`; old entries without it display as Administrator. Existing deadlines do not change when the default changes. This permission update is localhost-only until separately reviewed and deployed. No original/hosted migration is included.
