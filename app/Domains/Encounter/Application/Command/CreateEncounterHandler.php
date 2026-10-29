<?php

declare(strict_types=1);

namespace App\Domains\Encounter\Application\Command;

use App\Domains\Encounter\Domain\EncounterRepository;
use App\Domains\Encounter\Domain\Exception\ActiveEncounterExists;
use App\Domains\Encounter\Domain\Exception\EncounterTypeNotAvailable;
use App\Domains\Encounter\Domain\Exception\PatientNotEligibleForEncounter;
use App\Domains\Encounter\Domain\Model\Encounter;
use App\Domains\Encounter\Domain\Service\EncounterLifecycle;
use App\Domains\Encounter\Infrastructure\Sequence\EncounterNumberSequence;
use App\Domains\Patient\Contracts\Dto\PatientSummary;
use App\Domains\Patient\Contracts\PatientApi;
use App\Shared\Capability\FacilityCapabilities;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Orchestrates `CreateEncounter` (IC §5; ADR-130 CREATEENCOUNTER BOUNDARY).
 *
 * Order: resolve tenant + facility from context -> capability-gate the type
 * (ADR-120) -> validate the patient through PatientApi (exists + ACTIVE, MERGED
 * / DECEASED / INACTIVE rejected, provisional permitted — ADR-119) -> resolve
 * the initial status via EncounterLifecycle -> allocate ENC-%08d (ADR-115) ->
 * open + persist the aggregate (EncounterCreated to the outbox in the same
 * transaction). The partial unique index `enc_one_active_per_patient_facility`
 * is the final duplicate guard (ADR-121); its violation becomes
 * `ActiveEncounterExists` (IC §9.1). The `encounter.created` audit row is
 * written by the bus's WriteAuditRow step (the command is Auditable).
 *
 * `EncounterRepository` is a constructor dependency both because the handler
 * needs it and because `AuthorizeCommand` (IC §5.1) reflects it to resolve the
 * `Encounter` aggregate for the create-time (class-level) `create` policy check.
 */
final class CreateEncounterHandler
{
    public function __construct(
        private readonly EncounterRepository $encounters,
        private readonly EncounterNumberSequence $numbers,
        private readonly EncounterLifecycle $lifecycle,
        private readonly PatientApi $patients,
        private readonly FacilityCapabilities $capabilities,
        private readonly TenantContext $context,
    ) {}

    public function __invoke(CreateEncounter $command): CreateEncounterResult
    {
        $tenantId = $this->context->tenantId();
        $facilityId = $this->context->requireFacilityId();

        $requiredCapability = $command->encounterType->requiredCapability();
        if ($requiredCapability !== null && ! $this->capabilities->has($facilityId, $requiredCapability)) {
            throw EncounterTypeNotAvailable::for($command->encounterType, $requiredCapability, $facilityId);
        }

        $this->assertPatientEligible($this->patients->getPatientSummary($command->patientId));

        $status = $this->lifecycle->initialStatus($command->planned);
        $encounterNumber = $this->numbers->next($tenantId);

        $encounter = Encounter::open(
            id: $command->encounterId,
            tenantId: $tenantId,
            facilityId: $facilityId,
            patientId: $command->patientId,
            encounterNumber: $encounterNumber,
            type: $command->encounterType,
            status: $status,
            departmentId: $command->departmentId,
            unitId: $command->unitId,
            createdByUserId: $command->actorId,
            source: $command->source ?? CreateEncounter::DEFAULT_SOURCE,
        );

        try {
            $this->encounters->save($encounter); // versioned INSERT + EncounterCreated -> outbox (one txn)
        } catch (UniqueConstraintViolationException $e) {
            if (str_contains($e->getMessage(), 'enc_one_active_per_patient_facility')) {
                throw ActiveEncounterExists::forPatientAtFacility($command->patientId, $facilityId);
            }

            throw $e;
        }

        return new CreateEncounterResult((string) $encounter->id, $encounterNumber);
    }

    /**
     * A new attendance is created only for an ACTIVE patient identity
     * (ADR-130 E). `PatientSummary::$status` is the coded ADR-116 lifecycle
     * value; a MERGED / DECEASED / INACTIVE patient is rejected, a provisional
     * ACTIVE one is permitted (ADR-119). `getPatientSummary` has already thrown
     * `NotFound` for a patient absent from the tenant MPI.
     */
    private function assertPatientEligible(PatientSummary $patient): void
    {
        if ($patient->status === 'MERGED') {
            throw PatientNotEligibleForEncounter::merged($patient->patientId);
        }

        if ($patient->status === 'DECEASED') {
            throw PatientNotEligibleForEncounter::deceased($patient->patientId);
        }

        if ($patient->status === 'INACTIVE') {
            throw PatientNotEligibleForEncounter::inactive($patient->patientId);
        }
    }
}
