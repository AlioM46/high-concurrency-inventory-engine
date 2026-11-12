<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('idempotency_keys', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('idempotency_key', 255)->unique();
            $table->string('request_hash', 64);
            $table->string('endpoint', 255);
            $table->integer('response_status')->nullable();
            $table->jsonb('response_body')->nullable();
            $table->timestamp('expires_at')->index();
            $table->timestamps();

            $table->index(['organization_id', 'idempotency_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');
    }
};
