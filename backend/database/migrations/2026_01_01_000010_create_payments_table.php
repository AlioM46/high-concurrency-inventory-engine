<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUuid('order_id')->constrained('orders')->cascadeOnDelete();
            $table->string('provider', 30)->default('STRIPE');
            $table->string('provider_payment_intent_id')->nullable()->index();
            $table->unsignedBigInteger('amount_minor_units');
            $table->string('currency', 3)->default('USD');
            $table->string('status', 30)->default('PENDING')->index(); // PENDING, SUCCEEDED, FAILED, REFUNDED
            $table->text('failure_reason')->nullable();
            $table->timestamp('refunded_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
