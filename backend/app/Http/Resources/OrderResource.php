<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderResource extends JsonResource
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
                'product_id' => $item->product_id,
                'product' => [
                    'id' => $item->product?->id,
                    'sku' => $item->product?->sku,
                    'name' => $item->product?->name,
                ],
                'quantity' => $item->quantity,
                'unit_price_minor_units' => $item->unit_price_minor_units,
                'subtotal_minor_units' => $item->subtotal_minor_units,
            ];
        });

        return [
            'id' => $this->id,
            'order_number' => $this->order_number,
            'status' => $this->status,
            'customer_id' => $this->customer_id,
            'customer_email' => $this->customer_email,
            'reservation_id' => $this->reservation_id,
            'currency' => $this->currency,
            'total_amount_minor_units' => $this->total_amount_minor_units,
            'total_amount_formatted' => number_format($this->total_amount_minor_units / 100, 2),
            'items_count' => $items->count(),
            'items' => $items,
            'paid_at' => $this->paid_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
