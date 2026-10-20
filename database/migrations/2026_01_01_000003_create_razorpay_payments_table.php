<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('razorpay_payments', function (Blueprint $table) {
            $table->id();
            $table->morphs('billable');
            $table->string('razorpay_payment_id')->unique();
            $table->string('razorpay_order_id')->nullable()->index();
            $table->string('razorpay_subscription_id')->nullable()->index();
            $table->string('razorpay_invoice_id')->nullable();
            $table->unsignedBigInteger('amount');
            $table->string('currency', 3);
            $table->string('status');
            $table->string('method')->nullable();
            $table->unsignedBigInteger('amount_refunded')->default(0);
            $table->timestamp('captured_at')->nullable();
            $table->json('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('razorpay_payments');
    }
};
