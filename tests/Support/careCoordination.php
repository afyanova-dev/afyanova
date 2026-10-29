<?php

declare(strict_types=1);

use App\Domains\CareCoordination\Domain\Model\CareEpisode;
use App\Domains\CareCoordination\Domain\Model\Referral;
use App\Domains\CareCoordination\Domain\Model\ReferralFeedback;
use App\Domains\Encounter\Application\Command\CreateEncounter;
use App\Domains\Encounter\Application\Command\StartEncounter;
use App\Domains\Encounter\Domain\Enum\EncounterType;
use App\Domains\Organization\Domain\Model\Facility;
use App\Domains\Patient\Domain\Model\Patient;
use App\Shared\Bus\CommandBus;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

if (! function_exists('careCoordinationFixture')) {
    /**
     * @param  list<string>|null  $permissions
     * @param  list<string>  $capabilities
     * @return array{tenant: mixed, organization: mixed, facility: mixed, user: mixed, role_id: string}
     */
    function careCoordinationFixture(?array $permissions = null, array $capabilities = ['OUTPATIENT_CARE']): array
    {
        $permissions ??= [
            'care_coordination.access',
            'care_coordination.referral.send',
            'care_coordination.referral.receive',
            'care_coordination.handover.acknowledge',
            'care_coordination.feedback.record',
            'care_coordination.episode.manage',
            'care_coordination.followup.manage',
            'encounter.encounters.read',
            'encounter.encounters.create',
            'encounter.encounters.update',
            'encounter.encounters.cancel',
            'encounter.encounters.link_relationship',
            'patient.patients.read',
        ];

        /** @var array{tenant: mixed, organization: mixed, facility: mixed, user: mixed, role_id: string} $fixture */
        $fixture = makeTenantWithUser($permissions, $capabilities);
        test()->actingAs($fixture['user']);

        return $fixture;
    }
}

if (! function_exists('createReferralFixture')) {
    /**
     * @param  array<string, mixed>  $overrides
     */
    function createReferralFixture(string $tenantId, string $facilityId, string $patientId, array $overrides = []): Referral
    {
        return TenantContextScope::run($tenantId, null, fn () => Referral::factory()
            ->forTenant($tenantId)
            ->atFacility($facilityId)
            ->forPatient($patientId)
            ->create($overrides));
    }
}

if (! function_exists('createReferralFeedbackFixture')) {
    /**
     * @param  array<string, mixed>  $overrides
     */
    function createReferralFeedbackFixture(string $tenantId, string $facilityId, string $referralId, array $overrides = []): ReferralFeedback
    {
        return TenantContextScope::run($tenantId, null, fn () => ReferralFeedback::factory()
            ->forTenant($tenantId)
            ->atFacility($facilityId)
            ->forReferral($referralId)
            ->create($overrides));
    }
}

if (! function_exists('createCareEpisodeFixture')) {
    /**
     * @param  array<string, mixed>  $overrides
     */
    function createCareEpisodeFixture(string $tenantId, string $facilityId, string $patientId, array $overrides = []): CareEpisode
    {
        return TenantContextScope::run($tenantId, null, fn () => CareEpisode::factory()
            ->forTenant($tenantId)
            ->atFacility($facilityId)
            ->forPatient($patientId)
            ->create($overrides));
    }
}

if (! function_exists('seedActivePatientForCare')) {
    function seedActivePatientForCare(string $tenantId): string
    {
        return TenantContextScope::run($tenantId, null, fn () => (string) Patient::factory()
            ->forTenant($tenantId)
            ->create()->id);
    }
}

if (! function_exists('seedEligibleReferralEncounter')) {
    /**
     * Create an ARRIVED-or-ACTIVE encounter eligible for CareCoordination.
     *
     * @return array{encounterId: string, patientId: string}
     */
    function seedEligibleReferralEncounter(string $tenantId, string $facilityId, string $actorId, bool $started = true, EncounterType $encounterType = EncounterType::Outpatient): array
    {
        $patientId = TenantContextScope::run($tenantId, null, fn () => (string) Patient::factory()
            ->forTenant($tenantId)
            ->create()->id);

        $encounterId = (string) Str::uuid7();

        TenantContextScope::run($tenantId, $facilityId, function () use ($encounterId, $patientId, $actorId, $started, $encounterType): void {
            app(CommandBus::class)->dispatch(new CreateEncounter(
                encounterId: $encounterId,
                patientId: $patientId,
                actorId: $actorId,
                encounterType: $encounterType,
                planned: false,
            ));

            if ($started) {
                app(CommandBus::class)->dispatch(new StartEncounter(
                    encounterId: $encounterId,
                    actorId: $actorId,
                ));
            }
        });

        return ['encounterId' => $encounterId, 'patientId' => $patientId];
    }
}

if (! function_exists('createFacilityFixture')) {
    function createFacilityFixture(string $tenantId, string $organizationId, array $capabilities = ['OUTPATIENT_CARE']): Facility
    {
        return TenantContextScope::run($tenantId, null, function () use ($tenantId, $organizationId, $capabilities) {
            $facility = Facility::factory()->forTenant($tenantId, $organizationId)->create();
            foreach ($capabilities as $cap) {
                DB::table('org_facility_capabilities')->insert([
                    'id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'facility_id' => $facility->id,
                    'capability_code' => $cap, 'state' => 'ENABLED', 'enabled_at' => now(),
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }

            return $facility;
        });
    }
}

if (! function_exists('assignUserToFacility')) {
    function assignUserToFacility(string $tenantId, string $userId, string $roleId, string $facilityId): void
    {
        DB::table('idn_access_assignments')->insert([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'role_id' => $roleId,
            'facility_id' => $facilityId,
            'status' => 'ACTIVE',
            'is_primary' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
