# laravel-razorpay-billing

[![tests](https://github.com/shasha1589/laravel-razorpay-billing/actions/workflows/tests.yml/badge.svg)](https://github.com/shasha1589/laravel-razorpay-billing/actions/workflows/tests.yml)
[![Packagist](https://img.shields.io/packagist/v/shasha1589/laravel-razorpay-billing.svg)](https://packagist.org/packages/shasha1589/laravel-razorpay-billing)
[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

Cashier-style subscription billing for Laravel on [Razorpay](https://razorpay.com): plans, subscriptions, UPI AutoPay, card and bank e-mandates, webhooks, one-time charges, refunds and GST-ready invoices.

> **Status:** `0.x`. The API may still change between minor versions. It will be marked `1.0.0` after running in production for at least one billing cycle.

- [Requirements](#requirements)
- [Installation](#installation)
- [Configuration](#configuration)
- [Plans](#plans)
- [Billable models](#billable-models)
- [Subscribing](#subscribing)
- [Managing subscriptions](#managing-subscriptions)
- [One-time payments and refunds](#one-time-payments-and-refunds)
- [GST invoices (optional)](#gst-invoices-optional)
- [Scheduled commands](#scheduled-commands)
- [Webhooks](#webhooks)
- [Example: Blade](#example-blade)
- [Example: Inertia + React](#example-inertia--react)
- [Testing your app](#testing-your-app)
- [Razorpay API client](#razorpay-api-client)
- [Limitations](#limitations)

## Requirements

- PHP 8.2+
- Laravel 12 or 13 (Laravel 13 needs PHP 8.3+)
- A queue worker (webhooks are processed in a queued job by default)
- A Razorpay account with Subscriptions enabled

## Installation

```bash
composer require shasha1589/laravel-razorpay-billing
php artisan razorpay:install      # publishes config + JS helper, prints next steps
php artisan migrate
```

Migrations run automatically with `php artisan migrate`. They add `razorpay_customer_id`, `billing_gstin` and `billing_state_code` to your billable model's table (`billable_model` config), and create `razorpay_subscriptions`, `razorpay_payments`, `razorpay_webhook_events`, `razorpay_invoices` and `razorpay_invoice_sequences`. The invoice tables are created even while the GST module is off. To customise them, publish them with `php artisan razorpay:install --migrations` and set `run_migrations` to `false`.

Add your keys to `.env`:

```dotenv
RAZORPAY_KEY_ID=rzp_test_xxxxxxxx
RAZORPAY_KEY_SECRET=xxxxxxxx
RAZORPAY_WEBHOOK_SECRET=xxxxxxxx
```

## Configuration

`config/razorpay-billing.php`:

| Key | Default | Description |
| --- | --- | --- |
| `key_id` / `key_secret` | `env(...)` | Razorpay API keys. |
| `webhook_secret` | `env(...)` | Secret set on your Razorpay webhook; used to verify signatures. |
| `api.base_url` | `https://api.razorpay.com/v1` | API base URL (`RAZORPAY_API_URL`). |
| `api.timeout` | `30` | Request timeout in seconds. |
| `api.retries` | `2` | Extra attempts for GETs failing with a connection error or 5xx, and for any 429. Other writes are never retried. |
| `api.retry_delay` | `200` | Initial backoff in ms; doubles each attempt, with jitter. |
| `billable_model` | `App\Models\User` | Model using the `Billable` trait (`RAZORPAY_BILLABLE_MODEL`). |
| `currency` | `INR` | Currency for plans, orders and invoices. Amounts are always integer paise. |
| `path` | `razorpay` | Route prefix for `POST {path}/webhook` and `POST {path}/verify`. |
| `middleware` | `['web']` | Middleware for the checkout verify route. Add `auth` if only signed-in users check out. |
| `run_migrations` | `true` | Load package migrations automatically. Set `false` if you publish and customise them. |
| `keep_past_due_active` | `false` | Treat `pending` subscriptions (Razorpay retrying a failed charge) as valid. |
| `webhook_tolerance_events` | `true` | Store webhook event IDs and skip duplicate deliveries. |
| `webhook_queue.enabled` | `true` | Process webhooks in a queued job (`RAZORPAY_WEBHOOK_QUEUE`). If `false`, they're processed during the request. |
| `webhook_queue.connection` / `.queue` | `null` | Queue connection and name for webhook jobs. |
| `webhook_rate_limit` | `600` | Webhook requests per minute per IP. |
| `upi_autopay_max_amount` | `1500000` | Max UPI AutoPay amount in paise (₹15,000). UPI-only checkouts above this are refused. |
| `upcoming_charge_notice_hours` | `48` | Hours before `charge_at` to fire `UpcomingCharge`. |
| `plans` | `[]` | Plan key => definition, synced by `razorpay:sync-plans`. |
| `plan_cache_store` | default store | Cache store for the plan key => Razorpay plan ID map (`RAZORPAY_PLAN_CACHE_STORE`). |
| `invoices.enabled` | `false` | Generate a local GST invoice for every captured payment. |
| `invoices.seller.*` | `null` | Your legal name, GSTIN, address and two-digit GST `state_code` (required when enabled). |
| `invoices.sac_code` | `null` | Services Accounting Code, for your renderer. |
| `invoices.gst_rate` | `18` | GST rate in whole percent. |
| `invoices.prices_include_tax` | `false` | Whether plan and `charge()` amounts already include GST. If `false`, GST is added before amounts are sent to Razorpay. |
| `invoices.export_state_code` | `'96'` | Buyer state code that marks an export (zero-rated). |
| `invoices.lut_reference` | `null` | Letter of Undertaking reference for export invoices. |
| `invoices.number_prefix` | `'INV'` | Invoice number prefix. |
| `invoices.renderer` | `null` | Class implementing `InvoiceRenderer` for PDFs. |

## Plans

Define plans in config (amounts in paise):

```php
'plans' => [
    'pro-monthly' => [
        'name' => 'Pro',
        'period' => 'monthly',   // daily, weekly, monthly, quarterly, yearly
        'interval' => 1,         // daily plans need at least 7
        'amount' => 1999900,     // ₹19,999
        'methods' => ['card', 'emandate', 'upi'],
        'total_count' => 120,    // optional default number of billing cycles
    ],
],
```

Then create them in Razorpay:

```bash
php artisan razorpay:sync-plans            # add --dry-run to preview
```

The command creates missing plans and tags each one with a `plan_key` note, so running it again (or after a cache clear) never creates duplicates. Razorpay plans are immutable. If you change a plan's amount, currency or billing period, the command reports a **conflict** and exits non-zero. To change a price, add a new plan key instead.

## Billable models

```php
use Shasha1589\RazorpayBilling\Billable;

class Organization extends Model
{
    use Billable;
}
```

Customers:

```php
$org->createOrGetRazorpayCustomer();         // reuses a matching Razorpay customer
$org->updateRazorpayCustomer(['name' => 'Acme Pvt Ltd']);
```

Name, email and phone come from the `name`, `email` and `phone` attributes. Override `razorpayName()`, `razorpayEmail()` and `razorpayPhone()` to change that. `billing_gstin` is sent as the customer's GSTIN when set.

## Subscribing

```php
$checkout = $org->newSubscription('default', 'pro-monthly')   // plan key or a Razorpay plan_ ID
    ->quantity(1)
    ->trialDays(14)            // first charge after 14 days (Razorpay start_at)
    ->totalCount(120)          // billing cycles; required unless the plan sets total_count
    ->methods(['card', 'emandate'])
    ->notes(['source' => 'pricing-page'])
    ->notifyCustomer(false)
    ->create();
```

`create()` creates the Razorpay subscription and a local `Subscription` in `created` status, then returns a `Checkout`.

**UPI AutoPay limit:** a UPI-only checkout above `upi_autopay_max_amount` (plan amount × quantity) throws `SubscriptionCreationFailed`. When other methods are allowed too, UPI is dropped from the checkout instead.

**Payment methods:** Checkout can hide `card` and `upi`. eMandate cannot be hidden from Checkout options; enable or disable it in the Razorpay Dashboard's Subscriptions settings.

### Checkout in the browser

```bash
php artisan vendor:publish --tag=razorpay-billing-js
```

```blade
<meta name="csrf-token" content="{{ csrf_token() }}">
<script src="/vendor/razorpay-billing/razorpay-checkout.js"></script>
<script>
    RazorpayBilling.checkout(@json($checkout), { onPaymentFailed: (error) => console.warn(error) })
        .then((result) => { /* result.subscription.status, result.subscription.valid */ })
        .catch((error) => { if (! error.dismissed) alert(error.message); });
</script>
```

The helper loads Razorpay Checkout, opens it, and posts the result to `POST /razorpay/verify`. That route checks the signature (HMAC-SHA256 of `payment_id|subscription_id` for subscriptions, `order_id|payment_id` for one-time payments, with your key secret, compared in constant time) and refreshes the subscription or payment from Razorpay. It is only for instant UI feedback; webhooks remain the source of truth.

With Inertia, pass `$checkout` as a prop. It serialises to `{ options, verify_url, short_url }`. `$checkout->shortUrl()` is a Razorpay-hosted authorisation link you can email instead.

### Checking status

```php
$org->subscribed();                         // valid default subscription
$org->subscribed('default', 'pro-monthly'); // ...on a given plan key or plan ID
$org->onTrial();
$org->subscription()->pastDue();            // also: active, onTrial, halted, paused, cancelled, onGracePeriod, ended, valid
Subscription::query()->active()->get();     // scopes: active, onTrial, cancelled, pastDue, halted
```

## Managing subscriptions

```php
$subscription = $org->subscription();

$subscription->swap('pro-yearly');               // at cycle end (default)
$subscription->swap('pro-yearly', 'now');        // immediately
$subscription->updateQuantity(5, 'now');
$subscription->incrementQuantity();              // also decrementQuantity(); never below 1
$subscription->cancel();                         // at cycle end; valid until ends_at (onGracePeriod())
$subscription->cancelNow();
$subscription->pause();                          // active subscriptions only
$subscription->resume();                         // paused subscriptions only
$subscription->addOn(30000, 'Extra seat');       // one-time charge on the next invoice (paise)
$subscription->syncFromRazorpay();
```

Razorpay limits to be aware of:

- **Swap and quantity** work only on `authenticated` or `active` subscriptions, and never on subscriptions paid by **UPI or eMandate**. For those, `cancel()` and create a new subscription. These cases throw `SubscriptionUpdateFailure` before calling Razorpay.
- A cycle-end swap keeps the current plan locally until Razorpay applies it and sends `subscription.updated`.
- **Pause and resume** must be enabled on your Razorpay account. Pausing an `authenticated` subscription would cancel it in Razorpay, so `pause()` refuses.
- **Cancellation can't be undone.** `cancel()` on a subscription that hasn't started billing (created, authenticated or on trial) cancels immediately.
- Each method applies Razorpay's response straight away and fires the matching event (`SubscriptionUpdated`, `SubscriptionCancelled`, ...). The webhook that follows doesn't fire it again.

## One-time payments and refunds

```php
$checkout = $org->charge(49900, [            // ₹499 in paise; at least 100
    'receipt' => 'order-1042',               // optional, max 40 chars, unique
    'notes' => ['sku' => 'ebook'],           // optional, up to 13
    'description' => 'E-book',
]);
```

`charge()` creates a Razorpay order and returns a `Checkout`. Open it with the same JS helper as subscriptions. The verify route checks the order signature (HMAC-SHA256 of `order_id|payment_id`) and stores the `Payment`. The `payment.captured` webhook stores it too if the browser never comes back.

Payments are linked to the billable through the notes on the **order** your server created, fetched from Razorpay. Notes on the payment itself are ignored, because they come from the browser and can be tampered with. Keep auto-capture enabled in the Razorpay Dashboard. Payments left `authorized` aren't captured by this package.

```php
foreach ($org->payments as $payment) { /* status, amount, amount_refunded, method, ... */ }

$payment->refund();                          // full refund
$payment->refund(10000, ['reason' => 'damaged']); // partial, in paise
$payment->refunded();                        // fully refunded
$payment->partiallyRefunded();
```

`refund()` only works on captured payments and never more than the refundable amount (otherwise `RefundFailure`). It returns Razorpay's refund entity. A refund that Razorpay processes immediately updates `amount_refunded` and fires `RefundProcessed` right away. A pending one is applied when the `refund.processed` webhook arrives. Either way the event fires once.

## GST invoices (optional)

> **Important:** this module does arithmetic, not tax advice. Confirm the invoice format, rates, place-of-supply rules and export treatment with a chartered accountant before you issue invoices.

```php
// config/razorpay-billing.php
'invoices' => [
    'enabled' => true,
    'seller' => ['legal_name' => 'Acme Pvt Ltd', 'gstin' => '29ABCDE1234F1Z5', 'address' => '...', 'state_code' => '29'],
    'sac_code' => '998314',
    'gst_rate' => 18,
    'prices_include_tax' => false,
    'lut_reference' => 'AD290000000000X',
],
```

When a payment is captured (a subscription charge or a `charge()` payment), the package creates an `Invoice` and fires `GstInvoiceGenerated` (`$billable`, `$invoice`, `$payment`). Each payment gets exactly one invoice, however many times its webhooks arrive.

- **Numbers:** `{prefix}/{FY}/{sequence}`, e.g. `INV/2026-27/000123`. The sequence restarts each Indian financial year (April–March, in IST). Numbers are assigned inside the invoice's database transaction with a row lock on `razorpay_invoice_sequences`, so they never skip, even under concurrency.
- **Tax split:** the billable's `billing_state_code` is compared with `seller.state_code`.
  - Same state: CGST + SGST at half the rate each.
  - Different state: IGST at the full rate.
  - `export_state_code` (`96` by default): zero-rated export of services.
  - No buyer state: treated as the seller's state.
  - The buyer's `billing_gstin` is copied onto the invoice.
- **Amounts:** an invoice always splits the amount actually paid. `total` is the payment amount, and `subtotal` is `total ÷ (1 + rate)`, rounded half up. Tax is the remainder, so every paisa is accounted for. With an odd tax amount, SGST gets the extra paisa.
- **`prices_include_tax = false` (default):** your config prices exclude GST. With the module enabled, the package adds GST before sending amounts to Razorpay (`razorpay:sync-plans`, `charge()`, and the UPI limit check). For example, a ₹19,999 plan is charged at ₹23,598.82. Export customers pay the same gross amount; their invoice is zero-rated.
- Changing `prices_include_tax`, `gst_rate` or `enabled` changes plan amounts. `razorpay:sync-plans` then reports conflicts, so add new plan keys.
- Refunds don't create credit notes. Handle those in your app (listen for `RefundProcessed`).

```php
$org->invoices;            // local GST invoices (Invoice models)
$invoice->number;          // INV/2026-27/000001
$invoice->subtotal; $invoice->cgst; $invoice->sgst; $invoice->igst; $invoice->total; // paise
$invoice->isExport(); $invoice->isIntraState(); $invoice->tax();

$org->razorpayInvoices();  // Razorpay's own invoices for the org's subscriptions (array entities)
```

### PDF rendering

The package ships no PDF library. Implement `InvoiceRenderer` and set `invoices.renderer`. For example, with [`barryvdh/laravel-dompdf`](https://github.com/barryvdh/laravel-dompdf):

```php
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;
use Shasha1589\RazorpayBilling\Invoices\InvoiceRenderer;
use Shasha1589\RazorpayBilling\Models\Invoice;

class PdfInvoiceRenderer implements InvoiceRenderer
{
    public function render(Invoice $invoice): ?string
    {
        $path = 'invoices/'.str_replace('/', '-', $invoice->number).'.pdf';

        Storage::put($path, Pdf::loadView('invoices.gst', [
            'invoice' => $invoice,
            'seller' => config('razorpay-billing.invoices.seller'),
            'lut' => config('razorpay-billing.invoices.lut_reference'),
        ])->output());

        return $path;   // saved as $invoice->pdf_path
    }
}
```

## Scheduled commands

```php
// routes/console.php
use Illuminate\Support\Facades\Schedule;

Schedule::command('razorpay:sync')->hourly();              // reconcile in case webhooks were missed
Schedule::command('razorpay:upcoming-charges')->hourly();  // pre-debit reminders
```

- `razorpay:sync {--subscription=}` refreshes every subscription that hasn't ended (or just one) from Razorpay.
- `razorpay:upcoming-charges` fires `UpcomingCharge` once per charge, `upcoming_charge_notice_hours` (default 48) before `charge_at`. The event carries `$billable`, `$subscription`, `$chargeAt` and `$amount` (plan amount × quantity in paise, or `null` if the plan isn't in config). Use it to send RBI pre-debit notifications if Razorpay doesn't send them for your flow. Sent reminders are tracked in the cache (`plan_cache_store`).

## Webhooks

Webhooks are the source of truth for subscription and payment state.

1. In the Razorpay Dashboard (Account & Settings → Webhooks), add `https://your-app.com/razorpay/webhook`, set a secret, and select the events below.
2. Put the secret in `RAZORPAY_WEBHOOK_SECRET`.
3. Run a queue worker. Webhooks are stored and processed by a queued job, so Razorpay gets a fast `200`.

The webhook route is registered outside the `web` middleware group, so CSRF doesn't apply to it. If you register it inside `web` yourself, exclude it from CSRF verification. In Laravel 12/13 `bootstrap/app.php`:

```php
->withMiddleware(function (Middleware $middleware) {
    $middleware->validateCsrfTokens(except: ['razorpay/webhook']);
})
```

How webhooks are processed:

- **Signature:** HMAC-SHA256 of the raw request body with the webhook secret, compared in constant time with `X-Razorpay-Signature`. Requests that don't match get a `403`.
- **Idempotency:** every event is stored in `razorpay_webhook_events` keyed by `X-Razorpay-Event-Id`. A redelivered event that was already processed is acknowledged and skipped.
- **Ordering:** a subscription event older than the state already applied is ignored. Cancelled, completed and expired subscriptions are never revived. Payments only move forward (a late `payment.failed` can't undo a capture), and refunded amounts never decrease.
- **Events fire once per real change**, whether the change arrived by webhook or by the checkout verify route.
- If no local subscription exists, it's created from the billable notes added at checkout.
- `php artisan razorpay:webhook-replay {event_id}` reprocesses a stored event.

### Events

Every event carries `$billable`, the affected model (`$subscription` or `$payment`) and the raw `$payload`.

| Webhook | Local change | Event |
| --- | --- | --- |
| `subscription.authenticated` | status → authenticated | `SubscriptionAuthenticated` |
| `subscription.activated` | status → active | `SubscriptionActivated` |
| `subscription.charged` | periods updated, payment stored | `SubscriptionCharged` (`$payment` too) |
| `subscription.pending` | status → pending | `SubscriptionPastDue` |
| `subscription.halted` | status → halted | `SubscriptionHalted` |
| `subscription.paused` / `.resumed` | status → paused / active | `SubscriptionPaused` / `SubscriptionResumed` |
| `subscription.cancelled` | status → cancelled, `ends_at` set | `SubscriptionCancelled` |
| `subscription.completed` | status → completed | `SubscriptionCompleted` |
| `subscription.updated` | plan, quantity | `SubscriptionUpdated` |
| `payment.captured` / `payment.failed` | payment stored | `PaymentCaptured` / `PaymentFailed` |
| `refund.processed` | `amount_refunded` updated | `RefundProcessed` |
| `invoice.paid` / `invoice.expired` | none (Razorpay invoices aren't stored) | `InvoicePaid` / `InvoiceExpired` (`$invoice` entity; `$billable`/`$subscription` may be null) |
| `token.cancelled` / `token.paused` | `mandate_status` | `MandateCancelled` / `MandatePaused` |
| any event | — | `WebhookReceived` |

All events live in `Shasha1589\RazorpayBilling\Events`. Model events are dispatched after the database transaction commits.

**Mandate events:** Razorpay's token webhooks don't reference a subscription. The package links a token to a subscription using the `token_id` of that subscription's charged payments. A token cancelled before any charge was recorded only fires `WebhookReceived`.

## Example: Blade

```php
// routes/web.php
use Illuminate\Http\Request;

Route::middleware('auth')->group(function () {
    Route::get('/billing', fn (Request $request) => view('billing', [
        'subscription' => $request->user()->subscription(),
    ]));

    Route::post('/billing/subscribe', function (Request $request) {
        $checkout = $request->user()
            ->newSubscription('default', $request->validate(['plan' => 'required|in:pro-monthly,pro-yearly'])['plan'])
            ->trialDays(14)
            ->create()
            ->description('Pro plan');

        return view('checkout', ['checkout' => $checkout]);
    });
});
```

```blade
{{-- resources/views/checkout.blade.php --}}
<meta name="csrf-token" content="{{ csrf_token() }}">
<script src="{{ asset('vendor/razorpay-billing/razorpay-checkout.js') }}"></script>

<button id="pay">Authorise subscription</button>

<script>
    document.getElementById('pay').addEventListener('click', () => {
        RazorpayBilling.checkout(@json($checkout))
            .then(() => window.location = '/billing?welcome=1')
            .catch((error) => { if (! error.dismissed) alert(error.message); });
    });
</script>
```

```php
// Gate features on the subscription.
Route::middleware(['auth', function ($request, $next) {
    abort_unless($request->user()->subscribed(), 402);

    return $next($request);
}])->group(fn () => Route::get('/reports', ReportsController::class));
```

## Example: Inertia + React

```php
// Controller
return Inertia::render('Billing/Checkout', [
    'checkout' => $request->user()->newSubscription('default', 'pro-monthly')->create(),
]);
```

```jsx
// resources/js/Pages/Billing/Checkout.jsx
import { router } from '@inertiajs/react';

// Load the helper once in app.blade.php:
// <script src="{{ asset('vendor/razorpay-billing/razorpay-checkout.js') }}"></script>

export default function Checkout({ checkout }) {
    const pay = () =>
        window.RazorpayBilling.checkout(checkout, {
            onPaymentFailed: (error) => console.warn(error.description),
        })
            .then(() => router.visit('/billing'))
            .catch((error) => { if (! error.dismissed) alert(error.message); });

    return <button onClick={pay}>Authorise subscription</button>;
}
```

The verify route uses the `web` middleware group by default, so it needs the CSRF token. Inertia apps usually have a `<meta name="csrf-token">` tag in `app.blade.php`, and the helper reads it.

One-time payments work the same way: pass `$request->user()->charge(49900)` as the `checkout` prop.

## Testing your app

The package uses Laravel's HTTP client, so you can fake Razorpay in your own tests:

```php
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Shasha1589\RazorpayBilling\Events\SubscriptionActivated;

Http::fake([
    'api.razorpay.com/v1/subscriptions' => Http::response(['id' => 'sub_test1234567890', 'status' => 'created', 'short_url' => null]),
]);
Event::fake([SubscriptionActivated::class]);

$checkout = $user->newSubscription('default', 'plan_00000000000001')->totalCount(12)->methods(['card'])->create();
```

To test your webhook listeners, post a signed payload:

```php
$body = json_encode($payload);

$this->call('POST', '/razorpay/webhook', server: [
    'CONTENT_TYPE' => 'application/json',
    'HTTP_X_RAZORPAY_SIGNATURE' => hash_hmac('sha256', $body, config('razorpay-billing.webhook_secret')),
    'HTTP_X_RAZORPAY_EVENT_ID' => 'evt_test_1',
], content: $body)->assertOk();
```

Use Razorpay **test mode** keys (`rzp_test_...`) outside production. Razorpay's test mode can simulate successful and failed subscription payments.

## Razorpay API client

`Shasha1589\RazorpayBilling\Client\RazorpayClient` is a thin wrapper over Laravel's HTTP client, so `Http::fake()` works in your tests:

```php
$client = app(RazorpayClient::class);

$client->customers()->create(['name' => 'Gaurav Kumar', 'email' => 'gaurav@example.com']);
$client->plans()->fetch('plan_00000000000001');
$client->get('subscriptions/sub_123');   // any endpoint
```

Errors throw subclasses of `Client\Exceptions\RazorpayException`:

| Exception | When |
| --- | --- |
| `BadRequestException` | 400. `$e->errorCode`, `field`, `reason`, `source`, `step` and `metadata` carry Razorpay's error details. |
| `AuthenticationException` | 401: wrong API keys. |
| `NotFoundException` | 404 |
| `RateLimitException` | 429, after retries. |
| `ServerException` | 5xx |
| `ApiException` | Any other non-2xx (parent of the above). |
| `ConnectionException` | Network failure. |
| `MissingCredentialsException` | Keys not configured. |

## Limitations

- **INR-first.** Amounts are integer paise. Other currencies are passed through but untested, and the GST module assumes India.
- **Updates on UPI and eMandate subscriptions** (swap, quantity) aren't supported by Razorpay. Cancel and resubscribe instead.
- **eMandate can't be hidden** through Checkout options; control it in the Razorpay Dashboard.
- **Pause and resume** must be enabled on your Razorpay account.
- **Manual capture** isn't handled. Keep auto-capture on, or capture payments yourself.
- **Mandate (token) events** can only be linked to a subscription after one of its charges has been recorded.
- **Razorpay invoices aren't stored locally;** `invoice.*` webhooks only fire events.
- **GST:** no credit notes on refunds, no e-invoicing (IRN), and no tax advice. Confirm everything with a chartered accountant.
- **Not in v1:** Razorpay Route (split payments), payouts, payment links, Smart Collect, and UI components beyond the checkout helper.

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md).

```bash
composer test                # Pest (Unit + Feature, no real API calls)
composer analyse             # Larastan, level max
composer lint                # Pint
composer test:integration    # opt-in, real Razorpay TEST mode
```

The integration suite runs only when `RAZORPAY_TEST_KEY_ID` (`rzp_test_...`) and `RAZORPAY_TEST_KEY_SECRET` are set. It never runs in CI.

## Upgrading

See [UPGRADE.md](UPGRADE.md) and [CHANGELOG.md](CHANGELOG.md).

## Security

See [SECURITY.md](SECURITY.md).

See [SECURITY.md](SECURITY.md).

## License

MIT. See [LICENSE](LICENSE).
