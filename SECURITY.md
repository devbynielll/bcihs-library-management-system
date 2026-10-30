# Security and private configuration

## Private files

Never commit or place beneath the public document root:

- Database credentials and provider API keys
- Activation markers, session files, live-test authorization/claim ledgers, or password input files
- Database exports, backups, logs, or real uploaded user records
- `.env` files or local configuration files

Use the supplied `*.example.php` files as templates only. Store real values in the approved private deployment location with restrictive file permissions. The Semaphore balance reader expects its key in `semaphore-api-key.php`; the committed example returns an empty value. Verify `git status` and `git check-ignore <private-file>` before release. `.gitignore` does not remove files already tracked; review the staged file list and repository history separately.

## Authentication and roles

Staff-only operations must enforce authentication and role checks in PHP endpoints, not only in the browser. Use the existing session, CSRF, prepared-statement, rate-limit, upload-validation, and duplicate-protection code. Do not add public password-reset, admin-creation, activation, or diagnostics endpoints. Use the interactive local administrator helper for a new local account; never ship a predefined administrator password or reusable password hash.

## SMS safety

Simulation is the default. Provider credentials and live authorization stay private. SMS permission ON is not live-send authorization. Do not reset consumed one-attempt claims, automatically retry an uncertain provider submission, or fall back to another provider. UniSMS network/content restrictions are provider-specific; Semaphore remains separately configurable. API acceptance and carrier/handset delivery are distinct states.

## Uploads and hosting

Allow only approved image types and enforce size limits. Store uploaded content under the intended upload directories, deny script execution there, and block directory listing. Use HTTPS and secure session cookies. Keep the hosting-private directory outside `public_html`; keep `includes/`, configuration examples, Composer files, SQL, and logs inaccessible over HTTP.

## Release checklist

Before every release, inspect all staged files and diffs, scan for secrets and private data, run relevant tests and syntax checks, verify `.gitignore`, and confirm the deployment target. Do not rely on browser UI restrictions as the only authorization control.
