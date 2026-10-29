<?php

declare(strict_types=1);

namespace App\Domains\CareCoordination\Application\Command;

use App\Domains\CareCoordination\Domain\Exception\EncounterNotEligibleForReferral;
use App\Domains\CareCoordination\Domain\Exception\PatientNotEligibleForReferral;
use App\Domains\CareCoordination\Domain\Exception\ReferralContextMismatch;
use App\Domains\CareCoordination\Domain\Model\Referral;
use App\Domains\CareCoordination\Domain\ReferralRepository;
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
 * Orchestrates `CreateReferral` (ADR-151 D-D/D-G).
 *
 * Flow: `PatientApi::getPatientSummary` patient-state gate (ACTIVE, incl.
 * provisional) -> when `sourceEncounterId` is given, `EncounterApi::
 * getEncounterContext` + the ADR-133-style per-encounter-facility
 * refinement + patient/encounter consistency + encounter-state gate
 * (ARRIVED|ACTIVE|COMPLETED) and `facility_id` = the encounter's facility;
 * otherwise (standalone referral) `facility_id` = the caller's active
 * facility context -> create DRAFT -> versioned save (no event — D-J's
 * 12-event list starts at ReferralSent).
 */
final class CreateReferralHandler
{
    public function __construct(
        private readonly ReferralRepository $referrals,
        private readonly PatientApi $patients,
        private readonly EncounterApi $encounters,
        private readonly ScopeChecker $scope,
        private readonly TenantContext $context,
    ) {}

    public function __invoke(CreateReferral $command): Referral
    {
        $this->assertPatientEligible($this->patients->getPatientSummary($command->patientId));

        $facilityId = $this->context->requireFacilityId();

        if ($command->sourceEncounterId !== null) {
            $encounter = $this->encounters->getEncounterContext($command->sourceEncounterId);

            $this->assertActorScopedToEncounterFacility($encounter, $command->referralId);

            if ($encounter->patientId !== $command->patientId) {
                throw ReferralContextMismatch::patientEncounter(
                    $command->patientId,
                    $command->sourceEncounterId,
                    $encounter->patientId,
                );
            }

            if (! in_array($encounter->status, ['ARRIVED', 'ACTIVE', 'COMPLETED'], true)) {
                throw EncounterNotEligibleForReferral::status($encounter->encounterId, $encounter->status);
            }

            $facilityId = $encounter->facilityId;
        }

        $now = CarbonImmutable::now();
        $occurredAt = $command->occurredAt !== null ? CarbonImmutable::parse($command->occurredAt) : $now;

        $referral = Referral::create(
            id: $command->referralId,
            tenantId: $this->context->tenantId(),
            facilityId: $facilityId,
            patientId: $command->patientId,
            direction: $command->direction,
            impliesHandover: $command->impliesHandover,
            sourceEncounterId: $command->sourceEncounterId,
            targetType: $command->targetType,
            targetRef: $command->targetRef,
            specialtyRequested: $command->specialtyRequested,
            priority: strtoupper(trim($command->priority)),
            clinicalReasonRef: $command->clinicalReasonRef,
            consentBasisRef: $command->consentBasisRef,
            createdByUserId: $command->actorId,
            occurredAt: $occurredAt,
        );

        $this->referrals->save($referral);

        return $referral;
    }

    private function assertPatientEligible(PatientSummary $patient): void
    {
        if ($patient->status === 'MERGED') {
            throw PatientNotEligibleForReferral::merged($patient->patientId);
        }

        if ($patient->status === 'DECEASED') {
            throw PatientNotEligibleForReferral::deceased($patient->patientId);
        }

        if ($patient->status === 'INACTIVE') {
            throw PatientNotEligibleForReferral::inactive($patient->patientId);
        }
    }

    private function assertActorScopedToEncounterFacility(EncounterContext $encounter, string $referralId): void
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            throw new RuntimeException('CreateReferral dispatched without an authenticated user (IC §5).');
        }

        if (! in_array(
            'care_coordination.referral.send',
            $this->scope->effectivePermissions($user, $encounter->facilityId),
            true,
        )) {
            throw NotFound::aggregate(Referral::class, $referralId);
        }
    }
}
