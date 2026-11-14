<?php

namespace Tests\Feature;

use App\Exceptions\InsufficientStockException;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductWarehouse;
use App\Models\StockMovement;
use App\Models\Warehouse;
use App\Services\StockMovementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StockMovementServiceTest extends TestCase
{
    use RefreshDatabase;

    private StockMovementService $service;
    private Organization $org;
    private Product $product;
    private Warehouse $warehouse;
    private ProductWarehouse $pw;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(StockMovementService::class);

        $this->org = Organization::create([
            'name' => 'Acme Corporation',
            'slug' => 'acme-corp',
        ]);

        $this->product = Product::create([
            'organization_id' => $this->org->id,
            'sku' => 'TEST-SKU-01',
            'name' => 'Test Widget',
            'price_minor_units' => 5000,
            'currency' => 'USD',
        ]);

        $this->warehouse = Warehouse::create([
            'organization_id' => $this->org->id,
            'name' => 'Main Depot',
            'code' => 'DEPOT-1',
        ]);

        $this->pw = ProductWarehouse::create([
            'organization_id' => $this->org->id,
            'product_id' => $this->product->id,
            'warehouse_id' => $this->warehouse->id,
            'available_quantity' => 0,
            'reserved_quantity' => 0,
            'sold_quantity' => 0,
        ]);
    }

    public function test_can_record_stock_receipt(): void
    {
        $movement = $this->service->recordReceipt($this->pw, 50, 'Supplier delivery #100');

        $this->pw->refresh();
        $this->assertEquals(50, $this->pw->available_quantity);
        $this->assertEquals(0, $this->pw->reserved_quantity);
        $this->assertEquals(0, $this->pw->sold_quantity);

        $this->assertDatabaseHas('stock_movements', [
            'id' => $movement->id,
            'movement_type' => StockMovement::TYPE_RECEIPT,
            'available_delta' => 50,
            'reserved_delta' => 0,
            'sold_delta' => 0,
            'quantity_delta' => 50,
        ]);
    }

    public function test_can_record_reservation_and_move_stock(): void
    {
        $this->service->recordReceipt($this->pw, 20);

        $movement = $this->service->recordReservation($this->pw, 5, 'res_test_123');

        $this->pw->refresh();
        $this->assertEquals(15, $this->pw->available_quantity);
        $this->assertEquals(5, $this->pw->reserved_quantity);
        $this->assertEquals(0, $this->pw->sold_quantity);

        $this->assertEquals(-5, $movement->available_delta);
        $this->assertEquals(+5, $movement->reserved_delta);
        $this->assertEquals(0, $movement->quantity_delta);
    }

    public function test_cannot_reserve_more_than_available_stock(): void
    {
        $this->service->recordReceipt($this->pw, 3);

        $this->expectException(InsufficientStockException::class);

        // Attempting to reserve 5 when only 3 exist
        $this->service->recordReservation($this->pw, 5, 'res_test_fail');
    }

    public function test_can_release_reservation_back_to_available(): void
    {
        $this->service->recordReceipt($this->pw, 10);
        $this->service->recordReservation($this->pw, 4, 'res_hold_4');

        $this->pw->refresh();
        $this->assertEquals(6, $this->pw->available_quantity);
        $this->assertEquals(4, $this->pw->reserved_quantity);

        // Release the reservation
        $movement = $this->service->recordRelease($this->pw, 4, 'res_hold_4', 'Hold timeout');

        $this->pw->refresh();
        $this->assertEquals(10, $this->pw->available_quantity);
        $this->assertEquals(0, $this->pw->reserved_quantity);
        $this->assertEquals(+4, $movement->available_delta);
        $this->assertEquals(-4, $movement->reserved_delta);
    }

    public function test_can_record_confirmed_sale(): void
    {
        $this->service->recordReceipt($this->pw, 10);
        $this->service->recordReservation($this->pw, 2, 'res_hold_2');

        // Customer pays
        $movement = $this->service->recordSale($this->pw, 2, 'ord_paid_2');

        $this->pw->refresh();
        $this->assertEquals(8, $this->pw->available_quantity);
        $this->assertEquals(0, $this->pw->reserved_quantity);
        $this->assertEquals(2, $this->pw->sold_quantity);

        $this->assertEquals(0, $movement->available_delta);
        $this->assertEquals(-2, $movement->reserved_delta);
        $this->assertEquals(+2, $movement->sold_delta);
    }

    public function test_double_entry_mathematical_balance_invariant(): void
    {
        // 1. Receipt: +100
        $this->service->recordReceipt($this->pw, 100);
        // 2. Reservation A: 20
        $this->service->recordReservation($this->pw, 20, 'res_a');
        // 3. Reservation B: 10
        $this->service->recordReservation($this->pw, 10, 'res_b');
        // 4. Confirm Sale A: 20
        $this->service->recordSale($this->pw, 20, 'ord_a');
        // 5. Release B (timeout): 10
        $this->service->recordRelease($this->pw, 10, 'res_b', 'Expired');

        $this->pw->refresh();

        // Mathematical sum of all ledger deltas
        $sumAvailableDeltas = StockMovement::where('product_warehouse_id', $this->pw->id)->sum('available_delta');
        $sumReservedDeltas = StockMovement::where('product_warehouse_id', $this->pw->id)->sum('reserved_delta');
        $sumSoldDeltas = StockMovement::where('product_warehouse_id', $this->pw->id)->sum('sold_delta');

        // Invariants must hold true with zero discrepancies
        $this->assertEquals($this->pw->available_quantity, $sumAvailableDeltas);
        $this->assertEquals($this->pw->reserved_quantity, $sumReservedDeltas);
        $this->assertEquals($this->pw->sold_quantity, $sumSoldDeltas);

        $this->assertEquals(80, $this->pw->available_quantity);
        $this->assertEquals(0, $this->pw->reserved_quantity);
        $this->assertEquals(20, $this->pw->sold_quantity);
    }

    public function test_can_operate_using_uuid_string_instead_of_model(): void
    {
        $movement = $this->service->recordReceipt($this->pw->id, 30, 'Direct ID receipt');

        $this->pw->refresh();
        $this->assertEquals(30, $this->pw->available_quantity);
        $this->assertEquals(StockMovement::TYPE_RECEIPT, $movement->movement_type);
    }

    public function test_can_record_manual_inventory_adjustments(): void
    {
        $this->service->recordReceipt($this->pw, 50);

        // Positive adjustment (e.g. audit found extra units)
        $adjPlus = $this->service->recordAdjustment($this->pw, 10, 'Audit found extra');
        $this->assertEquals(60, $this->pw->available_quantity);
        $this->assertEquals(10, $adjPlus->quantity_delta);

        // Negative adjustment (e.g. water damage write-off)
        $adjMinus = $this->service->recordAdjustment($this->pw, -15, 'Damaged items write-off');
        $this->assertEquals(45, $this->pw->available_quantity);
        $this->assertEquals(-15, $adjMinus->quantity_delta);
    }

    public function test_adjustment_cannot_reduce_inventory_below_zero(): void
    {
        $this->service->recordReceipt($this->pw, 10);

        $this->expectException(InsufficientStockException::class);
        $this->service->recordAdjustment($this->pw, -15, 'Attempted excess deduction');
    }
}
