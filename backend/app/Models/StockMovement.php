<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockMovement extends Model
{
    use HasFactory, HasUuids;

    public $timestamps = false; // Stock movements are append-only; created_at is managed by database or model

    public const TYPE_RECEIPT = 'RECEIPT';
    public const TYPE_RESERVATION = 'RESERVATION';
    public const TYPE_RELEASE = 'RELEASE';
    public const TYPE_SALE = 'SALE';
    public const TYPE_ADJUSTMENT = 'ADJUSTMENT';

    protected $fillable = [
        'organization_id',
        'product_warehouse_id',
        'movement_type',
        'quantity_delta',
        'available_delta',
        'reserved_delta',
        'sold_delta',
        'reference_type',
        'reference_id',
        'reason',
        'created_by',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'quantity_delta' => 'integer',
            'available_delta' => 'integer',
            'reserved_delta' => 'integer',
            'sold_delta' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function productWarehouse(): BelongsTo
    {
        return $this->belongsTo(ProductWarehouse::class);
    }
}
