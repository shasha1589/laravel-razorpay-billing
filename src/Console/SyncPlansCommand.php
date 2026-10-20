<?php

declare(strict_types=1);

namespace Shasha1589\RazorpayBilling\Console;

use Illuminate\Console\Command;
use Shasha1589\RazorpayBilling\Plans\PlanRepository;
use Shasha1589\RazorpayBilling\Plans\PlanSyncStatus;

class SyncPlansCommand extends Command
{
    protected $signature = 'razorpay:sync-plans
                            {--dry-run : Show what would be created without creating anything}';

    protected $description = 'Create the plans defined in config/razorpay-billing.php that do not exist in Razorpay yet';

    public function handle(PlanRepository $plans): int
    {
        $results = $plans->sync((bool) $this->option('dry-run'));

        if ($results === []) {
            $this->components->warn('No plans are defined in config/razorpay-billing.php.');

            return self::SUCCESS;
        }

        $this->table(
            ['Plan key', 'Status', 'Razorpay plan ID'],
            array_map(fn ($result) => [$result->key, $result->status->value, $result->razorpayId ?? '-'], $results),
        );

        $conflicts = array_filter($results, fn ($result) => $result->status === PlanSyncStatus::Conflict);

        if ($conflicts !== []) {
            $this->components->error(
                'These plans exist in Razorpay with a different amount, currency or billing period: '
                .implode(', ', array_map(fn ($result) => $result->key, $conflicts))
                .'. Razorpay plans are immutable, so add a new plan key instead of editing an existing one.'
            );

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
