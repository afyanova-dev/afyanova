<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Presentation\Http\Controller;

use App\Domains\Inventory\Contracts\Dto\StockLocationSummary;
use App\Domains\Inventory\Contracts\InventoryApi;
use App\Domains\Laboratory\Application\Command\RecordLabExecutionMaterialUse;
use App\Domains\Laboratory\Application\Command\StartLabExecution;
use App\Domains\Laboratory\Application\Command\TransitionLabExecution;
use App\Domains\Laboratory\Application\LaboratoryExecutionWorkflow;
use App\Domains\Laboratory\Application\Query\GetLabExecution;
use App\Domains\Laboratory\Application\Query\GetLabExecutionWorkspace;
use App\Domains\Laboratory\Contracts\Dto\LabExecutionSummary;
use App\Domains\Laboratory\Presentation\Http\Request\AbortLabExecutionRequest;
use App\Domains\Laboratory\Presentation\Http\Request\RecordLabExecutionMaterialUseRequest;
use App\Domains\Laboratory\Presentation\Http\Request\StartLabExecutionRequest;
use App\Shared\Bus\CommandBus;
use App\Shared\Bus\QueryBus;
use App\Shared\Domain\NotFound;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class LaboratoryExecutionController
{
    public function __construct(
        private readonly QueryBus $queries,
        private readonly CommandBus $commands,
        private readonly LaboratoryExecutionWorkflow $workflow,
        private readonly InventoryApi $inventory,
        private readonly TenantContext $context,
    ) {}

    public function index(): Response
    {
        $facilityId = $this->context->requireFacilityId();
        $workspace = $this->queries->dispatch(new GetLabExecutionWorkspace($facilityId));
        [$locations, $canViewLocations] = $this->stockLocations($facilityId);

        return Inertia::render('Laboratory/ExecutionWorklist', [
            ...$workspace,
            'stockLocations' => $locations,
            'canViewStockLocations' => $canViewLocations,
        ]);
    }

    public function show(string $execution): Response
    {
        /** @var LabExecutionSummary $summary */
        $summary = $this->queries->dispatch(new GetLabExecution($execution));
        [$locations, $canViewLocations] = $this->stockLocations($this->context->requireFacilityId());

        return Inertia::render('Laboratory/Execution', [
            'execution' => $summary,
            'stockLocations' => $locations,
            'canViewStockLocations' => $canViewLocations,
        ]);
    }

    public function store(StartLabExecutionRequest $request, string $order, string $item): RedirectResponse
    {
        $data = $request->validated();
        $execution = $this->commands->dispatch(new StartLabExecution(
            executionId: $data['execution_id'],
            actorId: (string) $request->user()?->getAuthIdentifier(),
            orderId: $order,
            orderItemId: $item,
            specimenId: $data['specimen_id'],
            methodId: $data['method_id'],
            instrumentId: $data['instrument_id'] ?? null,
        ));

        return redirect()->route('laboratory.executions.show', ['execution' => $execution->id]);
    }

    public function recordMaterialUse(
        RecordLabExecutionMaterialUseRequest $request,
        string $execution,
        string $requirement,
    ): RedirectResponse {
        $data = $request->validated();
        $this->workflow->recordMaterialUse(new RecordLabExecutionMaterialUse(
            useId: $data['use_id'],
            actorId: (string) $request->user()?->getAuthIdentifier(),
            executionId: $execution,
            requirementId: $requirement,
            locationId: $data['location_id'],
            quantity: (string) $data['quantity'],
            batchNumber: $data['batch_number'] ?? null,
        ));

        return back()->with('status', 'Actual material use recorded in Inventory.');
    }

    public function complete(Request $request, string $execution): RedirectResponse
    {
        $this->commands->dispatch(new TransitionLabExecution(
            executionId: $execution,
            actorId: (string) $request->user()?->getAuthIdentifier(),
            targetStatus: TransitionLabExecution::TARGET_COMPLETED,
        ));

        return back()->with('status', 'Laboratory execution completed.');
    }

    public function abort(AbortLabExecutionRequest $request, string $execution): RedirectResponse
    {
        $this->commands->dispatch(new TransitionLabExecution(
            executionId: $execution,
            actorId: (string) $request->user()?->getAuthIdentifier(),
            targetStatus: TransitionLabExecution::TARGET_ABORTED,
            reason: $request->validated('reason'),
        ));

        return back()->with('status', 'Laboratory execution stopped. Previously consumed stock was not restored.');
    }

    /**
     * @return array{list<StockLocationSummary>, bool}
     */
    private function stockLocations(string $facilityId): array
    {
        try {
            return [$this->inventory->getStockLocationSummaries($facilityId, true), true];
        } catch (AuthorizationException|NotFound) {
            return [[], false];
        }
    }
}
