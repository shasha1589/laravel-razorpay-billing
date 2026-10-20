# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.1.1] - 2026-10-20

No changes to package code; installing `0.1.0` or `0.1.1` gives the same runtime behaviour.

### Fixed

- Test suite on PHP 8.3+ (Pest 4): the test helper `fixture()` clashed with Pest 4's global `fixture()` function and was renamed to `jsonFixture()`. CI now passes on PHP 8.2–8.5 with Laravel 12 and 13.
- CI uses `actions/checkout@v5`.

## [0.1.0] - 2026-10-20

First release. Supports PHP 8.2+ and Laravel 12 and 13.

### Added

- **Setup:** service provider with auto-discovery, `config/razorpay-billing.php`, auto-loaded migrations (publish tag `razorpay-billing-migrations`), and the `razorpay:install` command.
- **API client:** `RazorpayClient` on Laravel's HTTP client, with basic auth and typed exceptions.
  - Retries with exponential backoff and jitter: GETs on 5xx and connection errors, any request on 429.
  - Lazy pagination.
  - Customers, Plans, Subscriptions, Orders, Payments and Invoices endpoints.
- **Plans:** defined in config and validated on load. `razorpay:sync-plans` (`--dry-run`) creates missing plans and never modifies existing ones; changed plans are reported as conflicts.
- **Billable trait:**
  - customers: `createAsRazorpayCustomer`, `createOrGetRazorpayCustomer`, `updateRazorpayCustomer`, `asRazorpayCustomer`;
  - subscriptions: `newSubscription`, `subscription`, `subscriptions`, `subscribed`, `onTrial`, `onGenericTrial`;
  - payments and invoices: `charge`, `payments`, `invoices`, `razorpayInvoices`.
- **Subscriptions:**
  - `SubscriptionBuilder` with quantity, trials, total count, payment methods, notes, `notifyCustomer` and `expireBy`. It enforces the UPI AutoPay limit.
  - `Subscription` model with the `SubscriptionStatus` enum, state helpers, query scopes and `syncFromRazorpay()`.
  - Lifecycle: `swap`, `updateQuantity`, `incrementQuantity`, `decrementQuantity`, `cancel` (at cycle end, with grace period), `cancelNow`, `pause`, `resume` and `addOn`. Razorpay's update restrictions are checked before any API call.
- **Checkout:** `Checkout` (Arrayable/JsonSerializable), the `POST {path}/verify` route for subscription and order payments, and an optional JS helper (publish tag `razorpay-billing-js`).
- **One-time payments and refunds:** `charge()` creates a Razorpay order. `Payment` model with the `PaymentStatus` enum, `refund()` (full or partial), `refunded()` and `partiallyRefunded()`.
- **Webhooks:**
  - `POST {path}/webhook` with signature verification over the raw body, an idempotent event store (`razorpay_webhook_events`), queued processing and a separate rate limit.
  - Out-of-order and duplicate deliveries are handled.
  - One handler per event: `subscription.*` (authenticated, activated, charged, pending, halted, paused, resumed, cancelled, completed, updated), `payment.captured`, `payment.failed`, `refund.processed`, `invoice.paid`, `invoice.expired`, `token.cancelled` and `token.paused`.
  - `razorpay:webhook-replay {event_id}`.
- **Events:** one per state change, fired once whether the change arrived by webhook, verify route or API call. `WebhookReceived` fires for every webhook.
- **Commands:** `razorpay:sync {--subscription=}` reconciles subscriptions. `razorpay:upcoming-charges` fires `UpcomingCharge` before each charge.
- **GST invoices (optional module):**
  - one invoice per captured payment, numbered `{prefix}/{FY}/{sequence}` with no gaps, restarting each Indian financial year;
  - CGST/SGST, IGST and zero-rated export split;
  - `prices_include_tax`, with GST added to plan and charge amounts when prices exclude tax;
  - `InvoiceRenderer` interface and the `GstInvoiceGenerated` event.
- **Config options:** `api.*`, `plan_cache_store`, `middleware`, `run_migrations`, `keep_past_due_active`, `webhook_queue`, `webhook_rate_limit`, `invoices.prices_include_tax`, `invoices.export_state_code`, `invoices.lut_reference`, and the plan option `total_count`.

### Security

- All signatures are compared in constant time (`hash_equals`). Webhook signatures are computed over the raw request body.
- Payments from webhooks are linked to a billable only through server-created order notes, never through payment notes set in the browser.
- Only Razorpay IDs, statuses, amounts and methods are stored, never card, UPI or bank details.

[Unreleased]: https://github.com/shasha1589/laravel-razorpay-billing/compare/v0.1.1...HEAD
[0.1.1]: https://github.com/shasha1589/laravel-razorpay-billing/compare/v0.1.0...v0.1.1
[0.1.0]: https://github.com/shasha1589/laravel-razorpay-billing/releases/tag/v0.1.0
