<?php

namespace App\Services;

use App\Models\ProductWarehouse;
use App\Models\Reservation;
use App\Models\ReservationItem;
use App\Services\Contracts\ReservationServiceInterface;
use Illuminate\Support\Facades\DB;

class PessimisticLockReservationService implements ReservationServiceInterface
{
    public function __construct(
        protected StockMovementService $stockMovementService
    ) {}

    /**
     * Create a stock reservation using Strategy A: Deterministic Sorting + Row-level Pessimistic Locking.
     */
    public function create(array $data): Reservation
    {
        // 1. Deterministic Resource Sorting: Sort items by product_warehouse_id ASC to eliminate deadlocks
        $sortedItems = collect($data['items'])
            ->sortBy('product_warehouse_id')
            ->values()
            ->all();

        // 2. Resolve organization_id (from input or from the first product warehouse)
        $organizationId = $data['organization_id'] ?? null;
        if (! $organizationId) {
            $firstPw = ProductWarehouse::select('organization_id')
                ->where('id', $sortedItems[0]['product_warehouse_id'])
                ->firstOrFail();
            $organizationId = $firstPw->organization_id;
        }

        $expiresInMinutes = $data['expires_in_minutes'] ?? 10;
        $expiresAt = now()->addMinutes($expiresInMinutes);

        // 3. Execute reservation atomically inside a database transaction
        return DB::transaction(function () use ($data, $sortedItems, $organizationId, $expiresAt) {
            // Create master reservation record
            $reservation = Reservation::create([
                'organization_id' => $organizationId,
                'customer_id' => $data['customer_id'],
                'status' => Reservation::STATUS_ACTIVE,
                'expires_at' => $expiresAt,
            ]);

            // Iterate items in deterministic sorted order
            foreach ($sortedItems as $item) {
                // Record reservation hold on inventory row (locks row & moves stock: available -> reserved)
                $this->stockMovementService->recordReservation(
                    productWarehouse: $item['product_warehouse_id'],
                    quantity: (int) $item['quantity'],
                    reservationId: $reservation->id,
                    actor: $data['customer_id']
                );

                // Create reservation item snapshot
                ReservationItem::create([
                    'reservation_id' => $reservation->id,
                    'product_warehouse_id' => $item['product_warehouse_id'],
                    'quantity' => $item['quantity'],
                    'unit_price_minor_units' => $item['unit_price_minor_units'],
                ]);
            }

            // Dispatch ExpireReservationJob if the class exists (Task 3.7)
            if (class_exists(\App\Jobs\ExpireReservationJob::class)) {
                \App\Jobs\ExpireReservationJob::dispatch($reservation->id)->delay($expiresAt);
            }

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

            // Guard against releasing already finalized/released reservations
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
