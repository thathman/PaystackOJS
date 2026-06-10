# Changelog

All notable changes to this project will be documented in this file.

## 1.1.1 – 2026-06-10

### Compatibility / Security
- Added a temporary, fail-closed workaround for
  [pkp/pkp-lib#12885](https://github.com/pkp/pkp-lib/issues/12885). OJS 3.5
  creates APC queues under the requesting editor while notifying the author.
  Only the primary assigned author, or the sole assigned author when no primary
  author can be resolved, may repair that queue ownership before checkout.
- Existing ownership checks remain enforced for all payment types. The
  workaround is a no-op when OJS supplies the correct owner and should be
  removed once the minimum supported OJS version contains the upstream fix.

## 1.1.0 – 2026-06-10

### Security
- Optional **webhook IP allowlist**: when enabled, webhooks are only accepted
  from Paystack's documented source IPs (52.31.139.75, 52.49.173.169,
  52.214.14.220), in addition to the HMAC signature check. Off by default
  (proxies/CDNs can hide the client IP).

### Reliability
- Email-send idempotency moved from unbounded `emailed_success_*` plugin
  settings into the TTL'd `paystack_webhook_dedupe` table (legacy keys still
  honoured).

### Documentation
- New "Email templates & sponsorship" README section explaining the OJS
  paymethod mailable restriction and the sponsor-only Payment Method Support
  companion addon.

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
