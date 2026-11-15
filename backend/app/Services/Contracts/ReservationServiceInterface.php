<?php

namespace App\Services\Contracts;

use App\Models\Reservation;

interface ReservationServiceInterface
{
    /**
     * Create a stock reservation for one or multiple items.
     *
     * @param  array{
     *     organization_id?: string,
     *     customer_id: string,
     *     items: array<int, array{product_warehouse_id: string, quantity: int, unit_price_minor_units: int}>,
     *     expires_in_minutes?: int
     * }  $data
     */
    public function create(array $data): Reservation;

    /**
     * Release an active reservation and return stock to available inventory.
     */
    public function release(string $reservationId, string $reason = 'Reservation expired'): void;
}
