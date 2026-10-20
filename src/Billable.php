<?php

declare(strict_types=1);

namespace Shasha1589\RazorpayBilling;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Shasha1589\RazorpayBilling\Builders\OrderBuilder;
use Shasha1589\RazorpayBilling\Builders\SubscriptionBuilder;
use Shasha1589\RazorpayBilling\Client\RazorpayClient;
use Shasha1589\RazorpayBilling\Exceptions\CustomerAlreadyCreated;
use Shasha1589\RazorpayBilling\Exceptions\InvalidCustomer;
use Shasha1589\RazorpayBilling\Models\Invoice;
use Shasha1589\RazorpayBilling\Models\Payment;
use Shasha1589\RazorpayBilling\Models\Subscription;

/**
 * Add to any Eloquent model that pays: a User, Team, Organization, ...
 *
 * @mixin Model
 */
trait Billable
{
    public function razorpayId(): ?string
    {
        $id = $this->getAttribute('razorpay_customer_id');

        return is_string($id) && $id !== '' ? $id : null;
    }

    public function hasRazorpayId(): bool
    {
        return $this->razorpayId() !== null;
    }

    /**
     * Creates a Razorpay customer and stores its ID. If Razorpay already has a
     * customer with the same details, that customer is reused.
     *
     * @see https://razorpay.com/docs/api/customers/create
     *
     * @param  array{name?: string, email?: string, contact?: string, gstin?: string, fail_existing?: '0'|'1', notes?: array<string, string>}  $options
     */
    public function createAsRazorpayCustomer(array $options = []): string
    {
        $existing = $this->razorpayId();

        if ($existing !== null) {
            throw CustomerAlreadyCreated::exists($this, $existing);
        }

        $gstin = $this->getAttribute('billing_gstin');

        $customer = app(RazorpayClient::class)->customers()->create([
            ...array_filter([
                'name' => $this->razorpayName(),
                'email' => $this->razorpayEmail(),
                'contact' => $this->razorpayPhone(),
                'gstin' => is_string($gstin) && $gstin !== '' ? $gstin : null,
            ], fn ($value) => $value !== null),
            'fail_existing' => '0',
            ...$options,
        ]);

        $id = $customer['id'] ?? null;

        if (! is_string($id)) {
            throw new Client\Exceptions\UnexpectedResponseException('Razorpay returned a customer without an id.');
        }

        $this->forceFill(['razorpay_customer_id' => $id])->save();

        return $id;
    }

    /**
     * @param  array{name?: string, email?: string, contact?: string, gstin?: string, fail_existing?: '0'|'1', notes?: array<string, string>}  $options
     */
    public function createOrGetRazorpayCustomer(array $options = []): string
    {
        return $this->razorpayId() ?? $this->createAsRazorpayCustomer($options);
    }

    /**
     * @see https://razorpay.com/docs/api/customers/edit
     *
     * @param  array{name?: string, email?: string, contact?: string}  $options
     */
    public function updateRazorpayCustomer(array $options): void
    {
        $id = $this->razorpayId() ?? throw InvalidCustomer::notYetCreated($this);

        app(RazorpayClient::class)->customers()->update($id, $options);
    }

    /**
     * @see https://razorpay.com/docs/api/customers/fetch-with-id
     *
     * @return array<string, mixed>
     */
    public function asRazorpayCustomer(): array
    {
        $id = $this->razorpayId() ?? throw InvalidCustomer::notYetCreated($this);

        return app(RazorpayClient::class)->customers()->fetch($id);
    }

    public function razorpayName(): ?string
    {
        $name = $this->getAttribute('name');

        return is_string($name) && $name !== '' ? $name : null;
    }

    public function razorpayEmail(): ?string
    {
        $email = $this->getAttribute('email');

        return is_string($email) && $email !== '' ? $email : null;
    }

    public function razorpayPhone(): ?string
    {
        $phone = $this->getAttribute('phone');

        return is_string($phone) && $phone !== '' ? $phone : null;
    }

    /**
     * @param  string  $plan  A plan key from config or a Razorpay plan ID (plan_...).
     */
    public function newSubscription(string $type, string $plan): SubscriptionBuilder
    {
        return new SubscriptionBuilder($this, $type, $plan, $this->razorpayPrefill());
    }

    /**
     * Creates a Razorpay order for a one-time payment and returns the Checkout
     * to collect it. The Payment is stored when Razorpay confirms it.
     *
     * @param  int  $amount  In paise; at least 100.
     * @param  array{currency?: string, receipt?: string, notes?: array<string, string>, description?: string}  $options
     */
    public function charge(int $amount, array $options = []): Checkout
    {
        return (new OrderBuilder($this, $this->razorpayPrefill()))->create($amount, $options);
    }

    /**
     * @return MorphMany<Payment, $this>
     */
    public function payments(): MorphMany
    {
        return $this->morphMany(Payment::class, 'billable')->orderByDesc('id');
    }

    /**
     * Local GST invoices (when the invoice module is enabled).
     *
     * @return MorphMany<Invoice, $this>
     */
    public function invoices(): MorphMany
    {
        return $this->morphMany(Invoice::class, 'billable')->orderByDesc('id');
    }

    /**
     * Razorpay's own invoices for this billable's subscriptions, newest first.
     *
     * @see https://razorpay.com/docs/api/payments/subscriptions/fetch-invoices
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function razorpayInvoices(): Collection
    {
        $client = app(RazorpayClient::class);
        $invoices = [];

        foreach ($this->subscriptions()->get() as $subscription) {
            $items = $client->invoices()->forSubscription($subscription->razorpay_id)['items'] ?? [];

            foreach (is_array($items) ? $items : [] as $item) {
                if (is_array($item)) {
                    /** @var array<string, mixed> $item */
                    $invoices[] = $item;
                }
            }
        }

        return Collection::make($invoices)
            ->sortByDesc(fn (array $invoice) => is_int($invoice['created_at'] ?? null) ? $invoice['created_at'] : 0)
            ->values();
    }

    /**
     * @return array{name?: string, email?: string, contact?: string}
     */
    protected function razorpayPrefill(): array
    {
        return array_filter([
            'name' => $this->razorpayName(),
            'email' => $this->razorpayEmail(),
            'contact' => $this->razorpayPhone(),
        ], fn ($value) => $value !== null);
    }

    /**
     * @return MorphMany<Subscription, $this>
     */
    public function subscriptions(): MorphMany
    {
        return $this->morphMany(Subscription::class, 'billable')->orderByDesc('id');
    }

    /** The most recent subscription of a type. */
    public function subscription(string $type = 'default'): ?Subscription
    {
        return $this->subscriptions()->where('type', $type)->first();
    }

    /**
     * @param  string|null  $plan  Plan key or Razorpay plan ID.
     */
    public function subscribed(string $type = 'default', ?string $plan = null): bool
    {
        $subscription = $this->subscription($type);

        return $subscription !== null
            && $subscription->valid()
            && ($plan === null || $subscription->hasPlan($plan));
    }

    public function onTrial(string $type = 'default'): bool
    {
        if (func_num_args() === 0 && $this->onGenericTrial()) {
            return true;
        }

        return $this->subscription($type)?->onTrial() ?? false;
    }

    /**
     * A trial without a subscription, read from a `trial_ends_at` attribute
     * on the billable model if your app adds one.
     */
    public function onGenericTrial(): bool
    {
        $endsAt = $this->getAttribute('trial_ends_at');

        if (is_string($endsAt)) {
            $endsAt = Carbon::parse($endsAt);
        }

        return $endsAt instanceof \DateTimeInterface && Carbon::instance($endsAt)->isFuture();
    }
}
