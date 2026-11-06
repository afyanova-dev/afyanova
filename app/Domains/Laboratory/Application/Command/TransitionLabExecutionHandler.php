<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Application\Command;

use App\Domains\Inventory\Domain\Quantity;
use App\Domains\Laboratory\Domain\Exception\IllegalLabExecutionTransition;
use App\Domains\Laboratory\Domain\LabExecutionRepository;
use App\Domains\Laboratory\Domain\Model\LabExecution;
use App\Domains\Laboratory\Domain\Model\LabExecutionMaterialRequirement;
use App\Domains\Laboratory\Domain\Model\LabExecutionMaterialUse;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

final class TransitionLabExecutionHandler
{
    public function __construct(private readonly LabExecutionRepository $executions) {}

    public function __invoke(TransitionLabExecution $command): LabExecution
    {
        $execution = $this->executions->getByIdForUpdate($command->executionId);

        if ($execution->status !== LabExecution::STATUS_IN_PROGRESS) {
            throw IllegalLabExecutionTransition::fromStatus((string) $execution->status);
        }

        if (LabExecutionMaterialUse::query()
            ->where('execution_id', $execution->id)
            ->where('status', LabExecutionMaterialUse::STATUS_PENDING)
            ->exists()) {
            throw ValidationException::withMessages([
                'materials' => 'Resolve pending Inventory movements before closing this execution.',
            ]);
        }

        if ($command->targetStatus === TransitionLabExecution::TARGET_COMPLETED) {
            $requirements = LabExecutionMaterialRequirement::query()
                ->where('execution_id', $execution->id)
                ->where('required', true)
                ->get();

            foreach ($requirements as $requirement) {
                $uses = LabExecutionMaterialUse::query()
                    ->where('requirement_id', $requirement->id)
                    ->where('status', LabExecutionMaterialUse::STATUS_CONSUMED)
                    ->get(['quantity']);
                $consumedQuantity = '0.0000';
                foreach ($uses as $use) {
                    $consumedQuantity = Quantity::add($consumedQuantity, (string) $use->quantity);
                }

                if (Quantity::compare($consumedQuantity, (string) $requirement->quantity) < 0) {
                    throw ValidationException::withMessages([
                        'materials' => 'Record the required actual quantity for every required material before completing this test.',
                    ]);
                }
            }

            $execution->complete(CarbonImmutable::now());
        } else {
            $execution->abort((string) $command->reason, CarbonImmutable::now());
        }

        $this->executions->save($execution);

        return $execution;
    }
}
