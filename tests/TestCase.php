<?php

declare(strict_types=1);

namespace Shasha1589\RazorpayBilling\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Orchestra\Testbench\TestCase as Orchestra;
use Shasha1589\RazorpayBilling\RazorpayBillingServiceProvider;
use Shasha1589\RazorpayBilling\Tests\Support\User;

abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    protected function getPackageProviders($app): array
    {
        return [RazorpayBillingServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        // CI sets DB_CONNECTION (mysql, mariadb, pgsql); locally use in-memory SQLite.
        $app['config']->set('database.default', env('DB_CONNECTION', 'testing'));
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));

        $app['config']->set('razorpay-billing.key_id', 'rzp_test_dummykey');
        $app['config']->set('razorpay-billing.key_secret', 'dummy_secret');
        $app['config']->set('razorpay-billing.webhook_secret', 'dummy_webhook_secret');
        $app['config']->set('razorpay-billing.billable_model', User::class);
    }

    /**
     * Posts a signed webhook with the raw JSON body, as Razorpay does.
     *
     * @param  array<string, mixed>  $payload
     */
    public function postWebhook(array $payload, ?string $eventId = null, string $secret = 'dummy_webhook_secret'): TestResponse
    {
        $body = (string) json_encode($payload);

        return $this->call('POST', route('razorpay-billing.webhook'), server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_RAZORPAY_SIGNATURE' => hash_hmac('sha256', $body, $secret),
            'HTTP_X_RAZORPAY_EVENT_ID' => $eventId ?? 'evt_'.bin2hex(random_bytes(8)),
        ], content: $body);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/database/migrations');
    }
}
