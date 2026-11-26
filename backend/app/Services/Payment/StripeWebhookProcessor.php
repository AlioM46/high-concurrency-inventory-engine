<?php

namespace App\Services\Payment;

use App\Models\PaymentWebhookEvent;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class StripeWebhookProcessor
{
    /**
     * Ingest and process a verified Stripe webhook event with idempotent deduplication.
     *
     * @param array<string, mixed> $event The decoded Stripe event payload
     * @return array{
     *     status: string,
     *     event_id: string,
     *     event_type: string,
     *     is_duplicate: bool,
     *     webhook_event_id: string
     * }
     */
    public function process(array $event): array
    {
        $eventId = (string) ($event['id'] ?? '');
        $eventType = (string) ($event['type'] ?? 'unknown');

        if (empty($eventId)) {
            throw new \InvalidArgumentException('Webhook event missing required "id" field.');
        }

        // =========================================================================================
        // [الخطوة 1: الفحص السريع لمنع التكرار - Fast-Path Deduplication]
        // =========================================================================================
        // إذا كان هذا الإشعار قد عولج واكتمل مسبقاً، نتوقف فوراً ونرد بـ 200 OK دون فتح أي معاملة
        // أو إرهاق السيرفر بعمليات كتابة فاشلة، مما يوفر موارد الـ CPU والـ I/O بشكل هائل.
        // =========================================================================================
        $existingEvent = PaymentWebhookEvent::where('event_id', $eventId)
            ->where('provider', 'STRIPE')
            ->first();

        if ($existingEvent) {
            Log::info("Stripe Webhook [{$eventId}] already exists with status '{$existingEvent->processing_status}'. Skipping duplicate execution.");

            return [
                'status' => $existingEvent->processing_status === PaymentWebhookEvent::STATUS_PROCESSED
                    ? 'duplicate_ignored'
                    : 'already_received',
                'event_id' => $eventId,
                'event_type' => $eventType,
                'is_duplicate' => true,
                'webhook_event_id' => $existingEvent->id,
            ];
        }

        // =========================================================================================
        // [الخطوة 2: التسجيل داخل المعاملة مع حماية الـ Race Condition]
        // =========================================================================================
        // إذا أرسلت Stripe نسختين متطابقتين في نفس الميكروثانية واجتازتا الفحص المبدئي معاً:
        // فإن قيد الداتابيز UNIQUE(provider, event_id) سيرفض النسخة الثانية ويرمي QueryException.
        // نقوم باصطياد الاستثناء في catch ونتعامل مع الحدث كتكرار متكافئ بأمان تام (Idempotent).
        // =========================================================================================
        return DB::transaction(function () use ($event, $eventId, $eventType) {
            try {
                $webhookRecord = PaymentWebhookEvent::create([
                    'provider' => 'STRIPE',
                    'event_id' => $eventId,
                    'event_type' => $eventType,
                    'payload' => $event,
                    'processing_status' => PaymentWebhookEvent::STATUS_PENDING,
                ]);
            } catch (QueryException $e) {
                Log::warning("Stripe Webhook [{$eventId}] hit unique constraint race. Resolving idempotently.");
                $concurrentEvent = PaymentWebhookEvent::where('event_id', $eventId)
                    ->where('provider', 'STRIPE')
                    ->firstOrFail();

                return [
                    'status' => 'duplicate_ignored',
                    'event_id' => $eventId,
                    'event_type' => $eventType,
                    'is_duplicate' => true,
                    'webhook_event_id' => $concurrentEvent->id,
                ];
            }

            // Mark event as processed
            $webhookRecord->update([
                'processing_status' => PaymentWebhookEvent::STATUS_PROCESSED,
                'processed_at' => now(),
            ]);

            return [
                'status' => 'processed',
                'event_id' => $eventId,
                'event_type' => $eventType,
                'is_duplicate' => false,
                'webhook_event_id' => $webhookRecord->id,
            ];
        });
    }
}
