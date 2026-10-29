<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Application\Command;

use App\Domains\Theatre\Domain\Service\SurgicalProcedureLifecycle;
use App\Domains\Theatre\Domain\SurgicalProcedureRepository;

final class CompleteProcedureHandler
{
    public function __construct(
        private readonly SurgicalProcedureRepository $procedures,
        private readonly SurgicalProcedureLifecycle $lifecycle,
    ) {}

    public function __invoke(CompleteProcedure $command): void
    {
        $procedure = $this->procedures->getById($command->procedureId);

        $this->lifecycle->assertCanComplete((string) $procedure->status);

        $procedure->complete(
            $command->actualDescription !== null ? trim($command->actualDescription) : null,
            $command->outcome !== null ? trim($command->outcome) : null,
            $command->findings !== null && trim($command->findings) !== '' ? trim($command->findings) : null,
            $command->complications !== null && trim($command->complications) !== '' ? trim($command->complications) : null,
        );

        $this->procedures->save($procedure);
    }
}
