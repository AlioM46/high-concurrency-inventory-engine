<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReservationItem extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'reservation_id',
        'product_warehouse_id',
        'quantity',
        'unit_price_minor_units',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_price_minor_units' => 'integer',
        ];
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    public function productWarehouse(): BelongsTo
    {
        return $this->belongsTo(ProductWarehouse::class);
    }
}
