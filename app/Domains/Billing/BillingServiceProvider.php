<?php

declare(strict_types=1);

namespace App\Domains\Billing;

use App\Domains\Billing\Application\BillingApiService;
use App\Domains\Billing\Application\Command\AddBillingService;
use App\Domains\Billing\Application\Command\AddBillingServiceHandler;
use App\Domains\Billing\Application\Command\AddInvoiceLine;
use App\Domains\Billing\Application\Command\AddInvoiceLineHandler;
use App\Domains\Billing\Application\Command\AllocatePayment;
use App\Domains\Billing\Application\Command\AllocatePaymentHandler;
use App\Domains\Billing\Application\Command\ApproveAdjustment;
use App\Domains\Billing\Application\Command\ApproveAdjustmentHandler;
use App\Domains\Billing\Application\Command\ApproveWriteOff;
use App\Domains\Billing\Application\Command\ApproveWriteOffHandler;
use App\Domains\Billing\Application\Command\CaptureBillableEvent;
use App\Domains\Billing\Application\Command\CaptureBillableEventHandler;
use App\Domains\Billing\Application\Command\CloseCashSession;
use App\Domains\Billing\Application\Command\CloseCashSessionHandler;
use App\Domains\Billing\Application\Command\CloseFinancialAccount;
use App\Domains\Billing\Application\Command\CloseFinancialAccountHandler;
use App\Domains\Billing\Application\Command\CreateInvoice;
use App\Domains\Billing\Application\Command\CreateInvoiceHandler;
use App\Domains\Billing\Application\Command\DeactivateItemBillingMap;
use App\Domains\Billing\Application\Command\DeactivateItemBillingMapHandler;
use App\Domains\Billing\Application\Command\EndTariff;
use App\Domains\Billing\Application\Command\EndTariffHandler;
use App\Domains\Billing\Application\Command\IssueInvoice;
use App\Domains\Billing\Application\Command\IssueInvoiceHandler;
use App\Domains\Billing\Application\Command\MapItemToBillingService;
use App\Domains\Billing\Application\Command\MapItemToBillingServiceHandler;
use App\Domains\Billing\Application\Command\OpenCashSession;
use App\Domains\Billing\Application\Command\OpenCashSessionHandler;
use App\Domains\Billing\Application\Command\OpenFinancialAccount;
use App\Domains\Billing\Application\Command\OpenFinancialAccountHandler;
use App\Domains\Billing\Application\Command\ReconcileCashSession;
use App\Domains\Billing\Application\Command\ReconcileCashSessionHandler;
use App\Domains\Billing\Application\Command\RecordPayment;
use App\Domains\Billing\Application\Command\RecordPaymentHandler;
use App\Domains\Billing\Application\Command\RejectAdjustment;
use App\Domains\Billing\Application\Command\RejectAdjustmentHandler;
use App\Domains\Billing\Application\Command\RejectWriteOff;
use App\Domains\Billing\Application\Command\RejectWriteOffHandler;
use App\Domains\Billing\Application\Command\RemoveInvoiceLine;
use App\Domains\Billing\Application\Command\RemoveInvoiceLineHandler;
use App\Domains\Billing\Application\Command\RequestAdjustment;
use App\Domains\Billing\Application\Command\RequestAdjustmentHandler;
use App\Domains\Billing\Application\Command\RequestWriteOff;
use App\Domains\Billing\Application\Command\RequestWriteOffHandler;
use App\Domains\Billing\Application\Command\RetryBillableEvent;
use App\Domains\Billing\Application\Command\RetryBillableEventHandler;
use App\Domains\Billing\Application\Command\ReverseAdjustment;
use App\Domains\Billing\Application\Command\ReverseAdjustmentHandler;
use App\Domains\Billing\Application\Command\ReverseBillableEvent;
use App\Domains\Billing\Application\Command\ReverseBillableEventHandler;
use App\Domains\Billing\Application\Command\ReversePayment;
use App\Domains\Billing\Application\Command\ReversePaymentHandler;
use App\Domains\Billing\Application\Command\ReverseWriteOff;
use App\Domains\Billing\Application\Command\ReverseWriteOffHandler;
use App\Domains\Billing\Application\Command\SetBillingServiceStatus;
use App\Domains\Billing\Application\Command\SetBillingServiceStatusHandler;
use App\Domains\Billing\Application\Command\SetInvoiceCoverage;
use App\Domains\Billing\Application\Command\SetInvoiceCoverageHandler;
use App\Domains\Billing\Application\Command\SetTariff;
use App\Domains\Billing\Application\Command\SetTariffHandler;
use App\Domains\Billing\Application\Command\SettleInsuranceRemittance;
use App\Domains\Billing\Application\Command\SettleInsuranceRemittanceHandler;
use App\Domains\Billing\Application\Command\UnallocatePayment;
use App\Domains\Billing\Application\Command\UnallocatePaymentHandler;
use App\Domains\Billing\Application\Command\UpdateBillingService;
use App\Domains\Billing\Application\Command\UpdateBillingServiceHandler;
use App\Domains\Billing\Application\Command\VoidInvoice;
use App\Domains\Billing\Application\Command\VoidInvoiceHandler;
use App\Domains\Billing\Application\Listener\OnMedicationDispensed;
use App\Domains\Billing\Application\Listener\OnMedicationDispensingReversed;
use App\Domains\Billing\Application\Listener\OnRemittanceLineSettled;
use App\Domains\Billing\Application\Query\GetAccountAdjustments;
use App\Domains\Billing\Application\Query\GetAccountAdjustmentsHandler;
use App\Domains\Billing\Application\Query\GetAccountCharges;
use App\Domains\Billing\Application\Query\GetAccountChargesHandler;
use App\Domains\Billing\Application\Query\GetAccountInvoices;
use App\Domains\Billing\Application\Query\GetAccountInvoicesHandler;
use App\Domains\Billing\Application\Query\GetAccountLedger;
use App\Domains\Billing\Application\Query\GetAccountLedgerHandler;
use App\Domains\Billing\Application\Query\GetAccountPayments;
use App\Domains\Billing\Application\Query\GetAccountPaymentsHandler;
use App\Domains\Billing\Application\Query\GetAccountWriteOffs;
use App\Domains\Billing\Application\Query\GetAccountWriteOffsHandler;
use App\Domains\Billing\Application\Query\GetAdjustment;
use App\Domains\Billing\Application\Query\GetAdjustmentHandler;
use App\Domains\Billing\Application\Query\GetBillingService;
use App\Domains\Billing\Application\Query\GetBillingServiceHandler;
use App\Domains\Billing\Application\Query\GetCashSession;
use App\Domains\Billing\Application\Query\GetCashSessionHandler;
use App\Domains\Billing\Application\Query\GetCharge;
use App\Domains\Billing\Application\Query\GetChargeHandler;
use App\Domains\Billing\Application\Query\GetEffectiveTariff;
use App\Domains\Billing\Application\Query\GetEffectiveTariffHandler;
use App\Domains\Billing\Application\Query\GetFacilityCashSessions;
use App\Domains\Billing\Application\Query\GetFacilityCashSessionsHandler;
use App\Domains\Billing\Application\Query\GetFinancialAccount;
use App\Domains\Billing\Application\Query\GetFinancialAccountByPatient;
use App\Domains\Billing\Application\Query\GetFinancialAccountByPatientHandler;
use App\Domains\Billing\Application\Query\GetFinancialAccountHandler;
use App\Domains\Billing\Application\Query\GetInvoice;
use App\Domains\Billing\Application\Query\GetInvoiceHandler;
use App\Domains\Billing\Application\Query\GetItemBillingMap;
use App\Domains\Billing\Application\Query\GetItemBillingMapHandler;
use App\Domains\Billing\Application\Query\GetOutstandingBalance;
use App\Domains\Billing\Application\Query\GetOutstandingBalanceHandler;
use App\Domains\Billing\Application\Query\GetPayment;
use App\Domains\Billing\Application\Query\GetPaymentAllocations;
use App\Domains\Billing\Application\Query\GetPaymentAllocationsHandler;
use App\Domains\Billing\Application\Query\GetPaymentHandler;
use App\Domains\Billing\Application\Query\GetPendingAdjustments;
use App\Domains\Billing\Application\Query\GetPendingAdjustmentsHandler;
use App\Domains\Billing\Application\Query\GetPendingBillableEvents;
use App\Domains\Billing\Application\Query\GetPendingBillableEventsHandler;
use App\Domains\Billing\Application\Query\GetPendingWriteOffs;
use App\Domains\Billing\Application\Query\GetPendingWriteOffsHandler;
use App\Domains\Billing\Application\Query\GetPriceList;
use App\Domains\Billing\Application\Query\GetPriceListHandler;
use App\Domains\Billing\Application\Query\GetServiceTariffs;
use App\Domains\Billing\Application\Query\GetServiceTariffsHandler;
use App\Domains\Billing\Application\Query\GetReceipt;
use App\Domains\Billing\Application\Query\GetReceiptHandler;
use App\Domains\Billing\Application\Query\GetUninvoicedCharges;
use App\Domains\Billing\Application\Query\GetUninvoicedChargesHandler;
use App\Domains\Billing\Application\Query\GetWriteOff;
use App\Domains\Billing\Application\Query\GetWriteOffHandler;
use App\Domains\Billing\Application\Query\SearchBillingServices;
use App\Domains\Billing\Application\Query\SearchBillingServicesHandler;
use App\Domains\Billing\Application\Query\SearchItemBillingMap;
use App\Domains\Billing\Application\Query\SearchItemBillingMapHandler;
use App\Domains\Billing\Authorization\AdjustmentPolicy;
use App\Domains\Billing\Authorization\BillingCatalogPolicy;
use App\Domains\Billing\Authorization\CashSessionPolicy;
use App\Domains\Billing\Authorization\ChargePolicy;
use App\Domains\Billing\Authorization\FinancialAccountPolicy;
use App\Domains\Billing\Authorization\InvoicePolicy;
use App\Domains\Billing\Authorization\PaymentPolicy;
use App\Domains\Billing\Authorization\WriteOffPolicy;
use App\Domains\Billing\Console\ConfigureFinancialControlCommand;
use App\Domains\Billing\Contracts\BillingApi;
use App\Domains\Billing\Domain\AdjustmentReader;
use App\Domains\Billing\Domain\AdjustmentRepository;
use App\Domains\Billing\Domain\BillableEventRepository;
use App\Domains\Billing\Domain\BillingCatalogReader;
use App\Domains\Billing\Domain\BillingServiceRepository;
use App\Domains\Billing\Domain\CashSessionReader;
use App\Domains\Billing\Domain\CashSessionRepository;
use App\Domains\Billing\Domain\ChargeReader;
use App\Domains\Billing\Domain\ChargeRepository;
use App\Domains\Billing\Domain\ChargeReversalRepository;
use App\Domains\Billing\Domain\FinancialAccountReader;
use App\Domains\Billing\Domain\FinancialAccountRepository;
use App\Domains\Billing\Domain\FinancialControlSettingsReader;
use App\Domains\Billing\Domain\FinancialControlSettingsRepository;
use App\Domains\Billing\Domain\InvoiceLineRepository;
use App\Domains\Billing\Domain\InvoiceReader;
use App\Domains\Billing\Domain\InvoiceRepository;
use App\Domains\Billing\Domain\ItemBillingMapReader;
use App\Domains\Billing\Domain\ItemBillingMapRepository;
use App\Domains\Billing\Domain\LedgerPoster;
use App\Domains\Billing\Domain\Model\Adjustment;
use App\Domains\Billing\Domain\Model\BillableEvent;
use App\Domains\Billing\Domain\Model\BillingService;
use App\Domains\Billing\Domain\Model\CashSession;
use App\Domains\Billing\Domain\Model\Charge;
use App\Domains\Billing\Domain\Model\FinancialAccount;
use App\Domains\Billing\Domain\Model\Invoice;
use App\Domains\Billing\Domain\Model\ItemBillingMap;
use App\Domains\Billing\Domain\Model\Payment;
use App\Domains\Billing\Domain\Model\Tariff;
use App\Domains\Billing\Domain\Model\WriteOff;
use App\Domains\Billing\Domain\PaymentAllocationReader;
use App\Domains\Billing\Domain\PaymentAllocationRepository;
use App\Domains\Billing\Domain\PaymentReader;
use App\Domains\Billing\Domain\PaymentRepository;
use App\Domains\Billing\Domain\ReceiptReader;
use App\Domains\Billing\Domain\ReceiptRepository;
use App\Domains\Billing\Domain\TariffRepository;
use App\Domains\Billing\Domain\WriteOffReader;
use App\Domains\Billing\Domain\WriteOffRepository;
use App\Domains\Billing\Infrastructure\Ledger\EloquentLedgerPoster;
use App\Domains\Billing\Infrastructure\Reader\EloquentAdjustmentReader;
use App\Domains\Billing\Infrastructure\Reader\EloquentBillingCatalogReader;
use App\Domains\Billing\Infrastructure\Reader\EloquentCashSessionReader;
use App\Domains\Billing\Infrastructure\Reader\EloquentChargeReader;
use App\Domains\Billing\Infrastructure\Reader\EloquentFinancialAccountReader;
use App\Domains\Billing\Infrastructure\Reader\EloquentFinancialControlSettingsReader;
use App\Domains\Billing\Infrastructure\Reader\EloquentInvoiceReader;
use App\Domains\Billing\Infrastructure\Reader\EloquentItemBillingMapReader;
use App\Domains\Billing\Infrastructure\Reader\EloquentPaymentAllocationReader;
use App\Domains\Billing\Infrastructure\Reader\EloquentPaymentReader;
use App\Domains\Billing\Infrastructure\Reader\EloquentReceiptReader;
use App\Domains\Billing\Infrastructure\Reader\EloquentWriteOffReader;
use App\Domains\Billing\Infrastructure\Repository\EloquentAdjustmentRepository;
use App\Domains\Billing\Infrastructure\Repository\EloquentBillableEventRepository;
use App\Domains\Billing\Infrastructure\Repository\EloquentBillingServiceRepository;
use App\Domains\Billing\Infrastructure\Repository\EloquentCashSessionRepository;
use App\Domains\Billing\Infrastructure\Repository\EloquentChargeRepository;
use App\Domains\Billing\Infrastructure\Repository\EloquentChargeReversalRepository;
use App\Domains\Billing\Infrastructure\Repository\EloquentFinancialAccountRepository;
use App\Domains\Billing\Infrastructure\Repository\EloquentFinancialControlSettingsRepository;
use App\Domains\Billing\Infrastructure\Repository\EloquentInvoiceLineRepository;
use App\Domains\Billing\Infrastructure\Repository\EloquentInvoiceRepository;
use App\Domains\Billing\Infrastructure\Repository\EloquentItemBillingMapRepository;
use App\Domains\Billing\Infrastructure\Repository\EloquentPaymentAllocationRepository;
use App\Domains\Billing\Infrastructure\Repository\EloquentPaymentRepository;
use App\Domains\Billing\Infrastructure\Repository\EloquentReceiptRepository;
use App\Domains\Billing\Infrastructure\Repository\EloquentTariffRepository;
use App\Domains\Billing\Infrastructure\Repository\EloquentWriteOffRepository;
use App\Domains\Insurance\Contracts\Event\RemittanceLineSettled;
use App\Domains\Pharmacy\Contracts\Event\MedicationDispensed;
use App\Domains\Pharmacy\Contracts\Event\MedicationDispensingReversed;
use App\Shared\Authorization\PermissionRegistry;
use App\Shared\Bus\CommandBus;
use App\Shared\Bus\QueryBus;
use App\Shared\Events\EventRegistry;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Billing & Payments module service provider (20-billing-and-payments-domain.md;
 * ADR-187, ADR-188, ADR-189).
 *
 * Bill.1 (ADR-187): the domain frame + the Financial Account + the append-only
 * `bill_account_ledger_entries`. Bill.2 (ADR-188): the Service Catalog + the
 * base tariff / price list.
 *
 * Bill.3 (ADR-189): the `Charge` aggregate + the first billable-event consumer.
 * Bill.3a — `bill_item_billing_map` (`inv_items.id → billing_service`).
 * Bill.3b — `OnMedicationDispensed` → `CaptureBillableEvent` (SYSTEM; lazy-opens
 * the `FinancialAccount`, posts the Charge + the first `bill_account_ledger_entries`
 * DEBIT via `LedgerPoster`, `ChargeCreated`); `OnMedicationDispensingReversed` →
 * `ReverseBillableEvent` (SYSTEM; FULL reversal → `bill_charge_reversals` +
 * `CREDIT` ledger entry + `ChargeReversed`); `RetryBillableEvent` (the manual
 * worklist, key `billing.charges.manage`); `GetCharge` / `GetAccountCharges` /
 * `GetPendingBillableEvents`.
 *
 * The Invoice (Bill.4), the Payment + Receipt (Bill.5), and the Financial Access
 * decision engine (Bill.6+) are deferred to their increments.
 */
final class BillingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(FinancialAccountRepository::class, EloquentFinancialAccountRepository::class);
        $this->app->bind(FinancialAccountReader::class, EloquentFinancialAccountReader::class);
        $this->app->bind(BillingApi::class, BillingApiService::class);

        // Bill.2 — the Service Catalog + the base tariff (ADR-188).
        $this->app->bind(BillingServiceRepository::class, EloquentBillingServiceRepository::class);
        $this->app->bind(TariffRepository::class, EloquentTariffRepository::class);
        $this->app->bind(BillingCatalogReader::class, EloquentBillingCatalogReader::class);

        // Bill.3a — the item → billing-service map (ADR-189 D-A).
        $this->app->bind(ItemBillingMapRepository::class, EloquentItemBillingMapRepository::class);
        $this->app->bind(ItemBillingMapReader::class, EloquentItemBillingMapReader::class);

        // Bill.3b — the charge-capture path (ADR-189 D-B…D-I).
        $this->app->bind(BillableEventRepository::class, EloquentBillableEventRepository::class);
        $this->app->bind(ChargeRepository::class, EloquentChargeRepository::class);
        $this->app->bind(ChargeReversalRepository::class, EloquentChargeReversalRepository::class);
        $this->app->bind(ChargeReader::class, EloquentChargeReader::class);
        $this->app->bind(LedgerPoster::class, EloquentLedgerPoster::class);

        // Bill.4 — the Invoice aggregate (ADR-190).
        $this->app->bind(InvoiceRepository::class, EloquentInvoiceRepository::class);
        $this->app->bind(InvoiceLineRepository::class, EloquentInvoiceLineRepository::class);
        $this->app->bind(InvoiceReader::class, EloquentInvoiceReader::class);

        // Bill.5b — the Payment + the Receipt (ADR-191 B5-B / B5-C).
        $this->app->bind(PaymentRepository::class, EloquentPaymentRepository::class);
        $this->app->bind(PaymentReader::class, EloquentPaymentReader::class);
        $this->app->bind(ReceiptRepository::class, EloquentReceiptRepository::class);
        $this->app->bind(ReceiptReader::class, EloquentReceiptReader::class);

        // Bill.5c — payment allocation (ADR-191 B5-A).
        $this->app->bind(PaymentAllocationRepository::class, EloquentPaymentAllocationRepository::class);
        $this->app->bind(PaymentAllocationReader::class, EloquentPaymentAllocationReader::class);

        // Bill.6 — cash sessions (ADR-191 C6).
        $this->app->bind(CashSessionRepository::class, EloquentCashSessionRepository::class);
        $this->app->bind(CashSessionReader::class, EloquentCashSessionReader::class);

        // Bill.7a — write-offs + the SoD threshold config (ADR-191 G-3).
        $this->app->bind(WriteOffRepository::class, EloquentWriteOffRepository::class);
        $this->app->bind(WriteOffReader::class, EloquentWriteOffReader::class);
        $this->app->bind(FinancialControlSettingsRepository::class, EloquentFinancialControlSettingsRepository::class);
        $this->app->bind(FinancialControlSettingsReader::class, EloquentFinancialControlSettingsReader::class);

        // Bill.7b — adjustments (ADR-191 D7-D).
        $this->app->bind(AdjustmentRepository::class, EloquentAdjustmentRepository::class);
        $this->app->bind(AdjustmentReader::class, EloquentAdjustmentReader::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Database/Migrations');

        PermissionRegistry::add(require __DIR__.'/Authorization/permissions.php');

        if ($this->app->runningInConsole()) {
            $this->commands([ConfigureFinancialControlCommand::class]);
        }

        CommandBus::map([
            // Bill.1 — the Financial Account (ADR-187 D-D).
            OpenFinancialAccount::class => OpenFinancialAccountHandler::class,
            CloseFinancialAccount::class => CloseFinancialAccountHandler::class,

            // Bill.2 — the Service Catalog + the base tariff (ADR-188 D-C).
            AddBillingService::class => AddBillingServiceHandler::class,
            UpdateBillingService::class => UpdateBillingServiceHandler::class,
            SetBillingServiceStatus::class => SetBillingServiceStatusHandler::class,
            SetTariff::class => SetTariffHandler::class,
            EndTariff::class => EndTariffHandler::class,

            // Bill.3a — the item → billing-service map (ADR-189 D-A).
            MapItemToBillingService::class => MapItemToBillingServiceHandler::class,
            DeactivateItemBillingMap::class => DeactivateItemBillingMapHandler::class,

            // Bill.3b — the charge-capture path (ADR-189 D-E…D-G).
            CaptureBillableEvent::class => CaptureBillableEventHandler::class,
            ReverseBillableEvent::class => ReverseBillableEventHandler::class,
            RetryBillableEvent::class => RetryBillableEventHandler::class,

            // Bill.4 — the Invoice aggregate (ADR-190 D-C).
            CreateInvoice::class => CreateInvoiceHandler::class,
            AddInvoiceLine::class => AddInvoiceLineHandler::class,
            RemoveInvoiceLine::class => RemoveInvoiceLineHandler::class,
            SetInvoiceCoverage::class => SetInvoiceCoverageHandler::class,   // Ins.4 — ADR-201 D-C
            IssueInvoice::class => IssueInvoiceHandler::class,
            VoidInvoice::class => VoidInvoiceHandler::class,

            // Bill.5b — the Payment + the Receipt (ADR-191 B5-C).
            RecordPayment::class => RecordPaymentHandler::class,
            ReversePayment::class => ReversePaymentHandler::class,

            // Bill.5c (ADR-191 B5-A).
            AllocatePayment::class => AllocatePaymentHandler::class,
            UnallocatePayment::class => UnallocatePaymentHandler::class,

            // Bill.6 — cash sessions (ADR-191 C6).
            OpenCashSession::class => OpenCashSessionHandler::class,
            CloseCashSession::class => CloseCashSessionHandler::class,
            ReconcileCashSession::class => ReconcileCashSessionHandler::class,

            // Bill.7a — write-offs (ADR-191 G-3 / D7).
            RequestWriteOff::class => RequestWriteOffHandler::class,
            ApproveWriteOff::class => ApproveWriteOffHandler::class,
            RejectWriteOff::class => RejectWriteOffHandler::class,
            ReverseWriteOff::class => ReverseWriteOffHandler::class,

            // Bill.7b — adjustments (ADR-191 D7-D).
            RequestAdjustment::class => RequestAdjustmentHandler::class,
            ApproveAdjustment::class => ApproveAdjustmentHandler::class,
            RejectAdjustment::class => RejectAdjustmentHandler::class,
            ReverseAdjustment::class => ReverseAdjustmentHandler::class,

            // Ins.8 cross-domain settlement (ADR-205).
            SettleInsuranceRemittance::class => SettleInsuranceRemittanceHandler::class,
        ]);

        QueryBus::map([
            // Bill.1 (ADR-187 D-E).
            GetFinancialAccount::class => GetFinancialAccountHandler::class,
            GetFinancialAccountByPatient::class => GetFinancialAccountByPatientHandler::class,
            GetAccountLedger::class => GetAccountLedgerHandler::class,
            GetOutstandingBalance::class => GetOutstandingBalanceHandler::class,

            // Bill.2 (ADR-188 D-D).
            GetBillingService::class => GetBillingServiceHandler::class,
            SearchBillingServices::class => SearchBillingServicesHandler::class,
            GetEffectiveTariff::class => GetEffectiveTariffHandler::class,
            GetPriceList::class => GetPriceListHandler::class,
            GetServiceTariffs::class => GetServiceTariffsHandler::class,

            // Bill.3a (ADR-189 D-A).
            GetItemBillingMap::class => GetItemBillingMapHandler::class,
            SearchItemBillingMap::class => SearchItemBillingMapHandler::class,

            // Bill.3b (ADR-189 D-G).
            GetCharge::class => GetChargeHandler::class,
            GetAccountCharges::class => GetAccountChargesHandler::class,
            GetPendingBillableEvents::class => GetPendingBillableEventsHandler::class,

            // Bill.4 (ADR-190 D-D).
            GetInvoice::class => GetInvoiceHandler::class,
            GetAccountInvoices::class => GetAccountInvoicesHandler::class,
            GetUninvoicedCharges::class => GetUninvoicedChargesHandler::class,

            // Bill.5b (ADR-191 B5-C).
            GetPayment::class => GetPaymentHandler::class,
            GetAccountPayments::class => GetAccountPaymentsHandler::class,
            GetReceipt::class => GetReceiptHandler::class,

            // Bill.5c (ADR-191 B5-A).
            GetPaymentAllocations::class => GetPaymentAllocationsHandler::class,

            // Bill.6 (ADR-191 C6).
            GetCashSession::class => GetCashSessionHandler::class,
            GetFacilityCashSessions::class => GetFacilityCashSessionsHandler::class,

            // Bill.7a (ADR-191 D7).
            GetWriteOff::class => GetWriteOffHandler::class,
            GetAccountWriteOffs::class => GetAccountWriteOffsHandler::class,
            GetPendingWriteOffs::class => GetPendingWriteOffsHandler::class,

            // Bill.7b (ADR-191 D7-D).
            GetAdjustment::class => GetAdjustmentHandler::class,
            GetAccountAdjustments::class => GetAccountAdjustmentsHandler::class,
            GetPendingAdjustments::class => GetPendingAdjustmentsHandler::class,
        ]);

        // Bill.3b — consume Pharmacy's dispensing facts (ADR-189 D-E / D-F).
        EventRegistry::listen(MedicationDispensed::class, OnMedicationDispensed::class);
        EventRegistry::listen(MedicationDispensingReversed::class, OnMedicationDispensingReversed::class);

        // Ins.8 — consume Insurance claim remittance settlement (ADR-198 D-C; ADR-205).
        EventRegistry::listen(RemittanceLineSettled::class, OnRemittanceLineSettled::class);

        Gate::policy(FinancialAccount::class, FinancialAccountPolicy::class);
        Gate::policy(BillingService::class, BillingCatalogPolicy::class);
        Gate::policy(Tariff::class, BillingCatalogPolicy::class);
        Gate::policy(ItemBillingMap::class, BillingCatalogPolicy::class);
        Gate::policy(Charge::class, ChargePolicy::class);
        Gate::policy(BillableEvent::class, ChargePolicy::class);
        Gate::policy(Invoice::class, InvoicePolicy::class);
        Gate::policy(Payment::class, PaymentPolicy::class);
        Gate::policy(CashSession::class, CashSessionPolicy::class);
        Gate::policy(WriteOff::class, WriteOffPolicy::class);
        Gate::policy(Adjustment::class, AdjustmentPolicy::class);

        $this->loadRoutesFrom(__DIR__.'/routes.php');
    }
}
