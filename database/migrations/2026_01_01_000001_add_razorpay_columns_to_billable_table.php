<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table($this->table(), function (Blueprint $table) {
            $table->string('razorpay_customer_id')->nullable()->index();
            $table->string('billing_gstin')->nullable();
            $table->string('billing_state_code')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table($this->table(), function (Blueprint $table) {
            $table->dropIndex(['razorpay_customer_id']);
            $table->dropColumn(['razorpay_customer_id', 'billing_gstin', 'billing_state_code']);
        });
    }

    private function table(): string
    {
        /** @var class-string<Model> $model */
        $model = config('razorpay-billing.billable_model');

        return (new $model)->getTable();
    }
};
