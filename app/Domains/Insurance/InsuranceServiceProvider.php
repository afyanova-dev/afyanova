<?php

declare(strict_types=1);

namespace App\Domains\Insurance;

use App\Domains\Insurance\Application\Command\ActivatePayerContract;
use App\Domains\Insurance\Application\Command\ActivatePayerContractHandler;
use App\Domains\Insurance\Application\Command\ApproveAuthorization;
use App\Domains\Insurance\Application\Command\ApproveAuthorizationHandler;
use App\Domains\Insurance\Application\Command\CancelAuthorization;
use App\Domains\Insurance\Application\Command\CancelAuthorizationHandler;
use App\Domains\Insurance\Application\Command\CancelClaim;
use App\Domains\Insurance\Application\Command\CancelClaimHandler;
use App\Domains\Insurance\Application\Command\CreateClaimFromInvoice;
use App\Domains\Insurance\Application\Command\CreateClaimFromInvoiceHandler;
use App\Domains\Insurance\Application\Command\CreatePayerContract;
use App\Domains\Insurance\Application\Command\CreatePayerContractHandler;
use App\Domains\Insurance\Application\Command\CreatePayerPlan;
use App\Domains\Insurance\Application\Command\CreatePayerPlanHandler;
use App\Domains\Insurance\Application\Command\CreateReplacementClaim;
use App\Domains\Insurance\Application\Command\CreateReplacementClaimHandler;
use App\Domains\Insurance\Application\Command\DenyAuthorization;
use App\Domains\Insurance\Application\Command\DenyAuthorizationHandler;
use App\Domains\Insurance\Application\Command\EndPayerTariff;
use App\Domains\Insurance\Application\Command\EndPayerTariffHandler;
use App\Domains\Insurance\Application\Command\EndPlanCoverageRule;
use App\Domains\Insurance\Application\Command\EndPlanCoverageRuleHandler;
use App\Domains\Insurance\Application\Command\ExpireAuthorization;
use App\Domains\Insurance\Application\Command\ExpireAuthorizationHandler;
use App\Domains\Insurance\Application\Command\ExpirePayerContract;
use App\Domains\Insurance\Application\Command\ExpirePayerContractHandler;
use App\Domains\Insurance\Application\Command\ReconcileRemittanceLine;
use App\Domains\Insurance\Application\Command\ReconcileRemittanceLineHandler;
use App\Domains\Insurance\Application\Command\RecordClaimAdjudication;
use App\Domains\Insurance\Application\Command\RecordClaimAdjudicationHandler;
use App\Domains\Insurance\Application\Command\RecordRemittance;
use App\Domains\Insurance\Application\Command\RecordRemittanceHandler;
use App\Domains\Insurance\Application\Command\RegisterMemberCoverage;
use App\Domains\Insurance\Application\Command\RegisterMemberCoverageHandler;
use App\Domains\Insurance\Application\Command\RegisterPayer;
use App\Domains\Insurance\Application\Command\RegisterPayerHandler;
use App\Domains\Insurance\Application\Command\RequestAuthorization;
use App\Domains\Insurance\Application\Command\RequestAuthorizationHandler;
use App\Domains\Insurance\Application\Command\SetPayerPlanStatus;
use App\Domains\Insurance\Application\Command\SetPayerPlanStatusHandler;
use App\Domains\Insurance\Application\Command\SetPayerStatus;
use App\Domains\Insurance\Application\Command\SetPayerStatusHandler;
use App\Domains\Insurance\Application\Command\SetPayerTariff;
use App\Domains\Insurance\Application\Command\SetPayerTariffHandler;
use App\Domains\Insurance\Application\Command\SetPlanCoverageRule;
use App\Domains\Insurance\Application\Command\SetPlanCoverageRuleHandler;
use App\Domains\Insurance\Application\Command\SubmitClaim;
use App\Domains\Insurance\Application\Command\SubmitClaimHandler;
use App\Domains\Insurance\Application\Command\TerminateMemberCoverage;
use App\Domains\Insurance\Application\Command\TerminateMemberCoverageHandler;
use App\Domains\Insurance\Application\Command\TerminatePayerContract;
use App\Domains\Insurance\Application\Command\TerminatePayerContractHandler;
use App\Domains\Insurance\Application\Command\UpdateClaimDraft;
use App\Domains\Insurance\Application\Command\UpdateClaimDraftHandler;
use App\Domains\Insurance\Application\Command\UpdateMemberCoverage;
use App\Domains\Insurance\Application\Command\UpdateMemberCoverageHandler;
use App\Domains\Insurance\Application\Command\UpdatePayer;
use App\Domains\Insurance\Application\Command\UpdatePayerHandler;
use App\Domains\Insurance\Application\Command\UpdatePayerPlan;
use App\Domains\Insurance\Application\Command\UpdatePayerPlanHandler;
use App\Domains\Insurance\Application\Command\ValidateClaimReadiness;
use App\Domains\Insurance\Application\Command\ValidateClaimReadinessHandler;
use App\Domains\Insurance\Application\InsuranceApiService;
use App\Domains\Insurance\Application\Query\EvaluateCoverage;
use App\Domains\Insurance\Application\Query\EvaluateCoverageHandler;
use App\Domains\Insurance\Application\Query\FindEffectiveAuthorization;
use App\Domains\Insurance\Application\Query\FindEffectiveAuthorizationHandler;
use App\Domains\Insurance\Application\Query\FindEffectivePayerTariff;
use App\Domains\Insurance\Application\Query\FindEffectivePayerTariffHandler;
use App\Domains\Insurance\Application\Query\GetAdjudication;
use App\Domains\Insurance\Application\Query\GetAdjudicationHandler;
use App\Domains\Insurance\Application\Query\GetAuthorization;
use App\Domains\Insurance\Application\Query\GetAuthorizationHandler;
use App\Domains\Insurance\Application\Query\GetClaim;
use App\Domains\Insurance\Application\Query\GetClaimAdjudication;
use App\Domains\Insurance\Application\Query\GetClaimAdjudicationHandler;
use App\Domains\Insurance\Application\Query\GetClaimHandler;
use App\Domains\Insurance\Application\Query\GetClaimReadiness;
use App\Domains\Insurance\Application\Query\GetClaimReadinessHandler;
use App\Domains\Insurance\Application\Query\GetContractTariffs;
use App\Domains\Insurance\Application\Query\GetContractTariffsHandler;
use App\Domains\Insurance\Application\Query\GetMemberCoverage;
use App\Domains\Insurance\Application\Query\GetMemberCoverageHandler;
use App\Domains\Insurance\Application\Query\GetPatientAuthorizations;
use App\Domains\Insurance\Application\Query\GetPatientAuthorizationsHandler;
use App\Domains\Insurance\Application\Query\GetPatientClaims;
use App\Domains\Insurance\Application\Query\GetPatientClaimsHandler;
use App\Domains\Insurance\Application\Query\GetPatientCoverages;
use App\Domains\Insurance\Application\Query\GetPatientCoveragesHandler;
use App\Domains\Insurance\Application\Query\GetPayer;
use App\Domains\Insurance\Application\Query\GetPayerClaims;
use App\Domains\Insurance\Application\Query\GetPayerClaimsHandler;
use App\Domains\Insurance\Application\Query\GetPayerContract;
use App\Domains\Insurance\Application\Query\GetPayerContractHandler;
use App\Domains\Insurance\Application\Query\GetPayerContracts;
use App\Domains\Insurance\Application\Query\GetPayerContractsHandler;
use App\Domains\Insurance\Application\Query\GetPayerHandler;
use App\Domains\Insurance\Application\Query\GetPayerPlan;
use App\Domains\Insurance\Application\Query\GetPayerPlanHandler;
use App\Domains\Insurance\Application\Query\GetPendingAuthorizations;
use App\Domains\Insurance\Application\Query\GetPendingAuthorizationsHandler;
use App\Domains\Insurance\Application\Query\GetPlanCoverageRules;
use App\Domains\Insurance\Application\Query\GetPlanCoverageRulesHandler;
use App\Domains\Insurance\Application\Query\GetRemittance;
use App\Domains\Insurance\Application\Query\GetRemittanceHandler;
use App\Domains\Insurance\Application\Query\SearchMemberCoverages;
use App\Domains\Insurance\Application\Query\SearchMemberCoveragesHandler;
use App\Domains\Insurance\Application\Query\SearchPayerPlans;
use App\Domains\Insurance\Application\Query\SearchPayerPlansHandler;
use App\Domains\Insurance\Application\Query\SearchPayers;
use App\Domains\Insurance\Application\Query\SearchPayersHandler;
use App\Domains\Insurance\Application\Query\SearchRemittances;
use App\Domains\Insurance\Application\Query\SearchRemittancesHandler;
use App\Domains\Insurance\Authorization\AdjudicationPolicy;
use App\Domains\Insurance\Authorization\AuthorizationPolicy;
use App\Domains\Insurance\Authorization\ClaimPolicy;
use App\Domains\Insurance\Authorization\MemberCoveragePolicy;
use App\Domains\Insurance\Authorization\PayerContractPolicy;
use App\Domains\Insurance\Authorization\PayerPolicy;
use App\Domains\Insurance\Authorization\PayerTariffPolicy;
use App\Domains\Insurance\Authorization\PlanCoverageRulePolicy;
use App\Domains\Insurance\Authorization\PlanPolicy;
use App\Domains\Insurance\Authorization\RemittancePolicy;
use App\Domains\Insurance\Console\ExpireAuthorizationsCommand;
use App\Domains\Insurance\Console\ExpirePayerContractsCommand;
use App\Domains\Insurance\Contracts\InsuranceApi;
use App\Domains\Insurance\Domain\AdjudicationReader;
use App\Domains\Insurance\Domain\AdjudicationRepository;
use App\Domains\Insurance\Domain\AuthorizationReader;
use App\Domains\Insurance\Domain\AuthorizationRepository;
use App\Domains\Insurance\Domain\ClaimLineRepository;
use App\Domains\Insurance\Domain\ClaimReader;
use App\Domains\Insurance\Domain\ClaimRepository;
use App\Domains\Insurance\Domain\MemberCoverageReader;
use App\Domains\Insurance\Domain\MemberCoverageRepository;
use App\Domains\Insurance\Domain\Model\Adjudication;
use App\Domains\Insurance\Domain\Model\Authorization;
use App\Domains\Insurance\Domain\Model\Claim;
use App\Domains\Insurance\Domain\Model\MemberCoverage;
use App\Domains\Insurance\Domain\Model\Payer;
use App\Domains\Insurance\Domain\Model\PayerContract;
use App\Domains\Insurance\Domain\Model\PayerTariff;
use App\Domains\Insurance\Domain\Model\Plan;
use App\Domains\Insurance\Domain\Model\PlanCoverageRule;
use App\Domains\Insurance\Domain\Model\Remittance;
use App\Domains\Insurance\Domain\PayerContractReader;
use App\Domains\Insurance\Domain\PayerContractRepository;
use App\Domains\Insurance\Domain\PayerReader;
use App\Domains\Insurance\Domain\PayerRepository;
use App\Domains\Insurance\Domain\PayerTariffReader;
use App\Domains\Insurance\Domain\PayerTariffRepository;
use App\Domains\Insurance\Domain\PlanCoverageRuleReader;
use App\Domains\Insurance\Domain\PlanCoverageRuleRepository;
use App\Domains\Insurance\Domain\PlanReader;
use App\Domains\Insurance\Domain\PlanRepository;
use App\Domains\Insurance\Domain\RemittanceReader;
use App\Domains\Insurance\Domain\RemittanceRepository;
use App\Domains\Insurance\Infrastructure\Reader\EloquentAdjudicationReader;
use App\Domains\Insurance\Infrastructure\Reader\EloquentAuthorizationReader;
use App\Domains\Insurance\Infrastructure\Reader\EloquentClaimReader;
use App\Domains\Insurance\Infrastructure\Reader\EloquentMemberCoverageReader;
use App\Domains\Insurance\Infrastructure\Reader\EloquentPayerContractReader;
use App\Domains\Insurance\Infrastructure\Reader\EloquentPayerReader;
use App\Domains\Insurance\Infrastructure\Reader\EloquentPayerTariffReader;
use App\Domains\Insurance\Infrastructure\Reader\EloquentPlanCoverageRuleReader;
use App\Domains\Insurance\Infrastructure\Reader\EloquentPlanReader;
use App\Domains\Insurance\Infrastructure\Reader\EloquentRemittanceReader;
use App\Domains\Insurance\Infrastructure\Repository\EloquentAdjudicationRepository;
use App\Domains\Insurance\Infrastructure\Repository\EloquentAuthorizationRepository;
use App\Domains\Insurance\Infrastructure\Repository\EloquentClaimLineRepository;
use App\Domains\Insurance\Infrastructure\Repository\EloquentClaimRepository;
use App\Domains\Insurance\Infrastructure\Repository\EloquentMemberCoverageRepository;
use App\Domains\Insurance\Infrastructure\Repository\EloquentPayerContractRepository;
use App\Domains\Insurance\Infrastructure\Repository\EloquentPayerRepository;
use App\Domains\Insurance\Infrastructure\Repository\EloquentPayerTariffRepository;
use App\Domains\Insurance\Infrastructure\Repository\EloquentPlanCoverageRuleRepository;
use App\Domains\Insurance\Infrastructure\Repository\EloquentPlanRepository;
use App\Domains\Insurance\Infrastructure\Repository\EloquentRemittanceRepository;
use App\Shared\Authorization\PermissionRegistry;
use App\Shared\Bus\CommandBus;
use App\Shared\Bus\QueryBus;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Insurance & Payer module service provider (21-insurance-and-payer-domain.md;
 * ADR-198; ADR-202; ADR-203; ADR-204).
 *
 * Ins.1 (ADR-198): the domain frame + the Payer & Plan masters
 * (`ins_payers`, `ins_plans`). Ins.2 (ADR-199): Member Coverage
 * (`ins_member_coverages`). Ins.3 (ADR-200): Coverage rules
 * (`ins_plan_coverage_rules`) + the `EvaluateCoverage` -> `CoverageDecision`
 * read model (`CoverageEvaluator` / `CoverageCalculator`, LOCAL evaluation
 * only). Ins.4 (ADR-201): `InsuranceApi` (`patientCoverages` /
 * `evaluateCoverage`, both protected reads) published for the Billing estimate
 * seam. Ins.5 (ADR-202): Pre-Authorization (`ins_authorizations` + `AUT-%08d`).
 * Ins.6 (ADR-203): Payer Contract & Contracted Tariffs (`ins_payer_contracts`,
 * `ins_payer_tariffs` + `PCT-%08d`).
 * Ins.7 (ADR-204): Claims Capture & Submission (`ins_claims`, `ins_claim_lines`
 * + `CLM-%08d`).
 * Adjudication/Remittance (Ins.8) is deferred to its increment.
 */
final class InsuranceServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(PayerRepository::class, EloquentPayerRepository::class);
        $this->app->bind(PlanRepository::class, EloquentPlanRepository::class);
        $this->app->bind(PayerReader::class, EloquentPayerReader::class);
        $this->app->bind(PlanReader::class, EloquentPlanReader::class);

        // Ins.2 — Member Coverage (ADR-199).
        $this->app->bind(MemberCoverageRepository::class, EloquentMemberCoverageRepository::class);
        $this->app->bind(MemberCoverageReader::class, EloquentMemberCoverageReader::class);

        // Ins.3 — Coverage rules + the CoverageDecision read model (ADR-200).
        $this->app->bind(PlanCoverageRuleRepository::class, EloquentPlanCoverageRuleRepository::class);
        $this->app->bind(PlanCoverageRuleReader::class, EloquentPlanCoverageRuleReader::class);

        // Ins.4 — the cross-domain surface (ADR-201 D-A).
        $this->app->bind(InsuranceApi::class, InsuranceApiService::class);

        // Ins.5 — Pre-Authorization (ADR-202 D-A / D-B / D-C).
        $this->app->bind(AuthorizationRepository::class, EloquentAuthorizationRepository::class);
        $this->app->bind(AuthorizationReader::class, EloquentAuthorizationReader::class);

        // Ins.6 — Payer Contract & Tariff (ADR-203 D-A / D-B / D-C).
        $this->app->bind(PayerContractRepository::class, EloquentPayerContractRepository::class);
        $this->app->bind(PayerContractReader::class, EloquentPayerContractReader::class);
        $this->app->bind(PayerTariffRepository::class, EloquentPayerTariffRepository::class);
        $this->app->bind(PayerTariffReader::class, EloquentPayerTariffReader::class);

        // Ins.7 — Claims Capture & Submission (ADR-204 D-A / D-B / D-C).
        $this->app->bind(ClaimRepository::class, EloquentClaimRepository::class);
        $this->app->bind(ClaimLineRepository::class, EloquentClaimLineRepository::class);
        $this->app->bind(ClaimReader::class, EloquentClaimReader::class);

        // Ins.8 — Adjudication & Remittance (ADR-205).
        $this->app->bind(AdjudicationRepository::class, EloquentAdjudicationRepository::class);
        $this->app->bind(AdjudicationReader::class, EloquentAdjudicationReader::class);
        $this->app->bind(RemittanceRepository::class, EloquentRemittanceRepository::class);
        $this->app->bind(RemittanceReader::class, EloquentRemittanceReader::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Database/Migrations');

        PermissionRegistry::add(require __DIR__.'/Authorization/permissions.php');

        if ($this->app->runningInConsole()) {
            $this->commands([
                ExpireAuthorizationsCommand::class,
                ExpirePayerContractsCommand::class,
            ]);
        }

        CommandBus::map([
            // Ins.1 — the Payer & Plan masters (ADR-198 D-I).
            RegisterPayer::class => RegisterPayerHandler::class,
            UpdatePayer::class => UpdatePayerHandler::class,
            SetPayerStatus::class => SetPayerStatusHandler::class,
            CreatePayerPlan::class => CreatePayerPlanHandler::class,
            UpdatePayerPlan::class => UpdatePayerPlanHandler::class,
            SetPayerPlanStatus::class => SetPayerPlanStatusHandler::class,

            // Ins.2 — Member Coverage (ADR-199 D-B).
            RegisterMemberCoverage::class => RegisterMemberCoverageHandler::class,
            UpdateMemberCoverage::class => UpdateMemberCoverageHandler::class,
            TerminateMemberCoverage::class => TerminateMemberCoverageHandler::class,

            // Ins.3 — Coverage rules (ADR-200 D-B).
            SetPlanCoverageRule::class => SetPlanCoverageRuleHandler::class,
            EndPlanCoverageRule::class => EndPlanCoverageRuleHandler::class,

            // Ins.5 — Pre-Authorization (ADR-202 D-B).
            RequestAuthorization::class => RequestAuthorizationHandler::class,
            ApproveAuthorization::class => ApproveAuthorizationHandler::class,
            DenyAuthorization::class => DenyAuthorizationHandler::class,
            CancelAuthorization::class => CancelAuthorizationHandler::class,
            ExpireAuthorization::class => ExpireAuthorizationHandler::class,

            // Ins.6 — Payer Contract & Tariff (ADR-203 D-B).
            CreatePayerContract::class => CreatePayerContractHandler::class,
            ActivatePayerContract::class => ActivatePayerContractHandler::class,
            TerminatePayerContract::class => TerminatePayerContractHandler::class,
            ExpirePayerContract::class => ExpirePayerContractHandler::class,
            SetPayerTariff::class => SetPayerTariffHandler::class,
            EndPayerTariff::class => EndPayerTariffHandler::class,

            // Ins.7 — Claims Capture & Submission (ADR-204).
            CreateClaimFromInvoice::class => CreateClaimFromInvoiceHandler::class,
            UpdateClaimDraft::class => UpdateClaimDraftHandler::class,
            ValidateClaimReadiness::class => ValidateClaimReadinessHandler::class,
            SubmitClaim::class => SubmitClaimHandler::class,
            CancelClaim::class => CancelClaimHandler::class,
            CreateReplacementClaim::class => CreateReplacementClaimHandler::class,

            // Ins.8 — Adjudication & Remittance (ADR-205).
            RecordClaimAdjudication::class => RecordClaimAdjudicationHandler::class,
            RecordRemittance::class => RecordRemittanceHandler::class,
            ReconcileRemittanceLine::class => ReconcileRemittanceLineHandler::class,
        ]);

        QueryBus::map([
            // Ins.1 (ADR-198 D-I).
            GetPayer::class => GetPayerHandler::class,
            SearchPayers::class => SearchPayersHandler::class,
            GetPayerPlan::class => GetPayerPlanHandler::class,
            SearchPayerPlans::class => SearchPayerPlansHandler::class,

            // Ins.2 (ADR-199 D-C).
            GetMemberCoverage::class => GetMemberCoverageHandler::class,
            GetPatientCoverages::class => GetPatientCoveragesHandler::class,
            SearchMemberCoverages::class => SearchMemberCoveragesHandler::class,

            // Ins.3 (ADR-200 D-C / D-D).
            EvaluateCoverage::class => EvaluateCoverageHandler::class,
            GetPlanCoverageRules::class => GetPlanCoverageRulesHandler::class,

            // Ins.5 (ADR-202 D-C).
            GetAuthorization::class => GetAuthorizationHandler::class,
            GetPatientAuthorizations::class => GetPatientAuthorizationsHandler::class,
            GetPendingAuthorizations::class => GetPendingAuthorizationsHandler::class,
            FindEffectiveAuthorization::class => FindEffectiveAuthorizationHandler::class,

            // Ins.6 (ADR-203 D-C).
            GetPayerContract::class => GetPayerContractHandler::class,
            GetPayerContracts::class => GetPayerContractsHandler::class,
            GetContractTariffs::class => GetContractTariffsHandler::class,
            FindEffectivePayerTariff::class => FindEffectivePayerTariffHandler::class,

            // Ins.7 (ADR-204).
            GetClaim::class => GetClaimHandler::class,
            GetPatientClaims::class => GetPatientClaimsHandler::class,
            GetPayerClaims::class => GetPayerClaimsHandler::class,
            GetClaimReadiness::class => GetClaimReadinessHandler::class,

            // Ins.8 (ADR-205).
            GetAdjudication::class => GetAdjudicationHandler::class,
            GetClaimAdjudication::class => GetClaimAdjudicationHandler::class,
            GetRemittance::class => GetRemittanceHandler::class,
            SearchRemittances::class => SearchRemittancesHandler::class,
        ]);

        Gate::policy(Payer::class, PayerPolicy::class);
        Gate::policy(Plan::class, PlanPolicy::class);
        Gate::policy(MemberCoverage::class, MemberCoveragePolicy::class);
        Gate::policy(PlanCoverageRule::class, PlanCoverageRulePolicy::class);
        Gate::policy(Authorization::class, AuthorizationPolicy::class);
        Gate::policy(PayerContract::class, PayerContractPolicy::class);
        Gate::policy(PayerTariff::class, PayerTariffPolicy::class);
        Gate::policy(Claim::class, ClaimPolicy::class);
        Gate::policy(Adjudication::class, AdjudicationPolicy::class);
        Gate::policy(Remittance::class, RemittancePolicy::class);
    }
}
