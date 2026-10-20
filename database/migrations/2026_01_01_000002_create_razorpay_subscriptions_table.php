<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('razorpay_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->morphs('billable');
            $table->string('type');
            $table->string('razorpay_id')->unique();
            $table->string('razorpay_plan_id');
            $table->string('plan_key')->nullable();
            $table->string('status')->index();
            $table->unsignedInteger('quantity')->default(1);
            $table->string('payment_method')->nullable();
            $table->string('mandate_status')->nullable();
            $table->string('razorpay_token_id')->nullable()->index();
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('current_start')->nullable();
            $table->timestamp('current_end')->nullable();
            $table->timestamp('charge_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamp('paused_at')->nullable();
            $table->json('notes')->nullable();
            $table->timestamp('razorpay_synced_at')->nullable();
            $table->timestamps();

            $table->index(['billable_type', 'billable_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('razorpay_subscriptions');
    }
};
