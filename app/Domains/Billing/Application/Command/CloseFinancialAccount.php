<?php

declare(strict_types=1);

namespace App\Domains\Billing\Application\Command;

use App\Domains\Billing\Domain\ValueObject\FinancialAccountReason;
use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Close a Financial Account — {OPEN | ACTIVE | SETTLED} → CLOSED (terminal)
 * (20-billing-and-payments-domain.md §301; ADR-187 D-D).
 *
 * The handler enforces a zero-outstanding-balance guard (`FinancialAccountNotSettled`)
 * — closing must not hide an obligation (§84). Transaction history is preserved.
 * `reasonCode` SET-validated (§107).
 */
final readonly class CloseFinancialAccount implements Auditable, AuthorizesAgainst
{
    public string $reasonCode;

    public function __construct(
        public string $financialAccountId,
        public string $actorId,
        string $reasonCode,
        public ?string $idempotencyKey = null,
    ) {
        if (! Str::isUuid($financialAccountId) || ! Str::isUuid($actorId)) {
            throw new InvalidArgumentException('CloseFinancialAccount ids must be UUIDs (IC §3).');
        }

        $this->reasonCode = FinancialAccountReason::assertClose($reasonCode);
    }

    public function policyAbility(): string
    {
        return 'close';
    }

    public function subjectId(): string
    {
        return $this->financialAccountId;
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'billing.financial_account_closed',
            actorId: $this->actorId,
            targetType: 'financial_account',
            targetId: $this->financialAccountId,
            reason: $this->reasonCode,
        );
    }
}
