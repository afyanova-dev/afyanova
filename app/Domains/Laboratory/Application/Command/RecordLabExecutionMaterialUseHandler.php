<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Application\Command;

use App\Domains\Laboratory\Domain\LabExecutionRepository;
use App\Domains\Laboratory\Domain\Model\LabExecution;
use App\Domains\Laboratory\Domain\Model\LabExecutionMaterialRequirement;
use App\Domains\Laboratory\Domain\Model\LabExecutionMaterialUse;
use App\Shared\Domain\NotFound;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Validation\ValidationException;

final class RecordLabExecutionMaterialUseHandler
{
    public function __construct(
        private readonly LabExecutionRepository $executions,
        private readonly TenantContext $context,
    ) {}

    public function __invoke(RecordLabExecutionMaterialUse $command): LabExecutionMaterialUse
    {
        $execution = $this->executions->getByIdForUpdate($command->executionId);
        $requirement = LabExecutionMaterialRequirement::query()
            ->whereKey($command->requirementId)
            ->where('execution_id', $execution->id)
            ->lockForUpdate()
            ->first()
            ?? throw NotFound::aggregate(LabExecutionMaterialRequirement::class, $command->requirementId);

        $existing = LabExecutionMaterialUse::query()->whereKey($command->useId)->first();
        if ($existing !== null) {
            if ((string) $existing->execution_id !== (string) $execution->id
                || (string) $existing->requirement_id !== (string) $requirement->id) {
                throw ValidationException::withMessages([
                    'use_id' => 'This material-use identifier is already assigned to another record.',
                ]);
            }

            return $existing;
        }

        $pending = LabExecutionMaterialUse::query()
            ->where('requirement_id', $requirement->id)
            ->where('status', LabExecutionMaterialUse::STATUS_PENDING)
            ->orderByDesc('attempt_number')
            ->first();
        if ($pending !== null) {
            return $pending;
        }

        if ($execution->status !== LabExecution::STATUS_IN_PROGRESS) {
            throw ValidationException::withMessages([
                'execution' => 'Materials can only be recorded for an execution in progress.',
            ]);
        }

        $attemptNumber = (int) LabExecutionMaterialUse::query()
            ->where('requirement_id', $requirement->id)
            ->max('attempt_number') + 1;

        return LabExecutionMaterialUse::query()->create([
            'id' => $command->useId,
            'tenant_id' => $this->context->tenantId(),
            'execution_id' => $execution->id,
            'requirement_id' => $requirement->id,
            'attempt_number' => $attemptNumber,
            'movement_id' => $command->useId,
            'inventory_item_id' => (string) $requirement->inventory_item_id,
            'location_id' => $command->locationId,
            'batch_number' => $command->batchNumber !== null ? trim($command->batchNumber) : null,
            'quantity' => $command->quantity,
            'status' => LabExecutionMaterialUse::STATUS_PENDING,
            'recorded_by_user_id' => $command->actorId,
        ]);
    }
}
