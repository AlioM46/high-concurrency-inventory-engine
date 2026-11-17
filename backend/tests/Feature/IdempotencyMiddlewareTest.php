<?php

namespace Tests\Feature;

use App\Models\IdempotencyKey;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductWarehouse;
use App\Models\Reservation;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

class IdempotencyMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;
    private Warehouse $warehouse;
    private Product $product;
    private ProductWarehouse $pw;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::create([
            'name' => 'Nexus Retail',
            'slug' => 'nexus-retail',
        ]);

        $this->warehouse = Warehouse::create([
            'organization_id' => $this->org->id,
            'name' => 'Jeddah Regional Depot',
            'code' => 'JED-01',
        ]);

        $this->product = Product::create([
            'organization_id' => $this->org->id,
            'sku' => 'CONSOLE-PS5-PRO',
            'name' => 'PlayStation 5 Pro',
            'price_minor_units' => 69900,
            'currency' => 'USD',
        ]);

        $this->pw = ProductWarehouse::create([
            'product_id' => $this->product->id,
            'warehouse_id' => $this->warehouse->id,
            'organization_id' => $this->org->id,
            'available_quantity' => 10,
            'reserved_quantity' => 0,
            'sold_quantity' => 0,
            'version' => 1,
        ]);
    }

    public function test_missing_idempotency_key_returns_400(): void
    {
        $payload = [
            'customer_id' => 'cust_user_1',
            'items' => [
                [
                    'product_warehouse_id' => $this->pw->id,
                    'quantity' => 2,
                    'unit_price_minor_units' => 69900,
                ],
            ],
        ];

        // Intentionally omit Idempotency-Key header
        $response = $this->postJson('/api/v1/reservations/pessimistic', $payload);

        $response->assertStatus(400)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error_code', 'MISSING_IDEMPOTENCY_KEY');
    }

    public function test_invalid_uuid_idempotency_key_returns_400(): void
    {
        $payload = [
            'customer_id' => 'cust_user_1',
            'items' => [
                [
                    'product_warehouse_id' => $this->pw->id,
                    'quantity' => 2,
                    'unit_price_minor_units' => 69900,
                ],
            ],
        ];

        $response = $this->postJson('/api/v1/reservations/pessimistic', $payload, [
            'Idempotency-Key' => 'not-a-valid-uuid-format',
        ]);

        $response->assertStatus(400)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error_code', 'INVALID_IDEMPOTENCY_KEY');
    }

    public function test_replay_returns_cached_response_without_duplicate_stock_deduction(): void
    {
        $idempotencyKey = (string) Str::uuid();
        $payload = [
            'customer_id' => 'cust_user_vip',
            'items' => [
                [
                    'product_warehouse_id' => $this->pw->id,
                    'quantity' => 2,
                    'unit_price_minor_units' => 69900,
                ],
            ],
        ];

        // 1. First execution
        $firstResponse = $this->postJson('/api/v1/reservations/pessimistic', $payload, [
            'Idempotency-Key' => $idempotencyKey,
        ]);

        $firstResponse->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertHeader('X-Idempotent-Replay', 'false');

        $reservationId = $firstResponse->json('data.id');

        // Verify stock deducted once: 10 - 2 = 8
        $this->pw->refresh();
        $this->assertEquals(8, $this->pw->available_quantity);
        $this->assertEquals(2, $this->pw->reserved_quantity);
        $this->assertEquals(1, Reservation::count());

        // 2. Second execution (Replay with same key & same payload)
        $secondResponse = $this->postJson('/api/v1/reservations/pessimistic', $payload, [
            'Idempotency-Key' => $idempotencyKey,
        ]);

        $secondResponse->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $reservationId)
            ->assertHeader('X-Idempotent-Replay', 'true');

        // CRUCIAL: Stock must still be 8, NOT 6! Only 1 reservation must exist!
        $this->pw->refresh();
        $this->assertEquals(8, $this->pw->available_quantity);
        $this->assertEquals(2, $this->pw->reserved_quantity);
        $this->assertEquals(1, Reservation::count());
    }

    public function test_reusing_key_with_different_payload_returns_409_conflict(): void
    {
        $idempotencyKey = (string) Str::uuid();

        $originalPayload = [
            'customer_id' => 'cust_original',
            'items' => [
                [
                    'product_warehouse_id' => $this->pw->id,
                    'quantity' => 1,
                    'unit_price_minor_units' => 69900,
                ],
            ],
        ];

        // 1. Initial successful call
        $firstResponse = $this->postJson('/api/v1/reservations/pessimistic', $originalPayload, [
            'Idempotency-Key' => $idempotencyKey,
        ]);
        $firstResponse->assertStatus(201);

        // 2. Tampered / altered payload reusing the same key
        $tamperedPayload = [
            'customer_id' => 'cust_tampered',
            'items' => [
                [
                    'product_warehouse_id' => $this->pw->id,
                    'quantity' => 4, // Changed quantity!
                    'unit_price_minor_units' => 69900,
                ],
            ],
        ];

        $secondResponse = $this->postJson('/api/v1/reservations/pessimistic', $tamperedPayload, [
            'Idempotency-Key' => $idempotencyKey,
        ]);

        $secondResponse->assertStatus(409)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error_code', 'IDEMPOTENCY_PAYLOAD_MISMATCH');
    }

    public function test_in_flight_concurrent_request_returns_409_conflict(): void
    {
        $idempotencyKey = (string) Str::uuid();

        $payload = [
            'customer_id' => 'cust_concurrent',
            'items' => [
                [
                    'product_warehouse_id' => $this->pw->id,
                    'quantity' => 1,
                    'unit_price_minor_units' => 69900,
                ],
            ],
        ];

        // Simulate an active Redis distributed lock held by an in-flight worker
        $lock = Cache::lock("idempotency_lock:{$idempotencyKey}", 30);
        $this->assertTrue($lock->get());

        $response = $this->postJson('/api/v1/reservations/pessimistic', $payload, [
            'Idempotency-Key' => $idempotencyKey,
        ]);

        $response->assertStatus(409)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error_code', 'IDEMPOTENT_REQUEST_IN_FLIGHT');

        $lock->release();
    }

    public function test_idempotent_caching_also_works_for_atomic_reservation_endpoint(): void
    {
        $idempotencyKey = (string) Str::uuid();
        $payload = [
            'customer_id' => 'cust_atomic_user',
            'items' => [
                [
                    'product_warehouse_id' => $this->pw->id,
                    'quantity' => 3,
                    'unit_price_minor_units' => 69900,
                ],
            ],
        ];

        // 1. Execute atomic reservation
        $res1 = $this->postJson('/api/v1/reservations/atomic', $payload, [
            'Idempotency-Key' => $idempotencyKey,
        ]);
        $res1->assertStatus(201)
            ->assertHeader('X-Idempotent-Replay', 'false');

        $this->pw->refresh();
        $this->assertEquals(7, $this->pw->available_quantity);
        $this->assertEquals(3, $this->pw->reserved_quantity);

        // 2. Replay atomic reservation
        $res2 = $this->postJson('/api/v1/reservations/atomic', $payload, [
            'Idempotency-Key' => $idempotencyKey,
        ]);
        $res2->assertStatus(201)
            ->assertHeader('X-Idempotent-Replay', 'true');

        // Stock must remain unchanged
        $this->pw->refresh();
        $this->assertEquals(7, $this->pw->available_quantity);
        $this->assertEquals(3, $this->pw->reserved_quantity);
        $this->assertEquals(1, Reservation::count());
    }
}
