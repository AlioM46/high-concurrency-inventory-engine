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
            // 1. Create or Find Organization
            $org = Organization::firstOrCreate(
                ['slug' => 'apex-retail'],
                [
                    'name' => 'Apex Retail Global',
                    'is_active' => true,
                ]
            );

            // 2. Create or Find Warehouses
            $warehouseRiyadh = Warehouse::firstOrCreate(
                ['organization_id' => $org->id, 'code' => 'RUH-01'],
                [
                    'name' => 'Riyadh Central Fulfillment Center',
                    'location' => 'Riyadh, Saudi Arabia',
                    'is_active' => true,
                ]
            );

            $warehouseJeddah = Warehouse::firstOrCreate(
                ['organization_id' => $org->id, 'code' => 'JED-01'],
                [
                    'name' => 'Jeddah Red Sea Warehouse',
                    'location' => 'Jeddah, Saudi Arabia',
                    'is_active' => true,
                ]
            );

            // 3. Create or Find Products
            $ps5 = Product::firstOrCreate(
                ['organization_id' => $org->id, 'sku' => 'PS5-PRO-1TB'],
                [
                    'name' => 'Sony PlayStation 5 Pro 1TB',
                    'description' => 'Flagship gaming console with enhanced ray tracing and 4K 60FPS.',
                    'price_minor_units' => 79900, // $799.00
                    'currency' => 'USD',
                    'is_active' => true,
                ]
            );

            $iphone = Product::firstOrCreate(
                ['organization_id' => $org->id, 'sku' => 'IPHONE-16-PM-256'],
                [
                    'name' => 'Apple iPhone 16 Pro Max 256GB',
                    'description' => 'Titanium design, A18 Pro chip, 48MP Fusion camera.',
                    'price_minor_units' => 119900, // $1199.00
                    'currency' => 'USD',
                    'is_active' => true,
                ]
            );

            // 4. Create Product Warehouse stock entries & seed double-entry ledger receipts if not already created
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
                $pw = ProductWarehouse::firstOrCreate(
                    [
                        'organization_id' => $org->id,
                        'product_id' => $stockData['product']->id,
                        'warehouse_id' => $stockData['warehouse']->id,
                    ],
                    [
                        'available_quantity' => $stockData['quantity'],
                        'reserved_quantity' => 0,
                        'sold_quantity' => 0,
                        'version' => 1,
                    ]
                );

                // If this is a newly created record, write the initial stock receipt to ledger
                if ($pw->wasRecentlyCreated) {
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
            }
        });
    }
}
