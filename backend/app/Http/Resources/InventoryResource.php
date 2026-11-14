<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InventoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'product' => new ProductResource($this->whenLoaded('product')),
            'warehouse' => new WarehouseResource($this->whenLoaded('warehouse')),
            'quantities' => [
                'available' => $this->available_quantity,
                'reserved' => $this->reserved_quantity,
                'sold' => $this->sold_quantity,
            ],
            'version' => $this->version,
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
