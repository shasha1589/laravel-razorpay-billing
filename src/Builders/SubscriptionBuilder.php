<?php

declare(strict_types=1);

namespace Shasha1589\RazorpayBilling\Builders;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Shasha1589\RazorpayBilling\Checkout;
use Shasha1589\RazorpayBilling\Client\RazorpayClient;
use Shasha1589\RazorpayBilling\Enums\SubscriptionStatus;
use Shasha1589\RazorpayBilling\Exceptions\SubscriptionCreationFailed;
use Shasha1589\RazorpayBilling\Models\Subscription;
use Shasha1589\RazorpayBilling\Plans\PlanDefinition;
use Shasha1589\RazorpayBilling\Plans\PlanRepository;

class SubscriptionBuilder
{
    /** Razorpay allows 15 notes; three identify the billable for webhooks. */
    public const MAX_NOTES = 12;

    private int $quantity = 1;

    private ?Carbon $trialEndsAt = null;

    private ?int $totalCount = null;

    /** @var list<string>|null */
    private ?array $methods = null;

    /** @var array<string, string> */
    private array $notes = [];

    private bool $notifyCustomer = true;

    private ?Carbon $expireBy = null;

    /**
     * @param  string  $plan  A plan key from config or a Razorpay plan ID (plan_...).
     * @param  array{name?: string, email?: string, contact?: string}  $prefill
     */
    public function __construct(
        private readonly Model $owner,
        private readonly string $type,
        private readonly string $plan,
        private readonly array $prefill = [],
    ) {}

    public function quantity(int $quantity): self
    {
        if ($quantity < 1) {
            throw new \InvalidArgumentException('Quantity must be at least 1.');
        }

        $this->quantity = $quantity;

        return $this;
    }

    /** Delays the first charge; Razorpay's start_at. */
    public function trialDays(int $days): self
    {
        return $this->trialUntil(Carbon::now()->addDays($days));
    }

    public function trialUntil(DateTimeInterface $date): self
    {
        $this->trialEndsAt = Carbon::instance($date);

        return $this;
    }

    /** Number of billing cycles to charge. */
    public function totalCount(int $count): self
    {
        if ($count < 1) {
            throw new \InvalidArgumentException('Total count must be at least 1.');
        }

        $this->totalCount = $count;

        return $this;
    }

    /**
     * @param  list<string>  $methods  Any of card, emandate, upi.
     */
    public function methods(array $methods): self
    {
        $this->methods = array_values(array_unique($methods));

        return $this;
    }

    /**
     * @param  array<string, string>  $notes
     */
    public function notes(array $notes): self
    {
        if (count($notes) > self::MAX_NOTES) {
            throw SubscriptionCreationFailed::tooManyNotes(self::MAX_NOTES);
        }

        $this->notes = $notes;

        return $this;
    }

    /** Whether Razorpay emails/SMSes the customer (default) or you do. */
    public function notifyCustomer(bool $notify = true): self
    {
        $this->notifyCustomer = $notify;

        return $this;
    }

    /** Deadline for the customer to complete the authorisation payment. */
    public function expireBy(DateTimeInterface $date): self
    {
        $this->expireBy = Carbon::instance($date);

        return $this;
    }

    /**
     * Creates the Razorpay subscription and a local row in `created` status.
     *
     * @see https://razorpay.com/docs/api/payments/subscriptions/create-subscription
     */
    public function create(): Checkout
    {
        $client = app(RazorpayClient::class);
        [$planId, $definition] = $this->resolvePlan();

        $totalCount = $this->totalCount ?? $definition->totalCount ?? throw SubscriptionCreationFailed::totalCountMissing();
        $methods = $this->resolveMethods($client, $planId, $definition);

        $payload = [
            'plan_id' => $planId,
            'total_count' => $totalCount,
            'quantity' => $this->quantity,
            'customer_notify' => $this->notifyCustomer,
            'notes' => [
                ...$this->notes,
                'billable_type' => $this->owner->getMorphClass(),
                'billable_id' => $this->ownerKey(),
                'subscription_type' => $this->type,
            ],
        ];

        if ($this->trialEndsAt !== null) {
            $payload['start_at'] = $this->trialEndsAt->getTimestamp();
        }

        if ($this->expireBy !== null) {
            $payload['expire_by'] = $this->expireBy->getTimestamp();
        }

        $response = $client->subscriptions()->create($payload);

        $subscription = new Subscription([
            'type' => $this->type,
            'razorpay_id' => $response['id'] ?? null,
            'razorpay_plan_id' => $planId,
            'plan_key' => $definition?->key,
            'status' => SubscriptionStatus::Created,
            'quantity' => $this->quantity,
            'trial_ends_at' => $this->trialEndsAt,
            'notes' => $this->notes === [] ? null : $this->notes,
        ]);
        $subscription->billable()->associate($this->owner);
        $subscription->fillFromRazorpay($response)->save();

        return new Checkout(
            keyId: $client->keyId(),
            subscriptionId: $subscription->razorpay_id,
            prefill: $this->prefill,
            methods: $methods,
            shortUrl: is_string($response['short_url'] ?? null) ? $response['short_url'] : null,
        );
    }

    private function ownerKey(): string
    {
        $key = $this->owner->getKey();

        if (! is_int($key) && ! is_string($key)) {
            throw new \LogicException('The billable model must be saved before subscribing.');
        }

        return (string) $key;
    }

    /**
     * @return array{0: string, 1: PlanDefinition|null}
     */
    private function resolvePlan(): array
    {
        if (str_starts_with($this->plan, 'plan_')) {
            return [$this->plan, null];
        }

        $plans = app(PlanRepository::class);
        $definition = $plans->definition($this->plan);

        return [$plans->razorpayId($this->plan) ?? throw SubscriptionCreationFailed::planNotSynced($this->plan), $definition];
    }

    /**
     * Applies the UPI AutoPay limit: a UPI-only checkout above it is refused;
     * otherwise UPI is dropped from the offered methods.
     *
     * @return list<string>
     */
    private function resolveMethods(RazorpayClient $client, string $planId, ?PlanDefinition $definition): array
    {
        $allowed = $definition === null ? PlanDefinition::METHODS : $definition->methods;
        $methods = $this->methods ?? $allowed;

        $invalid = array_values(array_diff($methods, $allowed));

        if ($methods === [] || $invalid !== []) {
            throw SubscriptionCreationFailed::methodsNotAllowed($invalid);
        }

        if (! in_array('upi', $methods, true)) {
            return $methods;
        }

        $limit = config('razorpay-billing.upi_autopay_max_amount');
        $amount = $this->chargeAmount($client, $planId, $definition);

        if (! is_int($limit) || $amount <= $limit) {
            return $methods;
        }

        if ($methods === ['upi']) {
            throw SubscriptionCreationFailed::upiLimitExceeded($amount, $limit);
        }

        return array_values(array_diff($methods, ['upi']));
    }

    /**
     * Amount of each recurring charge in paise.
     *
     * @see https://razorpay.com/docs/api/payments/subscriptions/fetch-a-plan
     */
    private function chargeAmount(RazorpayClient $client, string $planId, ?PlanDefinition $definition): int
    {
        if ($definition !== null) {
            return $definition->chargeAmount * $this->quantity;
        }

        $item = $client->plans()->fetch($planId)['item'] ?? null;
        $amount = is_array($item) ? ($item['amount'] ?? null) : null;

        return is_int($amount) ? $amount * $this->quantity : 0;
    }
}
