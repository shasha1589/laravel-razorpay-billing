<?php

declare(strict_types=1);

use Shasha1589\RazorpayBilling\Checkout;

it('builds Razorpay Checkout options for a subscription', function () {
    config(['app.name' => 'Acme']);

    $checkout = (new Checkout(
        keyId: 'rzp_test_key',
        subscriptionId: 'sub_1',
        prefill: ['name' => 'Gaurav', 'email' => 'g@example.com'],
        shortUrl: 'https://rzp.io/i/x',
    ))->description('Pro plan')->themeColor('#123456');

    expect($checkout->options())->toBe([
        'key' => 'rzp_test_key',
        'subscription_id' => 'sub_1',
        'name' => 'Acme',
        'description' => 'Pro plan',
        'prefill' => ['name' => 'Gaurav', 'email' => 'g@example.com'],
        'theme' => ['color' => '#123456'],
    ]);
});

it('hides methods not allowed for the checkout', function () {
    $options = (new Checkout(keyId: 'k', subscriptionId: 'sub_1', methods: ['emandate', 'upi']))->options();

    expect($options['config'])->toBe([
        'display' => [
            'hide' => [['method' => 'card']],
            'preferences' => ['show_default_blocks' => true],
        ],
    ]);
});

it('serialises to options, verify URL and short URL', function () {
    $checkout = new Checkout(keyId: 'k', subscriptionId: 'sub_1', shortUrl: 'https://rzp.io/i/x');

    $json = json_decode((string) json_encode($checkout), true);

    expect($json['options']['subscription_id'])->toBe('sub_1')
        ->and($json['verify_url'])->toBe(url('razorpay/verify'))
        ->and($json['short_url'])->toBe('https://rzp.io/i/x')
        ->and($checkout->toArray())->toBe($json);
});
