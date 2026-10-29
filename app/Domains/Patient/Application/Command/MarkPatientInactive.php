<?php

declare(strict_types=1);

namespace App\Domains\Patient\Application\Command;

use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Move a patient identity to INACTIVE (10-patient-domain.md §44, §130;
 * ADR-116). Audited (§103).
 */
final readonly class MarkPatientInactive implements Auditable, AuthorizesAgainst
{
    public function __construct(
        public string $patientId,
        public string $actorId,
        public ?string $reason = null,
    ) {
        if (! Str::isUuid($patientId)) {
            throw new InvalidArgumentException('MarkPatientInactive::$patientId must be a UUID (IC §3).');
        }

        if (! Str::isUuid($actorId)) {
            throw new InvalidArgumentException('MarkPatientInactive::$actorId must be a UUID (IC §3).');
        }
    }

    public function policyAbility(): string
    {
        return 'deactivate';
    }

    public function subjectId(): string
    {
        return $this->patientId;
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'patient.deactivated',
            actorId: $this->actorId,
            targetType: 'patient',
            targetId: $this->patientId,
            reason: $this->reason,
        );
    }
}
