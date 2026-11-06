<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Presentation\Http\Controller;

use App\Domains\Inventory\Contracts\Dto\InventoryItemRef;
use App\Domains\Inventory\Contracts\InventoryApi;
use App\Domains\Laboratory\Application\LaboratoryCatalogWriter;
use App\Domains\Laboratory\Application\Query\GetLabPanels;
use App\Domains\Laboratory\Application\Query\GetLabTestDefinitions;
use App\Domains\Laboratory\Application\Query\GetLabTestMethods;
use App\Domains\Laboratory\Contracts\Dto\LabPanelSummary;
use App\Domains\Laboratory\Contracts\Dto\LabTestDefinitionSummary;
use App\Domains\Laboratory\Contracts\Dto\LabTestMethodSummary;
use App\Domains\Laboratory\Domain\Exception\LabPanelAlreadyExists;
use App\Domains\Laboratory\Domain\Exception\LabTestDefinitionAlreadyExists;
use App\Domains\Laboratory\Domain\Model\LabOrder;
use App\Domains\Laboratory\Presentation\Http\Request\SaveLabPanelRequest;
use App\Domains\Laboratory\Presentation\Http\Request\SaveLabTestDefinitionRequest;
use App\Domains\Laboratory\Presentation\Http\Request\SaveLabTestMethodRequest;
use App\Shared\Bus\QueryBus;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

final class LaboratoryCatalogController
{
    public function __construct(
        private readonly QueryBus $queries,
        private readonly LaboratoryCatalogWriter $writer,
        private readonly InventoryApi $inventory,
    ) {}

    public function index(Request $request): Response
    {
        /** @var list<LabTestDefinitionSummary> $definitions */
        $definitions = $this->queries->dispatch(new GetLabTestDefinitions);
        /** @var list<LabPanelSummary> $panels */
        $panels = $this->queries->dispatch(new GetLabPanels);
        /** @var list<LabTestMethodSummary> $methods */
        $methods = $this->queries->dispatch(new GetLabTestMethods);

        $inventoryItems = array_merge(
            $this->inventory->getItems('SUPPLY', true),
            $this->inventory->getItems('CONSUMABLE', true),
        );
        usort($inventoryItems, fn (InventoryItemRef $left, InventoryItemRef $right): int => [$left->name, $left->code] <=> [$right->name, $right->code]);

        return Inertia::render('Laboratory/Catalog', [
            'definitions' => $definitions,
            'panels' => $panels,
            'methods' => $methods,
            'inventoryItems' => array_map(fn (InventoryItemRef $item): array => [
                'itemId' => $item->itemId,
                'code' => $item->code,
                'name' => $item->name,
                'uom' => $item->uom,
                'category' => $item->category,
                'itemType' => $item->itemType,
            ], $inventoryItems),
            'canManageCatalog' => $request->user()?->can('manageCatalog', LabOrder::class) ?? false,
            'canManageInstruments' => $request->user()?->can('manageInstruments', LabOrder::class) ?? false,
        ]);
    }

    public function storeTest(SaveLabTestDefinitionRequest $request): RedirectResponse
    {
        try {
            $this->writer->createTest(
                $request->dataForCreate(),
                (string) ($request->user() ?? throw new AuthenticationException)->getAuthIdentifier(),
            );
        } catch (LabTestDefinitionAlreadyExists|InvalidArgumentException $exception) {
            return back()->withErrors(['catalog' => $exception->getMessage()]);
        }

        return back()->with('status', 'Laboratory test definition created.');
    }

    public function updateTest(SaveLabTestDefinitionRequest $request, string $definition): RedirectResponse
    {
        try {
            $this->writer->updateTest(
                $definition,
                $request->dataForUpdate(),
                (string) ($request->user() ?? throw new AuthenticationException)->getAuthIdentifier(),
            );
        } catch (InvalidArgumentException $exception) {
            return back()->withErrors(['catalog' => $exception->getMessage()]);
        }

        return back()->with('status', 'Laboratory test definition updated.');
    }

    public function storePanel(SaveLabPanelRequest $request): RedirectResponse
    {
        try {
            $this->writer->createPanel(
                $request->dataForCreate(),
                (string) ($request->user() ?? throw new AuthenticationException)->getAuthIdentifier(),
            );
        } catch (LabPanelAlreadyExists|InvalidArgumentException $exception) {
            return back()->withErrors(['catalog' => $exception->getMessage()]);
        }

        return back()->with('status', 'Laboratory panel created.');
    }

    public function updatePanel(SaveLabPanelRequest $request, string $panel): RedirectResponse
    {
        try {
            $this->writer->updatePanel(
                $panel,
                $request->dataForUpdate(),
                (string) ($request->user() ?? throw new AuthenticationException)->getAuthIdentifier(),
            );
        } catch (InvalidArgumentException $exception) {
            return back()->withErrors(['catalog' => $exception->getMessage()]);
        }

        return back()->with('status', 'Laboratory panel updated.');
    }

    public function storeMethod(SaveLabTestMethodRequest $request, string $definition): RedirectResponse
    {
        $this->writer->createTestMethod(
            $definition,
            $request->dataForCreate(),
            (string) ($request->user() ?? throw new AuthenticationException)->getAuthIdentifier(),
        );

        return back()->with('status', 'Laboratory test method created.');
    }

    public function updateMethod(SaveLabTestMethodRequest $request, string $definition, string $method): RedirectResponse
    {
        $this->writer->updateTestMethod(
            $definition,
            $method,
            $request->dataForUpdate(),
            (string) ($request->user() ?? throw new AuthenticationException)->getAuthIdentifier(),
        );

        return back()->with('status', 'Laboratory test method updated.');
    }
}
