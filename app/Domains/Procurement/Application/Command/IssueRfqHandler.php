<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Application\Command;

use App\Domains\Procurement\Domain\RfqRepository;
use App\Domains\Procurement\Domain\Service\RfqLifecycle;

final class IssueRfqHandler
{
    public function __construct(
        private readonly RfqRepository $rfqs,
        private readonly RfqLifecycle $lifecycle,
    ) {}

    public function __invoke(IssueRfq $command): void
    {
        $rfq = $this->rfqs->getByIdForUpdate($command->rfqId);

        $this->lifecycle->assertCanIssue((string) $rfq->status);

        $rfq->markIssued();

        $this->rfqs->save($rfq);
    }
}
