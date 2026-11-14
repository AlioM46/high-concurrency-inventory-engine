<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\WarehouseResource;
use App\Models\Warehouse;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WarehouseController extends Controller
{
    use ApiResponse;

    /**
     * List all fulfillment warehouses.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Warehouse::query();


        // filter_var("true") => true, filter_var(0) => false
        // converting string boolean to real Boolean Types
        if ($request->has('is_active')) {
            $query->where('is_active', filter_var($request->query('is_active'), FILTER_VALIDATE_BOOLEAN));
        }

        $warehouses = $query->orderBy('name')->get();

        return $this->successResponse(
            WarehouseResource::collection($warehouses),
            'Warehouses retrieved successfully.'
        );
    }

    /**
     * Show a specific warehouse by ID or Code.
     */
    public function show(string $id): JsonResponse
    {
        $warehouse = Warehouse::where('id', $id)
            ->orWhere('code', $id)
            ->firstOrFail();

        return $this->successResponse(
            new WarehouseResource($warehouse),
            'Warehouse details retrieved successfully.'
        );
    }
}
