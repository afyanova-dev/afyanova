<?php

declare(strict_types=1);

namespace App\Domains\Billing\Application\Command;

use App\Domains\Billing\Domain\Money;
use App\Domains\Billing\Domain\ValueObject\AdjustmentType;
use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Raise a post-charge account-level adjustment
 * (20-billing-and-payments-domain.md §81; ADR-191 G-3 / D7-D). Class-level
 * `request` (`billing.adjustments.request`). Below the per-tenant dual-control
 * threshold this also applies it; at / above it — or with no threshold — it
 * lands `REQUESTED` for a DISTINCT approver. The ledger direction is derived
 * from the type (`ADMIN_DEBIT` raises the balance; every other type lowers it).
 * `invoiceId` is a reporting tag only (§134).
 */
final readonly class RequestAdjustment implements Auditable, AuthorizesAgainst
{
    public string $adjustmentType;

    public string $direction;

    /** Validated + normalised to a scale-2 numeric string via {@see Money::of()}. */
    public string $amount;

    public function __construct(
        public string $adjustmentId,
        public string $actorId,
        public string $financialAccountId,
        string $amount,
        string $adjustmentType,
        public ?string $invoiceId = null,
        public ?string $note = null,
        public ?string $idempotencyKey = null,
    ) {
        foreach (['adjustmentId' => $adjustmentId, 'actorId' => $actorId, 'financialAccountId' => $financialAccountId] as $name => $value) {
            if (! Str::isUuid($value)) {
                throw new InvalidArgumentException("RequestAdjustment::\${$name} must be a UUID (IC §3).");
            }
        }

        if ($invoiceId !== null && ! Str::isUuid($invoiceId)) {
            throw new InvalidArgumentException('RequestAdjustment::$invoiceId must be a UUID (IC §3).');
        }

        $this->adjustmentType = AdjustmentType::assert($adjustmentType);
        $this->direction = AdjustmentType::direction($this->adjustmentType);

        if (! is_numeric($amount) || bccomp(Money::of($amount), '0', 2) <= 0) {
            throw new InvalidArgumentException('RequestAdjustment::$amount must be a positive money value (§81).');
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
            action: 'billing.adjustment_requested',
            actorId: $this->actorId,
            targetType: 'adjustment',
            targetId: $this->adjustmentId,
            reason: $this->adjustmentType,
            metadata: ['financial_account_id' => $this->financialAccountId, 'amount' => $this->amount, 'direction' => $this->direction],
        );
    }
}
