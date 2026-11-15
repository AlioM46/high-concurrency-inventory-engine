<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StockMovementResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'product_warehouse_id' => $this->product_warehouse_id,
            'movement_type' => $this->movement_type,
            'deltas' => [
                'quantity_delta' => $this->quantity_delta,
                'available_delta' => $this->available_delta,
                'reserved_delta' => $this->reserved_delta,
                'sold_delta' => $this->sold_delta,
            ],
            'audit' => [
                'reference_type' => $this->reference_type,
                'reference_id' => $this->reference_id,
                'reason' => $this->reason,
                'actor' => $this->created_by,
                'created_at' => $this->created_at?->toIso8601String(),
            ],
            'current_inventory' => $this->whenLoaded('productWarehouse', fn () => [
                'available_quantity' => $this->productWarehouse->available_quantity,
                'reserved_quantity' => $this->productWarehouse->reserved_quantity,
                'sold_quantity' => $this->productWarehouse->sold_quantity,
                'version' => $this->productWarehouse->version,
            ]),
        ];
    }
}
