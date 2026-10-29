<?php

declare(strict_types=1);

namespace App\Domains\Billing\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * The cashier is reconciling their own cash session with a non-zero variance (§157 / §165 separation of duties; the Procurement SelfApprovalNotPermitted precedent). Maps to HTTP 409.
 */
final class SelfReconcileNotPermitted extends DomainException
{
    public static function session(string $cashSessionId): self
    {
        return new self(sprintf(
            'Cash session [%s] has a non-zero variance and cannot be reconciled by its own cashier (§157).',
            $cashSessionId,
        ));
    }

    public function errorCode(): string
    {
        return 'self_reconcile_not_permitted';
    }
}
