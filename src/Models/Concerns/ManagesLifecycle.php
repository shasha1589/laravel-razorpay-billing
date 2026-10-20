<?php

declare(strict_types=1);

namespace Shasha1589\RazorpayBilling\Models\Concerns;

use Shasha1589\RazorpayBilling\Client\RazorpayClient;
use Shasha1589\RazorpayBilling\Enums\SubscriptionStatus;
use Shasha1589\RazorpayBilling\Exceptions\SubscriptionCreationFailed;
use Shasha1589\RazorpayBilling\Exceptions\SubscriptionUpdateFailure;
use Shasha1589\RazorpayBilling\Models\Subscription;
use Shasha1589\RazorpayBilling\Plans\PlanRepository;
use Shasha1589\RazorpayBilling\Sync\SubscriptionSync;

/**
 * Swap, quantity, cancel, pause, resume and add-ons. Each call applies
 * Razorpay's response through SubscriptionSync, so the matching events fire
 * once and the later webhook is a no-op.
 *
 * @mixin Subscription
 */
trait ManagesLifecycle
{
    /**
     * Moves to another plan. Razorpay can't update UPI or eMandate
     * subscriptions; those must be cancelled and recreated.
     *
     * @see https://razorpay.com/docs/api/payments/subscriptions/update-subscription
     *
     * @param  string  $plan  Plan key or Razorpay plan ID.
     * @param  'now'|'cycle_end'  $when
     */
    public function swap(string $plan, string $when = 'cycle_end'): self
    {
        $planId = str_starts_with($plan, 'plan_')
            ? $plan
            : app(PlanRepository::class)->razorpayId($plan) ?? throw SubscriptionCreationFailed::planNotSynced($plan);

        return $this->updateOnRazorpay(['plan_id' => $planId], $when);
    }

    /**
     * @param  'now'|'cycle_end'  $when
     */
    public function updateQuantity(int $quantity, string $when = 'cycle_end'): self
    {
        if ($quantity < 1) {
            throw new \InvalidArgumentException('Quantity must be at least 1.');
        }

        return $this->updateOnRazorpay(['quantity' => $quantity], $when);
    }

    /**
     * @param  'now'|'cycle_end'  $when
     */
    public function incrementQuantity(int $count = 1, string $when = 'cycle_end'): self
    {
        return $this->updateQuantity($this->quantity + $count, $when);
    }

    /**
     * @param  'now'|'cycle_end'  $when
     */
    public function decrementQuantity(int $count = 1, string $when = 'cycle_end'): self
    {
        return $this->updateQuantity(max(1, $this->quantity - $count), $when);
    }

    /**
     * Cancels at the end of the current billing cycle; the subscription stays
     * valid until then (onGracePeriod()). Subscriptions that haven't started
     * billing (created, authenticated) are cancelled immediately.
     *
     * @see https://razorpay.com/docs/api/payments/subscriptions/cancel-subscription
     */
    public function cancel(): self
    {
        if ($this->status !== SubscriptionStatus::Active || $this->current_end === null) {
            return $this->cancelNow();
        }

        $this->guardNotEnded();

        $this->applyRazorpay(app(RazorpayClient::class)->subscriptions()->cancel($this->razorpay_id, atCycleEnd: true));

        if (! $this->status->isTerminal()) {
            $this->ends_at = $this->current_end;
            $this->save();
        }

        return $this;
    }

    /**
     * @see https://razorpay.com/docs/api/payments/subscriptions/cancel-subscription
     */
    public function cancelNow(): self
    {
        $this->guardNotEnded();

        return $this->applyRazorpay(app(RazorpayClient::class)->subscriptions()->cancel($this->razorpay_id));
    }

    /**
     * Only active subscriptions can be paused (Razorpay cancels an
     * authenticated one instead, so that is refused here).
     *
     * @see https://razorpay.com/docs/api/payments/subscriptions/pause-subscription
     */
    public function pause(): self
    {
        if ($this->status !== SubscriptionStatus::Active) {
            throw SubscriptionUpdateFailure::notPausable($this);
        }

        return $this->applyRazorpay(app(RazorpayClient::class)->subscriptions()->pause($this->razorpay_id));
    }

    /**
     * @see https://razorpay.com/docs/api/payments/subscriptions/resume-subscription
     */
    public function resume(): self
    {
        if ($this->status !== SubscriptionStatus::Paused) {
            throw SubscriptionUpdateFailure::notResumable($this);
        }

        return $this->applyRazorpay(app(RazorpayClient::class)->subscriptions()->resume($this->razorpay_id));
    }

    /**
     * Adds a one-time charge to the next invoice.
     *
     * @see https://razorpay.com/docs/api/payments/subscriptions/#create-an-add-on
     * @see https://github.com/razorpay/razorpay-php/blob/master/documents/addon.md
     *
     * @return array<string, mixed> The Razorpay add-on entity.
     */
    public function addOn(int $amount, string $description): array
    {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Add-on amount must be a positive integer in paise.');
        }

        $this->guardNotEnded();
        $currency = config('razorpay-billing.currency');

        return app(RazorpayClient::class)->subscriptions()->createAddon($this->razorpay_id, [
            'item' => [
                'name' => $description,
                'amount' => $amount,
                'currency' => is_string($currency) ? $currency : 'INR',
            ],
            'quantity' => 1,
        ]);
    }

    /**
     * @param  array{plan_id?: string, quantity?: int}  $attributes
     */
    private function updateOnRazorpay(array $attributes, string $when): self
    {
        if (! in_array($when, ['now', 'cycle_end'], true)) {
            throw SubscriptionUpdateFailure::invalidSchedule($when);
        }

        if (! in_array($this->status, [SubscriptionStatus::Authenticated, SubscriptionStatus::Active], true)) {
            throw SubscriptionUpdateFailure::notUpdatable($this);
        }

        if (in_array($this->payment_method, ['upi', 'emandate'], true)) {
            throw SubscriptionUpdateFailure::paymentMethodNotUpdatable($this);
        }

        return $this->applyRazorpay(app(RazorpayClient::class)->subscriptions()->update($this->razorpay_id, [
            ...$attributes,
            'schedule_change_at' => $when,
        ]));
    }

    /**
     * @param  array<string, mixed>  $entity
     */
    private function applyRazorpay(array $entity): self
    {
        app(SubscriptionSync::class)->apply($this, $entity, $entity);

        return $this;
    }

    private function guardNotEnded(): void
    {
        if ($this->status->isTerminal()) {
            throw SubscriptionUpdateFailure::ended($this);
        }
    }
}
