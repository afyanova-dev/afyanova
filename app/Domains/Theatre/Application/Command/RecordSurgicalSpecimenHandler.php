<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Application\Command;

use App\Domains\Theatre\Domain\Model\SurgicalSpecimen;
use App\Domains\Theatre\Domain\Service\SurgicalSpecimenLifecycle;
use App\Domains\Theatre\Domain\SurgicalCaseRepository;
use App\Domains\Theatre\Domain\SurgicalSpecimenRepository;
use App\Models\User;
use App\Shared\Authorization\ScopeChecker;
use App\Shared\Domain\NotFound;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

/**
 * Orchestrates `RecordSurgicalSpecimen` (ADR-164 D-C). `SurgicalSpecimenRepository`
 * is injected FIRST so the bus resolves `SurgicalSpecimenPolicy` (IC §5.1).
 * The SurgicalCase is loaded via Theatre's own repository (the T.3 precedent)
 * for the eligibility gate, the denormalised context copy, and the ADR-133
 * per-case-facility refinement. `sequence` is allocated in this transaction.
 * The case is READ only — never mutated (100% additive to closed Theatre code).
 */
final class RecordSurgicalSpecimenHandler
{
    public function __construct(
        private readonly SurgicalSpecimenRepository $specimens,
        private readonly SurgicalCaseRepository $cases,
        private readonly SurgicalSpecimenLifecycle $lifecycle,
        private readonly ScopeChecker $scope,
    ) {}

    public function __invoke(RecordSurgicalSpecimen $command): SurgicalSpecimen
    {
        $case = $this->cases->getById($command->caseId);

        $this->assertActorScopedToCaseFacility((string) $case->facility_id, $command->specimenId);

        $this->lifecycle->assertCaseEligibleForSpecimen((string) $case->id, (string) $case->status);

        $specimen = SurgicalSpecimen::record(
            id: $command->specimenId,
            tenantId: (string) $case->tenant_id,
            facilityId: (string) $case->facility_id,
            caseId: (string) $case->id,
            patientId: (string) $case->patient_id,
            encounterId: (string) $case->encounter_id,
            sequence: $this->specimens->nextSequence((string) $case->id),
            specimenType: strtoupper(trim($command->specimenType)),
            bodySite: self::normalise($command->bodySite),
            laterality: self::normalise($command->laterality),
            description: $command->description !== null ? trim($command->description) : null,
            collectedByUserId: $command->collectedByUserId ?? $command->actorId,
            destinationLaboratory: self::normalise($command->destinationLaboratory),
            createdByUserId: $command->actorId,
        );

        $this->specimens->save($specimen);

        return $specimen;
    }

    private function assertActorScopedToCaseFacility(string $facilityId, string $specimenId): void
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            throw new RuntimeException('RecordSurgicalSpecimen dispatched without an authenticated user (IC §5).');
        }

        if (! in_array('theatre.specimens.record', $this->scope->effectivePermissions($user, $facilityId), true)) {
            throw NotFound::aggregate(SurgicalSpecimen::class, $specimenId);
        }
    }

    private static function normalise(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = strtoupper(trim($value));

        return $trimmed === '' ? null : $trimmed;
    }
}
