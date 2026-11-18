# Borrowing due dates

The system starts with a **14-calendar-day** default. Administrators and approved librarians can change the school-approved default; the saved policy applies to future checkouts only.

## At checkout

Authorized staff can use the current default period or choose an approved custom number of calendar days or due date. The checkout dialog displays the calculated date before confirmation. The server validates the choice and saves the selected due date on the borrowing or fulfilled-reservation record. QR pickup checkout and manual checkout use the same validation.

Student borrowing history, staff transactions, reports, and checkout notifications read the saved record date. They do not independently recalculate a deadline from today's default.

## Change the default

Administrators and approved librarians can use the **Default borrowing period** controls in the staff workflow. Enter the approved calendar-day count and reason, then review the confirmation. The server checks the authenticated staff role, CSRF token, current policy version, and allowed range, and records a policy audit entry identifying the actor ID and role. Older entries without an explicit role are displayed as administrator entries. A conflict means another staff member changed the policy; refresh and review before saving again.

Changing the default never silently changes active or historical loans. Existing loans keep their saved deadline. Use the existing return/extension policy approved by the school for an active loan; do not directly edit database dates.

## Date handling

Dates are interpreted in Asia/Manila time. A selected calendar due date is stored consistently with the application's due-date rules. The preview is informational until the matching checkout is confirmed; stale or mismatched previews are rejected.

Confirm the official borrowing period, holidays, and exception policy with the librarian before changing the default for school operations.
