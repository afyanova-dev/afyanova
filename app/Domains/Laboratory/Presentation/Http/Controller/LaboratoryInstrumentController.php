<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Presentation\Http\Controller;

use App\Domains\Inventory\Contracts\Dto\InventoryItemRef;
use App\Domains\Inventory\Contracts\InventoryApi;
use App\Domains\Laboratory\Application\LaboratoryInstrumentWriter;
use App\Domains\Laboratory\Application\Query\GetFacilityLabInstruments;
use App\Domains\Laboratory\Application\Query\GetLabTestDefinitions;
use App\Domains\Laboratory\Application\Query\GetLabTestMethods;
use App\Domains\Laboratory\Contracts\Dto\LabFacilityInstrumentSummary;
use App\Domains\Laboratory\Contracts\Dto\LabTestDefinitionSummary;
use App\Domains\Laboratory\Contracts\Dto\LabTestMethodSummary;
use App\Domains\Laboratory\Presentation\Http\Request\SaveLabFacilityInstrumentRequest;
use App\Shared\Bus\QueryBus;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

final class LaboratoryInstrumentController
{
    public function __construct(
        private readonly QueryBus $queries,
        private readonly LaboratoryInstrumentWriter $writer,
        private readonly InventoryApi $inventory,
        private readonly TenantContext $context,
    ) {}

    public function index(): Response
    {
        /** @var list<LabFacilityInstrumentSummary> $instruments */
        $instruments = $this->queries->dispatch(new GetFacilityLabInstruments);
        /** @var list<LabTestDefinitionSummary> $definitions */
        $definitions = $this->queries->dispatch(new GetLabTestDefinitions);
        /** @var list<LabTestMethodSummary> $methods */
        $methods = $this->queries->dispatch(new GetLabTestMethods);

        $availableDefinitionIds = [];
        foreach ($definitions as $definition) {
            if ($definition->active && $definition->availabilityStatus === 'AVAILABLE') {
                $availableDefinitionIds[$definition->definitionId] = true;
            }
        }

        $equipmentItems = $this->inventory->getItems('EQUIPMENT', true);
        usort(
            $equipmentItems,
            static fn (InventoryItemRef $left, InventoryItemRef $right): int => [$left->name, $left->code] <=> [$right->name, $right->code],
        );

        return Inertia::render('Laboratory/Instruments', [
            'facilityId' => $this->context->requireFacilityId(),
            'instruments' => $instruments,
            'methods' => array_values(array_filter(
                $methods,
                static fn (LabTestMethodSummary $method): bool => $method->active
                    && isset($availableDefinitionIds[$method->testDefinitionId]),
            )),
            'equipmentItems' => array_map(static fn (InventoryItemRef $item): array => [
                'itemId' => $item->itemId,
                'code' => $item->code,
                'name' => $item->name,
            ], $equipmentItems),
        ]);
    }

    public function store(SaveLabFacilityInstrumentRequest $request): RedirectResponse
    {
        $this->writer->create(
            $request->dataForCreate(),
            (string) $request->user()?->getAuthIdentifier(),
        );

        return back()->with('status', 'Laboratory instrument registered for this facility.');
    }

    public function update(
        SaveLabFacilityInstrumentRequest $request,
        string $instrument,
    ): RedirectResponse {
        $this->writer->update(
            $instrument,
            $request->dataForUpdate(),
            (string) $request->user()?->getAuthIdentifier(),
        );

        return back()->with('status', 'Laboratory instrument updated.');
    }
}
