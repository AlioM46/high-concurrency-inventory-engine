<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_webhook_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('provider', 30)->default('STRIPE');
            $table->string('event_id', 255)->unique();
            $table->string('event_type', 100)->index();
            $table->jsonb('payload');
            $table->string('processing_status', 30)->default('PENDING')->index(); // PENDING, PROCESSED, FAILED, IGNORED
            $table->text('error_message')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_webhook_events');
    }
};
