<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductWarehouse;
use App\Models\Reservation;
use App\Models\Warehouse;
use App\Services\PessimisticLockReservationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class OrderCreationTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;
    private Warehouse $warehouse;
    private Product $productA;
    private Product $productB;
    private ProductWarehouse $pwA;
    private ProductWarehouse $pwB;
    private PessimisticLockReservationService $reservationService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->reservationService = app(PessimisticLockReservationService::class);

        $this->org = Organization::create([
            'name' => 'Tech Retail Global',
            'slug' => 'tech-retail-global',
            'is_active' => true,
        ]);

        $this->warehouse = Warehouse::create([
            'organization_id' => $this->org->id,
            'name' => 'Main Distribution Center',
            'code' => 'MDC-01',
            'is_active' => true,
        ]);

        $this->productA = Product::create([
            'organization_id' => $this->org->id,
            'sku' => 'LAPTOP-PRO-16',
            'name' => 'Laptop Pro 16',
            'price_minor_units' => 250000, // $2,500.00
            'currency' => 'USD',
            'is_active' => true,
        ]);

        $this->productB = Product::create([
            'organization_id' => $this->org->id,
            'sku' => 'PHONE-ULTRA-5G',
            'name' => 'Phone Ultra 5G',
            'price_minor_units' => 120000, // $1,200.00
            'currency' => 'USD',
            'is_active' => true,
        ]);

        $this->pwA = ProductWarehouse::create([
            'organization_id' => $this->org->id,
            'warehouse_id' => $this->warehouse->id,
            'product_id' => $this->productA->id,
            'available_quantity' => 10,
            'reserved_quantity' => 0,
            'sold_quantity' => 0,
        ]);

        $this->pwB = ProductWarehouse::create([
            'organization_id' => $this->org->id,
            'warehouse_id' => $this->warehouse->id,
            'product_id' => $this->productB->id,
            'available_quantity' => 20,
            'reserved_quantity' => 0,
            'sold_quantity' => 0,
        ]);
    }

    /**
     * Helper to create an active reservation for tests.
     */
    private function createActiveReservation(int $qtyA = 2, int $qtyB = 1): Reservation
    {
        return $this->reservationService->create([
            'customer_id' => 'cust-12345',
            'organization_id' => $this->org->id,
            'expires_in_minutes' => 15,
            'items' => [
                [
                    'product_warehouse_id' => $this->pwA->id,
                    'quantity' => $qtyA,
                    'unit_price_minor_units' => 250000,
                ],
                [
                    'product_warehouse_id' => $this->pwB->id,
                    'quantity' => $qtyB,
                    'unit_price_minor_units' => 120000,
                ],
            ],
        ]);
    }

    public function test_creates_order_successfully_from_active_reservation(): void
    {
        $reservation = $this->createActiveReservation(2, 1);
        // Total expected = (2 * 250000) + (1 * 120000) = 620000 minor units ($6,200.00)

        $response = $this->withHeaders([
            'Idempotency-Key' => (string) Str::uuid(),
        ])->postJson('/api/v1/orders', [
            'reservation_id' => $reservation->id,
            'customer_email' => 'customer@example.com',
            'currency' => 'USD',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'PENDING_PAYMENT')
            ->assertJsonPath('data.customer_id', 'cust-12345')
            ->assertJsonPath('data.customer_email', 'customer@example.com')
            ->assertJsonPath('data.reservation_id', $reservation->id)
            ->assertJsonPath('data.currency', 'USD')
            ->assertJsonPath('data.total_amount_minor_units', 620000)
            ->assertJsonPath('data.total_amount_formatted', '6,200.00')
            ->assertJsonPath('data.items_count', 2);

        $orderNumber = $response->json('data.order_number');
        $this->assertMatchesRegularExpression('/^ORD-\d{8}-[A-Z0-9]{6}$/', $orderNumber);

        // Verify order in database
        $this->assertDatabaseHas('orders', [
            'reservation_id' => $reservation->id,
            'customer_email' => 'customer@example.com',
            'order_number' => $orderNumber,
            'status' => 'PENDING_PAYMENT',
            'total_amount_minor_units' => 620000,
        ]);

        // Verify order items snapshot
        $this->assertDatabaseCount('order_items', 2);
    }

    public function test_cannot_create_order_with_nonexistent_reservation(): void
    {
        $nonExistentReservationId = (string) Str::uuid();

        $response = $this->withHeaders([
            'Idempotency-Key' => (string) Str::uuid(),
        ])->postJson('/api/v1/orders', [
            'reservation_id' => $nonExistentReservationId,
            'customer_email' => 'customer@example.com',
        ]);

        $this->assertContains($response->status(), [404, 422]);
        if ($response->status() === 422) {
            $response->assertJsonValidationErrors('reservation_id');
        } else {
            $response->assertJsonPath('error_code', 'RESERVATION_NOT_FOUND');
        }
    }

    public function test_order_service_throws_not_found_exception_for_missing_reservation(): void
    {
        $this->expectException(\App\Exceptions\InvalidReservationException::class);
        $this->expectExceptionCode(404);

        $orderService = app(\App\Services\OrderService::class);
        $orderService->createFromReservation([
            'reservation_id' => (string) Str::uuid(),
            'customer_email' => 'customer@example.com',
        ]);
    }

    public function test_cannot_create_order_when_reservation_is_not_active(): void
    {
        $reservation = $this->createActiveReservation(1, 1);
        $reservation->update(['status' => Reservation::STATUS_RELEASED]);

        $response = $this->withHeaders([
            'Idempotency-Key' => (string) Str::uuid(),
        ])->postJson('/api/v1/orders', [
            'reservation_id' => $reservation->id,
            'customer_email' => 'customer@example.com',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error_code', 'RESERVATION_NOT_ACTIVE');
    }

    public function test_cannot_create_order_when_reservation_has_expired_ttl(): void
    {
        $reservation = $this->createActiveReservation(1, 1);
        $reservation->update(['expires_at' => now()->subMinute()]);

        $response = $this->withHeaders([
            'Idempotency-Key' => (string) Str::uuid(),
        ])->postJson('/api/v1/orders', [
            'reservation_id' => $reservation->id,
            'customer_email' => 'customer@example.com',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error_code', 'RESERVATION_EXPIRED');
    }

    public function test_cannot_create_duplicate_order_for_same_reservation(): void
    {
        $reservation = $this->createActiveReservation(1, 1);

        // First order creation succeeds
        $firstResponse = $this->withHeaders([
            'Idempotency-Key' => (string) Str::uuid(),
        ])->postJson('/api/v1/orders', [
            'reservation_id' => $reservation->id,
            'customer_email' => 'customer@example.com',
        ]);
        $firstResponse->assertStatus(201);

        // Second order creation with different idempotency key fails with 409 Conflict
        $secondResponse = $this->withHeaders([
            'Idempotency-Key' => (string) Str::uuid(),
        ])->postJson('/api/v1/orders', [
            'reservation_id' => $reservation->id,
            'customer_email' => 'different@example.com',
        ]);

        $secondResponse->assertStatus(409)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error_code', 'RESERVATION_ALREADY_ORDERED');

        $this->assertDatabaseCount('orders', 1);
    }

    public function test_cancelling_order_releases_stock_back_to_inventory(): void
    {
        $reservation = $this->createActiveReservation(3, 2);

        // Check reserved inventory after reservation
        $this->assertEquals(7, $this->pwA->fresh()->available_quantity);
        $this->assertEquals(3, $this->pwA->fresh()->reserved_quantity);
        $this->assertEquals(18, $this->pwB->fresh()->available_quantity);
        $this->assertEquals(2, $this->pwB->fresh()->reserved_quantity);

        // Create order
        $createResponse = $this->withHeaders([
            'Idempotency-Key' => (string) Str::uuid(),
        ])->postJson('/api/v1/orders', [
            'reservation_id' => $reservation->id,
            'customer_email' => 'customer@example.com',
        ]);
        $createResponse->assertStatus(201);
        $orderId = $createResponse->json('data.id');

        // Cancel order
        $cancelResponse = $this->withHeaders([
            'Idempotency-Key' => (string) Str::uuid(),
        ])->postJson("/api/v1/orders/{$orderId}/cancel", [
            'reason' => 'Customer changed mind',
        ]);

        $cancelResponse->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'CANCELLED');

        // Check order in DB
        $order = Order::findOrFail($orderId);
        $this->assertEquals(Order::STATUS_CANCELLED, $order->status);
        $this->assertNotNull($order->cancelled_at);

        // Check reservation is released
        $this->assertEquals(Reservation::STATUS_RELEASED, $reservation->fresh()->status);
        $this->assertNotNull($reservation->fresh()->released_at);

        // Check inventory was returned: reserved should be 0, available should be back to original (10 and 20)
        $this->assertEquals(0, $this->pwA->fresh()->reserved_quantity);
        $this->assertEquals(10, $this->pwA->fresh()->available_quantity);
        $this->assertEquals(0, $this->pwB->fresh()->reserved_quantity);
        $this->assertEquals(20, $this->pwB->fresh()->available_quantity);

        // Check stock movement ledger contains release records
        $this->assertDatabaseHas('stock_movements', [
            'reference_id' => $reservation->id,
            'movement_type' => 'RELEASE',
            'product_warehouse_id' => $this->pwA->id,
            'available_delta' => 3,
            'reserved_delta' => -3,
        ]);
    }

    public function test_cannot_cancel_already_cancelled_order(): void
    {
        $reservation = $this->createActiveReservation(1, 1);

        $orderResponse = $this->withHeaders([
            'Idempotency-Key' => (string) Str::uuid(),
        ])->postJson('/api/v1/orders', [
            'reservation_id' => $reservation->id,
            'customer_email' => 'customer@example.com',
        ]);
        $orderId = $orderResponse->json('data.id');

        // First cancel succeeds
        $this->withHeaders([
            'Idempotency-Key' => (string) Str::uuid(),
        ])->postJson("/api/v1/orders/{$orderId}/cancel")->assertStatus(200);

        // Second cancel fails because status is no longer PENDING_PAYMENT
        $secondCancel = $this->withHeaders([
            'Idempotency-Key' => (string) Str::uuid(),
        ])->postJson("/api/v1/orders/{$orderId}/cancel");

        $secondCancel->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error_code', 'INVALID_ORDER_STATE');
    }

    public function test_idempotent_order_creation_replays_cached_response(): void
    {
        $reservation = $this->createActiveReservation(1, 1);
        $idempotencyKey = (string) Str::uuid();

        $payload = [
            'reservation_id' => $reservation->id,
            'customer_email' => 'idempotent@example.com',
            'currency' => 'USD',
        ];

        // First request creates the order
        $firstResponse = $this->withHeaders([
            'Idempotency-Key' => $idempotencyKey,
        ])->postJson('/api/v1/orders', $payload);

        $firstResponse->assertStatus(201);
        $orderId = $firstResponse->json('data.id');
        $orderNumber = $firstResponse->json('data.order_number');

        // Second identical request with same idempotency key replays the response
        $secondResponse = $this->withHeaders([
            'Idempotency-Key' => $idempotencyKey,
        ])->postJson('/api/v1/orders', $payload);

        $secondResponse->assertStatus(201)
            ->assertJsonPath('data.id', $orderId)
            ->assertJsonPath('data.order_number', $orderNumber)
            ->assertHeader('X-Idempotent-Replay', 'true');

        // Ensure only 1 order exists in database
        $this->assertDatabaseCount('orders', 1);
    }
}
