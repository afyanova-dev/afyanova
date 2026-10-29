<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Application\Command;

use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Record a specimen removed during a surgical case (17-theatre-domain.md §72–§74,
 * §176; ADR-164 D-C). `$specimenId` is client-minted (UUIDv7). `facility_id` /
 * `patient_id` / `encounter_id` and the per-case `sequence` are set by the
 * handler from the case. `$specimenType` / `$bodySite` / `$laterality` /
 * `$destinationLaboratory` are open-coded (normalised uppercase in the handler);
 * `$description` is ROW-ONLY (ADR-136 deny-list). `$collectedByUserId` defaults
 * to the actor.
 */
final readonly class RecordSurgicalSpecimen implements Auditable, AuthorizesAgainst
{
    public function __construct(
        public string $specimenId,
        public string $caseId,
        public string $actorId,
        public string $specimenType,
        public ?string $bodySite = null,
        public ?string $laterality = null,
        public ?string $description = null,
        public ?string $collectedByUserId = null,
        public ?string $destinationLaboratory = null,
        public ?string $idempotencyKey = null,
    ) {
        foreach (['specimenId' => $specimenId, 'caseId' => $caseId, 'actorId' => $actorId] as $name => $value) {
            if (! Str::isUuid($value)) {
                throw new InvalidArgumentException("RecordSurgicalSpecimen::\${$name} must be a UUID (IC §3).");
            }
        }

        if (trim($specimenType) === '') {
            throw new InvalidArgumentException('RecordSurgicalSpecimen::$specimenType must not be blank.');
        }

        if ($collectedByUserId !== null && ! Str::isUuid($collectedByUserId)) {
            throw new InvalidArgumentException('RecordSurgicalSpecimen::$collectedByUserId must be a UUID or null (IC §3).');
        }

        if ($description !== null && trim($description) === '') {
            throw new InvalidArgumentException('RecordSurgicalSpecimen::$description must be a non-blank string or null.');
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
            action: 'theatre.surgical_specimen_collected',
            actorId: $this->actorId,
            targetType: 'surgical_specimen',
            targetId: $this->specimenId,
            metadata: [
                'case_id' => $this->caseId,
                'specimen_type' => strtoupper(trim($this->specimenType)),
            ],
        );
    }
}
