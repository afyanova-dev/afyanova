<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Application\Command;

use App\Domains\Inventory\Domain\Quantity;
use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

final readonly class MatchGoodsReceiptInvoice implements Auditable, AuthorizesAgainst
{
    /**
     * @param  list<array{receipt_line_id: string, invoice_quantity: string, invoice_unit_price: string}>  $lines
     */
    public function __construct(
        public string $goodsReceiptId,
        public string $actorId,
        public array $lines,
    ) {
        if (! Str::isUuid($goodsReceiptId) || ! Str::isUuid($actorId)) {
            throw new InvalidArgumentException('MatchGoodsReceiptInvoice ids must be UUIDs.');
        }

        if ($lines === []) {
            throw new InvalidArgumentException('At least one invoice line is required.');
        }

        foreach ($lines as $line) {
            if (! Str::isUuid($line['receipt_line_id'])
                || ! is_numeric($line['invoice_quantity'])
                || Quantity::isNegative($line['invoice_quantity'])
                || ! is_numeric($line['invoice_unit_price'])
                || bccomp($line['invoice_unit_price'], '0', 2) < 0) {
                throw new InvalidArgumentException('Invoice quantities and prices must be zero or positive.');
            }
        }
    }

    public function policyAbility(): string
    {
        return 'matchInvoice';
    }

    public function subjectId(): ?string
    {
        return $this->goodsReceiptId;
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'inventory.goods_receipt_invoice_matched',
            actorId: $this->actorId,
            targetType: 'goods_receipt',
            targetId: $this->goodsReceiptId,
        );
    }
}
