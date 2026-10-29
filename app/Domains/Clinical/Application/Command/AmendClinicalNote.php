<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Application\Command;

use App\Domains\Clinical\Domain\Enum\ClinicalNoteAmendmentType;
use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * Append a correction to a SIGNED clinical note — `SIGNED → SIGNED` with one
 * new immutable `clin_note_amendments` row (12-clinical-domain.md §14, §15,
 * Invariant 6; ADR-134). The signed body is NEVER rewritten. NOT
 * encounter-state-gated — a signed record is corrected whatever the encounter's
 * later state (Invariant 19; ADR-135).
 *
 * `$reason` is REQUIRED (ADR-134) and is CLINICAL data — it is stored on the
 * amendment row, never duplicated to the audit entry (whose `reason` stays
 * null; the audit `metadata` is `[]`, coded values live on the event).
 * `$amendmentType` is one of the `ClinicalNoteAmendmentType` coded values.
 * `$occurredAt` is the correction's clinical time (ISO-8601, optional — server
 * `now()` by default); never in the future.
 *
 * Per-subject command (`subjectId() = $clinicalNoteId`): the bus loads the note
 * and calls `ClinicalNotePolicy::amend` (which checks `clinical.notes.amend` at
 * the note's facility — the privileged-correction key) before the handler runs.
 */
final readonly class AmendClinicalNote implements Auditable, AuthorizesAgainst
{
    public function __construct(
        public string $clinicalNoteId,
        public string $actorId,
        public string $amendmentType,
        public string $body,
        public string $reason,
        public ?string $occurredAt = null,
    ) {
        if (! Str::isUuid($clinicalNoteId)) {
            throw new InvalidArgumentException('AmendClinicalNote::$clinicalNoteId must be a UUID (IC §3).');
        }

        if (! Str::isUuid($actorId)) {
            throw new InvalidArgumentException('AmendClinicalNote::$actorId must be a UUID (IC §3).');
        }

        if (ClinicalNoteAmendmentType::tryFrom($amendmentType) === null) {
            throw new InvalidArgumentException('AmendClinicalNote::$amendmentType must be a ClinicalNoteAmendmentType value.');
        }

        if (trim($body) === '') {
            throw new InvalidArgumentException('AmendClinicalNote::$body must not be blank.');
        }

        if (trim($reason) === '') {
            throw new InvalidArgumentException('AmendClinicalNote::$reason is required for an amendment.');
        }

        if ($occurredAt !== null) {
            try {
                $occurred = CarbonImmutable::parse($occurredAt);
            } catch (Throwable) {
                throw new InvalidArgumentException('AmendClinicalNote::$occurredAt must be an ISO-8601 datetime or null.');
            }

            if ($occurred->greaterThan(CarbonImmutable::now())) {
                throw new InvalidArgumentException('AmendClinicalNote::$occurredAt must not be in the future (12 §96, ADR-132).');
            }
        }
    }

    public function amendmentTypeEnum(): ClinicalNoteAmendmentType
    {
        return ClinicalNoteAmendmentType::from($this->amendmentType);
    }

    public function policyAbility(): string
    {
        return 'amend';
    }

    public function subjectId(): string
    {
        return $this->clinicalNoteId; // per-subject — the bus loads this note (IC §5.1)
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'clinical.note_amended',
            actorId: $this->actorId,
            targetType: 'clinical_note',
            targetId: $this->clinicalNoteId,
        );
    }
}
