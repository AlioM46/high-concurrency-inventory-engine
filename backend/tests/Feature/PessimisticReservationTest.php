<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductWarehouse;
use App\Models\Reservation;
use App\Models\StockMovement;
use App\Models\Warehouse;
use App\Services\PessimisticLockReservationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PessimisticReservationTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;
    private Warehouse $warehouse;
    private Product $productA;
    private Product $productB;
    private ProductWarehouse $pwA;
    private ProductWarehouse $pwB;
    private PessimisticLockReservationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(PessimisticLockReservationService::class);

        $this->org = Organization::create([
            'name' => 'Mega Retail Corp',
            'slug' => 'mega-retail',
        ]);

        $this->warehouse = Warehouse::create([
            'organization_id' => $this->org->id,
            'name' => 'Dubai Main Warehouse',
            'code' => 'DXB-01',
        ]);

        $this->productA = Product::create([
            'organization_id' => $this->org->id,
            'sku' => 'LAPTOP-PRO-16',
            'name' => 'Pro Laptop 16-inch',
            'price_minor_units' => 250000,
            'currency' => 'USD',
        ]);

        $this->productB = Product::create([
            'organization_id' => $this->org->id,
            'sku' => 'PHONE-ULTRA-5G',
            'name' => 'Ultra Phone 5G',
            'price_minor_units' => 120000,
            'currency' => 'USD',
        ]);

        $this->pwA = ProductWarehouse::create([
            'organization_id' => $this->org->id,
            'product_id' => $this->productA->id,
            'warehouse_id' => $this->warehouse->id,
            'available_quantity' => 10,
            'reserved_quantity' => 0,
            'sold_quantity' => 0,
            'version' => 1,
        ]);

        $this->pwB = ProductWarehouse::create([
            'organization_id' => $this->org->id,
            'product_id' => $this->productB->id,
            'warehouse_id' => $this->warehouse->id,
            'available_quantity' => 5,
            'reserved_quantity' => 0,
            'sold_quantity' => 0,
            'version' => 1,
        ]);
    }

    public function test_can_create_pessimistic_reservation_for_single_item(): void
    {
        $payload = [
            'customer_id' => 'cust_user_441',
            'items' => [
                [
                    'product_warehouse_id' => $this->pwA->id,
                    'quantity' => 2,
                    'unit_price_minor_units' => 250000,
                ],
            ],
            'expires_in_minutes' => 10,
        ];

        $response = $this->postJson('/api/v1/reservations/pessimistic', $payload);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.customer_id', 'cust_user_441')
            ->assertJsonPath('data.status', Reservation::STATUS_ACTIVE)
            ->assertJsonPath('data.items_count', 1)
            ->assertJsonPath('data.total_minor_units', 500000)
            ->assertJsonPath('data.items.0.product_warehouse_id', $this->pwA->id)
            ->assertJsonPath('data.items.0.quantity', 2);

        $this->pwA->refresh();
        $this->assertEquals(8, $this->pwA->available_quantity);
        $this->assertEquals(2, $this->pwA->reserved_quantity);

        $this->assertDatabaseHas('reservations', [
            'customer_id' => 'cust_user_441',
            'status' => Reservation::STATUS_ACTIVE,
        ]);

        $this->assertDatabaseHas('stock_movements', [
            'product_warehouse_id' => $this->pwA->id,
            'movement_type' => StockMovement::TYPE_RESERVATION,
            'available_delta' => -2,
            'reserved_delta' => 2,
        ]);
    }

    public function test_can_create_pessimistic_reservation_for_multiple_items(): void
    {
        // Intentionally provide items in arbitrary order to verify deterministic sorting
        $payload = [
            'customer_id' => 'cust_multi_99',
            'items' => [
                [
                    'product_warehouse_id' => $this->pwB->id,
                    'quantity' => 1,
                    'unit_price_minor_units' => 120000,
                ],
                [
                    'product_warehouse_id' => $this->pwA->id,
                    'quantity' => 3,
                    'unit_price_minor_units' => 250000,
                ],
            ],
        ];

        $response = $this->postJson('/api/v1/reservations/pessimistic', $payload);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.items_count', 2)
            ->assertJsonPath('data.total_minor_units', 120000 + (3 * 250000));

        $this->pwA->refresh();
        $this->assertEquals(7, $this->pwA->available_quantity);
        $this->assertEquals(3, $this->pwA->reserved_quantity);

        $this->pwB->refresh();
        $this->assertEquals(4, $this->pwB->available_quantity);
        $this->assertEquals(1, $this->pwB->reserved_quantity);
    }

    public function test_pessimistic_reservation_fails_atomically_if_one_item_is_insufficient(): void
    {
        // Item A has 10 units (requested 2 -> OK)
        // Item B has only 5 units (requested 10 -> INSUFFICIENT!)
        $payload = [
            'customer_id' => 'cust_fail_all_or_nothing',
            'items' => [
                [
                    'product_warehouse_id' => $this->pwA->id,
                    'quantity' => 2,
                    'unit_price_minor_units' => 250000,
                ],
                [
                    'product_warehouse_id' => $this->pwB->id,
                    'quantity' => 10, // Exceeds available 5
                    'unit_price_minor_units' => 120000,
                ],
            ],
        ];

        $response = $this->postJson('/api/v1/reservations/pessimistic', $payload);

        $response->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error_code', 'INSUFFICIENT_STOCK');

        // ALL-OR-NOTHING: Item A must NOT have been deducted!
        $this->pwA->refresh();
        $this->assertEquals(10, $this->pwA->available_quantity);
        $this->assertEquals(0, $this->pwA->reserved_quantity);

        $this->pwB->refresh();
        $this->assertEquals(5, $this->pwB->available_quantity);
        $this->assertEquals(0, $this->pwB->reserved_quantity);

        // No orphaned reservation record should exist
        $this->assertDatabaseMissing('reservations', [
            'customer_id' => 'cust_fail_all_or_nothing',
        ]);
    }

    public function test_can_release_pessimistic_reservation_and_return_stock(): void
    {
        $reservation = $this->service->create([
            'customer_id' => 'cust_to_release',
            'items' => [
                [
                    'product_warehouse_id' => $this->pwA->id,
                    'quantity' => 4,
                    'unit_price_minor_units' => 250000,
                ],
            ],
        ]);

        $this->pwA->refresh();
        $this->assertEquals(6, $this->pwA->available_quantity);
        $this->assertEquals(4, $this->pwA->reserved_quantity);

        // Perform release
        $this->service->release($reservation->id, 'Customer cancelled hold');

        $this->pwA->refresh();
        $this->assertEquals(10, $this->pwA->available_quantity);
        $this->assertEquals(0, $this->pwA->reserved_quantity);

        $reservation->refresh();
        $this->assertEquals(Reservation::STATUS_RELEASED, $reservation->status);
        $this->assertNotNull($reservation->released_at);

        $this->assertDatabaseHas('stock_movements', [
            'product_warehouse_id' => $this->pwA->id,
            'movement_type' => StockMovement::TYPE_RELEASE,
            'available_delta' => 4,
            'reserved_delta' => -4,
        ]);
    }

    public function test_release_is_idempotent_if_called_multiple_times(): void
    {
        $reservation = $this->service->create([
            'customer_id' => 'cust_double_release',
            'items' => [
                [
                    'product_warehouse_id' => $this->pwA->id,
                    'quantity' => 2,
                    'unit_price_minor_units' => 250000,
                ],
            ],
        ]);

        // Release first time
        $this->service->release($reservation->id);
        $this->pwA->refresh();
        $this->assertEquals(10, $this->pwA->available_quantity);

        // Release second time (should be a safe no-op)
        $this->service->release($reservation->id);
        $this->pwA->refresh();
        $this->assertEquals(10, $this->pwA->available_quantity); // NOT 12!

        // Exactly one RELEASE movement recorded
        $releaseCount = StockMovement::where('product_warehouse_id', $this->pwA->id)
            ->where('movement_type', StockMovement::TYPE_RELEASE)
            ->count();
        $this->assertEquals(1, $releaseCount);
    }
}
