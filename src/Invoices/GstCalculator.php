<?php

declare(strict_types=1);

namespace Shasha1589\RazorpayBilling\Invoices;

use Illuminate\Contracts\Config\Repository as Config;

/**
 * GST arithmetic in integer paise. Not a tax engine: rates and treatment come
 * from config and must be confirmed with a chartered accountant.
 *
 * - Buyer in the seller's state: CGST + SGST at half the rate each.
 * - Buyer in another state: IGST at the full rate.
 * - Buyer with the export state code: zero-rated export of services.
 * - Buyer without a state code: treated as in the seller's state.
 */
final class GstCalculator
{
    public function __construct(
        private readonly int $rate,
        private readonly ?string $sellerStateCode,
        private readonly string $exportStateCode = '96',
        private readonly bool $pricesIncludeTax = false,
        private readonly bool $enabled = true,
    ) {
        if ($rate < 0 || $rate > 100) {
            throw new InvalidInvoiceConfiguration('invoices.gst_rate must be a whole percentage between 0 and 100.');
        }
    }

    public static function fromConfig(Config $config): self
    {
        $invoices = $config->get('razorpay-billing.invoices', []);
        $invoices = is_array($invoices) ? $invoices : [];
        $seller = is_array($invoices['seller'] ?? null) ? $invoices['seller'] : [];

        return new self(
            is_int($invoices['gst_rate'] ?? null) ? $invoices['gst_rate'] : 18,
            is_string($seller['state_code'] ?? null) ? $seller['state_code'] : null,
            is_string($invoices['export_state_code'] ?? null) ? $invoices['export_state_code'] : '96',
            ($invoices['prices_include_tax'] ?? false) === true,
            ($invoices['enabled'] ?? false) === true,
        );
    }

    /**
     * The amount to charge through Razorpay for a configured price. When the
     * invoice module is on and prices exclude tax, GST is added on top.
     */
    public function chargeAmount(int $price): int
    {
        if (! $this->enabled || $this->pricesIncludeTax) {
            return $price;
        }

        return $price + self::percentOf($price, $this->rate);
    }

    /**
     * Splits an amount actually paid (tax-inclusive) into subtotal and tax.
     */
    public function split(int $total, ?string $buyerStateCode): GstBreakdown
    {
        if ($this->sellerStateCode === null || $this->sellerStateCode === '') {
            throw new InvalidInvoiceConfiguration('Set invoices.seller.state_code to generate GST invoices.');
        }

        $placeOfSupply = $buyerStateCode === null || $buyerStateCode === '' ? $this->sellerStateCode : $buyerStateCode;

        if ($placeOfSupply === $this->exportStateCode) {
            return new GstBreakdown($total, 0, 0, 0, $total, $placeOfSupply, export: true);
        }

        // subtotal = total × 100 / (100 + rate), rounded half up.
        $divisor = 100 + $this->rate;
        $subtotal = intdiv($total * 200 + $divisor, 2 * $divisor);
        $tax = $total - $subtotal;

        if ($placeOfSupply === $this->sellerStateCode) {
            $cgst = intdiv($tax, 2);

            return new GstBreakdown($subtotal, $cgst, $tax - $cgst, 0, $total, $placeOfSupply, export: false);
        }

        return new GstBreakdown($subtotal, 0, 0, $tax, $total, $placeOfSupply, export: false);
    }

    /** $percent % of $amount, rounded half up. */
    private static function percentOf(int $amount, int $percent): int
    {
        return intdiv($amount * $percent * 2 + 100, 200);
    }
}
