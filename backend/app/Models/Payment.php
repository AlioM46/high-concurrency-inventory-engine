<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    use HasFactory, HasUuids;

    public const STATUS_PENDING = 'PENDING';
    public const STATUS_SUCCEEDED = 'SUCCEEDED';
    public const STATUS_FAILED = 'FAILED';
    public const STATUS_REFUNDED = 'REFUNDED';

    protected $fillable = [
        'organization_id',
        'order_id',
        'provider',
        'provider_payment_intent_id',
        'amount_minor_units',
        'currency',
        'status',
        'failure_reason',
        'refunded_at',
    ];

    protected function casts(): array
    {
        return [
            'amount_minor_units' => 'integer',
            'refunded_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
