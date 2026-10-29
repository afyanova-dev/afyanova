<?php

declare(strict_types=1);

namespace App\Domains\Billing\Application\Command;

use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Attach (or clear) an Insurance coverage on a DRAFT invoice and record the
 * ESTIMATED payer/patient split (20-billing-and-payments-domain.md §34;
 * ADR-201 D-C). Per-subject `manage` (`billing.invoices.manage`).
 *
 * `memberCoverageId === null` reverts the invoice to SELF_PAY. Otherwise the
 * handler reads the patient's coverages from `InsuranceApi` (the acting user
 * also needs `insurance.access`), evaluates each line, and sums the split.
 * DRAFT only — the split freezes at ISSUE alongside `net`.
 */
final readonly class SetInvoiceCoverage implements Auditable, AuthorizesAgainst
{
    public ?string $onDate;

    public function __construct(
        public string $invoiceId,
        public string $actorId,
        public ?string $memberCoverageId = null,
        ?string $onDate = null,
    ) {
        if (! Str::isUuid($invoiceId) || ! Str::isUuid($actorId)) {
            throw new InvalidArgumentException('SetInvoiceCoverage ids must be UUIDs (IC §3).');
        }
        if ($memberCoverageId !== null && ! Str::isUuid($memberCoverageId)) {
            throw new InvalidArgumentException('SetInvoiceCoverage::$memberCoverageId must be a UUID or null (IC §3).');
        }

        $this->onDate = $onDate !== null && trim($onDate) !== '' ? trim($onDate) : null;
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
            action: $this->memberCoverageId === null ? 'billing.invoice_coverage_cleared' : 'billing.invoice_coverage_set',
            actorId: $this->actorId,
            targetType: 'invoice',
            targetId: $this->invoiceId,
            metadata: $this->memberCoverageId !== null ? ['member_coverage_id' => $this->memberCoverageId] : [],
        );
    }
}
