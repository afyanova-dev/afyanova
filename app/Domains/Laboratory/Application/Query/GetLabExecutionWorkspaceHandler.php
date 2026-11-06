<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Application\Query;

use App\Domains\Laboratory\Contracts\Dto\LabExecutionQueueItemSummary;
use App\Domains\Laboratory\Contracts\Dto\LabFacilityInstrumentSummary;
use App\Domains\Laboratory\Contracts\Dto\LabTestDefinitionSummary;
use App\Domains\Laboratory\Contracts\Dto\LabTestMethodSummary;
use App\Domains\Laboratory\Domain\LabExecutionReader;
use App\Domains\Laboratory\Domain\LabFacilityInstrumentReader;
use App\Domains\Laboratory\Domain\LabOrder;
use App\Domains\Laboratory\Domain\LabTestDefinitionReader;
use App\Domains\Laboratory\Domain\LabTestMethodReader;
use App\Models\User;
use App\Shared\Authorization\ScopeChecker;
use App\Shared\Domain\NotFound;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

final class GetLabExecutionWorkspaceHandler
{
    public function __construct(
        private readonly LabExecutionReader $executions,
        private readonly LabFacilityInstrumentReader $instruments,
        private readonly LabTestDefinitionReader $definitions,
        private readonly LabTestMethodReader $methods,
        private readonly ScopeChecker $scope,
        private readonly TenantContext $context,
    ) {}

    /**
     * @return array{
     *     queue: list<LabExecutionQueueItemSummary>,
     *     methods: list<LabTestMethodSummary>,
     *     instruments: list<LabFacilityInstrumentSummary>
     * }
     */
    public function __invoke(GetLabExecutionWorkspace $query): array
    {
        $user = Auth::user();
        if (! $user instanceof User) {
            throw new RuntimeException('GetLabExecutionWorkspace dispatched without an authenticated user.');
        }

        if (! $this->scope->hasFacilityPermissionInContext(
            $user,
            $this->context,
            $query->facilityId,
            'laboratory.access',
        )) {
            throw NotFound::aggregate(LabOrder::class, $query->facilityId);
        }

        /** @var array<string, LabTestDefinitionSummary> $availableDefinitions */
        $availableDefinitions = [];
        foreach ($this->definitions->list(active: true) as $definition) {
            if ($definition->availabilityStatus === LabTestDefinitionSummary::AVAILABILITY_AVAILABLE) {
                $availableDefinitions[$definition->definitionId] = $definition;
            }
        }

        $methods = [];
        foreach ($this->methods->list() as $method) {
            $definition = $availableDefinitions[$method->testDefinitionId] ?? null;
            if (! $method->active || $definition === null) {
                continue;
            }

            $methods[] = new LabTestMethodSummary(
                methodId: $method->methodId,
                testDefinitionId: $method->testDefinitionId,
                code: $method->code,
                name: $method->name,
                description: $method->description,
                specimenType: $method->specimenType ?? $definition->specimenType,
                containerType: $method->containerType ?? $definition->containerType,
                active: $method->active,
                version: $method->version,
                requirements: $method->requirements,
            );
        }

        $instruments = array_values(array_filter(
            $this->instruments->forFacility($query->facilityId),
            static fn (LabFacilityInstrumentSummary $instrument): bool => $instrument->status === 'ACTIVE',
        ));

        return [
            'queue' => $this->executions->queueForFacility($query->facilityId),
            'methods' => $methods,
            'instruments' => $instruments,
        ];
    }
}
