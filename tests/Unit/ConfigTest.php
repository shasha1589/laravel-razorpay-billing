<?php

declare(strict_types=1);

it('stores every money value in config as integer paise', function () {
    $config = require __DIR__.'/../../config/razorpay-billing.php';

    expect($config['upi_autopay_max_amount'])->toBeInt()
        ->and($config['invoices']['gst_rate'])->toBeInt();
});
