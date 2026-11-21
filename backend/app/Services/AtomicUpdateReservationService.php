<?php

namespace App\Services;

use App\Exceptions\InsufficientStockException;
use App\Jobs\ExpireReservationJob;
use App\Models\ProductWarehouse;
use App\Models\Reservation;
use App\Models\ReservationItem;
use App\Models\StockMovement;
use App\Services\Contracts\ReservationServiceInterface;
use Illuminate\Support\Facades\DB;

class AtomicUpdateReservationService implements ReservationServiceInterface
{
    public function __construct(
        protected StockMovementService $stockMovementService
    ) {}

    /**
     * Create a stock reservation using Strategy B: Atomic Conditional Update (No row-level read locks).
     */
    public function create(array $data): Reservation
    {
        // 1. Sort items deterministically by product_warehouse_id ASC for consistent transaction sequencing
        $sortedItems = collect($data['items'])
            ->sortBy('product_warehouse_id')
            ->values()
            ->all();

        // 2. Resolve organization_id
        $organizationId = $data['organization_id'] ?? null;
        if (! $organizationId) {
            $firstPw = ProductWarehouse::select('organization_id')
                ->where('id', $sortedItems[0]['product_warehouse_id'])
                ->firstOrFail();
            $organizationId = $firstPw->organization_id;
        }

        $expiresInMinutes = $data['expires_in_minutes'] ?? 10;
        $expiresAt = now()->addMinutes($expiresInMinutes);

        // 3. Execute atomic conditional updates inside a database transaction
        return DB::transaction(function () use ($data, $sortedItems, $organizationId, $expiresAt) {
            // Create master reservation record
            $reservation = Reservation::create([
                'organization_id' => $organizationId,
                'customer_id' => $data['customer_id'],
                'status' => Reservation::STATUS_ACTIVE,
                'expires_at' => $expiresAt,
            ]);

            foreach ($sortedItems as $item) {
                $id = $item['product_warehouse_id'];
                $quantity = (int) $item['quantity'];

                // ⚡ Atomic conditional SQL update: single disk operation, zero application locks
                $affected = DB::table('product_warehouses')
                    ->where('id', $id)
                    ->where('available_quantity', '>=', $quantity)
                    ->update([
                        'available_quantity' => DB::raw("available_quantity - {$quantity}"),
                        'reserved_quantity' => DB::raw("reserved_quantity + {$quantity}"),
                        'version' => DB::raw('version + 1'),
                        'updated_at' => now(),
                    ]);

                // If affected is 0, the condition (available_quantity >= quantity) was false
                if ($affected === 0) {
                    $currentAvailable = (int) (DB::table('product_warehouses')
                        ->where('id', $id)
                        ->value('available_quantity') ?? 0);

                    throw new InsufficientStockException(
                        message: "Insufficient available stock for item {$id}. Requested {$quantity}, available: {$currentAvailable}.",
                        requested: $quantity,
                        available: $currentAvailable
                    );
                }

                // Append immutable audit log in stock_movements ledger
                StockMovement::create([
                    'organization_id' => $organizationId,
                    'product_warehouse_id' => $id,
                    'movement_type' => StockMovement::TYPE_RESERVATION,
                    'quantity_delta' => 0,
                    'available_delta' => -$quantity,
                    'reserved_delta' => +$quantity,
                    'sold_delta' => 0,
                    'reference_type' => 'Reservation',
                    'reference_id' => $reservation->id,
                    'reason' => 'Customer reservation hold (Atomic conditional update, 10 min TTL)',
                    'created_by' => $data['customer_id'],
                    'created_at' => now(),
                ]);

                // Create reservation item snapshot
                ReservationItem::create([
                    'reservation_id' => $reservation->id,
                    'product_warehouse_id' => $id,
                    'quantity' => $quantity,
                    'unit_price_minor_units' => $item['unit_price_minor_units'],
                ]);
            }

            // Dispatch ExpireReservationJob with delayed execution matching expires_at TTL
            ExpireReservationJob::dispatch($reservation->id)->delay($expiresAt);

            return $reservation->load(['items.productWarehouse.product']);
        });
    }

    /**
     * Release an active reservation and return reserved stock back to available inventory.
     */
    public function release(string $reservationId, string $reason = 'Reservation expired'): void
    {
        DB::transaction(function () use ($reservationId, $reason) {
            $reservation = Reservation::with('items')
                ->where('id', $reservationId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($reservation->status !== Reservation::STATUS_ACTIVE) {
                return;
            }

            foreach ($reservation->items as $item) {
                $this->stockMovementService->recordRelease(
                    productWarehouse: $item->product_warehouse_id,
                    quantity: $item->quantity,
                    reservationId: $reservation->id,
                    reason: $reason,
                    actor: 'system_expiry'
                );
            }

            $reservation->status = Reservation::STATUS_RELEASED;
            $reservation->released_at = now();
            $reservation->save();
        });
    }
}
