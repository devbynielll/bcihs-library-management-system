# Semaphore credit balance

Authenticated administrators can expand **View SMS Credits — Semaphore** on **SMS Logs & Settings** to view the account's remaining `credit_balance`, the last successful update time, and a Refresh button.

The server uses Semaphore's read-only `GET /api/v4/account` endpoint and a shared server-side cache/cooldown. This check does not send a message, consume SMS credits, change notification permission, switch providers, or approve a sender name. A displayed balance is not proof of message delivery or Sender Name approval. The SMS Logs refresh reloads local records only and does not poll Semaphore delivery status.

## Private API key

The committed [`includes/semaphore-api-key.example.php`](includes/semaphore-api-key.example.php) returns an empty value. Keep the actual `semaphore-api-key.php` private and ignored by Git. In the Hostinger deployment layout, place it in the private `bcihs-private` directory outside `public_html`; the hosted adapter checks the supported environment setting first. For local use, follow the private-file path and permissions described by the deployment administrator. Never put a key in a browser response, HTML, JavaScript, log, screenshot, or public issue.

Missing, unreadable, rate-limited, or invalid configuration produces a warning; it does not enable live delivery. The configured provider for SMS sending remains an independent setting. A real provider message is never sent by this balance feature.

## Verification

The isolated mock test runs without contacting Semaphore:

```sh
php -d disable_functions=curl_init,curl_exec tests/semaphore-credits.php
node tests/semaphore-credits.js
```
