<?php

declare(strict_types=1);

namespace App\Domains\CareCoordination\Application\Command;

use App\Domains\CareCoordination\Domain\Exception\FollowUpContextMismatch;
use App\Domains\CareCoordination\Domain\Exception\PatientNotEligibleForFollowUp;
use App\Domains\CareCoordination\Domain\FollowUpRepository;
use App\Domains\CareCoordination\Domain\Model\FollowUp;
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
 * Orchestrates `ScheduleFollowUp` (ADR-154 D-E/D-I).
 *
 * Flow: `PatientApi::getPatientSummary` patient-state gate (ACTIVE, incl.
 * provisional) -> `EncounterApi::getEncounterContext` for the source encounter
 * (existence + the ADR-133 per-encounter-facility auth refinement +
 * patient/encounter consistency) -> `facility_id` copied from the encounter ->
 * create PENDING -> versioned save + `FollowUpScheduled`.
 */
final class ScheduleFollowUpHandler
{
    public function __construct(
        private readonly FollowUpRepository $followUps,
        private readonly PatientApi $patients,
        private readonly EncounterApi $encounters,
        private readonly ScopeChecker $scope,
        private readonly TenantContext $context,
    ) {}

    public function __invoke(ScheduleFollowUp $command): FollowUp
    {
        $this->assertPatientEligible($this->patients->getPatientSummary($command->patientId));

        $encounter = $this->encounters->getEncounterContext($command->sourceEncounterId);

        $this->assertActorScopedToEncounterFacility($encounter, $command->followUpId);

        if ($encounter->patientId !== $command->patientId) {
            throw FollowUpContextMismatch::patientEncounter(
                $command->patientId,
                $command->sourceEncounterId,
                $encounter->patientId,
            );
        }

        $now = CarbonImmutable::now();
        $occurredAt = $command->occurredAt !== null ? CarbonImmutable::parse($command->occurredAt) : $now;

        $followUp = FollowUp::schedule(
            id: $command->followUpId,
            tenantId: $this->context->tenantId(),
            facilityId: $encounter->facilityId,
            patientId: $command->patientId,
            sourceEncounterId: $command->sourceEncounterId,
            dueFrom: CarbonImmutable::parse($command->dueFrom),
            dueTo: CarbonImmutable::parse($command->dueTo),
            reason: $command->reason !== null ? trim($command->reason) : null,
            instructions: $command->instructions !== null && trim($command->instructions) !== '' ? trim($command->instructions) : null,
            createdByUserId: $command->actorId,
            occurredAt: $occurredAt,
        );

        $this->followUps->save($followUp);

        return $followUp;
    }

    private function assertPatientEligible(PatientSummary $patient): void
    {
        if ($patient->status === 'MERGED') {
            throw PatientNotEligibleForFollowUp::merged($patient->patientId);
        }

        if ($patient->status === 'DECEASED') {
            throw PatientNotEligibleForFollowUp::deceased($patient->patientId);
        }

        if ($patient->status === 'INACTIVE') {
            throw PatientNotEligibleForFollowUp::inactive($patient->patientId);
        }
    }

    private function assertActorScopedToEncounterFacility(EncounterContext $encounter, string $followUpId): void
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            throw new RuntimeException('ScheduleFollowUp dispatched without an authenticated user (IC §5).');
        }

        if (! in_array(
            'care_coordination.followup.manage',
            $this->scope->effectivePermissions($user, $encounter->facilityId),
            true,
        )) {
            throw NotFound::aggregate(FollowUp::class, $followUpId);
        }
    }
}
