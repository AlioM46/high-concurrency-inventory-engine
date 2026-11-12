<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PaymentWebhookEvent extends Model
{
    use HasFactory, HasUuids;

    public const STATUS_PENDING = 'PENDING';
    public const STATUS_PROCESSED = 'PROCESSED';
    public const STATUS_FAILED = 'FAILED';
    public const STATUS_IGNORED = 'IGNORED';

    protected $fillable = [
        'provider',
        'event_id',
        'event_type',
        'payload',
        'processing_status',
        'error_message',
        'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'processed_at' => 'datetime',
        ];
    }
}
