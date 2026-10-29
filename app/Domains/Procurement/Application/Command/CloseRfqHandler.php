<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Application\Command;

use App\Domains\Procurement\Domain\Model\Quotation;
use App\Domains\Procurement\Domain\QuotationRepository;
use App\Domains\Procurement\Domain\RfqRepository;
use App\Domains\Procurement\Domain\Service\RfqLifecycle;

/**
 * Orchestrates `CloseRfq` (ADR-173 D-D). ISSUED → CLOSED, and every RECEIVED
 * quotation → SUBMITTED (locked — no more revisions). One bus transaction;
 * `lockAllForRfq` is the consistency point against concurrent
 * `RecordQuotation` / `ReviseQuotation`.
 */
final class CloseRfqHandler
{
    public function __construct(
        private readonly RfqRepository $rfqs,
        private readonly QuotationRepository $quotations,
        private readonly RfqLifecycle $lifecycle,
    ) {}

    public function __invoke(CloseRfq $command): void
    {
        $rfq = $this->rfqs->getByIdForUpdate($command->rfqId);

        $this->lifecycle->assertCanClose((string) $rfq->status);

        $rfq->markClosed();
        $this->rfqs->save($rfq);

        foreach ($this->quotations->lockAllForRfq((string) $rfq->id) as $quotation) {
            if ((string) $quotation->status === Quotation::STATUS_RECEIVED) {
                $quotation->markSubmitted();
                $this->quotations->save($quotation);
            }
        }
    }
}
