<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Application\Command;

use App\Domains\Clinical\Domain\ClinicalNoteRepository;
use App\Domains\Clinical\Domain\Exception\ClinicalNoteContextMismatch;
use App\Domains\Clinical\Domain\Exception\EncounterNotEligibleForClinicalRecord;
use App\Domains\Clinical\Domain\Exception\PatientNotEligibleForClinicalRecord;
use App\Domains\Clinical\Domain\Model\ClinicalNote;
use App\Domains\Clinical\Domain\ValueObject\ClinicalNoteType;
use App\Domains\Encounter\Contracts\Dto\EncounterContext;
use App\Domains\Encounter\Contracts\EncounterApi;
use App\Domains\Patient\Contracts\Dto\PatientSummary;
use App\Domains\Patient\Contracts\PatientApi;
use App\Models\User;
use App\Shared\Authorization\ScopeChecker;
use App\Shared\Domain\NotFound;
use App\Shared\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

/**
 * Orchestrates `CreateClinicalNote` (IC §5; ADR-132, ADR-133, ADR-135).
 *
 * Flow (ADR-135):
 *   1. coarse `clinical.notes.create` — already enforced by the bus
 *      (`ClinicalNotePolicy::create`).
 *   2. `PatientApi::getPatientSummary` — a real cross-domain port call
 *      (`NotFound` for an unknown / cross-tenant patient; `AuthorizationException`
 *      if the caller lacks `patient.patients.read`). Patient-state gate:
 *      `ACTIVE` only, `isProvisional` permitted (ADR-119); MERGED / DECEASED /
 *      INACTIVE → `PatientNotEligibleForClinicalRecord` (409).
 *   3. `EncounterApi::getEncounterContext` — the E.13 port (delegates to the
 *      E.12 `GetEncounter` query, so its own `#[ProtectedQuery]` +
 *      facility-refinement applies; `NotFound` for absent / cross-tenant /
 *      out-of-scope encounter).
 *   4. ADR-133 per-encounter-facility refinement — the authenticated actor must
 *      hold `clinical.notes.create` at THE ENCOUNTER's facility
 *      (`ScopeChecker::effectivePermissions`); otherwise `NotFound` (§88 /
 *      Invariant 24/25 — facility selection does not grant clinical-record
 *      access; not an authorization disclosure).
 *   5. patient / encounter consistency — the named `patientId` must be the
 *      encounter's patient (`ClinicalNoteContextMismatch`, 409).
 *   6. encounter-state gate — origination requires status ∈ {ARRIVED, ACTIVE,
 *      COMPLETED} (`EncounterNotEligibleForClinicalRecord`, 409).
 *   7. `facility_id` is copied from the encounter context (ADR-135); create the
 *      `DRAFT`; `occurred_at` = caller value or `now()`, `recorded_at` = `now()`;
 *      `authored_context` = the command value (`CLINICIAN` default / `NURSING` —
 *      ADR-144; validated on the command and re-guarded in the aggregate).
 *   8. versioned save + `ClinicalNoteCreated` to the outbox (one txn). The
 *      `clinical.note_created` audit row (with the backdate `reason`, when
 *      present) is written by the bus `WriteAuditRow` step.
 *
 * `ClinicalNoteRepository` is injected both because the handler needs it and
 * because `AuthorizeCommand` (IC §5.1) reflects it to resolve the `ClinicalNote`
 * aggregate for the class-level `create` check. NO direct access to `pat_*` /
 * `enc_*` tables or the Patient / Encounter aggregates (AGENTS §8; IC §2.1).
 */
final class CreateClinicalNoteHandler
{
    public function __construct(
        private readonly ClinicalNoteRepository $notes,
        private readonly PatientApi $patients,
        private readonly EncounterApi $encounters,
        private readonly ScopeChecker $scope,
        private readonly TenantContext $context,
    ) {}

    public function __invoke(CreateClinicalNote $command): CreateClinicalNoteResult
    {
        $this->assertPatientEligible($this->patients->getPatientSummary($command->patientId));

        $encounter = $this->encounters->getEncounterContext($command->encounterId);

        $this->assertActorScopedToEncounterFacility($encounter, $command->clinicalNoteId);

        if ($encounter->patientId !== $command->patientId) {
            throw ClinicalNoteContextMismatch::patientEncounter(
                $command->patientId,
                $command->encounterId,
                $encounter->patientId,
            );
        }

        $this->assertEncounterEligible($encounter);

        $now = CarbonImmutable::now();
        $occurredAt = $command->occurredAt !== null ? CarbonImmutable::parse($command->occurredAt) : $now;

        $note = ClinicalNote::create(
            id: $command->clinicalNoteId,
            tenantId: $this->context->tenantId(),
            facilityId: $encounter->facilityId,
            patientId: $command->patientId,
            encounterId: $command->encounterId,
            authorUserId: $command->actorId,
            noteType: ClinicalNoteType::fromString($command->noteType)->value,
            title: $command->title,
            body: $command->body,
            occurredAt: $occurredAt,
            recordedAt: $now,
            authoredContext: $command->authoredContext,
        );

        $this->notes->save($note); // versioned INSERT + ClinicalNoteCreated -> outbox (one txn)

        return new CreateClinicalNoteResult((string) $note->id, $note->status->value);
    }

    /**
     * A clinical record is originated only for an `ACTIVE` patient identity
     * (ADR-135). `PatientSummary::$status` is the coded ADR-116 lifecycle value;
     * `isProvisional` `ACTIVE` is permitted (ADR-119). `getPatientSummary` has
     * already thrown `NotFound` for a patient absent from the tenant MPI.
     */
    private function assertPatientEligible(PatientSummary $patient): void
    {
        if ($patient->status === 'MERGED') {
            throw PatientNotEligibleForClinicalRecord::merged($patient->patientId);
        }

        if ($patient->status === 'DECEASED') {
            throw PatientNotEligibleForClinicalRecord::deceased($patient->patientId);
        }

        if ($patient->status === 'INACTIVE') {
            throw PatientNotEligibleForClinicalRecord::inactive($patient->patientId);
        }
    }

    /**
     * ADR-133 refinement — the authenticated author must hold
     * `clinical.notes.create` at the encounter's own facility, not merely at
     * their selected facility context. An out-of-scope encounter is
     * indistinguishable from an absent one (`NotFound`), never an authorization
     * disclosure (the E.12 R-1 pattern; the `Auth`-resolved actor cannot be
     * spoofed).
     */
    private function assertActorScopedToEncounterFacility(EncounterContext $encounter, string $clinicalNoteId): void
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            throw new RuntimeException('CreateClinicalNote dispatched without an authenticated user (IC §5).');
        }

        if (! in_array(
            'clinical.notes.create',
            $this->scope->effectivePermissions($user, $encounter->facilityId),
            true,
        )) {
            throw NotFound::aggregate(ClinicalNote::class, $clinicalNoteId);
        }
    }

    /**
     * Encounter-state gate (ADR-135) — ONLY origination is gated. A clinical
     * record is created only when the encounter is `ARRIVED`, `ACTIVE` or
     * `COMPLETED`.
     */
    private function assertEncounterEligible(EncounterContext $encounter): void
    {
        if (! in_array($encounter->status, ['ARRIVED', 'ACTIVE', 'COMPLETED'], true)) {
            throw EncounterNotEligibleForClinicalRecord::status($encounter->encounterId, $encounter->status);
        }
    }
}
