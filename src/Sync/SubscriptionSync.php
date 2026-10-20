<?php

declare(strict_types=1);

namespace Shasha1589\RazorpayBilling\Sync;

use Illuminate\Support\Carbon;
use Shasha1589\RazorpayBilling\Enums\SubscriptionStatus;
use Shasha1589\RazorpayBilling\Events\SubscriptionActivated;
use Shasha1589\RazorpayBilling\Events\SubscriptionAuthenticated;
use Shasha1589\RazorpayBilling\Events\SubscriptionCancelled;
use Shasha1589\RazorpayBilling\Events\SubscriptionCompleted;
use Shasha1589\RazorpayBilling\Events\SubscriptionEvent;
use Shasha1589\RazorpayBilling\Events\SubscriptionHalted;
use Shasha1589\RazorpayBilling\Events\SubscriptionPastDue;
use Shasha1589\RazorpayBilling\Events\SubscriptionPaused;
use Shasha1589\RazorpayBilling\Events\SubscriptionResumed;
use Shasha1589\RazorpayBilling\Events\SubscriptionUpdated;
use Shasha1589\RazorpayBilling\Models\Subscription;
use Shasha1589\RazorpayBilling\Plans\PlanRepository;

/**
 * The single place where Razorpay subscription state is applied locally, from
 * webhooks or API syncs. Events fire only on actual changes, so duplicate and
 * repeated deliveries are harmless.
 */
final class SubscriptionSync
{
    public function __construct(
        private readonly BillableResolver $billables,
        private readonly PlanRepository $plans,
    ) {}

    /**
     * Finds the local subscription for a Razorpay subscription entity, or
     * builds one from its billable notes (not saved yet).
     *
     * @param  array<string, mixed>  $entity
     */
    public function resolve(array $entity): ?Subscription
    {
        $id = $entity['id'] ?? null;

        if (! is_string($id)) {
            return null;
        }

        $subscription = Subscription::query()->where('razorpay_id', $id)->first();

        if ($subscription !== null) {
            return $subscription;
        }

        $notes = is_array($entity['notes'] ?? null) ? $entity['notes'] : [];
        $billable = $this->billables->fromNotes($notes);
        $planId = $entity['plan_id'] ?? null;

        if ($billable === null || ! is_string($planId)) {
            return null;
        }

        $subscription = new Subscription([
            'type' => is_string($notes['subscription_type'] ?? null) ? $notes['subscription_type'] : 'default',
            'razorpay_id' => $id,
            'razorpay_plan_id' => $planId,
            'plan_key' => $this->plans->keyForRazorpayId($planId),
            'status' => SubscriptionStatus::Created,
        ]);
        $subscription->billable()->associate($billable);

        return $subscription;
    }

    /**
     * Applies a Razorpay subscription entity. Returns false when it was
     * ignored: older than the state already applied, or trying to revive a
     * cancelled, completed or expired subscription.
     *
     * @param  array<string, mixed>  $entity
     * @param  array<string, mixed>  $payload  Passed to fired events.
     */
    public function apply(Subscription $subscription, array $entity, array $payload, ?Carbon $occurredAt = null): bool
    {
        $occurredAt ??= Carbon::now();

        if ($subscription->razorpay_synced_at !== null && $occurredAt->lt($subscription->razorpay_synced_at)) {
            return false;
        }

        $newStatus = is_string($entity['status'] ?? null) ? SubscriptionStatus::tryFrom($entity['status']) : null;

        if ($subscription->exists && $subscription->status->isTerminal() && $newStatus !== null && ! $newStatus->isTerminal()) {
            return false;
        }

        $existed = $subscription->exists;
        $oldStatus = $subscription->status;
        $oldPlan = $subscription->razorpay_plan_id;
        $oldQuantity = $subscription->quantity;

        $subscription->fillFromRazorpay($entity);
        $subscription->razorpay_synced_at = $occurredAt;

        if ($subscription->razorpay_plan_id !== $oldPlan) {
            $subscription->plan_key = $this->plans->keyForRazorpayId($subscription->razorpay_plan_id);
        }

        $subscription->save();

        if ($subscription->status !== $oldStatus) {
            $this->dispatchStatusEvent($subscription, $oldStatus, $payload);
        }

        if ($existed && ($subscription->razorpay_plan_id !== $oldPlan || $subscription->quantity !== $oldQuantity)) {
            SubscriptionUpdated::dispatch($subscription->billable, $subscription, $payload);
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function dispatchStatusEvent(Subscription $subscription, SubscriptionStatus $from, array $payload): void
    {
        /** @var class-string<SubscriptionEvent>|null $event */
        $event = match ($subscription->status) {
            SubscriptionStatus::Authenticated => SubscriptionAuthenticated::class,
            SubscriptionStatus::Active => $from === SubscriptionStatus::Paused ? SubscriptionResumed::class : SubscriptionActivated::class,
            SubscriptionStatus::Pending => SubscriptionPastDue::class,
            SubscriptionStatus::Halted => SubscriptionHalted::class,
            SubscriptionStatus::Paused => SubscriptionPaused::class,
            SubscriptionStatus::Cancelled => SubscriptionCancelled::class,
            SubscriptionStatus::Completed => SubscriptionCompleted::class,
            default => null,
        };

        if ($event !== null) {
            $event::dispatch($subscription->billable, $subscription, $payload);
        }
    }
}
