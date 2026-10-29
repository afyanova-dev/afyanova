<?php

declare(strict_types=1);

namespace App\Domains\Billing\Application\Command;

use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Remove a charge from a DRAFT invoice (ADR-190 D-C). Per-subject `manage`.
 * Deactivates the live line (`line_active = false`), releasing the charge for
 * future invoicing (INV-3). Idempotent — no-op if the charge has no live line
 * on the invoice.
 */
final readonly class RemoveInvoiceLine implements Auditable, AuthorizesAgainst
{
    public function __construct(
        public string $actorId,
        public string $invoiceId,
        public string $chargeId,
        public ?string $idempotencyKey = null,
    ) {
        foreach (['actorId' => $actorId, 'invoiceId' => $invoiceId, 'chargeId' => $chargeId] as $name => $value) {
            if (! Str::isUuid($value)) {
                throw new InvalidArgumentException("RemoveInvoiceLine::\${$name} must be a UUID (IC §3).");
            }
        }
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
            action: 'billing.invoice_line_removed',
            actorId: $this->actorId,
            targetType: 'invoice',
            targetId: $this->invoiceId,
            metadata: ['charge_id' => $this->chargeId],
        );
    }
}
