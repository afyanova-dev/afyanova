<?php

declare(strict_types=1);

namespace App\Domains\CareCoordination\Application\Command;

use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * Schedule a follow-up as PENDING (26-care-coordination-domain.md §6, §9;
 * ADR-154 D-E). `$followUpId` is client-minted (UUIDv7 — the CreateReferral
 * precedent). `facility_id` / `patient_id` are cross-checked against and
 * copied from the source encounter by the handler, never trusted from this
 * command. `$reason` / `$instructions` are ROW-ONLY (ADR-136 deny-list).
 */
final readonly class ScheduleFollowUp implements Auditable, AuthorizesAgainst
{
    public function __construct(
        public string $followUpId,
        public string $sourceEncounterId,
        public string $patientId,
        public string $actorId,
        public string $dueFrom,
        public string $dueTo,
        public ?string $reason = null,
        public ?string $instructions = null,
        public ?string $occurredAt = null,
        public ?string $idempotencyKey = null,
    ) {
        foreach (['followUpId' => $followUpId, 'sourceEncounterId' => $sourceEncounterId, 'patientId' => $patientId, 'actorId' => $actorId] as $name => $value) {
            if (! Str::isUuid($value)) {
                throw new InvalidArgumentException("ScheduleFollowUp::\${$name} must be a UUID (IC §3).");
            }
        }

        try {
            $from = CarbonImmutable::parse($dueFrom);
            $to = CarbonImmutable::parse($dueTo);
        } catch (Throwable) {
            throw new InvalidArgumentException('ScheduleFollowUp::$dueFrom / $dueTo must be ISO-8601 dates.');
        }

        if ($to->lessThan($from)) {
            throw new InvalidArgumentException('ScheduleFollowUp::$dueTo must not be before $dueFrom.');
        }

        if ($reason !== null && trim($reason) === '') {
            throw new InvalidArgumentException('ScheduleFollowUp::$reason must be a non-blank string or null.');
        }

        if ($occurredAt !== null) {
            try {
                CarbonImmutable::parse($occurredAt);
            } catch (Throwable) {
                throw new InvalidArgumentException('ScheduleFollowUp::$occurredAt must be an ISO-8601 datetime or null.');
            }
        }
    }

    public function policyAbility(): string
    {
        return 'create';
    }

    public function subjectId(): ?string
    {
        return null;
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'care_coordination.followup_scheduled',
            actorId: $this->actorId,
            targetType: 'follow_up',
            targetId: $this->followUpId,
            metadata: [
                'patient_id' => $this->patientId,
                'source_encounter_id' => $this->sourceEncounterId,
            ],
        );
    }
}
