<?php

declare(strict_types=1);

namespace App\Domains\Billing\Application\Command;

use App\Domains\Billing\Domain\ValueObject\InvoiceReason;
use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Void an invoice — {DRAFT | ISSUED} → VOIDED (20-billing-and-payments-domain.md
 * §136; ADR-190 D-C). Per-subject `manage`. The document-level correction path
 * (D3 — no credit notes in Bill.4). Preserves the historical invoice + lines +
 * reason + actor + time (INV-7); deactivates every live line, releasing the
 * charges for future invoicing.
 */
final readonly class VoidInvoice implements Auditable, AuthorizesAgainst
{
    public string $reasonCode;

    public function __construct(
        public string $actorId,
        public string $invoiceId,
        string $reasonCode,
        public ?string $idempotencyKey = null,
    ) {
        if (! Str::isUuid($actorId) || ! Str::isUuid($invoiceId)) {
            throw new InvalidArgumentException('VoidInvoice ids must be UUIDs (IC §3).');
        }

        $this->reasonCode = InvoiceReason::assertVoid($reasonCode);
    }

    public function policyAbility(): string
    {
        return 'manage';
    }

    public function subjectId(): string
    {
        return $this->invoiceId;
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'billing.invoice_voided',
            actorId: $this->actorId,
            targetType: 'invoice',
            targetId: $this->invoiceId,
            reason: $this->reasonCode,
        );
    }
}
