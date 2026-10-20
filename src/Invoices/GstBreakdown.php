<?php

declare(strict_types=1);

namespace Shasha1589\RazorpayBilling\Invoices;

/**
 * Tax split of an amount, all in paise. subtotal + cgst + sgst + igst = total.
 */
final class GstBreakdown
{
    public function __construct(
        public readonly int $subtotal,
        public readonly int $cgst,
        public readonly int $sgst,
        public readonly int $igst,
        public readonly int $total,
        public readonly string $placeOfSupply,
        public readonly bool $export,
    ) {}
}
