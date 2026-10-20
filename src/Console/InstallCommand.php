<?php

declare(strict_types=1);

namespace Shasha1589\RazorpayBilling\Console;

use Illuminate\Console\Command;

class InstallCommand extends Command
{
    protected $signature = 'razorpay:install
                            {--migrations : Also publish the migrations to customise them (then set run_migrations to false)}
                            {--force : Overwrite files that were already published}';

    protected $description = 'Publish the Razorpay Billing config and checkout helper, and print next steps';

    public function handle(): int
    {
        $force = (bool) $this->option('force');
        $path = config('razorpay-billing.path');
        $webhookUrl = url((is_string($path) ? $path : 'razorpay').'/webhook');

        $this->publish('razorpay-billing-config', $force);
        $this->publish('razorpay-billing-js', $force);

        if ($this->option('migrations')) {
            $this->publish('razorpay-billing-migrations', $force);
            $this->components->warn('Migrations published: set `run_migrations` to false in config/razorpay-billing.php so they are not loaded twice.');
        }

        $this->components->info('Razorpay Billing installed. Next steps:');
        $this->components->bulletList([
            'Set RAZORPAY_KEY_ID, RAZORPAY_KEY_SECRET and RAZORPAY_WEBHOOK_SECRET in .env',
            'Set RAZORPAY_BILLABLE_MODEL if your billable model is not App\Models\User, and add the Billable trait to it',
            'Run `php artisan migrate`',
            'Define plans in config/razorpay-billing.php, then run `php artisan razorpay:sync-plans`',
            "Add the webhook URL {$webhookUrl} in the Razorpay Dashboard",
            'Run a queue worker for webhooks, and schedule `razorpay:sync` and `razorpay:upcoming-charges` hourly',
        ]);

        return self::SUCCESS;
    }

    private function publish(string $tag, bool $force): void
    {
        $this->callSilently('vendor:publish', ['--tag' => $tag, '--force' => $force]);
        $this->components->task("Published {$tag}");
    }
}
