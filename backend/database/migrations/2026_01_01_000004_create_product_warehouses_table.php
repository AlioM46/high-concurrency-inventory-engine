<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_warehouses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUuid('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignUuid('warehouse_id')->constrained('warehouses')->cascadeOnDelete();
            $table->integer('available_quantity')->default(0);
            $table->integer('reserved_quantity')->default(0);
            $table->integer('sold_quantity')->default(0);
            $table->unsignedBigInteger('version')->default(1);
            $table->timestamps();

            $table->unique(['product_id', 'warehouse_id'], 'uq_product_warehouse');
            $table->index(['organization_id', 'product_id']);
        });

        // Add the iron-clad PostgreSQL check constraints
        DB::statement('ALTER TABLE product_warehouses ADD CONSTRAINT chk_available_non_negative CHECK (available_quantity >= 0)');
        DB::statement('ALTER TABLE product_warehouses ADD CONSTRAINT chk_reserved_non_negative CHECK (reserved_quantity >= 0)');
        DB::statement('ALTER TABLE product_warehouses ADD CONSTRAINT chk_sold_non_negative CHECK (sold_quantity >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('product_warehouses');
    }
};
