<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Application\Command;

use App\Domains\Clinical\Domain\ClinicalPlanRepository;
use App\Domains\Clinical\Domain\DiagnosisReader;
use App\Domains\Clinical\Domain\Exception\ClinicalPlanContextMismatch;
use App\Domains\Clinical\Domain\Exception\EncounterNotEligibleForClinicalRecord;
use App\Domains\Clinical\Domain\Exception\PatientNotEligibleForClinicalRecord;
use App\Domains\Clinical\Domain\Model\ClinicalPlan;
use App\Domains\Clinical\Domain\ProblemReader;
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

final class CreateClinicalPlanHandler
{
    public function __construct(
        private readonly ClinicalPlanRepository $plans,
        private readonly PatientApi $patients,
        private readonly EncounterApi $encounters,
        private readonly DiagnosisReader $diagnoses,
        private readonly ProblemReader $problems,
        private readonly ScopeChecker $scope,
        private readonly TenantContext $context,
    ) {}

    public function __invoke(CreateClinicalPlan $command): CreateClinicalPlanResult
    {
        $this->assertPatientEligible($this->patients->getPatientSummary($command->patientId));

        $encounter = $this->encounters->getEncounterContext($command->encounterId);

        $this->assertActorScopedToEncounterFacility($encounter, $command->clinicalPlanId);

        if ($encounter->patientId !== $command->patientId) {
            throw ClinicalPlanContextMismatch::patientEncounter(
                $command->patientId,
                $command->encounterId,
                $encounter->patientId,
            );
        }

        $this->assertEncounterEligible($encounter);

        if ($command->indicationDiagnosisId !== null) {
            $diagnosis = $this->diagnoses->summary($command->indicationDiagnosisId);
            if ($diagnosis->patientId !== $command->patientId) {
                throw ClinicalPlanContextMismatch::indicationDiagnosis(
                    $command->patientId,
                    $command->indicationDiagnosisId,
                    $diagnosis->patientId,
                );
            }
        }

        if ($command->indicationProblemId !== null) {
            $problem = $this->problems->summary($command->indicationProblemId);
            if ($problem->patientId !== $command->patientId) {
                throw ClinicalPlanContextMismatch::indicationProblem(
                    $command->patientId,
                    $command->indicationProblemId,
                    $problem->patientId,
                );
            }
        }

        $now = CarbonImmutable::now();
        $occurredAt = $command->occurredAt !== null ? CarbonImmutable::parse($command->occurredAt) : $now;

        $plan = ClinicalPlan::create(
            id: $command->clinicalPlanId,
            tenantId: $this->context->tenantId(),
            facilityId: $encounter->facilityId,
            patientId: $command->patientId,
            encounterId: $command->encounterId,
            authorUserId: $command->actorId,
            planType: $command->planType,
            clinicalIntent: $command->clinicalIntent,
            goals: $command->goals,
            plannedActions: $command->plannedActions,
            instructions: $command->instructions,
            followUp: $command->followUp,
            indicationDiagnosisId: $command->indicationDiagnosisId,
            indicationProblemId: $command->indicationProblemId,
            occurredAt: $occurredAt,
            recordedAt: $now,
        );

        $this->plans->save($plan);

        return new CreateClinicalPlanResult((string) $plan->id);
    }

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

    private function assertActorScopedToEncounterFacility(EncounterContext $encounter, string $clinicalPlanId): void
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            throw new RuntimeException('CreateClinicalPlan dispatched without an authenticated user (IC §5).');
        }

        if (! in_array(
            'clinical.plans.record',
            $this->scope->effectivePermissions($user, $encounter->facilityId),
            true,
        )) {
            throw NotFound::aggregate(ClinicalPlan::class, $clinicalPlanId);
        }
    }

    private function assertEncounterEligible(EncounterContext $encounter): void
    {
        if (! in_array($encounter->status, ['ARRIVED', 'ACTIVE', 'COMPLETED'], true)) {
            throw EncounterNotEligibleForClinicalRecord::status($encounter->encounterId, $encounter->status);
        }
    }
}
