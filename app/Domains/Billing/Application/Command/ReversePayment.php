<?php

declare(strict_types=1);

namespace App\Domains\Billing\Application\Command;

use App\Domains\Billing\Domain\ValueObject\PaymentReversalReason;
use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Reverse a payment — RECORDED → REVERSED (20-billing-and-payments-domain.md
 * §70; ADR-191 B5-C). Per-subject `reverse` (`billing.payments.reverse` — the
 * §164 / §165 record-vs-reverse separation). FULL reversal only: a compensating
 * `PAYMENT` / `DEBIT` ledger entry, the historical payment + receipt preserved
 * (§94). NOT a refund (money returned — deferred). Idempotent on an
 * already-REVERSED payment.
 */
final readonly class ReversePayment implements Auditable, AuthorizesAgainst
{
    public string $reasonCode;

    public function __construct(
        public string $actorId,
        public string $paymentId,
        string $reasonCode,
        public ?string $idempotencyKey = null,
    ) {
        if (! Str::isUuid($actorId) || ! Str::isUuid($paymentId)) {
            throw new InvalidArgumentException('ReversePayment ids must be UUIDs (IC §3).');
        }

        $this->reasonCode = PaymentReversalReason::assert($reasonCode);
    }

    public function policyAbility(): string
    {
        return 'reverse';
    }

    public function subjectId(): string
    {
        return $this->paymentId;
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'billing.payment_reversed',
            actorId: $this->actorId,
            targetType: 'payment',
            targetId: $this->paymentId,
            reason: $this->reasonCode,
        );
    }
}
