<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Shasha1589\RazorpayBilling\Enums\SubscriptionStatus;
use Shasha1589\RazorpayBilling\Events\GstInvoiceGenerated;
use Shasha1589\RazorpayBilling\Exceptions\SubscriptionCreationFailed;
use Shasha1589\RazorpayBilling\Invoices\InvoiceRenderer;
use Shasha1589\RazorpayBilling\Models\Invoice;
use Shasha1589\RazorpayBilling\Models\Subscription;
use Shasha1589\RazorpayBilling\Tests\Support\User;

beforeEach(function () {
    Carbon::setTestNow('2026-10-20 10:00:00');
    config([
        'razorpay-billing.invoices.enabled' => true,
        'razorpay-billing.invoices.seller.state_code' => '29',
    ]);

    $this->user = User::create(['name' => 'Gaurav Kumar']);
    $this->subscription = new Subscription([
        'type' => 'default',
        'razorpay_id' => 'sub_00000000000001',
        'razorpay_plan_id' => 'plan_00000000000001',
        'status' => SubscriptionStatus::Active,
    ]);
    $this->subscription->billable()->associate($this->user)->save();
});

it('generates a GST invoice for a subscription charge', function () {
    Event::fake([GstInvoiceGenerated::class]);
    $this->user->forceFill(['billing_state_code' => '27', 'billing_gstin' => '27ABCDE1234F1Z5'])->save();

    $this->postWebhook(fixture('webhooks/subscription.charged'))->assertOk();

    $invoice = $this->user->invoices()->sole();
    expect($invoice)
        ->number->toBe('INV/2026-27/000001')
        ->financial_year->toBe('2026-27')
        ->razorpay_payment_id->toBe('pay_DEXFWroJ6LikKT')
        ->total->toBe(100000)
        ->subtotal->toBe(84746)
        ->igst->toBe(15254)
        ->cgst->toBe(0)
        ->place_of_supply->toBe('27')
        ->buyer_gstin->toBe('27ABCDE1234F1Z5')
        ->and($invoice->payment->amount)->toBe(100000);
    Event::assertDispatched(GstInvoiceGenerated::class, fn ($e) => $e->invoice->is($invoice) && $e->billable->is($this->user));
});

it('generates one invoice per payment however often the charge is delivered', function () {
    $this->postWebhook(fixture('webhooks/subscription.charged'), 'evt_1')->assertOk();
    $this->postWebhook(fixture('webhooks/subscription.charged'), 'evt_2')->assertOk();
    $this->postWebhook(fixture('webhooks/payment.captured', ['payload' => ['payment' => ['entity' => ['id' => 'pay_DEXFWroJ6LikKT']]]]), 'evt_3')->assertOk();

    expect(Invoice::query()->count())->toBe(1);
});

it('numbers invoices consecutively', function () {
    $this->postWebhook(fixture('webhooks/subscription.charged'))->assertOk();
    $this->postWebhook(fixture('webhooks/subscription.charged', ['payload' => ['payment' => ['entity' => ['id' => 'pay_SECOND']]]]))->assertOk();

    expect(Invoice::query()->orderBy('id')->pluck('number')->all())->toBe(['INV/2026-27/000001', 'INV/2026-27/000002']);
});

it('generates an export invoice for a foreign customer', function () {
    config(['razorpay-billing.invoices.lut_reference' => 'AD290000000000X']);
    $this->user->forceFill(['billing_state_code' => '96'])->save();

    $this->postWebhook(fixture('webhooks/subscription.charged'))->assertOk();

    $invoice = Invoice::query()->sole();
    expect($invoice->isExport())->toBeTrue()
        ->and($invoice->tax())->toBe(0)
        ->and($invoice->subtotal)->toBe(100000);
});

it('generates an intra-state invoice when the buyer is in the seller state', function () {
    $this->postWebhook(fixture('webhooks/subscription.charged'))->assertOk();

    $invoice = Invoice::query()->sole();
    expect($invoice->isIntraState())->toBeTrue()
        ->and([$invoice->cgst, $invoice->sgst])->toBe([7627, 7627]);
});

it('generates invoices for one-time payments', function () {
    Http::fake(['api.razorpay.com/v1/orders/*' => Http::response(fixture('orders/order', ['notes' => ['billable_type' => User::class, 'billable_id' => (string) $this->user->id]]))]);

    $this->postWebhook(fixture('webhooks/payment.captured'))->assertOk();

    expect(Invoice::query()->sole()->razorpay_payment_id)->toBe('pay_DESlfW9H8K9uqM');
});

it('does nothing when the module is disabled', function () {
    config(['razorpay-billing.invoices.enabled' => false]);

    $this->postWebhook(fixture('webhooks/subscription.charged'))->assertOk();

    expect(Invoice::query()->count())->toBe(0);
});

it('does not invoice failed payments', function () {
    Http::fake(['api.razorpay.com/v1/orders/*' => Http::response(fixture('orders/order', ['notes' => ['billable_type' => User::class, 'billable_id' => (string) $this->user->id]]))]);

    $this->postWebhook(fixture('webhooks/payment.failed'))->assertOk();

    expect(Invoice::query()->count())->toBe(0);
});

it('renders the invoice with the configured renderer', function () {
    app()->bind('test.renderer', fn () => new class implements InvoiceRenderer
    {
        public function render(Invoice $invoice): ?string
        {
            return 'invoices/'.str_replace('/', '-', $invoice->number).'.pdf';
        }
    });
    config(['razorpay-billing.invoices.renderer' => 'test.renderer']);

    $this->postWebhook(fixture('webhooks/subscription.charged'))->assertOk();

    expect(Invoice::query()->sole()->pdf_path)->toBe('invoices/INV-2026-27-000001.pdf');
});

it('adds GST on top of tax-exclusive plan prices when syncing', function () {
    config(['razorpay-billing.plans' => ['pro-monthly' => ['name' => 'Pro', 'period' => 'monthly', 'interval' => 1, 'amount' => 1999900]]]);
    Http::fake(fn (Request $request) => $request->method() === 'GET'
        ? Http::response(['entity' => 'collection', 'count' => 0, 'items' => []])
        : Http::response(fixture('plans/plan', ['id' => 'plan_new'])));

    $this->artisan('razorpay:sync-plans')->assertSuccessful();

    Http::assertSent(fn (Request $request) => $request->method() === 'POST' && $request['item']['amount'] === 2359882);
});

it('sends inclusive prices unchanged', function () {
    config([
        'razorpay-billing.invoices.prices_include_tax' => true,
        'razorpay-billing.plans' => ['pro-monthly' => ['name' => 'Pro', 'period' => 'monthly', 'interval' => 1, 'amount' => 1999900]],
    ]);
    Http::fake(fn (Request $request) => $request->method() === 'GET'
        ? Http::response(['entity' => 'collection', 'count' => 0, 'items' => []])
        : Http::response(fixture('plans/plan', ['id' => 'plan_new'])));

    $this->artisan('razorpay:sync-plans')->assertSuccessful();

    Http::assertSent(fn (Request $request) => $request->method() === 'POST' && $request['item']['amount'] === 1999900);
});

it('adds GST to tax-exclusive one-time charges', function () {
    Http::fake(['api.razorpay.com/v1/orders' => Http::response(fixture('orders/order'))]);

    $checkout = $this->user->charge(10000);

    Http::assertSent(fn (Request $request) => $request['amount'] === 11800);
    expect($checkout->options()['amount'])->toBe(11800);
});

it('applies the UPI limit to the amount including GST', function () {
    // ₹13,000 + 18% = ₹15,340, above the ₹15,000 UPI AutoPay limit.
    config(['razorpay-billing.plans' => ['p' => ['name' => 'P', 'period' => 'monthly', 'interval' => 1, 'amount' => 1300000, 'total_count' => 12]]]);
    Cache::forever('razorpay-billing:plan:p', 'plan_00000000000009');

    $this->user->newSubscription('default', 'p')->methods(['upi'])->create();
})->throws(SubscriptionCreationFailed::class, 'UPI AutoPay');

it('lists Razorpay invoices for the billable subscriptions', function () {
    Http::fake(['api.razorpay.com/v1/invoices*' => Http::response(['entity' => 'collection', 'count' => 2, 'items' => [
        ['id' => 'inv_old', 'created_at' => 1591878130],
        ['id' => 'inv_new', 'created_at' => 1593344888],
    ]])]);

    expect($this->user->razorpayInvoices()->pluck('id')->all())->toBe(['inv_new', 'inv_old']);
    Http::assertSent(fn (Request $request) => $request->url() === 'https://api.razorpay.com/v1/invoices?subscription_id=sub_00000000000001');
});
