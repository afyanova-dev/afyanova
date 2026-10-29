<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Application\Command;

use App\Domains\Theatre\Domain\Model\SurgicalProcedure;
use App\Domains\Theatre\Domain\Service\SurgicalProcedureLifecycle;
use App\Domains\Theatre\Domain\SurgicalCaseRepository;
use App\Domains\Theatre\Domain\SurgicalProcedureRepository;
use App\Models\User;
use App\Shared\Authorization\ScopeChecker;
use App\Shared\Domain\NotFound;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

/**
 * Orchestrates `RecordPlannedProcedure` (ADR-156 D-C). `SurgicalProcedureRepository`
 * is injected FIRST so the bus resolves `SurgicalProcedurePolicy` (IC §5.1).
 * The SurgicalCase is loaded via Theatre's own repository (the T.2 precedent)
 * for the eligibility gate + the denormalised context copy + the ADR-133
 * per-case-facility refinement. `sequence` is allocated in this transaction.
 */
final class RecordPlannedProcedureHandler
{
    public function __construct(
        private readonly SurgicalProcedureRepository $procedures,
        private readonly SurgicalCaseRepository $cases,
        private readonly SurgicalProcedureLifecycle $lifecycle,
        private readonly ScopeChecker $scope,
    ) {}

    public function __invoke(RecordPlannedProcedure $command): SurgicalProcedure
    {
        $case = $this->cases->getById($command->caseId);

        $this->assertActorScopedToCaseFacility((string) $case->facility_id, $command->procedureId);

        $this->lifecycle->assertCaseEligibleForProcedure((string) $case->id, (string) $case->status);

        $procedure = SurgicalProcedure::record(
            id: $command->procedureId,
            tenantId: (string) $case->tenant_id,
            facilityId: (string) $case->facility_id,
            caseId: (string) $case->id,
            patientId: (string) $case->patient_id,
            encounterId: (string) $case->encounter_id,
            sequence: $this->procedures->nextSequence((string) $case->id),
            plannedDescription: $command->plannedDescription !== null ? trim($command->plannedDescription) : null,
            primarySurgeonUserId: $command->primarySurgeonUserId,
            createdByUserId: $command->actorId,
        );

        $this->procedures->save($procedure);

        return $procedure;
    }

    private function assertActorScopedToCaseFacility(string $facilityId, string $procedureId): void
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            throw new RuntimeException('RecordPlannedProcedure dispatched without an authenticated user (IC §5).');
        }

        if (! in_array('theatre.procedures.record', $this->scope->effectivePermissions($user, $facilityId), true)) {
            throw NotFound::aggregate(SurgicalProcedure::class, $procedureId);
        }
    }
}
