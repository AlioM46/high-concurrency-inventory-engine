<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateOrderRequest;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Services\OrderService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    use ApiResponse;

    /**
     * Create an order from an active reservation.
     */
    public function store(CreateOrderRequest $request, OrderService $orderService): JsonResponse
    {
        $order = $orderService->createFromReservation($request->validated());

        return $this->successResponse(
            new OrderResource($order),
            'Order created successfully from active reservation.',
            201
        );
    }

    /**
     * Retrieve order details by ID.
     */
    public function show(string $id): JsonResponse
    {
        $order = Order::with(['items.product', 'reservation'])->findOrFail($id);

        return $this->successResponse(
            new OrderResource($order),
            'Order retrieved successfully.'
        );
    }

    /**
     * Cancel an order and release linked reservation inventory.
     */
    public function cancel(Request $request, string $id, OrderService $orderService): JsonResponse
    {
        $reason = $request->input('reason', 'Customer requested cancellation');
        $order = $orderService->cancel($id, $reason);

        return $this->successResponse(
            new OrderResource($order),
            'Order cancelled and inventory hold released successfully.'
        );
    }
}
