<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Application\Command;

use App\Domains\Theatre\Domain\Service\SurgicalProcedureLifecycle;
use App\Domains\Theatre\Domain\SurgicalProcedureRepository;

final class StartProcedureHandler
{
    public function __construct(
        private readonly SurgicalProcedureRepository $procedures,
        private readonly SurgicalProcedureLifecycle $lifecycle,
    ) {}

    public function __invoke(StartProcedure $command): void
    {
        $procedure = $this->procedures->getById($command->procedureId);

        $this->lifecycle->assertCanStart((string) $procedure->status);

        $procedure->start($command->actualDescription !== null ? trim($command->actualDescription) : null);

        $this->procedures->save($procedure);
    }
}
