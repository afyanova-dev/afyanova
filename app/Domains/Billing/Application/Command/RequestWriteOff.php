<?php

declare(strict_types=1);

namespace App\Domains\Billing\Application\Command;

use App\Domains\Billing\Domain\Money;
use App\Domains\Billing\Domain\ValueObject\WriteOffReason;
use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Raise a write-off against a Financial Account
 * (20-billing-and-payments-domain.md §78; ADR-191 G-3 / D7-A). Class-level
 * `request` (`billing.writeoffs.request`). Below the per-tenant dual-control
 * threshold the request is applied immediately (self-approved); at / above it
 * — or with no threshold configured — it lands `REQUESTED` for a DISTINCT
 * approver. `invoiceId` is a reporting tag only (§134).
 */
final readonly class RequestWriteOff implements Auditable, AuthorizesAgainst
{
    public string $reasonCode;

    /** Validated + normalised to a scale-2 numeric string via {@see Money::of()}. */
    public string $amount;

    public function __construct(
        public string $writeOffId,
        public string $actorId,
        public string $financialAccountId,
        string $amount,
        string $reasonCode,
        public ?string $invoiceId = null,
        public ?string $note = null,
        public ?string $idempotencyKey = null,
    ) {
        foreach (['writeOffId' => $writeOffId, 'actorId' => $actorId, 'financialAccountId' => $financialAccountId] as $name => $value) {
            if (! Str::isUuid($value)) {
                throw new InvalidArgumentException("RequestWriteOff::\${$name} must be a UUID (IC §3).");
            }
        }

        if ($invoiceId !== null && ! Str::isUuid($invoiceId)) {
            throw new InvalidArgumentException('RequestWriteOff::$invoiceId must be a UUID (IC §3).');
        }

        $this->reasonCode = WriteOffReason::assert($reasonCode);

        if (! is_numeric($amount) || bccomp(Money::of($amount), '0', 2) <= 0) {
            throw new InvalidArgumentException('RequestWriteOff::$amount must be a positive money value (§78).');
        }

        $this->amount = Money::of($amount);
    }

    public function policyAbility(): string
    {
        return 'request';
    }

    public function subjectId(): ?string
    {
        return null;
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'billing.write_off_requested',
            actorId: $this->actorId,
            targetType: 'write_off',
            targetId: $this->writeOffId,
            reason: $this->reasonCode,
            metadata: ['financial_account_id' => $this->financialAccountId, 'amount' => $this->amount],
        );
    }
}
