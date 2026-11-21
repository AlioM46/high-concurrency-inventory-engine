<?php

namespace App\Services;

use App\Exceptions\InvalidReservationException;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Reservation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OrderService
{
    public function __construct(
        protected StockMovementService $stockMovementService
    ) {}

    /**
     * Create an Order from an active stock reservation.
     *
     * @param array{
     *     reservation_id: string,
     *     customer_email: string,
     *     currency?: string,
     * } $data
     * @throws InvalidReservationException
     */
    public function createFromReservation(array $data): Order
    {
        return DB::transaction(function () use ($data) {
            // 1. Lock the reservation row with items and product warehouse to prevent concurrent binds
            $reservation = Reservation::with(['items.productWarehouse'])
                ->where('id', $data['reservation_id'])
                ->lockForUpdate()
                ->first();

            if (! $reservation) {
                throw new InvalidReservationException(
                    message: "Reservation [{$data['reservation_id']}] not found.",
                    errorCode: 'RESERVATION_NOT_FOUND',
                    statusCode: 404
                );
            }

            // 2. Validate reservation state
            if ($reservation->status !== Reservation::STATUS_ACTIVE) {
                throw new InvalidReservationException(
                    message: "Cannot create order: Reservation [{$reservation->id}] is in '{$reservation->status}' status.",
                    errorCode: 'RESERVATION_NOT_ACTIVE',
                    statusCode: 422
                );
            }

            // 3. Validate TTL expiry
            if ($reservation->expires_at && $reservation->expires_at->isPast()) {
                throw new InvalidReservationException(
                    message: "Cannot create order: Reservation [{$reservation->id}] expired at {$reservation->expires_at->toIso8601String()}.",
                    errorCode: 'RESERVATION_EXPIRED',
                    statusCode: 422
                );
            }

            // 4. Ensure 1-to-1 binding: a reservation can only be converted into an order once
            $existingOrder = Order::where('reservation_id', $reservation->id)->first();
            if ($existingOrder) {
                throw new InvalidReservationException(
                    message: "An order [{$existingOrder->order_number}] already exists for this reservation.",
                    errorCode: 'RESERVATION_ALREADY_ORDERED',
                    statusCode: 409
                );
            }

            // 5. Calculate total amount across all items
            $totalAmountMinorUnits = 0;
            foreach ($reservation->items as $item) {
                $totalAmountMinorUnits += ($item->quantity * $item->unit_price_minor_units);
            }

            // 6. Generate unique order number
            $orderNumber = $this->generateOrderNumber();

            // 7. Create master Order record
            $order = Order::create([
                'organization_id' => $reservation->organization_id,
                'reservation_id' => $reservation->id,
                'customer_id' => $reservation->customer_id,
                'customer_email' => $data['customer_email'],
                'order_number' => $orderNumber,
                'status' => Order::STATUS_PENDING_PAYMENT,
                'total_amount_minor_units' => $totalAmountMinorUnits,
                'currency' => strtoupper($data['currency'] ?? 'USD'),
            ]);

            // 8. Create OrderItem line snapshots
            foreach ($reservation->items as $item) {
                OrderItem::create([
                    'order_id' => $order->id,
                    'product_id' => $item->productWarehouse->product_id,
                    'quantity' => $item->quantity,
                    'unit_price_minor_units' => $item->unit_price_minor_units,
                    'subtotal_minor_units' => $item->quantity * $item->unit_price_minor_units,
                ]);
            }

            return $order->load(['items.product', 'reservation']);
        });
    }

    /**
     * Cancel an existing order and release its held inventory.
     */
    public function cancel(string $orderId, string $reason = 'Customer cancelled order'): Order
    {
        return DB::transaction(function () use ($orderId, $reason) {
            $order = Order::with(['reservation.items'])
                ->where('id', $orderId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($order->status !== Order::STATUS_PENDING_PAYMENT) {
                throw new InvalidReservationException(
                    message: "Order cannot be cancelled in status '{$order->status}'.",
                    errorCode: 'INVALID_ORDER_STATE',
                    statusCode: 422
                );
            }

            $order->status = Order::STATUS_CANCELLED;
            $order->cancelled_at = now();
            $order->save();

            // If linked to an active reservation, release the reservation and return stock
            if ($order->reservation && $order->reservation->status === Reservation::STATUS_ACTIVE) {
                foreach ($order->reservation->items as $item) {
                    $this->stockMovementService->recordRelease(
                        productWarehouse: $item->product_warehouse_id,
                        quantity: $item->quantity,
                        reservationId: $order->reservation->id,
                        reason: $reason,
                        actor: 'order_cancellation'
                    );
                }

                $order->reservation->status = Reservation::STATUS_RELEASED;
                $order->reservation->released_at = now();
                $order->reservation->save();
            }

            return $order->load(['items.product', 'reservation']);
        });
    }

    /**
     * Generate a collision-resistant human-readable order number.
     * Format: ORD-YYYYMMDD-XXXXXX
     */
    protected function generateOrderNumber(): string
    {
        do {
            $number = 'ORD-' . date('Ymd') . '-' . strtoupper(Str::random(6));
        } while (Order::where('order_number', $number)->exists());

        return $number;
    }
}
