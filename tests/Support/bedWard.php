<?php

declare(strict_types=1);

use App\Domains\BedWard\Domain\Model\Bed;
use App\Domains\BedWard\Domain\Model\BedReservation;
use App\Domains\BedWard\Domain\Model\Ward;
use App\Domains\Encounter\Application\Command\CreateEncounter;
use App\Domains\Encounter\Application\Command\StartEncounter;
use App\Domains\Encounter\Domain\Enum\EncounterType;
use App\Domains\Patient\Domain\Model\Patient;
use App\Shared\Bus\CommandBus;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Support\Str;

if (! function_exists('bedWardFixture')) {
    /**
     * @param  list<string>|null  $permissions
     * @param  list<string>  $capabilities
     * @return array{tenant: mixed, organization: mixed, facility: mixed, user: mixed, role_id: string}
     */
    function bedWardFixture(?array $permissions = null, array $capabilities = ['INPATIENT_CARE']): array
    {
        $permissions ??= [
            'bed_ward.access',
            'bed_ward.wards.configure',
            'bed_ward.beds.assign',
            'bed_ward.beds.release',
            'bed_ward.beds.block',
            'bed_ward.occupancy.correct',
            'bed_ward.reservations.manage',
            'encounter.encounters.read',
            'encounter.encounters.create',
            'encounter.encounters.update',
            'patient.patients.read',
        ];

        /** @var array{tenant: mixed, organization: mixed, facility: mixed, user: mixed, role_id: string} $fixture */
        $fixture = makeTenantWithUser($permissions, $capabilities);
        test()->actingAs($fixture['user']);

        return $fixture;
    }
}

if (! function_exists('createWardFixture')) {
    /**
     * @param  array<string, mixed>  $overrides
     */
    function createWardFixture(string $tenantId, string $facilityId, array $overrides = []): Ward
    {
        return TenantContextScope::run($tenantId, null, fn () => Ward::factory()
            ->forTenant($tenantId)
            ->atFacility($facilityId)
            ->create($overrides));
    }
}

if (! function_exists('createBedFixture')) {
    /**
     * @param  array<string, mixed>  $overrides
     */
    function createBedFixture(string $tenantId, string $facilityId, string $wardId, array $overrides = []): Bed
    {
        return TenantContextScope::run($tenantId, null, fn () => Bed::factory()
            ->forTenant($tenantId)
            ->atFacility($facilityId)
            ->inWard($wardId)
            ->create($overrides));
    }
}

if (! function_exists('createBedReservationFixture')) {
    /**
     * @param  array<string, mixed>  $overrides
     */
    function createBedReservationFixture(string $tenantId, string $facilityId, string $bedId, array $overrides = []): BedReservation
    {
        return TenantContextScope::run($tenantId, null, fn () => BedReservation::factory()
            ->forTenant($tenantId)
            ->atFacility($facilityId)
            ->forBed($bedId)
            ->create($overrides));
    }
}

if (! function_exists('seedActiveInpatientEncounter')) {
    /**
     * Create an ACTIVE INPATIENT encounter for bed testing.
     *
     * @return array{encounterId: string, patientId: string}
     */
    function seedActiveInpatientEncounter(string $tenantId, string $facilityId, string $actorId, string $type = 'INPATIENT'): array
    {
        $patientId = TenantContextScope::run($tenantId, null, fn () => (string) Patient::factory()
            ->forTenant($tenantId)
            ->create()->id);

        $encounterId = (string) Str::uuid7();
        $encounterType = $type === 'MATERNITY' ? EncounterType::Maternity : EncounterType::Inpatient;

        TenantContextScope::run($tenantId, $facilityId, function () use ($encounterId, $patientId, $actorId, $encounterType): void {
            app(CommandBus::class)->dispatch(new CreateEncounter(
                encounterId: $encounterId,
                patientId: $patientId,
                actorId: $actorId,
                encounterType: $encounterType,
                planned: false,
            ));

            app(CommandBus::class)->dispatch(new StartEncounter(
                encounterId: $encounterId,
                actorId: $actorId,
            ));
        });

        return ['encounterId' => $encounterId, 'patientId' => $patientId];
    }
}
