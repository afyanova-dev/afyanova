<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Application\Command;

use App\Domains\Encounter\Contracts\Dto\EncounterContext;
use App\Domains\Encounter\Contracts\EncounterApi;
use App\Domains\Patient\Contracts\Dto\PatientSummary;
use App\Domains\Patient\Contracts\PatientApi;
use App\Domains\Theatre\Domain\Exception\EncounterNotEligibleForSurgicalCase;
use App\Domains\Theatre\Domain\Exception\PatientNotEligibleForSurgicalCase;
use App\Domains\Theatre\Domain\Exception\SurgicalCaseContextMismatch;
use App\Domains\Theatre\Domain\Model\SurgicalCase;
use App\Domains\Theatre\Domain\SurgicalCaseRepository;
use App\Domains\Theatre\Infrastructure\Sequence\CaseNumberSequence;
use App\Models\User;
use App\Shared\Authorization\ScopeChecker;
use App\Shared\Domain\NotFound;
use App\Shared\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

/**
 * Orchestrates `CreateSurgicalCase` (ADR-150 D-C/D-H).
 *
 * Flow: `PatientApi::getPatientSummary` patient-state gate (ACTIVE, incl.
 * provisional) -> `EncounterApi::getEncounterContext` -> ADR-133-style
 * per-encounter-facility refinement -> patient/encounter consistency ->
 * encounter-state gate (ARRIVED|ACTIVE — narrower than Clinical's, ADR-150
 * D-H) -> allocate `case_number` (`CaseNumberSequence`, ADR-115 pattern) ->
 * create REQUESTED -> versioned save + `SurgicalCaseCreated` (one txn).
 */
final class CreateSurgicalCaseHandler
{
    public function __construct(
        private readonly SurgicalCaseRepository $cases,
        private readonly PatientApi $patients,
        private readonly EncounterApi $encounters,
        private readonly CaseNumberSequence $caseNumbers,
        private readonly ScopeChecker $scope,
        private readonly TenantContext $context,
    ) {}

    public function __invoke(CreateSurgicalCase $command): SurgicalCase
    {
        $this->assertPatientEligible($this->patients->getPatientSummary($command->patientId));

        $encounter = $this->encounters->getEncounterContext($command->encounterId);

        $this->assertActorScopedToEncounterFacility($encounter, $command->caseId);

        if ($encounter->patientId !== $command->patientId) {
            throw SurgicalCaseContextMismatch::patientEncounter(
                $command->patientId,
                $command->encounterId,
                $encounter->patientId,
            );
        }

        if (! in_array($encounter->status, ['ARRIVED', 'ACTIVE'], true)) {
            throw EncounterNotEligibleForSurgicalCase::status($encounter->encounterId, $encounter->status);
        }

        $tenantId = $this->context->tenantId();
        $caseNumber = $this->caseNumbers->next($tenantId);

        $now = CarbonImmutable::now();
        $occurredAt = $command->occurredAt !== null ? CarbonImmutable::parse($command->occurredAt) : $now;

        $case = SurgicalCase::create(
            id: $command->caseId,
            tenantId: $tenantId,
            facilityId: $encounter->facilityId,
            patientId: $command->patientId,
            encounterId: $command->encounterId,
            caseNumber: $caseNumber,
            priority: strtoupper(trim($command->priority)),
            plannedProcedure: $command->plannedProcedure,
            indication: $command->indication,
            createdByUserId: $command->actorId,
            occurredAt: $occurredAt,
        );

        $this->cases->save($case);

        return $case;
    }

    private function assertPatientEligible(PatientSummary $patient): void
    {
        if ($patient->status === 'MERGED') {
            throw PatientNotEligibleForSurgicalCase::merged($patient->patientId);
        }

        if ($patient->status === 'DECEASED') {
            throw PatientNotEligibleForSurgicalCase::deceased($patient->patientId);
        }

        if ($patient->status === 'INACTIVE') {
            throw PatientNotEligibleForSurgicalCase::inactive($patient->patientId);
        }
    }

    private function assertActorScopedToEncounterFacility(EncounterContext $encounter, string $caseId): void
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            throw new RuntimeException('CreateSurgicalCase dispatched without an authenticated user (IC §5).');
        }

        if (! in_array(
            'theatre.cases.create',
            $this->scope->effectivePermissions($user, $encounter->facilityId),
            true,
        )) {
            throw NotFound::aggregate(SurgicalCase::class, $caseId);
        }
    }
}
