<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Application\Command;

use App\Domains\Inventory\Domain\Model\SupplierReturn;
use App\Domains\Inventory\Domain\Quantity;
use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

final readonly class ReturnGoodsToSupplier implements Auditable, AuthorizesAgainst
{
    /**
     * @param  list<array{receipt_line_id: string, quantity: string, credit_note_ref?: ?string, notes?: ?string}>  $lines
     */
    public function __construct(
        public string $returnId,
        public string $actorId,
        public string $receiptId,
        public string $locationId,
        public string $reason,
        public array $lines,
    ) {
        foreach (['returnId' => $returnId, 'actorId' => $actorId, 'receiptId' => $receiptId, 'locationId' => $locationId] as $name => $value) {
            if (! Str::isUuid($value)) {
                throw new InvalidArgumentException("ReturnGoodsToSupplier::\${$name} must be a UUID.");
            }
        }

        if (! in_array(strtoupper(trim($reason)), [
            SupplierReturn::REASON_DAMAGED_AFTER_RECEIPT,
            SupplierReturn::REASON_WRONG_ITEM,
            SupplierReturn::REASON_EXPIRED,
        ], true)) {
            throw new InvalidArgumentException('A valid supplier return reason is required.');
        }
        if ($lines === []) {
            throw new InvalidArgumentException('At least one goods-receipt line is required for a supplier return.');
        }

        $seen = [];
        foreach ($lines as $line) {
            if (! Str::isUuid($line['receipt_line_id'])
                || isset($seen[$line['receipt_line_id']])
                || ! is_numeric($line['quantity'])
                || ! Quantity::isPositive($line['quantity'])) {
                throw new InvalidArgumentException('Supplier return lines must have unique receipt lines and positive quantities.');
            }
            $seen[$line['receipt_line_id']] = true;
        }
    }

    public function policyAbility(): string
    {
        return 'returnToSupplier';
    }

    public function subjectId(): ?string
    {
        return null;
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'inventory.supplier_return_posted',
            actorId: $this->actorId,
            targetType: 'supplier_return',
            targetId: $this->returnId,
            metadata: [
                'receipt_id' => $this->receiptId,
                'location_id' => $this->locationId,
                'reason' => strtoupper(trim($this->reason)),
                'line_count' => count($this->lines),
            ],
        );
    }
}
