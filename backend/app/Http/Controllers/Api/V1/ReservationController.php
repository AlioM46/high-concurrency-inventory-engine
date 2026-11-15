<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateReservationRequest;
use App\Http\Resources\ReservationResource;
use App\Models\Reservation;
use App\Services\PessimisticLockReservationService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;

class ReservationController extends Controller
{
    use ApiResponse;

    /**
     * Create a reservation using Strategy A (Pessimistic Locking + Deterministic ID Sorting).
     */
    public function storePessimistic(
        CreateReservationRequest $request,
        PessimisticLockReservationService $service
    ): JsonResponse {
        $reservation = $service->create($request->validated());

        return $this->successResponse(
            new ReservationResource($reservation),
            'Reservation created successfully using pessimistic locking strategy.',
            201
        );
    }

    /**
     * Retrieve details of a reservation.
     */
    public function show(string $id): JsonResponse
    {
        $reservation = Reservation::with(['items.productWarehouse.product'])->findOrFail($id);

        return $this->successResponse(
            new ReservationResource($reservation),
            'Reservation retrieved successfully.'
        );
    }
}
