<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Application\Command;

use App\Domains\Clinical\Domain\Model\VitalSignsObservation;
use App\Domains\Clinical\Domain\ValueObject\VitalSigns;
use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * Correct a recorded vitals observation by a SUPERSEDING observation
 * (12-clinical-domain.md §94; ADR-138 D7).
 *
 * A recorded observation's measurement values are IMMUTABLE. To correct them a
 * NEW `VitalSignsObservation` is recorded carrying the corrected values,
 * `supersedes_observation_id` pointing at the original, and a mandatory
 * `reason` (the correction rationale — CLINICAL data stored on the new row,
 * never on the audit or the event). The original row receives a ONE-TIME
 * `superseded_at` + `superseded_by_observation_id` stamp; its values are
 * untouched. There is NO in-place edit and NO void / cancel command in C.2.
 *
 * `$newObservationId` is client-minted (a UUIDv7) so this `Auditable` command
 * names the created row before it exists. `$supersedesObservationId` is the
 * observation being corrected — a per-subject target the bus loads for the
 * `correct` policy check (which requires `clinical.vitals.record` at the
 * observation's facility — the SAME key as recording; ADR-138 D16).
 *
 * NOT encounter-state-gated and makes NO cross-domain call: the correction
 * restates an already-originated clinical record, whose patient / encounter /
 * facility context is copied from the original (the ADR-135 post-origination
 * principle; the C.1 `AmendClinicalNote` precedent).
 *
 * `$occurredAt` (optional) re-states the clinical time of the set; when null it
 * is inherited from the original. Never in the future. There is no separate
 * > 24 h backdate check — the mandatory `$reason` is the justification.
 */
final readonly class CorrectVitalSignsObservation implements Auditable, AuthorizesAgainst
{
    public function __construct(
        public string $newObservationId,
        public string $supersedesObservationId,
        public string $actorId,
        public VitalSigns $measurements,
        public string $reason,
        public string $authoredContext = VitalSignsObservation::AUTHORED_CONTEXT_CLINICIAN,
        public ?string $occurredAt = null,
    ) {
        if (! Str::isUuid($newObservationId)) {
            throw new InvalidArgumentException('CorrectVitalSignsObservation::$newObservationId must be a UUID (IC §3).');
        }

        if (! Str::isUuid($supersedesObservationId)) {
            throw new InvalidArgumentException('CorrectVitalSignsObservation::$supersedesObservationId must be a UUID (IC §3).');
        }

        if ($newObservationId === $supersedesObservationId) {
            throw new InvalidArgumentException('CorrectVitalSignsObservation: an observation cannot supersede itself.');
        }

        if (! Str::isUuid($actorId)) {
            throw new InvalidArgumentException('CorrectVitalSignsObservation::$actorId must be a UUID (IC §3).');
        }

        if (trim($reason) === '') {
            throw new InvalidArgumentException('CorrectVitalSignsObservation::$reason is required.');
        }

        if (! in_array($authoredContext, VitalSignsObservation::AUTHORED_CONTEXTS, true)) {
            throw new InvalidArgumentException('CorrectVitalSignsObservation::$authoredContext must be CLINICIAN or NURSING.');
        }

        if ($occurredAt !== null) {
            try {
                $occurred = CarbonImmutable::parse($occurredAt);
            } catch (Throwable) {
                throw new InvalidArgumentException('CorrectVitalSignsObservation::$occurredAt must be an ISO-8601 datetime or null.');
            }

            if ($occurred->greaterThan(CarbonImmutable::now())) {
                throw new InvalidArgumentException('CorrectVitalSignsObservation::$occurredAt must not be in the future (12 §96, ADR-132).');
            }
        }
    }

    public function policyAbility(): string
    {
        return 'correct';
    }

    public function subjectId(): string
    {
        return $this->supersedesObservationId; // per-subject — the bus loads the observation being corrected (IC §5.1)
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'clinical.vitals.corrected',
            actorId: $this->actorId,
            targetType: 'vital_signs_observation',
            targetId: $this->newObservationId,
        );
    }
}
