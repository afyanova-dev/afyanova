<?php

declare(strict_types=1);

namespace App\Domains\Radiology\Application\Command;

use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Begin acquisition of an imaging study (SCHEDULED | READY → IN_PROGRESS)
 * (15-radiology-domain.md §30, §53–§54, §104; ADR-166 D-F).
 *
 * `$patientVerified` MUST be true — the §53 / §54 wrong-patient safety gate
 * (Invariant 2). The handler records `verified_by_user_id` / `verified_at`
 * (defaulting to the actor) alongside `performed_by_user_id` / `started_at`.
 */
final readonly class StartImagingStudy implements Auditable, AuthorizesAgainst
{
    public function __construct(
        public string $studyId,
        public string $actorId,
        public bool $patientVerified,
        public ?string $performedByUserId = null,
        public ?string $verifiedByUserId = null,
        public ?string $idempotencyKey = null,
    ) {
        if (! Str::isUuid($studyId)) {
            throw new InvalidArgumentException('StartImagingStudy::$studyId must be a UUID (IC §3).');
        }

        if (! Str::isUuid($actorId)) {
            throw new InvalidArgumentException('StartImagingStudy::$actorId must be a UUID (IC §3).');
        }

        if ($patientVerified !== true) {
            throw new InvalidArgumentException('StartImagingStudy::$patientVerified must be true — the §53/§54 patient-verification gate (Invariant 2).');
        }

        foreach (['performedByUserId' => $performedByUserId, 'verifiedByUserId' => $verifiedByUserId] as $name => $value) {
            if ($value !== null && ! Str::isUuid($value)) {
                throw new InvalidArgumentException("StartImagingStudy::\${$name} must be a UUID or null (IC §3).");
            }
        }
    }

    public function policyAbility(): string
    {
        return 'perform';
    }

    public function subjectId(): string
    {
        return $this->studyId;
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'radiology.study_started',
            actorId: $this->actorId,
            targetType: 'imaging_study',
            targetId: $this->studyId,
            metadata: [
                'patient_verified' => true,
                'verified_by_user_id' => $this->verifiedByUserId ?? $this->actorId,
            ],
        );
    }
}
