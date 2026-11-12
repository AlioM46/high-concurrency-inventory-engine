<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUuid('product_warehouse_id')->constrained('product_warehouses')->cascadeOnDelete();
            $table->string('movement_type', 30)->index(); // RECEIPT, RESERVATION, RELEASE, SALE, ADJUSTMENT
            $table->integer('quantity_delta')->default(0);
            $table->integer('available_delta')->default(0);
            $table->integer('reserved_delta')->default(0);
            $table->integer('sold_delta')->default(0);
            $table->string('reference_type', 50)->nullable();
            $table->uuid('reference_id')->nullable();
            $table->string('reason')->nullable();
            $table->string('created_by')->nullable();
            $table->timestamp('created_at')->useCurrent()->index();

            $table->index(['product_warehouse_id', 'created_at']);
            $table->index(['reference_type', 'reference_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
    }
};
