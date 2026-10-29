<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Application\Command;

use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

final readonly class AttachGoodsReceiptInvoice implements Auditable, AuthorizesAgainst
{
    public function __construct(
        public string $goodsReceiptId,
        public string $actorId,
        public string $invoiceNumber,
        public ?string $invoiceDate,
        public string $invoiceCurrency = 'TZS',
        public ?string $invoiceTotal = null,
    ) {
        if (! Str::isUuid($goodsReceiptId) || ! Str::isUuid($actorId)) {
            throw new InvalidArgumentException('AttachGoodsReceiptInvoice ids must be UUIDs.');
        }

        if (trim($invoiceNumber) === '' || mb_strlen(trim($invoiceNumber)) > 100) {
            throw new InvalidArgumentException('Invoice number is required and must be at most 100 characters.');
        }

        if (strlen(trim($invoiceCurrency)) !== 3) {
            throw new InvalidArgumentException('Invoice currency must be a 3-letter currency code.');
        }

        if ($invoiceTotal !== null && (! is_numeric($invoiceTotal) || (float) $invoiceTotal < 0)) {
            throw new InvalidArgumentException('Invoice total must be zero or positive.');
        }
    }

    public function policyAbility(): string
    {
        return 'updateInvoice';
    }

    public function subjectId(): ?string
    {
        return $this->goodsReceiptId;
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'inventory.goods_receipt_invoice_attached',
            actorId: $this->actorId,
            targetType: 'goods_receipt',
            targetId: $this->goodsReceiptId,
        );
    }
}
