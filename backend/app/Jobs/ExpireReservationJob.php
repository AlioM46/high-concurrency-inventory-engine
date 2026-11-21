<?php

namespace App\Jobs;

use App\Models\Reservation;
use App\Services\StockMovementService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ExpireReservationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public string $reservationId
    ) {}

    /**
     * Execute the job to expire the reservation and release held inventory.
     */
    public function handle(StockMovementService $stockMovementService): void
    {
        DB::transaction(function () use ($stockMovementService) {
            // 1. Lock the reservation row with its items to prevent race conditions
            $reservation = Reservation::with('items')
                ->where('id', $this->reservationId)
                ->lockForUpdate()
                ->first();

            if (! $reservation) {
                Log::warning("ExpireReservationJob: Reservation [{$this->reservationId}] not found.");
                return;
            }

            // 2. Idempotency guard: only active reservations can be expired
            // If already CONFIRMED (paid), RELEASED (cancelled), or already EXPIRED, do nothing.
            if ($reservation->status !== Reservation::STATUS_ACTIVE) {
                Log::info("ExpireReservationJob: Reservation [{$this->reservationId}] has status '{$reservation->status}', skipping expiration.");
                return;
            }

            // 3. TTL guard: do not expire prematurely if expires_at is still in the future
            if ($reservation->expires_at && $reservation->expires_at->greaterThan(now()->addSeconds(5))) {
                Log::info("ExpireReservationJob: Reservation [{$this->reservationId}] expires at {$reservation->expires_at->toIso8601String()} which is still active. Skipping.");
                return;
            }

            // 4. Return reserved inventory back to available stock in double-entry ledger
            foreach ($reservation->items as $item) {
                $stockMovementService->recordRelease(
                    productWarehouse: $item->product_warehouse_id,
                    quantity: $item->quantity,
                    reservationId: $reservation->id,
                    reason: 'Reservation TTL expired (automatic queue worker release)',
                    actor: 'system_expiry_worker'
                );
            }

            // 4. Transition reservation state to EXPIRED
            $reservation->status = Reservation::STATUS_EXPIRED;
            $reservation->released_at = now();
            $reservation->save();

            Log::info("ExpireReservationJob: Reservation [{$this->reservationId}] successfully transitioned to EXPIRED and inventory restored.");
        });
    }
}
