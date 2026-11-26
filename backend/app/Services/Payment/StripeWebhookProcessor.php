<?php

namespace App\Services\Payment;

use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentWebhookEvent;
use App\Models\Reservation;
use App\Services\StockMovementService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class StripeWebhookProcessor
{
    public function __construct(
        protected StockMovementService $stockMovementService
    ) {}

    /**
     * Ingest and process a verified Stripe webhook event with idempotent deduplication and Atomic CAS guards.
     *
     * @param array<string, mixed> $event The decoded Stripe event payload
     * @return array{
     *     status: string,
     *     event_id: string,
     *     event_type: string,
     *     is_duplicate: bool,
     *     webhook_event_id: string,
     *     order_id?: string,
     *     order_number?: string,
     *     requires_refund?: bool
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

            // =========================================================================================
            // [الخطوة 3: توجيه الحدث لمعالجه المتخصص وفق نوعه]
            // =========================================================================================
            return match ($eventType) {
                'payment_intent.succeeded' => $this->handlePaymentIntentSucceeded($event, $webhookRecord),
                'payment_intent.payment_failed' => $this->handlePaymentIntentFailed($event, $webhookRecord),
                'charge.refunded' => $this->handleChargeRefunded($event, $webhookRecord),
                default => $this->handleUnsupportedEvent($event, $webhookRecord),
            };
        });
    }

    /**
     * Handle payment_intent.succeeded with Atomic Compare-and-Swap (CAS) guard.
     *
     * =========================================================================================
     * [المهمة 4.5: صمام أمان سباق الدفع ضد انتهاء الصلاحية - The Fatal Expiry vs Payment Race]
     * =========================================================================================
     * كيف يحدث هذا السباق الخطير في الواقع؟
     * 1. العميل قام بحجز البضاعة في البداية بنجاح (المهلة 10 دقائق من 12:00:00 إلى 12:10:00).
     * 2. أثناء وجود العميل في صفحة البنك، تأخر في استلام أو إدخال كود الـ OTP عبر الجوال.
     * 3. عند الدقيقة 12:10:00 بالضبط:
     *    - استيقظ عامل الخلفية ExpireReservationJob وألغى الحجز وحوّله إلى EXPIRED.
     *    - تم إرجاع القطعة إلى المخزون المتاح (available_quantity)، وجاء عميل آخر واشتراها فوراً!
     * 4. عند الدقيقة 12:10:05:
     *    - العميل الأول أدخل كود الـ OTP والبنك وافق وخصم 800$ من حسابه!
     *    - وصلت رسالة Stripe Webhook الآن لسيرفرنا تؤكد استلام المال!
     *
     * ما الذي يفعله هذا التابع لمنع الكارثة؟ (Atomic CAS Guard):
     * - نقفل الحجز والطلب بقفل حصري lockForUpdate().
     * - نقوم بالفحص الذري:
     *   [المسار السليم]: إذا كان الحجز ما زال ACTIVE:
     *     - كسبنا السباق! القطعة ما زالت محجوزة له ولم تُمس.
     *     - نحول الطلب لـ PAID، والحجز لـ CONFIRMED، وننقل المخزون من reserved إلى sold في الدفتر.
     *   [مسار السباق المتأخر]: إذا كان الحجز قد أصبح EXPIRED أو RELEASED:
     *     - خسرنا السباق! القطعة تحررت لشخص آخر ولا توجد قطعة ثانية في المستودع.
     *     - صمام أمان صارم: ممنوع منعاً باتاً لمس المخزون أو إنقاصه (Zero Overselling Invariant).
     *     - تحويل الطلب إلى EXPIRED، وتوجيه الدفعة فوراً للمهمة 4.6 لإطلاق الاسترداد التلقائي (Auto-Refund)!
     * =========================================================================================
     */
    protected function handlePaymentIntentSucceeded(array $event, PaymentWebhookEvent $webhookRecord): array
    {
        $intent = $event['data']['object'] ?? [];
        $intentId = (string) ($intent['id'] ?? '');
        $metadata = $intent['metadata'] ?? [];
        $orderId = (string) ($metadata['order_id'] ?? '');

        // 1. تحديد سجل الدفعة (Payment) بالـ Intent ID أو الـ Metadata
        $payment = Payment::where('provider_payment_intent_id', $intentId)->first();
        if (! $payment && ! empty($orderId)) {
            $payment = Payment::where('order_id', $orderId)
                ->where('provider', 'STRIPE')
                ->where('status', Payment::STATUS_PENDING)
                ->first();
        }

        $targetOrderId = $orderId ?: $payment?->order_id;
        if (empty($targetOrderId)) {
            Log::error("Stripe Webhook [{$webhookRecord->event_id}]: No order associated with payment_intent [{$intentId}].");
            $webhookRecord->update([
                'processing_status' => PaymentWebhookEvent::STATUS_FAILED,
                'error_message' => "No order associated with payment_intent [{$intentId}].",
                'processed_at' => now(),
            ]);

            return [
                'status' => 'failed_missing_order',
                'event_id' => $webhookRecord->event_id,
                'event_type' => $webhookRecord->event_type,
                'is_duplicate' => false,
                'webhook_event_id' => $webhookRecord->id,
            ];
        }

        // 2. قفل صف الطلب والحجز التابع له على مستوى الصف بقفل حصري لمنع أي سباق متزامن
        $order = Order::where('id', $targetOrderId)
            ->lockForUpdate()
            ->first();

        if (! $order) {
            Log::error("Stripe Webhook [{$webhookRecord->event_id}]: Order [{$targetOrderId}] not found.");
            $webhookRecord->update([
                'processing_status' => PaymentWebhookEvent::STATUS_FAILED,
                'error_message' => "Order [{$targetOrderId}] not found.",
                'processed_at' => now(),
            ]);

            return [
                'status' => 'failed_order_not_found',
                'event_id' => $webhookRecord->event_id,
                'event_type' => $webhookRecord->event_type,
                'is_duplicate' => false,
                'webhook_event_id' => $webhookRecord->id,
            ];
        }

        $reservation = Reservation::with('items')
            ->where('id', $order->reservation_id)
            ->lockForUpdate()
            ->first();

        // 3. فحص التكرار (Idempotency Check): إذا كان الطلب مدفوعاً بالفعل، نتجاهل إعادة الخصم
        if ($order->status === Order::STATUS_PAID) {
            Log::info("Stripe Webhook: Order [{$order->order_number}] is already PAID. Skipping duplicate sale commitment.");
            if ($payment && $payment->status !== Payment::STATUS_SUCCEEDED) {
                $payment->update(['status' => Payment::STATUS_SUCCEEDED]);
            }

            $webhookRecord->update([
                'processing_status' => PaymentWebhookEvent::STATUS_PROCESSED,
                'processed_at' => now(),
            ]);

            return [
                'status' => 'already_paid',
                'event_id' => $webhookRecord->event_id,
                'event_type' => $webhookRecord->event_type,
                'is_duplicate' => false,
                'webhook_event_id' => $webhookRecord->id,
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'requires_refund' => false,
            ];
        }

        // =========================================================================================
        // 4. صمام الأمان الذري (Atomic CAS Guard): فحص هل الحجز ما زال نشطاً؟
        // =========================================================================================
        if ($reservation && $reservation->status === Reservation::STATUS_ACTIVE) {
            // [المسار السعيد - Happy Path]: كسبنا السباق!
            // المخزون ما زال محجوزاً حصرياً لهذا الطلب في خانة reserved_quantity ولم يلمسه عامل الحذف.
            
            // أ) تحويل حالة الطلب إلى مدفوع مع توثيق وقت الدفع
            $order->status = Order::STATUS_PAID;
            $order->paid_at = now();
            $order->save();

            // ب) تحويل حالة الحجز إلى مؤكد رسمياً لمنع عامل الـ Expiry من لمسه مستقبلاً
            $reservation->status = Reservation::STATUS_CONFIRMED;
            $reservation->confirmed_at = now();
            $reservation->save();

            // ج) تحديث حالة سجل الدفعة إلى ناجحة
            if ($payment) {
                $payment->status = Payment::STATUS_SUCCEEDED;
                $payment->provider_payment_intent_id = $intentId;
                $payment->save();
            }

            // د) ترحيل حركة المخزون في السجل المالي للقيد المزدوج:
            // نقل الكمية من reserved_quantity إلى sold_quantity وتوثيق قيد SALE رسمي
            foreach ($reservation->items as $item) {
                $this->stockMovementService->recordSale(
                    productWarehouse: $item->product_warehouse_id,
                    quantity: $item->quantity,
                    orderId: $order->id,
                    actor: 'stripe_webhook'
                );
            }

            $webhookRecord->update([
                'processing_status' => PaymentWebhookEvent::STATUS_PROCESSED,
                'processed_at' => now(),
            ]);

            Log::info("Order [{$order->order_number}] successfully paid and stock committed to SOLD in ledger.");

            return [
                'status' => 'paid_and_confirmed',
                'event_id' => $webhookRecord->event_id,
                'event_type' => $webhookRecord->event_type,
                'is_duplicate' => false,
                'webhook_event_id' => $webhookRecord->id,
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'requires_refund' => false,
            ];
        }

        // =========================================================================================
        // 5. سباق الدفع المتأخر بعد فوات الأوان (The Late Payment Race Condition):
        // =========================================================================================
        // خسرنا السباق! العميل استغرق وقتاً طويلاً في صفحة البنك وتجاوزت العملية مهلة الـ 10 دقائق.
        // عامل الـ Expiry قام بإلغاء الحجز مسبقاً وأعاد القطعة للرصيد المتاح وبيعت لعميل آخر.
        //
        // [قاعدة الحماية الذهبية]:
        // 1. لا نلمس المخزون إطلاقاً! ولا ننقص أي قطعة لأن الرصيد الفعلي في المستودع أصبح صفراً.
        // 2. تحويل الطلب إلى EXPIRED لحماية سجلات المستودع من البيع الوهمي.
        // 3. توثيق أن الدفعة تحتاج استرداد مالي (requires_refund = true) وتمريرها لمهمة الـ Auto-Refund!
        // =========================================================================================
        Log::warning("Late payment race condition: Payment succeeded for EXPIRED/RELEASED reservation [{$reservation?->id}] on Order [{$order->order_number}]. Inventory untouched; refund required.");

        $order->status = Order::STATUS_EXPIRED;
        $order->save();

        if ($payment) {
            $payment->status = Payment::STATUS_PENDING; // بانتظار استدعاء الاسترداد المالي في Task 4.6
            $payment->failure_reason = 'PAYMENT_SUCCEEDED_AFTER_RESERVATION_EXPIRED';
            $payment->save();
        }

        $webhookRecord->update([
            'processing_status' => PaymentWebhookEvent::STATUS_PROCESSED,
            'error_message' => 'Payment received after reservation expired. Initiating refund flow.',
            'processed_at' => now(),
        ]);

        return [
            'status' => 'expired_late_payment',
            'event_id' => $webhookRecord->event_id,
            'event_type' => $webhookRecord->event_type,
            'is_duplicate' => false,
            'webhook_event_id' => $webhookRecord->id,
            'order_id' => $order->id,
            'order_number' => $order->order_number,
            'requires_refund' => true,
        ];
    }

    /**
     * Handle payment_intent.payment_failed.
     */
    protected function handlePaymentIntentFailed(array $event, PaymentWebhookEvent $webhookRecord): array
    {
        $intent = $event['data']['object'] ?? [];
        $intentId = (string) ($intent['id'] ?? '');
        $metadata = $intent['metadata'] ?? [];
        $orderId = (string) ($metadata['order_id'] ?? '');
        $failureMessage = $intent['last_payment_error']['message'] ?? 'Payment failed on Stripe';

        $payment = Payment::where('provider_payment_intent_id', $intentId)->first();
        $targetOrderId = $orderId ?: $payment?->order_id;

        if ($payment) {
            $payment->status = Payment::STATUS_FAILED;
            $payment->failure_reason = $failureMessage;
            $payment->save();
        }

        if ($targetOrderId) {
            $order = Order::find($targetOrderId);
            if ($order && $order->status === Order::STATUS_PENDING_PAYMENT) {
                $order->status = Order::STATUS_PAYMENT_FAILED;
                $order->save();
            }
        }

        $webhookRecord->update([
            'processing_status' => PaymentWebhookEvent::STATUS_PROCESSED,
            'processed_at' => now(),
        ]);

        return [
            'status' => 'payment_failed',
            'event_id' => $webhookRecord->event_id,
            'event_type' => $webhookRecord->event_type,
            'is_duplicate' => false,
            'webhook_event_id' => $webhookRecord->id,
            'requires_refund' => false,
        ];
    }

    /**
     * Handle charge.refunded.
     */
    protected function handleChargeRefunded(array $event, PaymentWebhookEvent $webhookRecord): array
    {
        $charge = $event['data']['object'] ?? [];
        $paymentIntentId = (string) ($charge['payment_intent'] ?? '');

        if ($paymentIntentId) {
            $payment = Payment::where('provider_payment_intent_id', $paymentIntentId)->first();
            if ($payment) {
                $payment->update([
                    'status' => Payment::STATUS_REFUNDED,
                    'refunded_at' => now(),
                ]);
            }
        }

        $webhookRecord->update([
            'processing_status' => PaymentWebhookEvent::STATUS_PROCESSED,
            'processed_at' => now(),
        ]);

        return [
            'status' => 'charge_refunded',
            'event_id' => $webhookRecord->event_id,
            'event_type' => $webhookRecord->event_type,
            'is_duplicate' => false,
            'webhook_event_id' => $webhookRecord->id,
            'requires_refund' => false,
        ];
    }

    /**
     * Handle unsupported event types by archiving them as IGNORED.
     */
    protected function handleUnsupportedEvent(array $event, PaymentWebhookEvent $webhookRecord): array
    {
        $webhookRecord->update([
            'processing_status' => PaymentWebhookEvent::STATUS_IGNORED,
            'processed_at' => now(),
        ]);

        Log::info("Stripe Webhook [{$webhookRecord->event_id}] type '{$webhookRecord->event_type}' is unsupported; marked as IGNORED.");

        return [
            'status' => 'ignored',
            'event_id' => $webhookRecord->event_id,
            'event_type' => $webhookRecord->event_type,
            'is_duplicate' => false,
            'webhook_event_id' => $webhookRecord->id,
            'requires_refund' => false,
        ];
    }
}
