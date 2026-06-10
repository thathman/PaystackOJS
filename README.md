# Paystack Payment Gateway for OJS

A [Paystack](https://paystack.com) payment method for Open Journal Systems 3.5.
Handles article/issue purchases, subscriptions, and publication fees with secure
server-side verification.

| | |
|---|---|
| **Version** | 1.0.1 |
| **OJS** | 3.5.0+ |
| **PHP** | 8.1+ |
| **License** | GPL-3.0-or-later |

Supported currencies: NGN, USD, GHS, ZAR, KES, XOF (your Paystack account must
be eligible for the currency you charge in).

## Features

- **Checkout + return + webhook** flow with strong verification: HMAC-SHA512 over
  the raw webhook body (`hash_equals`), and amount + currency + reference
  re-checked against the queued payment on both the callback and webhook
  (anti-tampering). Fulfilment is idempotent.
- **User payment history + receipt** pages, served by the plugin and reachable at
  `payment/plugin/paystackplugin/history` (and `/receipt/{id}`, ownership-checked).
  Ship as neutral, single-column default pages; any theme can override them.
- **Manager Transactions list with Refund** in the plugin settings.
- **Emails via OJS-native Mailables** — confirmation / failed / admin templates are
  editable in **Settings › Emails** (no custom email UI in the plugin).
- **No extra dependencies** — uses the Guzzle HTTP client bundled with OJS core.
- **No OJS core changes** — hooks only.

## Installation

1. Upload via **Settings → Website → Plugins → Upload A New Plugin**, or copy the
   folder to `plugins/paymethod/paystack/`.
2. In **Settings → Distribution → Payments**, enable payments, choose Paystack as
   the payment method, and set your currency.
3. Open the Paystack plugin **Settings**, enter your API keys, and toggle test mode.

## Configuration

| Setting | Description |
|---------|-------------|
| Test mode | Use Paystack test keys (`sk_test_…` / `pk_test_…`) |
| Test / Live keys | Secret + public keys from your Paystack dashboard |

In your Paystack dashboard set the **callback** and **webhook** URLs shown on the
plugin settings page:

```
Callback:  {journalUrl}/payment/plugin/paystackplugin/callback
Webhook:   {journalUrl}/payment/plugin/paystackplugin/webhook
```

## Security

| Property | Implementation |
|----------|----------------|
| Webhook authenticity | HMAC-SHA512 over the raw body, `hash_equals` |
| Payment tampering | Amount + currency + reference re-verified vs the queued payment |
| Replay / double-fulfilment | DB-backed webhook dedupe (30-day TTL) + a unique-insert fulfilment guard closing the callback/webhook race |
| Transport | HTTPS enforced outside test mode |
| CSRF | OJS CSRF token on mutating endpoints |
| Core changes | None — hook-based |

## Theming

The history / receipt / details / confirmation pages are neutral by default. A
theme can override any of them by placing a template at:

```
plugins/themes/<yourtheme>/templates/plugins/paymethod/paystack/templates/<file>.tpl
```

## Changelog

- **1.0.1** — DB-backed webhook dedupe + fulfilment guard, XOF support,
  localized settings text, dead-code cleanup, consistent GPL v3 licensing.
- **1.0.0** — Initial release.

See [CHANGELOG.md](CHANGELOG.md) for details.
