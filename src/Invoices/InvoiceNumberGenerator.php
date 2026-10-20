<?php

declare(strict_types=1);

namespace Shasha1589\RazorpayBilling\Invoices;

use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Gap-free invoice numbers, {prefix}/{FY}/{sequence}, e.g. INV/2026-27/000123.
 * The sequence restarts every Indian financial year (April–March, IST).
 */
class InvoiceNumberGenerator
{
    private const TABLE = 'razorpay_invoice_sequences';

    public function __construct(private readonly string $prefix = 'INV') {}

    public static function financialYear(DateTimeInterface $date): string
    {
        $date = Carbon::instance($date)->setTimezone('Asia/Kolkata');
        $start = $date->month >= 4 ? $date->year : $date->year - 1;

        return sprintf('%d-%02d', $start, ($start + 1) % 100);
    }

    /**
     * Reserves the next number. Call inside the transaction that creates the
     * invoice: the sequence row stays locked until it commits, and a rollback
     * releases the number, so no gaps appear.
     *
     * @return array{financial_year: string, sequence: int, number: string}
     */
    public function next(DateTimeInterface $date): array
    {
        $financialYear = self::financialYear($date);

        DB::table(self::TABLE)->insertOrIgnore(['financial_year' => $financialYear, 'last_sequence' => 0]);

        $row = DB::table(self::TABLE)->where('financial_year', $financialYear)->lockForUpdate()->first();
        $last = is_object($row) && is_numeric($row->last_sequence ?? null) ? (int) $row->last_sequence : 0;
        $sequence = $last + 1;

        DB::table(self::TABLE)->where('financial_year', $financialYear)->update(['last_sequence' => $sequence]);

        return [
            'financial_year' => $financialYear,
            'sequence' => $sequence,
            'number' => sprintf('%s/%s/%06d', $this->prefix, $financialYear, $sequence),
        ];
    }
}
