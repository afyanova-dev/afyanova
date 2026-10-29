<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Authorization;

use App\Domains\Inventory\Application\Command\AttachGoodsReceiptInvoice;
use App\Domains\Inventory\Application\Command\MatchGoodsReceiptInvoice;
use App\Domains\Inventory\Domain\Model\GoodsReceipt;
use App\Models\User;
use App\Shared\Authorization\BasePolicy;

final class GoodsReceiptPolicy extends BasePolicy
{
    public function updateInvoice(User $user, GoodsReceipt $receipt, ?AttachGoodsReceiptInvoice $command = null): bool
    {
        return $this->context->hasTenant()
            && (string) $user->tenant_id === $this->context->tenantId()
            && (string) $receipt->tenant_id === $this->context->tenantId()
            && ($command === null || $this->context->facilityId() === (string) $receipt->facility_id)
            && $this->permits($user, 'inventory.receipts.create');
    }

    public function matchInvoice(User $user, GoodsReceipt $receipt, ?MatchGoodsReceiptInvoice $command = null): bool
    {
        return $this->context->hasTenant()
            && (string) $user->tenant_id === $this->context->tenantId()
            && (string) $receipt->tenant_id === $this->context->tenantId()
            && ($command === null || $this->context->facilityId() === (string) $receipt->facility_id)
            && $this->permits($user, 'procurement.invoices.match');
    }
}
