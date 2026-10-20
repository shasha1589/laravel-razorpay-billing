<?php

declare(strict_types=1);

use Shasha1589\RazorpayBilling\Invoices\GstCalculator;
use Shasha1589\RazorpayBilling\Invoices\InvalidInvoiceConfiguration;

it('splits an intra-state amount into CGST and SGST', function () {
    $gst = (new GstCalculator(18, '29'))->split(118000, '29');

    expect([$gst->subtotal, $gst->cgst, $gst->sgst, $gst->igst, $gst->total, $gst->placeOfSupply, $gst->export])
        ->toBe([100000, 9000, 9000, 0, 118000, '29', false]);
});

it('charges IGST for an inter-state buyer', function () {
    $gst = (new GstCalculator(18, '29'))->split(118000, '27');

    expect([$gst->subtotal, $gst->cgst, $gst->sgst, $gst->igst, $gst->placeOfSupply])
        ->toBe([100000, 0, 0, 18000, '27']);
});

it('zero-rates exports', function () {
    $gst = (new GstCalculator(18, '29'))->split(118000, '96');

    expect([$gst->subtotal, $gst->cgst, $gst->sgst, $gst->igst, $gst->total, $gst->export])
        ->toBe([118000, 0, 0, 0, 118000, true]);
});

it('treats a buyer without a state as intra-state', function () {
    $gst = (new GstCalculator(18, '29'))->split(118000, null);

    expect($gst->cgst + $gst->sgst)->toBe(18000)
        ->and($gst->placeOfSupply)->toBe('29');
});

it('keeps every paisa when amounts do not divide evenly', function (int $total) {
    $gst = (new GstCalculator(18, '29'))->split($total, '29');

    expect($gst->subtotal + $gst->cgst + $gst->sgst + $gst->igst)->toBe($total)
        ->and(abs($gst->cgst - $gst->sgst))->toBeLessThanOrEqual(1);
})->with([1, 99, 100, 101, 1999900, 2359882, 4294967295]);

it('rounds the subtotal half up', function () {
    // 101 / 1.18 = 85.59 → 86
    expect((new GstCalculator(18, '29'))->split(101, '27')->subtotal)->toBe(86);
});

it('grosses up tax-exclusive prices only when the module is enabled', function () {
    expect((new GstCalculator(18, '29'))->chargeAmount(1999900))->toBe(2359882)
        ->and((new GstCalculator(18, '29', pricesIncludeTax: true))->chargeAmount(1999900))->toBe(1999900)
        ->and((new GstCalculator(18, '29', enabled: false))->chargeAmount(1999900))->toBe(1999900)
        ->and((new GstCalculator(18, '29'))->chargeAmount(101))->toBe(119); // 18.18 → 18
});

it('requires a seller state to split', function () {
    (new GstCalculator(18, null))->split(100, '29');
})->throws(InvalidInvoiceConfiguration::class, 'state_code');

it('rejects an invalid rate', function () {
    new GstCalculator(101, '29');
})->throws(InvalidInvoiceConfiguration::class);
