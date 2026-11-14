<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    use ApiResponse;

    /**
     * List all products with optional search/filters.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Product::query();

        if ($request->filled('sku')) {
            $query->where('sku', $request->query('sku'));
        }

        if ($request->has('is_active')) {
            $query->where('is_active', filter_var($request->query('is_active'), FILTER_VALIDATE_BOOLEAN));
        }

        $products = $query->orderBy('name')->get();

        return $this->successResponse(
            ProductResource::collection($products),
            'Products retrieved successfully.'
        );
    }

    /**
     * Show a specific product by ID or SKU.
     */
    public function show(string $id): JsonResponse
    {
        $product = Product::where('id', $id)
            ->orWhere('sku', $id)
            ->firstOrFail();

        return $this->successResponse(
            new ProductResource($product),
            'Product details retrieved successfully.'
        );
    }
}
