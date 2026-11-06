<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Application\Command;

use App\Domains\Laboratory\Domain\LabExecutionRepository;
use App\Domains\Laboratory\Domain\LabFacilityInstrumentReader;
use App\Domains\Laboratory\Domain\LabOrderRepository;
use App\Domains\Laboratory\Domain\LabTestDefinitionReader;
use App\Domains\Laboratory\Domain\Model\LabExecution;
use App\Domains\Laboratory\Domain\Model\LabExecutionMaterialRequirement;
use App\Domains\Laboratory\Domain\Model\LabMethodInventoryRequirement;
use App\Domains\Laboratory\Domain\Model\LabOrder;
use App\Domains\Laboratory\Domain\Model\LabOrderItem;
use App\Domains\Laboratory\Domain\Model\LabSpecimen;
use App\Domains\Laboratory\Domain\Model\LabTestDefinition;
use App\Domains\Laboratory\Domain\Model\LabTestMethod;
use App\Shared\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

final class StartLabExecutionHandler
{
    public function __construct(
        private readonly LabOrderRepository $orders,
        private readonly LabExecutionRepository $executions,
        private readonly LabFacilityInstrumentReader $instruments,
        private readonly LabTestDefinitionReader $definitions,
        private readonly TenantContext $context,
    ) {}

    public function __invoke(StartLabExecution $command): LabExecution
    {
        $existing = LabExecution::query()->whereKey($command->executionId)->first();
        if ($existing !== null) {
            if ((string) $existing->order_id !== $command->orderId
                || (string) $existing->order_item_id !== $command->orderItemId
                || (string) $existing->specimen_id !== $command->specimenId
                || (string) $existing->method_id !== $command->methodId
                || $existing->instrument_id !== $command->instrumentId) {
                throw ValidationException::withMessages([
                    'execution_id' => 'This execution identifier has already been used.',
                ]);
            }

            return $existing;
        }

        $order = $this->orders->getByIdForUpdate($command->orderId);
        $facilityId = $this->context->requireFacilityId();
        if ((string) $order->facility_id !== $facilityId) {
            throw ValidationException::withMessages([
                'order_item_id' => 'The order is not in the active laboratory facility.',
            ]);
        }

        if ($order->status !== LabOrder::STATUS_PROCESSING) {
            throw ValidationException::withMessages([
                'order_item_id' => 'A specimen must be accepted before test execution can start.',
            ]);
        }

        $item = LabOrderItem::query()
            ->whereKey($command->orderItemId)
            ->where('order_id', $order->id)
            ->lockForUpdate()
            ->first();

        if ($item === null || $item->status !== LabOrderItem::STATUS_ORDERED) {
            throw ValidationException::withMessages([
                'order_item_id' => 'The selected test line is unavailable for execution.',
            ]);
        }

        if (LabExecution::query()
            ->where('order_item_id', $item->id)
            ->where('status', LabExecution::STATUS_COMPLETED)
            ->exists()) {
            throw ValidationException::withMessages([
                'order_item_id' => 'This test line already has a completed execution.',
            ]);
        }

        $specimen = LabSpecimen::query()
            ->whereKey($command->specimenId)
            ->where('order_id', $order->id)
            ->where('facility_id', $facilityId)
            ->where('status', LabSpecimen::STATUS_ACCEPTED)
            ->first();

        if ($specimen === null) {
            throw ValidationException::withMessages([
                'specimen_id' => 'Select an accepted specimen belonging to this order.',
            ]);
        }

        $definition = $this->definitions->summary((string) $item->test_definition_id);
        if (! $definition->active || $definition->availabilityStatus !== LabTestDefinition::AVAILABILITY_AVAILABLE) {
            throw ValidationException::withMessages([
                'order_item_id' => 'The test definition is not currently available for execution.',
            ]);
        }

        $method = LabTestMethod::query()
            ->whereKey($command->methodId)
            ->where('test_definition_id', $item->test_definition_id)
            ->where('active', true)
            ->first();

        if ($method === null) {
            throw ValidationException::withMessages([
                'method_id' => 'Select an active method configured for this test.',
            ]);
        }

        $requiredSpecimenType = $method->specimen_type ?? $definition->specimenType;
        if ($requiredSpecimenType !== null
            && strtoupper(trim($requiredSpecimenType)) !== strtoupper(trim((string) $specimen->specimen_type))) {
            throw ValidationException::withMessages([
                'specimen_id' => 'The selected specimen type does not match the configured test method.',
            ]);
        }

        $requiredContainerType = $method->container_type ?? $definition->containerType;
        if ($requiredContainerType !== null
            && strtoupper(trim($requiredContainerType)) !== strtoupper(trim((string) $specimen->container_type))) {
            throw ValidationException::withMessages([
                'specimen_id' => 'The selected specimen container does not match the configured test method.',
            ]);
        }

        $instrument = null;
        if ($command->instrumentId !== null) {
            $instrument = $this->instruments->activeForFacility($command->instrumentId, $facilityId);
            if (! $this->instruments->supportsMethod($command->instrumentId, (string) $method->id)) {
                throw ValidationException::withMessages([
                    'instrument_id' => 'The selected instrument is not configured for this active method.',
                ]);
            }
        }

        if (LabExecution::query()
            ->where('order_item_id', $item->id)
            ->where('status', LabExecution::STATUS_IN_PROGRESS)
            ->exists()) {
            throw ValidationException::withMessages([
                'order_item_id' => 'This test line already has an execution in progress.',
            ]);
        }

        $attemptNumber = (int) LabExecution::query()
            ->where('order_item_id', $item->id)
            ->max('attempt_number') + 1;
        $execution = new LabExecution([
            'id' => $command->executionId,
            'tenant_id' => $this->context->tenantId(),
            'facility_id' => $facilityId,
            'order_id' => (string) $order->id,
            'order_item_id' => (string) $item->id,
            'specimen_id' => (string) $specimen->id,
            'attempt_number' => $attemptNumber,
            'test_definition_id' => (string) $item->test_definition_id,
            'test_code_snapshot' => (string) $item->test_code,
            'test_name_snapshot' => (string) $item->test_name,
            'test_definition_version' => (int) $item->test_definition_version,
            'method_id' => (string) $method->id,
            'method_code_snapshot' => (string) $method->code,
            'method_name_snapshot' => (string) $method->name,
            'method_version' => (int) $method->version,
            'instrument_id' => $instrument?->instrumentId,
            'instrument_identifier_snapshot' => $instrument?->identifier,
            'instrument_version' => $instrument?->version,
            'status' => LabExecution::STATUS_IN_PROGRESS,
            'started_by_user_id' => $command->actorId,
            'started_at' => CarbonImmutable::now(),
        ]);
        $this->executions->save($execution);

        $requirements = LabMethodInventoryRequirement::query()
            ->where('method_id', $method->id)
            ->where('method_version', $method->version)
            ->orderBy('id')
            ->get();

        foreach ($requirements as $requirement) {
            LabExecutionMaterialRequirement::query()->create([
                'tenant_id' => $this->context->tenantId(),
                'execution_id' => $execution->id,
                'method_requirement_id' => $requirement->id,
                'method_version' => (int) $requirement->method_version,
                'inventory_item_id' => (string) $requirement->inventory_item_id,
                'item_code_snapshot' => (string) $requirement->item_code_snapshot,
                'item_name_snapshot' => (string) $requirement->item_name_snapshot,
                'item_uom_snapshot' => (string) $requirement->item_uom_snapshot,
                'item_type_snapshot' => (string) $requirement->item_type_snapshot,
                'item_category_snapshot' => $requirement->item_category_snapshot,
                'requirement_role' => (string) $requirement->requirement_role,
                'required' => (bool) $requirement->required,
                'usage_scope' => (string) $requirement->usage_scope,
                'quantity' => (string) $requirement->quantity,
            ]);
        }

        return $execution;
    }
}
