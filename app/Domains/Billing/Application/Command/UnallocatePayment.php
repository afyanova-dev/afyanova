<?php

declare(strict_types=1);

namespace App\Domains\Billing\Application\Command;

use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Release the live allocation of a Payment to an Invoice
 * (20-billing-and-payments-domain.md §65; ADR-191 B5-A). Per-subject `manage`
 * (`billing.payments.manage`). Deactivates the row (`allocation_active = false`
 * — the historical row is preserved, the Bill.4 INV-3 pattern); idempotent —
 * a no-op if there is no live allocation for the pair. Posts NO ledger entry.
 */
final readonly class UnallocatePayment implements Auditable, AuthorizesAgainst
{
    public function __construct(
        public string $actorId,
        public string $paymentId,
        public string $invoiceId,
        public ?string $idempotencyKey = null,
    ) {
        foreach (['actorId' => $actorId, 'paymentId' => $paymentId, 'invoiceId' => $invoiceId] as $name => $value) {
            if (! Str::isUuid($value)) {
                throw new InvalidArgumentException("UnallocatePayment::\${$name} must be a UUID (IC §3).");
            }
        }
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
            action: 'billing.payment_unallocated',
            actorId: $this->actorId,
            targetType: 'payment',
            targetId: $this->paymentId,
            metadata: ['invoice_id' => $this->invoiceId],
        );
    }
}
