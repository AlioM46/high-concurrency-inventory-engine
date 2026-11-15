<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateStockAdjustmentRequest;
use App\Http\Resources\InventoryResource;
use App\Http\Resources\StockMovementResource;
use App\Models\Product;
use App\Models\ProductWarehouse;
use App\Services\StockMovementService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InventoryController extends Controller
{
    use ApiResponse;

    /**
     * List current stock levels across all products and warehouses.
     */
    public function index(Request $request): JsonResponse
    {
        $query = ProductWarehouse::with(['product', 'warehouse']);

        if ($request->filled('product_id')) {
            $query->where('product_id', $request->query('product_id'));
        }

        if ($request->filled('warehouse_id')) {
            $query->where('warehouse_id', $request->query('warehouse_id'));
        }

        $inventories = $query->get();

        return $this->successResponse(
            InventoryResource::collection($inventories),
            'Inventory levels retrieved successfully.'
        );
    }

    /**
     * Get stock overview for a specific product across all warehouses.
     */
    public function showByProduct(string $productId): JsonResponse
    {
        $product = Product::where('id', $productId)
            ->orWhere('sku', $productId)
            ->firstOrFail();

        $warehousesStock = ProductWarehouse::with('warehouse')
            ->where('product_id', $product->id)
            ->get();

        $totalAvailable = $warehousesStock->sum('available_quantity');
        $totalReserved = $warehousesStock->sum('reserved_quantity');
        $totalSold = $warehousesStock->sum('sold_quantity');

        return $this->successResponse([
            'product' => [
                'id' => $product->id,
                'sku' => $product->sku,
                'name' => $product->name,
            ],
            'totals' => [
                'available' => $totalAvailable,
                'reserved' => $totalReserved,
                'sold' => $totalSold,
                'total_physical' => $totalAvailable + $totalReserved + $totalSold,
            ],
            'warehouses' => $warehousesStock->map(fn ($item) => [
                'product_warehouse_id' => $item->id,
                'warehouse_id' => $item->warehouse->id,
                'warehouse_code' => $item->warehouse->code,
                'warehouse_name' => $item->warehouse->name,
                'available_quantity' => $item->available_quantity,
                'reserved_quantity' => $item->reserved_quantity,
                'sold_quantity' => $item->sold_quantity,
            ]),
        ], 'Product stock aggregated successfully.');
    }

    /**
     * Record a manual stock adjustment or inward receipt into the ledger.
     */
    public function adjust(
        CreateStockAdjustmentRequest $request,
        StockMovementService $stockService
    ): JsonResponse {
        $movement = $stockService->recordAdjustment(
            productWarehouse: $request->validated('product_warehouse_id'),
            quantityDelta: (int) $request->validated('quantity_delta'),
            reason: $request->validated('reason'),
            actor: $request->validated('actor', 'warehouse_operator'),
        );

        $movement->load('productWarehouse');

        return $this->successResponse(
            new StockMovementResource($movement),
            'Inventory adjusted and recorded in ledger successfully.',
            201
        );
    }
}
