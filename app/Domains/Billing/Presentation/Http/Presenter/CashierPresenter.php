<?php

declare(strict_types=1);

namespace App\Domains\Billing\Presentation\Http\Presenter;

use App\Domains\Billing\Application\Query\GetAccountInvoices;
use App\Domains\Billing\Application\Query\GetAccountPayments;
use App\Domains\Billing\Application\Query\GetFacilityCashSessions;
use App\Domains\Billing\Application\Query\GetFinancialAccountByPatient;
use App\Domains\Billing\Application\Query\GetOutstandingBalance;
use App\Domains\Billing\Application\Query\GetUninvoicedCharges;
use App\Domains\Billing\Contracts\Dto\CashSessionSummary;
use App\Domains\Billing\Contracts\Dto\ChargeSummary;
use App\Domains\Billing\Contracts\Dto\FinancialAccountSummary;
use App\Domains\Billing\Contracts\Dto\InvoiceSummary;
use App\Domains\Billing\Contracts\Dto\OutstandingBalanceSummary;
use App\Domains\Billing\Contracts\Dto\PaymentSummary;
use App\Domains\Billing\Domain\BillingCatalogReader;
use App\Domains\Billing\Presentation\Http\DTO\CashierChargeItem;
use App\Domains\Billing\Presentation\Http\DTO\CashierDashboardProps;
use App\Domains\Billing\Presentation\Http\DTO\CashierInvoiceItem;
use App\Domains\Billing\Presentation\Http\DTO\CashierPatientResult;
use App\Domains\Billing\Presentation\Http\DTO\CashierPaymentItem;
use App\Domains\Billing\Presentation\Http\DTO\CashierSessionItem;
use App\Domains\Patient\Contracts\PatientApi;
use App\Shared\Bus\QueryBus;

/**
 * Cashier workspace read model (UI-SPECIFICATIONS.md §11.14, phase 1).
 * Composes Billing's own protected queries + the Patient published contract
 * for display identity. No money is computed here beyond re-presenting
 * reader-derived figures; the page renders them verbatim via `<Money>`.
 */
final class CashierPresenter
{
    public function __construct(
        private readonly QueryBus $queryBus,
        private readonly PatientApi $patientApi,
        private readonly BillingCatalogReader $catalog,
    ) {}

    /**
     * @param  array<string, bool>  $permissions  superposition flags resolved by the controller
     * @param  list<CashierPatientResult>|null  $searchResults
     */
    public function present(string $facilityId, string $cashierUserId, ?string $patientId, array $permissions, ?string $query = null, ?array $searchResults = null): CashierDashboardProps
    {
        $mySession = $this->mySession($facilityId, $cashierUserId);

        if ($patientId === null) {
            return new CashierDashboardProps(
                facilityId: $facilityId,
                mySession: $mySession,
                canManageSessions: $permissions['sessions'] ?? false,
                canManageInvoices: $permissions['invoices'] ?? false,
                canRecordPayments: $permissions['payments'] ?? false,
                query: $query,
                searchResults: $searchResults,
            );
        }

        try {
            $patient = $this->patientApi->getPatientSummary($patientId);
        } catch (\Throwable) {
            return new CashierDashboardProps(
                facilityId: $facilityId,
                mySession: $mySession,
                canManageSessions: $permissions['sessions'] ?? false,
                canManageInvoices: $permissions['invoices'] ?? false,
                canRecordPayments: $permissions['payments'] ?? false,
                searchError: 'Patient was not found.',
                query: $query,
                searchResults: $searchResults,
            );
        }

        /** @var FinancialAccountSummary|null */
        $account = $this->queryBus->dispatch(new GetFinancialAccountByPatient($patientId, $facilityId));

        if ($account === null) {
            return new CashierDashboardProps(
                facilityId: $facilityId,
                mySession: $mySession,
                canManageSessions: $permissions['sessions'] ?? false,
                canManageInvoices: $permissions['invoices'] ?? false,
                canRecordPayments: $permissions['payments'] ?? false,
                patientId: $patientId,
                patientDisplayName: $patient->displayName,
                patientNumber: $patient->patientNumber,
                searchError: 'No open financial account for this patient at this facility yet. It opens automatically with the first charge or payment.',
                query: $query,
                searchResults: $searchResults,
            );
        }

        /** @var OutstandingBalanceSummary */
        $balance = $this->queryBus->dispatch(new GetOutstandingBalance($account->financialAccountId));
        /** @var list<InvoiceSummary> */
        $invoices = $this->queryBus->dispatch(new GetAccountInvoices($account->financialAccountId));
        /** @var list<ChargeSummary> */
        $charges = $this->queryBus->dispatch(new GetUninvoicedCharges($account->financialAccountId));
        /** @var list<PaymentSummary> */
        $payments = $this->queryBus->dispatch(new GetAccountPayments($account->financialAccountId));

        return new CashierDashboardProps(
            facilityId: $facilityId,
            mySession: $mySession,
            canManageSessions: $permissions['sessions'] ?? false,
            canManageInvoices: $permissions['invoices'] ?? false,
            canRecordPayments: $permissions['payments'] ?? false,
            accountId: $account->financialAccountId,
            accountNumber: $account->accountNumber,
            patientId: $patientId,
            patientDisplayName: $patient->displayName,
            patientNumber: $patient->patientNumber,
            outstanding: $balance->outstanding,
            chargedTotal: $balance->chargedTotal,
            settledTotal: $balance->settledTotal,
            currency: $balance->currency,
            invoices: array_map(static fn (InvoiceSummary $i): CashierInvoiceItem => new CashierInvoiceItem(
                invoiceId: $i->invoiceId,
                invoiceNumber: $i->invoiceNumber,
                status: $i->status,
                net: $i->net,
                paidAmount: $i->paidAmount,
                paymentStatus: $i->paymentStatus,
                derivedOutstanding: $i->derivedOutstanding,
                currency: $i->currency,
                lineCount: count($i->lines),
                issuedAt: $i->issuedAt,
            ), $invoices),
            uninvoicedCharges: array_map(fn (ChargeSummary $c): CashierChargeItem => new CashierChargeItem(
                chargeId: $c->chargeId,
                description: $this->serviceName($c->billingServiceId, $c->billingServiceCode),
                quantity: $c->quantity,
                unitPrice: $c->unitPrice,
                amount: $c->amount,
                status: $c->status,
                currency: $c->currency,
                occurredAt: $c->occurredAt,
            ), $charges),
            payments: array_map(static fn (PaymentSummary $p): CashierPaymentItem => new CashierPaymentItem(
                paymentId: $p->paymentId,
                receiptNumber: $p->receiptNumber,
                method: $p->method,
                amount: $p->amount,
                status: $p->status,
                unallocatedAmount: $p->unallocatedAmount,
                currency: $p->currency,
                receivedAt: $p->receivedAt,
            ), $payments),
            query: $query,
            searchResults: $searchResults,
        );
    }

    private function mySession(string $facilityId, string $cashierUserId): ?CashierSessionItem
    {
        /** @var list<CashSessionSummary> */
        $sessions = $this->queryBus->dispatch(new GetFacilityCashSessions($facilityId));

        foreach ($sessions as $session) {
            if ($session->cashierUserId !== $cashierUserId) {
                continue;
            }

            return new CashierSessionItem(
                cashSessionId: $session->cashSessionId,
                status: $session->status,
                openingFloat: $session->openingFloat,
                cashPaymentsTotal: $session->cashPaymentsTotal,
                expectedCash: $session->expectedCash,
                countedCash: $session->countedCash,
                variance: $session->variance,
                currency: $session->currency,
                openedAt: $session->openedAt,
                closedAt: $session->closedAt,
            );
        }

        return null;
    }

    private function serviceName(string $billingServiceId, string $fallbackCode): string
    {
        try {
            return $this->catalog->serviceSummary($billingServiceId)->name;
        } catch (\Throwable) {
            return $fallbackCode;
        }
    }
}
