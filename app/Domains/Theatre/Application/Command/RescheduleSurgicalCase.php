<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Application\Command;

use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * Change a surgical case's scheduled time (17-theatre-domain.md §103, §219;
 * ADR-150 D-D). `$reason` is preserved on the audit row only (ADR-136
 * deny-list; §103's own "preserve reason" requirement — the
 * `CorrectOccupancy` precedent), never on the domain event.
 */
final readonly class RescheduleSurgicalCase implements Auditable, AuthorizesAgainst
{
    public function __construct(
        public string $caseId,
        public string $actorId,
        public string $newScheduledFor,
        public ?string $reason = null,
        public ?string $idempotencyKey = null,
    ) {
        if (! Str::isUuid($caseId)) {
            throw new InvalidArgumentException('RescheduleSurgicalCase::$caseId must be a UUID (IC §3).');
        }

        if (! Str::isUuid($actorId)) {
            throw new InvalidArgumentException('RescheduleSurgicalCase::$actorId must be a UUID (IC §3).');
        }

        try {
            CarbonImmutable::parse($newScheduledFor);
        } catch (Throwable) {
            throw new InvalidArgumentException('RescheduleSurgicalCase::$newScheduledFor must be an ISO-8601 datetime.');
        }
    }

    public function policyAbility(): string
    {
        return 'reschedule';
    }

    public function subjectId(): string
    {
        return $this->caseId;
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'theatre.case_rescheduled',
            actorId: $this->actorId,
            targetType: 'surgical_case',
            targetId: $this->caseId,
            metadata: [
                'new_scheduled_for' => $this->newScheduledFor,
            ],
            reason: ($this->reason !== null && trim($this->reason) !== '') ? trim($this->reason) : null,
        );
    }
}
