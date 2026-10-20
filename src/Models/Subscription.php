<?php

declare(strict_types=1);

namespace Shasha1589\RazorpayBilling\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Shasha1589\RazorpayBilling\Client\RazorpayClient;
use Shasha1589\RazorpayBilling\Enums\SubscriptionStatus;
use Shasha1589\RazorpayBilling\Sync\SubscriptionSync;

/**
 * @property int $id
 * @property string $billable_type
 * @property int|string $billable_id
 * @property string $type
 * @property string $razorpay_id
 * @property string $razorpay_plan_id
 * @property string|null $plan_key
 * @property SubscriptionStatus $status
 * @property int $quantity
 * @property string|null $payment_method
 * @property string|null $mandate_status
 * @property string|null $razorpay_token_id
 * @property Carbon|null $razorpay_synced_at
 * @property Carbon|null $trial_ends_at
 * @property Carbon|null $current_start
 * @property Carbon|null $current_end
 * @property Carbon|null $charge_at
 * @property Carbon|null $ends_at
 * @property Carbon|null $paused_at
 * @property array<string, string>|null $notes
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Model $billable
 */
class Subscription extends Model
{
    use Concerns\ManagesLifecycle;

    protected $table = 'razorpay_subscriptions';

    protected $guarded = [];

    protected $attributes = [
        'quantity' => 1,
    ];

    protected function casts(): array
    {
        return [
            'status' => SubscriptionStatus::class,
            'quantity' => 'integer',
            'trial_ends_at' => 'datetime',
            'current_start' => 'datetime',
            'current_end' => 'datetime',
            'charge_at' => 'datetime',
            'ends_at' => 'datetime',
            'paused_at' => 'datetime',
            'razorpay_synced_at' => 'datetime',
            'notes' => 'array',
        ];
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function billable(): MorphTo
    {
        return $this->morphTo();
    }

    /** The plan key or Razorpay plan ID this subscription is on. */
    public function hasPlan(string $plan): bool
    {
        return $this->plan_key === $plan || $this->razorpay_plan_id === $plan;
    }

    /** Billing normally (including a cancellation pending at cycle end). */
    public function active(): bool
    {
        return $this->status === SubscriptionStatus::Active && ! $this->ended();
    }

    /** Mandate authenticated, waiting for the trial to end before the first charge. */
    public function onTrial(): bool
    {
        return $this->trial_ends_at !== null
            && $this->trial_ends_at->isFuture()
            && in_array($this->status, [SubscriptionStatus::Authenticated, SubscriptionStatus::Active], true);
    }

    /** A charge failed and Razorpay is retrying. */
    public function pastDue(): bool
    {
        return $this->status === SubscriptionStatus::Pending;
    }

    /** Razorpay exhausted its retries. */
    public function halted(): bool
    {
        return $this->status === SubscriptionStatus::Halted;
    }

    public function paused(): bool
    {
        return $this->status === SubscriptionStatus::Paused;
    }

    /** Cancelled now or scheduled to cancel at the end of the cycle. */
    public function cancelled(): bool
    {
        return $this->ends_at !== null || $this->status === SubscriptionStatus::Cancelled;
    }

    /** Cancelled, but the paid-for period has not ended yet. */
    public function onGracePeriod(): bool
    {
        return $this->ends_at !== null && $this->ends_at->isFuture();
    }

    public function ended(): bool
    {
        return $this->status->isTerminal() || ($this->cancelled() && ! $this->onGracePeriod());
    }

    /** Whether the customer should have access. */
    public function valid(): bool
    {
        return $this->active()
            || $this->onTrial()
            || $this->onGracePeriod()
            || ($this->pastDue() && config('razorpay-billing.keep_past_due_active') === true);
    }

    /**
     * Refreshes this subscription from Razorpay, firing events for any state
     * change.
     *
     * @see https://razorpay.com/docs/api/payments/subscriptions/fetch-subscription-id
     */
    public function syncFromRazorpay(): self
    {
        $entity = app(RazorpayClient::class)->subscriptions()->fetch($this->razorpay_id);

        app(SubscriptionSync::class)->apply($this, $entity, $entity);

        return $this;
    }

    /**
     * Copies state from a Razorpay subscription entity without saving or
     * firing events. Use SubscriptionSync to apply remote changes.
     *
     * @see https://razorpay.com/docs/api/payments/subscriptions/fetch-subscription-id
     *
     * @param  array<string, mixed>  $data
     */
    public function fillFromRazorpay(array $data): self
    {
        $status = is_string($data['status'] ?? null) ? SubscriptionStatus::tryFrom($data['status']) : null;

        if ($status !== null) {
            $this->status = $status;
        }

        if (is_string($data['plan_id'] ?? null)) {
            $this->razorpay_plan_id = $data['plan_id'];
        }

        if (is_int($data['quantity'] ?? null)) {
            $this->quantity = $data['quantity'];
        }

        if (is_string($data['payment_method'] ?? null)) {
            $this->payment_method = $data['payment_method'];
        }

        foreach (['current_start', 'current_end', 'charge_at'] as $field) {
            if (array_key_exists($field, $data)) {
                $this->{$field} = self::timestamp($data[$field]);
            }
        }

        if ($this->status->isTerminal()) {
            $this->ends_at = self::timestamp($data['ended_at'] ?? null) ?? $this->ends_at ?? Carbon::now();
        }

        $this->paused_at = $this->status === SubscriptionStatus::Paused ? ($this->paused_at ?? Carbon::now()) : null;

        return $this;
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('status', SubscriptionStatus::Active)
            ->where(fn (Builder $query) => $query->whereNull('ends_at')->orWhere('ends_at', '>', Carbon::now()));
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeOnTrial(Builder $query): void
    {
        $query->whereIn('status', [SubscriptionStatus::Authenticated, SubscriptionStatus::Active])
            ->whereNotNull('trial_ends_at')
            ->where('trial_ends_at', '>', Carbon::now());
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeCancelled(Builder $query): void
    {
        $query->where(fn (Builder $query) => $query->whereNotNull('ends_at')->orWhere('status', SubscriptionStatus::Cancelled));
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopePastDue(Builder $query): void
    {
        $query->where('status', SubscriptionStatus::Pending);
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeHalted(Builder $query): void
    {
        $query->where('status', SubscriptionStatus::Halted);
    }

    private static function timestamp(mixed $value): ?Carbon
    {
        return is_int($value) ? Carbon::createFromTimestamp($value) : null;
    }
}
