<?php

declare(strict_types=1);

namespace App\Domains\Billing\Application\Command;

use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Re-attempt the charge for one PENDING captured billable event — the manual
 * revenue-leakage worklist (20-billing-and-payments-domain.md §130 / §131;
 * ADR-189 D-G, sponsor ruling C1 — no automatic reconciliation sweep).
 *
 * Per-subject `manage` (NEW key `billing.charges.manage`) at the event's
 * facility. No-op if the event is no longer PENDING.
 */
final readonly class RetryBillableEvent implements Auditable, AuthorizesAgainst
{
    public function __construct(
        public string $actorId,
        public string $billableEventId,
        public ?string $idempotencyKey = null,
    ) {
        if (! Str::isUuid($actorId) || ! Str::isUuid($billableEventId)) {
            throw new InvalidArgumentException('RetryBillableEvent ids must be UUIDs (IC §3).');
        }
    }

    public function policyAbility(): string
    {
        return 'manage';
    }

    public function subjectId(): string
    {
        return $this->billableEventId;
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'billing.billable_event_retried',
            actorId: $this->actorId,
            targetType: 'billable_event',
            targetId: $this->billableEventId,
        );
    }
}
