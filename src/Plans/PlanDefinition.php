<?php

declare(strict_types=1);

namespace Shasha1589\RazorpayBilling\Plans;

/**
 * A plan as defined in config('razorpay-billing.plans').
 */
final class PlanDefinition
{
    public const METHODS = ['card', 'emandate', 'upi'];

    /** Razorpay allows 15 notes; one is reserved for the plan key. */
    private const MAX_NOTES = 14;

    /** Amount Razorpay charges per cycle in paise: the price, plus GST when prices exclude tax. */
    public readonly int $chargeAmount;

    /**
     * @param  list<string>  $methods
     * @param  array<string, string>  $notes
     */
    public function __construct(
        public readonly string $key,
        public readonly string $name,
        public readonly PlanPeriod $period,
        public readonly int $interval,
        public readonly int $amount,
        public readonly string $currency,
        public readonly ?string $description = null,
        public readonly array $methods = self::METHODS,
        public readonly array $notes = [],
        public readonly ?int $totalCount = null,
        ?int $chargeAmount = null,
    ) {
        $this->chargeAmount = $chargeAmount ?? $amount;

        if ($key === '') {
            throw new InvalidPlanException('Plan key must not be empty.');
        }

        if ($interval < $period->minimumInterval()) {
            throw new InvalidPlanException("Plan [{$key}]: interval for a {$period->value} plan must be at least {$period->minimumInterval()}.");
        }

        if ($amount <= 0) {
            throw new InvalidPlanException("Plan [{$key}]: amount must be a positive integer in paise.");
        }

        if ($methods === [] || array_diff($methods, self::METHODS) !== []) {
            throw new InvalidPlanException("Plan [{$key}]: methods must be a non-empty subset of ".implode(', ', self::METHODS).'.');
        }

        if ($totalCount !== null && $totalCount < 1) {
            throw new InvalidPlanException("Plan [{$key}]: total_count must be at least 1.");
        }

        if (count($notes) > self::MAX_NOTES) {
            throw new InvalidPlanException("Plan [{$key}]: at most ".self::MAX_NOTES.' notes are allowed.');
        }
    }

    /**
     * @param  array<mixed>  $config
     * @param  (\Closure(int): int)|null  $chargeAmount  Maps the price to the amount Razorpay charges.
     */
    public static function fromConfig(string $key, array $config, string $defaultCurrency, ?\Closure $chargeAmount = null): self
    {
        $name = $config['name'] ?? null;
        $period = is_string($config['period'] ?? null) ? PlanPeriod::tryFrom($config['period']) : null;
        $interval = $config['interval'] ?? 1;
        $amount = $config['amount'] ?? null;
        $currency = $config['currency'] ?? $defaultCurrency;
        $description = $config['description'] ?? null;
        $methods = $config['methods'] ?? self::METHODS;
        $notes = $config['notes'] ?? [];
        $totalCount = $config['total_count'] ?? null;

        if (! is_string($name) || $name === '') {
            throw new InvalidPlanException("Plan [{$key}]: name is required.");
        }

        if ($period === null) {
            throw new InvalidPlanException("Plan [{$key}]: period must be one of ".implode(', ', array_column(PlanPeriod::cases(), 'value')).'.');
        }

        if (! is_int($interval)) {
            throw new InvalidPlanException("Plan [{$key}]: interval must be an integer.");
        }

        if (! is_int($amount)) {
            throw new InvalidPlanException("Plan [{$key}]: amount must be an integer in paise.");
        }

        if (! is_string($currency) || ($description !== null && ! is_string($description))) {
            throw new InvalidPlanException("Plan [{$key}]: currency and description must be strings.");
        }

        if (! is_array($methods) || ! array_is_list($methods) || array_filter($methods, 'is_string') !== $methods) {
            throw new InvalidPlanException("Plan [{$key}]: methods must be a list of strings.");
        }

        if (! is_array($notes) || array_filter($notes, 'is_string') !== $notes || array_filter(array_keys($notes), 'is_string') !== array_keys($notes)) {
            throw new InvalidPlanException("Plan [{$key}]: notes must be an array of string keys and values.");
        }

        if ($totalCount !== null && ! is_int($totalCount)) {
            throw new InvalidPlanException("Plan [{$key}]: total_count must be an integer.");
        }

        /** @var list<string> $methods */
        /** @var array<string, string> $notes */
        return new self($key, $name, $period, $interval, $amount, $currency, $description, $methods, $notes, $totalCount, $chargeAmount === null ? null : $chargeAmount($amount));
    }

    /**
     * Request body for the create-plan endpoint.
     *
     * @see https://razorpay.com/docs/api/payments/subscriptions/create-plan
     *
     * @return array{period: string, interval: int, item: array{name: string, amount: int, currency: string, description?: string}, notes: array<string, string>}
     */
    public function toRazorpayPayload(): array
    {
        $item = ['name' => $this->name, 'amount' => $this->chargeAmount, 'currency' => $this->currency];

        if ($this->description !== null) {
            $item['description'] = $this->description;
        }

        return [
            'period' => $this->period->value,
            'interval' => $this->interval,
            'item' => $item,
            'notes' => [...$this->notes, PlanRepository::NOTE_KEY => $this->key],
        ];
    }

    /**
     * Whether a Razorpay plan bills the same as this definition. Name and
     * description are cosmetic and ignored.
     *
     * @param  array<string, mixed>  $plan
     */
    public function matches(array $plan): bool
    {
        $item = is_array($plan['item'] ?? null) ? $plan['item'] : [];

        return ($plan['period'] ?? null) === $this->period->value
            && ($plan['interval'] ?? null) === $this->interval
            && ($item['amount'] ?? null) === $this->chargeAmount
            && ($item['currency'] ?? null) === $this->currency;
    }
}
