<?php

namespace App\Services;

use App\Exceptions\InsufficientStockException;
use App\Models\ProductWarehouse;
use App\Models\StockMovement;
use Illuminate\Support\Facades\DB;

class StockMovementService
{
    /**
     * Record an inward stock receipt (e.g. new shipment arrived at the warehouse).
     */
    public function recordReceipt(
        ProductWarehouse|string $productWarehouse,
        int $quantity,
        string $reason = 'Stock Inward Receipt',
        ?string $actor = 'system'
    ): StockMovement {
        if ($quantity <= 0) {
            throw new \InvalidArgumentException('Receipt quantity must be strictly positive.');
        }

        $id = $this->resolveId($productWarehouse);

        return DB::transaction(function () use ($productWarehouse, $id, $quantity, $reason, $actor) {
            // Lock row fresh from DB to prevent race conditions
            $pw = $this->lockRow($id);

            // 1. Update balances on product_warehouses
            $pw->available_quantity += $quantity;
            $pw->version += 1;
            $pw->save();

            $this->syncOriginalModel($productWarehouse, $pw);

            // 2. Append immutable record to stock_movements ledger
            return StockMovement::create([
                'organization_id' => $pw->organization_id,
                'product_warehouse_id' => $pw->id,
                'movement_type' => StockMovement::TYPE_RECEIPT,
                'quantity_delta' => $quantity,
                'available_delta' => $quantity,
                'reserved_delta' => 0,
                'sold_delta' => 0,
                'reference_type' => 'StockReceipt',
                'reason' => $reason,
                'created_by' => $actor,
                'created_at' => now(),
            ]);
        });
    }

    /**
     * Record a reservation hold (moves stock: available -> reserved).
     */
    public function recordReservation(
        ProductWarehouse|string $productWarehouse,
        int $quantity,
        string $reservationId,
        ?string $actor = 'system'
    ): StockMovement {
        if ($quantity <= 0) {
            throw new \InvalidArgumentException('Reservation quantity must be strictly positive.');
        }

        $id = $this->resolveId($productWarehouse);

        return DB::transaction(function () use ($productWarehouse, $id, $quantity, $reservationId, $actor) {
            // Lock row fresh from DB with FOR UPDATE
            $pw = $this->lockRow($id);

            if ($pw->available_quantity < $quantity) {
                throw new InsufficientStockException(
                    message: "Insufficient available stock. Requested {$quantity}, available: {$pw->available_quantity}.",
                    requested: $quantity,
                    available: $pw->available_quantity
                );
            }

            // Move stock from available to reserved
            $pw->available_quantity -= $quantity;
            $pw->reserved_quantity += $quantity;
            $pw->version += 1;
            $pw->save();

            $this->syncOriginalModel($productWarehouse, $pw);

            return StockMovement::create([
                'organization_id' => $pw->organization_id,
                'product_warehouse_id' => $pw->id,
                'movement_type' => StockMovement::TYPE_RESERVATION,
                'quantity_delta' => 0, // No physical stock left the building
                'available_delta' => -$quantity,
                'reserved_delta' => +$quantity,
                'sold_delta' => 0,
                'reference_type' => 'Reservation',
                'reference_id' => $reservationId,
                'reason' => 'Customer reservation hold (10 min TTL)',
                'created_by' => $actor,
                'created_at' => now(),
            ]);
        });
    }

    /**
     * Record a reservation release (moves stock: reserved -> available).
     * Used when hold expires or customer cancels before payment.
     */
    public function recordRelease(
        ProductWarehouse|string $productWarehouse,
        int $quantity,
        string $reservationId,
        string $reason = 'Reservation expired',
        ?string $actor = 'system'
    ): StockMovement {
        if ($quantity <= 0) {
            throw new \InvalidArgumentException('Release quantity must be strictly positive.');
        }

        $id = $this->resolveId($productWarehouse);

        return DB::transaction(function () use ($productWarehouse, $id, $quantity, $reservationId, $reason, $actor) {
            $pw = $this->lockRow($id);

            if ($pw->reserved_quantity < $quantity) {
                throw new \LogicException("Cannot release {$quantity} units; reserved balance is only {$pw->reserved_quantity}.");
            }

            // Move stock back from reserved to available
            $pw->reserved_quantity -= $quantity;
            $pw->available_quantity += $quantity;
            $pw->version += 1;
            $pw->save();

            $this->syncOriginalModel($productWarehouse, $pw);

            return StockMovement::create([
                'organization_id' => $pw->organization_id,
                'product_warehouse_id' => $pw->id,
                'movement_type' => StockMovement::TYPE_RELEASE,
                'quantity_delta' => 0,
                'available_delta' => +$quantity,
                'reserved_delta' => -$quantity,
                'sold_delta' => 0,
                'reference_type' => 'Reservation',
                'reference_id' => $reservationId,
                'reason' => $reason,
                'created_by' => $actor,
                'created_at' => now(),
            ]);
        });
    }

    /**
     * Record a confirmed sale (moves stock: reserved -> sold).
     * Used upon verified Stripe payment webhook.
     */
    public function recordSale(
        ProductWarehouse|string $productWarehouse,
        int $quantity,
        string $orderId,
        ?string $actor = 'system'
    ): StockMovement {
        if ($quantity <= 0) {
            throw new \InvalidArgumentException('Sale quantity must be strictly positive.');
        }

        $id = $this->resolveId($productWarehouse);

        return DB::transaction(function () use ($productWarehouse, $id, $quantity, $orderId, $actor) {
            $pw = $this->lockRow($id);

            if ($pw->reserved_quantity < $quantity) {
                throw new \LogicException("Cannot commit sale for {$quantity} units; reserved balance is only {$pw->reserved_quantity}.");
            }

            // Move stock from reserved to permanently sold
            $pw->reserved_quantity -= $quantity;
            $pw->sold_quantity += $quantity;
            $pw->version += 1;
            $pw->save();

            $this->syncOriginalModel($productWarehouse, $pw);

            return StockMovement::create([
                'organization_id' => $pw->organization_id,
                'product_warehouse_id' => $pw->id,
                'movement_type' => StockMovement::TYPE_SALE,
                'quantity_delta' => 0,
                'available_delta' => 0,
                'reserved_delta' => -$quantity,
                'sold_delta' => +$quantity,
                'reference_type' => 'Order',
                'reference_id' => $orderId,
                'reason' => 'Payment verified, stock converted to sold',
                'created_by' => $actor,
                'created_at' => now(),
            ]);
        });
    }

    /**
     * Record manual stock adjustment (physical inventory count correction / damage write-off).
     */
    public function recordAdjustment(
        ProductWarehouse|string $productWarehouse,
        int $quantityDelta,
        string $reason,
        ?string $actor = 'system'
    ): StockMovement {
        if ($quantityDelta === 0) {
            throw new \InvalidArgumentException('Adjustment delta cannot be zero.');
        }

        $id = $this->resolveId($productWarehouse);

        return DB::transaction(function () use ($productWarehouse, $id, $quantityDelta, $reason, $actor) {
            $pw = $this->lockRow($id);

            $newAvailable = $pw->available_quantity + $quantityDelta;

            if ($newAvailable < 0) {
                throw new InsufficientStockException(
                    message: "Adjustment would result in negative available inventory ({$newAvailable}).",
                    requested: abs($quantityDelta),
                    available: $pw->available_quantity
                );
            }

            $pw->available_quantity = $newAvailable;
            $pw->version += 1;
            $pw->save();

            $this->syncOriginalModel($productWarehouse, $pw);

            return StockMovement::create([
                'organization_id' => $pw->organization_id,
                'product_warehouse_id' => $pw->id,
                'movement_type' => StockMovement::TYPE_ADJUSTMENT,
                'quantity_delta' => $quantityDelta,
                'available_delta' => $quantityDelta,
                'reserved_delta' => 0,
                'sold_delta' => 0,
                'reference_type' => 'ManualAdjustment',
                'reason' => $reason,
                'created_by' => $actor,
                'created_at' => now(),
            ]);
        });
    }


// resolveId: مهمتها توحيد المدخلات، سواء أعطيتها كائن الموديل أو المعرف المباشر كـ UUID string، سترجع لك الـ ID دائماً.

// lockRow: تستعلم من PostgreSQL وتضع قفل حصري (FOR UPDATE) على الصف ليعود بأحدث قيمة حقيقية من الداتابيز.
  
// syncOriginalModel: إذا كان المستدعي قد مرر كائناً أصلياً، تقوم بتحديث خصائصه في الذاكرة فوراً ليتطابق مع القيمة الجديدة في قاعدة البيانات دون أن يضطر لعمل refresh().

  

    /**
     * Resolve the ProductWarehouse ID from either an instance or a UUID string.
     */
    private function resolveId(ProductWarehouse|string $productWarehouse): string
    {
        return $productWarehouse instanceof ProductWarehouse ? $productWarehouse->id : $productWarehouse;
    }

    /**
     * Fetch and lock the ProductWarehouse row using pessimistic locking (SELECT ... FOR UPDATE).
     */
    private function lockRow(string $id): ProductWarehouse
    {
        return ProductWarehouse::where('id', $id)
            ->lockForUpdate()
            ->firstOrFail();
    }

    /**
     * If the caller passed a model instance, sync its in-memory attributes with the updated locked model.
     */
    private function syncOriginalModel(ProductWarehouse|string $productWarehouse, ProductWarehouse $lockedModel): void
    {
        if ($productWarehouse instanceof ProductWarehouse) {
            $productWarehouse->setRawAttributes($lockedModel->getAttributes(), true);
        }
    }
}
