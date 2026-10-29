<?php

declare(strict_types=1);

namespace App\Domains\Billing\Application\Command;

use App\Domains\Billing\Domain\BillingCatalogReader;
use App\Domains\Billing\Domain\ChargeReader;
use App\Domains\Billing\Domain\Exception\BillingServiceNotFound;
use App\Domains\Billing\Domain\Exception\ChargeNotForInvoiceAccount;
use App\Domains\Billing\Domain\Exception\IllegalInvoiceTransition;
use App\Domains\Billing\Domain\Exception\InvoiceChargeConflict;
use App\Domains\Billing\Domain\Exception\InvoiceHasReversedLine;
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
 * Orchestrates `AddInvoiceLine` (ADR-190 D-C). DRAFT only. The charge must be
 * POSTED (REVERSED → `InvoiceHasReversedLine`), on the invoice's account
 * (`ChargeNotForInvoiceAccount`), and unclaimed (`InvoiceChargeConflict`).
 * Recomputes totals from the live lines.
 */
final class AddInvoiceLineHandler
{
    public function __construct(
        private readonly InvoiceRepository $invoices,
        private readonly InvoiceLineRepository $lines,
        private readonly ChargeReader $charges,
        private readonly BillingCatalogReader $catalog,
        private readonly TenantContext $context,
    ) {}

    public function __invoke(AddInvoiceLine $command): void
    {
        $invoice = $this->invoices->getByIdForUpdate($command->invoiceId);

        if (! $invoice->isDraft()) {
            throw IllegalInvoiceTransition::fromStatus('AddInvoiceLine', (string) $invoice->status);
        }

        $charge = $this->charges->summary($command->chargeId);

        if ($charge->status !== ChargeStatus::POSTED) {
            throw InvoiceHasReversedLine::forCharge($command->chargeId);
        }
        if ((string) $charge->financialAccountId !== (string) $invoice->financial_account_id) {
            throw ChargeNotForInvoiceAccount::mismatch($command->chargeId, $command->invoiceId);
        }
        if ($this->lines->liveLineExistsForCharge($command->chargeId)) {
            throw InvoiceChargeConflict::forCharge($command->chargeId);
        }

        $now = CarbonImmutable::now();
        $quantity = is_numeric($charge->quantity) ? $charge->quantity : '0';

        $line = InvoiceLine::add(
            id: (string) Str::uuid7(),
            tenantId: $this->context->tenantId(),
            invoiceId: $command->invoiceId,
            chargeId: $command->chargeId,
            description: $this->descriptionFor($charge->billingServiceId, $charge->billingServiceCode),
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
            throw InvoiceChargeConflict::forCharge($command->chargeId);
        }

        $this->recomputeTotals($command->invoiceId, $invoice);
    }

    private function recomputeTotals(string $invoiceId, Invoice $invoice): void
    {
        $gross = '0';
        foreach ($this->lines->liveLinesForInvoiceForUpdate($invoiceId) as $line) {
            $gross = Money::add($gross, (string) $line->amount);
        }
        $invoice->recomputeTotals($gross);
        // The line set changed — any stored coverage split is now stale (ADR-201 D-C).
        $invoice->clearCoverage();
        $this->invoices->save($invoice);
    }

    private function descriptionFor(string $billingServiceId, string $fallbackCode): string
    {
        try {
            return $this->catalog->serviceSummary($billingServiceId)->name;
        } catch (BillingServiceNotFound) {
            return $fallbackCode;
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
