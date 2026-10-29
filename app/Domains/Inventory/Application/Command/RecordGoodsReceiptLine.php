<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Application\Command;

use App\Domains\Inventory\Domain\Model\GoodsReceiptLine;
use App\Domains\Inventory\Domain\Quantity;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * One entered line of a multi-line goods receipt (Phase 1).
 *
 * `quantityDelivered` / `quantityRejected` are in the item's BASE stock unit.
 * `enteredUnit` / `enteredQuantity` / `packSize` are the receiver's entry and
 * must multiply out exactly to `quantityDelivered` (the same pack rule as the
 * single-line `ReceiveStock` command).
 *
 * `poLineRef` is the exact Procurement purchase-order line; it is authoritative
 * and never inferred from the item code.
 */
final readonly class RecordGoodsReceiptLine
{
    public function __construct(
        public string $receiptLineId,
        public string $itemId,
        public ?string $poLineRef,
        public string $enteredUnit,
        public string $enteredQuantity,
        public string $packSize,
        public string $quantityDelivered,
        public string $quantityRejected,
        public ?string $batchNumber = null,
        public ?string $expiryDate = null,
        public ?string $manufactureDate = null,
        public string $condition = GoodsReceiptLine::CONDITION_GOOD,
        public ?string $conditionNotes = null,
        public ?string $rejectionReason = null,
        public ?string $substitutedForItemId = null,
    ) {
        if (! Str::isUuid($receiptLineId)) {
            throw new InvalidArgumentException('RecordGoodsReceiptLine::$receiptLineId must be a UUID.');
        }

        if (! Str::isUuid($itemId)) {
            throw new InvalidArgumentException('RecordGoodsReceiptLine::$itemId must be a UUID.');
        }

        if ($poLineRef !== null && ! Str::isUuid($poLineRef)) {
            throw new InvalidArgumentException('RecordGoodsReceiptLine::$poLineRef must be a UUID or null.');
        }

        if ($substitutedForItemId !== null && ! Str::isUuid($substitutedForItemId)) {
            throw new InvalidArgumentException('RecordGoodsReceiptLine::$substitutedForItemId must be a UUID or null.');
        }

        $enteredUnit = strtoupper(trim($enteredUnit));
        if ($enteredUnit === '') {
            throw new InvalidArgumentException('RecordGoodsReceiptLine::$enteredUnit must not be blank.');
        }

        foreach ([
            'enteredQuantity' => $enteredQuantity,
            'packSize' => $packSize,
            'quantityDelivered' => $quantityDelivered,
        ] as $name => $value) {
            if (! is_numeric($value) || ! Quantity::isPositive($value)) {
                throw new InvalidArgumentException("RecordGoodsReceiptLine::\${$name} must be a positive decimal.");
            }
        }

        if (! is_numeric($quantityRejected) || Quantity::isNegative($quantityRejected)) {
            throw new InvalidArgumentException('RecordGoodsReceiptLine::$quantityRejected must be zero or positive.');
        }

        if (Quantity::compare($quantityRejected, $quantityDelivered) > 0) {
            throw new InvalidArgumentException('RecordGoodsReceiptLine::$quantityRejected must not exceed the delivered quantity.');
        }

        if (Quantity::compare(Quantity::mul($enteredQuantity, $packSize), $quantityDelivered) !== 0) {
            throw new InvalidArgumentException('RecordGoodsReceiptLine entered quantity and pack size must multiply to the delivered base quantity.');
        }

        if (! GoodsReceiptLine::isKnownCondition($condition)) {
            throw new InvalidArgumentException('RecordGoodsReceiptLine::$condition is not recognised.');
        }
    }

    /** @return numeric-string */
    public function acceptedQuantity(): string
    {
        return Quantity::sub($this->quantityDelivered, $this->quantityRejected);
    }
}
