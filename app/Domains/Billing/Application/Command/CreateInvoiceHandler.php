<?php

declare(strict_types=1);

namespace App\Domains\Billing\Application\Command;

use App\Domains\Billing\Contracts\Dto\ChargeSummary;
use App\Domains\Billing\Domain\BillingCatalogReader;
use App\Domains\Billing\Domain\ChargeReader;
use App\Domains\Billing\Domain\Exception\BillingServiceNotFound;
use App\Domains\Billing\Domain\Exception\InvoiceChargeConflict;
use App\Domains\Billing\Domain\Exception\NoChargesToInvoice;
use App\Domains\Billing\Domain\FinancialAccountRepository;
use App\Domains\Billing\Domain\InvoiceLineRepository;
use App\Domains\Billing\Domain\InvoiceRepository;
use App\Domains\Billing\Domain\Model\Invoice;
use App\Domains\Billing\Domain\Model\InvoiceLine;
use App\Domains\Billing\Domain\Money;
use App\Domains\Billing\Domain\ValueObject\ChargeStatus;
use App\Shared\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;

/**
 * Orchestrates `CreateInvoice` (ADR-190 D-C).
 *
 * load the account (RLS / existence) → eligible = POSTED charges for the account
 * (`ChargeReader::forAccount`), optionally filtered to `chargeIds`, minus those
 * already claimed by a live invoice line → zero → `NoChargesToInvoice` → DRAFT
 * Invoice + one line per eligible charge (snapshot from the `ChargeSummary`, the
 * description re-read from `BillingCatalogReader`; on the partial-unique race →
 * `InvoiceChargeConflict`) → `recomputeTotals`. NO event.
 *
 * `bill_charges` is never touched (INV-2) — the handler reads `ChargeSummary`s
 * and never loads a `Charge` aggregate.
 */
final class CreateInvoiceHandler
{
    public function __construct(
        private readonly InvoiceRepository $invoices,
        private readonly InvoiceLineRepository $lines,
        private readonly FinancialAccountRepository $accounts,
        private readonly ChargeReader $charges,
        private readonly BillingCatalogReader $catalog,
        private readonly TenantContext $context,
    ) {}

    public function __invoke(CreateInvoice $command): void
    {
        $account = $this->accounts->getById($command->financialAccountId);

        $candidates = array_values(array_filter(
            $this->charges->forAccount($command->financialAccountId),
            fn (ChargeSummary $c): bool => $c->status === ChargeStatus::POSTED
                && ($command->chargeIds === null || in_array($c->chargeId, $command->chargeIds, true)),
        ));

        $claimed = $this->lines->liveClaimedChargeIds(array_map(static fn (ChargeSummary $c): string => $c->chargeId, $candidates));

        $eligible = array_values(array_filter(
            $candidates,
            static fn (ChargeSummary $c): bool => ! in_array($c->chargeId, $claimed, true),
        ));

        if ($eligible === []) {
            throw NoChargesToInvoice::forAccount($command->financialAccountId);
        }

        $now = CarbonImmutable::now();

        $invoice = Invoice::open(
            id: $command->invoiceId,
            tenantId: $this->context->tenantId(),
            facilityId: (string) $account->facility_id,
            financialAccountId: $command->financialAccountId,
            patientId: (string) $account->patient_id,
            currency: 'TZS',
            occurredAt: $now,
            recordedAt: $now,
        );

        $gross = '0';
        $added = 0;

        foreach ($eligible as $charge) {
            if ($this->lines->liveLineExistsForCharge($charge->chargeId)) {
                continue;
            }

            $quantity = $charge->quantity;
            if (! is_numeric($quantity)) {
                $quantity = '0';
            }

            $line = InvoiceLine::add(
                id: (string) Str::uuid7(),
                tenantId: $this->context->tenantId(),
                invoiceId: $command->invoiceId,
                chargeId: $charge->chargeId,
                description: $this->descriptionFor($charge),
                billingServiceCode: $charge->billingServiceCode,
                quantity: $quantity,
                unitPrice: Money::of($charge->unitPrice),
                amount: Money::of($charge->amount),
                serviceCategory: $this->categoryFor($charge->billingServiceId),
                occurredAt: $now,
                recordedAt: $now,
            );

            try {
                $this->lines->save($line);
            } catch (UniqueConstraintViolationException) {
                throw InvoiceChargeConflict::forCharge($charge->chargeId);
            }

            $gross = Money::add($gross, $charge->amount);
            $added++;
        }

        if ($added === 0) {
            throw NoChargesToInvoice::forAccount($command->financialAccountId);
        }

        $invoice->recomputeTotals($gross);
        $this->invoices->save($invoice);
    }

    private function descriptionFor(ChargeSummary $charge): string
    {
        try {
            return $this->catalog->serviceSummary($charge->billingServiceId)->name;
        } catch (BillingServiceNotFound) {
            return $charge->billingServiceCode;
        }
    }

    private function categoryFor(string $billingServiceId): ?string
    {
        try {
            return $this->catalog->serviceSummary($billingServiceId)->category;
        } catch (BillingServiceNotFound) {
            return null;
        }
    }
}
