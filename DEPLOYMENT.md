# Hostinger deployment guide

This repository is a source handover. It has no GitHub Actions workflow, webhook, or automatic production deployment. The live site is managed separately; pushing source changes does not update it.

## Before a release

1. Review the exact source diff and test the release locally.
2. Take and privately verify a current backup of the hosted database and uploaded files. Keep backups outside `public_html` and Git.
3. Confirm the target PHP version (PHP 8.3), PDO MySQL, required extensions, database, and document root in hPanel.
4. For a **new isolated deployment candidate**, the optional `scripts/build-hostinger-package.py` tool can assemble a closed-by-default layout from a privately reviewed SQL export and staging workspace. Those inputs and its output are intentionally not in this repository. The tool does not deploy or contact SMS providers. Do not use its candidate to overwrite the active site or its database. Follow [`deployment/hostinger/config.example.php`](deployment/hostinger/config.example.php) for the private configuration shape; never commit real values or place private configuration in the public web root.
5. Apply only reviewed, necessary migrations from `sql/` to the intended database. Do not import the complete schema over a populated site.
6. Upload reviewed files using Hostinger File Manager or the approved secure transfer method. Preserve the current release for rollback; do not replace unrelated uploads or account data.

## Access and private files

Keep the private directory outside the document root and deny HTTP access to `includes/`, configuration files, SQL files, logs, backups, and dependency metadata. Retain the site's HTTPS redirect, directory listing restrictions, upload execution restrictions, and security headers. Do not remove access-denial rules to work around a hosting error. Verify the changed file after saving and test both the homepage and blocked private paths.

The deployment activation gate is separate from login authentication. Follow the private credential rotation and activation procedure supplied to the authorized deployment administrator. Never create a public password-reset or activation script, and do not edit the marker to bypass incomplete rotation.

## SMS

Keep the hosted SMS setting OFF unless the school separately authorizes notifications. Keep simulation enabled and live delivery disabled during deployment verification. Provider secrets and sender settings belong only in private configuration. Viewing the Semaphore credit balance is read-only; it neither sends an SMS nor enables delivery.

## Post-upload review

Verify the homepage, public books and announcements, administrator and borrower login, authorization on staff APIs, uploads, reports, QR verification, borrowing/return, and Time In. Review SMS Settings and confirm the expected disabled/simulation state. Do not create test records in the live database. For functional tests that write data, use a separate disposable database and approved test accounts.

The Hostinger-specific source adapters and one-time setup helper are under `deployment/hostinger/`. The rotation helper is only for a separately reviewed new-install procedure; it is not a password-reset tool for an active school site. No deployment or database change is performed by this repository or guide. Require explicit release approval before making changes to the live site.
