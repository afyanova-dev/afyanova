<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Application\Command;

use App\Domains\Clinical\Domain\ClinicalPlanReader;
use App\Domains\Clinical\Domain\DiagnosisReader;
use App\Domains\Clinical\Domain\Exception\EncounterNotEligibleForClinicalRecord;
use App\Domains\Clinical\Domain\Exception\PatientNotEligibleForClinicalRecord;
use App\Domains\Clinical\Domain\Exception\PrescribingDecisionContextMismatch;
use App\Domains\Clinical\Domain\Model\PrescribingDecision;
use App\Domains\Clinical\Domain\PrescribingDecisionRepository;
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

final class RecordPrescribingDecisionHandler
{
    public function __construct(
        private readonly PrescribingDecisionRepository $decisions,
        private readonly PatientApi $patients,
        private readonly EncounterApi $encounters,
        private readonly ClinicalPlanReader $plans,
        private readonly DiagnosisReader $diagnoses,
        private readonly ProblemReader $problems,
        private readonly ScopeChecker $scope,
        private readonly TenantContext $context,
    ) {}

    public function __invoke(RecordPrescribingDecision $command): RecordPrescribingDecisionResult
    {
        $this->assertPatientEligible($this->patients->getPatientSummary($command->patientId));

        $encounter = $this->encounters->getEncounterContext($command->encounterId);

        $this->assertActorScopedToEncounterFacility($encounter, $command->prescribingDecisionId);

        if ($encounter->patientId !== $command->patientId) {
            throw PrescribingDecisionContextMismatch::patientEncounter(
                $command->patientId,
                $command->encounterId,
                $encounter->patientId,
            );
        }

        $this->assertEncounterEligible($encounter);

        if ($command->clinicalPlanId !== null) {
            $plan = $this->plans->summary($command->clinicalPlanId);
            if ($plan->patientId !== $command->patientId) {
                throw PrescribingDecisionContextMismatch::clinicalPlan(
                    $command->patientId,
                    $command->clinicalPlanId,
                    $plan->patientId,
                );
            }
        }

        if ($command->indicationDiagnosisId !== null) {
            $diagnosis = $this->diagnoses->summary($command->indicationDiagnosisId);
            if ($diagnosis->patientId !== $command->patientId) {
                throw PrescribingDecisionContextMismatch::indicationDiagnosis(
                    $command->patientId,
                    $command->indicationDiagnosisId,
                    $diagnosis->patientId,
                );
            }
        }

        if ($command->indicationProblemId !== null) {
            $problem = $this->problems->summary($command->indicationProblemId);
            if ($problem->patientId !== $command->patientId) {
                throw PrescribingDecisionContextMismatch::indicationProblem(
                    $command->patientId,
                    $command->indicationProblemId,
                    $problem->patientId,
                );
            }
        }

        $now = CarbonImmutable::now();
        $occurredAt = $command->occurredAt !== null ? CarbonImmutable::parse($command->occurredAt) : $now;

        $decision = PrescribingDecision::record(
            id: $command->prescribingDecisionId,
            tenantId: $this->context->tenantId(),
            facilityId: $encounter->facilityId,
            patientId: $command->patientId,
            encounterId: $command->encounterId,
            authorUserId: $command->actorId,
            medication: $command->medication,
            dose: $command->dose,
            route: $command->route,
            frequency: $command->frequency,
            duration: $command->duration,
            quantity: $command->quantity,
            quantityValue: $command->quantityValue,
            quantityUnit: $command->quantityUnit,
            dosingInstruction: $command->dosingInstruction,
            therapeuticIntent: $command->therapeuticIntent,
            indication: $command->indication,
            urgency: $command->urgency,
            clinicalPlanId: $command->clinicalPlanId,
            indicationDiagnosisId: $command->indicationDiagnosisId,
            indicationProblemId: $command->indicationProblemId,
            occurredAt: $occurredAt,
            recordedAt: $now,
        );

        $this->decisions->save($decision);

        return new RecordPrescribingDecisionResult((string) $decision->id);
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

    private function assertActorScopedToEncounterFacility(EncounterContext $encounter, string $prescribingDecisionId): void
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            throw new RuntimeException('RecordPrescribingDecision dispatched without an authenticated user (IC §5).');
        }

        if (! in_array(
            'clinical.prescribing.record',
            $this->scope->effectivePermissions($user, $encounter->facilityId),
            true,
        )) {
            throw NotFound::aggregate(PrescribingDecision::class, $prescribingDecisionId);
        }
    }

    private function assertEncounterEligible(EncounterContext $encounter): void
    {
        if (! in_array($encounter->status, ['ARRIVED', 'ACTIVE', 'COMPLETED'], true)) {
            throw EncounterNotEligibleForClinicalRecord::status($encounter->encounterId, $encounter->status);
        }
    }
}
