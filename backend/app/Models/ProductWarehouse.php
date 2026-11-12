<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductWarehouse extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'product_warehouses';

    protected $fillable = [
        'organization_id',
        'product_id',
        'warehouse_id',
        'available_quantity',
        'reserved_quantity',
        'sold_quantity',
        'version',
    ];

    protected function casts(): array
    {
        return [
            'available_quantity' => 'integer',
            'reserved_quantity' => 'integer',
            'sold_quantity' => 'integer',
            'version' => 'integer',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    public function reservationItems(): HasMany
    {
        return $this->hasMany(ReservationItem::class);
    }

    protected function totalQuantity(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->available_quantity + $this->reserved_quantity + $this->sold_quantity,
        );
    }
}
