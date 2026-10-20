<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tables for the optional GST invoice module. Created even while the module
 * is disabled, so enabling it later needs no new migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('razorpay_invoices', function (Blueprint $table) {
            $table->id();
            $table->morphs('billable');
            $table->string('razorpay_payment_id')->unique();
            $table->string('number')->unique();
            $table->string('financial_year', 7);
            $table->unsignedInteger('sequence');
            $table->unsignedBigInteger('subtotal');
            $table->unsignedBigInteger('cgst');
            $table->unsignedBigInteger('sgst');
            $table->unsignedBigInteger('igst');
            $table->unsignedBigInteger('total');
            $table->string('place_of_supply', 2);
            $table->string('buyer_gstin')->nullable();
            $table->timestamp('issued_at');
            $table->string('pdf_path')->nullable();
            $table->timestamps();

            $table->unique(['financial_year', 'sequence']);
        });

        // One row per financial year, locked while a number is assigned.
        Schema::create('razorpay_invoice_sequences', function (Blueprint $table) {
            $table->string('financial_year', 7)->primary();
            $table->unsignedInteger('last_sequence');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('razorpay_invoice_sequences');
        Schema::dropIfExists('razorpay_invoices');
    }
};
