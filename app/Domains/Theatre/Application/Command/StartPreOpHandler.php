<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Application\Command;

use App\Domains\Theatre\Domain\Service\SurgicalCaseLifecycle;
use App\Domains\Theatre\Domain\SurgicalCaseRepository;

final class StartPreOpHandler
{
    public function __construct(
        private readonly SurgicalCaseRepository $cases,
        private readonly SurgicalCaseLifecycle $lifecycle,
    ) {}

    public function __invoke(StartPreOp $command): void
    {
        $case = $this->cases->getById($command->caseId);

        $this->lifecycle->assertCanStartPreOp((string) $case->status);

        $case->startPreOp();

        $this->cases->save($case);
    }
}
