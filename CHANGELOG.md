# Changelog

All notable changes to this project will be documented in this file.

## 1.0.1 – 2026-06-10

### Reliability
- Webhook idempotency moved from plugin settings to a dedicated, TTL-purged
  `paystack_webhook_dedupe` table (30-day retention; settings fallback kept).
- Double-fulfilment race between the callback and webhook closed with a
  `paystack_fulfillment_guards` unique-insert claim inside a transaction.
- Webhook audit log table (`paystack_webhook_logs`) is now created at
  install/upgrade time.
- O(1) reference → completed-payment lookup via a reverse index written at
  fulfilment time.

### Features
- Added XOF (West African CFA Franc) to the supported currencies for
  Côte d'Ivoire merchants.

### Housekeeping
- All settings-page text is now localized (test-mode banner, secret-key hints).
- Removed dead code and stale files (legacy upgrade descriptor, display-name
  migration, unused template-health checks, unused test-mode banner hooks).
- Licensing made consistent: GPL v3 across LICENSE, composer.json, and file
  headers.

## 1.0.0 – 2026-06-03

- Initial release: Paystack checkout + callback + webhook flow with
  HMAC-SHA512 signature verification, amount/currency/reference re-checks,
  idempotent fulfilment, manager transactions list with refunds, user payment
  history and receipts, and OJS-native editable email templates.
