<?php

declare(strict_types=1);

namespace App\Domains\Billing\Application;

use App\Domains\Billing\Application\Query\GetInvoice;
use App\Domains\Billing\Contracts\BillingApi;
use App\Domains\Billing\Contracts\Dto\FinancialAccountSummary;
use App\Domains\Billing\Contracts\Dto\InvoiceSummary;
use App\Domains\Billing\Contracts\Dto\OutstandingBalanceSummary;
use App\Domains\Billing\Domain\Exception\InvoiceNotFound;
use App\Domains\Billing\Domain\FinancialAccountReader;
use App\Domains\Billing\Domain\InvoiceReader;
use App\Shared\Bus\QueryBus;

/**
 * Thin port implementation (IC §5.2; ADR-187 D-H; ADR-204). `financialAccountExists`
 * delegates straight to the Reader — an unprotected reference-style read that
 * exposes no financial data (IC §6.2's reference-read carve-out), matching
 * `PatientApiService::patientExists` / `ClinicalApiService::clinicalNoteExists`.
 * `getInvoice` dispatches `GetInvoice` across QueryBus — protected by `billing.access`.
 */
final class BillingApiService implements BillingApi
{
    public function __construct(
        private readonly FinancialAccountReader $reader,
        private readonly InvoiceReader $invoiceReader,
        private readonly QueryBus $queryBus,
    ) {}

    public function financialAccountExists(string $financialAccountId): bool
    {
        return $this->reader->exists($financialAccountId);
    }

    public function getInvoice(string $invoiceId): ?InvoiceSummary
    {
        try {
            /** @var InvoiceSummary */
            return $this->queryBus->dispatch(new GetInvoice($invoiceId));
        } catch (InvoiceNotFound) {
            return null;
        }
    }

    public function activeAccountForPatientFacility(string $patientId, string $facilityId): ?FinancialAccountSummary
    {
        return $this->reader->activeForPatientFacility($patientId, $facilityId);
    }

    public function accountLedger(string $financialAccountId, int $limit = 25): array
    {
        return $this->reader->ledgerEntries($financialAccountId, max(1, min(100, $limit)));
    }

    public function accountOutstanding(string $financialAccountId): OutstandingBalanceSummary
    {
        return $this->reader->outstandingBalance($financialAccountId);
    }

    public function dispensingClearedForHandover(string $dispensingId): bool
    {
        return $this->invoiceReader->dispensingClearedForHandover($dispensingId);
    }
}
