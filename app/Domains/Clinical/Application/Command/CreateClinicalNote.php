<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Application\Command;

use App\Domains\Clinical\Domain\Model\ClinicalNote;
use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * Originate a clinical note as a `DRAFT` (12-clinical-domain.md §10–§12;
 * ADR-131, ADR-132, ADR-134, ADR-135).
 *
 * `$clinicalNoteId` is client-minted (a UUIDv7) so this `Auditable` command
 * names its own target before the row exists (the `CreateEncounter` /
 * `RegisterPatient` precedent) and repeat delivery is idempotent.
 * `$actorId` is the authenticated principal — the clinical author (set at the
 * HTTP boundary in PHASE 16). `tenant_id` is resolved from `TenantContext`;
 * `facility_id` is taken from the ENCOUNTER context by the handler (ADR-135),
 * never from this command (Invariant 12).
 *
 * `$occurredAt` is the CLINICAL event time (ISO-8601, optional — defaults to
 * server `now()`); it may be earlier than the record time but never in the
 * future. When it is more than {@see BACKDATE_REASON_THRESHOLD_HOURS} hours
 * earlier than now, `$reason` is REQUIRED (ADR-132) — the justification is
 * written to the AUDIT row only, never to a `clin_notes` column.
 *
 * `$authoredContext` is `CLINICIAN` (default — the C.1 behaviour every existing
 * caller keeps) or `NURSING` (ADR-144, increment C.8 — a nursing note is a
 * `ClinicalNote`, not a separate aggregate). Set-membership-validated against
 * `ClinicalNote::AUTHORED_CONTEXTS`; it is documentation metadata, **not** an
 * authorization input (no `IdentityApi` / role check — the ADR-138 D8 / ADR-141
 * D-I pattern) and never branches lifecycle, authorization or handler logic.
 *
 * Class-level (create) authorization: the bus checks `ClinicalNotePolicy::create`
 * (`subjectId() === null`). The handler adds the ADR-133 per-encounter-facility
 * refinement.
 */
final readonly class CreateClinicalNote implements Auditable, AuthorizesAgainst
{
    /** ADR-132 — beyond this, backdated documentation must carry a `reason`. */
    public const BACKDATE_REASON_THRESHOLD_HOURS = 24;

    public function __construct(
        public string $clinicalNoteId,
        public string $actorId,
        public string $patientId,
        public string $encounterId,
        public string $noteType,
        public ?string $title,
        public string $body,
        public string $authoredContext = ClinicalNote::AUTHORED_CONTEXT_CLINICIAN,
        public ?string $occurredAt = null,
        public ?string $reason = null,
        public ?string $idempotencyKey = null,
    ) {
        if (! Str::isUuid($clinicalNoteId)) {
            throw new InvalidArgumentException('CreateClinicalNote::$clinicalNoteId must be a UUID (IC §3).');
        }

        if (! Str::isUuid($actorId)) {
            throw new InvalidArgumentException('CreateClinicalNote::$actorId must be a UUID (IC §3).');
        }

        if (! Str::isUuid($patientId)) {
            throw new InvalidArgumentException('CreateClinicalNote::$patientId must be a UUID (IC §3).');
        }

        if (! Str::isUuid($encounterId)) {
            throw new InvalidArgumentException('CreateClinicalNote::$encounterId must be a UUID (IC §3).');
        }

        if (! in_array($authoredContext, ClinicalNote::AUTHORED_CONTEXTS, true)) {
            throw new InvalidArgumentException('CreateClinicalNote::$authoredContext must be CLINICIAN or NURSING.');
        }

        if (trim($noteType) === '') {
            throw new InvalidArgumentException('CreateClinicalNote::$noteType must not be blank (12 §10).');
        }

        if (trim($body) === '') {
            throw new InvalidArgumentException('CreateClinicalNote::$body must not be blank.');
        }

        if ($title !== null && trim($title) === '') {
            throw new InvalidArgumentException('CreateClinicalNote::$title must be a non-blank string or null.');
        }

        if ($occurredAt !== null) {
            try {
                $occurred = CarbonImmutable::parse($occurredAt);
            } catch (Throwable) {
                throw new InvalidArgumentException('CreateClinicalNote::$occurredAt must be an ISO-8601 datetime or null.');
            }

            $now = CarbonImmutable::now();

            if ($occurred->greaterThan($now)) {
                throw new InvalidArgumentException('CreateClinicalNote::$occurredAt must not be in the future (12 §96, ADR-132).');
            }

            if ($occurred->lessThan($now->subHours(self::BACKDATE_REASON_THRESHOLD_HOURS))
                && ($reason === null || trim($reason) === '')) {
                throw new InvalidArgumentException(sprintf(
                    'CreateClinicalNote::$reason is required when $occurredAt is more than %d hours in the past.',
                    self::BACKDATE_REASON_THRESHOLD_HOURS,
                ));
            }
        }
    }

    public function policyAbility(): string
    {
        return 'create';
    }

    public function subjectId(): ?string
    {
        return null; // create — class-level check (IC §5.1)
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'clinical.note_created',
            actorId: $this->actorId,
            targetType: 'clinical_note',
            targetId: $this->clinicalNoteId,
            reason: ($this->reason !== null && trim($this->reason) !== '') ? trim($this->reason) : null,
        );
    }
}
