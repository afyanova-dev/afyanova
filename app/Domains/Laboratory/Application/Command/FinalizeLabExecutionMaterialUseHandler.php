<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Application\Command;

use App\Domains\Laboratory\Domain\Exception\IllegalLabExecutionTransition;
use App\Domains\Laboratory\Domain\LabExecutionRepository;
use App\Domains\Laboratory\Domain\Model\LabExecutionMaterialUse;
use App\Shared\Domain\NotFound;
use Carbon\CarbonImmutable;

final class FinalizeLabExecutionMaterialUseHandler
{
    public function __construct(private readonly LabExecutionRepository $executions) {}

    public function __invoke(FinalizeLabExecutionMaterialUse $command): LabExecutionMaterialUse
    {
        $this->executions->getByIdForUpdate($command->executionId);
        $use = LabExecutionMaterialUse::query()
            ->whereKey($command->useId)
            ->where('execution_id', $command->executionId)
            ->lockForUpdate()
            ->first()
            ?? throw NotFound::aggregate(LabExecutionMaterialUse::class, $command->useId);

        if ($command->outcome === FinalizeLabExecutionMaterialUse::OUTCOME_CONSUMED) {
            $use->markConsumed(CarbonImmutable::now());
        } elseif ($use->status !== LabExecutionMaterialUse::STATUS_FAILED) {
            if ($use->status === LabExecutionMaterialUse::STATUS_CONSUMED) {
                throw IllegalLabExecutionTransition::fromMaterialUseStatus((string) $use->status);
            }

            $use->markFailed((string) $command->failureMessage, CarbonImmutable::now());
        }

        $use->save();

        return $use;
    }
}
