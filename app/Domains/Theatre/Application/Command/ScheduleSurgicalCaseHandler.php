<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Application\Command;

use App\Domains\Theatre\Domain\Service\SurgicalCaseLifecycle;
use App\Domains\Theatre\Domain\SurgicalCaseRepository;
use Carbon\CarbonImmutable;

final class ScheduleSurgicalCaseHandler
{
    public function __construct(
        private readonly SurgicalCaseRepository $cases,
        private readonly SurgicalCaseLifecycle $lifecycle,
    ) {}

    public function __invoke(ScheduleSurgicalCase $command): void
    {
        $case = $this->cases->getById($command->caseId);

        $this->lifecycle->assertCanSchedule((string) $case->status);

        $case->schedule(CarbonImmutable::parse($command->scheduledFor));

        $this->cases->save($case);
    }
}
