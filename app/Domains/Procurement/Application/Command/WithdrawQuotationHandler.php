<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Application\Command;

use App\Domains\Procurement\Domain\QuotationRepository;
use App\Domains\Procurement\Domain\RfqRepository;
use App\Domains\Procurement\Domain\Service\QuotationLifecycle;
use App\Domains\Procurement\Domain\Service\RfqLifecycle;

final class WithdrawQuotationHandler
{
    public function __construct(
        private readonly QuotationRepository $quotations,
        private readonly RfqRepository $rfqs,
        private readonly QuotationLifecycle $lifecycle,
        private readonly RfqLifecycle $rfqLifecycle,
    ) {}

    public function __invoke(WithdrawQuotation $command): void
    {
        $quotation = $this->quotations->getByIdForUpdate($command->quotationId);

        $this->lifecycle->assertCanWithdraw((string) $quotation->status);

        $rfq = $this->rfqs->getById((string) $quotation->rfq_id);
        $this->rfqLifecycle->assertOpenForQuotation((string) $rfq->id, (string) $rfq->status);

        $quotation->markWithdrawn();

        $this->quotations->save($quotation);
    }
}
