<?php

declare(strict_types=1);

namespace App\Domains\Procurement;

use App\Domains\Inventory\Contracts\Event\GoodsReceiptPosted;
use App\Domains\Inventory\Contracts\Event\StockReceived;
use App\Domains\Procurement\Application\Command\ActivateContract;
use App\Domains\Procurement\Application\Command\ActivateContractHandler;
use App\Domains\Procurement\Application\Command\AmendContract;
use App\Domains\Procurement\Application\Command\AmendContractHandler;
use App\Domains\Procurement\Application\Command\AmendPurchaseOrder;
use App\Domains\Procurement\Application\Command\AmendPurchaseOrderHandler;
use App\Domains\Procurement\Application\Command\ApprovePurchaseOrder;
use App\Domains\Procurement\Application\Command\ApprovePurchaseOrderHandler;
use App\Domains\Procurement\Application\Command\ApprovePurchaseRequisition;
use App\Domains\Procurement\Application\Command\ApprovePurchaseRequisitionHandler;
use App\Domains\Procurement\Application\Command\AwardRfq;
use App\Domains\Procurement\Application\Command\AwardRfqHandler;
use App\Domains\Procurement\Application\Command\BlacklistSupplier;
use App\Domains\Procurement\Application\Command\BlacklistSupplierHandler;
use App\Domains\Procurement\Application\Command\CancelPurchaseOrder;
use App\Domains\Procurement\Application\Command\CancelPurchaseOrderHandler;
use App\Domains\Procurement\Application\Command\CancelPurchaseRequisition;
use App\Domains\Procurement\Application\Command\CancelPurchaseRequisitionHandler;
use App\Domains\Procurement\Application\Command\CancelRfq;
use App\Domains\Procurement\Application\Command\CancelRfqHandler;
use App\Domains\Procurement\Application\Command\ChangeSupplierStatus;
use App\Domains\Procurement\Application\Command\ChangeSupplierStatusHandler;
use App\Domains\Procurement\Application\Command\ClosePurchaseOrderShort;
use App\Domains\Procurement\Application\Command\ClosePurchaseOrderShortHandler;
use App\Domains\Procurement\Application\Command\CloseRfq;
use App\Domains\Procurement\Application\Command\CloseRfqHandler;
use App\Domains\Procurement\Application\Command\CreateContract;
use App\Domains\Procurement\Application\Command\CreateContractHandler;
use App\Domains\Procurement\Application\Command\CreatePurchaseOrder;
use App\Domains\Procurement\Application\Command\CreatePurchaseOrderHandler;
use App\Domains\Procurement\Application\Command\CreatePurchaseRequisition;
use App\Domains\Procurement\Application\Command\CreatePurchaseRequisitionHandler;
use App\Domains\Procurement\Application\Command\CreateRfq;
use App\Domains\Procurement\Application\Command\CreateRfqHandler;
use App\Domains\Procurement\Application\Command\EvaluateQuotation;
use App\Domains\Procurement\Application\Command\EvaluateQuotationHandler;
use App\Domains\Procurement\Application\Command\ExpireContract;
use App\Domains\Procurement\Application\Command\ExpireContractHandler;
use App\Domains\Procurement\Application\Command\IssuePurchaseOrder;
use App\Domains\Procurement\Application\Command\IssuePurchaseOrderHandler;
use App\Domains\Procurement\Application\Command\IssueRfq;
use App\Domains\Procurement\Application\Command\IssueRfqHandler;
use App\Domains\Procurement\Application\Command\RecordPurchaseOrderReceipt;
use App\Domains\Procurement\Application\Command\RecordPurchaseOrderReceiptBatch;
use App\Domains\Procurement\Application\Command\RecordPurchaseOrderReceiptBatchHandler;
use App\Domains\Procurement\Application\Command\RecordPurchaseOrderReceiptHandler;
use App\Domains\Procurement\Application\Command\RecordQuotation;
use App\Domains\Procurement\Application\Command\RecordQuotationHandler;
use App\Domains\Procurement\Application\Command\RegisterSupplier;
use App\Domains\Procurement\Application\Command\RegisterSupplierHandler;
use App\Domains\Procurement\Application\Command\RejectPurchaseRequisition;
use App\Domains\Procurement\Application\Command\RejectPurchaseRequisitionHandler;
use App\Domains\Procurement\Application\Command\RenewContract;
use App\Domains\Procurement\Application\Command\RenewContractHandler;
use App\Domains\Procurement\Application\Command\RequireRfqEvaluation;
use App\Domains\Procurement\Application\Command\RequireRfqEvaluationHandler;
use App\Domains\Procurement\Application\Command\ReviseEvaluation;
use App\Domains\Procurement\Application\Command\ReviseEvaluationHandler;
use App\Domains\Procurement\Application\Command\ReviseQuotation;
use App\Domains\Procurement\Application\Command\ReviseQuotationHandler;
use App\Domains\Procurement\Application\Command\SetApprovalLimit;
use App\Domains\Procurement\Application\Command\SetApprovalLimitHandler;
use App\Domains\Procurement\Application\Command\SubmitEvaluation;
use App\Domains\Procurement\Application\Command\SubmitEvaluationHandler;
use App\Domains\Procurement\Application\Command\SubmitPurchaseOrder;
use App\Domains\Procurement\Application\Command\SubmitPurchaseOrderHandler;
use App\Domains\Procurement\Application\Command\SubmitPurchaseRequisition;
use App\Domains\Procurement\Application\Command\SubmitPurchaseRequisitionHandler;
use App\Domains\Procurement\Application\Command\TerminateContract;
use App\Domains\Procurement\Application\Command\TerminateContractHandler;
use App\Domains\Procurement\Application\Command\UpdateDraftPurchaseRequisition;
use App\Domains\Procurement\Application\Command\UpdateDraftPurchaseRequisitionHandler;
use App\Domains\Procurement\Application\Command\UpdateSupplier;
use App\Domains\Procurement\Application\Command\UpdateSupplierHandler;
use App\Domains\Procurement\Application\Command\UpdateSupplierLicence;
use App\Domains\Procurement\Application\Command\UpdateSupplierLicenceHandler;
use App\Domains\Procurement\Application\Command\WithdrawQuotation;
use App\Domains\Procurement\Application\Command\WithdrawQuotationHandler;
use App\Domains\Procurement\Application\Listener\OnGoodsReceiptPosted;
use App\Domains\Procurement\Application\Listener\OnStockReceived;
use App\Domains\Procurement\Application\ProcurementApiService;
use App\Domains\Procurement\Application\Query\CompareQuotations;
use App\Domains\Procurement\Application\Query\CompareQuotationsHandler;
use App\Domains\Procurement\Application\Query\GetContract;
use App\Domains\Procurement\Application\Query\GetContractHandler;
use App\Domains\Procurement\Application\Query\GetGoodsReceiptPurchaseOrder;
use App\Domains\Procurement\Application\Query\GetGoodsReceiptPurchaseOrderHandler;
use App\Domains\Procurement\Application\Query\GetItemPriceReferences;
use App\Domains\Procurement\Application\Query\GetItemPriceReferencesHandler;
use App\Domains\Procurement\Application\Query\GetOpenPurchaseOrders;
use App\Domains\Procurement\Application\Query\GetOpenPurchaseOrdersHandler;
use App\Domains\Procurement\Application\Query\GetPurchaseOrder;
use App\Domains\Procurement\Application\Query\GetPurchaseOrderHandler;
use App\Domains\Procurement\Application\Query\GetPurchaseRequisition;
use App\Domains\Procurement\Application\Query\GetPurchaseRequisitionHandler;
use App\Domains\Procurement\Application\Query\GetQuotationEvaluations;
use App\Domains\Procurement\Application\Query\GetQuotationEvaluationsHandler;
use App\Domains\Procurement\Application\Query\GetRequisitionApprovalQueue;
use App\Domains\Procurement\Application\Query\GetRequisitionApprovalQueueHandler;
use App\Domains\Procurement\Application\Query\GetRfq;
use App\Domains\Procurement\Application\Query\GetRfqHandler;
use App\Domains\Procurement\Application\Query\GetRfqQuotations;
use App\Domains\Procurement\Application\Query\GetRfqQuotationsHandler;
use App\Domains\Procurement\Application\Query\GetSupplier;
use App\Domains\Procurement\Application\Query\GetSupplierHandler;
use App\Domains\Procurement\Application\Query\GetSuppliers;
use App\Domains\Procurement\Application\Query\GetSuppliersHandler;
use App\Domains\Procurement\Application\Query\SearchContracts;
use App\Domains\Procurement\Application\Query\SearchContractsHandler;
use App\Domains\Procurement\Application\Query\SearchPurchaseOrders;
use App\Domains\Procurement\Application\Query\SearchPurchaseOrdersHandler;
use App\Domains\Procurement\Application\Query\SearchPurchaseRequisitions;
use App\Domains\Procurement\Application\Query\SearchPurchaseRequisitionsHandler;
use App\Domains\Procurement\Application\Query\SearchReceivablePurchaseOrderLines;
use App\Domains\Procurement\Application\Query\SearchReceivablePurchaseOrderLinesHandler;
use App\Domains\Procurement\Application\Query\SearchReceivablePurchaseOrders;
use App\Domains\Procurement\Application\Query\SearchReceivablePurchaseOrdersHandler;
use App\Domains\Procurement\Application\Query\SearchRfqs;
use App\Domains\Procurement\Application\Query\SearchRfqsHandler;
use App\Domains\Procurement\Authorization\ApprovalPolicyPolicy;
use App\Domains\Procurement\Authorization\ContractPolicy;
use App\Domains\Procurement\Authorization\PurchaseOrderPolicy;
use App\Domains\Procurement\Authorization\PurchaseRequisitionPolicy;
use App\Domains\Procurement\Authorization\QuotationEvaluationPolicy;
use App\Domains\Procurement\Authorization\QuotationPolicy;
use App\Domains\Procurement\Authorization\RfqPolicy;
use App\Domains\Procurement\Authorization\SupplierPolicy;
use App\Domains\Procurement\Console\ConfigureApprovalLimitCommand;
use App\Domains\Procurement\Console\ConfigureEvaluationCommand;
use App\Domains\Procurement\Console\ExpireContractsCommand;
use App\Domains\Procurement\Contracts\ProcurementApi;
use App\Domains\Procurement\Domain\ApprovalPolicyRepository;
use App\Domains\Procurement\Domain\ContractReader;
use App\Domains\Procurement\Domain\ContractRepository;
use App\Domains\Procurement\Domain\EvaluationSettingsReader;
use App\Domains\Procurement\Domain\EvaluationSettingsRepository;
use App\Domains\Procurement\Domain\Model\ApprovalPolicy;
use App\Domains\Procurement\Domain\Model\Contract;
use App\Domains\Procurement\Domain\Model\PurchaseOrder;
use App\Domains\Procurement\Domain\Model\PurchaseRequisition;
use App\Domains\Procurement\Domain\Model\Quotation;
use App\Domains\Procurement\Domain\Model\QuotationEvaluation;
use App\Domains\Procurement\Domain\Model\Rfq;
use App\Domains\Procurement\Domain\Model\Supplier;
use App\Domains\Procurement\Domain\PurchaseOrderReader;
use App\Domains\Procurement\Domain\PurchaseOrderRepository;
use App\Domains\Procurement\Domain\PurchaseRequisitionReader;
use App\Domains\Procurement\Domain\PurchaseRequisitionRepository;
use App\Domains\Procurement\Domain\QuotationEvaluationReader;
use App\Domains\Procurement\Domain\QuotationEvaluationRepository;
use App\Domains\Procurement\Domain\QuotationReader;
use App\Domains\Procurement\Domain\QuotationRepository;
use App\Domains\Procurement\Domain\RfqReader;
use App\Domains\Procurement\Domain\RfqRepository;
use App\Domains\Procurement\Domain\SupplierReader;
use App\Domains\Procurement\Domain\SupplierRepository;
use App\Domains\Procurement\Infrastructure\Reader\EloquentContractReader;
use App\Domains\Procurement\Infrastructure\Reader\EloquentEvaluationSettingsReader;
use App\Domains\Procurement\Infrastructure\Reader\EloquentPurchaseOrderReader;
use App\Domains\Procurement\Infrastructure\Reader\EloquentPurchaseRequisitionReader;
use App\Domains\Procurement\Infrastructure\Reader\EloquentQuotationEvaluationReader;
use App\Domains\Procurement\Infrastructure\Reader\EloquentQuotationReader;
use App\Domains\Procurement\Infrastructure\Reader\EloquentRfqReader;
use App\Domains\Procurement\Infrastructure\Reader\EloquentSupplierReader;
use App\Domains\Procurement\Infrastructure\Repository\EloquentApprovalPolicyRepository;
use App\Domains\Procurement\Infrastructure\Repository\EloquentContractRepository;
use App\Domains\Procurement\Infrastructure\Repository\EloquentEvaluationSettingsRepository;
use App\Domains\Procurement\Infrastructure\Repository\EloquentPurchaseOrderRepository;
use App\Domains\Procurement\Infrastructure\Repository\EloquentPurchaseRequisitionRepository;
use App\Domains\Procurement\Infrastructure\Repository\EloquentQuotationEvaluationRepository;
use App\Domains\Procurement\Infrastructure\Repository\EloquentQuotationRepository;
use App\Domains\Procurement\Infrastructure\Repository\EloquentRfqRepository;
use App\Domains\Procurement\Infrastructure\Repository\EloquentSupplierRepository;
use App\Shared\Authorization\PermissionRegistry;
use App\Shared\Bus\CommandBus;
use App\Shared\Bus\QueryBus;
use App\Shared\Events\EventRegistry;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Procurement module service provider (19-procurement-domain.md; ADR-171, ADR-172).
 *
 * Proc.1a: the Supplier Master (HTTP-command lifecycle), the Purchase
 * Requisition (+ lines), the Purchase Order (through ISSUED, + lines +
 * append-only amendment log), and the first published `InventoryApi::getItem`
 * port on the Inventory module.
 *
 * Proc.1b (ADR-172): the `OnStockReceived` listener — the FIRST
 * `EventRegistry::listen(...)` in the engagement — projecting Inventory's
 * `StockReceived` onto PO fulfilment via the `RecordPurchaseOrderReceipt`
 * system command + the `pcr_po_receipts` append-only ledger.
 *
 * Proc.3a (ADR-176): the purchase-contract record (`Contract` aggregate +
 * `pcr_contract_lines`, DRAFT → ACTIVE → TERMINATED, no event) and a fourth
 * `CreatePurchaseOrder` sourcing path — `?contractId` seeds the PO lines at the
 * agreed contract price after a check-at-use ACTIVE + unexpired guard (§194 /
 * Invariant 32).
 *
 * Proc.3b (ADR-177): `pcr_po_lines.contract_line_id` + the cumulative
 * contract-ceiling invariant (INV-33a, state-based, `pcr_contracts` row lock) +
 * contract-price authority (INV-price) on the PO write path.
 *
 * Proc.3c (ADR-178): `AmendContract` (in place, `pcr_contract_amendments` log)
 * and `RenewContract` (a new `pcr_contracts` row + `supersedes_contract_id`,
 * old → `SUPERSEDED`, fresh line ids / zero consumption).
 *
 * Proc.3d (ADR-179): `ExpireContract` (system command) + the
 * `procurement:expire-contracts` hourly sweep flipping past-`expiry_date` ACTIVE
 * contracts to `EXPIRED` — status-accuracy only (Inv 32 stays check-at-use).
 */
final class ProcurementServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // The only cross-domain Procurement surface (IC §2.1) — consumed by the
        // Inventory PO-aware goods-receipt workflow (Phase 7).
        $this->app->bind(ProcurementApi::class, ProcurementApiService::class);

        $this->app->bind(SupplierRepository::class, EloquentSupplierRepository::class);
        $this->app->bind(PurchaseRequisitionRepository::class, EloquentPurchaseRequisitionRepository::class);
        $this->app->bind(PurchaseOrderRepository::class, EloquentPurchaseOrderRepository::class);

        $this->app->bind(RfqRepository::class, EloquentRfqRepository::class);
        $this->app->bind(QuotationRepository::class, EloquentQuotationRepository::class);
        $this->app->bind(QuotationEvaluationRepository::class, EloquentQuotationEvaluationRepository::class);
        $this->app->bind(EvaluationSettingsRepository::class, EloquentEvaluationSettingsRepository::class);
        $this->app->bind(ApprovalPolicyRepository::class, EloquentApprovalPolicyRepository::class);
        $this->app->bind(ContractRepository::class, EloquentContractRepository::class);

        $this->app->bind(SupplierReader::class, EloquentSupplierReader::class);
        $this->app->bind(PurchaseRequisitionReader::class, EloquentPurchaseRequisitionReader::class);
        $this->app->bind(PurchaseOrderReader::class, EloquentPurchaseOrderReader::class);
        $this->app->bind(RfqReader::class, EloquentRfqReader::class);
        $this->app->bind(QuotationReader::class, EloquentQuotationReader::class);
        $this->app->bind(QuotationEvaluationReader::class, EloquentQuotationEvaluationReader::class);
        $this->app->bind(EvaluationSettingsReader::class, EloquentEvaluationSettingsReader::class);
        $this->app->bind(ContractReader::class, EloquentContractReader::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Database/Migrations');
        $this->loadRoutesFrom(__DIR__.'/Presentation/Http/routes.php');

        PermissionRegistry::add(require __DIR__.'/Authorization/permissions.php');

        if ($this->app->runningInConsole()) {
            $this->commands([
                ConfigureApprovalLimitCommand::class,
                ConfigureEvaluationCommand::class,
                ExpireContractsCommand::class,
            ]);
        }

        CommandBus::map([
            RegisterSupplier::class => RegisterSupplierHandler::class,
            UpdateSupplier::class => UpdateSupplierHandler::class,
            UpdateSupplierLicence::class => UpdateSupplierLicenceHandler::class,
            ChangeSupplierStatus::class => ChangeSupplierStatusHandler::class,
            BlacklistSupplier::class => BlacklistSupplierHandler::class,

            CreatePurchaseRequisition::class => CreatePurchaseRequisitionHandler::class,
            UpdateDraftPurchaseRequisition::class => UpdateDraftPurchaseRequisitionHandler::class,
            SubmitPurchaseRequisition::class => SubmitPurchaseRequisitionHandler::class,
            ApprovePurchaseRequisition::class => ApprovePurchaseRequisitionHandler::class,
            RejectPurchaseRequisition::class => RejectPurchaseRequisitionHandler::class,
            CancelPurchaseRequisition::class => CancelPurchaseRequisitionHandler::class,

            CreatePurchaseOrder::class => CreatePurchaseOrderHandler::class,
            SubmitPurchaseOrder::class => SubmitPurchaseOrderHandler::class,
            ApprovePurchaseOrder::class => ApprovePurchaseOrderHandler::class,
            IssuePurchaseOrder::class => IssuePurchaseOrderHandler::class,
            AmendPurchaseOrder::class => AmendPurchaseOrderHandler::class,
            CancelPurchaseOrder::class => CancelPurchaseOrderHandler::class,
            ClosePurchaseOrderShort::class => ClosePurchaseOrderShortHandler::class,

            // Shape B — administrative policy: who may sign off an order alone.
            SetApprovalLimit::class => SetApprovalLimitHandler::class,

            // Proc.1b — the system reaction to Inventory's goods receipt (ADR-172 D-D).
            RecordPurchaseOrderReceipt::class => RecordPurchaseOrderReceiptHandler::class,
            // P1-03 — one batch projection per multi-line GRN.
            RecordPurchaseOrderReceiptBatch::class => RecordPurchaseOrderReceiptBatchHandler::class,

            // Proc.2a — the sourcing decision loop (ADR-173 D-D).
            CreateRfq::class => CreateRfqHandler::class,
            IssueRfq::class => IssueRfqHandler::class,
            CloseRfq::class => CloseRfqHandler::class,
            CancelRfq::class => CancelRfqHandler::class,
            AwardRfq::class => AwardRfqHandler::class,
            RecordQuotation::class => RecordQuotationHandler::class,
            ReviseQuotation::class => ReviseQuotationHandler::class,
            WithdrawQuotation::class => WithdrawQuotationHandler::class,

            // Proc.2b — structured quotation evaluation (ADR-174 D-C).
            EvaluateQuotation::class => EvaluateQuotationHandler::class,
            ReviseEvaluation::class => ReviseEvaluationHandler::class,
            SubmitEvaluation::class => SubmitEvaluationHandler::class,

            // Proc.2c — the §23 "requires evaluation" hard gate (ADR-175 D-C).
            RequireRfqEvaluation::class => RequireRfqEvaluationHandler::class,

            // Proc.3a — the purchase-contract record + PO contract linkage (ADR-176 D-C).
            CreateContract::class => CreateContractHandler::class,
            ActivateContract::class => ActivateContractHandler::class,
            TerminateContract::class => TerminateContractHandler::class,

            // Proc.3c — contract amendment + renewal + the SUPERSEDED chain (ADR-178 D-C).
            AmendContract::class => AmendContractHandler::class,
            RenewContract::class => RenewContractHandler::class,

            // Proc.3d — the ACTIVE → EXPIRED expiry sweep (ADR-179 D-C; system command).
            ExpireContract::class => ExpireContractHandler::class,
        ]);

        // Proc.1b — the first cross-domain event listener in the engagement (ADR-172 D-C).
        EventRegistry::listen(StockReceived::class, OnStockReceived::class);
        EventRegistry::listen(GoodsReceiptPosted::class, OnGoodsReceiptPosted::class);

        QueryBus::map([
            GetSupplier::class => GetSupplierHandler::class,
            GetSuppliers::class => GetSuppliersHandler::class,
            GetPurchaseRequisition::class => GetPurchaseRequisitionHandler::class,
            SearchPurchaseRequisitions::class => SearchPurchaseRequisitionsHandler::class,
            GetRequisitionApprovalQueue::class => GetRequisitionApprovalQueueHandler::class,
            GetPurchaseOrder::class => GetPurchaseOrderHandler::class,
            GetGoodsReceiptPurchaseOrder::class => GetGoodsReceiptPurchaseOrderHandler::class,
            SearchPurchaseOrders::class => SearchPurchaseOrdersHandler::class,
            GetOpenPurchaseOrders::class => GetOpenPurchaseOrdersHandler::class,
            GetItemPriceReferences::class => GetItemPriceReferencesHandler::class,
            SearchReceivablePurchaseOrders::class => SearchReceivablePurchaseOrdersHandler::class,
            SearchReceivablePurchaseOrderLines::class => SearchReceivablePurchaseOrderLinesHandler::class,

            // Proc.2a (ADR-173 D-F) — GetRfq / SearchRfqs on `procurement.access`;
            // GetRfqQuotations / CompareQuotations on `procurement.quotations.view` (§101).
            GetRfq::class => GetRfqHandler::class,
            SearchRfqs::class => SearchRfqsHandler::class,
            GetRfqQuotations::class => GetRfqQuotationsHandler::class,
            CompareQuotations::class => CompareQuotationsHandler::class,

            // Proc.2b (ADR-174 D-D) — the combined evaluation overview on
            // `procurement.quotations.view` + the §207 independence filter.
            GetQuotationEvaluations::class => GetQuotationEvaluationsHandler::class,

            // Proc.3a (ADR-176 D-F) — contract reads on the coarse `procurement.access`.
            GetContract::class => GetContractHandler::class,
            SearchContracts::class => SearchContractsHandler::class,
        ]);

        Gate::policy(Supplier::class, SupplierPolicy::class);
        Gate::policy(PurchaseRequisition::class, PurchaseRequisitionPolicy::class);
        Gate::policy(PurchaseOrder::class, PurchaseOrderPolicy::class);
        Gate::policy(Rfq::class, RfqPolicy::class);
        Gate::policy(Quotation::class, QuotationPolicy::class);
        Gate::policy(QuotationEvaluation::class, QuotationEvaluationPolicy::class);
        Gate::policy(Contract::class, ContractPolicy::class);
        Gate::policy(ApprovalPolicy::class, ApprovalPolicyPolicy::class);
    }
}
