<?php

declare(strict_types=1);

namespace Shasha1589\RazorpayBilling;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Shasha1589\RazorpayBilling\Client\RazorpayClient;
use Shasha1589\RazorpayBilling\Console\InstallCommand;
use Shasha1589\RazorpayBilling\Console\SyncCommand;
use Shasha1589\RazorpayBilling\Console\SyncPlansCommand;
use Shasha1589\RazorpayBilling\Console\UpcomingChargesCommand;
use Shasha1589\RazorpayBilling\Console\WebhookReplayCommand;
use Shasha1589\RazorpayBilling\Plans\PlanRepository;

class RazorpayBillingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/razorpay-billing.php', 'razorpay-billing');

        $this->app->singleton(RazorpayClient::class, fn (Application $app) => RazorpayClient::fromConfig($app->make('config')));
        $this->app->singleton(PlanRepository::class);
    }

    public function boot(): void
    {
        $this->registerRoutes();

        if (config('razorpay-billing.run_migrations') === true) {
            $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        }

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/razorpay-billing.php' => $this->app->configPath('razorpay-billing.php'),
            ], 'razorpay-billing-config');

            $this->publishesMigrations([
                __DIR__.'/../database/migrations' => $this->app->databasePath('migrations'),
            ], 'razorpay-billing-migrations');

            $this->publishes([
                __DIR__.'/../resources/js/razorpay-checkout.js' => $this->app->publicPath('vendor/razorpay-billing/razorpay-checkout.js'),
            ], 'razorpay-billing-js');

            $this->commands([
                InstallCommand::class,
                SyncCommand::class,
                SyncPlansCommand::class,
                UpcomingChargesCommand::class,
                WebhookReplayCommand::class,
            ]);
        }
    }

    private function registerRoutes(): void
    {
        RateLimiter::for('razorpay-billing-webhooks', function () {
            $perMinute = config('razorpay-billing.webhook_rate_limit');

            return Limit::perMinute(is_int($perMinute) ? $perMinute : 600);
        });

        if ($this->app->routesAreCached()) {
            return;
        }

        $path = config('razorpay-billing.path');

        Route::prefix(is_string($path) ? $path : 'razorpay')
            ->group(__DIR__.'/../routes/razorpay.php');
    }
}
