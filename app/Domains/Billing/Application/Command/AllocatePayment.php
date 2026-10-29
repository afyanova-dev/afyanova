<?php

declare(strict_types=1);

namespace App\Domains\Billing\Application\Command;

use App\Domains\Billing\Domain\Money;
use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Allocate part of a Payment to an ISSUED Invoice
 * (20-billing-and-payments-domain.md §65 / §294; ADR-191 B5-A).
 *
 * Per-subject `manage` (`billing.payments.manage` — reused; allocation is a
 * payment-clerk function). Both parents must be on the SAME Financial Account.
 * Σ(active allocations) may not exceed `payment.amount` or `invoice.net`. ONE
 * live allocation per (payment, invoice) — re-allocate via
 * `UnallocatePayment` + a fresh `AllocatePayment`. Posts NO ledger entry.
 */
final readonly class AllocatePayment implements Auditable, AuthorizesAgainst
{
    /** Validated + normalised to a scale-2 numeric string via {@see Money::of()}. */
    public string $amount;

    public function __construct(
        public string $actorId,
        public string $allocationId,
        public string $paymentId,
        public string $invoiceId,
        string $amount,
        public ?string $idempotencyKey = null,
    ) {
        foreach (['actorId' => $actorId, 'allocationId' => $allocationId, 'paymentId' => $paymentId, 'invoiceId' => $invoiceId] as $name => $value) {
            if (! Str::isUuid($value)) {
                throw new InvalidArgumentException("AllocatePayment::\${$name} must be a UUID (IC §3).");
            }
        }

        if (! is_numeric($amount) || bccomp(Money::of($amount), '0', 2) <= 0) {
            throw new InvalidArgumentException('AllocatePayment::$amount must be a positive money value (§65).');
        }

        $this->amount = Money::of($amount);
    }

    public function policyAbility(): string
    {
        return 'manage';
    }

    public function subjectId(): string
    {
        return $this->paymentId;
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'billing.payment_allocated',
            actorId: $this->actorId,
            targetType: 'payment',
            targetId: $this->paymentId,
            metadata: ['invoice_id' => $this->invoiceId, 'amount' => $this->amount],
        );
    }
}
