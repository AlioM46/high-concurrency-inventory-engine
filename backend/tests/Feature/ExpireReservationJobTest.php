<?php

namespace Tests\Feature;

use App\Jobs\ExpireReservationJob;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductWarehouse;
use App\Models\Reservation;
use App\Models\ReservationItem;
use App\Models\StockMovement;
use App\Models\Warehouse;
use App\Services\AtomicUpdateReservationService;
use App\Services\PessimisticLockReservationService;
use App\Services\StockMovementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class ExpireReservationJobTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;
    private Warehouse $warehouse;
    private Product $product;
    private ProductWarehouse $pw;
    private StockMovementService $stockService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->stockService = app(StockMovementService::class);

        $this->org = Organization::create([
            'name' => 'Titan Logistics',
            'slug' => 'titan-logistics',
        ]);

        $this->warehouse = Warehouse::create([
            'organization_id' => $this->org->id,
            'name' => 'Dammam Eastern Hub',
            'code' => 'DMM-01',
        ]);

        $this->product = Product::create([
            'organization_id' => $this->org->id,
            'sku' => 'IPHONE-16-PRO',
            'name' => 'iPhone 16 Pro 256GB',
            'price_minor_units' => 99900,
            'currency' => 'USD',
        ]);

        $this->pw = ProductWarehouse::create([
            'product_id' => $this->product->id,
            'warehouse_id' => $this->warehouse->id,
            'organization_id' => $this->org->id,
            'available_quantity' => 20,
            'reserved_quantity' => 0,
            'sold_quantity' => 0,
            'version' => 1,
        ]);
    }

    public function test_job_is_dispatched_when_pessimistic_reservation_is_created(): void
    {
        Queue::fake();

        $pessimisticService = app(PessimisticLockReservationService::class);

        $reservation = $pessimisticService->create([
            'customer_id' => 'cust_pessimistic_exp',
            'expires_in_minutes' => 10,
            'items' => [
                [
                    'product_warehouse_id' => $this->pw->id,
                    'quantity' => 2,
                    'unit_price_minor_units' => 99900,
                ],
            ],
        ]);

        Queue::assertPushed(ExpireReservationJob::class, function ($job) use ($reservation) {
            return $job->reservationId === $reservation->id;
        });
    }

    public function test_job_is_dispatched_when_atomic_reservation_is_created(): void
    {
        Queue::fake();

        $atomicService = app(AtomicUpdateReservationService::class);

        $reservation = $atomicService->create([
            'customer_id' => 'cust_atomic_exp',
            'expires_in_minutes' => 10,
            'items' => [
                [
                    'product_warehouse_id' => $this->pw->id,
                    'quantity' => 3,
                    'unit_price_minor_units' => 99900,
                ],
            ],
        ]);

        Queue::assertPushed(ExpireReservationJob::class, function ($job) use ($reservation) {
            return $job->reservationId === $reservation->id;
        });
    }

    public function test_job_execution_expires_active_reservation_and_returns_stock(): void
    {
        // 1. Manually reserve 4 units
        $this->stockService->recordReservation(
            productWarehouse: $this->pw,
            quantity: 4,
            reservationId: (string) Str::uuid()
        );

        $this->pw->refresh();
        $this->assertEquals(16, $this->pw->available_quantity);
        $this->assertEquals(4, $this->pw->reserved_quantity);

        $reservation = Reservation::create([
            'organization_id' => $this->org->id,
            'customer_id' => 'cust_to_be_expired',
            'status' => Reservation::STATUS_ACTIVE,
            'expires_at' => now()->subMinute(), // Expired
        ]);

        ReservationItem::create([
            'reservation_id' => $reservation->id,
            'product_warehouse_id' => $this->pw->id,
            'quantity' => 4,
            'unit_price_minor_units' => 99900,
        ]);

        // 2. Execute the job
        $job = new ExpireReservationJob($reservation->id);
        $job->handle($this->stockService);

        // 3. Verify reservation status transitioned to EXPIRED
        $reservation->refresh();
        $this->assertEquals(Reservation::STATUS_EXPIRED, $reservation->status);
        $this->assertNotNull($reservation->released_at);

        // 4. Verify inventory restored to available
        $this->pw->refresh();
        $this->assertEquals(20, $this->pw->available_quantity);
        $this->assertEquals(0, $this->pw->reserved_quantity);

        // 5. Verify ledger entry recorded
        $this->assertDatabaseHas('stock_movements', [
            'product_warehouse_id' => $this->pw->id,
            'movement_type' => StockMovement::TYPE_RELEASE,
            'available_delta' => 4,
            'reserved_delta' => -4,
            'reference_type' => 'Reservation',
            'reference_id' => $reservation->id,
            'created_by' => 'system_expiry_worker',
        ]);
    }

    public function test_job_execution_is_idempotent_if_already_released(): void
    {
        $reservation = Reservation::create([
            'organization_id' => $this->org->id,
            'customer_id' => 'cust_already_released',
            'status' => Reservation::STATUS_RELEASED,
            'expires_at' => now()->subMinutes(5),
            'released_at' => now()->subMinutes(2),
        ]);

        ReservationItem::create([
            'reservation_id' => $reservation->id,
            'product_warehouse_id' => $this->pw->id,
            'quantity' => 2,
            'unit_price_minor_units' => 99900,
        ]);

        $initialMovementsCount = StockMovement::count();

        // Execute job
        $job = new ExpireReservationJob($reservation->id);
        $job->handle($this->stockService);

        // Status should remain unchanged and no new movement logged
        $reservation->refresh();
        $this->assertEquals(Reservation::STATUS_RELEASED, $reservation->status);
        $this->assertEquals($initialMovementsCount, StockMovement::count());
    }

    public function test_job_execution_does_not_expire_confirmed_order_reservation(): void
    {
        $reservation = Reservation::create([
            'organization_id' => $this->org->id,
            'customer_id' => 'cust_confirmed_buyer',
            'status' => Reservation::STATUS_CONFIRMED,
            'expires_at' => now()->subMinutes(1),
            'confirmed_at' => now()->subSeconds(30),
        ]);

        ReservationItem::create([
            'reservation_id' => $reservation->id,
            'product_warehouse_id' => $this->pw->id,
            'quantity' => 1,
            'unit_price_minor_units' => 99900,
        ]);

        $initialMovementsCount = StockMovement::count();

        // Execute job
        $job = new ExpireReservationJob($reservation->id);
        $job->handle($this->stockService);

        // Status remains CONFIRMED
        $reservation->refresh();
        $this->assertEquals(Reservation::STATUS_CONFIRMED, $reservation->status);
        $this->assertEquals($initialMovementsCount, StockMovement::count());
    }

    public function test_job_handles_non_existent_reservation_gracefully(): void
    {
        $nonExistentId = (string) Str::uuid();

        $job = new ExpireReservationJob($nonExistentId);
        $job->handle($this->stockService);

        // Should complete without throwing exception
        $this->assertTrue(true);
    }
}
