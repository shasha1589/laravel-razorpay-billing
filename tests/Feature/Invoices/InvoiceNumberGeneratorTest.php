<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Shasha1589\RazorpayBilling\Invoices\InvoiceNumberGenerator;

it('computes the Indian financial year in IST', function (string $utc, string $expected) {
    expect(InvoiceNumberGenerator::financialYear(Carbon::parse($utc, 'UTC')))->toBe($expected);
})->with([
    ['2026-04-01 00:00:00', '2026-27'],
    ['2027-03-31 12:00:00', '2026-27'],
    ['2027-03-31 18:29:59', '2026-27'], // 23:59:59 IST
    ['2027-03-31 18:30:00', '2027-28'], // 00:00 IST on 1 April
    ['2026-01-15 00:00:00', '2025-26'],
    ['2099-12-31 00:00:00', '2099-00'],
]);

it('numbers invoices sequentially and restarts each financial year', function () {
    $numbers = new InvoiceNumberGenerator('INV');

    $first = DB::transaction(fn () => $numbers->next(Carbon::parse('2027-03-31 12:00', 'Asia/Kolkata')));
    $second = DB::transaction(fn () => $numbers->next(Carbon::parse('2027-03-31 23:59', 'Asia/Kolkata')));
    $third = DB::transaction(fn () => $numbers->next(Carbon::parse('2027-04-01 00:00', 'Asia/Kolkata')));

    expect($first)->toBe(['financial_year' => '2026-27', 'sequence' => 1, 'number' => 'INV/2026-27/000001'])
        ->and($second['number'])->toBe('INV/2026-27/000002')
        ->and($third)->toBe(['financial_year' => '2027-28', 'sequence' => 1, 'number' => 'INV/2027-28/000001']);
});

it('releases the number when the transaction rolls back, leaving no gap', function () {
    $numbers = new InvoiceNumberGenerator('INV');
    $date = Carbon::parse('2026-10-20');

    try {
        DB::transaction(function () use ($numbers, $date) {
            $numbers->next($date);

            throw new RuntimeException('invoice insert failed');
        });
    } catch (RuntimeException) {
    }

    expect(DB::transaction(fn () => $numbers->next($date))['sequence'])->toBe(1);
});
