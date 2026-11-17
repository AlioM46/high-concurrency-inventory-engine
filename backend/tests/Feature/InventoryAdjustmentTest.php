<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductWarehouse;
use App\Models\StockMovement;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class InventoryAdjustmentTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;
    private Product $product;
    private Warehouse $warehouse;
    private ProductWarehouse $pw;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::create([
            'name' => 'Acme Corp',
            'slug' => 'acme-corp',
        ]);

        $this->product = Product::create([
            'organization_id' => $this->org->id,
            'sku' => 'IPHONE-15-BLK',
            'name' => 'iPhone 15 Black 128GB',
            'price_minor_units' => 79900,
            'currency' => 'USD',
        ]);

        $this->warehouse = Warehouse::create([
            'organization_id' => $this->org->id,
            'name' => 'Riyadh Central Distribution',
            'code' => 'RUH-01',
        ]);

        $this->pw = ProductWarehouse::create([
            'organization_id' => $this->org->id,
            'product_id' => $this->product->id,
            'warehouse_id' => $this->warehouse->id,
            'available_quantity' => 20,
            'reserved_quantity' => 0,
            'sold_quantity' => 0,
            'version' => 1,
        ]);
    }

    public function test_can_perform_positive_stock_adjustment(): void
    {
        $payload = [
            'product_warehouse_id' => $this->pw->id,
            'quantity_delta' => 30,
            'reason' => 'Supplier shipment #PO-9941 received',
            'actor' => 'warehouse_supervisor',
        ];

        $response = $this->postJson('/api/v1/inventory/adjustments', $payload, [
            'Idempotency-Key' => (string) Str::uuid(),
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.movement_type', StockMovement::TYPE_ADJUSTMENT)
            ->assertJsonPath('data.deltas.quantity_delta', 30)
            ->assertJsonPath('data.deltas.available_delta', 30)
            ->assertJsonPath('data.current_inventory.available_quantity', 50)
            ->assertJsonPath('data.audit.reason', 'Supplier shipment #PO-9941 received')
            ->assertJsonPath('data.audit.actor', 'warehouse_supervisor');

        $this->pw->refresh();
        $this->assertEquals(50, $this->pw->available_quantity);
        $this->assertEquals(2, $this->pw->version);

        $this->assertDatabaseHas('stock_movements', [
            'product_warehouse_id' => $this->pw->id,
            'movement_type' => StockMovement::TYPE_ADJUSTMENT,
            'quantity_delta' => 30,
            'available_delta' => 30,
            'reason' => 'Supplier shipment #PO-9941 received',
        ]);
    }

    public function test_can_perform_negative_stock_adjustment(): void
    {
        $payload = [
            'product_warehouse_id' => $this->pw->id,
            'quantity_delta' => -8,
            'reason' => 'Water damage during warehouse roof inspection',
            'actor' => 'auditor_01',
        ];

        $response = $this->postJson('/api/v1/inventory/adjustments', $payload, [
            'Idempotency-Key' => (string) Str::uuid(),
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.deltas.quantity_delta', -8)
            ->assertJsonPath('data.deltas.available_delta', -8)
            ->assertJsonPath('data.current_inventory.available_quantity', 12);

        $this->pw->refresh();
        $this->assertEquals(12, $this->pw->available_quantity);
    }

    public function test_adjustment_fails_if_would_result_in_negative_inventory(): void
    {
        // pw available is 20, trying to subtract 25
        $payload = [
            'product_warehouse_id' => $this->pw->id,
            'quantity_delta' => -25,
            'reason' => 'Attempted write-off exceeding available balance',
        ];

        $response = $this->postJson('/api/v1/inventory/adjustments', $payload, [
            'Idempotency-Key' => (string) Str::uuid(),
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error_code', 'INSUFFICIENT_STOCK')
            ->assertJsonPath('details.requested_quantity', 25)
            ->assertJsonPath('details.available_quantity', 20);

        // Inventory must remain untouched
        $this->pw->refresh();
        $this->assertEquals(20, $this->pw->available_quantity);
    }

    public function test_adjustment_validation_rules(): void
    {
        // 1. Missing required fields and delta = 0
        $response = $this->postJson('/api/v1/inventory/adjustments', [
            'quantity_delta' => 0,
        ], [
            'Idempotency-Key' => (string) Str::uuid(),
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['product_warehouse_id', 'quantity_delta', 'reason']);

        // 2. Non-existent product warehouse ID
        $responseNonExistent = $this->postJson('/api/v1/inventory/adjustments', [
            'product_warehouse_id' => '00000000-0000-0000-0000-000000000000',
            'quantity_delta' => 5,
            'reason' => 'Valid reason',
        ], [
            'Idempotency-Key' => (string) Str::uuid(),
        ]);

        $responseNonExistent->assertStatus(422)
            ->assertJsonValidationErrors(['product_warehouse_id']);
    }
}
