<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductWarehouse;
use App\Models\Reservation;
use App\Models\StockMovement;
use App\Models\Warehouse;
use App\Services\AtomicUpdateReservationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AtomicReservationTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;
    private Warehouse $warehouse;
    private Product $productA;
    private Product $productB;
    private ProductWarehouse $pwA;
    private ProductWarehouse $pwB;
    private AtomicUpdateReservationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(AtomicUpdateReservationService::class);

        $this->org = Organization::create([
            'name' => 'Atomic Global Corp',
            'slug' => 'atomic-global',
        ]);

        $this->warehouse = Warehouse::create([
            'organization_id' => $this->org->id,
            'name' => 'Singapore High-Speed Depot',
            'code' => 'SIN-01',
        ]);

        $this->productA = Product::create([
            'organization_id' => $this->org->id,
            'sku' => 'GPU-RTX-5090',
            'name' => 'GeForce RTX 5090 32GB',
            'price_minor_units' => 199900,
            'currency' => 'USD',
        ]);

        $this->productB = Product::create([
            'organization_id' => $this->org->id,
            'sku' => 'CPU-RYZEN-9950X',
            'name' => 'AMD Ryzen 9 9950X',
            'price_minor_units' => 64900,
            'currency' => 'USD',
        ]);

        $this->pwA = ProductWarehouse::create([
            'organization_id' => $this->org->id,
            'product_id' => $this->productA->id,
            'warehouse_id' => $this->warehouse->id,
            'available_quantity' => 15,
            'reserved_quantity' => 0,
            'sold_quantity' => 0,
            'version' => 1,
        ]);

        $this->pwB = ProductWarehouse::create([
            'organization_id' => $this->org->id,
            'product_id' => $this->productB->id,
            'warehouse_id' => $this->warehouse->id,
            'available_quantity' => 8,
            'reserved_quantity' => 0,
            'sold_quantity' => 0,
            'version' => 1,
        ]);
    }

    public function test_can_create_atomic_reservation_for_single_item(): void
    {
        $payload = [
            'customer_id' => 'cust_gamer_99',
            'items' => [
                [
                    'product_warehouse_id' => $this->pwA->id,
                    'quantity' => 3,
                    'unit_price_minor_units' => 199900,
                ],
            ],
            'expires_in_minutes' => 10,
        ];

        $response = $this->postJson('/api/v1/reservations/atomic', $payload, [
            'Idempotency-Key' => (string) Str::uuid(),
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.customer_id', 'cust_gamer_99')
            ->assertJsonPath('data.status', Reservation::STATUS_ACTIVE)
            ->assertJsonPath('data.items_count', 1)
            ->assertJsonPath('data.total_minor_units', 3 * 199900)
            ->assertJsonPath('data.items.0.product_warehouse_id', $this->pwA->id)
            ->assertJsonPath('data.items.0.quantity', 3);

        $this->pwA->refresh();
        $this->assertEquals(12, $this->pwA->available_quantity);
        $this->assertEquals(3, $this->pwA->reserved_quantity);
        $this->assertEquals(2, $this->pwA->version);

        $this->assertDatabaseHas('reservations', [
            'customer_id' => 'cust_gamer_99',
            'status' => Reservation::STATUS_ACTIVE,
        ]);

        $this->assertDatabaseHas('stock_movements', [
            'product_warehouse_id' => $this->pwA->id,
            'movement_type' => StockMovement::TYPE_RESERVATION,
            'available_delta' => -3,
            'reserved_delta' => 3,
        ]);
    }

    public function test_can_create_atomic_reservation_for_multiple_items(): void
    {
        $payload = [
            'customer_id' => 'cust_pc_builder',
            'items' => [
                [
                    'product_warehouse_id' => $this->pwB->id,
                    'quantity' => 2,
                    'unit_price_minor_units' => 64900,
                ],
                [
                    'product_warehouse_id' => $this->pwA->id,
                    'quantity' => 1,
                    'unit_price_minor_units' => 199900,
                ],
            ],
        ];

        $response = $this->postJson('/api/v1/reservations/atomic', $payload, [
            'Idempotency-Key' => (string) Str::uuid(),
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.items_count', 2)
            ->assertJsonPath('data.total_minor_units', (2 * 64900) + 199900);

        $this->pwA->refresh();
        $this->assertEquals(14, $this->pwA->available_quantity);
        $this->assertEquals(1, $this->pwA->reserved_quantity);

        $this->pwB->refresh();
        $this->assertEquals(6, $this->pwB->available_quantity);
        $this->assertEquals(2, $this->pwB->reserved_quantity);
    }

    public function test_atomic_reservation_fails_atomically_if_one_item_is_insufficient(): void
    {
        // Item A has 15 (requested 2 -> OK)
        // Item B has 8 (requested 20 -> INSUFFICIENT!)
        $payload = [
            'customer_id' => 'cust_fail_atomic_cart',
            'items' => [
                [
                    'product_warehouse_id' => $this->pwA->id,
                    'quantity' => 2,
                    'unit_price_minor_units' => 199900,
                ],
                [
                    'product_warehouse_id' => $this->pwB->id,
                    'quantity' => 20, // Exceeds available 8
                    'unit_price_minor_units' => 64900,
                ],
            ],
        ];

        $response = $this->postJson('/api/v1/reservations/atomic', $payload, [
            'Idempotency-Key' => (string) Str::uuid(),
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error_code', 'INSUFFICIENT_STOCK');

        // ALL-OR-NOTHING: Item A must NOT have been deducted!
        $this->pwA->refresh();
        $this->assertEquals(15, $this->pwA->available_quantity);
        $this->assertEquals(0, $this->pwA->reserved_quantity);

        $this->pwB->refresh();
        $this->assertEquals(8, $this->pwB->available_quantity);
        $this->assertEquals(0, $this->pwB->reserved_quantity);

        // No orphaned reservation record
        $this->assertDatabaseMissing('reservations', [
            'customer_id' => 'cust_fail_atomic_cart',
        ]);
    }

    public function test_can_release_atomic_reservation_and_return_stock(): void
    {
        $reservation = $this->service->create([
            'customer_id' => 'cust_release_atomic',
            'items' => [
                [
                    'product_warehouse_id' => $this->pwA->id,
                    'quantity' => 5,
                    'unit_price_minor_units' => 199900,
                ],
            ],
        ]);

        $this->pwA->refresh();
        $this->assertEquals(10, $this->pwA->available_quantity);
        $this->assertEquals(5, $this->pwA->reserved_quantity);

        // Perform release
        $this->service->release($reservation->id, 'Atomic hold expired');

        $this->pwA->refresh();
        $this->assertEquals(15, $this->pwA->available_quantity);
        $this->assertEquals(0, $this->pwA->reserved_quantity);

        $reservation->refresh();
        $this->assertEquals(Reservation::STATUS_RELEASED, $reservation->status);
        $this->assertNotNull($reservation->released_at);

        $this->assertDatabaseHas('stock_movements', [
            'product_warehouse_id' => $this->pwA->id,
            'movement_type' => StockMovement::TYPE_RELEASE,
            'available_delta' => 5,
            'reserved_delta' => -5,
        ]);
    }

    public function test_atomic_release_is_idempotent(): void
    {
        $reservation = $this->service->create([
            'customer_id' => 'cust_double_release_atomic',
            'items' => [
                [
                    'product_warehouse_id' => $this->pwA->id,
                    'quantity' => 2,
                    'unit_price_minor_units' => 199900,
                ],
            ],
        ]);

        $this->service->release($reservation->id);
        $this->pwA->refresh();
        $this->assertEquals(15, $this->pwA->available_quantity);

        // Second release must be a no-op
        $this->service->release($reservation->id);
        $this->pwA->refresh();
        $this->assertEquals(15, $this->pwA->available_quantity);
    }
}
