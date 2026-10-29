<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Application\Command;

use App\Domains\Theatre\Domain\Exception\RecoveryHandoffAlreadyExistsForCase;
use App\Domains\Theatre\Domain\Model\RecoveryHandoff;
use App\Domains\Theatre\Domain\RecoveryHandoffRepository;
use App\Domains\Theatre\Domain\Service\RecoveryHandoffLifecycle;
use App\Domains\Theatre\Domain\SurgicalCaseRepository;
use App\Models\User;
use App\Shared\Authorization\ScopeChecker;
use App\Shared\Domain\NotFound;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

/**
 * Orchestrates `InitiateRecoveryHandoff` (ADR-156 D-D). `RecoveryHandoffRepository`
 * is injected FIRST so the bus resolves `RecoveryHandoffPolicy` (IC §5.1). The
 * SurgicalCase is loaded via Theatre's own repository (the T.2 precedent) for
 * the eligibility gate + the denormalised context copy + the ADR-133
 * per-case-facility refinement.
 */
final class InitiateRecoveryHandoffHandler
{
    public function __construct(
        private readonly RecoveryHandoffRepository $handoffs,
        private readonly SurgicalCaseRepository $cases,
        private readonly RecoveryHandoffLifecycle $lifecycle,
        private readonly ScopeChecker $scope,
    ) {}

    public function __invoke(InitiateRecoveryHandoff $command): RecoveryHandoff
    {
        $case = $this->cases->getById($command->caseId);

        $this->assertActorScopedToCaseFacility((string) $case->facility_id, $command->handoffId);

        $this->lifecycle->assertCaseEligibleForHandoff((string) $case->id, (string) $case->status);

        $handoff = RecoveryHandoff::initiate(
            id: $command->handoffId,
            tenantId: (string) $case->tenant_id,
            facilityId: (string) $case->facility_id,
            caseId: (string) $case->id,
            patientId: (string) $case->patient_id,
            encounterId: (string) $case->encounter_id,
            destinationType: strtoupper(trim($command->destinationType)),
            patientCondition: $command->patientCondition !== null && trim($command->patientCondition) !== '' ? trim($command->patientCondition) : null,
            pendingActions: $command->pendingActions !== null && trim($command->pendingActions) !== '' ? trim($command->pendingActions) : null,
            receivingTeamRef: $command->receivingTeamRef,
            initiatedByUserId: $command->actorId,
        );

        try {
            $this->handoffs->save($handoff);
        } catch (UniqueConstraintViolationException $e) {
            if (str_contains($e->getMessage(), 'thr_recovery_handoffs_tenant_id_case_id_unique')) {
                throw RecoveryHandoffAlreadyExistsForCase::forCase((string) $case->id);
            }

            throw $e;
        }

        return $handoff;
    }

    private function assertActorScopedToCaseFacility(string $facilityId, string $handoffId): void
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            throw new RuntimeException('InitiateRecoveryHandoff dispatched without an authenticated user (IC §5).');
        }

        if (! in_array('theatre.recovery.handoff', $this->scope->effectivePermissions($user, $facilityId), true)) {
            throw NotFound::aggregate(RecoveryHandoff::class, $handoffId);
        }
    }
}
