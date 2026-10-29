<?php

declare(strict_types=1);

use App\Domains\Encounter\Application\Command\CreateEncounter;
use App\Domains\Encounter\Application\Command\StartEncounter;
use App\Domains\Encounter\Domain\Enum\EncounterType;
use App\Domains\Laboratory\Application\Command\AcceptLabOrder;
use App\Domains\Laboratory\Application\Command\AcceptSpecimen;
use App\Domains\Laboratory\Application\Command\CollectSpecimen;
use App\Domains\Laboratory\Application\Command\CreateLabOrder;
use App\Domains\Laboratory\Application\Command\ReceiveSpecimen;
use App\Domains\Laboratory\Application\Command\SubmitLabOrder;
use App\Domains\Laboratory\Domain\Model\LabOrderItem;
use App\Domains\Laboratory\Domain\Model\LabPanel;
use App\Domains\Laboratory\Domain\Model\LabPanelMember;
use App\Domains\Laboratory\Domain\Model\LabTestDefinition;
use App\Domains\Patient\Domain\Model\Patient;
use App\Shared\Bus\CommandBus;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Support\Str;

if (! function_exists('laboratoryFixture')) {
    /**
     * @param  list<string>|null  $permissions
     * @param  list<string>  $capabilities
     * @return array{tenant: mixed, organization: mixed, facility: mixed, user: mixed, role_id: string}
     */
    function laboratoryFixture(?array $permissions = null, array $capabilities = ['OUTPATIENT_CARE']): array
    {
        $permissions ??= [
            'laboratory.access',
            'laboratory.orders.create',
            'laboratory.orders.accept',
            'laboratory.specimens.collect',
            'laboratory.specimens.receive',
            'laboratory.results.enter',
            'laboratory.results.verify',
            'laboratory.results.release',
            'laboratory.results.amend',
            'laboratory.critical.manage',
            'laboratory.quality.manage',
            'encounter.encounters.read',
            'encounter.encounters.create',
            'encounter.encounters.update',
            'encounter.encounters.cancel',
            'patient.patients.read',
        ];

        /** @var array{tenant: mixed, organization: mixed, facility: mixed, user: mixed, role_id: string} $fixture */
        $fixture = makeTenantWithUser($permissions, $capabilities);
        test()->actingAs($fixture['user']);

        return $fixture;
    }
}

if (! function_exists('seedLabTestDefinition')) {
    /**
     * @param  array<string, mixed>  $overrides
     */
    function seedLabTestDefinition(string $tenantId, array $overrides = []): LabTestDefinition
    {
        return TenantContextScope::run($tenantId, null, fn () => LabTestDefinition::factory()
            ->forTenant($tenantId)
            ->create($overrides));
    }
}

if (! function_exists('seedLabPanel')) {
    /**
     * @param  list<string>  $testDefinitionIds
     */
    function seedLabPanel(string $tenantId, string $code, array $testDefinitionIds, bool $active = true): string
    {
        return TenantContextScope::run($tenantId, null, function () use ($tenantId, $code, $testDefinitionIds, $active): string {
            $panel = LabPanel::factory()->forTenant($tenantId)->withCode($code)->create(['active' => $active]);
            $sequence = 1;
            foreach ($testDefinitionIds as $definitionId) {
                LabPanelMember::factory()->forPanel((string) $panel->id)->forDefinition($definitionId)
                    ->create(['tenant_id' => $tenantId, 'sequence' => $sequence++]);
            }

            return (string) $panel->id;
        });
    }
}

if (! function_exists('seedActiveLabEncounter')) {
    /**
     * Create an ARRIVED-or-ACTIVE encounter eligible for a laboratory order.
     *
     * @return array{encounterId: string, patientId: string}
     */
    function seedActiveLabEncounter(string $tenantId, string $facilityId, string $actorId, bool $started = true, bool $planned = false): array
    {
        $patientId = TenantContextScope::run($tenantId, null, fn () => (string) Patient::factory()
            ->forTenant($tenantId)
            ->create()->id);

        $encounterId = (string) Str::uuid7();

        TenantContextScope::run($tenantId, $facilityId, function () use ($encounterId, $patientId, $actorId, $started, $planned): void {
            app(CommandBus::class)->dispatch(new CreateEncounter(
                encounterId: $encounterId,
                patientId: $patientId,
                actorId: $actorId,
                encounterType: EncounterType::Outpatient,
                planned: $planned,
            ));

            if ($started && ! $planned) {
                app(CommandBus::class)->dispatch(new StartEncounter(
                    encounterId: $encounterId,
                    actorId: $actorId,
                ));
            }
        });

        return ['encounterId' => $encounterId, 'patientId' => $patientId];
    }
}

if (! function_exists('seedAcceptedLabOrder')) {
    /**
     * Create a lab order and drive it to ACCEPTED, ready for specimen collection.
     *
     * @return array{orderId: string, encounterId: string, patientId: string, definitionId: string}
     */
    function seedAcceptedLabOrder(string $tenantId, string $facilityId, string $actorId): array
    {
        $definition = seedLabTestDefinition($tenantId);
        ['encounterId' => $encounterId, 'patientId' => $patientId] =
            seedActiveLabEncounter($tenantId, $facilityId, $actorId);

        $orderId = (string) Str::uuid7();
        TenantContextScope::run($tenantId, $facilityId, function () use ($orderId, $actorId, $patientId, $encounterId, $definition): void {
            $bus = app(CommandBus::class);
            $bus->dispatch(new CreateLabOrder(
                orderId: $orderId,
                actorId: $actorId,
                patientId: $patientId,
                encounterId: $encounterId,
                testDefinitionIds: [(string) $definition->id],
            ));
            $bus->dispatch(new SubmitLabOrder(orderId: $orderId, actorId: $actorId));
            $bus->dispatch(new AcceptLabOrder(orderId: $orderId, actorId: $actorId));
        });

        return [
            'orderId' => $orderId,
            'encounterId' => $encounterId,
            'patientId' => $patientId,
            'definitionId' => (string) $definition->id,
        ];
    }
}

if (! function_exists('seedResultReadyLabOrder')) {
    /**
     * Create a lab order, collect + receive + accept a specimen, so the order
     * is PROCESSING and ready for RecordResult.
     *
     * @return array{orderId: string, specimenId: string, orderItemId: string, patientId: string}
     */
    function seedResultReadyLabOrder(string $tenantId, string $facilityId, string $actorId): array
    {
        ['orderId' => $orderId, 'patientId' => $patientId] = seedAcceptedLabOrder($tenantId, $facilityId, $actorId);

        $orderItemId = (string) TenantContextScope::run($tenantId, $facilityId, fn () => LabOrderItem::query()->where('order_id', $orderId)->value('id'));
        $specimenId = (string) Str::uuid7();

        TenantContextScope::run($tenantId, $facilityId, function () use ($specimenId, $actorId, $orderId): void {
            $bus = app(CommandBus::class);
            $bus->dispatch(new CollectSpecimen(specimenId: $specimenId, actorId: $actorId, orderId: $orderId, specimenType: 'WHOLE_BLOOD'));
            $bus->dispatch(new ReceiveSpecimen(specimenId: $specimenId, actorId: $actorId));
            $bus->dispatch(new AcceptSpecimen(specimenId: $specimenId, actorId: $actorId));
        });

        return ['orderId' => $orderId, 'specimenId' => $specimenId, 'orderItemId' => $orderItemId, 'patientId' => $patientId];
    }
}
