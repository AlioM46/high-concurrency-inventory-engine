<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductWarehouse;
use App\Models\Reservation;
use App\Models\StockMovement;
use App\Models\Warehouse;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ConcurrencyReservationTest extends TestCase
{
    private ?Organization $org = null;
    private ?Warehouse $warehouse = null;
    private ?Product $productA = null;
    private ?Product $productB = null;
    private ?ProductWarehouse $pwA = null;
    private ?ProductWarehouse $pwB = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::create([
            'name' => 'Flash Sale Global ' . uniqid(),
            'slug' => 'flash-sale-global-' . uniqid(),
        ]);

        $this->warehouse = Warehouse::create([
            'organization_id' => $this->org->id,
            'name' => 'Fulfillment Center Alpha',
            'code' => 'ALPHA-01',
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
            'sku' => 'CPU-9950X3D',
            'name' => 'Ryzen 9 9950X3D Processor',
            'price_minor_units' => 69900,
            'currency' => 'USD',
        ]);

        $this->pwA = ProductWarehouse::create([
            'product_id' => $this->productA->id,
            'warehouse_id' => $this->warehouse->id,
            'organization_id' => $this->org->id,
            'available_quantity' => 10,
            'reserved_quantity' => 0,
            'sold_quantity' => 0,
            'version' => 1,
        ]);

        $this->pwB = ProductWarehouse::create([
            'product_id' => $this->productB->id,
            'warehouse_id' => $this->warehouse->id,
            'organization_id' => $this->org->id,
            'available_quantity' => 10,
            'reserved_quantity' => 0,
            'sold_quantity' => 0,
            'version' => 1,
        ]);
    }

    /**
     * Test Strategy B: Atomic Update under high multi-process concurrency.
     * 25 concurrent requests fight for 10 available units.
     * Exactly 10 must succeed, 15 must fail. Zero overselling.
     */
    public function test_atomic_update_prevents_overselling_under_concurrent_processes(): void
    {
        $totalRequests = 25;
        $availableUnits = 10;

        $itemsPayload = json_encode([
            [
                'product_warehouse_id' => $this->pwA->id,
                'quantity' => 1,
                'unit_price_minor_units' => 199900,
            ],
        ]);

        /** @var Process[] $processes */
        $processes = [];

        // 1. Spawn 25 concurrent operating system processes
        for ($i = 0; $i < $totalRequests; $i++) {
            $process = new Process([
                PHP_BINARY,
                base_path('artisan'),
                'stockforge:reserve-worker',
                '--strategy=atomic',
                "--customer=cust_flash_user_{$i}",
                "--payload={$itemsPayload}",
            ], env: [
                'APP_ENV' => 'testing',
                'DB_DATABASE' => config('database.connections.pgsql.database'),
                'DB_HOST' => config('database.connections.pgsql.host'),
                'DB_PORT' => config('database.connections.pgsql.port'),
                'DB_USERNAME' => config('database.connections.pgsql.username'),
                'DB_PASSWORD' => config('database.connections.pgsql.password'),
            ]);

            $process->start();
            $processes[] = $process;
        }

        // 2. Wait for all processes to complete
        $successCount = 0;
        $insufficientCount = 0;
        $unexpectedErrors = [];

        foreach ($processes as $process) {
            $process->wait();
            $exitCode = $process->getExitCode();

            if ($exitCode === 0) {
                $successCount++;
            } elseif ($exitCode === 2) {
                $insufficientCount++;
            } else {
                $unexpectedErrors[] = $process->getOutput();
            }
        }

        // 3. Mathematical Invariants:
        $this->assertEmpty($unexpectedErrors, 'No process should crash or encounter errors: ' . json_encode($unexpectedErrors));
        $this->assertEquals($availableUnits, $successCount, "Exactly {$availableUnits} requests must succeed.");
        $this->assertEquals($totalRequests - $availableUnits, $insufficientCount, 'Excess requests must receive INSUFFICIENT_STOCK.');

        // 4. Database Inventory Invariant Check:
        $this->pwA->refresh();
        $this->assertEquals(0, $this->pwA->available_quantity, 'Available stock must be exactly 0 (no oversell).');
        $this->assertEquals(10, $this->pwA->reserved_quantity, 'Reserved stock must be exactly 10.');

        // 5. Audit Ledger Invariant:
        $movementsCount = StockMovement::where('product_warehouse_id', $this->pwA->id)
            ->where('movement_type', StockMovement::TYPE_RESERVATION)
            ->count();
        $this->assertEquals(10, $movementsCount, 'Exactly 10 immutable reservation movements must be logged in ledger.');
    }

    /**
     * Test Strategy A: Pessimistic Locking under high multi-process concurrency.
     * 25 concurrent requests fight for 10 available units.
     * Exactly 10 must succeed, 15 must fail. Zero overselling.
     */
    public function test_pessimistic_locking_prevents_overselling_under_concurrent_processes(): void
    {
        $totalRequests = 25;
        $availableUnits = 10;

        $itemsPayload = json_encode([
            [
                'product_warehouse_id' => $this->pwB->id,
                'quantity' => 1,
                'unit_price_minor_units' => 69900,
            ],
        ]);

        /** @var Process[] $processes */
        $processes = [];

        // 1. Spawn 25 concurrent operating system processes
        for ($i = 0; $i < $totalRequests; $i++) {
            $process = new Process([
                PHP_BINARY,
                base_path('artisan'),
                'stockforge:reserve-worker',
                '--strategy=pessimistic',
                "--customer=cust_pessimistic_user_{$i}",
                "--payload={$itemsPayload}",
            ], env: [
                'APP_ENV' => 'testing',
                'DB_DATABASE' => config('database.connections.pgsql.database'),
                'DB_HOST' => config('database.connections.pgsql.host'),
                'DB_PORT' => config('database.connections.pgsql.port'),
                'DB_USERNAME' => config('database.connections.pgsql.username'),
                'DB_PASSWORD' => config('database.connections.pgsql.password'),
            ]);

            $process->start();
            $processes[] = $process;
        }

        // 2. Wait for all processes to complete
        $successCount = 0;
        $insufficientCount = 0;
        $unexpectedErrors = [];

        foreach ($processes as $process) {
            $process->wait();
            $exitCode = $process->getExitCode();

            if ($exitCode === 0) {
                $successCount++;
            } elseif ($exitCode === 2) {
                $insufficientCount++;
            } else {
                $unexpectedErrors[] = $process->getOutput();
            }
        }

        // 3. Mathematical Invariants:
        $this->assertEmpty($unexpectedErrors, 'No process should crash or encounter errors: ' . json_encode($unexpectedErrors));
        $this->assertEquals($availableUnits, $successCount, "Exactly {$availableUnits} requests must succeed.");
        $this->assertEquals($totalRequests - $availableUnits, $insufficientCount, 'Excess requests must receive INSUFFICIENT_STOCK.');

        // 4. Database Inventory Invariant Check:
        $this->pwB->refresh();
        $this->assertEquals(0, $this->pwB->available_quantity, 'Available stock must be exactly 0 (no oversell).');
        $this->assertEquals(10, $this->pwB->reserved_quantity, 'Reserved stock must be exactly 10.');
    }

    /**
     * Test Deadlock Prevention in Multi-Item Carts under Reverse Concurrency.
     * Group 1 orders [Product A, Product B].
     * Group 2 orders [Product B, Product A] (deliberately opposite order).
     * Deterministic ID sorting must ensure ZERO deadlocks occur.
     */
    public function test_deterministic_sorting_eliminates_deadlocks_in_multi_item_carts(): void
    {
        // Re-seed both products with 10 units each
        $this->pwA->update(['available_quantity' => 10, 'reserved_quantity' => 0]);
        $this->pwB->update(['available_quantity' => 10, 'reserved_quantity' => 0]);

        $payloadOrderAB = json_encode([
            ['product_warehouse_id' => $this->pwA->id, 'quantity' => 1, 'unit_price_minor_units' => 199900],
            ['product_warehouse_id' => $this->pwB->id, 'quantity' => 1, 'unit_price_minor_units' => 69900],
        ]);

        $payloadOrderBA = json_encode([
            ['product_warehouse_id' => $this->pwB->id, 'quantity' => 1, 'unit_price_minor_units' => 69900],
            ['product_warehouse_id' => $this->pwA->id, 'quantity' => 1, 'unit_price_minor_units' => 199900],
        ]);

        /** @var Process[] $processes */
        $processes = [];

        // Launch 10 workers ordering [A, B] and 10 workers ordering [B, A] simultaneously
        for ($i = 0; $i < 20; $i++) {
            $payload = ($i % 2 === 0) ? $payloadOrderAB : $payloadOrderBA;
            $strategy = ($i % 2 === 0) ? 'pessimistic' : 'atomic';

            $process = new Process([
                PHP_BINARY,
                base_path('artisan'),
                'stockforge:reserve-worker',
                "--strategy={$strategy}",
                "--customer=cust_deadlock_test_{$i}",
                "--payload={$payload}",
            ], env: [
                'APP_ENV' => 'testing',
                'DB_DATABASE' => config('database.connections.pgsql.database'),
                'DB_HOST' => config('database.connections.pgsql.host'),
                'DB_PORT' => config('database.connections.pgsql.port'),
                'DB_USERNAME' => config('database.connections.pgsql.username'),
                'DB_PASSWORD' => config('database.connections.pgsql.password'),
            ]);

            $process->start();
            $processes[] = $process;
        }

        // Wait for all to finish
        $deadlockErrors = [];
        $successCount = 0;

        foreach ($processes as $process) {
            $process->wait();
            $output = $process->getOutput();
            $decoded = json_decode($output, true);

            if ($process->getExitCode() === 0) {
                $successCount++;
            } elseif ($process->getExitCode() === 1) {
                // If exit code is 1, check if error was deadlock
                $deadlockErrors[] = $decoded['message'] ?? $output;
            }
        }

        // PROOF OF ZERO DEADLOCKS:
        $this->assertEmpty($deadlockErrors, 'Zero deadlocks must occur despite reversed cart orders: ' . json_encode($deadlockErrors));
        $this->assertEquals(10, $successCount, 'Exactly 10 dual-item reservations must succeed.');

        // Both products must be reduced to exactly 0
        $this->pwA->refresh();
        $this->pwB->refresh();
        $this->assertEquals(0, $this->pwA->available_quantity);
        $this->assertEquals(10, $this->pwA->reserved_quantity);
        $this->assertEquals(0, $this->pwB->available_quantity);
        $this->assertEquals(10, $this->pwB->reserved_quantity);
    }

    protected function tearDown(): void
    {
        if ($this->org) {
            Reservation::where('organization_id', $this->org->id)->delete();
            StockMovement::where('organization_id', $this->org->id)->delete();
            ProductWarehouse::where('organization_id', $this->org->id)->delete();
            Product::where('organization_id', $this->org->id)->delete();
            Warehouse::where('organization_id', $this->org->id)->delete();
            $this->org->delete();
        }

        parent::tearDown();
    }
}
