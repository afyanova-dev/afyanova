<?php

declare(strict_types=1);

use App\Domains\Encounter\Application\Command\CreateEncounter;
use App\Domains\Encounter\Application\Command\StartEncounter;
use App\Domains\Encounter\Domain\Enum\EncounterType;
use App\Domains\Organization\Domain\Model\Room as OrgRoom;
use App\Domains\Patient\Domain\Model\Patient;
use App\Domains\Theatre\Domain\Model\OperatingRoom;
use App\Domains\Theatre\Domain\Model\SurgicalCase;
use App\Shared\Bus\CommandBus;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Support\Str;

if (! function_exists('createOrgRoomFixture')) {
    function createOrgRoomFixture(string $tenantId, string $facilityId): OrgRoom
    {
        return TenantContextScope::run($tenantId, null, fn () => OrgRoom::query()->create([
            'facility_id' => $facilityId,
            'unit_id' => null,
            'name' => 'OR '.fake()->unique()->numerify('##'),
            'code' => 'OR-'.fake()->unique()->numerify('###'),
            'room_type' => 'THEATRE',
        ]));
    }
}

if (! function_exists('theatreFixture')) {
    /**
     * @param  list<string>|null  $permissions
     * @param  list<string>  $capabilities
     * @return array{tenant: mixed, organization: mixed, facility: mixed, user: mixed, role_id: string}
     */
    function theatreFixture(?array $permissions = null, array $capabilities = ['THEATRE', 'OUTPATIENT_CARE']): array
    {
        $permissions ??= [
            'theatre.access',
            'theatre.cases.create',
            'theatre.cases.schedule',
            'theatre.rooms.manage',
            'theatre.team.manage',
            'theatre.checklists.manage',
            'theatre.cases.execute',
            'theatre.cases.cancel',
            'theatre.operative_notes.author',
            'theatre.operative_notes.sign',
            'theatre.anaesthesia.record',
            'theatre.anaesthesia.sign',
            'theatre.procedures.record',
            'theatre.recovery.handoff',
            'theatre.recovery.acknowledge',
            'theatre.case_events.record',
            'theatre.specimens.record',
            'theatre.specimens.handoff',
            'encounter.encounters.read',
            'encounter.encounters.create',
            'encounter.encounters.update',
            'encounter.encounters.cancel',
            'patient.patients.read',
            'bed_ward.beds.assign',
        ];

        /** @var array{tenant: mixed, organization: mixed, facility: mixed, user: mixed, role_id: string} $fixture */
        $fixture = makeTenantWithUser($permissions, $capabilities);
        test()->actingAs($fixture['user']);

        return $fixture;
    }
}

if (! function_exists('createOperatingRoomFixture')) {
    /**
     * @param  array<string, mixed>  $overrides
     */
    function createOperatingRoomFixture(string $tenantId, string $facilityId, array $overrides = []): OperatingRoom
    {
        return TenantContextScope::run($tenantId, null, fn () => OperatingRoom::factory()
            ->forTenant($tenantId)
            ->atFacility($facilityId)
            ->create($overrides));
    }
}

if (! function_exists('createSurgicalCaseFixture')) {
    /**
     * @param  array<string, mixed>  $overrides
     */
    function createSurgicalCaseFixture(string $tenantId, string $facilityId, string $encounterId, string $patientId, array $overrides = []): SurgicalCase
    {
        return TenantContextScope::run($tenantId, null, fn () => SurgicalCase::factory()
            ->forTenant($tenantId)
            ->atFacility($facilityId)
            ->forEncounter($encounterId, $patientId)
            ->create($overrides));
    }
}

if (! function_exists('seedEligibleSurgicalEncounter')) {
    /**
     * Create an ARRIVED-or-ACTIVE encounter eligible for CreateSurgicalCase
     * (ADR-150 D-H).
     *
     * @return array{encounterId: string, patientId: string}
     */
    function seedEligibleSurgicalEncounter(string $tenantId, string $facilityId, string $actorId, bool $started = true, EncounterType $encounterType = EncounterType::Outpatient): array
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
