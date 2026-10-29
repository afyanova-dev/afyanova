<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Contracts\Dto;

/**
 * One line of a goods receipt as shown on the register detail and the printed
 * GRN (Phase 1 / P1-07–P1-08).
 *
 * `quantityAccepted` is the generated column value. `movementNumber` is null
 * when the line was fully rejected and no stock posted.
 *
 * @property numeric-string $enteredQuantity
 * @property numeric-string $packSize
 * @property numeric-string $quantityDelivered
 * @property numeric-string $quantityRejected
 * @property numeric-string $quantityAccepted
 * @property numeric-string|null $poUnitPrice
 * @property numeric-string|null $invoiceQuantity
 * @property numeric-string|null $invoiceUnitPrice
 */
final readonly class GoodsReceiptLineSummary
{
    public function __construct(
        public string $receiptLineId,
        public string $itemId,
        public string $itemCode,
        public string $itemName,
        public ?string $poLineRef,
        public string $enteredUnit,
        public string $enteredQuantity,
        public string $packSize,
        public string $quantityDelivered,
        public string $quantityRejected,
        public string $quantityAccepted,
        public ?string $poUnit,
        public ?string $poUnitPrice,
        public ?string $invoiceQuantity,
        public ?string $invoiceUnitPrice,
        public string $uom,
        public ?string $batchNumber,
        public ?string $expiryDate,
        public ?string $manufactureDate,
        public string $condition,
        public ?string $substitutedForItemId,
        public ?string $conditionNotes,
        public ?string $rejectionReason,
        public ?string $movementNumber,
    ) {}
}
