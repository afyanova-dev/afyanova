<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Application\Command;

use App\Domains\Theatre\Domain\Service\SurgicalProcedureLifecycle;
use App\Domains\Theatre\Domain\SurgicalProcedureRepository;

final class AbortProcedureHandler
{
    public function __construct(
        private readonly SurgicalProcedureRepository $procedures,
        private readonly SurgicalProcedureLifecycle $lifecycle,
    ) {}

    public function __invoke(AbortProcedure $command): void
    {
        $procedure = $this->procedures->getById($command->procedureId);

        $this->lifecycle->assertCanAbort((string) $procedure->status);

        $procedure->abort(trim($command->reason));

        $this->procedures->save($procedure);
    }
}
