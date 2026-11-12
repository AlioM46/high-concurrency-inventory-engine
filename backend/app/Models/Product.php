<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'organization_id',
        'sku',
        'name',
        'description',
        'price_minor_units',
        'currency',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'price_minor_units' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function productWarehouses(): HasMany
    {
        return $this->hasMany(ProductWarehouse::class);
    }
}
