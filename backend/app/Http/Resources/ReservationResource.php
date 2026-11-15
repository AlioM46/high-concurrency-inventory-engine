<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReservationResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $items = $this->items->map(function ($item) {
            return [
                'id' => $item->id,
                'product_warehouse_id' => $item->product_warehouse_id,
                'product' => [
                    'id' => $item->productWarehouse?->product?->id,
                    'sku' => $item->productWarehouse?->product?->sku,
                    'name' => $item->productWarehouse?->product?->name,
                ],
                'quantity' => $item->quantity,
                'unit_price_minor_units' => $item->unit_price_minor_units,
                'subtotal_minor_units' => $item->quantity * $item->unit_price_minor_units,
            ];
        });

        $totalMinorUnits = $items->sum('subtotal_minor_units');

        return [
            'id' => $this->id,
            'customer_id' => $this->customer_id,
            'status' => $this->status,
            'expires_at' => $this->expires_at?->toIso8601String(),
            'total_minor_units' => $totalMinorUnits,
            'items_count' => $items->count(),
            'items' => $items,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
