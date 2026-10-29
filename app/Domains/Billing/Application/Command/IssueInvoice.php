<?php

declare(strict_types=1);

namespace App\Domains\Billing\Application\Command;

use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Issue a DRAFT invoice — DRAFT → ISSUED (ADR-190 D-C). Per-subject `manage`.
 * Requires ≥ 1 active line (D8); refuses if any active line's charge is now
 * REVERSED (`InvoiceHasReversedLine` — INV-4); mints `INV-%08d` (INV-6); freezes
 * `net`; records `InvoiceIssued`.
 */
final readonly class IssueInvoice implements Auditable, AuthorizesAgainst
{
    public function __construct(
        public string $actorId,
        public string $invoiceId,
        public ?string $idempotencyKey = null,
    ) {
        if (! Str::isUuid($actorId) || ! Str::isUuid($invoiceId)) {
            throw new InvalidArgumentException('IssueInvoice ids must be UUIDs (IC §3).');
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
            action: 'billing.invoice_issued',
            actorId: $this->actorId,
            targetType: 'invoice',
            targetId: $this->invoiceId,
        );
    }
}
