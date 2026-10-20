<?php

declare(strict_types=1);

namespace Shasha1589\RazorpayBilling\Console;

use Illuminate\Console\Command;
use Shasha1589\RazorpayBilling\Client\Exceptions\RazorpayException;
use Shasha1589\RazorpayBilling\Enums\SubscriptionStatus;
use Shasha1589\RazorpayBilling\Models\Subscription;

/**
 * Reconciles local subscriptions with Razorpay, for missed webhooks.
 *
 * @see https://razorpay.com/docs/api/payments/subscriptions/fetch-subscription-id
 */
class SyncCommand extends Command
{
    protected $signature = 'razorpay:sync
                            {--subscription= : Sync one subscription by its Razorpay ID}';

    protected $description = 'Refresh local subscriptions from Razorpay (safe to schedule hourly)';

    public function handle(): int
    {
        $query = Subscription::query();
        $id = $this->option('subscription');

        if (is_string($id) && $id !== '') {
            $query->where('razorpay_id', $id);
        } else {
            $query->whereNotIn('status', array_filter(SubscriptionStatus::cases(), fn (SubscriptionStatus $status) => $status->isTerminal()));
        }

        $synced = 0;
        $failed = 0;

        $query->chunkById(100, function ($subscriptions) use (&$synced, &$failed) {
            /** @var Subscription $subscription */
            foreach ($subscriptions as $subscription) {
                try {
                    $subscription->syncFromRazorpay();
                    $synced++;
                } catch (RazorpayException $e) {
                    $failed++;
                    $this->components->error("{$subscription->razorpay_id}: {$e->getMessage()}");
                }
            }
        });

        if ($synced === 0 && $failed === 0 && is_string($id) && $id !== '') {
            $this->components->error("No local subscription {$id}.");

            return self::FAILURE;
        }

        $this->components->info("Synced {$synced} subscription(s)".($failed > 0 ? ", {$failed} failed." : '.'));

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
