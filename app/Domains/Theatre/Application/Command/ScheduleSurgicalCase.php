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
 * Set a surgical case's scheduled time (17-theatre-domain.md §219; ADR-150 D-D).
 */
final readonly class ScheduleSurgicalCase implements Auditable, AuthorizesAgainst
{
    public function __construct(
        public string $caseId,
        public string $actorId,
        public string $scheduledFor,
        public ?string $idempotencyKey = null,
    ) {
        if (! Str::isUuid($caseId)) {
            throw new InvalidArgumentException('ScheduleSurgicalCase::$caseId must be a UUID (IC §3).');
        }

        if (! Str::isUuid($actorId)) {
            throw new InvalidArgumentException('ScheduleSurgicalCase::$actorId must be a UUID (IC §3).');
        }

        try {
            CarbonImmutable::parse($scheduledFor);
        } catch (Throwable) {
            throw new InvalidArgumentException('ScheduleSurgicalCase::$scheduledFor must be an ISO-8601 datetime.');
        }
    }

    public function policyAbility(): string
    {
        return 'schedule';
    }

    public function subjectId(): string
    {
        return $this->caseId;
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'theatre.case_scheduled',
            actorId: $this->actorId,
            targetType: 'surgical_case',
            targetId: $this->caseId,
            metadata: [
                'scheduled_for' => $this->scheduledFor,
            ],
        );
    }
}
