<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Application\Command;

use App\Domains\Procurement\Domain\RfqRepository;
use App\Domains\Procurement\Domain\Service\RfqLifecycle;

final class CancelRfqHandler
{
    public function __construct(
        private readonly RfqRepository $rfqs,
        private readonly RfqLifecycle $lifecycle,
    ) {}

    public function __invoke(CancelRfq $command): void
    {
        $rfq = $this->rfqs->getByIdForUpdate($command->rfqId);

        $this->lifecycle->assertCanCancel((string) $rfq->status);

        $rfq->markCancelled($command->reason);

        $this->rfqs->save($rfq);
    }
}
