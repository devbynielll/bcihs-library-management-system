# BCIHS Library Management System

A PHP and MySQL/MariaDB library system for Batangas City Integrated High School. It includes a public catalog and announcements, approved borrower accounts, borrowing requests, reservations, QR pickup verification, staff checkout and returns, due-date policy, attendance, reports, and SMS notifications.

## Technology

- PHP 8.3 with PDO MySQL
- MySQL or MariaDB with InnoDB
- HTML, CSS, and vanilla JavaScript
- Composer-managed PhpSpreadsheet for Excel reports

## Start here

1. Read [LOCAL_SETUP.md](LOCAL_SETUP.md) to install a local copy and create an administrator account.
2. Read [DEPLOYMENT.md](DEPLOYMENT.md) before making a reviewed Hostinger release.
3. Read [SECURITY.md](SECURITY.md) for configuration, data, and SMS safeguards.
4. Read [HANDOVER.md](HANDOVER.md) for the feature guide and operational limits.

The application does not include production database records or private configuration. SMS defaults to simulation; paid delivery requires private provider configuration, an independent server-side authorization, and administrator permission. This repository does not deploy automatically.
