<?php

namespace Database\Seeders;

use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductWarehouse;
use App\Models\StockMovement;
use App\Models\Warehouse;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            // 1. Create Organization
            $org = Organization::create([
                'name' => 'Apex Retail Global',
                'slug' => 'apex-retail',
                'is_active' => true,
            ]);

            // 2. Create Warehouses
            $warehouseRiyadh = Warehouse::create([
                'organization_id' => $org->id,
                'name' => 'Riyadh Central Fulfillment Center',
                'code' => 'RUH-01',
                'location' => 'Riyadh, Saudi Arabia',
                'is_active' => true,
            ]);

            $warehouseJeddah = Warehouse::create([
                'organization_id' => $org->id,
                'name' => 'Jeddah Red Sea Warehouse',
                'code' => 'JED-01',
                'location' => 'Jeddah, Saudi Arabia',
                'is_active' => true,
            ]);

            // 3. Create Products
            $ps5 = Product::create([
                'organization_id' => $org->id,
                'sku' => 'PS5-PRO-1TB',
                'name' => 'Sony PlayStation 5 Pro 1TB',
                'description' => 'Flagship gaming console with enhanced ray tracing and 4K 60FPS.',
                'price_minor_units' => 79900, // $799.00
                'currency' => 'USD',
                'is_active' => true,
            ]);

            $iphone = Product::create([
                'organization_id' => $org->id,
                'sku' => 'IPHONE-16-PM-256',
                'name' => 'Apple iPhone 16 Pro Max 256GB',
                'description' => 'Titanium design, A18 Pro chip, 48MP Fusion camera.',
                'price_minor_units' => 119900, // $1199.00
                'currency' => 'USD',
                'is_active' => true,
            ]);

            // 4. Create Product Warehouse stock entries & seed double-entry ledger receipts
            $initialStocks = [
                [
                    'product' => $ps5,
                    'warehouse' => $warehouseRiyadh,
                    'quantity' => 20, // The famous 20 units for our flash sale scenario!
                ],
                [
                    'product' => $ps5,
                    'warehouse' => $warehouseJeddah,
                    'quantity' => 50,
                ],
                [
                    'product' => $iphone,
                    'warehouse' => $warehouseRiyadh,
                    'quantity' => 15,
                ],
            ];

            foreach ($initialStocks as $stockData) {
                $pw = ProductWarehouse::create([
                    'organization_id' => $org->id,
                    'product_id' => $stockData['product']->id,
                    'warehouse_id' => $stockData['warehouse']->id,
                    'available_quantity' => $stockData['quantity'],
                    'reserved_quantity' => 0,
                    'sold_quantity' => 0,
                    'version' => 1,
                ]);

                // Record initial stock receipt in the double-entry ledger
                StockMovement::create([
                    'organization_id' => $org->id,
                    'product_warehouse_id' => $pw->id,
                    'movement_type' => StockMovement::TYPE_RECEIPT,
                    'quantity_delta' => $stockData['quantity'],
                    'available_delta' => $stockData['quantity'],
                    'reserved_delta' => 0,
                    'sold_delta' => 0,
                    'reference_type' => 'InitialStockReceipt',
                    'reason' => 'Initial warehouse stock onboarding',
                    'created_by' => 'system_seeder',
                    'created_at' => now(),
                ]);
            }
        });
    }
}
