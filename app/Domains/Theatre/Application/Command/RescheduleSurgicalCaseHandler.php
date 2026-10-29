<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Application\Command;

use App\Domains\Theatre\Domain\Service\SurgicalCaseLifecycle;
use App\Domains\Theatre\Domain\SurgicalCaseRepository;
use Carbon\CarbonImmutable;

final class RescheduleSurgicalCaseHandler
{
    public function __construct(
        private readonly SurgicalCaseRepository $cases,
        private readonly SurgicalCaseLifecycle $lifecycle,
    ) {}

    public function __invoke(RescheduleSurgicalCase $command): void
    {
        $case = $this->cases->getById($command->caseId);

        $this->lifecycle->assertCanReschedule((string) $case->status);

        $case->reschedule(CarbonImmutable::parse($command->newScheduledFor));

        $this->cases->save($case);
    }
}
