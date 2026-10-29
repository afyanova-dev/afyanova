<?php

declare(strict_types=1);

namespace App\Domains\Billing\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * A write-off / adjustment (or its reversal) was approved / reversed by the
 * user who requested it (20-billing-and-payments-domain.md §165 / §167;
 * ADR-191 G-3 — the Procurement `SelfApprovalNotPermitted` / §228 precedent).
 * Maps to HTTP 409.
 */
final class SelfApprovalNotPermitted extends DomainException
{
    public static function forWriteOff(string $writeOffId): self
    {
        return new self(sprintf('Write-off [%s] cannot be approved or reversed by the user who requested it (§165).', $writeOffId));
    }

    public static function forAdjustment(string $adjustmentId): self
    {
        return new self(sprintf('Adjustment [%s] cannot be approved or reversed by the user who requested it (§165).', $adjustmentId));
    }

    public function errorCode(): string
    {
        return 'self_approval_not_permitted';
    }
}
