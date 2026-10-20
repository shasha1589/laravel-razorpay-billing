<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

afterEach(function () {
    File::delete(config_path('razorpay-billing.php'));
    File::deleteDirectory(public_path('vendor/razorpay-billing'));
    File::delete(File::glob(database_path('migrations/*razorpay*.php')) ?: []);
});

it('publishes the config and JS helper and prints next steps', function () {
    $this->artisan('razorpay:install')
        ->expectsOutputToContain('razorpay:sync-plans')
        ->assertSuccessful();

    expect(File::exists(config_path('razorpay-billing.php')))->toBeTrue()
        ->and(File::exists(public_path('vendor/razorpay-billing/razorpay-checkout.js')))->toBeTrue()
        ->and(File::glob(database_path('migrations/*razorpay*.php')))->toBeEmpty();
});

it('publishes migrations on request', function () {
    $this->artisan('razorpay:install', ['--migrations' => true])
        ->expectsOutputToContain('run_migrations')
        ->assertSuccessful();

    expect(File::glob(database_path('migrations/*razorpay*.php')))->toHaveCount(5);
});
