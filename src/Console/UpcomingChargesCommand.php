<?php

declare(strict_types=1);

namespace Shasha1589\RazorpayBilling\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Support\Carbon;
use Shasha1589\RazorpayBilling\Enums\SubscriptionStatus;
use Shasha1589\RazorpayBilling\Events\UpcomingCharge;
use Shasha1589\RazorpayBilling\Models\Subscription;
use Shasha1589\RazorpayBilling\Plans\InvalidPlanException;
use Shasha1589\RazorpayBilling\Plans\PlanRepository;

/**
 * Fires UpcomingCharge for each charge due within `upcoming_charge_notice_hours`,
 * once per subscription and charge date. Schedule it hourly.
 */
class UpcomingChargesCommand extends Command
{
    protected $signature = 'razorpay:upcoming-charges';

    protected $description = 'Fire UpcomingCharge events for subscription charges due soon (schedule hourly)';

    public function handle(PlanRepository $plans, CacheFactory $cache): int
    {
        $hours = config('razorpay-billing.upcoming_charge_notice_hours');
        $hours = is_int($hours) && $hours > 0 ? $hours : 48;
        $store = config('razorpay-billing.plan_cache_store');
        $store = $cache->store(is_string($store) ? $store : null);
        $now = Carbon::now();
        $fired = 0;

        Subscription::query()
            ->whereIn('status', [SubscriptionStatus::Active, SubscriptionStatus::Authenticated])
            ->whereBetween('charge_at', [$now, $now->copy()->addHours($hours)])
            ->chunkById(100, function ($subscriptions) use ($plans, $store, $hours, &$fired) {
                /** @var Subscription $subscription */
                foreach ($subscriptions as $subscription) {
                    $chargeAt = $subscription->charge_at;

                    // No charge happens if the subscription ends first.
                    if ($chargeAt === null || ($subscription->ends_at !== null && $subscription->ends_at->lte($chargeAt))) {
                        continue;
                    }

                    $key = "razorpay-billing:upcoming:{$subscription->id}:{$chargeAt->getTimestamp()}";

                    if (! $store->add($key, true, now()->addHours($hours + 24))) {
                        continue;
                    }

                    UpcomingCharge::dispatch($subscription->billable, $subscription, $chargeAt, $this->amount($plans, $subscription));
                    $fired++;
                }
            });

        $this->components->info("Fired {$fired} UpcomingCharge event(s).");

        return self::SUCCESS;
    }

    private function amount(PlanRepository $plans, Subscription $subscription): ?int
    {
        if ($subscription->plan_key === null) {
            return null;
        }

        try {
            return $plans->definition($subscription->plan_key)->chargeAmount * $subscription->quantity;
        } catch (InvalidPlanException) {
            return null;
        }
    }
}
