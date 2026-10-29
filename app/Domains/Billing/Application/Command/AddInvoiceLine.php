<?php

declare(strict_types=1);

namespace App\Domains\Billing\Application\Command;

use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Add one POSTED charge to a DRAFT invoice (ADR-190 D-C). Per-subject `manage`.
 * The charge must be POSTED, on the invoice's Financial Account, and not already
 * claimed by a live line.
 */
final readonly class AddInvoiceLine implements Auditable, AuthorizesAgainst
{
    public function __construct(
        public string $actorId,
        public string $invoiceId,
        public string $chargeId,
        public ?string $idempotencyKey = null,
    ) {
        foreach (['actorId' => $actorId, 'invoiceId' => $invoiceId, 'chargeId' => $chargeId] as $name => $value) {
            if (! Str::isUuid($value)) {
                throw new InvalidArgumentException("AddInvoiceLine::\${$name} must be a UUID (IC §3).");
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
            action: 'billing.invoice_line_added',
            actorId: $this->actorId,
            targetType: 'invoice',
            targetId: $this->invoiceId,
            metadata: ['charge_id' => $this->chargeId],
        );
    }
}
